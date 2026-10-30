<x-filament-panels::page>
    @include('nova-poshta::pages.report-styles')
    @php
        $run = $this->run();
        $active = in_array($run->status, ['queued', 'running'], true);
        $report = app(\NovaPoshta\Filament\Services\SyncReport::class);
        $lang = \NovaPoshta\Filament\Support\Lang::class;
        $icons = \Filament\Support\Icons\Heroicon::class;
        $statusColor = match ($run->status) { 'completed' => 'success', 'failed' => 'danger', 'running' => 'primary', default => 'gray' };
        $statusIcon = match ($run->status) {
            'completed' => $icons::OutlinedCheckCircle,
            'failed' => $icons::OutlinedXCircle,
            'running' => $icons::OutlinedArrowPath,
            default => $icons::OutlinedClock,
        };
        $stageLabel = $lang::get($active ? $run->stage : $run->status);
        $stageCounter = $run->status === 'completed' ? '100%' : $lang::get('stage_counter', ['current' => min(7, $report->progress($run) + 1), 'total' => 7]);
        $progressMeta = $lang::get('counts').': '.array_sum($run->counts ?? []).' · '.($active
            ? $lang::get('detail_page').': '.($run->checkpoint['page'] ?? 1)
            : $lang::get('request_count').': '.$run->request_count);
    @endphp
    <div class="np-report" @if ($active) wire:poll.2s="refreshReport" @endif>
        <x-filament::section compact :heading="$lang::get('progress')" :icon="$icons::OutlinedArrowPath">
            <div style="display:grid;grid-template-columns:minmax(0,1fr);gap:1rem">
                <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:0.75rem">
                    <x-filament::badge :color="$statusColor" :icon="$statusIcon">
                        {{ $lang::get($run->status) }}@if ($run->stage !== $run->status) · {{ $lang::get($run->stage) }}@endif
                    </x-filament::badge>
                </div>
                {{-- The native progress supplies semantics; this scoped overlay adds readable operational information. --}}
                <div class="np-report-progress" style="--np-progress-width: {{ $report->progress($run) / 7 * 100 }}%; --np-progress-color: var(--{{ $statusColor === 'gray' ? 'primary' : $statusColor }}-500)">
                    <progress max="7" value="{{ $report->progress($run) }}" aria-label="{{ $lang::get('progress') }}" aria-valuetext="{{ $stageCounter }} · {{ $stageLabel }} · {{ $progressMeta }}"></progress>
                    <div class="np-report-progress-fill" aria-hidden="true"></div>
                    <div class="np-report-progress-content" aria-hidden="true">
                        <div>
                            <strong>{{ $stageLabel }}</strong>
                            <span class="np-report-progress-meta">{{ $progressMeta }}</span>
                        </div>
                        <strong class="np-report-progress-counter">{{ $stageCounter }}</strong>
                    </div>
                </div>
                @if ($active)
                    <p class="np-secondary">{{ $lang::get('progress_unknown') }}</p>
                @endif
                @if ($run->status === 'queued')
                    <x-filament::callout :icon="$icons::OutlinedClock" :heading="$lang::get('waiting_worker')" role="status" />
                @elseif ($run->next_attempt_at)
                    <x-filament::callout color="warning" :icon="$icons::OutlinedArrowPath" :heading="$lang::get('retry_at', ['time' => $run->next_attempt_at->toDateTimeString()])" role="status" />
                @endif
                @if ($run->status === 'failed')
                    <x-filament::callout color="danger" :icon="$icons::OutlinedXCircle" :heading="$lang::get($run->error ?? 'synchronization_failed')" :description="$lang::get('restart_needed')" role="alert" />
                @endif
                @if (in_array($run->key_status, ['key_rejected', 'key_unavailable'], true))
                    <x-filament::callout color="warning" :icon="$icons::OutlinedKey" :heading="$lang::get($run->key_status)" role="alert" />
                @endif
                @if ($run->status === 'completed')
                    <x-filament::callout color="success" :icon="$icons::OutlinedCheckCircle" :heading="$lang::get('sync_published')" />
                @elseif ($active)
                    <p class="np-secondary">{{ $lang::get('publication_help') }}</p>
                @endif
            </div>
        </x-filament::section>

        {{ $this->content }}

        <x-filament::section compact :heading="$lang::get('event_log')" :description="$lang::get('log_help')" :icon="$icons::OutlinedQueueList">
            <div style="display:grid;grid-template-columns:minmax(0,1fr);gap:1rem" x-data="{ atBottom: true }">
                <div style="display:flex;flex-wrap:wrap;gap:0.75rem">
                    <x-filament::button color="gray" :icon="$icons::OutlinedArrowLeft" wire:click="older" wire:loading.attr="disabled">{{ $lang::get('older_events') }}</x-filament::button>
                    <x-filament::button color="gray" :icon="$icons::OutlinedArrowRight" wire:click="newer" wire:loading.attr="disabled">{{ $lang::get('newer_events') }}</x-filament::button>
                    <x-filament::button color="gray" :icon="$icons::OutlinedArrowDown" wire:click="latest" wire:loading.attr="disabled">{{ $lang::get('latest_events') }}</x-filament::button>
                    <label class="flex items-center gap-2"><x-filament::input.checkbox wire:model.live="follow" />{{ $lang::get('follow_log') }}</label>
                </div>
                <div style="max-height:32rem;overflow-y:auto" tabindex="0" aria-label="{{ $lang::get('event_log') }}"
                    x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight }); $watch('$wire.logEnd', () => { if ($wire.follow) $nextTick(() => $el.scrollTop = $el.scrollHeight) })"
                    x-on:scroll="atBottom = $el.scrollHeight - $el.scrollTop - $el.clientHeight < 24; if (!atBottom && $wire.follow) $wire.follow = false">
                    <ol class="np-report-log">
                        @forelse ($this->events() as $event)
                            <li class="np-report-event" data-level="{{ $event->level }}" wire:key="sync-event-{{ $event->id }}">
                                        <div class="np-report-event-meta">
                                            <time datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->format('H:i:s') }}</time>
                                            @if ($event->level !== 'info')
                                                <x-filament::badge :color="$event->level === 'error' ? 'danger' : 'warning'">{{ $lang::get($event->level) }}</x-filament::badge>
                                            @endif
                                            <span>{{ $lang::get($event->stage) }}</span>
                                        </div>
                                        <p class="np-report-event-message">{{ $report->message($event) }}</p>
                                        @if ($event->context)
                                            <details class="np-report-event-details"><summary aria-label="{{ $lang::get('technical_details') }}">{{ $lang::get('report_event_details') }}</summary>
                                                <dl>
                                                    @foreach ($event->context as $key => $value)
                                                        <div wire:key="sync-event-{{ $event->id }}-{{ $key }}"><dt>{{ $lang::get('detail_'.$key) }}</dt><dd>{{ $value }}</dd></div>
                                                    @endforeach
                                                </dl>
                                            </details>
                                        @endif
                            </li>
                        @empty
                            <li>{{ $lang::get('empty_log') }}</li>
                        @endforelse
                    </ol>
                </div>
                <p wire:loading role="status">{{ $lang::get('refreshing') }}</p>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
