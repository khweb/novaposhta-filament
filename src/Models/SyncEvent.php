<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Models;

use Illuminate\Database\Eloquent\Model;
use NovaPoshta\Filament\Support\Tables;

final class SyncEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['context' => 'array', 'created_at' => 'immutable_datetime'];

    public function getTable(): string
    {
        return Tables::name('sync_events');
    }
}
