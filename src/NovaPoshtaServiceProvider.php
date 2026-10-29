<?php

declare(strict_types=1);

namespace NovaPoshta\Filament;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use NovaPoshta\Filament\Services\SyncCoordinator;
use NovaPoshta\Filament\Services\SyncJournal;
use NovaPoshta\Filament\Support\Tables;

final class NovaPoshtaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nova-poshta.php', 'nova-poshta');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nova-poshta');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'nova-poshta');
        foreach (['view', 'manage', 'sync', 'diagnose'] as $ability) {
            if (! Gate::has('nova-poshta.'.$ability)) {
                Gate::define('nova-poshta.'.$ability, fn ($user): bool => false);
            }
        }
        RateLimiter::for('nova-poshta', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('nova-poshta-streets', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        if (config('nova-poshta.rest.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }
        $this->publishes([__DIR__.'/../config/nova-poshta.php' => config_path('nova-poshta.php')], 'nova-poshta-config');
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->call(function (): void {
                if (Schema::hasTable(Tables::name('settings'))) {
                    app(SyncCoordinator::class)->tick();
                }
            })->name('nova-poshta:schedule')->everyMinute()->withoutOverlapping();
            $schedule->call(function (): void {
                if (Schema::hasTable(Tables::name('sync_events'))) {
                    app(SyncJournal::class)->prune();
                }
            })->name('nova-poshta:prune-journal')->hourly()->withoutOverlapping();
        });
    }
}
