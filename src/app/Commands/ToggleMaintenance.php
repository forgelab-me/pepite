<?php

declare(strict_types=1);

namespace App\Commands;

use App\Filters\Maintenance;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Toggles the maintenance flag around a manual release apply, so that
 * `dotnet restore`/`push` traffic gets a clean 503 instead of hitting files
 * mid-replacement. See App\Filters\Maintenance.
 */
final class ToggleMaintenance extends BaseCommand
{
    protected $group       = 'Pepite';
    protected $name        = 'pepite:maintenance';
    protected $description = 'Turns the NuGet API maintenance mode on or off.';
    protected $usage       = 'pepite:maintenance on|off';
    protected $arguments   = ['state' => 'on or off'];

    public function run(array $params): int
    {
        $state = $params[0] ?? null;

        if ($state === 'on') {
            file_put_contents(Maintenance::FLAG_FILE, date('c'));
            CLI::write('  Maintenance mode is ON. NuGet API routes now answer 503.');

            return EXIT_SUCCESS;
        }

        if ($state === 'off') {
            if (is_file(Maintenance::FLAG_FILE)) {
                unlink(Maintenance::FLAG_FILE);
            }

            CLI::write('  Maintenance mode is OFF.');

            return EXIT_SUCCESS;
        }

        CLI::write($this->usage);

        return EXIT_USER_INPUT;
    }
}
