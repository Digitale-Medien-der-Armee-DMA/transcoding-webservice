<?php

namespace App\Services\Security;

class MediaPathGuard
{
    public function isSafeRelativePath(?string $path): bool
    {
        if (!is_string($path) || $path === '') {
            return false;
        }

        if (strpos($path, "\0") !== false) {
            return false;
        }

        if (preg_match('/^[a-zA-Z]:[\/\\\\]/', $path) === 1) {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return false;
        }

        $segments = preg_split('/[\/\\\\]+/', $path);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    public function filterSafeRelativePaths(array $paths): array
    {
        return array_values(array_filter($paths, function ($path) {
            return $this->isSafeRelativePath($path);
        }));
    }

    public function isValidMediaKey(?string $mediakey): bool
    {
        return is_string($mediakey) && preg_match('/^[A-Za-z0-9]{32}$/', $mediakey) === 1;
    }
}
