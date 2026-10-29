<?php

namespace NovaPoshta\Filament\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Services\SyncCoordinator;

final class QueuedSyncTest extends TestCase
{
    public function test_real_database_worker_publishes_and_records_terminal_failure_safely(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        config([
            'queue.default' => 'database',
            'queue.connections.database' => ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 180],
            'queue.failed' => ['driver' => 'database-uuids', 'database' => 'testing', 'table' => 'failed_jobs'],
        ]);
        $this->fakeDirectories();
        $run = app(SyncCoordinator::class)->request();
        $this->assertSame('queued', $run->status);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1])->assertExitCode(0);
        $this->assertSame('completed', $run->refresh()->status);
        $this->assertSame(0, DB::table('jobs')->count());
        $published = Settings::current()->active_generation_id;

        $this->fakeDirectories('getWarehouses');
        $failed = app(SyncCoordinator::class)->request();
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1])->assertExitCode(0);
        $this->assertSame('failed', $failed->refresh()->status);
        $this->assertSame('upstream_api_error', $failed->error);
        $this->assertEquals($published, Settings::current()->active_generation_id);
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertStringNotContainsString('SECRET', DB::table('failed_jobs')->value('exception'));
    }
}
