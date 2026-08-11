<?php

namespace App\Console\Commands;

use App\Models\VimpCallback;
use App\Services\VimpCallbackHttpClient;
use Illuminate\Console\Command;

class ProbeVimpCallback extends Command
{
    protected $signature = 'vimp:callback-probe
                            {callback : Persisted callback ID}
                            {--variant=current : current, minimal, label-only, or source-url}
                            {--send : Send the diagnostic request to ViMP}';

    protected $description = 'Preview or send a controlled ViMP callback diagnostic request';

    public function handle(VimpCallbackHttpClient $client): int
    {
        $callback = VimpCallback::with(['user', 'download'])->find($this->argument('callback'));

        if (!$callback || !$callback->user) {
            $this->error('Callback or callback user not found.');
            return 1;
        }

        $variant = $this->option('variant');
        $payload = $this->variantPayload($callback, $variant);

        if ($payload === null) {
            $this->error('Unknown or unavailable probe variant.');
            return 1;
        }

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (!$this->option('send')) {
            $this->comment('Dry run only. Add --send to transmit this payload.');
            return 0;
        }

        $this->warn('Sending a diagnostic callback can change ViMP state.');
        $result = $client->send($callback->user, $payload);
        $this->line('HTTP: ' . ($result['status_code'] ?: 'transport error'));
        $this->line('Response: ' . ($result['response'] ?: '[empty]'));

        return $result['status_code'] >= 200 && $result['status_code'] < 300 ? 0 : 1;
    }

    private function variantPayload(VimpCallback $callback, string $variant): ?array
    {
        if ($variant === 'current') {
            return $callback->payload;
        }

        if ($variant === 'minimal') {
            return ['mediakey' => $callback->mediakey];
        }

        if ($variant === 'label-only' && isset($callback->payload['medium']['label'])) {
            return [
                'mediakey' => $callback->mediakey,
                'medium' => ['label' => $callback->payload['medium']['label']],
            ];
        }

        if ($variant === 'source-url' && isset($callback->payload['medium'])) {
            $sourceUrl = data_get($callback->download, 'payload.source.url');
            if (!$sourceUrl) {
                return null;
            }

            $payload = $callback->payload;
            $payload['medium']['url'] = $sourceUrl;
            return $payload;
        }

        return null;
    }
}
