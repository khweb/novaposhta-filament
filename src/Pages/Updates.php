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

                return collect(['last_success_at', 'scheduler_seen_at', 'worker_seen_at', 'api_seen_at'])->mapWithKeys(fn ($key) => [Lang::get($key) => $s->{$key}?->toIso8601String() ?? Lang::get('never')])->all();
            }),
            EmbeddedTable::make(),
        ]);
    }

    #[Unexposed]
    public function table(Table $table): Table
    {
        return $table->query(fn () => SyncRun::query()->where(fn ($query) => $query->where('created_at', '>=', now()->subDays(7))->orWhereIn('status', ['queued', 'running'])))->defaultSort('id', 'desc')
            ->description(Lang::get('history_help'))
            ->recordUrl(fn (SyncRun $record): string => SyncReportPage::getUrl(['runId' => $record->id]))
            ->poll(fn () => app(SyncCoordinator::class)->active() ? '2s' : null)
            ->columns([
                TextColumn::make('id')->label('#')->visibleFrom('md'),
                TextColumn::make('created_at')->label(Lang::get('started'))->dateTime('d.m H:i'),
                TextColumn::make('status')->label(Lang::get('status'))->badge()->color(fn ($state) => match ($state) {
                    'completed' => 'success', 'failed' => 'danger', 'running' => 'primary', default => 'gray'
                })->formatStateUsing(fn ($state) => Lang::get($state)),
                TextColumn::make('stage')->label(Lang::get('stage'))->formatStateUsing(fn ($state) => Lang::get($state)),
                TextColumn::make('counts')->label(Lang::get('counts'))->getStateUsing(fn (SyncRun $record): int => array_sum($record->counts ?? []))->numeric()->visibleFrom('md'),
                TextColumn::make('duration')->label(Lang::get('duration'))->getStateUsing(fn (SyncRun $record): string => Lang::get('seconds_value', ['seconds' => (int) ($record->started_at?->diffInSeconds($record->finished_at ?? now()) ?? 0)]))->visibleFrom('md'),
                TextColumn::make('error_count')->label(Lang::get('error_count'))->numeric()->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('retry_count')->label(Lang::get('retry_count'))->numeric()->visibleFrom('md'),
                TextColumn::make('warning_count')->label(Lang::get('warning_count'))->numeric()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source')->label(Lang::get('source_label'))->formatStateUsing(fn ($state) => Lang::get($state))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('key_status')->label(Lang::get('key_health'))->getStateUsing(fn (SyncRun $record) => $record->key_status ?? 'unchecked')->badge()->formatStateUsing(fn ($state) => Lang::get($state.'_short'))->tooltip(fn (SyncRun $record) => Lang::get($record->key_status ?? 'unchecked'))->color(fn ($state) => match ($state) {
                    'key_rejected' => 'danger', 'key_unavailable' => 'warning', 'key_valid' => 'success', default => 'gray'
                })->wrap(),
                TextColumn::make('error')->label(Lang::get('error'))->formatStateUsing(fn ($state) => $state ? Lang::get($state) : '')->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])->recordActions([Action::make('report')->label(Lang::get('sync_report'))->url(fn (SyncRun $record) => SyncReportPage::getUrl(['runId' => $record->id]))])
            ->paginated([20, 50, 100])->emptyStateHeading(Lang::get('empty'));
    }

    #[Exposed]
    public function updateNow(): void
    {
        Gate::authorize('nova-poshta.sync');
        $run = app(SyncCoordinator::class)->request();
        Notification::make()->title(Lang::get('job_status', ['id' => $run?->id, 'status' => Lang::get($run?->status ?? 'failed')]))->send();
        if ($run !== null) {
            $this->redirect(SyncReportPage::getUrl(['runId' => $run->id]));
        }
    }
}
