<?php

namespace App\Http\Controllers;

use App\Services\ActivityAssignments;
use App\Services\Gradebook;
use App\Services\LearningAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LmsController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|string|max:255', 'password' => 'required|string|max:1024']);
        $login = strtolower(trim($credentials['email']));
        $field = str_contains($login, '@') ? 'email' : 'username';
        $key = 'login:'.hash('sha256', $login.'|'.$request->ip());
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429);
        RateLimiter::hit($key, 60);
        if (! Auth::attempt([
            $field => $login,
            'password' => $credentials['password'],
            'locked' => false,
            fn (Builder $query) => $query->whereNotNull('tenant_id')->whereIn('role', ['tenant_admin', 'teacher', 'student']),
        ])) {
            throw ValidationException::withMessages(['email' => 'Thông tin đăng nhập không hợp lệ.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();

        return $this->respond(redirect(Auth::user()->must_change_password ? '/account/password' : '/'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->respond(redirect('/'));
    }

    public static function courses()
    {
        $user = Auth::user();
        abort_if(! $user || $user->locked || ($user->role !== 'admin' && ! $user->tenant_id), 403);

        return DB::table('courses')->when($user->role !== 'admin', fn ($query) => $query->where('tenant_id', $user->tenant_id))->when($user->role === 'teacher', fn ($query) => $query->where(fn ($q) => $q->where('teacher_id', $user->id)->orWhereIn('id', DB::table('course_teachers')->where('user_id', $user->id)->select('course_id'))))
            ->when($user->role === 'student', fn ($query) => $query->whereIn('id', DB::table('enrollments')->where('user_id', $user->id)->select('course_id')));
    }

    public function changePassword(Request $request)
    {
        abort_if(Auth::user()->locked, 403);
        $firstChange = Auth::user()->must_change_password;
        $data = $request->validate([
            'current_password' => 'required|string|max:1024|current_password',
            'password' => 'required|string|min:12|max:1024|confirmed|different:current_password',
        ]);
        DB::transaction(function () use ($data) {
            Auth::user()->forceFill([
                'password' => Hash::make($data['password']), 'must_change_password' => false, 'remember_token' => null,
            ])->save();
            DB::table('sessions')->where('user_id', Auth::id())->delete();
        });
        $request->session()->regenerate();

        return $this->respond($firstChange ? redirect('/') : back(), 'Đã đổi mật khẩu.');
    }

    public function changeRole(Request $request, int $id)
    {
        $this->requireTenantAdmin();
        $data = $request->validate(['role' => 'required|in:teacher,student']);
        abort_if($id === Auth::id(), 403);
        DB::transaction(function () use ($id, $data) {
            $user = DB::table('users')->where('id', $id)->where('tenant_id', Auth::user()->tenant_id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($user->role, ['teacher', 'student'], true), 403);
            abort_if($data['role'] === 'student' && (DB::table('courses')->where('teacher_id', $id)->exists()
                || DB::table('course_teachers')->where('user_id', $id)->exists()
                || DB::table('classrooms')->where('teacher_id', $id)->exists()), 422, 'Hãy gỡ phân công lớp hoặc khóa học trước khi đổi giáo viên thành học viên.');
            abort_if($data['role'] === 'teacher' && (DB::table('enrollments')->where('user_id', $id)->exists() || DB::table('classroom_members')->where('user_id', $id)->exists()), 422, 'Hãy thu hồi ghi danh và thành viên lớp trước khi đổi học viên thành giáo viên.');
            DB::table('users')->where('id', $id)->update($data + ['updated_at' => now()]);
            DB::table('audit_logs')->insert(['event' => 'user.role_changed', 'user_id' => Auth::id(), 'resource_id' => $id]);
        });

        return $this->respond(back(), 'Đã cập nhật vai trò.');
    }

    public function dashboard()
    {
        $tenantId = Auth::user()?->tenant_id;

        return $this->screen('lms', [
            'courses' => Auth::check() ? self::courses()->latest()->get() : collect(),
            'course' => null,
            'organization' => $tenantId ? DB::table('tenants')->find($tenantId) : null,
        ]);
    }

    public function course(int $id, string $screen = 'activities')
    {
        $course = self::courses()->where('id', $id)->firstOrFail();
        $student = Auth::user()->role === 'student';
        abort_if($student && in_array($screen, ['enrollment', 'settings'], true), 403);

        $filters = request()->validate(['classroom_id' => 'nullable|integer', 'activity_id' => 'nullable|integer', 'history' => 'sometimes|boolean', 'result_status' => 'nullable|in:all,not_reported,in_progress,completed']);
        if (! empty($filters['classroom_id'])) {
            abort_if($student, 403);
            DB::table('course_classrooms')->join('classrooms', 'classrooms.id', '=', 'course_classrooms.classroom_id')->where('course_id', $id)->where('classroom_id', $filters['classroom_id'])->where('tenant_id', $course->tenant_id)->firstOrFail();
        }
        if (! empty($filters['activity_id'])) {
            DB::table('activities')->where('course_id', $id)->where('id', $filters['activity_id'])->firstOrFail();
        }
        $history = $screen === 'grades' && request()->boolean('history') ? Gradebook::records($id, $filters['classroom_id'] ?? null, $filters['activity_id'] ?? null)->get() : collect();

        return $this->screen('lms', [
            'course' => $course,
            'screen' => $screen,
            'activities' => ! in_array($screen, ['activities', 'enrollment', 'grades'], true) ? collect() : DB::table('activities')->where('course_id', $id)->when($student, fn ($query) => $query->where('published', true)->whereNull('archived_at')->whereIn('id', self::assignedActivities(Auth::id())))->orderBy('sort_order')->orderBy('id')->get(),
            'learners' => $screen !== 'enrollment' ? collect() : DB::table('enrollments')->join('users', 'users.id', '=', 'enrollments.user_id')->where('course_id', $id)->where('users.role', 'student')->select('users.id', 'users.name', 'users.email')->get(),
            'availableLearners' => $screen === 'enrollment' && in_array(Auth::user()->role, ['teacher', 'tenant_admin'], true)
                ? DB::table('users')->where('tenant_id', $course->tenant_id)->where('role', 'student')->where('locked', false)
                    ->when(Auth::user()->role === 'teacher', fn ($query) => $query->whereIn('id', DB::table('classroom_members')
                        ->whereIn('classroom_id', LearningAccess::classrooms()->whereNull('archived_at')->select('id'))->select('user_id')))
                    ->whereNotIn('id', DB::table('enrollments')->where('course_id', $id)->select('user_id'))
                    ->orderBy('name')->get(['name', 'email'])
                : collect(),
            'assignments' => $screen !== 'enrollment' ? collect() : DB::table('activity_assignments')->join('activities', 'activities.id', '=', 'activity_assignments.activity_id')->join('users', 'users.id', '=', 'activity_assignments.user_id')->where('activities.course_id', $id)->select('activities.title', 'users.name')->get(),
            'tools' => $student || $screen !== 'activities' ? collect() : DB::table('lti_registrations')->where('tenant_id', $course->tenant_id)->select('id', 'name', 'deep_link_url')->get(),
            'grades' => Gradebook::filterStatus(request()->boolean('history') ? $history : ($screen === 'grades' ? Gradebook::overview($id, $filters['classroom_id'] ?? null, $filters['activity_id'] ?? null) : collect()), $filters['result_status'] ?? null),
            'classrooms' => $student ? collect() : LearningAccess::classrooms()->orderBy('name')->get(),
            'attachedClassrooms' => $student ? collect() : DB::table('course_classrooms')->where('course_id', $id)->pluck('classroom_id'),
            'teachers' => $student ? collect() : DB::table('users')->where('tenant_id', $course->tenant_id)->where('role', 'teacher')->get(['id', 'name', 'locked']),
            'primaryTeacher' => $student ? null : DB::table('users')->where('id', $course->teacher_id)->where('tenant_id', $course->tenant_id)->first(['name']),
            'coTeachers' => $student ? collect() : DB::table('course_teachers')->where('course_id', $id)->pluck('user_id'),
            'assignmentOverrides' => $screen === 'enrollment' ? DB::table('activity_assignment_overrides')->join('activities', 'activities.id', '=', 'activity_assignment_overrides.activity_id')->join('users', 'users.id', '=', 'activity_assignment_overrides.user_id')->where('activities.course_id', $id)->get(['activities.title', 'users.name', 'activity_assignment_overrides.granted']) : collect(),
        ]);
    }

    public function saveCourse(Request $request, ?int $id = null)
    {
        abort_unless(in_array(Auth::user()->role, ['teacher', 'tenant_admin'], true) && Auth::user()->tenant_id && ! Auth::user()->locked, 403);
        $data = $request->validate(['title' => 'required|string|max:255', 'description' => 'nullable|string|max:3000']);
        $data['updated_at'] = now();
        if ($id === null) {
            $id = DB::table('courses')->insertGetId($data + ['tenant_id' => Auth::user()->tenant_id, 'teacher_id' => Auth::id(), 'created_at' => now()]);
        } else {
            self::courses()->where('id', $id)->firstOrFail();
            DB::table('courses')->where('id', $id)->update($data);
        }

        return $this->respond(redirect('/courses/'.$id), 'Đã lưu khóa học.');
    }

    public function enroll(Request $request, int $id)
    {
        $course = LearningAccess::manageCourse($id);
        $classes = LearningAccess::classrooms();
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'classroom_id' => ['nullable', 'integer', Rule::requiredIf(fn () => Auth::user()->role === 'teacher'
                || (clone $classes)->whereNull('archived_at')->exists())],
        ]);
        $message = DB::transaction(function () use ($id, $course, $classes, $data) {
            $classroom = null;
            if (isset($data['classroom_id'])) {
                $classroom = $classes->where('id', $data['classroom_id'])->lockForUpdate()->firstOrFail();
                abort_if($classroom->archived_at, 422, 'Lớp đã được lưu trữ.');
            }
            $student = DB::table('users')->where('role', 'student')->where('tenant_id', $course->tenant_id)
                ->where('locked', false)->where('email', strtolower($data['email']))->lockForUpdate()->first();
            if (! $student) {
                throw ValidationException::withMessages(['email' => 'Không tìm thấy tài khoản học viên.']);
            }

            if ($classroom) {
                DB::table('classroom_members')->insertOrIgnore(['classroom_id' => $classroom->id, 'user_id' => $student->id]);
                DB::table('course_classrooms')->insertOrIgnore(['course_id' => $id, 'classroom_id' => $classroom->id]);
            }
            $added = DB::table('enrollments')->insertOrIgnore(['course_id' => $id, 'user_id' => $student->id]);

            if ($classroom) {
                return $student->name.' đã có trong khóa học và lớp '.$classroom->name.'.';
            }

            return $added ? 'Đã ghi danh '.$student->name.'.' : $student->name.' đã có trong khóa học.';
        });

        return $this->respond(redirect('/courses/'.$id.'/enrollment'), $message);
    }

    public function unenroll(int $id, int $user)
    {
        $this->manage($id);
        DB::transaction(function () use ($id, $user) {
            DB::table('enrollments')->where('course_id', $id)->where('user_id', $user)->delete();
            DB::table('activity_assignments')->where('user_id', $user)
                ->whereIn('activity_id', DB::table('activities')->where('course_id', $id)->select('id'))
                ->delete();
            DB::table('activity_assignment_overrides')->where('user_id', $user)
                ->whereIn('activity_id', DB::table('activities')->where('course_id', $id)->select('id'))->delete();
        });

        return $this->respond(back(), 'Đã thu hồi quyền truy cập khóa học.');
    }

    public function activity(Request $request, int $id, ?int $activity = null)
    {
        $this->manage($id);
        $existing = DB::table('activities')->where('course_id', $id)->where('id', $activity);
        if ($activity !== null) {
            $existing->firstOrFail();
        }
        if ($activity !== null && $request->isMethod('POST')) {
            $request->validate(['published' => 'required|boolean']);
            abort_if($existing->firstOrFail()->archived_at && $request->boolean('published'), 422, 'Khôi phục bài lưu trữ trước khi xuất bản.');
            $existing->update(['published' => $request->boolean('published')]);

            return $this->respond(back(), 'Đã cập nhật trạng thái.');
        }
        $data = $request->validate([
            'title' => 'required|string|max:255', 'lti_registration_id' => 'required|integer',
            'target_uri' => 'required|url:https|max:2048', 'maximum' => 'required|integer|min:1|max:100000',
            'published' => 'sometimes|boolean',
            'instructions' => 'nullable|string|max:10000', 'sort_order' => 'sometimes|integer|min:0|max:100000',
            'opens_at' => 'nullable|date', 'due_at' => 'nullable|date', 'closes_at' => 'nullable|date',
            'grade_policy' => 'sometimes|in:latest,highest',
        ]);
        $tool = DB::table('lti_registrations')->where('tenant_id', Auth::user()->tenant_id)->where('id', $data['lti_registration_id'])->firstOrFail();
        $toolHost = parse_url($tool->redirect_uri, PHP_URL_HOST);
        if (! LearningAccess::origin($data['target_uri']) || LearningAccess::origin($data['target_uri']) !== LearningAccess::origin($tool->redirect_uri)) {
            throw ValidationException::withMessages([
                'target_uri' => $toolHost === 'demo.invalid'
                    ? 'H5P Demo chỉ có URL mẫu. Hãy chọn công cụ đã kết nối với H5P Hosting.'
                    : "URL bài H5P phải dùng cùng tên miền với Redirect URI của công cụ ({$toolHost}).",
            ]);
        }
        foreach (['opens_at', 'due_at', 'closes_at'] as $field) {
            $data[$field] = empty($data[$field]) ? null : Carbon::parse($data[$field], 'Asia/Ho_Chi_Minh')->utc()->format('Y-m-d H:i:s');
        }
        if (($data['opens_at'] && $data['closes_at'] && $data['opens_at'] >= $data['closes_at']) ||
            ($data['due_at'] && $data['opens_at'] && $data['due_at'] < $data['opens_at']) ||
            ($data['due_at'] && $data['closes_at'] && $data['due_at'] > $data['closes_at'])) {
            throw ValidationException::withMessages(['closes_at' => 'Thời gian mở, hạn nộp và đóng phải theo thứ tự.']);
        }
        $data['published'] = $request->boolean('published');
        if ($activity !== null) {
            DB::transaction(function () use ($existing, $activity, $data) {
                $old = $existing->lockForUpdate()->firstOrFail();
                $used = DB::table('grades')->where('activity_id', $activity)->exists() || DB::table('audit_logs')->where('event', 'lti.launch')->where('resource_id', $activity)->exists();
                if ($used && ($old->lti_registration_id != $data['lti_registration_id'] || $old->target_uri !== $data['target_uri'] || $old->maximum != $data['maximum'])) {
                    throw ValidationException::withMessages(['target_uri' => 'Bài đã được mở hoặc có điểm. Hãy tạo hoạt động mới để đổi bài, công cụ hoặc thang điểm.']);
                }
                abort_if($old->archived_at && $data['published'], 422, 'Khôi phục bài lưu trữ trước khi xuất bản.');
                if ($old->target_uri !== $data['target_uri'] || $old->lti_registration_id != $data['lti_registration_id']) {
                    $data['hub_custom'] = null;
                }
                $existing->update($data);
            });

            return $this->respond(back(), 'Đã lưu hoạt động.');
        }

        DB::table('activities')->insert($data + ['course_id' => $id, 'resource_link_id' => (string) Str::uuid()]);

        return $this->respond(back(), 'Đã thêm hoạt động.');
    }

    public static function assignedActivities(int $user)
    {
        return DB::table('activity_assignments')->where('user_id', $user)->select('activity_id');
    }

    public function assign(Request $request, int $id)
    {
        $this->manage($id);
        $data = $request->validate([
            'activity_id' => 'required|integer|min:1',
            'user_id' => 'required|integer|min:1',
            'granted' => 'required_without:mode|boolean', 'mode' => 'sometimes|in:inherit',
        ]);
        DB::table('activities')->where('course_id', $id)->where('id', $data['activity_id'])->firstOrFail();
        DB::table('enrollments')->join('users', 'users.id', '=', 'enrollments.user_id')
            ->where('course_id', $id)->where('user_id', $data['user_id'])->where('users.role', 'student')->where('users.tenant_id', Auth::user()->tenant_id)->firstOrFail();
        ActivityAssignments::individual((int) $data['activity_id'], (int) $data['user_id'], ($data['mode'] ?? null) === 'inherit' ? null : $request->boolean('granted'));

        return $this->respond(back(), 'Đã cập nhật quyền làm bài.');
    }

    public function deleteCourse(int $id)
    {
        $this->manage($id);
        $course = self::courses()->where('id', $id)->firstOrFail();
        abort_unless(Auth::user()->role === 'tenant_admin' || $course->teacher_id === Auth::id(), 403);
        abort_if(DB::table('grades')->whereIn('activity_id', DB::table('activities')->where('course_id', $id)->select('id'))->exists(), 422, 'Khóa đã có điểm; không thể xóa lịch sử học tập.');
        DB::table('courses')->where('id', $id)->delete();

        return $this->respond(redirect('/'), 'Đã xóa khóa học.');
    }

    public function admin(string $screen = 'users')
    {
        $this->requireTenantAdmin();
        $tenant = Auth::user()->tenant_id;

        return $this->screen('admin', [
            'roles' => $screen === 'users' ? DB::table('roles')->pluck('name', 'code') : collect(),
            'organization' => $screen === 'tenants' ? DB::table('tenants')->where('id', $tenant)->firstOrFail() : null,
            'screen' => $screen,
            'userGroups' => $screen !== 'users' ? collect() : DB::table('users')
                ->join('tenants', 'tenants.id', '=', 'users.tenant_id')
                ->where('users.tenant_id', $tenant)
                ->select('users.id', 'users.name', 'users.email', 'users.username', 'users.role', 'users.locked', 'tenants.name as tenant_name')
                ->orderBy('users.name')->get()->groupBy('tenant_name'),
            'toolGroups' => $screen !== 'tools' ? collect() : DB::table('lti_registrations as tools')
                ->join('tenants', 'tenants.id', '=', 'tools.tenant_id')
                ->where('tools.tenant_id', $tenant)
                ->orderBy('tools.name')->get(['tools.*', 'tenants.name as tenant_name'])->groupBy('tenant_name'),
            'logGroups' => $screen !== 'logs' ? collect() : DB::table('audit_logs as logs')
                ->join('users as actor', 'actor.id', '=', 'logs.user_id')
                ->join('tenants', 'tenants.id', '=', 'actor.tenant_id')
                ->where('actor.tenant_id', $tenant)
                ->latest('logs.id')->limit(20)
                ->get(['logs.*', 'tenants.name as tenant_name'])->groupBy('tenant_name'),
        ]);
    }

    public function updateTenantWelcome(Request $request)
    {
        $this->requireTenantAdmin();
        $tenant = Auth::user()->tenant_id;

        $data = $request->validate(['welcome_message' => 'nullable|string|max:1000']);

        DB::table('tenants')->where('id', $tenant)->update([
            'welcome_message' => $data['welcome_message'] ?? null,
            'updated_at' => now(),
        ]);

        return $this->respond(redirect('/admin/tenants'), 'Đã lưu câu chào.');
    }

    public function createUser(Request $request): JsonResponse|RedirectResponse
    {
        $tenant = LearningAccess::tenantManager();
        foreach (['username', 'email'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => strtolower($request->input($field))]);
            }
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['nullable', 'required_without:email', 'string', 'min:3', 'max:64', 'regex:/\A(?![0-9]+\z)[a-z0-9][a-z0-9._-]*\z/', 'unique:users'],
            'email' => 'nullable|required_without:username|email|max:255|unique:users',
            'role' => 'required|in:teacher,student',
            'password' => 'required|string|min:12|max:1024',
        ]);
        $data['password'] = Hash::make($data['password']);
        DB::table('users')->insert($data + [
            'tenant_id' => $tenant, 'must_change_password' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->respond(back(), 'Đã tạo tài khoản.');
    }

    public function lockUser(Request $request, int $id)
    {
        $this->requireTenantAdmin();
        $request->validate(['locked' => 'required|boolean']);
        DB::transaction(function () use ($request, $id) {
            DB::table('users')->where('id', $id)->where('tenant_id', Auth::user()->tenant_id)->whereIn('role', ['teacher', 'student'])->lockForUpdate()->firstOrFail();
            DB::table('users')->where('id', $id)->update(['locked' => $request->boolean('locked')]);
            if ($request->boolean('locked') && $id !== Auth::id()) {
                DB::table('sessions')->where('user_id', $id)->delete();
            }
        });

        return $this->respond(back(), 'Đã cập nhật tài khoản.');
    }

    private function screen(string $view, array $data)
    {
        if (request()->expectsJson()) {
            return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
        }

        return view($view, $data);
    }

    private function respond(RedirectResponse $redirect, ?string $message = null)
    {
        if (request()->expectsJson()) {
            return response()->json(['message' => $message, 'redirect' => $redirect->getTargetUrl()])
                ->header('Cache-Control', 'private, no-store');
        }

        return $message === null ? $redirect : $redirect->with('status', $message);
    }

    private function manage(int $id): void
    {
        abort_unless(in_array(Auth::user()->role, ['teacher', 'tenant_admin'], true) && Auth::user()->tenant_id, 403);
        self::courses()->where('id', $id)->firstOrFail();
    }

    private function requireTenantAdmin(): void
    {
        abort_unless(Auth::user()->role === 'tenant_admin' && Auth::user()->tenant_id && ! Auth::user()->locked, 403);
    }
}
