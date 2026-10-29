<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Exceptions;

final class DirectoryException extends \RuntimeException
{
    public function __construct(public readonly string $reason = 'upstream_unavailable', public readonly bool $retryable = false, public readonly int $retryAfter = 0, public readonly ?int $httpStatus = null)
    {
        parent::__construct($reason);
    }
}
