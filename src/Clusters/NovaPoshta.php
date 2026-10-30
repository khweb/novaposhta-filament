<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Clusters;

use Filament\Clusters\Cluster;
use Filament\Facades\Filament;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use NovaPoshta\Filament\Support\Lang;

final class NovaPoshta extends Cluster
{
    protected static ?string $slug = 'nova-poshta';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    public static function canAccess(): bool
    {
        return Gate::allows('nova-poshta.view') && self::canAccessClusteredComponents();
    }

    public static function getNavigationLabel(): string
    {
        return Lang::get('plugin');
    }

    public static function getClusterBreadcrumb(): string
    {
        return Lang::get('plugin');
    }

    public static function getNavigationItemActiveRoutePattern(): string
    {
        return Filament::getCurrentOrDefaultPanel()->generateRouteName('pages.nova-poshta.*');
    }
}
