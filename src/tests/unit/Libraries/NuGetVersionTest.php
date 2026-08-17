<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use App\Exceptions\InvalidVersionException;
use App\Libraries\Version\NuGetVersion;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class NuGetVersionTest extends CIUnitTestCase
{
    /**
     * Ascending reference ordering. Every ordering assertion below is derived
     * from this single list, so there is one place to read the intent.
     *
     * @var list<string>
     */
    private const ORDERED = [
        '0.9.0',
        '1.0.0-alpha',
        '1.0.0-alpha.1',
        '1.0.0-alpha.beta',
        '1.0.0-beta',
        '1.0.0-beta.2',
        '1.0.0-beta.11',
        '1.0.0-rc.1',
        '1.0.0',
        '1.0.0.1',
        '1.0.1',
        '1.1.0',
        '2.0.0',
    ];

    #[DataProvider('provideNormalization')]
    public function testNormalization(string $input, string $expected): void
    {
        $this->assertSame($expected, NuGetVersion::parse($input)->normalized());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideNormalization(): iterable
    {
        yield 'single segment' => ['1', '1.0.0'];

        yield 'two segments' => ['1.2', '1.2.0'];

        yield 'three segments' => ['1.2.3', '1.2.3'];

        yield 'zero revision drops' => ['1.2.3.0', '1.2.3'];

        yield 'revision kept' => ['1.2.3.4', '1.2.3.4'];

        yield 'leading zeros' => ['01.02.03', '1.2.3'];

        yield 'all zeros' => ['0.0.0', '0.0.0'];

        yield 'metadata dropped' => ['1.0.0+build.5', '1.0.0'];

        yield 'prerelease kept' => ['1.0.0-beta.2', '1.0.0-beta.2'];

        yield 'prerelease case' => ['1.0.0-Beta', '1.0.0-Beta'];

        yield 'both' => ['1.0.0-beta.2+build.5', '1.0.0-beta.2'];

        yield 'surrounding spaces' => ['  1.0.0  ', '1.0.0'];
    }

    #[DataProvider('provideRejectsInvalidVersions')]
    public function testRejectsInvalidVersions(string $input): void
    {
        $this->assertNull(NuGetVersion::tryParse($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectsInvalidVersions(): iterable
    {
        yield 'empty' => [''];

        yield 'blank' => ['   '];

        yield 'letters' => ['abc'];

        yield 'five segments' => ['1.2.3.4.5'];

        yield 'empty segment' => ['1..0'];

        yield 'trailing dot' => ['1.0.'];

        yield 'dangling hyphen' => ['1.0.0-'];

        yield 'dangling plus' => ['1.0.0+'];

        yield 'empty label' => ['1.0.0-alpha..1'];

        yield 'illegal label char' => ['1.0.0-alpha_1'];

        yield 'leading v' => ['v1.0.0'];

        yield 'negative' => ['-1.0.0'];
    }

    public function testRejectsSegmentsAboveInt32(): void
    {
        $this->expectException(InvalidVersionException::class);

        NuGetVersion::parse('2147483648.0.0');
    }

    public function testAcceptsTheLargestSegment(): void
    {
        $this->assertSame('2147483647.0.0', NuGetVersion::parse('2147483647')->normalized());
    }

    public function testParseThrowsWhereTryParseReturnsNull(): void
    {
        $this->expectException(InvalidVersionException::class);

        NuGetVersion::parse('not a version');
    }

    public function testBuildMetadataIsKeptForDisplayOnly(): void
    {
        $version = NuGetVersion::parse('1.0.0-beta.2+build.5');

        $this->assertSame('build.5', $version->metadata);
        $this->assertSame('1.0.0-beta.2', $version->normalized());
        $this->assertSame('1.0.0-beta.2+build.5', $version->full());
        $this->assertSame('1.0.0-beta.2+build.5', $version->original);
    }

    public function testMetadataDoesNotAffectIdentity(): void
    {
        $bare  = NuGetVersion::parse('1.0.0');
        $noted = NuGetVersion::parse('1.0.0+abc');

        $this->assertTrue($bare->equals($noted));
        $this->assertSame($bare->normalizedLower(), $noted->normalizedLower());
        $this->assertSame($bare->sortKey(), $noted->sortKey());
    }

    public function testPrereleaseComparisonIgnoresCase(): void
    {
        $upper = NuGetVersion::parse('1.0.0-Alpha');
        $lower = NuGetVersion::parse('1.0.0-alpha');

        $this->assertTrue($upper->equals($lower));
        $this->assertSame($upper->sortKey(), $lower->sortKey());
        // ...but display keeps what the author wrote.
        $this->assertSame('1.0.0-Alpha', $upper->normalized());
    }

    #[DataProvider('provideSemVer2Detection')]
    public function testSemVer2Detection(string $input, bool $expected): void
    {
        $this->assertSame($expected, NuGetVersion::parse($input)->isSemVer2());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideSemVer2Detection(): iterable
    {
        yield 'plain' => ['1.0.0', false];

        yield 'single label' => ['1.0.0-beta', false];

        yield 'four segments' => ['1.2.3.4', false];

        yield 'dotted labels' => ['1.0.0-beta.1', true];

        yield 'metadata' => ['1.0.0+sha.abc', true];

        yield 'dotted and metadata' => ['1.0.0-beta.1+sha', true];

        yield 'four plus one label' => ['1.2.3.4-beta', false];
    }

    public function testOrdering(): void
    {
        $shuffled = self::ORDERED;
        shuffle($shuffled);

        $sorted = array_map(
            static fn (NuGetVersion $v): string => $v->original,
            NuGetVersion::sort(array_map(NuGetVersion::parse(...), $shuffled)),
        );

        $this->assertSame(self::ORDERED, $sorted);
    }

    public function testEachPairInTheReferenceListComparesStrictly(): void
    {
        $versions = array_map(NuGetVersion::parse(...), self::ORDERED);

        for ($i = 0; $i < count($versions) - 1; $i++) {
            $lower  = $versions[$i];
            $higher = $versions[$i + 1];

            $this->assertSame(
                -1,
                $lower->compareTo($higher) <=> 0,
                sprintf('%s should sort before %s', $lower->original, $higher->original),
            );
            $this->assertSame(
                1,
                $higher->compareTo($lower) <=> 0,
                sprintf('%s should sort after %s', $higher->original, $lower->original),
            );
        }
    }

    /**
     * The whole point of the sort key: the database must reach the same order
     * as compareTo() using nothing but a byte comparison.
     */
    public function testSortKeyByteOrderMatchesComparison(): void
    {
        $keys = array_map(
            static fn (string $v): string => NuGetVersion::parse($v)->sortKey(),
            self::ORDERED,
        );

        $sorted = $keys;
        sort($sorted, SORT_STRING);

        $this->assertSame($keys, $sorted);
    }

    public function testNumericPrereleaseLabelsCompareNumericallyNotAlphabetically(): void
    {
        $two    = NuGetVersion::parse('1.0.0-rc.2');
        $eleven = NuGetVersion::parse('1.0.0-rc.11');

        $this->assertLessThan(0, $two->compareTo($eleven));
        $this->assertLessThan(0, strcmp($two->sortKey(), $eleven->sortKey()));
    }

    public function testNumericLabelsSortBeforeAlphanumericOnes(): void
    {
        $numeric = NuGetVersion::parse('1.0.0-1');
        $alpha   = NuGetVersion::parse('1.0.0-alpha');

        $this->assertLessThan(0, $numeric->compareTo($alpha));
        $this->assertLessThan(0, strcmp($numeric->sortKey(), $alpha->sortKey()));
    }

    /**
     * NuGet reads numeric labels with int.TryParse. A label above int32 fails
     * that parse and is therefore compared as text — which puts it *after* a
     * genuinely numeric label rather than above it.
     */
    public function testOversizedNumericLabelIsComparedAsText(): void
    {
        $small = NuGetVersion::parse('1.0.0-2');
        $huge  = NuGetVersion::parse('1.0.0-99999999999');

        $this->assertLessThan(0, $small->compareTo($huge));
        $this->assertLessThan(0, strcmp($small->sortKey(), $huge->sortKey()));
    }

    public function testShorterLabelListSortsFirst(): void
    {
        $short = NuGetVersion::parse('1.0.0-alpha');
        $long  = NuGetVersion::parse('1.0.0-alpha.1');

        $this->assertLessThan(0, $short->compareTo($long));
        $this->assertLessThan(0, strcmp($short->sortKey(), $long->sortKey()));
    }

    public function testNormalizedLowerIsWhatUrlsUse(): void
    {
        $version = NuGetVersion::parse('1.2.3.0-Beta.1+Sha');

        $this->assertSame('1.2.3-beta.1', $version->normalizedLower());
    }
}
