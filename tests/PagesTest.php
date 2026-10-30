<?php

namespace NovaPoshta\Filament\Tests;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use NovaPoshta\Filament\Clusters\NovaPoshta;
use NovaPoshta\Filament\Jobs\SyncDirectories;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Pages\Directories;
use NovaPoshta\Filament\Pages\SettingsPage;
use NovaPoshta\Filament\Pages\SyncReportPage;
use NovaPoshta\Filament\Pages\Tester;
use NovaPoshta\Filament\Pages\Updates;
use NovaPoshta\Filament\Services\SyncCoordinator;
use NovaPoshta\Filament\Support\Lang;

final class PagesTest extends TestCase
{
    public function test_pages_are_closed_by_default(): void
    {
        foreach ([SettingsPage::class, Directories::class, Updates::class, Tester::class] as $page) {
            $this->assertFalse($page::canAccess());
            Livewire::test($page)->assertForbidden();
        }
    }

    public function test_settings_page_never_hydrates_saved_key_and_allows_replacement(): void
    {
        $this->allow();
        Queue::fake();
        Settings::current()->update(['api_key' => 'SECRET-OLD']);
        $page = Livewire::test(SettingsPage::class)->assertOk()->assertSet('data.replacement_key', null);
        $this->assertStringNotContainsString('SECRET-OLD', $page->html());
        $page->set('data.replacement_key', 'SECRET-NEW')->call('save')->assertHasNoErrors()->assertSet('data.replacement_key', null);
        $this->assertSame('SECRET-NEW', Settings::current()->api_key);
        Gate::define('nova-poshta.manage', fn ($user) => false);
        $page->call('save')->assertForbidden();
    }

    public function test_read_only_directory_and_update_pages_render_and_sync_is_gated(): void
    {
        $this->allow();
        $this->sync();
        Livewire::test(Directories::class)->assertOk()->assertSee('Абазівка')->searchTable('АБАЗ')->assertSee('Абазівка')->assertDontSee('Інше');
        Queue::fake();
        $page = Livewire::test(Updates::class)->assertOk()->call('updateNow')->assertHasNoErrors();
        Queue::assertPushed(SyncDirectories::class, 1);
        Gate::define('nova-poshta.sync', fn ($user) => false);
        $page->call('updateNow')->assertForbidden();
    }

    public function test_settings_schedule_modes_save_without_replacing_the_key(): void
    {
        $this->allow();
        Queue::fake();
        Settings::current()->update(['api_key' => 'KEEP-EXISTING-KEY']);
        $page = Livewire::test(SettingsPage::class)->assertOk();

        foreach (['hourly', 'daily', 'weekly', 'monthly'] as $frequency) {
            $schedule = [
                'enabled' => true, 'frequency' => $frequency, 'hours' => 6,
                'weekday' => 3, 'day' => 15, 'time' => '09:30', 'timezone' => 'Europe/Kyiv',
            ];
            $page->fillForm(['schedule' => $schedule])->call('save')->assertHasNoErrors()
                ->assertSet('data.replacement_key', null);
            $saved = Settings::current();
            $this->assertSame($frequency, $saved->schedule['frequency']);
            $this->assertTrue($saved->schedule['enabled']);
            $this->assertSame('Europe/Kyiv', $saved->schedule['timezone']);
            $field = match ($frequency) {
                'hourly' => 'hours', 'daily' => 'time', 'weekly' => 'weekday', 'monthly' => 'day',
            };
            $this->assertEquals($schedule[$field], $saved->schedule[$field]);
            $this->assertSame('KEEP-EXISTING-KEY', $saved->api_key);
            $this->assertStringNotContainsString('KEEP-EXISTING-KEY', $page->html());
        }
        Queue::assertNothingPushed();
    }

    public function test_directory_navigation_opens_each_directory_from_its_url(): void
    {
        $this->allow();
        $this->sync();

        foreach (['areas', 'districts', 'settlements', 'warehouses', 'warehouse-types'] as $directory) {
            $page = Livewire::withQueryParams(['directory' => $directory])->test(Directories::class)
                ->assertOk()->assertSet('directory', $directory);
            $this->assertSame(Lang::get($directory), $page->instance()->getTitle());
            $items = $page->instance()->getSubNavigation();
            $this->assertCount(4, $items);
            $this->assertSame([Lang::get('settings'), Lang::get('directories'), Lang::get('updates'), Lang::get('tester')], array_map(fn ($item) => $item->getLabel(), $items));
            foreach ($items as $item) {
                $page->assertSeeHtml($item->getUrl());
            }
            foreach (['areas', 'districts', 'settlements', 'warehouses', 'warehouse-types'] as $target) {
                $page->assertSeeHtml(Directories::getUrl(['directory' => $target]));
            }
            $this->assertCount(5, $items[1]->getChildItems());
            $this->assertCount(1, array_filter($items[1]->getChildItems(), fn ($item) => $item->isActive()));
            $directory === 'warehouses'
                ? $page->assertTableColumnVisible('type')->assertSee('Поштомат 1')
                : $page->assertTableColumnHidden('type');
        }
    }

