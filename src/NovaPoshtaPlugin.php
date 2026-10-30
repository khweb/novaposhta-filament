<?php

declare(strict_types=1);

namespace NovaPoshta\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use NovaPoshta\Filament\Clusters\NovaPoshta;
use NovaPoshta\Filament\Pages\Directories;
use NovaPoshta\Filament\Pages\SettingsPage;
use NovaPoshta\Filament\Pages\SyncReportPage;
use NovaPoshta\Filament\Pages\Tester;
use NovaPoshta\Filament\Pages\Updates;

final class NovaPoshtaPlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'nova-poshta';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([NovaPoshta::class, SettingsPage::class, Directories::class, Updates::class, Tester::class, SyncReportPage::class]);
    }

    public function boot(Panel $panel): void {}
}
