<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ResetClientLearningDataSeeder extends Seeder
{
    public function run(): void
    {
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
