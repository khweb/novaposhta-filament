<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use NovaPoshta\Filament\Services\KeyHealth;

final class CheckApiKey implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly string $revision) {}

    public function handle(KeyHealth $health): void
    {
        $health->check($this->revision);
    }
}
