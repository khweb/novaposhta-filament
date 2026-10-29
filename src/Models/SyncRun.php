<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NovaPoshta\Filament\Support\Tables;

final class SyncRun extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['counts' => 'array', 'checkpoint' => 'array', 'revision' => 'integer', 'next_attempt_at' => 'immutable_datetime', 'heartbeat_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class, 'generation_id');
    }

    public function getTable(): string
    {
        return Tables::name('sync_runs');
    }
}
