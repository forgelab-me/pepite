<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * `forgelab-me/ci4-trusted-publishing` 1.1.0 adds `Identity::$workflow` and a
 * matching `PublisherMatcher` column: a row can now pin not just the
 * repository and environment, but the specific pipeline file allowed to mint
 * — 'owner/repo/.github/workflows/release.yml' for GitHub, a stripped
 * `ci_config_ref_uri` for GitLab. Null keeps the old behaviour: any workflow
 * in the repository satisfies the row.
 */
class AddWorkflowToTrustedPublishers extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('trusted_publishers', [
            'workflow' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'environment',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('trusted_publishers', 'workflow');
    }
}
