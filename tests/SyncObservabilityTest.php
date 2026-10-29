<?php

namespace NovaPoshta\Filament\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use NovaPoshta\Filament\Exceptions\DirectoryException;
use NovaPoshta\Filament\Jobs\CheckApiKey;
use NovaPoshta\Filament\Jobs\SyncDirectories;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Models\SyncEvent;
use NovaPoshta\Filament\Models\SyncRun;
use NovaPoshta\Filament\Pages\SyncReportPage;
use NovaPoshta\Filament\Pages\Updates;
use NovaPoshta\Filament\Services\KeyHealth;
use NovaPoshta\Filament\Services\SettingsService;
use NovaPoshta\Filament\Services\SyncCoordinator;
use NovaPoshta\Filament\Services\Synchronizer;
use NovaPoshta\Filament\Services\SyncJournal;
use NovaPoshta\Filament\Services\SyncReport;
use NovaPoshta\Filament\Support\Lang;
use NovaPoshta\Filament\Support\Tables;

final class SyncObservabilityTest extends TestCase
{
    private function queued(): SyncRun
    {
        return SyncRun::query()->create(['status' => 'queued', 'heartbeat_at' => now()])->refresh();
    }

    public function test_each_job_commits_one_page_and_duplicate_delivery_is_ignored(): void
    {
        Queue::fake();
        $this->fakeDirectories();
        $run = app(SyncCoordinator::class)->request();
        $job = new SyncDirectories($run->id, 0);
        $job->handle(app(Synchronizer::class));
        $this->assertSame('running', $run->refresh()->status);
        $this->assertSame(1, $run->revision);
        $this->assertSame('settlements', $run->stage);
        $this->assertNull(Settings::current()->active_generation_id);
        $count = SyncEvent::query()->count();
        $job->handle(app(Synchronizer::class));
        $this->assertSame(1, $run->refresh()->revision);
        $this->assertSame($count, SyncEvent::query()->count());
        Http::assertSentCount(1);
        Queue::assertPushed(SyncDirectories::class, fn ($job) => $job->revision === 1);
        $this->fakeDirectories();
        app(Synchronizer::class)->run($run->id);
        $this->assertSame('completed', $run->refresh()->status);
        $this->assertSame(1, DB::table(Tables::name('warehouses'))->count());
    }

    public function test_temporary_error_is_delayed_then_recovers_with_accurate_counters(): void
    {
        $this->freezeTime();
        Http::fakeSequence()->push([], 429, ['Retry-After' => '30']);
        $run = $this->queued();
        $sync = app(Synchronizer::class);
        $delay = $sync->step($run->id, 0);
        $this->assertGreaterThanOrEqual(30, $delay);
        $this->assertSame('running', $run->refresh()->status);
        $this->assertSame(1, (int) $run->retry_count);
        $this->assertSame(1, (int) $run->error_count);
        Http::assertSentCount(1);
        $sync->step($run->id, $run->revision);
        Http::assertSentCount(1); // A duplicate early delivery respects Retry-After.
        $this->travel($delay + 1)->seconds();
        $this->fakeDirectories();
        $sync->run($run->id);
        $this->assertSame('completed', $run->refresh()->status);
        $this->assertSame(1, (int) $run->retry_count);
        $this->assertSame(1, (int) $run->error_count);
        $this->assertSame(9, (int) $run->request_count);
        $this->assertTrue(SyncEvent::query()->where('code', 'sync_published')->exists());
    }

