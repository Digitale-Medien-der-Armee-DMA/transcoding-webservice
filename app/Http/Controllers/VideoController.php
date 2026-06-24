<?php

namespace App\Http\Controllers;

use App\Jobs\ConvertVideoJob;
use App\Models\Download;
use App\Models\DownloadJob;
use App\Models\Video;
use App\Services\Security\MediaPathGuard;
use App\Services\Security\SourceUrlPolicy;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class VideoController extends Controller
{

    public static function deleteById($id)
    {
        Log::debug("Entering " . __METHOD__);
        $filenames = DB::table('videos')->select('file')->whereIn('id', explode(',', $id))->pluck('file')->toArray();
        $filenames = app(MediaPathGuard::class)->filterSafeRelativePaths($filenames);
        Storage::disk('converted')->delete($filenames);
        Log::debug("Exiting " . __METHOD__);
    }

    public function getFile($filename)
    {
        Log::debug("Entering " . __METHOD__);
        if (!app(MediaPathGuard::class)->isSafeRelativePath($filename)) {
            Log::debug("Exiting " . __METHOD__);
            return response()->json(['message' => 'File not found'])->setStatusCode(404);
        }

        Log::info('Plugin tries to download ' . $filename . ' with user id ' . Auth::guard('api')->user()->id);
        //$uid = DB::table('videos')->where('file','=', $filename)->pluck('user_id')->first();

        try {
            Video::where('file', '=', $filename)->where('user_id', '=', Auth::guard('api')->user()->id)->firstOrFail();
            $file = Storage::disk('converted')->path($filename);
            if (file_exists($file)) {
                Log::debug("Exiting " . __METHOD__);
                return response()->download($file, null, [], null);
            }
            Log::debug("Exiting " . __METHOD__);
            return response()->json([
                'message' => 'File not found'
            ])->setStatusCode(404);
        } catch (\Exception $exception) {
            Log::debug("Exiting " . __METHOD__);
            return response()->json(['message' => $exception->getMessage()])->setStatusCode(500);
        }
    }

    public function setDownloadFinished($filename)
    {
        Log::debug("Entering " . __METHOD__);
        if (!app(MediaPathGuard::class)->isSafeRelativePath($filename)) {
            Log::debug("Exiting " . __METHOD__);
            return response()->json(['message' => 'File not found'])->setStatusCode(404);
        }

        Log::info('Plugin tries to set file ' . $filename . ' with user id ' . Auth::guard('api')->user()->id . ' to finished state');

        $video = Video::where('file', '=', $filename)->where('user_id', '=', Auth::guard('api')->user()->id);
        if ($video->count() === 0) {
            Log::debug("Exiting " . __METHOD__);
            return response()->json(['message' => 'File not found'])->setStatusCode(404);
        }

        $video->update(['downloaded_at' => Carbon::now()]);
        Log::info('Video ' . $filename . ' was set to finished state');
        Log::debug("Exiting " . __METHOD__);
        return response()->json(['message' => 'ok'])->setStatusCode(200);
    }

    public static function deleteAllByMediaKey($mediakey)
    {
        Log::debug("Entering " . __METHOD__);
        Log::info('Delete all files and DB entries for mediakey ' . $mediakey);
        $pathGuard = app(MediaPathGuard::class);

        if (!$pathGuard->isValidMediaKey($mediakey)) {
            Log::debug("Exiting " . __METHOD__);
            return response()->json('Not found')->setStatusCode(404);
        }

        if (!empty($mediakey)) {
            $filenames = DB::table('videos')->select('file')->whereIn('mediakey', explode(',', $mediakey))->pluck('file')->toArray();
            $filenames = $pathGuard->filterSafeRelativePaths($filenames);

            $deleteVideo = Video::where('mediakey', $mediakey)->get();
            DownloadJob::where('download_id', '=', $deleteVideo->first()->download_id)->delete();

            foreach ($deleteVideo as $video) {
                if (!empty($video->download)) {
                    $video->download->delete();
                }
                if(isset($video->target['label'], $video->target['extension'])) {
                    $dir = $video->path . '_' . $video->target['label'] . '_' . $video->target['extension'];
                    if ($pathGuard->isSafeRelativePath($dir) && Storage::disk('converted')->exists($dir)) {
                        Storage::disk('converted')->deleteDirectory($dir);
                    }
                }
                $video->delete();
            }
            Storage::disk('converted')->delete($filenames);
            if ($pathGuard->isSafeRelativePath($mediakey)) {
                Storage::disk('uploaded')->delete($mediakey);
            }
        }
        Log::debug("Exiting " . __METHOD__);
    }

    public static function getStatus($mediakey)
    {
        Log::debug("Entering " . __METHOD__);
        Log::info('Plugin tries to get transcoding status for mediakey ' . $mediakey);
        try {
            $download = Download::where('mediakey', '=', $mediakey)->firstOrFail();
            if ($download->videos->count() > 0) {
                $video = Video::where('mediakey', '=', $mediakey)->firstOrFail();
                $total = Video::where('download_id', $video->download_id)->count();
                $processed = Video::where('download_id', $video->download_id)->where('processed', Video::PROCESSED)->count();
                Log::info('Transcoding status for mediakey ' . $mediakey . ': processed ' . $processed . ' of ' . $total);
                Log::debug("Exiting " . __METHOD__);
                return response()->json(round(($processed / $total) * 100, 0))->setStatusCode(200);
            }
            Log::info('Transcoding status for mediakey ' . $mediakey . ': no videos converted yet.');
            Log::debug("Exiting " . __METHOD__);
            return response()->json(0)->setStatusCode(200);
        } catch (\Exception $exception) {
            Log::info('Transcoding status for mediakey ' . $mediakey . ': not found');
            Log::debug("Exiting " . __METHOD__);
            return response()->json('Not found')->setStatusCode(404);
        }
    }

    public function testUrl(Request $request)
    {
        Log::debug("Entering " . __METHOD__);
        $api_token = $request->input('api_token', false);
        $url = $request->input('url', false);
        if ($api_token && $url) {
            if (!app(SourceUrlPolicy::class)->allows($url)) {
                Log::debug("Exiting " . __METHOD__);
                return response()->json(['message' => 'URL is not allowed'])->setStatusCode(400);
            }

            $guzzle = new Client();
            $requestOptions = array(
                RequestOptions::CONNECT_TIMEOUT => (int) config('security.downloads.connect_timeout_seconds', 10),
                RequestOptions::TIMEOUT => (int) config('security.downloads.timeout_seconds', 300),
                RequestOptions::JSON => [
                    'api_token' => $api_token,
                ]);

            try {
                $response = $guzzle->post($url . '/transcoderwebservice/version', $requestOptions);
                $body = json_decode($response->getBody()->getContents());

                Log::debug("Exiting " . __METHOD__);

                return response()->json($body)->setStatusCode($response->getStatusCode());
            }
            catch (\Throwable $exception) {
                Log::debug("Exiting " . __METHOD__);
		return response()->json(['message' => $exception->getMessage()])->setStatusCode(400);
            }
        }
        Log::debug("Exiting " . __METHOD__);
        return response()->json(['message' => 'Error'])->setStatusCode(404);
    }
}
