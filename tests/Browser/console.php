<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Models\SyncRun;
use NovaPoshta\Filament\Services\SyncCoordinator;

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/LiveHarness.php';

$harness = new LiveHarness('test_fixture');
$app = $harness->start();
$command = $argv[1] ?? 'status';

if ($command === 'work') {
    $lastTick = 0;
    Queue::looping(function () use (&$lastTick): void {
        if (time() - $lastTick >= 60) {
            app(SyncCoordinator::class)->tick();
            $lastTick = time();
        }
    });
    exit($app->make(Kernel::class)->call('queue:work', [
        'connection' => 'database', '--queue' => config('nova-poshta.queue'),
        '--sleep' => 1, '--tries' => 1, '--timeout' => 120,
    ]));
}

if ($command === 'sync') {
    $run = app(SyncCoordinator::class)->request();
    echo json_encode(['run_id' => $run->id, 'status' => $run->status], JSON_THROW_ON_ERROR).PHP_EOL;
} elseif ($command === 'status') {
    $settings = Settings::current();
    $run = SyncRun::query()->latest('id')->first();
    echo json_encode([
        'generation' => $settings->active_generation_id,
        'key_status' => $settings->key_status,
        'run' => $run?->only(['id', 'status', 'stage', 'counts', 'checkpoint', 'error', 'request_count', 'retry_count', 'next_attempt_at']),
        'pending_jobs' => DB::table('jobs')->count(),
        'failed_jobs' => DB::table('failed_jobs')->count(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL;
} else {
    fwrite(STDERR, 'Usage: php tests/Browser/console.php [status|sync|work]'.PHP_EOL);
    exit(1);
}
