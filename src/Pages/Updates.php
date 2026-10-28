<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Pages;

use Filament\Actions\Action;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Models\SyncRun;
use NovaPoshta\Filament\Services\SyncCoordinator;
use NovaPoshta\Filament\Support\Lang;

final class Updates extends PackagePage implements HasTable
{
    use InteractsWithTable;

    protected static string $label = 'updates';

    protected static ?string $slug = 'nova-poshta/updates';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Actions::make([Action::make('updateNow')->label(Lang::get('update_now'))->authorize('nova-poshta.sync')->action(fn () => $this->updateNow())]),
            KeyValueEntry::make('diagnostics')->label(Lang::get('diagnostics'))->state(function (): array {
                $s = Settings::current();

                return collect(['scheduler_seen_at', 'worker_seen_at', 'api_seen_at'])->mapWithKeys(fn ($key) => [Lang::get($key) => $s->{$key}?->toIso8601String() ?? Lang::get('never')])->all();
            }),
            EmbeddedTable::make(),
        ]);
    }

    #[Unexposed]
    public function table(Table $table): Table
    {
        return $table->query(fn () => SyncRun::query())->defaultSort('id', 'desc')
            ->poll(fn () => app(SyncCoordinator::class)->active() ? '5s' : null)
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('status')->label(Lang::get('status'))->badge()->formatStateUsing(fn ($state) => Lang::get($state)),
                TextColumn::make('stage')->label(Lang::get('stage'))->formatStateUsing(fn ($state) => Lang::get($state)),
                TextColumn::make('counts')->label(Lang::get('counts'))->getStateUsing(fn (SyncRun $record): string => implode(', ', array_map(fn ($key, $value) => Lang::get($key).': '.$value, array_keys($record->counts ?? []), $record->counts ?? [])))->wrap(),
                TextColumn::make('started_at')->label(Lang::get('started'))->dateTime()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('heartbeat_at')->label(Lang::get('heartbeat'))->dateTime(),
                TextColumn::make('finished_at')->label(Lang::get('finished'))->dateTime(),
                TextColumn::make('error')->label(Lang::get('error'))->formatStateUsing(fn ($state) => $state ? Lang::get($state) : '')->wrap(),
            ])->paginated([20, 50, 100])->emptyStateHeading(Lang::get('empty'));
    }

    #[Exposed]
    public function updateNow(): void
    {
        Gate::authorize('nova-poshta.sync');
        $run = app(SyncCoordinator::class)->request();
        Notification::make()->title(Lang::get('job_status', ['id' => $run?->id, 'status' => Lang::get($run?->status ?? 'failed')]))->send();
    }
}
