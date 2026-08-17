<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Libraries\Version\NuGetVersion;
use App\Models\FeedModel;
use App\Models\PackageModel;
use App\Models\PackageVersionModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Proves the sort key does its job where it matters: inside SQL.
 *
 * NuGetVersionTest already checks that the key's byte order matches the
 * comparator in PHP. What has to hold here is that the *database* reaches the
 * same order — that is the whole reason the column exists, and the reason it
 * must carry a binary collation.
 *
 * @internal
 */
final class VersionOrderingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    /**
     * Deliberately unordered, and picked to exercise every rule at once:
     * prerelease before release, numeric before alphanumeric labels, numeric
     * labels compared as numbers, case folding, four segments, and build
     * metadata that must not disturb anything.
     *
     * @var list<string>
     */
    private const VERSIONS = [
        '2.0.0',
        '1.0.0-beta.11',
        '1.0.0',
        '1.0.0-alpha',
        '1.0.1',
        '1.0.0-beta.2',
        '0.9.0',
        '1.0.0.1',
        '1.0.0-RC.1',
        '1.0.0-1',
        '1.1.0',
        '1.0.0-alpha.beta',
    ];

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;
    private int $packageId;

    protected function setUp(): void
    {
        parent::setUp();

        $feed = model(FeedModel::class)->findBySlug('default');

        model(PackageModel::class)->insert([
            'feed_id'          => (int) $feed['id'],
            'package_id'       => 'Ordering.Sample',
            'package_id_lower' => 'ordering.sample',
        ]);

        $this->packageId = (int) model(PackageModel::class)->getInsertID();

        foreach (self::VERSIONS as $raw) {
            $this->insertVersion(NuGetVersion::parse($raw));
        }
    }

    public function testTheDatabaseOrdersVersionsExactlyAsNuGetDoes(): void
    {
        $expected = array_map(
            static fn (NuGetVersion $v): string => $v->normalized(),
            NuGetVersion::sort(array_map(NuGetVersion::parse(...), self::VERSIONS)),
        );

        $actual = array_column(
            model(PackageVersionModel::class)->forPackage($this->packageId),
            'version_normalized',
        );

        $this->assertSame($expected, $actual);
    }

    /**
     * The migration forces a binary collation on this column, and that is not
     * a precaution: measured on MariaDB 11, utf8mb4_unicode_ci sorts the
     * stable-release sentinel '~' *before* the prerelease markers, which would
     * place 1.0.0 ahead of 1.0.0-alpha in every listing we serve.
     */
    public function testTheSortKeyColumnUsesABinaryCollationOnMysql(): void
    {
        $db = db_connect();

        if ($db->DBDriver !== 'MySQLi') {
            $this->markTestSkipped('SQLite compares TEXT with BINARY already.');
        }

        $row = $db->query(
            'SELECT COLLATION_NAME AS collation_name FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$db->prefixTable('package_versions'), 'version_sort_key'],
        )->getRowArray();

        $this->assertNotNull($row, 'version_sort_key column not found');
        $this->assertStringEndsWith('_bin', (string) $row['collation_name']);
    }

    public function testLatestIgnoresPrereleasesUnlessAsked(): void
    {
        $versions = model(PackageVersionModel::class);

        $this->assertSame('2.0.0', $versions->latestForPackage($this->packageId)['version_normalized']);
        $this->assertSame(
            '2.0.0',
            $versions->latestForPackage($this->packageId, includePrerelease: true)['version_normalized'],
        );
    }

    public function testLatestFallsBackToAPrereleaseWhenNothingIsStable(): void
    {
        $versions = model(PackageVersionModel::class);
        $versions->where('package_id', $this->packageId)->where('is_prerelease', false)->delete();

        $this->assertNull($versions->latestForPackage($this->packageId));
        $this->assertSame(
            '1.0.0-RC.1',
            $versions->latestForPackage($this->packageId, includePrerelease: true)['version_normalized'],
            'RC sorts after beta and alpha, and case must not change that',
        );
    }

    public function testDelistedVersionsCanBeFilteredOutWithoutBeingRemoved(): void
    {
        $versions = model(PackageVersionModel::class);
        $versions->where('package_id', $this->packageId)
            ->where('version_normalized_lower', '1.0.0')
            ->set('is_listed', false)
            ->update();

        $listed = array_column($versions->forPackage($this->packageId, includeUnlisted: false), 'version_normalized');

        $this->assertNotContains('1.0.0', $listed);
        // Still stored: delisting hides a version from search, it never deletes it.
        $this->assertCount(count(self::VERSIONS), $versions->forPackage($this->packageId));
    }

    public function testUniquenessIsCaseInsensitiveOnPrereleaseLabels(): void
    {
        $this->assertTrue(
            model(PackageVersionModel::class)->versionExists($this->packageId, NuGetVersion::parse('1.0.0-rc.1')),
            '1.0.0-rc.1 and 1.0.0-RC.1 are the same version',
        );
    }

    public function testBuildMetadataDoesNotCreateASeparateVersion(): void
    {
        $this->assertTrue(
            model(PackageVersionModel::class)->versionExists($this->packageId, NuGetVersion::parse('1.0.0+sha.abc')),
        );
    }

    private function insertVersion(NuGetVersion $version): void
    {
        model(PackageVersionModel::class)->insert([
            'package_id'               => $this->packageId,
            'version_original'         => $version->original,
            'version_normalized'       => $version->normalized(),
            'version_normalized_lower' => $version->normalizedLower(),
            'version_sort_key'         => $version->sortKey(),
            'is_prerelease'            => $version->isPrerelease(),
            'is_listed'                => true,
            'semver_level'             => $version->isSemVer2() ? 2 : 0,
            'nupkg_path'               => 'unused',
            'nuspec_path'              => 'unused',
            'sha512_base64'            => 'unused',
        ]);
    }
}
