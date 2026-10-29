<?php declare(strict_types = 1);

namespace ShipMonk\ComposerNormalizer;

use RuntimeException;
use Throwable;

final class FileAccessException extends RuntimeException
{

    public function __construct(
        string $message,
        ?Throwable $previous = null,
    )
    {
        parent::__construct($message, 0, $previous);
    }

}
