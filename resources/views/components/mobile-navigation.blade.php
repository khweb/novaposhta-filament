@props(['navigation'])

@php
    $items = collect($navigation)->flatMap(fn ($group) => collect($group->getItems())
        ->flatMap(fn ($item) => [$item, ...$item->getChildItems()]));
    $active = $items->last(fn ($item) => $item->isActive());
@endphp
<x-filament::dropdown placement="bottom-start" width="sm"
    :attributes="\Filament\Support\prepare_inherited_attributes($attributes)->class(['fi-page-sub-navigation-dropdown'])">
    <x-slot name="trigger">
        <x-filament::button color="gray" :icon="\Filament\Support\Icons\Heroicon::ChevronDown" icon-position="after">
            {{ $active?->getLabel() }}
        </x-filament::button>
    </x-slot>
    <x-filament::dropdown.list>
        @foreach ($items as $item)
            <x-filament::dropdown.list.item tag="a" :href="$item->getUrl()"
                :icon="$item->getIcon()" :badge="$item->getBadge()"
                :badge-color="$item->getBadgeColor($item->getBadge())"
                :target="$item->shouldOpenUrlInNewTab() ? '_blank' : null"
                :aria-current="$item->isActive() ? 'page' : null">
                {{ $item->getLabel() }}
            </x-filament::dropdown.list.item>
        @endforeach
    </x-filament::dropdown.list>
</x-filament::dropdown>
