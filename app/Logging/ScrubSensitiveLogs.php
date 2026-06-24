<?php

namespace App\Logging;

class ScrubSensitiveLogs
{
    public function __invoke($logger): void
    {
        if (!(bool) config('security.logs.scrubbing_enabled', true)) {
            return;
        }

        foreach ($logger->getHandlers() as $handler) {
            if (!method_exists($handler, 'pushProcessor')) {
                continue;
            }

            $handler->pushProcessor(function (array $record): array {
                $record['message'] = $this->scrubString($record['message']);
                $record['context'] = $this->scrubValue($record['context']);
                $record['extra'] = $this->scrubValue($record['extra']);

                return $record;
            });
        }
    }

    private function scrubValue($value)
    {
        if (is_array($value)) {
            $scrubbed = [];

            foreach ($value as $key => $item) {
                $scrubbed[$key] = $this->isSensitiveKey((string) $key)
                    ? config('security.logs.redacted_value', '[redacted]')
                    : $this->scrubValue($item);
            }

            return $scrubbed;
        }

        if (is_string($value)) {
            return $this->scrubString($value);
        }

        return $value;
    }

    private function scrubString(string $value): string
    {
        $redacted = preg_replace('/(api_token|token|password|secret)=([^&\s]+)/i', '$1=' . config('security.logs.redacted_value', '[redacted]'), $value);
        $redacted = preg_replace('/(Authorization:\s*Bearer\s+)[A-Za-z0-9._\-]+/i', '$1' . config('security.logs.redacted_value', '[redacted]'), $redacted);

        return $redacted;
    }

    private function isSensitiveKey(string $key): bool
    {
        foreach (config('security.logs.sensitive_keys', []) as $sensitiveKey) {
            if (strtolower($key) === strtolower($sensitiveKey)) {
                return true;
            }
        }

        return false;
    }
}
