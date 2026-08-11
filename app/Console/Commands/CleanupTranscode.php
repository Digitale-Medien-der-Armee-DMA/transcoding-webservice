<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\Download;
use App\Services\VimpCallbackOutbox;
use App\Services\VimpDownloadFinalizer;

class CleanupTranscode extends Command
{
    /**
     * The name and signature of the console command.
     * @var string
     */
    protected $signature = 'transcode:cleanup';

    /**
     * The console command description.
     * @var string
     */
    protected $description = 'Cleanup after transcoding tasks';

    /**
     * Create a new command instance.
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     * @return mixed
     */
    public function handle()
    {
        Log::debug("Entering " . __METHOD__);
        $finalizer = app(VimpDownloadFinalizer::class);

        Download::where('processed', Download::PROCESSING)
            ->orderBy('id')
            ->each(function (Download $download) use ($finalizer) {
                $finalizer->finalizeIfComplete($download->id);
            });

        Download::where('processed', Download::PROCESSED)
            ->orderBy('id')
            ->each(function (Download $download) use ($finalizer) {
                $finalizer->ensureFinishedCallback($download);
            });

        $dispatched = app(VimpCallbackOutbox::class)->dispatchDue();

        if ($dispatched > 0) {
            Log::info('Dispatched due ViMP callbacks', ['count' => $dispatched]);
        }
        Log::debug("Exiting " . __METHOD__);
    }
}
