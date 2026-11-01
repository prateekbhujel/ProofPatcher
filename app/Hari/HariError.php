<?php

declare(strict_types=1);

namespace Hari;

/** Typed kernel failure. Codes are stable API; messages carry no internals. */
final class HariError extends \RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function code(): string
    {
        return $this->errorCode;
    }
}
