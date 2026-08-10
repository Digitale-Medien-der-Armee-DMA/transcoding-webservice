<?php

namespace App\Jobs;

use App\Jobs\Concerns\GuardsVideoWorker;
use App\Jobs\Concerns\HandlesVideoJobLifecycle;
use App\Http\Controllers\TranscodingController;
use App\Models\Video;
use FFMpeg\Coordinate\Dimension;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateSpritemapJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, GuardsVideoWorker, HandlesVideoJobLifecycle;

    public $video;

    private $dimension;

    private $transcoder;

    public function __construct(Video $video)
    {
        $this->video = $video;
        $this->dimension = new Dimension(10, 10);
    }

    public function handle()
    {
        Log::debug("Entering " . __METHOD__);
        if (!$this->guardVideoWorker($this->job)) {
            Log::debug("Exiting " . __METHOD__ . " after GPU guardrail release");
            return;
        }

        if (!$this->shouldProcessVideo()) {
            Log::debug("Exiting " . __METHOD__ . " because the video job is complete or cancelled");
            return;
        }

        $this->transcoder = new TranscodingController($this->video, $this->dimension, $this->attempts());
        try
        {
            $this->transcoder->createSpritemap();
        }

        catch (Throwable $exception)
        {
            Log::info("CreateSpritemapJob Message: " . $exception->getMessage() . ", Code: " . $exception->getCode() . ", Attempt: " . $this->attempts() . ", Class: " . get_class($exception) . ", Trace: " . $exception->getTraceAsString());
            $this->retryVideoJob($exception);
        }
        Log::debug("Exiting " . __METHOD__);
    }

    public function jobs()
    {
        return $this->onQueue($this->queue);
    }
}
