<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('roles')->upsert([
            ['code' => 'admin', 'name' => 'Admin hệ thống'],
            ['code' => 'tenant_admin', 'name' => 'Quản lý tổ chức'],
            ['code' => 'teacher', 'name' => 'Giáo viên'],
            ['code' => 'student', 'name' => 'Học viên'],
        ], ['code'], ['name']);
    }
}
