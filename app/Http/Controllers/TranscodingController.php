<?php

namespace App\Http\Controllers;

use Alchemy\BinaryDriver\Listeners\DebugListener;
use App\Format\Video\H264;
use App\Models\Download;
use App\Models\Profile;
use App\Models\Video;
use App\Models\User;
use App\Models\VimpCallback;
use App\Services\Security\MediaPathGuard;
use App\Services\VideoFilterGraph;
use App\Services\VimpCallbackOutbox;
use App\Services\VimpCallbackPayloadBuilder;
use App\Services\WorkerHeartbeat;
use Carbon\Carbon;
use Exception;
use FFMpeg\Filters\Frame\CustomFrameFilter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use FFMpeg;
use FFMpeg\Coordinate\Dimension;
use ZipArchive;
use Throwable;

class TranscodingController extends Controller
{
    public const TRANSCODERWEBSERVICE_CALLBACK = '/transcoderwebservice/callback';
    public const SPRITEMAP_DEFAULT_WIDTH = 142;
    public const SPRITEMAP_DEFAULT_HEIGHT = 80;

    public $video;
    private $dimension;
    private $preview;
    private $hls;
    private $user;
    private $profile;
    private $attempts;
    private $progress;
    private $pid;
    private $worker;

    public function __construct(Video $video, Dimension $dimension, $attempts)
    {
        $this->video = $video;
        $this->dimension = $dimension;
        $this->attempts = $attempts;
        $this->user = User::find($this->video->user_id);
        $this->profile = $this->user->profile;
        $this->worker = config('workers.heartbeat.name') ?: gethostname();
    }

    public function updateProgress($percentage)
    {
        Log::debug("Entering " . __METHOD__);
        try {
            Cache::lock('target-' . $this->getTargetFileName())->get(function () use ($percentage) {
                    $this->video->update([
                        'percentage' => $percentage,
                    ]);
            });

        }
        catch(Throwable $exception)
        {
                Log::debug("Failed to update progress of " . $this->getTargetFileName() . ":" . $exception->getMessage());
        }
        Log::debug("Exiting " . __METHOD__);
    }

    public function transcode()
    {
        Log::debug("Entering " . __METHOD__);
        $pid = $this->pid = getmypid();

	$start = now();
        app(WorkerHeartbeat::class)->touch(config('workers.queues.video'), $this->video->title);
        $this->video->update([
            'processed' => Video::PROCESSING,
            'file' => $this->getTargetFileName(),
            'worker' => $this->worker
        ]);

        $this->prepare();

        $target = $this->video->target;

        $converted_name = $this->getTargetFileName();
        Log::info("Clip: $converted_name, encoder: " . $this->profile->encoder . ", attempt: $this->attempts");
        $fallback_profile = Profile::find($this->user->profile->fallback_id);
        if ($this->attempts > 1  && !empty($fallback_profile)) {
            Log::info("Failed to encode $converted_name with " . $this->profile->encoder . " codec");
            $this->profile = $fallback_profile;
        }
        Log::info("Trying to encode clip $converted_name with " . $this->profile->encoder . " codec ..");
        Log::debug("Target:  ". print_r($this->video->target, true));

        $h264 = (new H264('aac', $this->profile->encoder))
            ->setKiloBitrate($target['vbr'])
            ->setAudioKiloBitrate($target['abr'])
            ->setAdditionalParameters($this->applyAdditionalParameters())
            ->setInitialParameters($this->applyInitialParameters());

        $ffmpeg = FFMpeg\FFMpeg::create(self::getFFmpegConfig());
        if(self::getFFmpegConfig()['ffmpeg.debug']) {
            $ffmpeg->getFFMpegDriver()->listen(new DebugListener());
                $ffmpeg->getFFMpegDriver()->on('debug', function ($message) {
                    Log::info('FFmpeg: ' . $message);
                });
        }

        $videofile = Storage::disk('uploaded')->path($this->video->path);
        if (file_exists($videofile) && is_readable($videofile) && is_writable($videofile)) {

            $video = $ffmpeg->open(Storage::disk('uploaded')->path($this->video->path));

            $video = $this->applyFilters($video);

            $h264->on('progress', function ($video, $format, $percentage) use ($pid, $converted_name) {
		        if ($percentage !== 0) {
                    Log::info("Host: $this->worker, PID: $pid, $percentage% of $converted_name transcoded");
                    $this->progress = (int) $percentage;
                    $this->updateProgress($percentage);
                }
            });

            Log::debug('Executing ' . print_r($video->getFinalCommand($h264, Storage::disk('converted')->path($this->getTargetFileName())), true));
            $video->save($h264, Storage::disk('converted')->path($this->getTargetFileName()));
            $time = $start->diffInSeconds(now());
            Log::debug("Conversion in " . __METHOD__ . " of " . $this->getTargetFileName() . " took $time seconds");
            $this->video->update([
                'converted_at' => Carbon::now(),
                'processed' => Video::PROCESSED,
                'percentage' => 100
            ]);
        }

        else {
            Log::debug("File " . $videofile . ' is not readable, please check permissions of the storage folder');
        }

	    Log::debug("Exiting " . __METHOD__);
    }

