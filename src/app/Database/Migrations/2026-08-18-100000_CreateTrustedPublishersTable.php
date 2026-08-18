<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Trusted Publishing: a feed can name a GitHub Actions workflow that is
 * allowed to exchange its OIDC identity token for a scoped, short-lived
 * API key at push time, instead of a long-lived secret sitting in the
 * repo's settings. See App\Controllers\Api\PublishToken.
 *
 * This table is the trust policy only — it never stores a credential
 * itself. The identifier pattern and can_create_package columns mirror
 * feed_api_key_rules exactly, because a minted token is authorized the
 * same way a manually issued one is: by writing a feed_api_key_rules row
 * for it. Trusted Publishing is a way of obtaining that row's identity,
 * not a second authorization mechanism.
 */
class CreateTrustedPublishersTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'feed_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'user_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'provider' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'github'],

            // owner/name, e.g. "forgelab-me/pepite".
            'repository' => ['type' => 'VARCHAR', 'constraint' => 140],

            // The numeric GitHub account/org id — not the owner name, which
            // can be renamed or freed and reclaimed by someone else. This is
            // what a claim is actually matched against.
            'repository_owner_id' => ['type' => 'VARCHAR', 'constraint' => 32],

            // A GitHub Actions "environment" the job must have run in. When
            // set, this is required, not a hint — pairing it with a GitHub
            // *protected* environment is what puts a human approval between
            // a push to the repo and a publish to the feed.
            'environment' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],

            // Same meaning as feed_api_key_rules.id_pattern.
            'id_pattern' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],

            // Defaults closed, unlike feed_api_key_rules: a manually issued
            // key is a deliberate per-request choice, but this is the shape
            // every future push from this workflow gets without anyone
            // looking again, so the safer default carries more weight here.
            'can_create_package' => ['type' => 'BOOLEAN', 'default' => false],

            'last_used_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('feed_id');
        $this->forge->addForeignKey('feed_id', 'feeds', 'id', '', 'CASCADE');
        $this->forge->createTable('trusted_publishers');
    }

    public function down(): void
    {
        $this->forge->dropTable('trusted_publishers', true);
    }
}
