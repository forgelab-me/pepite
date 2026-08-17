<?php

declare(strict_types=1);

namespace App\Commands;

use App\Filters\NuGetApiKey;
use App\Models\FeedApiKeyRuleModel;
use App\Models\FeedModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Issues an API key for pushing packages.
 *
 * The key is one of Shield's access tokens: hashed in auth_identities, carrying
 * its scopes and its last-used timestamp. Nothing about key storage is
 * reimplemented here.
 *
 * The raw value is shown once and never again — only its hash is kept.
 *
 * --feed and --pattern attach a feed_api_key_rules row restricting the key's
 * reach, the way a CI pipeline should be scoped: --pattern "Contoso.*"
 * --no-create lets it push new versions of existing Contoso.* packages and
 * nothing else. A key with neither option is unrestricted, matching a plain
 * nuget.org key.
 */
final class CreateApiKey extends BaseCommand
{
    protected $group       = 'Pepite';
    protected $name        = 'pepite:key';
    protected $description = 'Issues an API key for a user.';
    protected $usage       = 'pepite:key -e user@example.test [-n "CI pipeline"] [--read-only] '
        . '[--feed default] [--pattern "Contoso.*"] [--no-create]';
    protected $options = [
        '-e'          => 'Email of the user the key belongs to. Required.',
        '-n'          => 'A name for the key, shown in the admin console.',
        '--read-only' => 'Issue a key that can neither push nor delist.',
        '--feed'      => 'Restrict the key to this feed slug. Requires --pattern or --no-create.',
        '--pattern'   => 'Restrict the key to identifiers matching this glob, e.g. "Contoso.*".',
        '--no-create' => 'The key may push new versions of existing packages, never a new identifier.',
    ];

    public function run(array $params): int
    {
        $email = $params['e'] ?? CLI::getOption('e');

        if (! is_string($email) || trim($email) === '') {
            CLI::error('Give the account email with -e.');
            CLI::write($this->usage);

            return EXIT_USER_INPUT;
        }

        $users = model(UserModel::class);
        $user  = $users->findByCredentials(['email' => trim($email)]);

        if ($user === null) {
            CLI::error(sprintf('No account with email "%s".', $email));

            return EXIT_USER_INPUT;
        }

        $feedSlug = $params['feed'] ?? CLI::getOption('feed');
        $feed     = null;

        if (is_string($feedSlug) && trim($feedSlug) !== '') {
            $feed = model(FeedModel::class)->findBySlug(trim($feedSlug));

            if ($feed === null) {
                CLI::error(sprintf('No feed named "%s".', $feedSlug));

                return EXIT_USER_INPUT;
            }
        }

        $name = $params['n'] ?? CLI::getOption('n');
        $name = is_string($name) && trim($name) !== '' ? trim($name) : 'API key';

        $readOnly = $this->flag($params, 'read-only');

        $scopes = $readOnly
            ? ['packages.read']
            : ['packages.read', NuGetApiKey::SCOPE_PUSH, NuGetApiKey::SCOPE_UNLIST];

        $token = $user->generateAccessToken($name, $scopes);

        $pattern  = $params['pattern'] ?? CLI::getOption('pattern');
        $pattern  = is_string($pattern) && trim($pattern) !== '' ? trim($pattern) : null;
        $noCreate = $this->flag($params, 'no-create');
        $hasRule  = $feed !== null || $pattern !== null || $noCreate;

        if ($hasRule) {
            model(FeedApiKeyRuleModel::class)->insert([
                'identity_id'        => (int) $token->id,
                'feed_id'            => $feed === null ? null : (int) $feed['id'],
                'id_pattern'         => $pattern,
                'can_create_package' => ! $noCreate,
                'created_at'         => date('Y-m-d H:i:s'),
            ]);
        }

        CLI::newLine();
        CLI::write('  ' . CLI::color('Key issued. It is shown once — only its hash is stored.', 'yellow'));
        CLI::newLine();
        CLI::write('  ' . CLI::color($token->raw_token, 'green'));
        CLI::newLine();
        CLI::write('  account : ' . $user->email);
        CLI::write('  name    : ' . $name);
        CLI::write('  scopes  : ' . implode(', ', $scopes));

        if ($hasRule) {
            CLI::write('  feed    : ' . ($feed === null ? '(any)' : $feed['slug']));
            CLI::write('  pattern : ' . ($pattern ?? '(any)'));
            CLI::write('  create  : ' . ($noCreate ? 'no' : 'yes'));
        }

        CLI::newLine();
        CLI::write('  dotnet nuget push <package> -s <feed-index-url> -k ' . $token->raw_token);
        CLI::newLine();

        return EXIT_SUCCESS;
    }

    /**
     * Whether a valueless flag such as --read-only was passed. See
     * ManageFeed::flag() for why this can't just be CLI::getOption().
     */
    private function flag(array $params, string $name): bool
    {
        return array_key_exists($name, $params) || CLI::getOption($name) !== null;
    }
}
