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
     * Search, as the SearchQueryService exposes it.
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

        foreach ($this->terms($query) as $term) {
            $builder->where($this->termClause($term), null, false);
        }

        $total = (clone $builder)->countAllResults(false);

        $rows = $builder
            ->orderBy('total_downloads', 'DESC')
            ->orderBy('package_id_lower', 'ASC')
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
