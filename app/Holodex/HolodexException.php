<?php

namespace App\Holodex;

use RuntimeException;

/** Failed Holodex call; carries status and path, never the API key. */
class HolodexException extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $path, string $reason)
    {
        parent::__construct("Holodex {$path}: {$reason}");
    }
}
