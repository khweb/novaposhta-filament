<x-filament-panels::page>
    @php
        $run = $this->run();
        $active = in_array($run->status, ['queued', 'running'], true);
        $report = app(\NovaPoshta\Filament\Services\SyncReport::class);
        $lang = \NovaPoshta\Filament\Support\Lang::class;
    @endphp
    <div @if ($active) wire:poll.2s="refreshReport" @endif style="display:grid;grid-template-columns:minmax(0,1fr);gap:1.5rem;min-width:0">
        <x-filament::section :heading="$lang::get('progress')">
            <div style="display:grid;grid-template-columns:minmax(0,1fr);gap:0.75rem">
                <x-filament::badge :color="match ($run->status) { 'completed' => 'success', 'failed' => 'danger', 'running' => 'primary', default => 'gray' }">
                    {{ $lang::get($run->status) }}@if ($run->stage !== $run->status) · {{ $lang::get($run->stage) }}@endif
                </x-filament::badge>
                {{-- Schemas has no operational progress primitive; native progress retains semantics without a theme build. --}}
                <progress style="width:100%;height:0.5rem;accent-color:var(--primary-500)" max="7" value="{{ $report->progress($run) }}" aria-label="{{ $lang::get('progress') }}"></progress>
                <p>{{ $run->status === 'completed' ? '100%' : $lang::get('progress_unknown') }}</p>
                <p>{{ $lang::get('stage_counter', ['current' => min(7, $report->progress($run) + 1), 'total' => 7]) }} · {{ $lang::get('detail_page') }}: {{ $run->checkpoint['page'] ?? 1 }} · {{ $lang::get('counts') }}: {{ array_sum($run->counts ?? []) }}</p>
                @if ($run->status === 'queued')
                    <p role="status">{{ $lang::get('waiting_worker') }}</p>
                @elseif ($run->next_attempt_at)
                    <p role="status">{{ $lang::get('retry_at', ['time' => $run->next_attempt_at->toDateTimeString()]) }}</p>
                @endif
                @if ($run->status === 'failed')
                    <div role="alert"><p>{{ $lang::get($run->error ?? 'synchronization_failed') }}</p><p>{{ $lang::get('restart_needed') }}</p></div>
                @endif
                @if (in_array($run->key_status, ['key_rejected', 'key_unavailable'], true))
                    <p role="alert">{{ $lang::get($run->key_status) }}</p>
                @endif
                <p>{{ $lang::get($run->status === 'completed' ? 'sync_published' : 'publication_help') }}</p>
            </div>
        </x-filament::section>

        {{ $this->content }}

        <x-filament::section :heading="$lang::get('event_log')" :description="$lang::get('log_help')">
            <div style="display:grid;grid-template-columns:minmax(0,1fr);gap:1rem" x-data="{ atBottom: true }">
                <div style="display:flex;flex-wrap:wrap;gap:0.75rem">
                    <x-filament::button color="gray" wire:click="older" wire:loading.attr="disabled">{{ $lang::get('older_events') }}</x-filament::button>
                    <x-filament::button color="gray" wire:click="newer" wire:loading.attr="disabled">{{ $lang::get('newer_events') }}</x-filament::button>
                    <x-filament::button color="gray" wire:click="latest" wire:loading.attr="disabled">{{ $lang::get('latest_events') }}</x-filament::button>
                    <label class="flex items-center gap-2"><x-filament::input.checkbox wire:model.live="follow" />{{ $lang::get('follow_log') }}</label>
                </div>
                <div style="max-height:32rem;overflow-y:auto" tabindex="0" aria-label="{{ $lang::get('event_log') }}"
                    x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight }); $watch('$wire.logEnd', () => { if ($wire.follow) $nextTick(() => $el.scrollTop = $el.scrollHeight) })"
                    x-on:scroll="atBottom = $el.scrollHeight - $el.scrollTop - $el.clientHeight < 24; if (!atBottom && $wire.follow) $wire.follow = false">
                    <ol style="display:grid;grid-template-columns:minmax(0,1fr);gap:1rem">
                        @forelse ($this->events() as $event)
                            <li wire:key="sync-event-{{ $event->id }}" style="display:grid;gap:0.25rem">
                                <div class="flex flex-wrap items-center gap-2">
                                    <time datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->format('H:i:s') }}</time>
                                    <x-filament::badge :color="match ($event->level) { 'error' => 'danger', 'warning' => 'warning', default => 'gray' }">{{ $lang::get($event->level) }}</x-filament::badge>
                                    <span>{{ $lang::get($event->stage) }}</span>
                                </div>
                                <p>{{ $report->message($event) }}</p>
                                @if ($event->context)
                                    <details><summary>{{ $lang::get('technical_details') }}</summary>
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
