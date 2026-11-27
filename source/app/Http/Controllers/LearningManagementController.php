<?php

namespace App\Http\Controllers;

use App\Services\ActivityAssignments;
use App\Services\Gradebook;
use App\Services\LearningAccess;
use App\Services\ToolDiagnostics;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LearningManagementController extends Controller
{
    public function classrooms()
    {
        $classrooms = LearningAccess::classrooms()->orderBy('name')->get();
        $tenant = Auth::user()->tenant_id;
        $tenantManager = Auth::user()->role === 'tenant_admin';

        return view('classrooms', [
            'classrooms' => $classrooms,
            'tenantManager' => $tenantManager,
            'teachers' => $tenantManager ? DB::table('users')->where('tenant_id', $tenant)->where('role', 'teacher')->orderBy('name')->get(['id', 'name', 'locked'])->keyBy('id') : collect(),
            'students' => $tenantManager ? DB::table('users')->where('tenant_id', $tenant)->where('role', 'student')->orderBy('name')->get(['id', 'name', 'email', 'username', 'locked']) : collect(),
            'members' => DB::table('classroom_members')->join('users', 'users.id', '=', 'classroom_members.user_id')
                ->whereIn('classroom_id', $classrooms->pluck('id'))->where('users.role', 'student')->where('users.tenant_id', $tenant)
                ->orderBy('users.name')->get(['classroom_id', 'user_id', 'name', 'email', 'username', 'locked'])->groupBy('classroom_id'),
        ]);
    }

    public function saveClassroom(Request $request, ?int $classroom = null)
    {
        $tenant = LearningAccess::tenantManager();
        if ($classroom !== null) {
            DB::table('classrooms')->where('tenant_id', $tenant)->where('id', $classroom)->firstOrFail();
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('classrooms')->where('tenant_id', $tenant)->ignore($classroom)],
            'description' => 'nullable|string|max:3000',
            'teacher_id' => 'sometimes|nullable|integer',
        ]);
        DB::transaction(function () use ($classroom, $tenant, $data) {
            if (isset($data['teacher_id'])) {
                $teacher = DB::table('users')->where('tenant_id', $tenant)->where('role', 'teacher')->where('locked', false)
                    ->where('id', $data['teacher_id'])->lockForUpdate()->first();
                if (! $teacher) {
                    throw ValidationException::withMessages(['teacher_id' => 'Chọn giáo viên đang hoạt động trong tổ chức.']);
                }
            }
            if ($classroom === null) {
                DB::table('classrooms')->insert($data + ['tenant_id' => $tenant, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('classrooms')->where('tenant_id', $tenant)->where('id', $classroom)->update($data + ['updated_at' => now()]);
            }
        });

        return back()->with('status', 'Đã lưu lớp học.');
    }

    public function createCourseClassroomStudent(Request $request, int $course)
    {
        LearningAccess::manageCourse($course);
        $data = $request->validate(['classroom_id' => 'required|integer']);
        $request->merge(['create_account' => true]);

        return $this->addClassroomStudent($request, (int) $data['classroom_id']);
    }

    public function addClassroomStudent(Request $request, int $classroom)
    {
        $query = LearningAccess::classrooms()->where('id', $classroom);
        $query->firstOrFail();
        $request->merge(['email' => is_string($request->input('email')) ? strtolower(trim($request->input('email'))) : $request->input('email')]);
        $create = $request->boolean('create_account');
        $data = $request->validate([
            'create_account' => 'sometimes|boolean',
            'email' => ['required', 'email', 'max:255', ...($create ? ['unique:users'] : [])],
            'name' => $create ? 'required|string|max:255' : 'prohibited',
            'password' => $create ? 'required|string|min:12|max:1024' : 'prohibited',
        ]);
        try {
            DB::transaction(function () use ($query, $data, $create, $classroom) {
                $group = $query->lockForUpdate()->firstOrFail();
                if ($group->archived_at) {
                    throw ValidationException::withMessages(['classroom_id' => 'Lớp đã lưu trữ. Hãy chọn lớp đang hoạt động.']);
                }
                if ($create) {
                    $id = DB::table('users')->insertGetId([
                        'tenant_id' => $group->tenant_id, 'role' => 'student', 'name' => $data['name'], 'email' => $data['email'],
                        'password' => Hash::make($data['password']), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                } else {
                    $student = DB::table('users')->where('tenant_id', $group->tenant_id)->where('role', 'student')->where('locked', false)
                        ->where('email', $data['email'])->lockForUpdate()->first();
                    if (! $student) {
                        throw ValidationException::withMessages(['email' => 'Không tìm thấy học viên đang hoạt động trong tổ chức.']);
                    }
                    $id = $student->id;
                }
                DB::table('classroom_members')->insertOrIgnore(['classroom_id' => $classroom, 'user_id' => $id]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'Email đã có tài khoản. Dùng chức năng thêm học viên hiện có.']);
        }

        return back()->with('status', $create ? 'Đã tạo tài khoản và thêm học viên vào lớp.' : 'Học viên đã có trong lớp.');
    }

    public function members(Request $request, int $classroom)
    {
        $tenant = LearningAccess::tenantManager();
        $data = $request->validate(['user_ids' => 'sometimes|array|max:1000', 'user_ids.*' => 'required|integer|distinct']);
        $ids = $data['user_ids'] ?? [];
        DB::transaction(function () use ($classroom, $ids, $tenant) {
            $group = DB::table('classrooms')->where('tenant_id', $tenant)->where('id', $classroom)->lockForUpdate()->firstOrFail();
            abort_if($group->archived_at, 422, 'Khôi phục lớp trước khi thay đổi thành viên.');
            $valid = DB::table('users')->where('tenant_id', $tenant)->where('role', 'student')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');
            abort_unless($valid->count() === count($ids), 422, 'Danh sách có học viên không thuộc tổ chức.');
            DB::table('classroom_members')->where('classroom_id', $classroom)->delete();
            foreach ($ids as $id) {
                DB::table('classroom_members')->insert(['classroom_id' => $classroom, 'user_id' => $id]);
            }
        });

        return back()->with('status', 'Đã lưu danh sách lớp. Vào khóa học để giao bài cho lớp này.');
    }

    public function courseClassroom(Request $request, int $course)
    {
        LearningAccess::manageCourse($course);
        $data = $request->validate(['classroom_id' => 'required|integer']);
        DB::transaction(function () use ($course, $data) {
            $classroom = LearningAccess::classrooms()->where('id', $data['classroom_id'])->lockForUpdate()->firstOrFail();
            abort_if($classroom->archived_at, 422, 'Lớp đã được lưu trữ.');
            $this->enrollClassroom($course, $classroom, $this->activeClassroomStudents($classroom));
        });

        return back()->with('status', 'Đã gắn lớp và ghi danh các học viên hiện tại. Bấm lại để bổ sung thành viên mới; thu hồi học viên tại danh sách ghi danh.');
    }

    private function activeClassroomStudents(object $classroom): array
    {
        return DB::table('classroom_members')->join('users', 'users.id', '=', 'classroom_members.user_id')
            ->where('classroom_id', $classroom->id)->where('users.tenant_id', $classroom->tenant_id)
            ->where('role', 'student')->where('locked', false)->orderBy('user_id')->pluck('user_id')->all();
    }

    private function enrollClassroom(int $course, object $classroom, array $students): void
    {
        DB::table('course_classrooms')->insertOrIgnore(['course_id' => $course, 'classroom_id' => $classroom->id]);
        foreach ($students as $student) {
            DB::table('enrollments')->insertOrIgnore(['course_id' => $course, 'user_id' => $student]);
        }
    }

    public function teachers(Request $request, int $course)
    {
        $context = LearningAccess::manageCourse($course);
        abort_unless(Auth::user()->role === 'tenant_admin' || $context->teacher_id === Auth::id(), 403);
        $data = $request->validate(['user_id' => 'required|integer', 'granted' => 'required|boolean']);
        DB::table('users')->where('tenant_id', $context->tenant_id)->where('role', 'teacher')->when($request->boolean('granted'), fn ($q) => $q->where('locked', false))->where('id', $data['user_id'])->firstOrFail();
        abort_if($data['user_id'] == $context->teacher_id, 422, 'Không thu hồi giáo viên phụ trách tại đây.');
        $key = ['course_id' => $course, 'user_id' => $data['user_id']];
        if ($request->boolean('granted')) {
            DB::table('course_teachers')->insertOrIgnore($key);
        } else {
            DB::table('course_teachers')->where($key)->delete();
        }
        $this->audit('course.teacher_changed', $course);

        return back()->with('status', 'Đã cập nhật giáo viên đồng giảng.');
    }

    public function bulkAssign(Request $request, int $course)
    {
        $context = LearningAccess::manageCourse($course);
        if (! $request->has('audience') && $request->filled('classroom_id')) {
            $request->merge($request->input('classroom_id') === 'course'
                ? ['audience' => 'course', 'classroom_id' => null]
                : ['audience' => 'classroom']);
        }
        $data = $request->validate([
            'activity_id' => 'required|integer', 'audience' => 'required|in:course,classroom',
            'classroom_id' => 'required_if:audience,classroom|nullable|integer', 'granted' => 'required|boolean',
        ]);
        $activityId = (int) $data['activity_id'];
        $granted = $request->boolean('granted');
        if ($data['audience'] === 'classroom' && $granted) {
            $message = DB::transaction(function () use ($course, $activityId, $data) {
                $classroom = LearningAccess::classrooms()->where('id', $data['classroom_id'])->lockForUpdate()->firstOrFail();
                if ($classroom->archived_at) {
                    throw ValidationException::withMessages(['classroom_id' => 'Lớp đã lưu trữ. Hãy chọn lớp đang hoạt động.']);
                }
                $activity = DB::table('activities')->where('course_id', $course)->where('id', $activityId)->lockForUpdate()->firstOrFail();
                ActivityAssignments::ensureAssignable($activity);
                $students = $this->activeClassroomStudents($classroom);
                if ($students === []) {
                    return 'Lớp '.$classroom->name.' chưa có học viên đang hoạt động.';
                }

                $this->enrollClassroom($course, $classroom, $students);
                $exceptions = ActivityAssignments::bulk($activityId, $students, true);
                if (! $activity->published) {
                    DB::table('activities')->where('id', $activityId)->update(['published' => true]);
                    $activity->published = true;
                }
                $this->audit('activity.bulk_assigned', $activityId);

                $message = 'Đã giao bài cho lớp '.$classroom->name.'. '.count($students).' học viên đang hoạt động của lớp đã có trong khóa học.';
                if ($exceptions > 0) {
                    $message .= ' Đã giữ '.$exceptions.' ngoại lệ cá nhân.';
                }

                return $message.match (LearningAccess::availability($activity)) {
                    'scheduled' => ' Học viên có thể mở bài khi đến giờ đã đặt.',
                    'closed' => ' Thời gian mở bài đã kết thúc.',
                    default => '',
                };
            }, 3);

            return back()->with('status', $message);
        }

        ActivityAssignments::ensureAssignable(DB::table('activities')->where('course_id', $course)->where('id', $activityId)->firstOrFail());
        if ($data['audience'] === 'classroom') {
            $group = LearningAccess::classrooms()->where('id', $data['classroom_id'])->firstOrFail();
        }
        $ids = DB::table('enrollments')->join('users', 'users.id', '=', 'enrollments.user_id')
            ->where('course_id', $course)->where('users.tenant_id', $context->tenant_id)->where('role', 'student')
            ->when($granted, fn ($q) => $q->where('locked', false))
            ->when($data['audience'] === 'classroom', fn ($q) => $q->whereIn('user_id', DB::table('classroom_members')->where('classroom_id', $data['classroom_id'])->select('user_id')))
            ->pluck('user_id');
        if ($data['audience'] === 'classroom' && $ids->isEmpty()) {
            return back()->with('status', 'Lớp '.$group->name.' chưa có học viên trong khóa học này.');
        }
        $exceptions = ActivityAssignments::bulk($activityId, $ids->all(), $granted);
        $this->audit('activity.bulk_assigned', $activityId);

        return back()->with('status', 'Đã cập nhật đợt giao cho '.$ids->count().' học viên, giữ '.$exceptions.' ngoại lệ cá nhân. Học viên ghi danh sau cần được giao bài bổ sung.');
    }

    public function archive(Request $request, int $course, int $activity)
    {
        LearningAccess::manageCourse($course);
        $request->validate(['archived' => 'required|boolean']);
        DB::table('activities')->where('course_id', $course)->where('id', $activity)->firstOrFail();
        DB::table('activities')->where('id', $activity)->update([
            'archived_at' => $request->boolean('archived') ? now() : null, 'published' => false,
        ]);
        $this->audit('activity.archived', $activity);

        return back()->with('status', 'Đã cập nhật lưu trữ. Bài khôi phục được giữ ở bản nháp.');
    }

    public function report(Request $request, int $course)
    {
        $context = LmsController::courses()->where('id', $course)->firstOrFail();
        $data = $request->validate(['classroom_id' => 'nullable|integer', 'activity_id' => 'nullable|integer', 'history' => 'sometimes|boolean', 'result_status' => 'nullable|in:all,not_reported,in_progress,completed']);
        if (! empty($data['classroom_id'])) {
            abort_if(Auth::user()->role === 'student', 403);
            DB::table('course_classrooms')->join('classrooms', 'classrooms.id', '=', 'course_classrooms.classroom_id')
                ->where('course_id', $course)->where('classroom_id', $data['classroom_id'])->where('tenant_id', $context->tenant_id)->firstOrFail();
        }
        if (! empty($data['activity_id'])) {
            DB::table('activities')->where('course_id', $course)->where('id', $data['activity_id'])->firstOrFail();
        }
        $rows = $request->boolean('history') ? Gradebook::records($course, $data['classroom_id'] ?? null, $data['activity_id'] ?? null)->get() : Gradebook::overview($course, $data['classroom_id'] ?? null, $data['activity_id'] ?? null);
        $rows = Gradebook::filterStatus($rows, $data['result_status'] ?? null);

        return response()->streamDownload(function () use ($rows) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Học viên', 'Email', 'Bài học', 'Điểm', 'Điểm tối đa', 'Tiến độ', 'Thời gian UTC', 'Nộp trễ'], ',', '"', '');
            foreach ($rows as $row) {
                $progress = ! ($row->has_result ?? true) ? 'Chưa nhận kết quả' : (($row->completed ?? $row->activity_progress === 'Completed') ? 'Completed' : $row->activity_progress);
                $values = [$row->name, $row->email, $row->title, $row->score ?? '', $row->maximum, $progress, $row->reported_at ?? '',
                    $row->due_at && $row->reported_at && Carbon::parse($row->reported_at)->gt($row->due_at) ? 'Có' : 'Không'];
                $values = array_map(fn ($v) => preg_match('/^[\s]*[=+@\-]/u', (string) $v) ? "'".$v : $v, $values);
                fputcsv($stream, $values, ',', '"', '');
            }
            fclose($stream);
        }, 'grades-'.$course.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function updateUser(Request $request, int $user)
    {
        $tenant = LearningAccess::tenantManager();
        DB::table('users')->where('tenant_id', $tenant)->whereIn('role', ['teacher', 'student'])->where('id', $user)->firstOrFail();
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'name' => 'required|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
        ]);
        $data['email'] = strtolower($data['email']);
        DB::table('users')->where('id', $user)->update($data + ['updated_at' => now()]);
        $this->audit('user.updated', $user);

        return back()->with('status', 'Đã cập nhật hồ sơ.');
    }

    public function roster(Request $request, int $course)
    {
        $context = LearningAccess::manageCourse($course);
        $students = DB::table('enrollments')->join('users', 'users.id', '=', 'enrollments.user_id')
            ->where('course_id', $course)->where('users.tenant_id', $context->tenant_id)->where('role', 'student')->orderBy('users.name')->get(['users.name', 'users.email', 'users.locked']);

        return response()->streamDownload(function () use ($students) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Họ tên', 'Email', 'Trạng thái'], ',', '"', '');
            foreach ($students as $student) {
                $values = [$student->name, $student->email, $student->locked ? 'Đã khóa' : 'Đang hoạt động'];
                $values = array_map(fn ($value) => preg_match('/^[\s]*[=+@\-]/u', (string) $value) ? "'".$value : $value, $values);
                fputcsv($stream, $values, ',', '"', '');
            }
            fclose($stream);
        }, 'learners-'.$course.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function resetPassword(Request $request, int $user)
    {
        $tenant = LearningAccess::tenantManager();
        $data = $request->validate(['password' => 'required|string|min:12|max:1024|confirmed']);
        DB::transaction(function () use ($tenant, $user, $data) {
            DB::table('users')->where('tenant_id', $tenant)->whereIn('role', ['teacher', 'student'])->where('id', $user)->lockForUpdate()->firstOrFail();
            DB::table('users')->where('id', $user)->update(['password' => Hash::make($data['password']), 'updated_at' => now(), 'remember_token' => null]);
            DB::table('sessions')->where('user_id', $user)->delete();
            $this->audit('user.password_reset', $user);
        });

        return back()->with('status', 'Đã đặt lại mật khẩu và thu hồi phiên đăng nhập cũ.');
    }

    public function importUsers(Request $request)
    {
        $tenant = LearningAccess::tenantManager();
        $request->validate(['csv' => 'required|file|max:2048']);
        $stream = fopen($request->file('csv')->getRealPath(), 'r');
        $header = fgetcsv($stream, null, ',', '"', '');
        if ($header) {
            $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
        }
        $rows = [];
        try {
            if ($header !== ['name', 'email', 'password']) {
                throw ValidationException::withMessages(['csv' => 'Dòng đầu CSV phải là name,email,password.']);
            }
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== 3 || count($rows) >= 500) {
                    throw ValidationException::withMessages(['csv' => 'CSV tối đa 500 học viên, mỗi dòng có đủ 3 cột.']);
                }
                $data = ['name' => $row[0], 'email' => strtolower(trim($row[1])), 'password' => $row[2]];
                $validator = validator($data, ['name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users', 'password' => 'required|string|min:12|max:1024']);
                if ($validator->fails() || in_array($data['email'], array_column($rows, 'email'), true)) {
                    throw ValidationException::withMessages(['csv' => 'Dòng '.(count($rows) + 2).' không hợp lệ hoặc email đã tồn tại. Chưa nhập dữ liệu.']);
                }
                $rows[] = $data;
            }
        } finally {
            fclose($stream);
        }
        if (! $rows) {
            throw ValidationException::withMessages(['csv' => 'CSV chưa có học viên.']);
        }
        DB::transaction(function () use ($rows, $tenant) {
            foreach ($rows as $row) {
                $row['password'] = Hash::make($row['password']);
                DB::table('users')->insert($row + ['tenant_id' => $tenant, 'role' => 'student', 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit('users.imported', null);
        });

        return back()->with('status', 'Đã nhập '.count($rows).' học viên.');
    }

    public function archiveClassroom(Request $request, int $classroom)
    {
        $tenant = LearningAccess::tenantManager();
        $request->validate(['archived' => 'required|boolean']);
        DB::table('classrooms')->where('tenant_id', $tenant)->where('id', $classroom)->firstOrFail();
        DB::table('classrooms')->where('id', $classroom)->update(['archived_at' => $request->boolean('archived') ? now() : null, 'updated_at' => now()]);
        $this->audit('classroom.archived', $classroom);

        return back()->with('status', 'Đã cập nhật lưu trữ lớp. Ghi danh, quyền học và điểm đã có vẫn được giữ.');
    }

    public function detachClassroom(int $course, int $classroom)
    {
        LearningAccess::manageCourse($course);
        LearningAccess::classrooms()->where('id', $classroom)->firstOrFail();
        DB::table('course_classrooms')->where('course_id', $course)->where('classroom_id', $classroom)->firstOrFail();
        DB::table('course_classrooms')->where('course_id', $course)->where('classroom_id', $classroom)->delete();
        $this->audit('course.classroom_detached', $course);

        return back()->with('status', 'Đã gỡ liên kết lớp. Nếu cần ngừng quyền học, thu hồi từng học viên trong danh sách ghi danh.');
    }

    public function transferTeacher(Request $request, int $course)
    {
        $context = LearningAccess::manageCourse($course);
        abort_unless(Auth::user()->role === 'tenant_admin' || $context->teacher_id === Auth::id(), 403);
        $data = $request->validate(['teacher_id' => 'required|integer', 'retain_previous' => 'sometimes|boolean']);
        DB::transaction(function () use ($context, $course, $data, $request) {
            $locked = DB::table('courses')->where('id', $course)->lockForUpdate()->firstOrFail();
            abort_unless(Auth::user()->role === 'tenant_admin' || $locked->teacher_id === Auth::id(), 403);
            DB::table('users')->where('tenant_id', $context->tenant_id)->where('role', 'teacher')->where('locked', false)->where('id', $data['teacher_id'])->lockForUpdate()->firstOrFail();
            abort_if($locked->teacher_id == $data['teacher_id'], 422, 'Người này đã là giáo viên phụ trách.');
            DB::table('courses')->where('id', $course)->update(['teacher_id' => $data['teacher_id'], 'updated_at' => now()]);
            DB::table('course_teachers')->where('course_id', $course)->whereIn('user_id', [$locked->teacher_id, $data['teacher_id']])->delete();
            $previous = DB::table('users')->where('id', $locked->teacher_id)->where('role', 'teacher')->where('tenant_id', $context->tenant_id)->where('locked', false)->exists();
            if ($request->boolean('retain_previous') && $previous) {
                DB::table('course_teachers')->insert(['course_id' => $course, 'user_id' => $locked->teacher_id]);
            }
            $this->audit('course.owner_transferred', $course);
        });

        return redirect('/')->with('status', 'Đã bàn giao giáo viên phụ trách. Khóa học, nội dung và điểm được giữ nguyên.');
    }

    public function reports(Request $request)
    {
        abort_unless(! Auth::user()->locked && in_array(Auth::user()->role, ['admin', 'tenant_admin', 'teacher'], true), 403);
        $data = $request->validate(['course_id' => 'nullable|integer']);
        if (! empty($data['course_id'])) {
            $course = LmsController::courses()->where('id', $data['course_id'])->firstOrFail();

            return redirect('/courses/'.$course->id.'/grades');
        }

        return view('reports', ['courses' => LmsController::courses()->orderBy('title')->get()]);
    }

    public function profile()
    {
        abort_if(Auth::user()->locked, 403);

        return view('profile');
    }

    public function updateProfile(Request $request)
    {
        abort_if(Auth::user()->locked, 403);
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
        $changedEmail = $request->input('email') !== Auth::user()->email;
        $data = $request->validate([
            'name' => 'required|string|max:255', 'email' => ['present', Rule::requiredIf(! Auth::user()->username), 'nullable', 'email', 'max:255', Rule::unique('users')->ignore(Auth::id())],
            'current_password' => [Rule::requiredIf($changedEmail), 'nullable', 'string', 'max:1024', 'current_password'],
        ]);
        DB::transaction(function () use ($data, $changedEmail, $request) {
            Auth::user()->forceFill(['name' => $data['name'], 'email' => $data['email']])->save();
            if ($changedEmail) {
                DB::table('sessions')->where('user_id', Auth::id())->where('id', '!=', $request->session()->getId())->delete();
                Auth::user()->setRememberToken(null);
                Auth::user()->save();
            }
            $this->audit('account.profile_updated', Auth::id());
        });

        return back()->with('status', 'Đã cập nhật hồ sơ cá nhân.');
    }

    public function toolDiagnostics(int $tool)
    {
        $tenant = LearningAccess::tenantManager();
        $registration = DB::table('lti_registrations')->where('tenant_id', $tenant)->where('id', $tool)->firstOrFail();

        return view('tool-diagnostics', ['tool' => $registration, 'checks' => ToolDiagnostics::checks($registration)]);
    }

    private function audit(string $event, ?int $resource): void
    {
        DB::table('audit_logs')->insert(['event' => $event, 'user_id' => Auth::id(), 'resource_id' => $resource]);
    }
}
