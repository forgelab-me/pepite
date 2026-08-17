<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\FeedModel;

/**
 * Turns a feed slug from the URL into a feed row.
 *
 * Results are memoised per request: every V3 endpoint needs the feed, and a
 * restore hits several of them in a row.
 */
final class FeedResolver
{
    /**
     * @var array<string, array<string, mixed>|null>
     */
    private array $cache = [];

    public function __construct(private readonly FeedModel $feeds)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $slug): ?array
    {
        return $this->cache[$slug] ??= $this->feeds->findBySlug($slug);
    }
}
