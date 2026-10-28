<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use NovaPoshta\Filament\Tests\TestCase;

// Test-only HTTP harness. Bind to loopback; never deploy this file.
require __DIR__.'/../../vendor/autoload.php';

final class BrowserHarness extends TestCase
{
    public function test_fixture(): void {}

    public function start(): Application
    {
        $this->setUp();
        $this->allow();
        if (! is_file(public_path('css/filament/filament/app.css'))) {
            $this->artisan('filament:assets')->run();
        }
        $this->sync();
        config(['app.url' => 'http://127.0.0.1:8824']);

        return $this->app;
    }
}

$harness = new BrowserHarness('test_fixture');
$app = $harness->start();
ValidateCsrfToken::except('*');
$assetPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$asset = realpath(public_path(ltrim($assetPath, '/')));
$public = realpath(public_path());
if ($asset && str_starts_with($asset, $public.DIRECTORY_SEPARATOR) && is_file($asset)) {
    $extension = pathinfo($asset, PATHINFO_EXTENSION);
    header('Content-Type: '.match ($extension) {
        'css' => 'text/css', 'js' => 'application/javascript', 'woff2' => 'font/woff2', default => 'application/octet-stream'
    });
    readfile($asset);

    return;
}
$request = Request::capture();
$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
