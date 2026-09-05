<?php

namespace Database\Seeders;

use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;

class MemberProfileSeeder extends Seeder
{
    public function run(): void
    {
        $hq = Organization::firstOrCreate(
            ['identifier' => 'IDX-HQ'],
            ['name' => 'HQ', 'type' => 'headquarters'],
        );

        $branch = Organization::firstOrCreate(
            ['identifier' => 'IDX-A'],
            ['name' => 'Branch A', 'type' => 'branch', 'parent_id' => $hq->id],
        );

        $user = User::where('email', 'test@test.com')->first();
        if ($user === null) {
            return;
        }

        Member::firstOrCreate(
            ['user_id' => $user->id],
            [
                'membership_id' => 'S1-M-000100',
                'branch_id' => $branch->id,
                'registration_channel' => 'web',
                'first_name' => 'Paul',
                'last_name' => 'Test',
                'email' => $user->email,
                'phone' => '08030000000',
                'photo_path' => '/photos/paul-test.jpg',
                'membership_status' => 'active',
                'lifecycle_status' => 'active',
                'consent_data_processing' => true,
            ],
        );
    }
}
