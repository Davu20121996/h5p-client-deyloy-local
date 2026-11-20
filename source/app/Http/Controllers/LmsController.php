<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LmsController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:1024']);
        $credentials['email'] = strtolower($credentials['email']);
        $key = 'login:'.hash('sha256', $credentials['email'].'|'.$request->ip());
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429);
        RateLimiter::hit($key, 60);
        if (! Auth::attempt($credentials + ['locked' => false])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['email' => 'Thông tin đăng nhập không hợp lệ.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();

        return $this->respond(redirect('/'));
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
        abort_if(! $user || $user->locked || ($user->role !== 'admin' && !$user->tenant_id), 403);

        return DB::table('courses')->when($user->role !== 'admin', fn ($query) => $query->where('tenant_id', $user->tenant_id))->when($user->role === 'teacher', fn ($query) => $query->where('teacher_id', $user->id))
            ->when($user->role === 'student', fn ($query) => $query->whereIn('id', DB::table('enrollments')->where('user_id', $user->id)->select('course_id')));
    }

    public function changePassword(Request $request)
    {
        abort_if(Auth::user()->locked, 403);
        $data = $request->validate([
            'current_password' => 'required|string|max:1024|current_password',
            'password' => 'required|string|min:12|max:1024|confirmed|different:current_password',
        ]);
        DB::transaction(function () use ($data) {
            Auth::user()->forceFill(['password' => Hash::make($data['password'])])->save();
            DB::table('sessions')->where('user_id', Auth::id())->delete();
        });
        $request->session()->regenerate();

        return $this->respond(back(), 'Đã đổi mật khẩu.');
    }

    public function changeRole(Request $request, int $id)
    {
        $this->requireTenantAdmin();
        $data = $request->validate(['role' => 'required|in:teacher,student']);
        abort_if($id === Auth::id(), 403);
        DB::transaction(function () use ($id, $data) {
            $user = DB::table('users')->where('id', $id)->where('tenant_id', Auth::user()->tenant_id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($user->role, ['teacher', 'student'], true), 403);
            abort_if($data['role'] === 'student' && DB::table('courses')->where('teacher_id', $id)->exists(), 422, 'Tài khoản đang phụ trách khóa học.');
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

        return $this->screen('lms', [
            'course' => $course,
            'screen' => $screen,
            'activities' => !in_array($screen, ['activities', 'enrollment'], true) ? collect() : DB::table('activities')->where('course_id', $id)->when($student, fn ($query) => $query->where('published', true)->whereIn('id', self::assignedActivities(Auth::id())))->get(),
            'learners' => $screen !== 'enrollment' ? collect() : DB::table('enrollments')->join('users', 'users.id', '=', 'enrollments.user_id')->where('course_id', $id)->where('users.role', 'student')->select('users.id', 'users.name', 'users.email')->get(),
            'availableLearners' => $screen === 'enrollment' && in_array(Auth::user()->role, ['teacher', 'tenant_admin'], true)
                ? DB::table('users')->where('tenant_id', $course->tenant_id)->where('role', 'student')->where('locked', false)
                    ->whereNotIn('id', DB::table('enrollments')->where('course_id', $id)->select('user_id'))
                    ->orderBy('name')->get(['name', 'email'])
                : collect(),
            'assignments' => $screen !== 'enrollment' ? collect() : DB::table('activity_assignments')->join('activities', 'activities.id', '=', 'activity_assignments.activity_id')->join('users', 'users.id', '=', 'activity_assignments.user_id')->where('activities.course_id', $id)->select('activities.title', 'users.name')->get(),
            'tools' => $student || $screen !== 'activities' ? collect() : DB::table('lti_registrations')->where('tenant_id', $course->tenant_id)->select('id', 'name')->get(),
            'grades' => $screen !== 'grades' ? collect() : DB::table('grades')->join('activities', 'activities.id', '=', 'grades.activity_id')->join('users', 'users.id', '=', 'grades.user_id')
                ->where('course_id', $id)->when($student, fn ($query) => $query->where('grades.user_id', Auth::id())->where('published', true)->whereIn('activities.id', self::assignedActivities(Auth::id())))
                ->select('users.name', 'activities.title', 'activities.maximum', 'grades.score', 'grades.reported_at')
                ->orderByDesc('grades.reported_at')->get(),
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
        $this->manage($id);
        $data = $request->validate(['email' => 'required|email|max:255']);
        $student = DB::table('users')->where('role', 'student')->where('tenant_id', Auth::user()->tenant_id)
            ->where('locked', false)->where('email', strtolower($data['email']))->first();
        if (! $student) {
            throw \Illuminate\Validation\ValidationException::withMessages(['email' => 'Không tìm thấy tài khoản học viên.']);
        }
        $added = DB::table('enrollments')->insertOrIgnore(['course_id' => $id, 'user_id' => $student->id]);

        return $this->respond(redirect('/courses/'.$id.'/enrollment'), $added
            ? 'Đã ghi danh '.$student->name.'.'
            : $student->name.' đã có trong khóa học.');
    }

    public function unenroll(int $id, int $user)
    {
        $this->manage($id);
        DB::transaction(function () use ($id, $user) {
            DB::table('enrollments')->where('course_id', $id)->where('user_id', $user)->delete();
            DB::table('activity_assignments')->where('user_id', $user)
                ->whereIn('activity_id', DB::table('activities')->where('course_id', $id)->select('id'))
                ->delete();
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
            $existing->update(['published' => $request->boolean('published')]);

            return $this->respond(back(), 'Đã cập nhật trạng thái.');
        }
        $data = $request->validate([
            'title' => 'required|string|max:255', 'lti_registration_id' => 'required|integer',
            'target_uri' => 'required|url:https|max:2048', 'maximum' => 'required|integer|min:1|max:100000',
            'published' => 'sometimes|boolean',
        ]);
        $tool = DB::table('lti_registrations')->where('tenant_id', Auth::user()->tenant_id)->where('id', $data['lti_registration_id'])->firstOrFail();
        $toolHost = parse_url($tool->redirect_uri, PHP_URL_HOST);
        $targetHost = parse_url($data['target_uri'], PHP_URL_HOST);
        if (strcasecmp($targetHost, $toolHost) !== 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'target_uri' => $toolHost === 'demo.invalid'
                    ? 'H5P Demo chỉ có URL mẫu. Hãy chọn công cụ đã kết nối với H5P Hosting.'
                    : "URL bài H5P phải dùng cùng tên miền với Redirect URI của công cụ ({$toolHost}).",
            ]);
        }
        $data['published'] = $request->boolean('published');
        if ($activity !== null) {
            $existing->update($data);

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
            'granted' => 'required|boolean',
        ]);
        DB::table('activities')->where('course_id', $id)->where('id', $data['activity_id'])->firstOrFail();
        DB::table('enrollments')->join('users', 'users.id', '=', 'enrollments.user_id')
            ->where('course_id', $id)->where('user_id', $data['user_id'])->where('users.role', 'student')->where('users.tenant_id', Auth::user()->tenant_id)->firstOrFail();
        $assignment = ['activity_id' => $data['activity_id'], 'user_id' => $data['user_id']];
        if ($data['granted']) {
            DB::table('activity_assignments')->insertOrIgnore($assignment);
        } else {
            DB::table('activity_assignments')->where($assignment)->delete();
        }

        return $this->respond(back(), 'Đã cập nhật quyền làm bài.');
    }

    public function deleteCourse(int $id)
    {
        $this->manage($id);
        DB::table('courses')->where('id', $id)->delete();

        return $this->respond(redirect('/'), 'Đã xóa khóa học.');
    }

    public function admin(string $screen = 'users')
    {
        $user = Auth::user();
        abort_unless(!$user->locked && ($user->role === 'admin' || ($user->role === 'tenant_admin' && $user->tenant_id)), 403);
        $platform = $user->role === 'admin';

        return $this->screen('admin', [
            'platform' => $platform,
            'roles' => $screen === 'users' ? DB::table('roles')->pluck('name', 'code') : collect(),
            'tenants' => $screen === 'tenants'
                ? DB::table('tenants')->when(!$platform, fn ($query) => $query->where('id', $user->tenant_id))->orderBy('name')->get()
                : collect(),
            'tenantUsers' => $platform && $screen === 'tenants'
                ? DB::table('users')->whereNotNull('tenant_id')->whereIn('role', ['tenant_admin', 'teacher', 'student'])
                    ->select('id', 'tenant_id', 'name', 'email', 'role', 'locked')->orderBy('name')->get()->groupBy('tenant_id')
                : collect(),
            'screen' => $screen,
            'userGroups' => $screen !== 'users' ? collect() : DB::table('users')
                ->leftJoin('tenants', 'tenants.id', '=', 'users.tenant_id')
                ->when(!$platform, fn ($query) => $query->where('users.tenant_id', $user->tenant_id))
                ->select('users.id', 'users.name', 'users.email', 'users.role', 'users.locked', 'users.tenant_id', 'tenants.name as tenant_name')
                ->orderBy('tenants.name')->orderBy('users.name')->get()->groupBy('tenant_id'),
            'toolGroups' => $screen !== 'tools' ? collect() : DB::table('lti_registrations as tools')
                ->leftJoin('tenants', 'tenants.id', '=', 'tools.tenant_id')
                ->when(!$platform, fn ($query) => $query->where('tools.tenant_id', $user->tenant_id))
                ->orderBy('tenants.name')->orderBy('tools.name')
                ->get(['tools.*', 'tenants.name as tenant_name'])->groupBy('tenant_id'),
            'logGroups' => $screen !== 'logs' ? collect() : DB::table('audit_logs as logs')
                ->leftJoin('users as actor', 'actor.id', '=', 'logs.user_id')
                ->leftJoin('users as member', function ($join) {
                    $join->on('member.id', '=', 'logs.resource_id')->where('logs.event', 'tenant.manager_granted');
                })
                ->leftJoin('tenants', DB::raw('coalesce(member.tenant_id, actor.tenant_id)'), '=', 'tenants.id')
                ->when(!$platform, fn ($query) => $query->where('tenants.id', $user->tenant_id))
                ->latest('logs.id')->limit(20)
                ->get(['logs.*', 'tenants.id as tenant_id', 'tenants.name as tenant_name'])->groupBy('tenant_id'),
        ]);
    }

    public function grantTenantManager(int $tenant, int $user)
    {
        abort_unless(Auth::user()->role === 'admin' && !Auth::user()->locked, 403);
        DB::transaction(function () use ($tenant, $user) {
            $member = DB::table('users')->where('tenant_id', $tenant)->where('id', $user)
                ->whereIn('role', ['teacher', 'student', 'tenant_admin'])->lockForUpdate()->firstOrFail();
            if ($member->locked) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'user' => 'Hãy mở khóa tài khoản trước khi cấp quyền quản lý.',
                ]);
            }
            if ($member->role !== 'tenant_admin') {
                DB::table('users')->where('id', $user)->update(['role' => 'tenant_admin', 'updated_at' => now()]);
                DB::table('audit_logs')->insert(['event' => 'tenant.manager_granted', 'user_id' => Auth::id(), 'resource_id' => $user]);
            }
        });

        return $this->respond(redirect('/admin/tenants'), 'Đã cấp quyền quản lý tổ chức.');
    }

    public function createTenant(Request $request)
    {
        abort_unless(Auth::user()->role === 'admin' && !Auth::user()->locked, 403);
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower($request->input('email'))]);
        }
        $data = $request->validate([
            'tenant_name' => 'required|string|max:255|unique:tenants,name',
            'welcome_message' => 'nullable|string|max:1000',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users',
            'password' => 'required|string|min:12|max:1024',
        ]);
        DB::transaction(function () use ($data) {
            $tenant = DB::table('tenants')->insertGetId([
                'name' => $data['tenant_name'], 'created_at' => now(), 'updated_at' => now(),
                'welcome_message' => $data['welcome_message'] ?? null,
            ]);
            DB::table('users')->insert([
                'tenant_id' => $tenant, 'role' => 'tenant_admin', 'name' => $data['name'],
                'email' => $data['email'], 'password' => Hash::make($data['password']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $this->respond(redirect('/admin/tenants'), 'Đã tạo tổ chức và tài khoản quản lý.');
    }

    public function updateTenantWelcome(Request $request, ?int $tenant = null)
    {
        if ($tenant === null) {
            $this->requireTenantAdmin();
            $tenant = Auth::user()->tenant_id;
        } else {
            abort_unless(Auth::user()->role === 'admin' && !Auth::user()->locked, 403);
            DB::table('tenants')->where('id', $tenant)->firstOrFail();
        }

        $data = $request->validate(['welcome_message' => 'nullable|string|max:1000']);

        DB::table('tenants')->where('id', $tenant)->update([
            'welcome_message' => $data['welcome_message'] ?? null,
            'updated_at' => now(),
        ]);

        return $this->respond(redirect('/admin/tenants'), 'Đã lưu câu chào.');
    }

    public function createUser(Request $request)
    {
        $this->requireTenantAdmin();
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower($request->input('email'))]);
        }
        $data = $request->validate([
            'name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users',
            'role' => 'required|in:teacher,student', 'password' => 'required|string|min:12|max:1024',
        ]);
        $data['password'] = Hash::make($data['password']);
        DB::table('users')->insert($data + ['tenant_id' => Auth::user()->tenant_id, 'created_at' => now(), 'updated_at' => now()]);

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

    public function tool(Request $request, ?int $id = null)
    {
        $this->requireTenantAdmin();
        $registration = $id === null ? null : DB::table('lti_registrations')->where('id', $id)->where('tenant_id', Auth::user()->tenant_id)->firstOrFail();
        $clientId = \Illuminate\Validation\Rule::unique('lti_registrations');
        if ($registration) {
            $clientId->ignore($registration->id);
        }
        $data = $request->validate([
            'name' => 'required|string|max:255', 'client_id' => ['required', 'string', 'max:255', $clientId],
            'deployment_id' => 'required|string|max:255', 'login_url' => 'required|url:https|max:2048',
            'redirect_uri' => 'required|url:https|max:2048', 'public_key' => 'required|string|max:8192',
        ]);
        $key = openssl_pkey_get_public($data['public_key']);
        $details = $key ? openssl_pkey_get_details($key) : false;
        if (! $details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
            throw \Illuminate\Validation\ValidationException::withMessages(['public_key' => 'Cần khóa công khai RSA từ 2048 bit.']);
        }
        $data['public_key'] = $details['key'];
        if ($registration) {
            DB::table('lti_registrations')->where('id', $id)->update($data);

            return $this->respond(back(), 'Đã cập nhật công cụ.');
        }
        DB::table('lti_registrations')->insert($data + ['tenant_id' => Auth::user()->tenant_id]);

        return $this->respond(back(), 'Đã đăng ký công cụ.');
    }

    private function screen(string $view, array $data)
    {
        if (request()->expectsJson()) {
            return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
        }

        return view($view, $data);
    }

    private function respond(\Illuminate\Http\RedirectResponse $redirect, ?string $message = null)
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
