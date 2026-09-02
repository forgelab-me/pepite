<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class PackageModel extends Model
{
    protected $table         = 'packages';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'feed_id',
        'package_id',
        'package_id_lower',
        'total_downloads',
    ];

    /**
     * Lookup is always on the folded identifier: that is what identity means
     * here, and what the URLs carry.
     *
     * @return array<string, mixed>|null
     */
    public function findInFeed(int $feedId, string $packageId): ?array
    {
        return $this->where('feed_id', $feedId)
            ->where('package_id_lower', strtolower($packageId))
            ->first();
    }

    /**
     * Every package in a feed, unfiltered — for the admin console, which
     * unlike search() has no reason to hide unlisted or SemVer 2 content.
     *
     * @return list<array<string, mixed>>
     */
    public function forFeed(int $feedId): array
    {
        return $this->where('feed_id', $feedId)->orderBy('package_id_lower', 'ASC')->findAll();
    }

    /**
     * Every package a user owns, across every feed — the account area's
     * landing page. Joined the same way search()'s cross-feed branch is,
     * since both need a feed name/slug alongside each row; feed_visibility
     * rides along too, so the view can tell a private-feed row apart (its
     * public page 404s regardless of ownership) without a second query.
     *
     * @return list<array<string, mixed>>
     */
    public function ownedBy(int $userId): array
    {
        $packages = $this->db->prefixTable('packages');
        $owners   = $this->db->prefixTable('package_owners');
        $feeds    = $this->db->prefixTable('feeds');

        return $this->builder()
            ->select($packages . '.*, f.slug AS feed_slug, f.name AS feed_name, f.visibility AS feed_visibility')
            ->join($owners . ' o', 'o.package_id = ' . $packages . '.id')
            ->join($feeds . ' f', 'f.id = ' . $packages . '.feed_id')
            ->where('o.user_id', $userId)
            ->orderBy('f.slug', 'ASC')
            ->orderBy($packages . '.package_id_lower', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Search, as the SearchQueryService exposes it — and also what the web
     * search box runs, per feed or, with $feedId null, across every public
     * one at once (a private feed is reachable only from its own page with
     * an API key, never from a search that spans feeds it wasn't asked
     * about).
     *
     * A package qualifies when it has at least one version the caller is
     * allowed to see. That "at least one visible version" test is why this is
     * an EXISTS subquery rather than a join: a join would return a package
     * once per matching version and force a DISTINCT.
     *
     * EXISTS and LIKE are plain ANSI SQL, so this runs unchanged on SQLite and
     * MySQL. Full-text search is deliberately absent — it has no SQLite
     * equivalent (see PLAN.md §6.4).
     *
     * @return array{total: int, packages: list<array<string, mixed>>}
     */
    public function search(
        ?int $feedId,
        string $query = '',
        int $skip = 0,
        int $take = 20,
        bool $includePrerelease = true,
        bool $semVer2 = false,
        ?string $packageType = null,
        string $sort = 'downloads',
    ): array {
        $builder  = $this->builder();
        $packages = $this->db->prefixTable('packages');

        if ($feedId !== null) {
            $builder->where('feed_id', $feedId);
        } else {
            $builder->join($this->db->prefixTable('feeds') . ' f', 'f.id = ' . $packages . '.feed_id')
                ->where('f.visibility', 'public');
        }

        $builder->where($this->visibleVersionClause($includePrerelease, $semVer2, $packageType), null, false);

        foreach ($this->terms($query) as $term) {
            $builder->where($this->termClause($term), null, false);
        }

        $total = (clone $builder)->countAllResults(false);

        // Enough to render a result card (icon, blurb, tags, version,
        // prerelease badge) without a second query per row: the latest
        // listed version's own, whatever its prerelease/SemVer2 status — a
        // search result showing slightly ahead-of-what-restores metadata is
        // a display nuance, not a protocol concern the way
        // visibleVersionClause() above is.
        $builder->select($packages . '.*');

        if ($feedId === null) {
            $builder->select('f.slug AS feed_slug, f.name AS feed_name');
        }

        $builder->select($this->latestVersionSubquery('description'), false);
        $builder->select($this->latestVersionSubquery('tags'), false);
        $builder->select($this->latestVersionSubquery('icon_path'), false);
        $builder->select($this->latestVersionSubquery('icon_url'), false);
        $builder->select($this->latestVersionSubquery('version_normalized'), false);
        $builder->select($this->latestVersionSubquery('version_normalized_lower'), false);
        $builder->select($this->latestVersionSubquery('is_prerelease'), false);

        if ($sort === 'name') {
            $builder->orderBy('package_id_lower', 'ASC');
        } else {
            $builder->orderBy('total_downloads', 'DESC')->orderBy('package_id_lower', 'ASC');
        }

        $rows = $builder
            ->limit(max(0, $take), max(0, $skip))
            ->get()
            ->getResultArray();

        return ['total' => $total, 'packages' => $rows];
    }

    /**
     * Identifier autocompletion: prefix match, ordered by popularity.
     *
     * @return array{total: int, ids: list<string>}
     */
    public function autocompleteIds(
        int $feedId,
        string $query = '',
        int $skip = 0,
        int $take = 20,
        bool $includePrerelease = true,
        bool $semVer2 = false,
        ?string $packageType = null,
    ): array {
        $builder = $this->builder()->where('feed_id', $feedId);

        $builder->where($this->visibleVersionClause($includePrerelease, $semVer2, $packageType), null, false);

        if (trim($query) !== '') {
            $builder->like('package_id_lower', strtolower(trim($query)), 'after');
        }

        $total = (clone $builder)->countAllResults(false);

        $rows = $builder
            ->orderBy('total_downloads', 'DESC')
            ->orderBy('package_id_lower', 'ASC')
            ->limit(max(0, $take), max(0, $skip))
            ->get()
            ->getResultArray();

        return ['total' => $total, 'ids' => array_column($rows, 'package_id')];
    }

    /**
     * @return list<string>
     */
    private function terms(string $query): array
    {
        $trimmed = trim($query);

        if ($trimmed === '') {
            return [];
        }

        // NuGet's own syntax supports field prefixes (id:, tags:). Only the
        // free-text form is handled here; a field prefix degrades to a literal
        // term rather than being silently dropped.
        return array_values(array_filter(preg_split('/\s+/', $trimmed) ?: []));
    }

    private function termClause(string $term): string
    {
        $escaped  = $this->db->escapeLikeString(strtolower($term));
        $pattern  = "'%" . $escaped . "%'";
        $versions = $this->db->prefixTable('package_versions');
        $packages = $this->db->prefixTable('packages');

        return sprintf(
            '(LOWER(%s.package_id) LIKE %s'
            . ' OR EXISTS (SELECT 1 FROM %s v WHERE v.package_id = %s.id'
            . ' AND (LOWER(v.description) LIKE %s OR LOWER(v.tags) LIKE %s OR LOWER(v.title) LIKE %s)))',
            $packages,
            $pattern,
            $versions,
            $packages,
            $pattern,
            $pattern,
            $pattern,
        );
    }

    /**
     * A correlated scalar subquery pulling one column from the latest listed
     * version of the row it sits beside — the cheapest way to show a search
     * result's icon/description/tags without a second query per row.
     */
    private function latestVersionSubquery(string $column): string
    {
        $versions = $this->db->prefixTable('package_versions');
        $packages = $this->db->prefixTable('packages');

        return sprintf(
            '(SELECT v.%1$s FROM %2$s v WHERE v.package_id = %3$s.id AND v.is_listed = 1'
            . ' ORDER BY v.version_sort_key DESC LIMIT 1) AS latest_%1$s',
            $column,
            $versions,
            $packages,
        );
    }

    private function visibleVersionClause(bool $includePrerelease, bool $semVer2, ?string $packageType): string
    {
        $versions = $this->db->prefixTable('package_versions');
        $packages = $this->db->prefixTable('packages');

        $conditions = ['v.package_id = ' . $packages . '.id', 'v.is_listed = 1'];

        if (! $includePrerelease) {
            $conditions[] = 'v.is_prerelease = 0';
        }

        // A client that does not announce semVerLevel=2.0.0 must not be shown
        // SemVer 2 versions; it would not know how to parse them.
        if (! $semVer2) {
            $conditions[] = 'v.semver_level = 0';
        }

        if ($packageType !== null && trim($packageType) !== '') {
            $needle       = $this->db->escapeLikeString('"name":"' . strtolower(trim($packageType)) . '"');
            $conditions[] = "LOWER(v.package_types) LIKE '%" . $needle . "%'";
        }

        return sprintf('EXISTS (SELECT 1 FROM %s v WHERE %s)', $versions, implode(' AND ', $conditions));
    }
}
