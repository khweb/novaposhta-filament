<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use NovaPoshta\Filament\Tests\TestCase;

// Local development only: shares the existing browser database and encrypted key.
final class LiveHarness extends TestCase
{
    public function test_fixture(): void {}

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $directory = dirname(__DIR__).'/.databases';
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $database = $directory.'/browser-completed.sqlite';
        if (! is_file($database)) {
            touch($database);
        }
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => $database, 'prefix' => '',
            'foreign_key_constraints' => true, 'busy_timeout' => 10000,
            'journal_mode' => 'WAL',
        ]);
        $app['config']->set('cache.default', 'file');
        $app['config']->set('cache.stores.file', [
            'driver' => 'file', 'path' => $directory.'/cache', 'lock_path' => $directory.'/cache',
        ]);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database', 'connection' => 'testing', 'table' => 'jobs',
            'queue' => 'default', 'retry_after' => 180,
        ]);
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids', 'database' => 'testing', 'table' => 'failed_jobs',
        ]);
        $app['config']->set('app.url', 'http://127.0.0.1:8824');
        $app['config']->set('app.debug', false);
    }

    public function start(): Application
    {
        if (getenv('NP_TEST_DB_DRIVER') && getenv('NP_TEST_DB_DRIVER') !== 'sqlite') {
            throw new RuntimeException('Local browser harness requires SQLite.');
        }
        $this->setUp();
        $this->allow();
        // TestCase blocks the network. Live mode deliberately uses an unfaked factory.
        Http::swap(new Factory);
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
        if (! is_file(public_path('css/filament/filament/app.css'))) {
            $this->artisan('filament:assets')->run();
        }

        return $this->app;
    }
}
