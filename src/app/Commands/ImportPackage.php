<?php

declare(strict_types=1);

namespace App\Commands;

use App\Exceptions\InvalidPackageException;
use App\Exceptions\PublishException;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Publishes a .nupkg straight from disk, bypassing HTTP.
 *
 * Useful in its own right — seeding a feed, restoring from a backup — and it
 * is what lets the read side (lot 3) be exercised against a populated feed
 * before the push endpoint exists.
 */
final class ImportPackage extends BaseCommand
{
    protected $group       = 'Pepite';
    protected $name        = 'pepite:import';
    protected $description = 'Imports one or more .nupkg files from disk into a feed.';
    protected $usage       = 'pepite:import <path> [<path>...] [--feed default] [--owner 1]';
    protected $arguments   = [
        'path' => 'Path to a .nupkg file, or a glob such as "packages/*.nupkg".',
    ];
    protected $options = [
        '--feed'  => 'Feed slug to publish into. Default: "default".',
        '--owner' => 'User id to record as owner of newly claimed identifiers.',
    ];

    public function run(array $params): int
    {
        $patterns = array_values(array_filter($params, static fn ($value, $key): bool => is_int($key), ARRAY_FILTER_USE_BOTH));

        if ($patterns === []) {
            CLI::error('Give at least one .nupkg path.');
            CLI::write($this->usage);

            return EXIT_USER_INPUT;
        }

        $feed  = (string) ($params['feed'] ?? CLI::getOption('feed') ?? 'default');
        $owner = $params['owner'] ?? CLI::getOption('owner');
        $owner = $owner === null || $owner === true ? null : (int) $owner;

        $files = $this->expand($patterns);

        if ($files === []) {
            CLI::error('No file matched.');

            return EXIT_USER_INPUT;
        }

        $publisher = service('packagePublisher');
        $failures  = 0;

        foreach ($files as $file) {
            try {
                $result = $publisher->publish($file, $feed, $owner);

                CLI::write(sprintf(
                    '  %s %s %s%s',
                    CLI::color('published', 'green'),
                    $result->metadata->id,
                    $result->metadata->version->full(),
                    $result->claimedNewIdentifier ? CLI::color('  (new identifier)', 'yellow') : '',
                ));
            } catch (PublishException $e) {
                $failures++;
                CLI::write(sprintf(
                    '  %s %s — %s',
                    CLI::color(sprintf('refused (%d)', $e->status), 'red'),
                    basename($file),
                    $e->getMessage(),
                ));
            } catch (InvalidPackageException $e) {
                $failures++;
                CLI::write(sprintf('  %s %s — %s', CLI::color('invalid', 'red'), basename($file), $e->getMessage()));
            } catch (Throwable $e) {
                $failures++;
                CLI::write(sprintf('  %s %s — %s', CLI::color('failed', 'red'), basename($file), $e->getMessage()));
            }
        }

        CLI::newLine();
        CLI::write(sprintf('%d of %d imported into feed "%s".', count($files) - $failures, count($files), $feed));

        return $failures === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }

    /**
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    private function expand(array $patterns): array
    {
        $files = [];

        foreach ($patterns as $pattern) {
            // spark changes the working directory to public/ during boot, so a
            // path relative to where the user actually is has to be tried
            // against the project root as well.
            $candidates = [$pattern];

            if (! preg_match('#^([A-Za-z]:[\\\\/]|/)#', $pattern)) {
                $candidates[] = rtrim(ROOTPATH, '\\/') . DIRECTORY_SEPARATOR . $pattern;
            }

            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    $files[] = $candidate;

                    continue 2;
                }

                $matches = array_filter((array) glob($candidate), is_file(...));

                if ($matches !== []) {
                    $files = array_merge($files, array_values($matches));

                    continue 2;
                }
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }
}
