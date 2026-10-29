<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Pages;

use Filament\Actions\Action;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use NovaPoshta\Filament\Models\SyncEvent;
use NovaPoshta\Filament\Models\SyncRun;
use NovaPoshta\Filament\Services\SyncCoordinator;
use NovaPoshta\Filament\Services\SyncReport;
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
            Section::make(Lang::get('sync_report'))->columns(['default' => 1, 'sm' => 2, 'xl' => 4])
                ->schema(fn () => collect(app(SyncReport::class)->summary($this->run()))->map(fn ($value, $label) => TextEntry::make('summary_'.sha1($label))->label($label)->state($value))->values()->all()),
            Section::make(Lang::get('counts'))->schema([
                KeyValueEntry::make('counts')->label(Lang::get('counts'))->hiddenLabel()->state(fn () => collect($this->run()->counts ?? [])->mapWithKeys(fn ($value, $key) => [Lang::get($key) => $value])->all()),
            ])->collapsible(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('history')->label(Lang::get('updates'))->url(Updates::getUrl()),
            Action::make('restart')->label(Lang::get('restart'))->authorize('nova-poshta.sync')
                ->visible(fn () => $this->run()->status === 'failed')->action(fn () => $this->restart()),
            Action::make('settings')->label(Lang::get('settings'))->authorize('nova-poshta.manage')
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
