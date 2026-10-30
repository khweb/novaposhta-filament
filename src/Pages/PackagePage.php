<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Pages;

use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Facades\Gate;
use NovaPoshta\Filament\Clusters\NovaPoshta;
use NovaPoshta\Filament\Services\DirectoryBrowser;
use NovaPoshta\Filament\Support\Lang;

abstract class PackagePage extends Page
{
    protected static ?string $cluster = NovaPoshta::class;

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

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    // Keep existing public page URLs and route names when adding the cluster.
    public static function prependClusterSlug(Panel $panel, string $slug): string
    {
        return $slug;
    }

    public static function prependClusterRouteBaseName(Panel $panel, string $name): string
    {
        return $name;
    }

    public static function getNavigationLabel(): string
    {
        return Lang::get(static::$label);
    }

    #[Unexposed]
    public function getSubNavigation(): array
    {
        $items = parent::getSubNavigation();
        foreach ($items as $item) {
            if ($item->getKey() === Directories::class) {
                $counts = $this instanceof Directories ? $this->directoryCounts() : [];
                $item->childItems(collect(DirectoryBrowser::DIRECTORIES)
                    ->map(fn (string $directory) => NavigationItem::make(Lang::get($directory))
                        ->url(Directories::getUrl(['directory' => $directory]))
                        ->badge(isset($counts[$directory]) ? number_format($counts[$directory], thousands_separator: ' ') : null,
                            color: $this instanceof Directories && $this->directory === $directory ? 'primary' : 'gray')
                        ->isActiveWhen(fn (): bool => $this instanceof Directories && $this->directory === $directory))
                    ->all());
            }
        }

        return $items;
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return Lang::get(static::$label);
    }
}
