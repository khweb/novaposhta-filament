<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use NovaPoshta\Filament\Models\SyncRun;
use NovaPoshta\Filament\Services\SyncCoordinator;
use NovaPoshta\Filament\Services\Synchronizer;
use NovaPoshta\Filament\Tests\TestCase;

// Test-only HTTP harness. Bind to loopback; never deploy this file.
// Serve published assets without booting Testbench or seeding fixture data.
$public = realpath(__DIR__.'/../../vendor/orchestra/testbench-core/laravel/public');
$assetPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$asset = $public ? realpath($public.'/'.ltrim($assetPath, '/')) : false;
$contentTypes = [
    'css' => 'text/css', 'js' => 'application/javascript', 'woff2' => 'font/woff2',
    'woff' => 'font/woff', 'svg' => 'image/svg+xml', 'png' => 'image/png',
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'ico' => 'image/x-icon',
];
if ($asset && str_starts_with($asset, $public.DIRECTORY_SEPARATOR) && is_file($asset)) {
    $contentType = $contentTypes[strtolower(pathinfo($asset, PATHINFO_EXTENSION))] ?? null;
    if ($contentType !== null) {
        header('Content-Type: '.$contentType);
        header('Cache-Control: public, max-age=3600');
        readfile($asset);

        return;
    }
}

require __DIR__.'/../../vendor/autoload.php';

final class BrowserHarness extends TestCase
{
    public function test_fixture(): void {}

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $directory = __DIR__.'/../.databases';
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $scenario = $this->scenario();
        $database = $directory.'/browser-fixture-'.$scenario.'.sqlite';
        if (! is_file($database)) {
            touch($database);
        }
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
    }

    private function scenario(): string
    {
        $scenario = $_SERVER['HTTP_X_NOVA_POSHTA_SCENARIO'] ?? 'completed';

        return in_array($scenario, ['completed', 'running', 'queued', 'failed'], true) ? $scenario : 'completed';
    }

    public function start(): Application
    {
        $this->setUp();
        $this->allow();
        if (! is_file(public_path('css/filament/filament/app.css'))) {
            $this->artisan('filament:assets')->run();
        }
        $scenario = $this->scenario();
        $this->fakeDirectories();
        $seeded = SyncRun::query()->exists();
        if (in_array($scenario, ['running', 'queued'], true)) {
            Queue::fake();
            if (! $seeded) {
                $run = app(SyncCoordinator::class)->request();
                if ($scenario === 'running') {
                    app(Synchronizer::class)->step($run->id, 0);
                }
            }
        } elseif (! $seeded) {
            $this->sync();
            if ($scenario === 'failed') {
                $this->sync('getWarehouses');
            }
        }
        config(['app.url' => 'http://127.0.0.1:8824', 'app.debug' => true]);

        return $this->app;
    }
}

// Real API by default; named scenarios explicitly opt into fixture mode.
if (isset($_SERVER['HTTP_X_NOVA_POSHTA_SCENARIO'])) {
    $harness = new BrowserHarness('test_fixture');
} else {
    require __DIR__.'/LiveHarness.php';
    $harness = new LiveHarness('test_fixture');
}
$app = $harness->start();
ValidateCsrfToken::except('*');
$request = Request::capture();
$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
