<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Ensure APP_ENV is 'test' before loading .env files so that bootEnv()
// picks up .env.test instead of falling back to the default ('dev').
if (!isset($_SERVER['APP_ENV'])) {
    $_SERVER['APP_ENV'] = 'test';
    $_ENV['APP_ENV'] = 'test';
    putenv('APP_ENV=test');
}

if (method_exists(Dotenv::class, 'bootEnv')) {
    $dotenv = new Dotenv();
    $dotenv->bootEnv(dirname(__DIR__).'/.env');

    // Docker Compose env_file injects .env vars as real system env vars,
    // preventing bootEnv() from applying .env.test overrides.
    // Parse .env.test and force-populate to ensure test values win.
    $envTestFile = dirname(__DIR__).'/.env.test';
    if ($_SERVER['APP_ENV'] === 'test' && file_exists($envTestFile)) {
        $testVars = $dotenv->parse(file_get_contents($envTestFile), $envTestFile);
        $dotenv->populate($testVars, overrideExistingVars: true);
    }
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
