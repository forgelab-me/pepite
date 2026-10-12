<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A trace of the actions that cannot be undone or that change who may do
 * what: deleting a package, version or feed, delisting, issuing and revoking
 * keys, adding and removing admins and trusted publishers.
 *
 * Append-only by design, and deliberately without foreign keys: the point of
 * a log is to outlive what it describes, so a deleted user or feed must not
 * take its history with it. The actor's name is therefore copied in as text
 * (actor_label) rather than only referenced.
 */
class CreateAuditLogTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'created_at' => ['type' => 'DATETIME'],

            // Null for the CLI and for anything done with no account behind it.
            'actor_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'actor_label'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],

            // How it was done: web (console session), api (NuGet key), cli, system.
            'via' => ['type' => 'VARCHAR', 'constraint' => 8],

            // Dotted verb, e.g. package.purge, key.revoke.
            'action' => ['type' => 'VARCHAR', 'constraint' => 64],

            // What it was done to, as a person would say it: "Contoso.Utils 1.0.0 (feed default)".
            'target' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],

            // JSON, for the few actions that carry more than a target.
            'detail' => ['type' => 'TEXT', 'null' => true],

            'ip' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('created_at');
        $this->forge->addKey('action');
        $this->forge->createTable('audit_log');
    }

    public function down(): void
    {
        $this->forge->dropTable('audit_log', true);
    }
}
