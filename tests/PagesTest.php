<?php

namespace NovaPoshta\Filament\Tests;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use NovaPoshta\Filament\Jobs\SyncDirectories;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Pages\Directories;
use NovaPoshta\Filament\Pages\SettingsPage;
use NovaPoshta\Filament\Pages\Tester;
use NovaPoshta\Filament\Pages\Updates;
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
