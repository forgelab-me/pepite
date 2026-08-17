<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A publication was refused.
 *
 * Carries the status the push endpoint should answer with, so the controller
 * at lot 4 has no mapping table to maintain and no reason to drift from what
 * the service actually decided.
 */
final class PublishException extends RuntimeException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function feedNotFound(string $slug): self
    {
        return new self(sprintf('There is no feed named "%s".', $slug), 404);
    }

    /**
     * The one refusal that is not a failure: the version is already published,
     * and published versions are immutable. Clients rely on this — a cached
     * version is never re-downloaded.
     */
    public static function versionAlreadyExists(string $id, string $version): self
    {
        return new self(
            sprintf('%s %s is already published. Published versions are immutable.', $id, $version),
            409,
        );
    }

    public static function newPackagesNotAllowed(string $feedSlug, string $id): self
    {
        return new self(
            sprintf('Feed "%s" does not accept new package identifiers, and "%s" is unknown to it.', $feedSlug, $id),
            403,
        );
    }

    /**
     * @param list<string> $allowed
     */
    public static function packageTypeRejected(string $feedSlug, array $found, array $allowed): self
    {
        return new self(
            sprintf(
                'Feed "%s" accepts package types [%s]; this package declares [%s].',
                $feedSlug,
                implode(', ', $allowed),
                implode(', ', $found),
            ),
            400,
        );
    }

    public static function storageFailed(string $reason): self
    {
        return new self(sprintf('The package could not be stored: %s', $reason), 500);
    }

    public static function idNotAllowedByKey(string $id): self
    {
        return new self(
            sprintf('This API key is not allowed to publish "%s".', $id),
            403,
        );
    }

    public static function keyCannotCreatePackages(string $id): self
    {
        return new self(
            sprintf('This API key may only push new versions of existing packages, and "%s" is unknown.', $id),
            403,
        );
    }

    public static function notAnOwner(string $id): self
    {
        return new self(
            sprintf('You are not an owner of "%s".', $id),
            403,
        );
    }
}
