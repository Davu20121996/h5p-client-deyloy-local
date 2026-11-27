<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = DB::table('users')->where('role', 'admin')->orderBy('id')->value('email');
        if ($email !== null) {
            $this->command->info('Tài khoản Admin hệ thống đã tồn tại: '.$email);

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
