<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A .nupkg or .nuspec could not be accepted.
 *
 * Every message is safe to show to the publisher: a push that fails must say
 * why, or the author has no way to fix it.
 */
final class InvalidPackageException extends RuntimeException
{
    public static function unreadableArchive(string $path, string $reason): self
    {
        return new self(sprintf('Cannot read package "%s": %s', basename($path), $reason));
    }

    public static function missingNuspec(): self
    {
        return new self('The package contains no .nuspec file at its root.');
    }

    public static function ambiguousNuspec(int $count): self
    {
        return new self(sprintf(
            'The package contains %d .nuspec files at its root; exactly one is required.',
            $count,
        ));
    }

    public static function malformedNuspec(string $reason): self
    {
        return new self(sprintf('The .nuspec is not valid XML: %s', $reason));
    }

    public static function missingElement(string $element): self
    {
        return new self(sprintf('The .nuspec has no <%s> element.', $element));
    }

    public static function invalidId(string $id): self
    {
        return new self(sprintf('"%s" is not a valid package identifier.', $id));
    }

    public static function unsafeEntry(string $entry): self
    {
        return new self(sprintf('The package contains an unsafe entry name: "%s".', $entry));
    }

    public static function entryNotFound(string $entry): self
    {
        return new self(sprintf('The package has no entry named "%s".', $entry));
    }

    public static function entryTooLarge(string $entry, int $limit): self
    {
        return new self(sprintf(
            'Entry "%s" is larger than the %d byte limit.',
            $entry,
            $limit,
        ));
    }
}
