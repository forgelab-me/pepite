<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Libraries\Package\PackageMetadata;

/**
 * What a successful publication produced.
 */
final class PublishResult
{
    public function __construct(
        public readonly int $feedId,
        public readonly string $feedSlug,
        public readonly int $packageRowId,
        public readonly int $versionRowId,
        public readonly PackageMetadata $metadata,
        public readonly string $directory,
        public readonly bool $claimedNewIdentifier,
    ) {
    }
}
