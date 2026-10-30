<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Pages;

use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
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

    protected static ?int $navigationSort = 3;

    #[Unexposed]
    public static function getNavigationItemActiveRoutePattern(): string|array
    {
        return [self::getRouteName(), SyncReportPage::getRouteName()];
    }

    #[Unexposed]
    public function getSubheading(): ?string
    {
        return Lang::get('updates_intro');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('updateNow')
            ->label(fn (): string => Lang::get(app(SyncCoordinator::class)->active() ? 'updates_open_active' : 'update_now'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->authorize('nova-poshta.sync')->action(fn () => $this->updateNow())];
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(Lang::get('updates_overview'))
                ->compact()
                ->description(Lang::get('publication_help'))
                ->icon(Heroicon::OutlinedCircleStack)
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('last_success_at')->label(Lang::get('last_success_at'))
                        ->state(fn () => Settings::current()->last_success_at)
                        ->dateTime('d.m.Y, H:i')->placeholder(Lang::get('never')),
                    TextEntry::make('current_update')->label(Lang::get('updates_current'))
                        ->state(fn (): string => app(SyncCoordinator::class)->active()?->status ?? 'updates_idle')
                        ->formatStateUsing(fn (string $state): string => Lang::get($state))
                        ->badge()->color(fn (string $state): string => $state === 'running' ? 'primary' : 'gray'),
                    TextEntry::make('auto_update')->label(Lang::get('auto_update'))
                        ->state(fn (): string => (Settings::current()->schedule['enabled'] ?? false) ? 'updates_enabled' : 'updates_disabled')
                        ->formatStateUsing(fn (string $state): string => Lang::get($state))
                        ->badge()->color(fn (string $state): string => $state === 'updates_enabled' ? 'success' : 'gray'),
                ]),
            Section::make(Lang::get('diagnostics'))->icon(Heroicon::OutlinedServer)
                ->columns(['default' => 1, 'md' => 3])->compact()->secondary()->collapsed()
                ->schema(collect(['scheduler_seen_at', 'worker_seen_at', 'api_seen_at'])
                    ->map(fn (string $key): TextEntry => TextEntry::make($key)->label(Lang::get($key))
                        ->state(fn () => Settings::current()->{$key})
                        ->dateTime('d.m.Y, H:i')->placeholder(Lang::get('never')))->all()),
            EmbeddedTable::make(),
        ]);
    }

    #[Unexposed]
    public function table(Table $table): Table
    {
        return $table->query(fn () => SyncRun::query()->where(fn ($query) => $query->where('created_at', '>=', now()->subDays(7))->orWhereIn('status', ['queued', 'running'])))->defaultSort('id', 'desc')
            ->heading(Lang::get('updates_history'))->description(Lang::get('history_help'))
            ->columnManager(false)
            ->recordUrl(fn (SyncRun $record): string => SyncReportPage::getUrl(['runId' => $record->id]))
            ->poll(fn () => app(SyncCoordinator::class)->active() ? '2s' : null)
            ->columns([
                TextColumn::make('id')->label('#')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(Lang::get('started'))->dateTime('d.m.Y, H:i')
                    ->description(fn (SyncRun $record): string => Lang::get($record->source))->wrap(),
                TextColumn::make('status')->label(Lang::get('status'))->badge()->color(fn ($state) => match ($state) {
                    'completed' => 'success', 'failed' => 'danger', 'running' => 'primary', default => 'gray'
                })->formatStateUsing(fn ($state) => Lang::get($state))
                    ->icon(fn (string $state): Heroicon => match ($state) {
                        'completed' => Heroicon::OutlinedCheckCircle,
                        'failed' => Heroicon::OutlinedXCircle,
                        'running' => Heroicon::OutlinedArrowPath,
                        default => Heroicon::OutlinedClock,
                    })
                    ->description(fn (SyncRun $record): ?string => implode(' · ', array_filter([
                        $record->error || $record->stage !== $record->status ? Lang::get($record->error ?: $record->stage) : null,
                        in_array($record->key_status, ['key_rejected', 'key_unavailable'], true) ? Lang::get($record->key_status.'_short') : null,
                    ])) ?: null)->wrap(),
                TextColumn::make('stage')->label(Lang::get('stage'))->formatStateUsing(fn ($state) => Lang::get($state))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('counts')->label(Lang::get('counts'))->getStateUsing(fn (SyncRun $record): int => array_sum($record->counts ?? []))->numeric()->visibleFrom('md'),
                TextColumn::make('duration')->label(Lang::get('duration'))->getStateUsing(fn (SyncRun $record): string => Lang::get('seconds_value', ['seconds' => (int) ($record->started_at?->diffInSeconds($record->finished_at ?? now()) ?? 0)]))->visibleFrom('md'),
                TextColumn::make('error_count')->label(Lang::get('error_count'))->numeric()->color(fn ($state) => $state > 0 ? 'danger' : 'gray')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('retry_count')->label(Lang::get('retry_count'))->numeric()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('warning_count')->label(Lang::get('warning_count'))->numeric()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source')->label(Lang::get('source_label'))->formatStateUsing(fn ($state) => Lang::get($state))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('key_status')->label(Lang::get('key_health'))->getStateUsing(fn (SyncRun $record) => $record->key_status ?? 'unchecked')->badge()->formatStateUsing(fn ($state) => Lang::get($state.'_short'))->tooltip(fn (SyncRun $record) => Lang::get($record->key_status ?? 'unchecked'))->color(fn ($state) => match ($state) {
                    'key_rejected' => 'danger', 'key_unavailable' => 'warning', 'key_valid' => 'success', default => 'gray'
                })->wrap()->visibleFrom('md'),
                TextColumn::make('error')->label(Lang::get('error'))->formatStateUsing(fn ($state) => $state ? Lang::get($state) : '')->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])->recordActions([Action::make('report')->label(Lang::get('sync_report'))
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)->iconButton()->tooltip(Lang::get('sync_report'))
            ->url(fn (SyncRun $record) => SyncReportPage::getUrl(['runId' => $record->id]))])
            ->paginated([20, 50, 100])
            ->emptyStateIcon(Heroicon::OutlinedArrowPath)
            ->emptyStateHeading(Lang::get('updates_empty'))
            ->emptyStateDescription(Lang::get('updates_empty_help'));
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
