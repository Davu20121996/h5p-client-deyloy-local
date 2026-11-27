<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

Artisan::command('lms:create-admin {email} {--generate}', function () {
    $generated = $this->option('generate');
    $data = [
        'email' => strtolower($this->argument('email')),
        'name' => $generated ? 'Quản trị hệ thống' : $this->ask('Tên quản trị viên'),
        'password' => $generated ? Str::random(24) : $this->secret('Mật khẩu'),
    ];
    $validator = Validator::make($data, [
        'email' => 'required|email|max:255|unique:users', 'name' => 'required|string|max:255',
        'password' => 'required|string|min:12|max:1024',
    ]);
    if ($validator->fails()) {
        $this->error($validator->errors()->first());

        return 1;
    }
    $password = $data['password'];
    $data['password'] = Hash::make($password);
    DB::table('users')->insert($data + ['role' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
    $this->info('Đã tạo quản trị viên.');
    if ($generated) {
        $this->line('Email: '.$data['email']);
        $this->line('Mật khẩu ban đầu: '.$password);
    }

    return 0;
});

Artisan::command('lms:admin-exists', function () {
    $this->line(DB::table('users')->where('role', 'admin')->exists() ? 'yes' : 'no');
});

Artisan::command('lms:reset-password {email}', function () {
    $email = strtolower(trim($this->argument('email')));
    $user = DB::table('users')->where('email', $email)->first(['id', 'role', 'tenant_id', 'locked']);
    if (! $user) {
        $this->error('Không tìm thấy tài khoản theo email này trong Client.');

        return 1;
    }
    if (! $user->tenant_id || ! in_array($user->role, ['tenant_admin', 'teacher', 'student'], true)) {
        $this->error('Tài khoản này không có quyền đăng nhập Client. Để đăng nhập với quyền quản trị tổ chức, cấp Tenant Admin từ Hub.');

        return 1;
    }
    if (! $this->input->isInteractive()) {
        $this->error('Hãy chạy lệnh trong terminal tương tác để nhập mật khẩu ẩn.');

        return 1;
    }

    $password = $this->secret('Mật khẩu mới', false);
    $confirmation = $this->secret('Nhập lại mật khẩu mới', false);
    $validator = Validator::make(['password' => $password, 'password_confirmation' => $confirmation], [
        'password' => 'required|string|min:12|max:1024|confirmed',
    ]);
    if ($validator->fails()) {
        $this->error('Mật khẩu cần từ 12 đến 1024 ký tự và hai lần nhập phải trùng nhau.');

        return 1;
    }

    $hash = Hash::make($password);
    $updated = DB::transaction(function () use ($user, $email, $hash) {
        $updated = DB::table('users')->where('id', $user->id)->where('email', $email)
            ->where('role', $user->role)->where('tenant_id', $user->tenant_id)
            ->update(['password' => $hash, 'remember_token' => null, 'updated_at' => now()]);
        if ($updated) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        return $updated;
    });
    if (! $updated) {
        $this->error('Thông tin tài khoản đã thay đổi. Hãy kiểm tra email rồi chạy lại lệnh.');

        return 1;
    }

    $this->info('Đã đặt lại mật khẩu và thu hồi phiên đăng nhập của '.$email.'.');
    if ($user->locked) {
        $this->warn('Tài khoản hiện đang bị khóa.');
    }

    return 0;
})->purpose('Reset the password for an existing Client account');

Artisan::command('lti:rotate-key {--if-missing}', function () {
    if ($this->option('if-missing') && DB::table('lti_keys')->whereNull('retired_at')->exists()) {
        return 0;
    }

    $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if (! $key || ! openssl_pkey_export($key, $pem)) {
        $this->error('Không tạo được khóa RSA.');

        return 1;
    }
    $rsa = openssl_pkey_get_details($key)['rsa'];
    $encode = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    Cache::lock('lti:key-rotation', 30)->block(5, function () use ($pem, $rsa, $encode) {
        DB::transaction(function () use ($pem, $rsa, $encode) {
            DB::table('lti_keys')->whereNull('retired_at')->update(['retired_at' => now()]);
            DB::table('lti_keys')->insert([
                'kid' => (string) Str::uuid(), 'encrypted_key' => Crypt::encryptString($pem),
                'modulus' => $encode($rsa['n']), 'exponent' => $encode($rsa['e']),
            ]);
        });
    });
    $this->info('Đã tạo khóa ký mới.');

    return 0;
});
