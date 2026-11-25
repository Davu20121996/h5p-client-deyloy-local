<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });
        Schema::create('classroom_members', function (Blueprint $table) {
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['classroom_id', 'user_id']);
        });
        Schema::create('course_classrooms', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->primary(['course_id', 'classroom_id']);
        });
        Schema::create('course_teachers', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['course_id', 'user_id']);
        });
        Schema::table('activities', function (Blueprint $table) {
            $table->text('instructions')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampTz('opens_at')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('closes_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->string('grade_policy')->default('latest');
            $table->json('hub_custom')->nullable();
        });
        Schema::table('lti_registrations', function (Blueprint $table) {
            $table->text('deep_link_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lti_registrations', fn (Blueprint $table) => $table->dropColumn('deep_link_url'));
        Schema::table('activities', fn (Blueprint $table) => $table->dropColumn([
            'instructions', 'sort_order', 'opens_at', 'due_at', 'closes_at', 'archived_at', 'grade_policy', 'hub_custom',
        ]));
        foreach (['course_teachers', 'course_classrooms', 'classroom_members', 'classrooms'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
