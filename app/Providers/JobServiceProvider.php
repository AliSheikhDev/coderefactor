<?php
// app/Providers/JobServiceProvider.php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\JobService;

class JobServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(JobService::class, function ($app) {
            return new JobService($app->make(\DTApi\Repository\BookingRepository::class));
        });
    }
}
