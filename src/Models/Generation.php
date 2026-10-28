<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use NovaPoshta\Filament\Support\Tables;

final class Generation extends Model
{
    protected $guarded = ['id'];

    public function getTable(): string
    {
        return Tables::name('generations');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }
}