    public function createThumbnail()
    {
        Log::debug("Entering " . __METHOD__);

	$start = now();
        $payload = $this->video->target;
        $target = $payload['thumbnail_item'];

        $key = array_key_first($target);
        $converted_name = $this->video->path . '_' . $payload['source']['created_at'] . '_' . $key . '.jpg';

        $ffmpeg = FFMpeg\FFMpeg::create(self::getFFmpegConfig());
        if(self::getFFmpegConfig()['ffmpeg.debug']) {
            $ffmpeg->getFFMpegDriver()->listen(new DebugListener());
            $ffmpeg->getFFMpegDriver()->on('debug', function ($message) {
                Log::info(__METHOD__ . ' FFmpeg: ' . $message);
            });
        }

        $videofile = Storage::disk('uploaded')->path($this->video->path);
        if (file_exists($videofile) && is_readable($videofile) && is_writable($videofile)) {
            $ffmpeg->open($videofile)
                ->frame(FFMpeg\Coordinate\TimeCode::fromSeconds($target[$key]['second']))
                ->save(Storage::disk('converted')->path($converted_name));

            $time = $start->diffInSeconds(now());
            Log::debug("Conversion in " . __METHOD__ . " of " . $converted_name ." took $time seconds", ['name' => $converted_name]);

            $this->video->update([
                'converted_at' => Carbon::now(),
                'processed' => Video::PROCESSED,
                'percentage' => 100,
                'file' => $converted_name,
                'worker' => $this->worker
            ]);

            $this->enqueuePreparedCallback(
                VimpCallback::TYPE_THUMBNAIL,
                function () {
                    return app(VimpCallbackPayloadBuilder::class)->thumbnail($this->video->fresh());
                }
            );
        }
        else {
            Log::debug("File " . $videofile . ' is not readable, please check permissions of the storage folder');
        }
        Log::debug("Exiting " . __METHOD__);
    }

    public function createSpritemap()
    {
        Log::debug("Entering " . __METHOD__);

	    $start = now();
        $payload = $this->video->target;
        $spritemap = $payload['spritemap'];

        $converted_name = $this->video->path . '_' . $payload['source']['created_at'] . '_sprites.jpg';

        $target_width = $spritemap['width'] ?? self::SPRITEMAP_DEFAULT_WIDTH;
        $target_height = $spritemap['height'] ?? self::SPRITEMAP_DEFAULT_HEIGHT;

        $ffmpeg = FFMpeg\FFMpeg::create(self::getFFmpegConfig());
        if(self::getFFmpegConfig()['ffmpeg.debug']) {
            $ffmpeg->getFFMpegDriver()->listen(new DebugListener());
            $ffmpeg->getFFMpegDriver()->on('debug', function ($message) {
                Log::info('FFmpeg: ' . $message);
            });
        }

        $ffprobe = FFMpeg\FFProbe::create(self::getFFmpegConfig());
        if(self::getFFmpegConfig()['ffprobe.debug']) {
            $ffprobe->getFFProbeDriver()->listen(new DebugListener());
            $ffprobe->getFFProbeDriver()->on('debug', function ($message) {
                Log::info('FFprobe: ' . $message);
            });
        }

        $videofile = Storage::disk('uploaded')->path($this->video->path);
        if (file_exists($videofile) && is_readable($videofile) && is_writable($videofile)) {
            $duration = $ffprobe->format($videofile)->get('duration');
            $video = $ffmpeg->open(Storage::disk('uploaded')->path($this->video->path));
            Log::debug("Spritemap count: " . $spritemap['count'] . ", duration: " . $duration);
            $fps = $spritemap['count'] / ceil($duration);

            $video->frame(FFMpeg\Coordinate\TimeCode::fromSeconds(0))
                ->addFilter(new CustomFrameFilter('select=eq(pict_type\,PICT_TYPE_I),mpdecimate,scale=' . $target_width . ':' . $target_height . ',fps=' . $fps . ',tile=10x10:margin=2:padding=2'))
                ->save(Storage::disk('converted')->path($converted_name));

            $time = $start->diffInSeconds(now());
            Log::debug("Conversion in " . __METHOD__ . " of " . $converted_name ." took $time seconds", ['name' => $converted_name] );

            $this->video->update([
                'converted_at' => Carbon::now(),
                'processed' => Video::PROCESSED,
                'percentage' => 100,
                'file' => $converted_name,
                'worker' => $this->worker
            ]);

            $this->enqueuePreparedCallback(
                VimpCallback::TYPE_SPRITEMAP,
                function () {
                    return app(VimpCallbackPayloadBuilder::class)->spritemap($this->video->fresh());
                }
            );
        }
        else {
            Log::debug("File " . $videofile . ' is not readable, please check permissions of the storage folder');
        }
	    Log::debug("Exiting " . __METHOD__);
    }

