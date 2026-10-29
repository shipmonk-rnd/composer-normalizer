<?php declare(strict_types = 1);

namespace ShipMonk\ComposerNormalize;

use RuntimeException;
use Throwable;

final class InvalidComposerJsonException extends RuntimeException
{

    public function __construct(
        string $message,
        ?Throwable $previous = null,
    )
    {
        parent::__construct($message, 0, $previous);
    }

}
