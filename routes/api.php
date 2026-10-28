<?php

use Illuminate\Support\Facades\Route;
use NovaPoshta\Filament\Http\DirectoryController;

Route::prefix(config('nova-poshta.rest.prefix'))->middleware(config('nova-poshta.rest.middleware', ['api']))->group(function (): void {
    foreach (['areas', 'districts', 'settlements', 'warehouses', 'warehouse-types', 'streets'] as $directory) {
        Route::get($directory, DirectoryController::class)->defaults('directory', $directory)
            ->middleware($directory === 'streets' ? ['throttle:nova-poshta', 'throttle:nova-poshta-streets'] : ['throttle:nova-poshta'])
            ->name('nova-poshta.'.$directory);
    }
});
