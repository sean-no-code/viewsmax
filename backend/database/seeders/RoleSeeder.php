<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'admin',
                'display_name' => 'Administrator',
                'description' => 'Full system access and management capabilities',
            ],
            [
                'name' => 'customer',
                'display_name' => 'Customer',
                'description' => 'Standard user with plan-based access',
            ],
            [
                'name' => 'promotional_customer',
                'display_name' => 'Promotional customer',
                'description' => 'Free access for a fixed window (or unlimited) with no card on file',
            ],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['name' => $role['name']],
                $role
            );
        }
    }
}
