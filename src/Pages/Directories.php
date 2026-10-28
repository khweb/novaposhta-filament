<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Pages;

use Filament\Forms\Components\Select;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use NovaPoshta\Filament\Models\DirectoryRecord;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Services\DirectorySearch;
use NovaPoshta\Filament\Support\Lang;

final class Directories extends PackagePage implements HasTable
{
    use InteractsWithTable;

    protected static string $label = 'directories';

    protected static ?string $slug = 'nova-poshta/directories';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    public ?array $data = ['directory' => 'settlements'];

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Select::make('directory')->label(Lang::get('directory'))->options(collect(array_diff(DirectorySearch::DIRECTORIES, ['streets']))->mapWithKeys(fn ($name) => [$name => Lang::get($name)])->all())
                ->live()->afterStateUpdated(fn () => $this->resetTable()),
            Text::make(Lang::get('directory_not_ready'))->visible(fn () => Settings::current()->active_generation_id === null),
            EmbeddedTable::make(),
        ]);
    }

    #[Unexposed]
    public function table(Table $table): Table
    {
        return $table->query(function () {
            Gate::authorize('nova-poshta.view');
            $directory = $this->data['directory'] ?? 'settlements';
            abort_unless(in_array($directory, array_diff(DirectorySearch::DIRECTORIES, ['streets']), true), 422);
            if (Settings::current()->active_generation_id === null) {
                return (new DirectoryRecord)->newQuery()->whereRaw('1 = 0');
            }

            return app(DirectorySearch::class)->query($directory);
        })->columns([
            TextColumn::make('name')->label(Lang::get('name'))->searchable(query: fn (Builder $query, string $search) => app(DirectorySearch::class)->applyTextSearch($query, $search))->sortable()->wrap(),
            TextColumn::make('ref')->label('Ref')->copyable()->toggleable(),
            TextColumn::make('type')->label(Lang::get('type'))->formatStateUsing(fn ($state) => $state ? Lang::get($state) : '')->toggleable(),
            TextColumn::make('area_ref')->label(Lang::get('area_ref'))->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('settlement_ref')->label(Lang::get('settlement_ref'))->toggleable(isToggledHiddenByDefault: true),
        ])->paginated([20, 50, 100])->defaultPaginationPageOption(20)
            ->emptyStateHeading(Lang::get('empty'))->recordActions([])->toolbarActions([]);
    }
}
