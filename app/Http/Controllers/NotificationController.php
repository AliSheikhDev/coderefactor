<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use Illuminate\Http\Request;
use App\Services\NotificationService;


/**
 * Class NotificationController
 * @package DTApi\Http\Controllers
 */
class NotificationController extends Controller
{
    /**
     * @var NotificationService
     */
    protected $notificationService;

    /**
     * BookingController constructor.
     * @param NotificationService $notificationService
     */
    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }
    
    public function resendNotifications(Request $request)
    {
        $response = $this->notificationService->resendNotifications($request);
        return response($response);
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        $response = $this->notificationService->resendSMSNotifications($request);
        return response($response);
    }
}