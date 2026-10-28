<?php

namespace NovaPoshta\Filament\Tests;

use Filament\Facades\Filament;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use NovaPoshta\Filament\Models\SyncRun;
use NovaPoshta\Filament\NovaPoshtaServiceProvider;
use NovaPoshta\Filament\Services\Synchronizer;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        $providers = [];
        $installed = json_decode(file_get_contents(__DIR__.'/../vendor/composer/installed.json'), true);
        foreach ($installed['packages'] as $package) {
            foreach ($package['extra']['laravel']['providers'] ?? [] as $provider) {
                if (str_starts_with($provider, 'Filament\\') || str_starts_with($provider, 'Livewire\\') || str_starts_with($provider, 'BladeUI\\') || str_starts_with($provider, 'RyanChandler\\')) {
                    $providers[] = $provider;
                }
            }
        }

        return [...$providers, NovaPoshtaServiceProvider::class, TestPanelProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.locale', 'uk');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $driver = getenv('NP_TEST_DB_DRIVER') ?: 'sqlite';
        if ($driver !== 'sqlite') {
            $database = getenv('NP_TEST_DB_DATABASE') ?: 'nova_poshta_test';
            if (! str_starts_with($database, 'nova_poshta_test')) {
                throw new \RuntimeException('Database tests require a dedicated nova_poshta_test* database.');
            }
            $app['config']->set('database.connections.testing', [
                'driver' => $driver, 'host' => '127.0.0.1',
                'port' => (int) (getenv('NP_TEST_DB_PORT') ?: ($driver === 'mysql' ? 3306 : 5432)),
                'database' => $database, 'username' => $driver === 'mysql' ? 'root' : 'postgres',
                'password' => getenv('NP_TEST_DB_PASSWORD') ?: 'password',
                'prefix' => '', 'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
            ]);
        }
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('nova-poshta.http.retry_ms', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('NP_TEST_DB_DRIVER') && getenv('NP_TEST_DB_DRIVER') !== 'sqlite') {
            $this->artisan('db:wipe', ['--database' => 'testing', '--force' => true])->run();
        }
        $this->artisan('migrate')->run();
        Http::preventStrayRequests();
        Filament::setCurrentPanel('admin');
    }

    protected function allow(): void
    {
        $user = new User;
        $user->forceFill(['id' => 1, 'name' => 'Tester', 'email' => 'test@example.test']);
        $this->actingAs($user);
        foreach (['view', 'manage', 'sync', 'diagnose'] as $ability) {
            Gate::define('nova-poshta.'.$ability, fn ($user) => true);
        }
    }

    protected function fakeDirectories(?string $fail = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($fail) {
            $method = $request['calledMethod'];
            $properties = (array) $request['methodProperties'];
            if ($method === $fail) {
                return Http::response(['success' => false, 'errors' => ['SECRET should never escape'], 'data' => []]);
            }
            $rows = match ($method) {
                'getAreas' => [['Ref' => 'company-area', 'Description' => 'Київська']],
                'getSettlements' => [
                    ['Ref' => 'settlement', 'Description' => 'Абазівка', 'DescriptionRu' => 'Абазовка', 'Area' => 'territorial-area', 'AreaDescription' => 'Київська', 'Region' => 'district', 'RegionsDescription' => 'Район'],
                    ['Ref' => 'other-settlement', 'Description' => 'Інше', 'Area' => 'other-area', 'AreaDescription' => 'Інша', 'Region' => 'other-district', 'RegionsDescription' => 'Інший район'],
                ],
                'getCities' => [['Ref' => 'city', 'Description' => 'Абазівка', 'Area' => 'company-area']],
                'getWarehouseTypes' => [['Ref' => 'type', 'Description' => 'Поштомат']],
                'getWarehouses' => [['Ref' => 'warehouse', 'Description' => 'Поштомат 1', 'CityRef' => 'city', 'SettlementRef' => 'settlement', 'TypeOfWarehouse' => 'type', 'CategoryOfWarehouse' => 'Postomat', 'Schedule' => ['Monday' => '00:00-23:59'], 'Latitude' => '50.45', 'Longitude' => '30.52', 'TotalMaxWeightAllowed' => '20']],
                default => [],
            };
            if ((int) ($properties['Page'] ?? 1) > 1) {
                $rows = [];
            }

            return Http::response(['success' => true, 'data' => $rows, 'info' => []]);
        });
    }

    protected function sync(?string $fail = null): SyncRun
    {
        $this->fakeDirectories($fail);
        $run = SyncRun::query()->create(['status' => 'running', 'stage' => 'queued', 'heartbeat_at' => now(), 'started_at' => now()]);
        app(Synchronizer::class)->run($run->id);

        $run->refresh();
        if ($fail === null) {
            $this->assertSame('completed', $run->status, json_encode($run->toArray()));
        }

        return $run;
    }
}
