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
