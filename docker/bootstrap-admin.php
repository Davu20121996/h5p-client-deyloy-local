<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

require '/var/www/app/vendor/autoload.php';
$app = require '/var/www/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Existing administrators and passwords are never changed by a redeployment.
if (DB::table('users')->where('role', 'admin')->exists()) {
    echo "Administrator already exists; bootstrap skipped.\n";
    exit(0);
}

$data = [
    'email' => strtolower(trim((string) getenv('ADMIN_EMAIL'))),
    'name' => trim((string) getenv('ADMIN_NAME')),
    'password' => (string) getenv('ADMIN_PASSWORD'),
];
$validator = Validator::make($data, [
    'email' => 'required|email|max:255|unique:users,email',
    'name' => 'required|string|max:255',
    'password' => 'required|string|min:12|max:1024',
]);
if ($validator->fails()) {
    fwrite(STDERR, "Set a valid ADMIN_EMAIL, ADMIN_NAME and ADMIN_PASSWORD (12–1024 characters) in Dockhand for the first deployment.\n");
    exit(1);
}

DB::table('users')->insert([
    'email' => $data['email'],
    'name' => $data['name'],
    'password' => Hash::make($data['password']),
    'role' => 'admin',
    'created_at' => now(),
    'updated_at' => now(),
]);
echo "Initial administrator created.\n";
