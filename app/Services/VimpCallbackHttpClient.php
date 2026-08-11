<?php

namespace App\Services;

use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Throwable;

class VimpCallbackHttpClient
{
    public function send(User $user, array $payload): array
    {
        $url = rtrim($user->url, '/') . '/transcoderwebservice/callback';
        $payload = array_merge(['api_token' => $user->api_token], $payload);
        $startedAt = microtime(true);

        try {
            $response = (new Client())->post($url, [
                RequestOptions::CONNECT_TIMEOUT => (int) config('vimp_callbacks.connect_timeout_seconds', 10),
                RequestOptions::TIMEOUT => (int) config('vimp_callbacks.timeout_seconds', 120),
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::JSON => $payload,
            ]);

            return [
                'url' => $url,
                'status_code' => $response->getStatusCode(),
                'response' => $this->sanitizeResponse((string) $response->getBody(), $user->api_token),
                'error' => null,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } catch (Throwable $exception) {
            return [
                'url' => $url,
                'status_code' => null,
                'response' => null,
                'error' => $this->sanitizeResponse($exception->getMessage(), $user->api_token),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }
    }

    private function sanitizeResponse(string $value, string $apiToken): string
    {
        if ($apiToken !== '') {
            $value = str_replace($apiToken, '[redacted]', $value);
        }

        $value = preg_replace('/(api_token|token|password|secret)(["\'=:\s]+)[^\s"\']+/i', '$1$2[redacted]', $value);

        return substr((string) $value, 0, (int) config('vimp_callbacks.max_response_bytes', 2048));
    }
}
