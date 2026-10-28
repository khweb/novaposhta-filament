<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Support;

final class Lang
{
    public static function get(string $key, array $replace = []): string
    {
        $locale = in_array(app()->getLocale(), ['uk', 'ru', 'en'], true) ? app()->getLocale() : 'uk';

        return (string) trans('nova-poshta::messages.'.$key, $replace, $locale);
    }
}
