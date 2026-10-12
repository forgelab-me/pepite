<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Filters\NuGetApiKey;
use App\Models\FeedApiKeyRuleModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Shield\Entities\AccessToken;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserIdentityModel;
use Forgelabme\TrustedPublishing\ScopedTokens;
use RuntimeException;
use Throwable;

/**
 * The one place an API key is created, reconfigured or revoked.
 *
 * A key is two things: a Shield access token, and — if it is restricted — a
 * feed_api_key_rules row. That second half is what makes the pair dangerous
 * to handle by hand, because a key with no rule is *unrestricted*
 * (PublishAuthorizer, FeedRead): a token created, then a failed insert of its
 * rule, is not a key that was never made but a key that can do everything.
 * Four controllers and a CLI command used to each do the two steps
 * separately. Here they are one transaction, and every change is audited.
 *
 * Who may call this is the caller's business (admin console, the user
 * themselves, the CLI, Trusted Publishing) — it only does the mutation.
 */
final class ApiKeyIssuer
{
    public const SCOPE_READ = 'packages.read';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly FeedApiKeyRuleModel $rules,
        private readonly UserIdentityModel $identities,
        private readonly Audit $audit,
    ) {
    }

    /**
     * What an admin (or the CLI) issues by default: the whole bundle, or
     * read-only. Self-service keys are narrower and pass their own list.
     *
     * @return list<string>
     */
    public static function scopes(bool $readOnly): array
    {
        return $readOnly
            ? [self::SCOPE_READ]
            : [self::SCOPE_READ, NuGetApiKey::SCOPE_PUSH, NuGetApiKey::SCOPE_UNLIST];
    }

    /**
     * @param list<string>                                                                     $scopes
     * @param array{feed_id: int|null, id_pattern: string|null, can_create_package: bool}|null $rule   null = unrestricted
     */
    public function issue(User $user, string $name, array $scopes, ?array $rule = null): AccessToken
    {
        $token = $this->atomically(function () use ($user, $name, $scopes, $rule): AccessToken {
            $token = $user->generateAccessToken($name, $scopes);

            if ($rule !== null) {
                $this->insertRule((int) $token->id, $rule);
            }

            return $token;
        });

        $this->audit->record('key.issue', sprintf('"%s" for %s', $name, $this->who($user)), $this->describe($scopes, $rule));

        return $token;
    }

    /**
     * A short-lived key for one Trusted Publishing run, always restricted to
     * the feed that trusted it. Token and rule are created together or not at
     * all: a minted push key that lost its rule would be unrestricted until
     * it expired.
     *
     * Expired minted keys are swept here too — one is created per workflow
     * run, so this is where they pile up — along with the rules they leave
     * behind, which nothing else deletes (feed_api_key_rules has no foreign
     * key to the identity).
     *
     * @param list<string>                                                                $scopes
     * @param array{feed_id: int|null, id_pattern: string|null, can_create_package: bool} $rule
     */
    public function mintExpiring(User $user, string $name, array $scopes, int $ttlMinutes, array $rule): AccessToken
    {
        ScopedTokens::purgeExpired(NuGetApiKey::SCOPE_PUSH);

        $this->db->table('feed_api_key_rules')
            ->where('identity_id NOT IN (SELECT id FROM ' . $this->db->prefixTable('auth_identities') . ')', null, false)
            ->delete();

        $token = $this->atomically(function () use ($user, $name, $scopes, $ttlMinutes, $rule): AccessToken {
            $token = ScopedTokens::mint($user, $name, $scopes, $ttlMinutes);
            $this->insertRule((int) $token->id, $rule);

            return $token;
        });

        $this->audit->record('key.mint', $name, $this->describe($scopes, $rule) + ['ttl_minutes' => $ttlMinutes]);

        return $token;
    }

    /**
     * Replaces a key's scopes and its restriction in one step. Null removes
     * the restriction — the key becomes unrestricted, which is the point of
     * asking, and why this is only reachable from the admin console.
     *
     * @param list<string>                                                                     $scopes
     * @param array{feed_id: int|null, id_pattern: string|null, can_create_package: bool}|null $rule
     */
    public function reconfigure(int $identityId, array $scopes, ?array $rule): void
    {
        $this->atomically(function () use ($identityId, $scopes, $rule): void {
            $this->identities->update($identityId, ['extra' => serialize($scopes)]);
            $this->rules->where('identity_id', $identityId)->delete();

            if ($rule !== null) {
                $this->insertRule($identityId, $rule);
            }
        });

        $this->audit->record('key.update', $this->label($identityId), $this->describe($scopes, $rule));
    }

    /**
     * Deletes the key and its restriction together. A no-op for an id that
     * is not an access token — this is never the way to remove a login.
     */
    public function revoke(int $identityId): void
    {
        $label = $this->label($identityId);

        $this->atomically(function () use ($identityId): void {
            $this->identities->where('id', $identityId)->where('type', 'access_token')->delete();
            $this->rules->where('identity_id', $identityId)->delete();
        });

        $this->audit->record('key.revoke', $label);
    }

    /**
     * @param array{feed_id: int|null, id_pattern: string|null, can_create_package: bool} $rule
     */
    private function insertRule(int $identityId, array $rule): void
    {
        $this->rules->insert([
            'identity_id'        => $identityId,
            'feed_id'            => $rule['feed_id'],
            'id_pattern'         => $rule['id_pattern'],
            'can_create_package' => $rule['can_create_package'],
            'created_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function atomically(callable $work): mixed
    {
        $this->db->transBegin();

        try {
            $result = $work();

            // Inside a transaction CodeIgniter does not throw on a failed
            // query: it flags the transaction and the call returns false.
            // Committing anyway would keep the half that worked — a token
            // with no rule, which is an unrestricted key.
            if (! $this->db->transStatus()) {
                throw new RuntimeException('A query failed; the key was not saved.');
            }

            $this->db->transCommit();

            return $result;
        } catch (Throwable $e) {
            $this->db->transRollback();
            // The flag a failed query set outlives the rollback and would make
            // the next, unrelated transaction on this connection roll back too.
            $this->db->resetTransStatus();

            throw $e;
        }
    }

    private function label(int $identityId): string
    {
        $row = $this->identities->asArray()->where('id', $identityId)->first();

        return $row === null ? '#' . $identityId : sprintf('"%s" (#%d)', (string) ($row['name'] ?? ''), $identityId);
    }

    private function who(User $user): string
    {
        return (string) ($user->email ?? $user->username ?? ('#' . $user->id));
    }

    /**
     * @param list<string>                                                                     $scopes
     * @param array{feed_id: int|null, id_pattern: string|null, can_create_package: bool}|null $rule
     *
     * @return array<string, mixed>
     */
    private function describe(array $scopes, ?array $rule): array
    {
        return [
            'scopes' => $scopes,
            'feed'   => $rule === null ? 'any' : ($rule['feed_id'] ?? 'any'),
            'ids'    => $rule['id_pattern'] ?? '*',
            'create' => $rule === null ? true : $rule['can_create_package'],
        ];
    }
}
