<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use App\Exceptions\InvalidVersionException;
use App\Libraries\Version\NuGetVersion;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fixtures;

/**
 * Conformance against the real thing.
 *
 * NuGetVersionTest states what we believe NuGet does. This one checks it
 * against NuGet.Versioning itself: tools/build-version-oracle.sh runs that
 * library and commits its answers to Fixtures/Versions/oracle.json.
 *
 * When a case here fails, our implementation is wrong — not the fixture.
 *
 * @internal
 */
final class NuGetVersionOracleTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('provideMatchesNuGetVersioning')]
    public function testMatchesNuGetVersioning(array $expected): void
    {
        $version = NuGetVersion::parse($expected['input']);

        $this->assertSame($expected['normalized'], $version->normalized(), 'ToNormalizedString()');
        $this->assertSame($expected['full'], $version->full(), 'ToFullString()');
        $this->assertSame($expected['isPrerelease'], $version->isPrerelease(), 'IsPrerelease');
        $this->assertSame($expected['isSemVer2'], $version->isSemVer2(), 'IsSemVer2');
        $this->assertSame($expected['metadata'], $version->metadata, 'Metadata');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideMatchesNuGetVersioning(): iterable
    {
        /** @var array<string, mixed> $oracle */
        $oracle = Fixtures::json('Versions/oracle.json');

        foreach ($oracle['versions'] as $entry) {
            yield $entry['input'] => [$entry];
        }
    }

    #[DataProvider('provideAcceptanceMatchesNuGetVersioning')]
    public function testAcceptanceMatchesNuGetVersioning(string $input, bool $parses): void
    {
        // parse() throws on an out-of-range segment where tryParse() would have
        // to answer "no", so route everything through the total function.
        $result = null;

        try {
            $result = NuGetVersion::tryParse($input);
        } catch (InvalidVersionException) {
            $result = null;
        }

        $this->assertSame($parses, $result !== null);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideAcceptanceMatchesNuGetVersioning(): iterable
    {
        /** @var array<string, mixed> $oracle */
        $oracle = Fixtures::json('Versions/oracle.json');

        foreach ($oracle['candidates'] as $entry) {
            yield sprintf('"%s"', $entry['input']) => [$entry['input'], $entry['parses']];
        }
    }

    /**
     * The oracle's ordering must be non-decreasing under our comparator, and
     * under a plain byte comparison of our sort keys.
     *
     * Asserting "non-decreasing" rather than array equality is deliberate:
     * versions that compare equal (1.0.0 and 1.0.0+build.5) have no defined
     * order between them.
     */
    public function testOrderingMatchesNuGetVersioning(): void
    {
        /** @var array<string, mixed> $oracle */
        $oracle = Fixtures::json('Versions/oracle.json');

        /** @var list<string> $sorted */
        $sorted = $oracle['sorted'];

        for ($i = 0; $i < count($sorted) - 1; $i++) {
            $lower  = NuGetVersion::parse($sorted[$i]);
            $higher = NuGetVersion::parse($sorted[$i + 1]);

            $this->assertLessThanOrEqual(
                0,
                $lower->compareTo($higher),
                sprintf('NuGet orders "%s" before "%s"', $sorted[$i], $sorted[$i + 1]),
            );

            $this->assertLessThanOrEqual(
                0,
                strcmp($lower->sortKey(), $higher->sortKey()),
                sprintf('Sort key disagrees for "%s" before "%s"', $sorted[$i], $sorted[$i + 1]),
            );
        }
    }

    #[DataProvider('provideEqualityMatchesNuGetVersioning')]
    public function testEqualityMatchesNuGetVersioning(string $left, string $right): void
    {
        $a = NuGetVersion::parse($left);
        $b = NuGetVersion::parse($right);

        $this->assertTrue($a->equals($b));

        // Equal versions must be indistinguishable to the database, otherwise
        // the same package could be published twice under two spellings.
        $this->assertSame($a->sortKey(), $b->sortKey());
        $this->assertSame($a->normalizedLower(), $b->normalizedLower());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideEqualityMatchesNuGetVersioning(): iterable
    {
        /** @var array<string, mixed> $oracle */
        $oracle = Fixtures::json('Versions/oracle.json');

        foreach ($oracle['equalPairs'] as $pair) {
            yield sprintf('%s == %s', $pair['left'], $pair['right']) => [$pair['left'], $pair['right']];
        }
    }
}
