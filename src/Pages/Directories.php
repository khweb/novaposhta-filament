<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Pages;

use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use NovaPoshta\Filament\Models\DirectoryRecord;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Services\DirectoryBrowser;
use NovaPoshta\Filament\Services\DirectorySearch;
use NovaPoshta\Filament\Support\Lang;

final class Directories extends PackagePage implements HasTable
{
    use InteractsWithTable;

    protected static string $label = 'directories';

    protected static ?string $slug = 'nova-poshta/directories';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 2;

    #[Locked]
    #[Url]
    public string $directory = 'settlements';

    #[Locked]
    public ?int $generation = null;

    protected ?array $cachedDirectoryCounts = null;

    public function mount(): void
    {
        abort_unless(in_array($this->directory, array_diff(DirectorySearch::DIRECTORIES, ['streets']), true), 422);
        $this->generation = Settings::current()->active_generation_id;
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return Lang::get($this->directory);
    }

    #[Unexposed]
    public function getSubheading(): ?string
    {
        return Lang::get($this->directory === 'warehouse-types' ? 'directory_types_intro' : 'directories_intro');
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    #[Unexposed]
    public function table(Table $table): Table
    {
        return $table->query(function () {
            Gate::authorize('nova-poshta.view');

            return app(DirectoryBrowser::class)->query($this->directory, $this->generation);
        })->columns([
            Stack::make([
                TextColumn::make('name')->label(Lang::get('name'))
                    ->searchable($this->directory !== 'warehouse-types', query: fn (Builder $query, string $search) => app(DirectorySearch::class)->applyTextSearch($query, $search))
                    ->weight(FontWeight::Medium)->wrap()
                    ->description(fn (DirectoryRecord $record): ?string => implode(' · ', collect(app(DirectoryBrowser::class)->context($this->directory, $record))
                        ->map(fn (string $name, string $column): string => $this->parentLabel($column).': '.$name)->all()) ?: null),
                TextColumn::make('ref')->label('Ref')->formatStateUsing(fn (string $state): string => 'Ref: '.$state)
                    ->copyable()->copyableState(fn (DirectoryRecord $record): string => $record->ref)->color('gray')->wrap(),
                TextColumn::make('type')->label(Lang::get('type'))->formatStateUsing(fn ($state) => $state ? Lang::get($state) : '')
                    ->badge()->color('gray')->visible(fn (): bool => $this->directory === 'warehouses'),
            ])->space(1),
        ])->columnManager(false)
            ->heading(fn (): string => Lang::get('directory_totals', [
                'total' => number_format($this->directoryCounts()[$this->directory], thousands_separator: ' '),
                'found' => number_format($this->getFilteredTableQuery()->count(), thousands_separator: ' '),
            ]))
            ->filters($this->parentFilters(), layout: FiltersLayout::Dropdown)
            ->filtersFormWidth(Width::TwoExtraLarge)
            ->paginated([20, 50, 100])->defaultPaginationPageOption(20)
            ->emptyStateIcon(Heroicon::OutlinedBookOpen)
            ->emptyStateHeading(fn (): string => $this->generation === null ? Lang::get('directories_not_loaded') : Lang::get('empty'))
            ->emptyStateDescription(fn (): string => $this->generation === null ? Lang::get('directory_not_ready') : Lang::get('directories_empty_help'))
            ->recordAction('quickView')
            ->recordActions([ViewAction::make('quickView')->label(Lang::get('quick_view'))
                ->icon(Heroicon::OutlinedEye)->button()->outlined()->size(Size::ExtraLarge)->authorize('nova-poshta.view')
                ->modalHeading(fn (DirectoryRecord $record): string => $record->name)
                ->modalWidth(Width::TwoExtraLarge)->modalCancelActionLabel(Lang::get('close_view'))
                ->mutateRecordDataUsing(fn (array $data): array => [])
                ->schema(fn (DirectoryRecord $record): array => $this->quickViewSchema($record))])
            ->toolbarActions([]);
    }

    #[Unexposed]
    public function directoryCounts(): array
    {
        return $this->cachedDirectoryCounts ??= app(DirectoryBrowser::class)->counts($this->generation);
    }

    protected function parentFilters(): array
    {
        $parents = app(DirectoryBrowser::class)->parents($this->directory);
        if ($parents === []) {
            return [];
        }
        $fields = [];
        foreach ($parents as $column => $parent) {
            $fields[] = Select::make($column)->label($this->parentLabel($column))->searchable()->live()
                ->options(fn (Get $get): array => $this->parentOptions($parent, $get))
                ->getSearchResultsUsing(fn (string $search, Get $get): array => $this->parentOptions($parent, $get, $search))
                ->getOptionLabelUsing(fn ($value): ?string => app(DirectoryBrowser::class)->label($parent, $this->generation, $value))
                ->afterStateUpdated(function (Set $set) use ($column): void {
                    foreach (match ($column) {
                        'area_ref' => ['district_ref', 'settlement_ref'],
                        'district_ref' => ['settlement_ref'],
                        default => [],
                    } as $child) {
                        $set($child, null);
                    }
                });
        }
        if ($this->directory === 'warehouses') {
            $fields[] = Select::make('type')->label(Lang::get('type'))
                ->options(['branch' => Lang::get('branch'), 'parcel_locker' => Lang::get('parcel_locker')]);
        }

        return [Filter::make('parents')->schema([Grid::make(['default' => 1, 'md' => 2])->schema($fields)])
            ->query(fn (Builder $query, array $data): Builder => app(DirectoryBrowser::class)->filter($query, $this->directory, $data))
            ->indicateUsing(function (array $data) use ($parents): array {
                $indicators = [];
                foreach (array_filter(array_intersect_key($data, [...$parents, 'type' => 'type']), fn ($value): bool => is_string($value) && filled($value)) as $column => $value) {
                    $label = $column === 'type' ? Lang::get($value) : app(DirectoryBrowser::class)->label($parents[$column], $this->generation, $value);
                    $indicators[] = Indicator::make($this->parentLabel($column).': '.$label)->removeField($column);
                }

                return $indicators;
            })];
    }

    protected function parentOptions(string $parent, Get $get, ?string $search = null): array
    {
        return app(DirectoryBrowser::class)->options($parent, $this->generation,
            ['area_ref' => $get('area_ref'), 'district_ref' => $get('district_ref')], $search);
    }

    protected function parentLabel(string $column): string
    {
        return Lang::get($column === 'district_ref' ? 'directory_district' : $column);
    }

    protected function quickViewSchema(DirectoryRecord $record): array
    {
        Gate::authorize('nova-poshta.view');
        $entries = [TextEntry::make('record_ref')->label('Ref')->state($record->ref)->copyable()->columnSpanFull()];
        foreach (app(DirectoryBrowser::class)->context($this->directory, $record) as $column => $name) {
            $entries[] = TextEntry::make('view_'.$column)->label($this->parentLabel($column))->state($name);
        }
        if ($this->directory !== 'warehouses') {
            return [Grid::make(['default' => 1, 'md' => 2])->schema($entries)];
        }
        $details = app(DirectoryBrowser::class)->details($record);
        $entries[] = TextEntry::make('category')->label(Lang::get('type'))->state(Lang::get($record->type))->badge()->color('gray');
        foreach (['address', 'coordinates', 'total_weight', 'place_weight'] as $key) {
            if (filled($details[$key])) {
                $entries[] = TextEntry::make('view_'.$key)->label(Lang::get('directory_'.$key))->state($details[$key])
                    ->columnSpan($key === 'address' ? 'full' : 1);
            }
        }

        return [
            Grid::make(['default' => 1, 'md' => 2])->schema($entries),
            Section::make(Lang::get('directory_schedule'))->compact()->collapsible()->collapsed()
                ->visible($details['schedule'] !== [])->schema([
                    KeyValueEntry::make('opening_hours')->hiddenLabel()->state($details['schedule']),
                ]),
        ];
    }
}
