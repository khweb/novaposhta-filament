<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Exceptions;

final class DirectoryException extends \RuntimeException
{
    public function __construct(public readonly string $reason = 'upstream_unavailable')
    {
        parent::__construct($reason);
    }
}
