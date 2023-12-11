<?php 

// app/Services/JobService.php

namespace App\Services;

use DTApi\Repository\BookingRepository;
use Illuminate\Support\Facades\Log;

class JobService
{
    protected $repository;

    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

	public function getJobs(Request $request)
    {
        if ($user_id = $request->get('user_id')) {
            return $this->repository->getUsersJobs($user_id);
        } elseif (
            $request->__authenticatedUser->user_type == env('ADMIN_ROLE_ID') ||
            $request->__authenticatedUser->user_type == env('SUPERADMIN_ROLE_ID')
        ) {
            return $this->repository->getAll($request);
        }

        return null;
    }

    public function getJobDetails($id)
    {
        return $this->repository->with('translatorJobRel.user')->find($id);
    }

    public function storeJob(Request $request)
    {
		$data = $request->all();
        return $this->repository->store($request->__authenticatedUser, $data);
    }

	public function updateJob($id, Request $request)
    {
        $data = $request->all();
        $cuser = $request->__authenticatedUser;
        $response = $this->repository->updateJob($id, array_except($data, ['_token', 'submit']), $cuser);

        return $response;
    }

    public function acceptJob($data, $user)
    {
        try {
            // Your business logic for accepting a job
            $response = $this->repository->acceptJob($data, $user);

            // Additional actions or logging if needed
            Log::info('Job accepted successfully.');

            return $response;
        } catch (\Exception $e) {
            // Handle exceptions or log errors
            Log::error('Error accepting job: ' . $e->getMessage());

            // Return a custom error response if needed
            return ['error' => 'Failed to accept the job.'];
        }
    }

	public function acceptJobWithId(Request $request)
    {
        $data = $request->get('job_id');
        $user = $request->__authenticatedUser;

        $response = $this->repository->acceptJobWithId($data, $user);

        return $response;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        $response = $this->repository->cancelJobAjax($data, $user);

        return $response;
    }

	 /**
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
        $data = $request->all();

        $response = $this->repository->endJob($data);

        return $response;

    }
}


