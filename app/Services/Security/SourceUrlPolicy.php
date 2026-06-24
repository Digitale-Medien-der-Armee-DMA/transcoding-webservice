<?php

namespace App\Services\Security;

class SourceUrlPolicy
{
    public function allows(string $sourceUrl, ?string $userBaseUrl = null): bool
    {
        $parts = parse_url($sourceUrl);

        if (!isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (!in_array(strtolower($parts['scheme']), config('security.source_url.allowed_schemes', ['http', 'https']), true)) {
            return false;
        }

        if (!(bool) config('security.source_url.allowlist_enabled', false)) {
            return true;
        }

        $host = strtolower($parts['host']);
        $allowedHosts = array_map('strtolower', config('security.source_url.allowed_hosts', []));

        if (in_array($host, $allowedHosts, true)) {
            return true;
        }

        if ((bool) config('security.source_url.allow_user_host', true)) {
            $userHost = $this->hostFromUrl($userBaseUrl);

            return $userHost !== null && $host === $userHost;
        }

        return false;
    }

    private function hostFromUrl(?string $url): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        return isset($parts['host']) ? strtolower($parts['host']) : null;
    }
}
