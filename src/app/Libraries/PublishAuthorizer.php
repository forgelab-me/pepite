<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Exceptions\PublishException;
use App\Models\FeedApiKeyRuleModel;
use App\Models\PackageOwnerModel;

/**
 * Decides who may push what — the half of the push cascade PackagePublisher
 * deliberately leaves out (see its own docblock): it answers "can this
 * package be stored here", this answers "may this identity store it".
 *
 * Two independent questions, both from PLAN.md 9.2:
 *
 *   - does the API key's reach cover this package identifier?
 *   - if the package already exists, does the pushing user own it?
 *
 * A key with no rows in feed_api_key_rules is unrestricted — the nuget.org
 * default for a plain key.
 */
final class PublishAuthorizer
{
    public function __construct(
        private readonly FeedApiKeyRuleModel $rules,
        private readonly PackageOwnerModel $owners,
    ) {
    }

    /**
     * @param array<string, mixed>|null $existingPackage null when this push
     *                                                   would create a new identifier
     */
    public function authorize(
        int $identityId,
        ?int $userId,
        int $feedId,
        string $packageId,
        ?array $existingPackage,
    ): void {
        $this->authorizeKeyReach($identityId, $feedId, $packageId, $existingPackage === null);

        if ($existingPackage !== null) {
            $this->authorizeOwnership($userId, (int) $existingPackage['id'], $packageId);
        }
    }

    private function authorizeKeyReach(int $identityId, int $feedId, string $packageId, bool $isNewPackage): void
    {
        // No rule anywhere: the key is unrestricted, matching a plain
        // nuget.org API key with no configured glob. This has to be checked
        // against *every* rule the identity owns, not just this feed's —
        // otherwise a key scoped to feed A would look unrestricted on feed B,
        // rather than having no reach there at all.
        if (! $this->rules->hasAnyRule($identityId)) {
            return;
        }

        $rules    = $this->rules->forIdentityAndFeed($identityId, $feedId);
        $matching = array_filter(
            $rules,
            static fn (array $rule): bool => self::matches($rule['id_pattern'], $packageId),
        );

        if ($matching === []) {
            throw PublishException::idNotAllowedByKey($packageId);
        }

        if (! $isNewPackage) {
            return;
        }

        $canCreate = array_filter($matching, static fn (array $rule): bool => (bool) $rule['can_create_package']);

        if ($canCreate === []) {
            throw PublishException::keyCannotCreatePackages($packageId);
        }
    }

    private function authorizeOwnership(?int $userId, int $existingPackageRowId, string $packageId): void
    {
        if ($userId === null || ! $this->owners->owns($existingPackageRowId, $userId)) {
            throw PublishException::notAnOwner($packageId);
        }
    }

    /**
     * A null pattern matches everything. Otherwise `*` matches any run of
     * characters, case-insensitively, and everything else is literal.
     */
    private static function matches(?string $pattern, string $packageId): bool
    {
        if ($pattern === null || $pattern === '') {
            return true;
        }

        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';

        return preg_match($regex, $packageId) === 1;
    }
}
