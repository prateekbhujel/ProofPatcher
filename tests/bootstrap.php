<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// The test app needs its own disposable encryption key, never a developer's key.
$testAppKey = 'base64:' . base64_encode(random_bytes(32));
putenv('APP_KEY=' . $testAppKey);
$_ENV['APP_KEY'] = $testAppKey;
$_SERVER['APP_KEY'] = $testAppKey;

// Tests always use a synthetic repository; never inherit a developer's private target.
unset($_ENV['PROOFPATCH_TEST_REPO'], $_SERVER['PROOFPATCH_TEST_REPO']);
unset($_ENV['PROOFPATCH_TEST_BASE_COMMIT'], $_SERVER['PROOFPATCH_TEST_BASE_COMMIT']);
putenv('PROOFPATCH_TEST_REPO=example-owner/proofpatch-test');
putenv('PROOFPATCH_TEST_BASE_COMMIT=' . str_repeat('a', 40));

// Safe suite-wide fallback temporary SQLite database BEFORE any test or Laravel bootstrap can run.
// This guarantees that any test that does not initialize an explicit isolated test DB will NEVER touch
// the development/production database at database/proofpatch.sqlite.
$suiteFallbackDir = sys_get_temp_dir() . '/proofpatch-suite-guard-' . bin2hex(random_bytes(6));
if (!is_dir($suiteFallbackDir)) {
    mkdir($suiteFallbackDir, 0700, true);
}
$suiteFallbackDb = $suiteFallbackDir . '/suite-fallback.sqlite';
touch($suiteFallbackDb);
$suiteStorage = $suiteFallbackDir . '/storage';
foreach (['app', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
    if (!mkdir($suiteStorage . '/' . $directory, 0700, true) && !is_dir($suiteStorage . '/' . $directory)) {
        throw new RuntimeException('Unable to create isolated test storage.');
    }
}
$_ENV['LARAVEL_STORAGE_PATH'] = $suiteStorage;
$_SERVER['LARAVEL_STORAGE_PATH'] = $suiteStorage;

putenv('PROOFPATCH_DB=' . $suiteFallbackDb);
$_ENV['PROOFPATCH_DB'] = $suiteFallbackDb;
$_SERVER['PROOFPATCH_DB'] = $suiteFallbackDb;

// Isolate Muse provider selection from the developer's real encrypted config.
// Without this, ProviderConfigStore::activeTarget() resolves ambient HOME state
// (e.g. Meta selected in Settings) and tests asserting the Baseten default fail.
$suiteProviderConfig = $suiteFallbackDir . '/provider_config.enc';
putenv('PROOFPATCH_PROVIDER_CONFIG_FILE=' . $suiteProviderConfig);
$_ENV['PROOFPATCH_PROVIDER_CONFIG_FILE'] = $suiteProviderConfig;
$_SERVER['PROOFPATCH_PROVIDER_CONFIG_FILE'] = $suiteProviderConfig;

register_shutdown_function(static function () use ($suiteFallbackDb, $suiteFallbackDir, $suiteStorage, $suiteProviderConfig): void {
    if (is_dir($suiteStorage)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($suiteStorage, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($suiteStorage);
    }
    if (file_exists($suiteFallbackDb)) {
        @unlink($suiteFallbackDb);
    }
    if (file_exists($suiteProviderConfig)) {
        @unlink($suiteProviderConfig);
    }
    if (is_dir($suiteFallbackDir)) {
        @rmdir($suiteFallbackDir);
    }
});
