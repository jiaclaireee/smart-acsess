<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@uplb.edu.ph'],
            [
                'first_name' => 'SMART',
                'middle_name' => null,
                'last_name' => 'ADMIN',
                'contact_no' => 'N/A',
                'office_department' => 'ICT Office',
                'college_course' => 'MIT',
                'password' => Hash::make('Admin@123!'),
                'role' => User::ROLE_ADMIN,
                'approval_status' => User::APPROVAL_APPROVED,
            ]
        );
    }
}
