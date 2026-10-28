<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NovaPoshta\Filament\Support\Tables;

class DirectoryRecord extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array'];

    protected string $directory = 'settlements';

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class, 'generation_id');
    }

    public function getTable(): string
    {
        return Tables::name($this->directory);
    }

    public function inDirectory(string $directory): static
    {
        if (! in_array($directory, Tables::directories(), true)) {
            throw new \InvalidArgumentException('Unknown directory');
        }
        $this->directory = $directory;

        return $this;
    }

    public function newInstance($attributes = [], $exists = false): static
    {
        $model = parent::newInstance($attributes, $exists);
        $model->directory = $this->directory;

        return $model;
    }
}
