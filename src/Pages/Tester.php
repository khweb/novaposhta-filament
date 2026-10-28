<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use NovaPoshta\Filament\Exceptions\DirectoryException;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Services\ApiClient;
use NovaPoshta\Filament\Services\DirectorySearch;
use NovaPoshta\Filament\Services\SyncCoordinator;
use NovaPoshta\Filament\Support\Lang;
use NovaPoshta\Filament\Support\Tables;

final class Tester extends PackagePage
{
    protected static string $ability = 'diagnose';

    protected static string $label = 'tester';

    protected static ?string $slug = 'nova-poshta/tester';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    public ?array $data = [];

    #[Locked]
    public ?string $searchError = null;

    #[Locked]
    public int $responseMs = 0;

    public function mount(): void
    {
        $this->content->fill([]);
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make(Lang::get('tester'))->description(Lang::get('tester_help'))
                ->columns(['default' => 1, 'md' => 2])->schema([
                    $this->directorySelect('area_ref', 'areas', ['district_ref', 'settlement_ref', 'warehouse_ref', 'street_ref', 'street_query']),
                    $this->directorySelect('district_ref', 'districts', ['settlement_ref', 'warehouse_ref', 'street_ref', 'street_query'])
                        ->disabled(fn (Get $get) => ! $get('area_ref'))->helperText(Lang::get('district_help')),
                    $this->directorySelect('settlement_ref', 'settlements', ['warehouse_ref', 'street_ref', 'street_query'])
                        ->disabled(fn (Get $get) => ! $get('area_ref'))->helperText(fn (Get $get) => ! $get('area_ref') ? Lang::get('choose_area') : null),
                    Select::make('type')->label(Lang::get('type'))->options(['branch' => Lang::get('branch'), 'parcel_locker' => Lang::get('parcel_locker')])
                        ->live()->afterStateUpdated(fn (Set $set) => $set('warehouse_ref', null)),
                    $this->directorySelect('warehouse_ref', 'warehouses', [])
                        ->disabled(fn (Get $get) => ! $get('settlement_ref'))->helperText(fn (Get $get) => ! $get('settlement_ref') ? Lang::get('choose_settlement') : null),
                    TextInput::make('street_query')->label(Lang::get('street_query'))->minLength(3)->maxLength(100)
                        ->live(debounce: 400)->disabled(fn (Get $get) => ! $get('settlement_ref'))
                        ->afterStateUpdated(fn (Set $set) => $set('street_ref', null))->helperText(Lang::get('street_help')),
                    Select::make('street_ref')->label(Lang::get('streets'))->options(function (Get $get): array {
                        $q = trim((string) $get('street_query'));
                        if (! $get('settlement_ref') || mb_strlen($q) < 3) {
                            return [];
                        }

                        return $this->lookup('streets', ['settlement_ref' => $get('settlement_ref'), 'q' => $q]);
                    })->disabled(fn (Get $get) => ! $get('settlement_ref') || mb_strlen(trim((string) $get('street_query'))) < 3)
                        ->searchable()->loadingMessage(Lang::get('loading'))->noSearchResultsMessage(Lang::get('empty')),
                ]),
            Text::make(fn () => Lang::get('source', ['ms' => $this->responseMs, 'date' => $this->updatedAt()])),
            Text::make(fn () => $this->searchError ? Lang::get($this->searchError) : Lang::get('search_ready'))
                ->extraAttributes(['role' => 'status', 'aria-live' => 'polite']),
            Actions::make([
                Action::make('checkConnection')->label(Lang::get('check_connection'))->authorize('nova-poshta.diagnose')->action(fn () => $this->checkConnection()),
                Action::make('checkKey')->label(Lang::get('check_key'))->authorize('nova-poshta.diagnose')->action(fn () => $this->checkKey()),
            ]),
        ]);
    }

    protected function directorySelect(string $field, string $directory, array $clear): Select
    {
        return Select::make($field)->label(Lang::get($field))->searchable()->searchDebounce(400)->live()
            ->options(fn (Get $get): array => $this->lookup($directory, $this->parentFilters($directory, $get)))
            ->getSearchResultsUsing(fn (string $search, Get $get): array => $this->lookup($directory, [...$this->parentFilters($directory, $get), 'q' => $search]))
            ->getOptionLabelUsing(function ($value, Get $get) use ($directory): ?string {
                Gate::authorize('nova-poshta.diagnose');
                try {
                    return app(DirectorySearch::class)->query($directory, $this->parentFilters($directory, $get))->where('ref', $value)->value('name');
                } catch (DirectoryException) {
                    return null;
                }
            })->afterStateUpdated(function (Set $set) use ($clear): void {
                foreach ($clear as $field) {
                    $set($field, null);
                }
            })->loadingMessage(Lang::get('loading'))->noSearchResultsMessage(Lang::get('empty'))
            ->searchPrompt(Lang::get('search_prompt'));
    }

    protected function parentFilters(string $directory, Get $get): array
    {
        $fields = match ($directory) {
            'districts' => ['area_ref'],
            'settlements' => ['area_ref', 'district_ref'],
            'warehouses' => ['area_ref', 'district_ref', 'settlement_ref', 'type'],
            default => [],
        };

        return array_filter(array_combine($fields, array_map(fn ($key) => $get($key), $fields)), fn ($value) => filled($value));
    }

    protected function lookup(string $directory, array $filters): array
    {
        Gate::authorize('nova-poshta.diagnose');
        $start = hrtime(true);
        try {
            $this->searchError = null;
            $result = app(DirectorySearch::class)->search($directory, [...$filters, 'per_page' => 100]);

            return collect($result->items())->pluck('name', 'ref')->all();
        } catch (DirectoryException $exception) {
            $this->searchError = $exception->reason;

            return [];
        } finally {
            $this->responseMs = (int) round((hrtime(true) - $start) / 1_000_000);
        }
    }

    protected function updatedAt(): string
    {
        $id = Settings::current()->active_generation_id;
        $date = $id ? DB::table(Tables::name('generations'))->where('id', $id)->value('updated_at') : null;

        return $date ? (string) $date : Lang::get('never');
    }

    #[Exposed]
    public function checkConnection(): void
    {
        $this->diagnose(false);
    }

    #[Exposed]
    public function checkKey(): void
    {
        $this->diagnose(true);
    }

    protected function diagnose(bool $key): void
    {
        Gate::authorize('nova-poshta.diagnose');
        $lock = Cache::lock(SyncCoordinator::lockName('diagnose'), 120);
        if (! $lock->get()) {
            Notification::make()->title(Lang::get('busy'))->warning()->send();

            return;
        }
        try {
            if (! RateLimiter::attempt(SyncCoordinator::lockName('diagnose-rate'), 1, fn () => true, 5)) {
                Notification::make()->title(Lang::get('busy'))->warning()->send();

                return;
            }
            if ($key) {
                app(ApiClient::class)->validateKey(Settings::current()->api_key ?? '');
            } else {
                if (! app(ApiClient::class)->connection()) {
                    throw new DirectoryException('upstream_invalid_response');
                }
            }
            Notification::make()->title(Lang::get('check_success'))->success()->send();
        } catch (DirectoryException $exception) {
            Notification::make()->title(Lang::get($exception->reason))->danger()->send();
        } finally {
            $lock->release();
        }
    }
}
