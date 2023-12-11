<?php
// app/Providers/NotificationServiceProvider.php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\NotificationService;

class NotificationServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(NotificationService::class, function ($app) {
            return new NotificationService($app->make(\DTApi\Repository\NotificationRepository::class,\DTApi\Repository\BookingRepository::class));
        });
    }
}
