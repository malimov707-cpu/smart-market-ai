<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class ImportFailure extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly bool $retryable = false)
    {
        parent::__construct($errorCode);
    }
}
