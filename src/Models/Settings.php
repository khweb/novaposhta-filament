<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NovaPoshta\Filament\Support\Tables;

final class Settings extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['api_key'];

    protected $casts = [
        'api_key' => 'encrypted', 'schedule' => 'array',
        'last_scheduled_at' => 'immutable_datetime', 'scheduler_seen_at' => 'immutable_datetime',
        'worker_seen_at' => 'immutable_datetime', 'api_seen_at' => 'immutable_datetime',
        'key_checked_at' => 'immutable_datetime', 'last_success_at' => 'immutable_datetime',
    ];

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class, 'active_generation_id');
    }

    public function getTable(): string
    {
        return Tables::name('settings');
    }

    public static function current(): self
    {
        return self::query()->findOrFail(1);
    }
}
