<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Pages;

use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Facades\Gate;
use NovaPoshta\Filament\Support\Lang;

abstract class PackagePage extends Page
{
    protected static string $ability = 'view';

    protected static string $label = '';

    public static function canAccess(): bool
    {
        return Gate::allows('nova-poshta.view') && Gate::allows('nova-poshta.'.static::$ability);
    }

    public function boot(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public static function getNavigationGroup(): string
    {
        return Lang::get('plugin');
    }

    public static function getNavigationLabel(): string
    {
        return Lang::get(static::$label);
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return Lang::get(static::$label);
    }
}
