<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create a test user with MFA not enrolled
        User::create([
            'name' => 'Paul Test',
            'email' => 'test@test.com',
            'password' => Hash::make('password'),
            'roles' => ['member'],
            'has_mfa_enrolled' => false,
        ]);

        // Create a test admin user with MFA not enrolled
        User::create([
            'name' => 'Admin Test',
            'email' => 'admin@test.com',
            'password' => Hash::make('password'),
            'roles' => ['admin'],
            'has_mfa_enrolled' => false,
        ]);
        
        // Create a test user with MFA enrolled
        User::create([
            'name' => 'MFA Test',
            'email' => 'mfa@test.com',
            'password' => Hash::make('password'),
            'roles' => ['member'],
            'has_mfa_enrolled' => true,
        ]);
    }
}