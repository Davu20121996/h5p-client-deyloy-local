<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ResetClientLearningDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('ResetClientLearningDataSeeder runs only in local or testing environments.');
        }

        DB::transaction(function (): void {
            $accounts = DB::table('users')->whereIn('role', ['teacher', 'student']);
            DB::table('sessions')->whereIn('user_id', (clone $accounts)->select('id'))->delete();

            // Foreign keys cascade enrollments, activities, grades, and classroom assignments.
            DB::table('courses')->delete();
            DB::table('classrooms')->delete();
            $accounts->delete();
            DB::table('audit_logs')->delete();
        });
    }
}