    public function setPreview($preview = true)
    {
        $this->preview = $preview;
    }

    public function getPreview()
    {
        return $this->preview;
    }

    public function setHLS($hls = true)
    {
        $this->hls = $hls;
    }

    public function getHLS()
    {
        return $this->hls;
    }

    public static function getFFmpegConfig()
    {
        return array(
            'ffmpeg.binaries' => config('php-ffmpeg.ffmpeg.binaries'),
            'ffmpeg.threads' => config('php-ffmpeg.ffmpeg.threads'),
            'ffprobe.binaries' => config('php-ffmpeg.ffprobe.binaries'),
            'ffmpeg.debug' => config('php-ffmpeg.ffmpeg.debug'),
            'ffprobe.debug' => config('php-ffmpeg.ffprobe.debug'),
            'timeout' => config('php-ffmpeg.timeout'),
        );
    }

    public function executeCallback()
    {
        Log::debug("Entering " . __METHOD__);
        $this->enqueuePreparedCallback(VimpCallback::TYPE_MEDIUM, function () {
            $video = $this->video->fresh();
            $payloads = app(VimpCallbackPayloadBuilder::class);

            if ($this->getHLS()) {
                return $payloads->hlsMedium($video, $this->createHLSArchive());
            }

            return $payloads->medium($video);
        });
        Log::debug("Exiting " . __METHOD__);
    }

    public function executeFinalCallback()
    {
	    Log::debug("Entering " . __METHOD__);
        $download = $this->video->download;
        Log::info('Queueing final callback for mediakey ' . $download->mediakey);
        app(VimpCallbackOutbox::class)->enqueueForDownload(
            $download,
            VimpCallback::TYPE_FINISHED,
            app(VimpCallbackPayloadBuilder::class)->finished($download)
        );
	    Log::debug("Exiting " . __METHOD__);
    }

    public static function executeErrorCallback($video, $message)
    {
	    Log::debug("Entering " . __METHOD__);
        Log::info('Queueing error callback for mediakey ' . $video->mediakey);
        $download = $video->download;
        app(VimpCallbackOutbox::class)->enqueueForDownload(
            $download,
            VimpCallback::TYPE_ERROR,
            app(VimpCallbackPayloadBuilder::class)->error($video, $message)
        );
	    Log::debug("Exiting " . __METHOD__);
    }

    public function downloadComplete()
    {
	    Log::debug("Entering " . __METHOD__);
        Log::info('Check if all downloads are complete for mediakey ' . $this->video->mediakey);
        try {
            $video = Video::where('mediakey', '=', $this->video->mediakey)->firstOrFail();
            $total = Video::where('download_id', $video->download_id)->count();
            $processed = Video::where('download_id', $video->download_id)->where('processed', Video::PROCESSED)->whereNotNull('downloaded_at')->count();
            if ($total === $processed) {
                Log::info('All downloads are complete for mediakey ' . $this->video->mediakey . " ($processed of $total)");
                Log::debug("Exiting " . __METHOD__);
                return true;
            }
            Log::info('Downloads are not yet complete for mediakey ' . $this->video->mediakey . " ($processed of $total)");
            Log::debug("Exiting " . __METHOD__);
            return false;
        } catch (\Exception $exception) {
            Log::info('Downloads are incomplete for mediakey ' . $this->video->mediakey);
            Log::debug("Exiting " . __METHOD__);
            return false;
        }
    }

