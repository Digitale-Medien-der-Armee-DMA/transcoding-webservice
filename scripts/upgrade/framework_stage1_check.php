<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$composerJson = json_decode(file_get_contents($root . '/composer.json'), true);
$composerLock = json_decode(file_get_contents($root . '/composer.lock'), true);

if (!is_array($composerJson) || !is_array($composerLock)) {
    fwrite(STDERR, "Unable to read composer.json or composer.lock\n");
    exit(1);
}

$packages = [];

foreach (array_merge($composerLock['packages'] ?? [], $composerLock['packages-dev'] ?? []) as $package) {
    $packages[$package['name']] = $package;
}

$expectedLockedPackages = [
    'laravel/framework' => 'v8.83.29',
    'laravel/ui' => 'v3.4.6',
    'encore/laravel-admin' => 'v1.8.11',
    'guzzlehttp/guzzle' => '7.12.3',
    'laravel/legacy-factories' => 'v1.4.2',
    'php-ffmpeg/php-ffmpeg' => 'v0.16',
    'phpunit/phpunit' => '9.6.34',
];

$errors = [];

foreach ($expectedLockedPackages as $packageName => $expectedVersion) {
    if (!isset($packages[$packageName])) {
        $errors[] = "Missing locked package {$packageName}";
        continue;
    }

    $actualVersion = $packages[$packageName]['version'] ?? '';

    if ($actualVersion !== $expectedVersion) {
        $errors[] = "{$packageName} is locked at {$actualVersion}, expected {$expectedVersion}; update docs/FRAMEWORK_UPGRADE_STAGE_1.md with the new baseline";
    }
}

$phpConstraint = $composerJson['require']['php'] ?? null;
$laravelConstraint = $composerJson['require']['laravel/framework'] ?? null;

if ($phpConstraint !== '^7.4') {
    $errors[] = "composer.json PHP constraint is {$phpConstraint}, expected ^7.4 for Laravel 8 baseline";
}

if ($laravelConstraint !== '^8.83') {
    $errors[] = "composer.json Laravel constraint is {$laravelConstraint}, expected ^8.83 for Laravel 8 baseline";
}

if (PHP_VERSION_ID < 70400 || PHP_VERSION_ID >= 80000) {
    $errors[] = 'Laravel 8 baseline CI must run on PHP 7.4 before the PHP 8 runtime hop';
}

$phpFfmpeg = $packages['php-ffmpeg/php-ffmpeg']['require']['php'] ?? '';

if (strpos($phpFfmpeg, '^8') !== false) {
    $errors[] = 'php-ffmpeg/php-ffmpeg unexpectedly advertises PHP 8 support; revisit the blocker notes';
}

if ($errors !== []) {
    fwrite(STDERR, "Framework upgrade baseline check failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "- {$error}\n");
    }
    exit(1);
}

echo "Framework upgrade baseline:\n";
foreach ($expectedLockedPackages as $packageName => $expectedVersion) {
    echo "- {$packageName}: {$expectedVersion}\n";
}
echo "- php constraint: {$phpConstraint}\n";
echo "- laravel/framework constraint: {$laravelConstraint}\n";
echo "- ci php: " . PHP_VERSION . "\n";
echo "Next dependency hop is documented in docs/FRAMEWORK_UPGRADE_STAGE_1.md\n";