    public function test_exhausted_retries_fail_safely_and_keep_published_generation(): void
    {
        $this->sync();
        $published = Settings::current()->active_generation_id;
        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['private' => 'SECRET-KEY'], 503));
        $run = $this->queued();
        $sync = app(Synchronizer::class);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $delay = $sync->step($run->id, $run->refresh()->revision);
                $this->travel($delay + 1)->seconds();
            } catch (DirectoryException $exception) {
                $this->assertSame('upstream_http_error', $exception->reason);
                $this->assertNull($exception->getPrevious());
            }
        }
        $this->assertSame('failed', $run->refresh()->status);
        $this->assertSame(3, (int) $run->error_count);
        $this->assertSame(2, (int) $run->retry_count);
        $this->assertEquals($published, Settings::current()->active_generation_id);
        $this->assertStringNotContainsString('SECRET', SyncEvent::all()->toJson());
        $this->assertTrue(SyncEvent::query()->where('code', 'restart_needed')->exists());
    }

    public function test_local_rate_limit_delays_without_sending_an_external_request(): void
    {
        config(['nova-poshta.http.per_minute' => 1]);
        RateLimiter::hit('nova-poshta:external', 60);
        $run = $this->queued();
        $this->assertGreaterThan(0, app(Synchronizer::class)->step($run->id, 0));
        Http::assertNothingSent();
        $this->assertSame('running', $run->refresh()->status);
    }

    public function test_repeated_page_is_rejected_across_persisted_checkpoints(): void
    {
        Http::fake(fn () => Http::response(['success' => true, 'data' => [['Ref' => 'same', 'Description' => 'Same', 'Area' => 'area', 'AreaDescription' => 'Area']], 'info' => []]));
        $run = $this->queued();
        $sync = app(Synchronizer::class);
        $sync->step($run->id, 0); // Areas.
        $sync->step($run->id, $run->refresh()->revision); // First settlements page.
        try {
            $sync->step($run->id, $run->refresh()->revision);
            $this->fail('Repeated page must fail');
        } catch (DirectoryException $exception) {
            $this->assertSame('invalid_or_repeated_page', $exception->reason);
        }
        $this->assertSame(1, DB::table(Tables::name('settlements'))->count());
        $this->assertSame(1, $run->refresh()->counts['settlements']);
        $this->assertNull(Settings::current()->active_generation_id);
    }

    public function test_lost_dispatch_is_recovered_and_old_revision_cannot_fail_the_run(): void
    {
        Queue::fake();
        $this->fakeDirectories();
        $run = $this->queued();
        app(Synchronizer::class)->step($run->id, 0);
        $run->refresh()->update(['heartbeat_at' => now()->subSeconds(300)]);
        app(SyncCoordinator::class)->tick();
        Queue::assertPushed(SyncDirectories::class, fn ($job) => $job->runId === $run->id && $job->revision === 1);
        (new SyncDirectories($run->id, 0))->failed(new \RuntimeException('old delivery'));
        $this->assertSame('running', $run->refresh()->status);
    }

    public function test_pruning_removes_events_but_preserves_active_runs_and_last_success(): void
    {
        $run = $this->sync();
        $last = Settings::current()->last_success_at;
        $generation = $run->generation_id;
        $run->update(['created_at' => now()->subDays(8)]);
        $active = $this->queued();
        $active->update(['created_at' => now()->subDays(8)]);
        app(SyncJournal::class)->prune();
        $this->assertNull($run->fresh());
        $this->assertNotNull($active->fresh());
        $this->assertSame(0, SyncEvent::query()->where('sync_run_id', $run->id)->count());
        $this->assertEquals($generation, Settings::current()->active_generation_id);
        $this->assertTrue($last->equalTo(Settings::current()->last_success_at));
        $this->assertSame(1, DB::table(Tables::name('warehouses'))->count());
    }

    public function test_key_health_distinguishes_rejection_and_unavailability_without_leaking_data(): void
    {
        Settings::current()->update(['api_key' => 'SECRET-KEY', 'key_revision' => 'first']);
        Http::fakeSequence()->push(['success' => false, 'data' => [], 'errors' => ['API key invalid: SECRET-KEY']])
            ->push([], 500)->push([], 500)->push([], 500)
            ->push(['success' => true, 'data' => [['Private' => 'PRIVATE-COUNTERPARTY']]]);
        $health = app(KeyHealth::class);
        $this->assertSame('key_rejected', $health->check());
        $this->assertSame('key_rejected', Settings::current()->key_status);
        $this->assertSame('key_unavailable', $health->check());
        $this->assertSame('key_valid', $health->check());
        $this->assertNotNull(Settings::current()->key_checked_at);
        $this->assertStringNotContainsString('SECRET', Settings::current()->toJson());
        $this->assertStringNotContainsString('PRIVATE', SyncEvent::all()->toJson());
    }

    public function test_late_key_check_cannot_overwrite_replacement_status(): void
    {
        Settings::current()->update(['api_key' => 'OLD', 'key_revision' => 'old']);
        Http::fake(function () {
            Settings::current()->update(['api_key' => 'NEW', 'key_revision' => 'new', 'key_status' => 'unchecked']);

            return Http::response(['success' => true, 'data' => []]);
        });
        app(KeyHealth::class)->check('old');
        $this->assertSame('unchecked', Settings::current()->key_status);
        $this->assertSame('unchecked', app(KeyHealth::class)->check('old'));
        Http::assertSentCount(1);
    }

    public function test_replacing_key_queues_check_without_serializing_secret(): void
    {
        Queue::fake();
        app(SettingsService::class)->save(Settings::current()->schedule, 'NEW-SECRET');
        Queue::assertPushed(CheckApiKey::class, function ($job) {
            $this->assertStringNotContainsString('NEW-SECRET', serialize($job));

            return $job->revision === Settings::current()->key_revision;
        });
    }

    public function test_report_is_gated_and_renders_complete_human_readable_log(): void
    {
        $run = $this->sync();
        Livewire::test(SyncReportPage::class, ['runId' => $run->id])->assertForbidden();
        $this->allow();
        Livewire::test(SyncReportPage::class, ['runId' => $run->id])->assertOk()
            ->assertSee(Lang::get('sync_published'))->assertSee('100%')->assertDontSee('wire:poll.2s', false);
        $this->get(SyncReportPage::getUrl(['runId' => $run->id]))->assertOk();
    }

    public function test_log_navigation_is_bounded_and_pausing_follow_keeps_cursor(): void
    {
        $this->allow();
        $run = $this->queued();
        for ($i = 0; $i < 110; $i++) {
            app(SyncJournal::class)->event($run, 'page_requested', context: ['page' => $i + 1, 'attempt' => 1, 'apiKey' => 'SECRET']);
        }
        $page = Livewire::test(SyncReportPage::class, ['runId' => $run->id])->assertOk()->assertSee('wire:poll.2s', false);
        $this->assertCount(50, $page->instance()->events());
        $page->call('older')->assertSet('follow', false);
        $end = $page->get('logEnd');
        app(SyncJournal::class)->event($run, 'sync_published');
        $page->call('refreshReport')->assertSet('logEnd', $end)->call('newer')->call('latest')->assertSet('follow', true);
        $this->assertSame((int) SyncEvent::query()->max('id'), $page->get('logEnd'));
        $this->assertStringNotContainsString('SECRET', $page->html());
        Gate::define('nova-poshta.view', fn () => false);
        $page->call('refreshReport')->assertForbidden();
    }

    public function test_history_hides_old_terminal_runs_and_restart_is_authorized(): void
    {
        $this->allow();
        $old = $this->sync();
        $old->update(['created_at' => now()->subDays(8)]);
        Livewire::test(Updates::class)->assertCanNotSeeTableRecords([$old]);
        Livewire::test(SyncReportPage::class, ['runId' => $old->id])->assertNotFound();
        Queue::fake();
        $failed = $this->queued();
        app(Synchronizer::class)->fail($failed->id, 'worker_failed');
        Gate::define('nova-poshta.sync', fn () => false);
        Livewire::test(SyncReportPage::class, ['runId' => $failed->id])->call('restart')->assertForbidden();
        Gate::define('nova-poshta.sync', fn () => true);
        Livewire::test(SyncReportPage::class, ['runId' => $failed->id])->call('restart')->assertRedirect();
        Queue::assertPushed(SyncDirectories::class, fn ($job) => $job->runId !== $failed->id);
    }

    public function test_progress_reaches_completion_only_after_publication(): void
    {
        $run = $this->queued();
        $run->update(['status' => 'running', 'checkpoint' => ['stage' => 6]]);
        $this->assertSame(6, app(SyncReport::class)->progress($run));
        $this->assertNull(Settings::current()->last_success_at);
        $run->update(['status' => 'completed']);
        $this->assertSame(7, app(SyncReport::class)->progress($run));
    }

    public function test_page_insert_and_checkpoint_roll_back_together_on_database_failure(): void
    {
        $this->fakeDirectories();
        $run = $this->queued();
        $sync = app(Synchronizer::class);
        $sync->step($run->id, 0);
        $throwOnce = true;
        DB::listen(function ($query) use (&$throwOnce): void {
            if ($throwOnce && str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, Tables::name('settlements'))) {
                $throwOnce = false;
                throw new \RuntimeException('PRIVATE SQL DETAILS');
            }
        });
        try {
            $sync->step($run->id, 1);
            $this->fail('Insert failure must fail the run');
        } catch (DirectoryException $exception) {
            $this->assertSame('synchronization_failed', $exception->reason);
        }
        $this->assertSame(0, DB::table(Tables::name('settlements'))->count());
        $this->assertSame(1, $run->refresh()->revision);
        $this->assertArrayNotHasKey('settlements', $run->counts);
        $this->assertStringNotContainsString('PRIVATE', SyncEvent::all()->toJson());
    }

    public function test_worker_revoked_during_http_request_cannot_commit_the_page(): void
    {
        $run = $this->queued();
        Http::fake(function () use ($run) {
            app(Synchronizer::class)->fail($run->id, 'heartbeat_expired');

            return Http::response(['success' => true, 'data' => [['Ref' => 'area', 'Description' => 'Area']]]);
        });
        $this->assertNull(app(Synchronizer::class)->step($run->id, 0));
        $this->assertSame('failed', $run->refresh()->status);
        $this->assertSame(0, DB::table(Tables::name('areas'))->count());
        $this->assertNull(Settings::current()->active_generation_id);
    }

    public function test_live_report_refreshes_counts_and_final_log_then_stops_polling(): void
    {
        $this->allow();
        $this->fakeDirectories();
        $run = $this->queued();
        $page = Livewire::test(SyncReportPage::class, ['runId' => $run->id])->assertSee(Lang::get('waiting_worker'));
        $sync = app(Synchronizer::class);
        $sync->step($run->id, 0);
        $page->call('refreshReport')->assertSee(Lang::get('sync_started'))->assertSee('wire:poll.2s', false);
        $sync->run($run->id);
        $page->call('refreshReport')->assertSee(Lang::get('sync_published'))->assertSee('100%')->assertDontSee('wire:poll.2s', false);
        $this->assertSame('completed', $run->refresh()->status);
    }

    public function test_upgrade_migration_keeps_existing_directories_and_backfills_last_publication(): void
    {
        $run = $this->sync();
        Settings::current()->update(['api_key' => 'EXISTING-KEY']);
        $ciphertext = DB::table(Tables::name('settings'))->value('api_key');
        $migration = require __DIR__.'/../database/migrations/2026_10_29_000002_add_sync_observability.php';
        $migration->down();
        $this->assertSame('completed', SyncRun::query()->findOrFail($run->id)->status);
        $migration->up();
        $this->assertEquals($run->generation_id, Settings::current()->active_generation_id);
        $this->assertNotNull(Settings::current()->last_success_at);
        $this->assertSame($ciphertext, DB::table(Tables::name('settings'))->value('api_key'));
        $this->assertSame('EXISTING-KEY', Settings::current()->api_key);
        $this->assertSame(1, DB::table(Tables::name('warehouses'))->count());
    }

    public function test_synchronous_queue_cannot_recursively_dispatch_a_delayed_retry(): void
    {
        Http::fake(fn () => Http::response([], 429));
        $run = $this->queued();
        (new SyncDirectories($run->id))->handle(app(Synchronizer::class));
        $this->assertSame('failed', $run->refresh()->status);
        $this->assertSame('async_queue_required', $run->error);
        Http::assertSentCount(1);
    }
}
