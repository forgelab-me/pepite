<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The feed, package and ownership tables.
 *
 * Three invariants are enforced here rather than in application code, because
 * they are the ones that are expensive to retrofit onto a populated feed:
 *
 *   - identifiers are unique per feed on their folded form, so Foo.Bar and
 *     foo.bar cannot become two packages owned by two different people;
 *   - versions are unique per package on their folded normalized form, so
 *     1.0.0, 1.0.0+meta and 1.0.0-Beta/1.0.0-beta cannot collide;
 *   - ownership exists from the first push (see package_owners).
 */
final class CreateCoreTables extends Migration
{
    public function up(): void
    {
        $this->createFeeds();
        $this->createPackages();
        $this->createPackageVersions();
        $this->createPackageDependencies();
        $this->createPackageOwners();
        $this->createFeedApiKeyRules();

        $this->seedDefaultFeed();
    }

    public function down(): void
    {
        // Reverse order: children before parents.
        $this->forge->dropTable('feed_api_key_rules', true);
        $this->forge->dropTable('package_owners', true);
        $this->forge->dropTable('package_dependencies', true);
        $this->forge->dropTable('package_versions', true);
        $this->forge->dropTable('packages', true);
        $this->forge->dropTable('feeds', true);
    }

    private function createFeeds(): void
    {
        $this->forge->addField([
            'id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'slug' => ['type' => 'VARCHAR', 'constraint' => 64],
            'name' => ['type' => 'VARCHAR', 'constraint' => 128],

            'description' => ['type' => 'TEXT', 'null' => true],

            // 'public' or 'private'. A reading client of a private feed
            // authenticates with Basic auth whose password is an API key.
            'visibility' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'public'],

            // Whether a push may claim an identifier that does not exist yet.
            'allow_new_packages' => ['type' => 'BOOLEAN', 'default' => true],

            // JSON list of accepted packageType names; empty means "any".
            // This is what lets a push aimed at the wrong feed be refused.
            'allowed_package_types' => ['type' => 'TEXT', 'null' => true],

            'owner_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('feeds');
    }

    private function createPackages(): void
    {
        $this->forge->addField([
            'id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'feed_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],

            // As the author spelled it, for display.
            'package_id' => ['type' => 'VARCHAR', 'constraint' => 128],

            // Folded, for identity and for URLs.
            'package_id_lower' => ['type' => 'VARCHAR', 'constraint' => 128],

            'total_downloads' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['feed_id', 'package_id_lower']);
        $this->forge->addForeignKey('feed_id', 'feeds', 'id', '', 'CASCADE');
        $this->forge->createTable('packages');
    }

    private function createPackageVersions(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'package_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],

            // As written in the .nuspec, build metadata included.
            'version_original' => ['type' => 'VARCHAR', 'constraint' => 128],

            // Normalized: three segments unless the revision is non-zero, no
            // leading zeros, no build metadata. Keeps the author's casing.
            'version_normalized' => ['type' => 'VARCHAR', 'constraint' => 128],

            // The identity. Prerelease labels compare case-insensitively, so
            // uniqueness has to be on the folded form.
            'version_normalized_lower' => ['type' => 'VARCHAR', 'constraint' => 128],

            // Collation key whose byte order matches NuGet's comparison. Forced
            // to a binary collation on MySQL below.
            'version_sort_key' => ['type' => 'VARCHAR', 'constraint' => 255],

            'is_prerelease' => ['type' => 'BOOLEAN', 'default' => false],

            // Delisted versions stay downloadable and stay in the flat
            // container; they only disappear from search. Nothing is ever
            // deleted, because published versions are immutable.
            'is_listed' => ['type' => 'BOOLEAN', 'default' => true],

            // 0 for legacy, 2 for SemVer 2. Clients that do not announce
            // semVerLevel=2.0.0 must not be shown the latter.
            'semver_level' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],

            'title'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'description'   => ['type' => 'TEXT', 'null' => true],
            'summary'       => ['type' => 'TEXT', 'null' => true],
            'release_notes' => ['type' => 'TEXT', 'null' => true],
            'authors'       => ['type' => 'TEXT', 'null' => true],
            'owners'        => ['type' => 'TEXT', 'null' => true],
            'tags'          => ['type' => 'TEXT', 'null' => true],
            'copyright'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'language'      => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'project_url'   => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
            'icon_url'      => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
            'license_url'   => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
            'license_type'  => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'license_value' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],

