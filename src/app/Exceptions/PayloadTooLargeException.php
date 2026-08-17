<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The upload exceeded the configured ceiling.
 *
 * Answered with 413, and the message names the limit: on shared hosting this
 * is the failure publishers hit most, and "too large" without a number leaves
 * them with nothing to act on.
 */
final class PayloadTooLargeException extends RuntimeException
{
    public function __construct(
        public readonly int $limitBytes,
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf(
            'The upload exceeds the maximum accepted size of %d bytes.',
            $limitBytes,
        ));
    }

    public static function forField(string $name, int $limitBytes): self
    {
        return new self($limitBytes, sprintf(
            'Field "%s" exceeds the maximum accepted size of %d bytes.',
            $name,
            $limitBytes,
        ));
    }
}
