<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('student');
            $table->boolean('locked')->default(false);
        });
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('enrollments', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['course_id', 'user_id']);
        });
        Schema::create('lti_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('client_id')->unique();
            $table->string('deployment_id');
            $table->text('login_url');
            $table->text('redirect_uri');
            $table->text('public_key');
        });
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lti_registration_id')->constrained()->restrictOnDelete();
            $table->uuid('resource_link_id')->unique();
            $table->string('title');
            $table->text('target_uri');
            $table->unsignedInteger('maximum');
            $table->boolean('published')->default(false);
        });
        Schema::create('grades', function (Blueprint $table) {
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 12, 4)->nullable();
            $table->timestampTz('reported_at', 6);
            $table->primary(['activity_id', 'user_id']);
        });
        Schema::create('lti_keys', function (Blueprint $table) {
            $table->uuid('kid')->primary();
            $table->text('encrypted_key');
            $table->text('modulus');
            $table->text('exponent');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('retired_at')->nullable();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'lti_keys', 'grades', 'activities', 'lti_registrations', 'enrollments', 'courses'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'locked']));
    }
};
