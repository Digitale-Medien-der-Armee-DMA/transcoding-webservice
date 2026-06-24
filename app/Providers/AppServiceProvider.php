<?php

namespace App\Providers;

use App\Services\WorkerHeartbeat;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        URL::forceRootUrl(env('APP_URL'));
	    Schema::defaultStringLength(191);

        Queue::before(function (JobProcessing $event) {
            // $event->connectionName
            // $event->job
            // $event->job->payload()
            app(WorkerHeartbeat::class)->touch($this->queueName($event->job), $this->jobName($event->job));
            Log::debug("Host: " . gethostname() . ' ' .  print_r($event->job->payload(), true));
        });

        Queue::after(function (JobProcessed $event) {
            app(WorkerHeartbeat::class)->touch($this->queueName($event->job), $this->jobName($event->job));
        });

        Queue::failing(function (JobFailed $event) {
            app(WorkerHeartbeat::class)->touch($this->queueName($event->job), $this->jobName($event->job));
        });
    }

    private function queueName($job): ?string
    {
        return method_exists($job, 'getQueue') ? $job->getQueue() : null;
    }

    private function jobName($job): ?string
    {
        return method_exists($job, 'resolveName') ? $job->resolveName() : null;
    }
}
