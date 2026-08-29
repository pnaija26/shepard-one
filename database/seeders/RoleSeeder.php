<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Story 1.6: built-in roles (idempotent — safe to re-run).
 *
 * `super_admin` is the break-glass tier protected by AC4. The others are
 * starting points that security administrators can extend with scoped
 * permissions through the role management API.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $builtins = [
            ['name' => 'super_admin', 'description' => 'Church-wide break-glass administrator (protected tier).', 'is_super_admin' => true],
            ['name' => 'admin', 'description' => 'HQ administrator with church-wide operational access.', 'is_super_admin' => false],
            ['name' => 'branch_admin', 'description' => 'Branch-scoped administrator; grants limited to their branch subtree.', 'is_super_admin' => false],
            ['name' => 'member', 'description' => 'Standard member with read-only self-service access.', 'is_super_admin' => false],
        ];

        foreach ($builtins as $attrs) {
            Role::firstOrCreate(['name' => $attrs['name']], [
                'description' => $attrs['description'],
                'is_super_admin' => $attrs['is_super_admin'],
                'is_system' => true,
            ]);
        }
    }
}
