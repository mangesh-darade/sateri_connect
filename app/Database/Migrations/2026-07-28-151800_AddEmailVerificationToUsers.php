<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddEmailVerificationToUsers extends Migration
{
    public function up(): void
    {
        $fields = [];

        if (! $this->db->fieldExists('email_verification_token', 'users')) {
            $fields['email_verification_token'] = [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'password',
            ];
        }

        if (! $this->db->fieldExists('email_verification_sent_at', 'users')) {
            $fields['email_verification_sent_at'] = [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'email_verification_token',
            ];
        }

        if (! $this->db->fieldExists('email_verified_at', 'users')) {
            $fields['email_verified_at'] = [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'email_verification_sent_at',
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn('users', $fields);
        }

        $this->db->query('UPDATE users SET email_verified_at = COALESCE(email_verified_at, created_at, NOW())');

        try {
            $this->db->query('ALTER TABLE users ADD UNIQUE KEY users_email_verification_token_unique (email_verification_token)');
        } catch (\Throwable $e) {
            // Index may already exist on upgraded environments.
        }

        try {
            $this->db->query('ALTER TABLE users ADD KEY users_email_verified_at_index (email_verified_at)');
        } catch (\Throwable $e) {
            // Index may already exist on upgraded environments.
        }
    }

    public function down(): void
    {
        try {
            $this->db->query('ALTER TABLE users DROP INDEX users_email_verification_token_unique');
        } catch (\Throwable $e) {
        }

        try {
            $this->db->query('ALTER TABLE users DROP INDEX users_email_verified_at_index');
        } catch (\Throwable $e) {
        }

        $drop = [];
        foreach (['email_verification_token', 'email_verification_sent_at', 'email_verified_at'] as $field) {
            if ($this->db->fieldExists($field, 'users')) {
                $drop[] = $field;
            }
        }

        if ($drop !== []) {
            $this->forge->dropColumn('users', $drop);
        }
    }
}
