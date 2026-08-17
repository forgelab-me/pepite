<?php

declare(strict_types=1);

namespace App\Libraries\Version;

use App\Exceptions\InvalidVersionException;

/**
 * A NuGet dependency version range.
 *
 * The server never resolves dependencies — that is the client's job — but it
 * does have to echo ranges back in the registration documents, and it has to
 * echo them in NuGet's normalized form. A bare "1.0" in a .nuspec must come
 * back out as "[1.0.0, )", spaces and all.
 *
 * Interval notation:
 *   1.0            [1.0.0, )        at least 1.0.0
 *   [1.0]          [1.0.0, 1.0.0]   exactly 1.0.0
 *   (1.0,)         (1.0.0, )        above 1.0.0
 *   [,2.0]         (, 2.0.0]        up to and including 2.0.0
 *   [1.0,2.0)      [1.0.0, 2.0.0)   at least 1.0.0 and below 2.0.0
 *
 * A range must carry at least one bound: "(,)" is rejected, as is an inverted
 * range like "[2.0,1.0]".
 */
final class VersionRange
{
    private function __construct(
        public readonly ?NuGetVersion $minVersion,
        public readonly bool $isMinInclusive,
        public readonly ?NuGetVersion $maxVersion,
        public readonly bool $isMaxInclusive,
        public readonly ?string $floatToken,
        public readonly string $original,
    ) {
    }

    public static function parse(string $value): self
    {
        $range = self::tryParse($value);

        if ($range === null) {
            throw InvalidVersionException::forRange($value);
        }

        return $range;
    }

    public static function tryParse(string $value): ?self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $opens  = $trimmed[0];
        $closes = $trimmed[strlen($trimmed) - 1];

        if ($opens !== '[' && $opens !== '(') {
            return self::fromSingleVersion($trimmed);
        }

        if ($closes !== ']' && $closes !== ')') {
            return null;
        }

        $inner = trim(substr($trimmed, 1, -1));
        $parts = explode(',', $inner);

        if (count($parts) > 2) {
            return null;
        }

        // No comma: the exact-version form, which only "[x]" expresses.
        if (count($parts) === 1) {
            if ($opens !== '[' || $closes !== ']') {
                return null;
            }

            $exact = self::parseBound(trim($parts[0]));

            if ($exact === null) {
                return null;
            }

            return new self($exact['version'], true, $exact['version'], true, $exact['float'], $trimmed);
        }

        $left  = trim($parts[0]);
        $right = trim($parts[1]);

        // At least one bound is required: "(,)" and "[,]" are not ranges.
        if ($left === '' && $right === '') {
            return null;
        }

        $lower = null;
        $float = null;

        if ($left !== '') {
            $bound = self::parseBound($left);

            if ($bound === null) {
                return null;
            }

            $lower = $bound['version'];
            $float = $bound['float'];
        }

        $upper = null;

        if ($right !== '') {
            // Floating is a lower-bound notion; an upper bound must be exact.
            if (str_contains($right, '*')) {
                return null;
            }

            $upper = NuGetVersion::tryParse($right);

            if ($upper === null) {
                return null;
            }
        }

        if ($lower !== null && $upper !== null && $lower->compareTo($upper) > 0) {
            return null;
        }

        return new self(
            $lower,
            $left !== '' && $opens === '[',
            $upper,
            $right !== '' && $closes === ']',
            $float,
            $trimmed,
        );
    }

    public function isFloating(): bool
    {
        return $this->floatToken !== null;
    }

    public function hasLowerBound(): bool
    {
        return $this->minVersion !== null;
    }

    public function hasUpperBound(): bool
    {
        return $this->maxVersion !== null;
    }

    /**
     * The form NuGet writes into registration documents, down to the space
     * after the comma.
     */
    public function normalized(): string
    {
        $lower = $this->floatToken ?? $this->minVersion?->normalized() ?? '';
        $upper = $this->maxVersion?->normalized() ?? '';

        return sprintf(
            '%s%s, %s%s',
            $this->isMinInclusive ? '[' : '(',
            $lower,
            $upper,
            $this->isMaxInclusive ? ']' : ')',
        );
    }

    private static function fromSingleVersion(string $value): ?self
    {
        $bound = self::parseBound($value);

        if ($bound === null) {
            return null;
        }

        return new self($bound['version'], true, null, false, $bound['float'], $value);
    }

    /**
     * Parses a lower bound, which may float.
     *
     * A floating bound keeps its original token for rendering, and resolves to
     * a concrete minimum by substituting the star with a zero: "1.0.*" is at
     * least 1.0.0, and "1.0.0-beta.*" at least 1.0.0-beta.0.
     *
     * @return array{version: NuGetVersion, float: string|null}|null
     */
    private static function parseBound(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        if (! str_contains($token, '*')) {
            $version = NuGetVersion::tryParse($token);

            return $version === null ? null : ['version' => $version, 'float' => null];
        }

        // Exactly one star, and only in final position.
        if (substr_count($token, '*') !== 1 || ! str_ends_with($token, '*')) {
            return null;
        }

        $version = NuGetVersion::tryParse(substr($token, 0, -1) . '0');

        return $version === null ? null : ['version' => $version, 'float' => $token];
    }
}
