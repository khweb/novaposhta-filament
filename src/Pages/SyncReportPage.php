<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Pages;

use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Models\SyncEvent;
use NovaPoshta\Filament\Models\SyncRun;
use NovaPoshta\Filament\Services\SyncCoordinator;
use NovaPoshta\Filament\Support\Lang;

final class SyncReportPage extends PackagePage
{
    protected static string $label = 'sync_report';

    protected static ?string $slug = 'nova-poshta/report/{runId}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'nova-poshta::pages.sync-report';

    #[Locked]
    public int $runId;

    #[Locked]
    public int $logEnd = 0;

    public bool $follow = true;

    #[Unexposed]
    public function getTitle(): string
    {
        return Lang::get('report_title', ['id' => $this->runId]);
    }

    #[Unexposed]
    public function getSubheading(): ?string
    {
        return Lang::get('report_intro');
    }

    public function mount(int $runId): void
    {
        $this->runId = $runId;
        $this->run();
        $this->logEnd = $this->latestEvent();
    }

    #[Unexposed]
    public function run(): SyncRun
    {
        return SyncRun::query()->where(function ($query): void {
            $query->where('created_at', '>=', now()->subDays(7))->orWhereIn('status', ['queued', 'running']);
        })->findOrFail($this->runId);
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(Lang::get('report_metrics'))->icon(Heroicon::OutlinedChartBar)
                ->compact()
                ->columns(['default' => 2, 'md' => 4])->schema(
                    collect(['request_count', 'error_count', 'retry_count', 'warning_count'])
                        ->map(fn (string $key): TextEntry => TextEntry::make($key)->label(Lang::get($key))
                            ->state(fn () => $this->run()->{$key})->numeric()->weight(FontWeight::SemiBold)->size(TextSize::Large)
                            ->color(fn ($state): string => $state > 0 ? match ($key) {
                                'error_count' => 'danger', 'retry_count', 'warning_count' => 'warning', default => 'primary',
                            } : 'gray'))->all()
                ),
            Section::make(Lang::get('report_details'))->icon(Heroicon::OutlinedClock)
                ->compact()
                ->columns(['default' => 1, 'md' => 2, 'xl' => 3])->schema([
                    TextEntry::make('source')->label(Lang::get('source_label'))->state(fn () => Lang::get($this->run()->source)),
                    TextEntry::make('started')->label(Lang::get('started'))->state(fn () => $this->run()->started_at)
                        ->dateTime('d.m.Y, H:i')->placeholder(Lang::get('waiting_worker')),
                    TextEntry::make('finished')->label(Lang::get('finished'))->state(fn () => $this->run()->finished_at)
                        ->dateTime('d.m.Y, H:i')->placeholder('—'),
                    TextEntry::make('duration')->label(Lang::get('duration'))
                        ->state(fn () => Lang::get('seconds_value', ['seconds' => (int) ($this->run()->started_at?->diffInSeconds($this->run()->finished_at ?? now()) ?? 0)])),
                    TextEntry::make('key_health')->label(Lang::get('key_health'))
                        ->state(fn () => $this->run()->key_status ?? 'unchecked')
                        ->formatStateUsing(fn (string $state): string => Lang::get($state.'_short'))
                        ->badge()->color(fn (string $state): string => match ($state) {
                            'key_valid' => 'success', 'key_rejected' => 'danger', 'key_unavailable' => 'warning', default => 'gray',
                        }),
                    TextEntry::make('last_success')->label(Lang::get('last_success'))
                        ->state(fn () => Settings::current()->last_success_at)->dateTime('d.m.Y, H:i')->placeholder(Lang::get('never')),
                ]),
            Section::make(Lang::get('counts'))->icon(Heroicon::OutlinedCircleStack)
                ->columns(['default' => 1, 'sm' => 2, 'xl' => 3])
                ->schema(fn () => collect($this->run()->counts ?? [])
                    ->map(fn ($value, $key): TextEntry => TextEntry::make('count_'.$key)->label(Lang::get($key))->state($value)->numeric())->values()->all())
                ->collapsed(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('history')->label(Lang::get('updates'))->icon(Heroicon::OutlinedArrowLeft)->color('gray')->url(Updates::getUrl()),
            Action::make('restart')->label(Lang::get('restart'))->icon(Heroicon::OutlinedArrowPath)->authorize('nova-poshta.sync')
                ->visible(fn () => $this->run()->status === 'failed')->action(fn () => $this->restart()),
            Action::make('settings')->label(Lang::get('settings'))->icon(Heroicon::OutlinedCog6Tooth)->color('gray')->authorize('nova-poshta.manage')
                ->visible(fn () => in_array($this->run()->key_status, ['key_rejected', 'key_unavailable'], true))->url(SettingsPage::getUrl()),
        ];
    }

    #[Exposed]
    public function restart(): void
    {
        Gate::authorize('nova-poshta.sync');
        abort_unless($this->run()->status === 'failed', 422);
        $run = app(SyncCoordinator::class)->request();
        $this->redirect(self::getUrl(['runId' => $run->id]));
    }

    #[Exposed]
    public function refreshReport(): void
    {
        Gate::authorize('nova-poshta.view');
        $this->run();
        if ($this->follow) {
            $this->logEnd = $this->latestEvent();
        }
    }

    #[Unexposed]
    public function events(): Collection
    {
        return SyncEvent::query()->where('sync_run_id', $this->runId)->where('id', '<=', $this->logEnd)->latest('id')->limit(50)->get()->reverse()->values();
    }

    #[Exposed]
    public function older(): void
    {
        Gate::authorize('nova-poshta.view');
        $first = $this->events()->first()?->id ?? 0;
        $end = SyncEvent::query()->where('sync_run_id', $this->runId)->where('id', '<', $first)->max('id');
        if ($end) {
            $this->follow = false;
            $this->logEnd = (int) $end;
        }
    }

    #[Exposed]
    public function newer(): void
    {
        Gate::authorize('nova-poshta.view');
        $end = SyncEvent::query()->where('sync_run_id', $this->runId)->where('id', '>', $this->logEnd)->orderBy('id')->limit(50)->pluck('id')->last();
        $this->logEnd = (int) ($end ?? $this->logEnd);
        $this->follow = $this->logEnd === $this->latestEvent();
    }

    #[Exposed]
    public function latest(): void
    {
        Gate::authorize('nova-poshta.view');
        $this->follow = true;
        $this->logEnd = $this->latestEvent();
    }

    private function latestEvent(): int
    {
        return (int) SyncEvent::query()->where('sync_run_id', $this->runId)->max('id');
    }
}