    public function test_cluster_has_one_main_menu_item_and_keeps_existing_page_urls(): void
    {
        $this->allow();
        $navigation = collect(Filament::getNavigation())->flatMap(fn ($group) => $group->getItems());
        $this->assertCount(1, $navigation);
        $this->assertSame(Lang::get('plugin'), $navigation->first()->getLabel());
        $this->assertSame('/admin/nova-poshta/directories', parse_url(Directories::getUrl(), PHP_URL_PATH));
        $this->get(NovaPoshta::getUrl())->assertRedirect(SettingsPage::getUrl());
    }

    public function test_second_level_navigation_only_contains_authorized_pages(): void
    {
        $this->allow();
        Gate::define('nova-poshta.manage', fn ($user) => false);
        Gate::define('nova-poshta.diagnose', fn ($user) => false);
        $page = Livewire::test(Directories::class)->assertOk();
        $items = $page->instance()->getSubNavigation();
        $this->assertSame([Lang::get('directories'), Lang::get('updates')], array_map(fn ($item) => $item->getLabel(), $items));
        $this->get(NovaPoshta::getUrl())->assertRedirect(Directories::getUrl());
        Gate::define('nova-poshta.view', fn ($user) => false);
        $this->get(NovaPoshta::getUrl())->assertForbidden();
    }

    public function test_updates_summary_and_header_action_follow_the_active_run(): void
    {
        $this->allow();
        Queue::fake();
        $page = Livewire::test(Updates::class)->assertOk()
            ->assertSee(Lang::get('updates_idle'))->assertSee(Lang::get('updates_disabled'))
            ->assertSee(Lang::get('updates_empty'));
        $this->assertNull($page->instance()->getTable()->getPollingInterval());

        $run = app(SyncCoordinator::class)->request();
        $page = Livewire::test(Updates::class)->assertOk()->assertSee(Lang::get('updates_open_active'));
        $this->assertSame('2s', $page->instance()->getTable()->getPollingInterval());
        $page->callAction('updateNow')->assertRedirect(SyncReportPage::getUrl(['runId' => $run->id]));
        Queue::assertPushed(SyncDirectories::class, 1);
    }

    public function test_directory_url_rejects_unsupported_directories(): void
    {
        $this->allow();
        foreach (['streets', 'unknown'] as $directory) {
            Livewire::withQueryParams(['directory' => $directory])->test(Directories::class)->assertStatus(422);
        }
    }

    public function test_directory_empty_state_explains_when_data_is_not_loaded(): void
    {
        $this->allow();
        Livewire::test(Directories::class)->assertOk()
            ->assertSee(Lang::get('directories_not_loaded'))
            ->assertSee(Lang::get('directory_not_ready'));
    }

    public function test_tester_renders_and_resets_children_when_parent_changes(): void
    {
        $this->allow();
        $this->sync();
        $page = Livewire::test(Tester::class)->assertOk();
        $page->fillForm(['area_ref' => 'territorial-area', 'district_ref' => 'district', 'settlement_ref' => 'settlement', 'warehouse_ref' => 'warehouse']);
        $page->fillForm(['area_ref' => 'other-area'])->assertSet('data.district_ref', null)
            ->assertSet('data.settlement_ref', null)->assertSet('data.warehouse_ref', null);
    }

    public function test_tester_handles_not_ready_and_connection_errors_safely(): void
    {
        $this->allow();
        Http::fake(fn () => Http::response(['success' => false, 'data' => [], 'errors' => ['SECRET']]));
        Livewire::test(Tester::class)->assertOk()->assertSee(Lang::get('directory_not_ready'))->call('checkConnection')->assertHasNoErrors()
            ->assertNotified(Lang::get('upstream_api_error'));
        Gate::define('nova-poshta.diagnose', fn ($user) => false);
        Livewire::test(Tester::class)->assertForbidden();
    }

    public function test_all_languages_have_same_keys_and_unknown_locale_falls_back_to_uk(): void
    {
        $uk = require __DIR__.'/../resources/lang/uk/messages.php';
        foreach (['en', 'ru'] as $locale) {
            $this->assertSame(array_keys($uk), array_keys(require __DIR__.'/../resources/lang/'.$locale.'/messages.php'));
        }
        app()->setLocale('fr');
        $this->assertSame('Налаштування', Lang::get('settings'));
    }
}
