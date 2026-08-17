<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\FeedModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Creates and lists feeds.
 *
 * There is no admin console yet (lot 6), so this is the only way to get a
 * second feed — in particular a private one, which is what proves the
 * visibility mechanism actually works end to end.
 */
final class ManageFeed extends BaseCommand
{
    protected $group       = 'Pepite';
    protected $name        = 'pepite:feed';
    protected $description = 'Creates or lists feeds.';
    protected $usage       = 'pepite:feed create <slug> [-n "Name"] [--private] [--no-new-packages] '
        . "[--package-types Foo,Bar]\n"
        . '       pepite:feed list';
    protected $arguments = [
        'action' => 'create or list',
        'slug'   => 'Feed slug (create only).',
    ];
    protected $options = [
        '-n'                => 'Display name. Defaults to the slug.',
        '--private'         => 'Reading the feed requires an API key over Basic auth.',
        '--no-new-packages' => 'Refuse any push that would claim a new identifier.',
        '--package-types'   => 'Comma separated list of accepted packageType names. Default: any.',
    ];

    public function run(array $params): int
    {
        $action = $params[0] ?? null;

        return match ($action) {
            'create' => $this->create($params),
            'list'   => $this->list(),
            default  => $this->usage(),
        };
    }

    private function create(array $params): int
    {
        $slug = $params[1] ?? null;

        if (! is_string($slug) || trim($slug) === '') {
            CLI::error('Give a feed slug.');
            CLI::write($this->usage);

            return EXIT_USER_INPUT;
        }

        $slug  = strtolower(trim($slug));
        $feeds = model(FeedModel::class);

        if ($feeds->findBySlug($slug) !== null) {
            CLI::error(sprintf('Feed "%s" already exists.', $slug));

            return EXIT_USER_INPUT;
        }

        $name = $params['n'] ?? CLI::getOption('n');
        $name = is_string($name) && trim($name) !== '' ? trim($name) : $slug;

        $types = $params['package-types'] ?? CLI::getOption('package-types');
        $types = is_string($types) && trim($types) !== ''
            ? array_values(array_filter(array_map(trim(...), explode(',', $types))))
            : [];

        $feeds->insert([
            'slug'                  => $slug,
            'name'                  => $name,
            'visibility'            => $this->flag($params, 'private') ? 'private' : 'public',
            'allow_new_packages'    => ! $this->flag($params, 'no-new-packages'),
            'allowed_package_types' => $types === [] ? null : json_encode($types),
        ]);

        CLI::write(sprintf('  %s feed "%s" created.', CLI::color('OK', 'green'), $slug));

        return EXIT_SUCCESS;
    }

    private function list(): int
    {
        $feeds = model(FeedModel::class)->orderBy('id', 'ASC')->findAll();

        if ($feeds === []) {
            CLI::write('  No feed exists yet.');

            return EXIT_SUCCESS;
        }

        foreach ($feeds as $feed) {
            CLI::write(sprintf(
                '  %-20s %-8s %s',
                $feed['slug'],
                $feed['visibility'],
                $feed['name'],
            ));
        }

        return EXIT_SUCCESS;
    }

    private function usage(): int
    {
        CLI::write($this->usage);

        return EXIT_USER_INPUT;
    }

    /**
     * Whether a valueless flag such as --private was passed.
     *
     * Neither the real CLI parser nor the command() test helper puts a
     * boolean in $params for a flag with no value — both record it as
     * `null`, which `??` treats as absent. CLI::getOption() hides this by
     * translating null to true, but only for a genuine command-line
     * invocation: it reads its own static argv parse, which command()
     * bypasses entirely. Presence in $params, value or not, is therefore the
     * only check that works in both places.
     */
    private function flag(array $params, string $name): bool
    {
        return array_key_exists($name, $params) || CLI::getOption($name) !== null;
    }
}