    private function enqueuePreparedCallback(string $type, callable $buildPayload): void
    {
        try {
            app(VimpCallbackOutbox::class)->enqueueForVideo(
                $this->video->fresh(),
                $type,
                $buildPayload()
            );
        } catch (Throwable $exception) {
            app(VimpCallbackOutbox::class)->recordPreparationFailureForVideo(
                $this->video->fresh(),
                $type,
                $exception
            );

            Log::error('Could not prepare ViMP callback; transcoded artifact remains available', [
                'video_id' => $this->video->id,
                'mediakey' => $this->video->mediakey,
                'type' => $type,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    protected function applyInitialParameters()
    {
        $profile_options_db = $this->profile->options->pluck('value', 'key')->toArray();

        $profile_options = array();
        foreach ($profile_options_db as $key => $value) {
            $profile_options[] = $key;
            $profile_options[] = $value;
        }
        if ($this->preview) {
            $profile_options[] = '-ss';
            $profile_options[] = FFMpeg\Coordinate\TimeCode::fromSeconds($this->video->download()->get()->first()->payload['target']['start']);
        }

        return $profile_options;
    }

    protected function applyAdditionalParameters()
    {
        $payload = $this->video->download()->get()->first()->payload;
        $profile_additional_parameters_db = $this->profile->additionalparameters->pluck('value', 'key')->toArray();
        $profile_additional_parameters = array();
        foreach ($profile_additional_parameters_db as $key => $value) {
            $profile_additional_parameters[] = $key;
            $profile_additional_parameters[] = $value;
        }
        if ($this->getPreview()) {
            $profile_additional_parameters[] = '-t';
            $profile_additional_parameters[] = FFMpeg\Coordinate\TimeCode::fromSeconds($payload['target']['duration']);
        }
        if ($this->getHLS()) {
            $filepath_ts = Storage::disk('converted')->path(substr($this->getTargetFileName(), 0, -5) . '_%03d.ts');

            $profile_additional_parameters[] = '-hls_time';
            $profile_additional_parameters[] = '4';
            $profile_additional_parameters[] = '-hls_playlist_type';
            $profile_additional_parameters[] = 'vod';
            $profile_additional_parameters[] = '-hls_segment_filename';
            $profile_additional_parameters[] = $filepath_ts;
        }
        return $profile_additional_parameters;
    }

    protected function getTargetFileName()
    {
        $target = $this->video->target;
        $separator = '_';

        if (isset($target['default']) && $target['default'] == true) {
            $target['label'] = '';
            $separator = '';
        }

        $file = $this->video->path . '_' . $target['created_at'] . $separator . $target['label'] . '.' . $target['extension'];

        if($this->getHLS())
        {
            Storage::disk('converted')->makeDirectory($this->getHLSDirectory());
            $file = $this->getHLSDirectory() . DIRECTORY_SEPARATOR . $this->video->path . '_' . $target['created_at'] . $separator . $target['label'] . '_' . $target['extension'] . '.m3u8';;
        }

        if ($this->getPreview())
        {
            $file = 'preview_' . $file;
        }
        return $file;
    }

    private function applyFilters($video)
    {
        $filter = app(VideoFilterGraph::class)->forEncoder(
            $this->profile->encoder,
            $this->dimension->getWidth(),
            $this->dimension->getHeight()
        );

        $video->filters()->custom($filter)->synchronize();

        return $video;
    }

    private function prepare()
    {
        if($this->getHLS())
        {
            $directory = $this->getHLSDirectory();
            if (!app(MediaPathGuard::class)->isSafeRelativePath($directory)) {
                throw new Exception('Unsafe HLS output directory');
            }
            Storage::disk('converted')->deleteDirectory($directory);
        }
    }

    private function getHLSDirectory()
    {
        $directory = $this->video->path . '_' . $this->video->target['label'] . '_' . $this->video->target['extension'];

        if (!app(MediaPathGuard::class)->isSafeRelativePath($directory)) {
            throw new Exception('Unsafe HLS output directory');
        }

        return $directory;
    }

    public static function getFFmpegVersion()
    {
        try{
            $ffmpeg = FFMpeg\FFMpeg::create(self::getFFmpegConfig());
            return $ffmpeg->getFFMpegDriver()->getVersion();
        }
        catch (Exception $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * @return string
     * @throws Exception
     */
    protected function createHLSArchive(): string
    {
        $files = Storage::disk('converted')->files($this->getHLSDirectory());
        $archiveFile = $this->getHLSDirectory() . '.zip';
        Log::info('Archive: ' . $archiveFile);

        $archive = new ZipArchive();

        if ($archive->open(Storage::disk('converted')->path($archiveFile), ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            foreach ($files as $file) {
                if ($archive->addFile(Storage::disk('converted')->path($file), basename($file))) {
                    continue;
                }
                throw new Exception("File [`{$file}`] could not be added to the zip file: " . $archive->getStatusString());
            }

            if ($archive->close()) {
                $this->video->update([
                    'file' => $archiveFile
                ]);
            }
        }
        return $archiveFile;
    }
}
