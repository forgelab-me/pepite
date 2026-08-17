<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * A version or version range string could not be understood.
 */
final class InvalidVersionException extends InvalidArgumentException
{
    public static function forVersion(string $value): self
    {
        return new self(sprintf('"%s" is not a valid NuGet version.', $value));
    }

    public static function forRange(string $value): self
    {
        return new self(sprintf('"%s" is not a valid NuGet version range.', $value));
    }

    public static function outOfRange(string $value): self
    {
        return new self(sprintf(
            'Version "%s" has a segment above %d, which NuGet cannot represent.',
            $value,
            2147483647,
        ));
    }
}
