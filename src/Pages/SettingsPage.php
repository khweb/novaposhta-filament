<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Services\SettingsService;
use NovaPoshta\Filament\Support\Lang;

final class SettingsPage extends PackagePage
{
    protected static string $ability = 'manage';

    protected static string $label = 'settings';

    protected static ?string $slug = 'nova-poshta/settings';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    public ?array $data = [];

    public function mount(): void
    {
        $this->content->fill(['schedule' => Settings::current()->schedule, 'replacement_key' => null]);
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make(Lang::get('connection'))->schema([
                TextEntry::make('key_health')->label(Lang::get('key_health'))->state(fn () => Lang::get(Settings::current()->key_status)),
                TextEntry::make('key_checked_at')->label(Lang::get('key_checked_at'))->state(fn () => Settings::current()->key_checked_at?->toDateTimeString() ?? Lang::get('never')),
                TextInput::make('replacement_key')->label(Lang::get('api_key'))->password()->maxLength(512)
                    ->autocomplete('new-password')->helperText(Lang::get('key_help')),
            ]),
            Section::make(Lang::get('schedule'))->columns(['default' => 1, 'md' => 2])->schema([
                Toggle::make('schedule.enabled')->label(Lang::get('auto_update'))->default(false),
                Select::make('schedule.frequency')->label(Lang::get('frequency'))->required()->live()
                    ->options(collect(['hourly', 'daily', 'weekly', 'monthly'])->mapWithKeys(fn ($key) => [$key => Lang::get($key)])->all()),
                TextInput::make('schedule.time')->label(Lang::get('time'))->required()->rules(['date_format:H:i'])->type('time')->visible(fn (Get $get) => $get('schedule.frequency') !== 'hourly'),
                Select::make('schedule.timezone')->label(Lang::get('timezone'))->required()->searchable()
                    ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list())),
                TextInput::make('schedule.hours')->label(Lang::get('hours'))->numeric()->integer()->minValue(1)->maxValue(8760)->required()->visible(fn (Get $get) => $get('schedule.frequency') === 'hourly'),
                TextInput::make('schedule.weekday')->label(Lang::get('weekday'))->numeric()->integer()->minValue(1)->maxValue(7)->required()->visible(fn (Get $get) => $get('schedule.frequency') === 'weekly'),
                TextInput::make('schedule.day')->label(Lang::get('day'))->numeric()->integer()->minValue(1)->maxValue(31)->required()->helperText(Lang::get('month_help'))->visible(fn (Get $get) => $get('schedule.frequency') === 'monthly'),
            ]),
            Actions::make([Action::make('save')->label(Lang::get('save'))->action(fn () => $this->save())->authorize('nova-poshta.manage')]),
        ]);
    }

    #[Exposed]
    public function save(): void
    {
        Gate::authorize('nova-poshta.manage');
        $data = $this->content->getState();
        try {
            app(SettingsService::class)->save($data['schedule'], $data['replacement_key'] ?? null);
        } finally {
            $this->data['replacement_key'] = null;
        }
        Notification::make()->title(Lang::get('saved'))->success()->send();
    }
}
