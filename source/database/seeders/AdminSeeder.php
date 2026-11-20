<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('users')->where('role', 'admin')->exists()) {
            return;
        }

        $status = $this->command->call('lms:create-admin', [
            'email' => env('ADMIN_EMAIL', 'admin@example.com'),
            '--generate' => true,
        ]);

        if ($status !== 0) {
            throw new \RuntimeException('Không tạo được tài khoản quản trị ban đầu.');
        }
    }
}
