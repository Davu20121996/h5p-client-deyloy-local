<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $teacher = $this->account('teacher', 'Nguyễn Minh Anh');
            $student = $this->account('student', 'Trần Gia Huy');
            $tool = DB::table('lti_registrations')->where('client_id', 'demo-h5p')->first();
            $toolId = $tool?->id ?? DB::table('lti_registrations')->insertGetId([
                'name' => 'H5P Demo',
                'client_id' => 'demo-h5p',
                'deployment_id' => 'demo-deployment',
                'login_url' => 'https://demo.invalid/login',
                'redirect_uri' => 'https://demo.invalid/launch',
                'public_key' => '',
            ]);

            foreach ([
                ['Khoa học qua tương tác', 'Khám phá hệ Mặt Trời', 86],
                ['Nhập môn STEM Robotics', 'Nhận biết cảm biến và động cơ', 92],
                ['Kỹ năng số', 'Bảo vệ thông tin cá nhân', 78],
            ] as [$title, $activity, $score]) {
                $course = DB::table('courses')->where('teacher_id', $teacher)->where('title', $title)->first();
                $courseId = $course?->id ?? DB::table('courses')->insertGetId([
                    'teacher_id' => $teacher, 'title' => $title,
                    'description' => 'Khóa học mẫu để xem hoạt động H5P và sổ điểm.',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('enrollments')->insertOrIgnore(['course_id' => $courseId, 'user_id' => $student]);
                $item = DB::table('activities')->where('course_id', $courseId)
                    ->where('lti_registration_id', $toolId)->where('title', $activity)->first();
                $itemId = $item?->id ?? DB::table('activities')->insertGetId([
                    'course_id' => $courseId, 'lti_registration_id' => $toolId,
                    'resource_link_id' => (string) Str::uuid(), 'title' => $activity,
                    'target_uri' => 'https://demo.invalid/activity', 'maximum' => 100, 'published' => false,
                ]);
                DB::table('grades')->insertOrIgnore([
                    'activity_id' => $itemId, 'user_id' => $student, 'score' => $score, 'reported_at' => now(),
                ]);
            }
        });
    }

    private function account(string $role, string $name): int
    {
        $email = "demo.{$role}@example.com";
        $user = DB::table('users')->where('email', $email)->first();
        if ($user) {
            return $user->id;
        }

        return DB::table('users')->insertGetId([
            'email' => $email, 'name' => $name, 'role' => $role,
            'password' => Hash::make(Str::random(40)), 'locked' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
