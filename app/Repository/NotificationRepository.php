<?php

namespace DTApi\Repository;

use Carbon\Carbon;
use DTApi\Events\JobWasCanceled;
use DTApi\Events\JobWasCreated;
use DTApi\Events\SessionEnded;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Helpers\SendSMSHelper;
use DTApi\Helpers\TeHelper;
use DTApi\Mailers\AppMailer;
use DTApi\Mailers\MailerInterface;
use DTApi\Models\Job;
use DTApi\Models\Language;
use DTApi\Models\Translator;
use DTApi\Models\User;
use DTApi\Models\UserLanguages;
use DTApi\Models\UserMeta;
use DTApi\Models\UsersBlacklist;
use Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * Class NotificationRepository.
 */
class NotificationRepository extends BaseRepository
{
    protected $model;
    protected $mailer;
    protected $logger;

    public function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger("admin_logger");

        $this->logger->pushHandler(
            new StreamHandler(
                storage_path("logs/admin/laravel-" . date("Y-m-d") . ".log"),
                Logger::DEBUG
            )
        );
        $this->logger->pushHandler(new FirePHPHandler());
    }

    

    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator(
        $job,
        $data = [],
        $exclude_user_id
    ) {
        $users = User::all();
        $translator_array = []; // suitable translators (no need to delay push)
        $delpay_translator_array = []; // suitable translators (need to delay push)

        foreach ($users as $oneUser) {
            if (
                $oneUser->user_type == "2" &&
                $oneUser->status == "1" &&
                $oneUser->id != $exclude_user_id
            ) {
                // user is translator and he is not disabled
                if (!$this->isNeedToSendPush($oneUser->id)) {
                    continue;
                }
                $not_get_emergency = TeHelper::getUsermeta(
                    $oneUser->id,
                    "not_get_emergency"
                );
                if (
                    $data["immediate"] == "yes" &&
                    $not_get_emergency == "yes"
                ) {
                    continue;
                }
                $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id); // get all potential jobs of this user
                foreach ($jobs as $oneJob) {
                    if ($job->id == $oneJob->id) {
                        // one potential job is the same with current job
                        $userId = $oneUser->id;
                        $job_for_translator = Job::assignedToPaticularTranslator(
                            $userId,
                            $oneJob->id
                        );
                        if ($job_for_translator == "SpecificJob") {
                            $job_checker = Job::checkParticularJob(
                                $userId,
                                $oneJob
                            );
                            if ($job_checker != "userCanNotAcceptJob") {
                                if ($this->isNeedToDelayPush($oneUser->id)) {
                                    $delpay_translator_array[] = $oneUser;
                                } else {
                                    $translator_array[] = $oneUser;
                                }
                            }
                        }
                    }
                }
            }
        }
        $data["language"] = TeHelper::fetchLanguageFromJobId(
            $data["from_language_id"]
        );
        $data["notification_type"] = "suitable_job";
        $msg_contents = "";
        if ($data["immediate"] == "no") {
            $msg_contents =
                "Ny bokning för " .
                $data["language"] .
                "tolk " .
                $data["duration"] .
                "min " .
                $data["due"];
        } else {
            $msg_contents =
                "Ny akutbokning för " .
                $data["language"] .
                "tolk " .
                $data["duration"] .
                "min";
        }
        $msg_text = [
            "en" => $msg_contents,
        ];

        $logger = new Logger("push_logger");

        $logger->pushHandler(
            new StreamHandler(
                storage_path("logs/push/laravel-" . date("Y-m-d") . ".log"),
                Logger::DEBUG
            )
        );
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo("Push send for job " . $job->id, [
            $translator_array,
            $delpay_translator_array,
            $msg_text,
            $data,
        ]);
        $this->sendPushNotificationToSpecificUsers(
            $translator_array,
            $job->id,
            $data,
            $msg_text,
            false
        ); // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers(
            $delpay_translator_array,
            $job->id,
            $data,
            $msg_text,
            true
        ); // send new booking push to suitable translators(need to delay)
    }

    /**
     * Sends SMS to translators and retuns count of translators.
     *
     * @param $job
     *
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where("user_id", $job->user_id)->first();

        // prepare message templates
        $date = date("d.m.Y", strtotime($job->due));
        $time = date("H:i", strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans("sms.phone_job", [
            "date" => $date,
            "time" => $time,
            "duration" => $duration,
            "jobId" => $jobId,
        ]);

        $physicalJobMessageTemplate = trans("sms.physical_job", [
            "date" => $date,
            "time" => $time,
            "town" => $city,
            "duration" => $duration,
            "jobId" => $jobId,
        ]);

        // analyse weather it's phone or physical; if both = default to phone
        if (
            $job->customer_physical_type == "yes" &&
            $job->customer_phone_type == "no"
        ) {
            // It's a physical job
            $message = $physicalJobMessageTemplate;
        } elseif (
            $job->customer_physical_type == "no" &&
            $job->customer_phone_type == "yes"
        ) {
            // It's a phone job
            $message = $phoneJobMessageTemplate;
        } elseif (
            $job->customer_physical_type == "yes" &&
            $job->customer_phone_type == "yes"
        ) {
            // It's both, but should be handled as phone job
            $message = $phoneJobMessageTemplate;
        } else {
            // This shouldn't be feasible, so no handling of this edge case
            $message = "";
        }
        Log::info($message);

        // send messages via sms handler
        foreach ($translators as $translator) {
            // send message to translator
            $status = SendSMSHelper::send(
                env("SMS_NUMBER"),
                $translator->mobile,
                $message
            );
            Log::info(
                "Send SMS to " .
                    $translator->email .
                    " (" .
                    $translator->mobile .
                    "), status: " .
                    print_r($status, true)
            );
        }

        return count($translators);
    }

    /**
     * Function to delay the push.
     *
     * @param $user_id
     *
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) {
            return false;
        }
        $not_get_nighttime = TeHelper::getUsermeta(
            $user_id,
            "not_get_nighttime"
        );
        if ($not_get_nighttime == "yes") {
            return true;
        }

        return false;
    }

    /**
     * Function to check if need to send the push.
     *
     * @param $user_id
     *
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        $not_get_notification = TeHelper::getUsermeta(
            $user_id,
            "not_get_notification"
        );
        if ($not_get_notification == "yes") {
            return false;
        }

        return true;
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags.
     *
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers(
        $users,
        $job_id,
        $data,
        $msg_text,
        $is_need_delay
    ) {
        $logger = new Logger("push_logger");

        $logger->pushHandler(
            new StreamHandler(
                storage_path("logs/push/laravel-" . date("Y-m-d") . ".log"),
                Logger::DEBUG
            )
        );
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo("Push send for job " . $job_id, [
            $users,
            $data,
            $msg_text,
            $is_need_delay,
        ]);
        if (env("APP_ENV") == "prod") {
            $onesignalAppID = config("app.prodOnesignalAppID");
            $onesignalRestAuthKey = sprintf(
                "Authorization: Basic %s",
                config("app.prodOnesignalApiKey")
            );
        } else {
            $onesignalAppID = config("app.devOnesignalAppID");
            $onesignalRestAuthKey = sprintf(
                "Authorization: Basic %s",
                config("app.devOnesignalApiKey")
            );
        }

        $user_tags = $this->getUserTagsStringFromArray($users);

        $data["job_id"] = $job_id;
        $ios_sound = "default";
        $android_sound = "default";

        if ($data["notification_type"] == "suitable_job") {
            if ($data["immediate"] == "no") {
                $android_sound = "normal_booking";
                $ios_sound = "normal_booking.mp3";
            } else {
                $android_sound = "emergency_booking";
                $ios_sound = "emergency_booking.mp3";
            }
        }

        $fields = [
            "app_id" => $onesignalAppID,
            "tags" => json_decode($user_tags),
            "data" => $data,
            "title" => ["en" => "DigitalTolk"],
            "contents" => $msg_text,
            "ios_badgeType" => "Increase",
            "ios_badgeCount" => 1,
            "android_sound" => $android_sound,
            "ios_sound" => $ios_sound,
        ];
        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields["send_after"] = $next_business_time;
        }
        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt(
            $ch,
            CURLOPT_URL,
            "https://onesignal.com/api/v1/notifications"
        );
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            $onesignalRestAuthKey,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $logger->addInfo("Push send for job " . $job_id . " curl answer", [
            $response,
        ]);
        curl_close($ch);
    }

    /**
     * @param $job
     * @param $data
     *
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
        //        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'completed'])) {
        $job->status = $data["status"];
        if ($data["admin_comments"] == "") {
            return false;
        }
        $job->admin_comments = $data["admin_comments"];
        if ($data["status"] == "completed") {
            $user = $job->user()->first();
            if ($data["sesion_time"] == "") {
                return false;
            }
            $interval = $data["sesion_time"];
            $diff = explode(":", $interval);
            $job->end_at = date("Y-m-d H:i:s");
            $job->session_time = $interval;
            $session_time = $diff[0] . " tim " . $diff[1] . " min";
            if (!empty($job->user_email)) {
                $email = $job->user_email;
            } else {
                $email = $user->email;
            }
            $name = $user->name;
            $dataEmail = [
                "user" => $user,
                "job" => $job,
                "session_time" => $session_time,
                "for_text" => "faktura",
            ];

            $subject =
                "Information om avslutad tolkning för bokningsnummer #" .
                $job->id;
            $this->mailer->send(
                $email,
                $name,
                $subject,
                "emails.session-ended",
                $dataEmail
            );

            $user = $job->translatorJobRel
                ->where("completed_at", null)
                ->where("cancel_at", null)
                ->first();

            $email = $user->user->email;
            $name = $user->user->name;
            $subject =
                "Information om avslutad tolkning för bokningsnummer # " .
                $job->id;
            $dataEmail = [
                "user" => $user,
                "job" => $job,
                "session_time" => $session_time,
                "for_text" => "lön",
            ];
            $this->mailer->send(
                $email,
                $name,
                $subject,
                "emails.session-ended",
                $dataEmail
            );
        }
        $job->save();

        return true;
        //        }
        return false;
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification(
        $user,
        $job,
        $language,
        $due,
        $duration
    ) {
        $this->logger->pushHandler(
            new StreamHandler(
                storage_path("logs/cron/laravel-" . date("Y-m-d") . ".log"),
                Logger::DEBUG
            )
        );
        $this->logger->pushHandler(new FirePHPHandler());
        $data = [];
        $data["notification_type"] = "session_start_remind";
        $due_explode = explode(" ", $due);
        if ($job->customer_physical_type == "yes") {
            $msg_text = [
                "en" =>
                    "Detta är en påminnelse om att du har en " .
                    $language .
                    "tolkning (på plats i " .
                    $job->town .
                    ") kl " .
                    $due_explode[1] .
                    " på " .
                    $due_explode[0] .
                    " som vara i " .
                    $duration .
                    " min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!",
            ];
        } else {
            $msg_text = [
                "en" =>
                    "Detta är en påminnelse om att du har en " .
                    $language .
                    "tolkning (telefon) kl " .
                    $due_explode[1] .
                    " på " .
                    $due_explode[0] .
                    " som vara i " .
                    $duration .
                    " min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!",
            ];
        }

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->bookingRepository->sendPushNotificationToSpecificUsers(
                $users_array,
                $job->id,
                $data,
                $msg_text,
                $this->bookingRepository->isNeedToDelayPush($user->id)
            );
            $this->logger->addInfo("sendSessionStartRemindNotification ", [
                "job" => $job->id,
            ]);
        }
    }

    
    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification(
        $job,
        $current_translator,
        $new_translator
    ) {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject =
            "Meddelande om tilldelning av tolkuppdrag för uppdrag # " .
            $job->id .
            ")";
        $data = [
            "user" => $user,
            "job" => $job,
        ];
        $this->mailer->send(
            $email,
            $name,
            $subject,
            "emails.job-changed-translator-customer",
            $data
        );
        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data["user"] = $user;

            $this->mailer->send(
                $email,
                $name,
                $subject,
                "emails.job-changed-translator-old-translator",
                $data
            );
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data["user"] = $user;

        $this->mailer->send(
            $email,
            $name,
            $subject,
            "emails.job-changed-translator-new-translator",
            $data
        );
    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject =
            "Meddelande om ändring av tolkbokning för uppdrag # " .
            $job->id .
            "";
        $data = [
            "user" => $user,
            "job" => $job,
            "old_time" => $old_time,
        ];
        $this->mailer->send(
            $email,
            $name,
            $subject,
            "emails.job-changed-date",
            $data
        );

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            "user" => $translator,
            "job" => $job,
            "old_time" => $old_time,
        ];
        $this->mailer->send(
            $translator->email,
            $translator->name,
            $subject,
            "emails.job-changed-date",
            $data
        );
    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject =
            "Meddelande om ändring av tolkbokning för uppdrag # " .
            $job->id .
            "";
        $data = [
            "user" => $user,
            "job" => $job,
            "old_lang" => $old_lang,
        ];
        $this->mailer->send(
            $email,
            $name,
            $subject,
            "emails.job-changed-lang",
            $data
        );
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send(
            $translator->email,
            $translator->name,
            $subject,
            "emails.job-changed-date",
            $data
        );
    }

    /**
     * Function to send Job Expired Push Notification.
     *
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = [];
        $data["notification_type"] = "job_expired";
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" =>
                "Tyvärr har ingen tolk accepterat er bokning: (" .
                $language .
                ", " .
                $job->duration .
                "min, " .
                $job->due .
                "). Vänligen pröva boka om tiden.",
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->sendPushNotificationToSpecificUsers(
                $users_array,
                $job->id,
                $data,
                $msg_text,
                $this->isNeedToDelayPush($user->id)
            );
        }
    }

    /**
     * Function to send the notification for sending the admin job cancel.
     *
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        $data = []; // save job's information to data for sending Push
        $data["job_id"] = $job->id;
        $data["from_language_id"] = $job->from_language_id;
        $data["immediate"] = $job->immediate;
        $data["duration"] = $job->duration;
        $data["status"] = $job->status;
        $data["gender"] = $job->gender;
        $data["certified"] = $job->certified;
        $data["due"] = $job->due;
        $data["job_type"] = $job->job_type;
        $data["customer_phone_type"] = $job->customer_phone_type;
        $data["customer_physical_type"] = $job->customer_physical_type;
        $data["customer_town"] = $user_meta->city;
        $data["customer_type"] = $user_meta->customer_type;

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];
        $data["due_date"] = $due_date;
        $data["due_time"] = $due_time;
        $data["job_for"] = [];
        if ($job->gender != null) {
            if ($job->gender == "male") {
                $data["job_for"][] = "Man";
            } elseif ($job->gender == "female") {
                $data["job_for"][] = "Kvinna";
            }
        }
        if ($job->certified != null) {
            if ($job->certified == "both") {
                $data["job_for"][] = "normal";
                $data["job_for"][] = "certified";
            } elseif ($job->certified == "yes") {
                $data["job_for"][] = "certified";
            } else {
                $data["job_for"][] = $job->certified;
            }
        }
        $this->sendNotificationTranslator($job, $data, "*"); // send Push all sutiable translators
    }

    /**
     * send session start remind notificatio.
     *
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending(
        $user,
        $job,
        $language,
        $due,
        $duration
    ) {
        $data = [];
        $data["notification_type"] = "session_start_remind";
        if ($job->customer_physical_type == "yes") {
            $msg_text = [
                "en" =>
                    "Du har nu fått platstolkningen för " .
                    $language .
                    " kl " .
                    $duration .
                    " den " .
                    $due .
                    ". Vänligen säkerställ att du är förberedd för den tiden. Tack!",
            ];
        } else {
            $msg_text = [
                "en" =>
                    "Du har nu fått telefontolkningen för " .
                    $language .
                    " kl " .
                    $duration .
                    " den " .
                    $due .
                    ". Vänligen säkerställ att du är förberedd för den tiden. Tack!",
            ];
        }

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->bookingRepository->sendPushNotificationToSpecificUsers(
                $users_array,
                $job->id,
                $data,
                $msg_text,
                $this->bookingRepository->isNeedToDelayPush($user->id)
            );
        }
    }

   
    /**
     * Convert number of minutes to hour and minute variant.
     *
     * @param int    $time
     * @param string $format
     *
     * @return string
     */
    private function convertToHoursMins($time, $format = "%02dh %02dmin")
    {
        if ($time < 60) {
            return $time . "min";
        } elseif ($time == 60) {
            return "1h";
        }

        $hours = floor($time / 60);
        $minutes = $time % 60;

        return sprintf($format, $hours, $minutes);
    }
}
