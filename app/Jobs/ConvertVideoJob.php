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

class ConvertVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, GuardsVideoWorker, HandlesVideoJobLifecycle;

    public $video;

    private $dimension;

    private $transcoder;

    public function __construct(Video $video)
    {
        $this->video = $video;
        $target = $this->video->target;

        $size = explode('x', $target['size']);
        $this->dimension = new Dimension($size[0], $size[1]);
    }

    public function handle()
    {
        Log::debug("Entering " . __METHOD__);
        if (!$this->guardVideoWorker($this->job)) {
            Log::debug("Exiting " . __METHOD__ . " after GPU guardrail release");
            return;
        }

        if ($this->shouldProcessVideo())
        {
            try
            {
                $this->transcoder = new TranscodingController($this->video, $this->dimension, $this->attempts());
                $this->transcoder->transcode();
                $this->transcoder->executeCallback();
            }
            catch (Throwable $exception)
            {
                Log::info("ConvertVideoJob Message: " . $exception->getMessage() . ", Code: " . $exception->getCode() . ", Attempt: " . $this->attempts() . ", Class: " . get_class($exception) . ", Trace: " . $exception->getTraceAsString());
                $this->retryVideoJob($exception);
            }
        }
        Log::debug("Exiting " . __METHOD__);
    }

    public function jobs()
    {
        return $this->onQueue($this->queue);
    }

}