            'require_license_acceptance' => ['type' => 'BOOLEAN', 'default' => false],
            'development_dependency'     => ['type' => 'BOOLEAN', 'default' => false],

            'min_client_version' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'repository_type'    => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'repository_url'     => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
            'repository_branch'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'repository_commit'  => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],

            // JSON list of {name, version}.
            'package_types' => ['type' => 'TEXT', 'null' => true],

            // Blob locations, relative to the storage root outside the web root.
            'nupkg_path' => ['type' => 'VARCHAR', 'constraint' => 512],

            // Symbol packages are stored but never served: see PLAN.md 4.4.
            'snupkg_path' => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
            'nuspec_path' => ['type' => 'VARCHAR', 'constraint' => 512],
            'icon_path'   => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
            'readme_path' => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],

            'size_bytes'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'sha512_base64' => ['type' => 'VARCHAR', 'constraint' => 128],
            'downloads'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'published_at'  => ['type' => 'DATETIME', 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['package_id', 'version_normalized_lower']);
        $this->forge->addKey(['package_id', 'version_sort_key']);
        $this->forge->addKey('is_listed');
        $this->forge->addForeignKey('package_id', 'packages', 'id', '', 'CASCADE');
        $this->forge->createTable('package_versions');

        $this->forceBinaryCollation();
    }

    private function createPackageDependencies(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'package_version_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],

            // Null means the group applies to every framework, which is also
            // how the legacy flat <dependencies> form is stored.
            'target_framework' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],

            'dependency_id' => ['type' => 'VARCHAR', 'constraint' => 128],

            // Already normalized: "[1.0.0, )", the spelling a registration
            // document has to carry.
            'version_range' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],

            'include_assets' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'exclude_assets' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('package_version_id');
        $this->forge->addForeignKey('package_version_id', 'package_versions', 'id', '', 'CASCADE');
        $this->forge->createTable('package_dependencies');
    }

    private function createPackageOwners(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'package_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'user_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'role'       => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'owner'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['package_id', 'user_id']);
        $this->forge->addForeignKey('package_id', 'packages', 'id', '', 'CASCADE');
        $this->forge->createTable('package_owners');
    }

    private function createFeedApiKeyRules(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],

            // Shield stores an access token as a row in auth_identities, and
            // its scopes in that row's `extra` column. The per-feed reach and
            // the identifier pattern have nowhere to live there, hence this.
            'identity_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'feed_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],

            // Glob over package identifiers, e.g. "Contoso.*". Null means any.
            'id_pattern' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],

            // False restricts the key to new versions of existing packages —
            // the shape a CI pipeline should be given.
            'can_create_package' => ['type' => 'BOOLEAN', 'default' => true],

            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('identity_id');
        $this->forge->addForeignKey('feed_id', 'feeds', 'id', '', 'CASCADE');
        $this->forge->createTable('feed_api_key_rules');
    }

    /**
     * The sort key encodes ordering in its bytes, so it must be compared byte
     * by byte. A Unicode collation may treat '.' as ignorable and interleave
     * punctuation with letters, which silently reorders versions.
     *
     * SQLite compares TEXT with BINARY by default and needs nothing.
     */
    private function forceBinaryCollation(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            return;
        }

        $table = $this->db->prefixTable('package_versions');

        $this->db->query(
            "ALTER TABLE {$table} MODIFY version_sort_key VARCHAR(255) "
            . 'CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
        );
    }

    /**
     * A server is useless without at least one feed. The web installer renames
     * this one rather than creating another.
     */
    private function seedDefaultFeed(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('feeds')->insert([
            'slug'               => 'default',
            'name'               => 'Default feed',
            'description'        => 'Created on install.',
            'visibility'         => 'public',
            'allow_new_packages' => true,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }
}
