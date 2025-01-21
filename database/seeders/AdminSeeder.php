<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [];
        $admins = [];

        // Tạo hai role: admin và member
        $adminRoleId = (string) Str::uuid();
        $memberRoleId = (string) Str::uuid();

        $roles[] = [
            'id' => $adminRoleId,
            'name' => 'admin',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];
        $roles[] = [
            'id' => $memberRoleId,
            'name' => 'member',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];

        // Tạo 10 admins, mỗi role có 5 admins
        for ($i = 1; $i <= 5; $i++) {
            // Admins với role admin
            $admins[] = [
                'id' => (string) Str::uuid(),
                'name' => "Admin $i",
                'email' => "admin$i@example.com",
                'role_id' => $adminRoleId,
                'password' => Hash::make('Password123@'),
                'email_verified_at' => Carbon::now(),
                'remember_token' => Str::random(10),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];

            // Admins với role member
            $admins[] = [
                'id' => (string) Str::uuid(),
                'name' => "Member $i",
                'email' => "member$i@example.com",
                'role_id' => $memberRoleId,
                'password' => Hash::make('Password123@'),
                'email_verified_at' => Carbon::now(),
                'remember_token' => Str::random(10),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
        }

        // Insert vào database
        DB::table('roles')->insert($roles);
        DB::table('admins')->insert($admins);
    }
}
