<?php 

// app/Services/JobService.php

namespace App\Services;

use DTApi\Repository\NotificationRepository;
use DTApi\Repository\BookingRepository;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    protected $repository;
	protected $bookingRepo;

    public function __construct(NotificationRepository $notificationRepository,BookingRepository $bookingRepository)
    {
        $this->repository = $notificationRepository;
		$this->bookingRepo = $bookingRepository;
    }

	public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->bookingRepo->find($data['jobid']);
        $job_data = $this->bookingRepo->jobToData($job);
        $this->repository->sendNotificationTranslator($job, $job_data, '*');

        return ['success' => 'Push sent'];
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->bookingRepo->find($data['jobid']);
        $job_data = $this->bookingRepo->jobToData($job);

        try {
            $this->repository->sendSMSNotificationToTranslator($job);
            return ['success' => 'SMS sent'];
        } catch (\Exception $e) {
            return ['success' => $e->getMessage()];
        }
    }
}


