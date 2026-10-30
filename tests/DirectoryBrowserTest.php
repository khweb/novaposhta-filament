<?php

namespace NovaPoshta\Filament\Tests;

use Filament\Actions\Testing\TestAction;
use Filament\Infolists\Components\Entry;
use Illuminate\Support\Facades\DB;
use Illuminate\View\ComponentAttributeBag;
use Livewire\Livewire;
use NovaPoshta\Filament\Pages\Directories;
use NovaPoshta\Filament\Services\DirectoryBrowser;
use NovaPoshta\Filament\Support\Lang;
use NovaPoshta\Filament\Support\Tables;

final class DirectoryBrowserTest extends TestCase
{
    public function test_parents_and_counts_belong_to_the_requested_generation(): void
    {
        $first = $this->sync()->generation_id;
        $second = $this->sync()->generation_id;
        DB::table(Tables::name('areas'))->where('generation_id', $first)
            ->where('ref', 'territorial-area')->update(['name' => 'OLD AREA']);
        $browser = app(DirectoryBrowser::class);
        $this->assertSame(['areas' => 2, 'districts' => 2, 'settlements' => 2, 'warehouses' => 1, 'warehouse-types' => 1], $browser->counts($second));
        foreach (['districts', 'settlements', 'warehouses'] as $directory) {
            $row = $browser->query($directory, $second)->where('area_ref', 'territorial-area')->firstOrFail();
            $this->assertSame('Київська', $browser->context($directory, $row)['area_ref']);
        }
        $old = $browser->query('settlements', $first)->where('ref', 'settlement')->firstOrFail();
        $this->assertSame('OLD AREA', $browser->context('settlements', $old)['area_ref']);
        $warehouse = $browser->query('warehouses', $second)->firstOrFail();
        $this->assertSame(['area_ref' => 'Київська', 'district_ref' => 'Район', 'settlement_ref' => 'Абазівка', 'type_ref' => 'Поштомат'], $browser->context('warehouses', $warehouse));
        foreach (['areas', 'warehouse-types'] as $directory) {
            $this->assertSame([], $browser->parents($directory));
            $this->assertSame([], $browser->context($directory, $browser->query($directory, $second)->firstOrFail()));
        }
        $this->assertSame(array_fill_keys(DirectoryBrowser::DIRECTORIES, 0), $browser->counts(null));
    }

    public function test_parent_options_are_bounded_and_distinguish_duplicate_names(): void
    {
        $generation = $this->sync()->generation_id;
        $table = Tables::name('settlements');
        $source = (array) DB::table($table)->where('generation_id', $generation)->where('ref', 'settlement')->first();
        unset($source['id']);
        for ($index = 0; $index < 60; $index++) {
            DB::table($table)->insert([...$source, 'ref' => 'duplicate-'.$index]);
        }
        $browser = app(DirectoryBrowser::class);
        $options = $browser->options('settlements', $generation, ['area_ref' => 'territorial-area'], 'АБАЗ');
        $this->assertCount(50, $options);
        foreach ($options as $ref => $label) {
            $this->assertStringContainsString('Київська', $label);
            $this->assertStringContainsString('Район', $label);
            $this->assertStringContainsString('Ref: '.$ref, $label);
        }
        $this->assertSame([], $browser->options('settlements', $generation, ['area_ref' => 'other-area'], 'АБАЗ'));
    }

    public function test_livewire_parent_filters_counts_search_and_refs_work_together(): void
    {
        $this->allow();
        $this->sync();
        $page = Livewire::test(Directories::class)->assertOk()
            ->assertSee('Київська')->assertSee('Ref: settlement')
            ->assertSee(Lang::get('directory_totals', ['total' => 2, 'found' => 2]));
        $page->filterTable('parents', ['area_ref' => 'territorial-area', 'district_ref' => 'district'])
            ->assertCountTableRecords(1)->assertSee('Абазівка')
            ->assertSee(Lang::get('directory_totals', ['total' => 2, 'found' => 1]));
        $page->searchTable('ІНШЕ')->assertCountTableRecords(0)
            ->assertSee(Lang::get('directory_totals', ['total' => 2, 'found' => 0]));
        $warehouse = Livewire::withQueryParams(['directory' => 'warehouses'])->test(Directories::class);
        $warehouse->filterTable('parents', ['settlement_ref' => 'settlement', 'type_ref' => 'type', 'type' => 'parcel_locker'])
            ->assertCountTableRecords(1)->assertSee('Абазівка')->assertSee('Ref: warehouse');
        $warehouse->filterTable('parents', ['area_ref' => 'other-area'])->assertCountTableRecords(0);
        Livewire::withQueryParams(['directory' => 'districts'])->test(Directories::class)
            ->filterTable('parents', ['area_ref' => 'other-area'])->assertCountTableRecords(1);
        foreach (['areas', 'warehouse-types'] as $directory) {
            $component = Livewire::withQueryParams(['directory' => $directory])->test(Directories::class)->assertOk();
            $this->assertSame([], $component->instance()->getTable()->getFilters());
        }
    }

    public function test_mobile_menu_uses_each_child_url_and_count(): void
    {
        $this->allow();
        $this->sync();
        $page = Livewire::test(Directories::class);
        $html = view('filament-panels::components.page.sub-navigation.mobile-menu', [
            'navigation' => $page->instance()->getCachedSubNavigation(),
            'attributes' => new ComponentAttributeBag,
        ])->render();
        foreach (DirectoryBrowser::DIRECTORIES as $directory) {
            $this->assertStringContainsString('href="'.e(Directories::getUrl(['directory' => $directory])).'"', $html);
        }
        $this->assertStringContainsString('Населені пункти', $html);
    }

    public function test_all_directories_have_authorized_quick_view_without_sorting(): void
    {
        $this->allow();
        $generation = $this->sync()->generation_id;
        $browser = app(DirectoryBrowser::class);
        foreach (DirectoryBrowser::DIRECTORIES as $directory) {
            $record = $browser->query($directory, $generation)->firstOrFail();
            $page = Livewire::withQueryParams(['directory' => $directory])->test(Directories::class)->assertOk();
            $this->assertFalse($page->instance()->getTable()->getColumn('name')->isSortable());
            $this->assertSame($directory !== 'warehouse-types', $page->instance()->getTable()->getColumn('name')->isSearchable());
            $page->mountTableAction('quickView', $record)->assertActionMounted(TestAction::make('quickView')->table($record))
                ->assertSee($record->ref)->assertSee($record->name);
            $component = $page->instance();
            $states = collect($component->getSchema($component->getMountedActionSchemaName())->getFlatComponents(withHidden: true))
                ->filter(fn ($entry) => $entry instanceof Entry)
                ->mapWithKeys(fn ($entry) => [$entry->getName() => $entry->getState()])->all();
            $this->assertSame($record->ref, $states['record_ref']);
            if ($directory === 'warehouses') {
                $this->assertSame('50.45, 30.52', $states['view_coordinates']);
                $this->assertSame('Абазівка', $states['view_settlement_ref']);
                $this->assertSame([Lang::get('directory_monday') => '00:00-23:59'], $states['opening_hours']);
            }
            $page->unmountTableAction();
        }
    }
}
