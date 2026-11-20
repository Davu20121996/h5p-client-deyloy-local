<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
        foreach (['users', 'courses', 'lti_registrations'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete()->index());
        }
    }

    public function down(): void
    {
        foreach (['users', 'courses', 'lti_registrations'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('tenant_id'));
        }
        Schema::dropIfExists('tenants');
    }
};
