<?php

namespace App\Http\Controllers;

use App\Services\Gradebook;
use App\Services\HubProvisioningConfig;
use App\Services\LearningAccess;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LtiController extends Controller
{
    private const LTI = 'https://purl.imsglobal.org/spec/lti/claim/';

    private const AGS = 'https://purl.imsglobal.org/spec/lti-ags/scope/';

    private const DEEP = 'https://purl.imsglobal.org/spec/lti-dl/claim/';

    private const SCOPES = ['score', 'lineitem.readonly', 'result.readonly'];

    public function learn(int $course, int $activity)
    {
        $item = $this->launchable($course, $activity);

        return response()->view('learn', ['item' => $item])->header('Cache-Control', 'private, no-store');
    }

    public function learningStatus(int $course, int $activity)
    {
        $item = $this->launchable($course, $activity, false);
        $records = Gradebook::records($course, null, $activity)->where('grades.user_id', Auth::id())->get();
        $grade = Gradebook::official($records)->first();

        return response()->json([
            'completed' => $grade?->completed ?? false,
            'score' => $grade?->score === null ? null : (float) $grade->score,
            'maximum' => (int) $item->maximum,
            'reported_at' => $grade?->reported_at,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function start(Request $request, int $course, int $activity)
    {
        $item = $this->launchable($course, $activity);
        $hint = Str::random(64);
        Cache::put('lti:hint:'.hash('sha256', $hint), [
            'user' => Auth::id(), 'session' => $request->session()->getId(),
            'course' => $course, 'activity' => $activity,
        ], 300);

        return $this->form($item->login_url, [
            'iss' => config('app.url'), 'login_hint' => $hint, 'lti_message_hint' => $hint,
            'client_id' => $item->client_id, 'lti_deployment_id' => $item->deployment_id,
            'target_link_uri' => $item->target_uri,
        ]);
    }

    public function selectContent(Request $request, int $course)
    {
        $context = LearningAccess::manageCourse($course);
        $data = $request->validate(['lti_registration_id' => 'required|integer', 'embedded' => 'sometimes|boolean']);
        $tool = DB::table('lti_registrations')->where('tenant_id', $context->tenant_id)->where('id', $data['lti_registration_id'])->firstOrFail();
        abort_unless($tool->deep_link_url && LearningAccess::origin($tool->deep_link_url) === LearningAccess::origin($tool->redirect_uri), 422, 'Quản trị viên cần cấu hình URL chọn bài của Hub.');
        $selection = Str::random(64);
        Cache::put('lti:selection:'.hash('sha256', $selection), [
            'user' => Auth::id(), 'session' => $request->session()->getId(), 'course' => $course,
            'tool' => $tool->id, 'client_id' => $tool->client_id, 'deployment_id' => $tool->deployment_id,
            'public_key' => $tool->public_key, 'redirect_uri' => $tool->redirect_uri,
            'embedded' => $request->boolean('embedded'),
        ], 1800);
        $hint = Str::random(64);
        Cache::put('lti:hint:'.hash('sha256', $hint), [
            'user' => Auth::id(), 'session' => $request->session()->getId(), 'course' => $course,
            'selection' => $selection, 'tool' => $tool->id,
        ], 300);

        return $this->form($tool->login_url, [
            'iss' => config('app.url'), 'client_id' => $tool->client_id, 'login_hint' => $hint,
            'lti_message_hint' => $hint, 'lti_deployment_id' => $tool->deployment_id, 'target_link_uri' => $tool->deep_link_url,
        ]);
    }

    public function openAccountForm(Request $request)
    {
        $tenant = LearningAccess::tenantManager();
        $tool = HubProvisioningConfig::accountTool($tenant);
        abort_unless($tool, 422, 'Tổ chức chưa có kết nối H5P Hub để tạo tài khoản.');
        $hint = Str::random(64);
        Cache::put('lti:hint:'.hash('sha256', $hint), [
            'user' => Auth::id(), 'session' => $request->session()->getId(),
            'account_form' => true, 'tenant' => $tenant, 'tool' => $tool->id,
        ], 300);

        return $this->form($tool->login_url, [
            'iss' => config('app.url'), 'client_id' => $tool->client_id, 'login_hint' => $hint,
            'lti_message_hint' => $hint, 'lti_deployment_id' => $tool->deployment_id, 'target_link_uri' => $tool->redirect_uri,
        ]);
    }

    public function receiveAccountForm(Request $request)
    {
        $tenant = LearningAccess::tenantManager();
        $data = $request->input('data');
        abort_unless(is_string($data) && strlen($data) === 64, 403);
        $key = 'lti:account-form:'.hash('sha256', $data);

        return Cache::lock($key.':lock', 10)->get(function () use ($request, $tenant, $key) {
            $pending = Cache::get($key);
            abort_unless($pending && $pending['user'] === Auth::id()
                && $pending['session'] === $request->session()->getId() && $pending['tenant'] === $tenant, 403);

            $input = $request->only(['name', 'email', 'username', 'role']);
            foreach (['email', 'username'] as $field) {
                if (is_string($input[$field] ?? null)) {
                    $input[$field] = strtolower(trim($input[$field]));
                }
            }
            $validator = validator($input, [
                'name' => 'required|string|max:255', 'role' => 'required|in:teacher,student',
                'email' => 'nullable|required_without:username|email|max:255|unique:users',
                'username' => ['nullable', 'required_without:email', 'min:3', 'max:64', 'regex:/\A(?![0-9]+\z)[a-z0-9][a-z0-9._-]*\z/', 'unique:users'],
            ]);
            if ($validator->fails()) {
                return $this->accountFormResult($validator->errors()->all(), 422);
            }
            $password = (string) config('hub.initial_password');
            if (strlen($password) < 12 || strlen($password) > 72) {
                return $this->accountFormResult(['Client chưa cấu hình mật khẩu ban đầu.'], 503);
            }
            try {
                DB::table('users')->insert($validator->validated() + [
                    'tenant_id' => $tenant, 'password' => Hash::make($password), 'must_change_password' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return $this->accountFormResult(['Tên đăng nhập hoặc email đã có tài khoản.'], 409);
            }
            Cache::forget($key);
            session()->flash('status', 'Đã tạo tài khoản. Người dùng cần đổi mật khẩu khi đăng nhập lần đầu.');

            return $this->accountFormResult();
        }) ?: abort(409);
    }

    private function accountFormResult(array $messages = [], int $status = 200)
    {
        return response()->view('hub-account-result', compact('messages'), $status)->header('Cache-Control', 'no-store')
            ->header('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'; frame-ancestors 'self'; base-uri 'none'");
    }

    public function selectedContent(Request $request)
    {
        $input = $request->validate(['JWT' => 'required|string|max:131072']);
        $parts = explode('.', $input['JWT']);
        try {
            $untrusted = count($parts) === 3 ? json_decode(JWT::urlsafeB64Decode($parts[1]), true, 32, JSON_THROW_ON_ERROR) : null;
        } catch (\Throwable) {
            abort(422, 'Phản hồi chọn bài không hợp lệ.');
        }
        $selection = is_array($untrusted) ? ($untrusted[self::DEEP.'data'] ?? null) : null;
        abort_unless(is_string($selection) && strlen($selection) === 64, 403);
        $cacheKey = 'lti:selection:'.hash('sha256', $selection);

        return Cache::lock($cacheKey.':lock', 10)->get(function () use ($request, $input, $selection, $cacheKey) {
            $pending = Cache::get($cacheKey);
            abort_unless($pending && $pending['user'] === Auth::id() && $pending['session'] === $request->session()->getId(), 403, 'Phiên chọn bài đã hết hạn hoặc không thuộc tài khoản này.');
            $course = LearningAccess::manageCourse($pending['course']);
            $tool = DB::table('lti_registrations')->where('tenant_id', $course->tenant_id)->where('id', $pending['tool'])->firstOrFail();
            foreach (['client_id', 'deployment_id', 'public_key', 'redirect_uri'] as $field) {
                abort_unless($pending[$field] === $tool->$field, 409, 'Kết nối đã thay đổi. Hãy chọn bài lại.');
            }
            try {
                $claims = (array) JWT::decode($input['JWT'], new Key($tool->public_key, 'RS256'));
            } catch (\Throwable) {
                abort(403, 'Chữ ký hoặc thời hạn phản hồi của Hub không hợp lệ.');
            }
            abort_unless(($claims['iss'] ?? null) === $tool->client_id
                && in_array(config('app.url'), (array) ($claims['aud'] ?? []), true)
                && ($claims[self::LTI.'message_type'] ?? null) === 'LtiDeepLinkingResponse'
                && ($claims[self::LTI.'version'] ?? null) === '1.3.0'
                && ($claims[self::LTI.'deployment_id'] ?? null) === $tool->deployment_id
                && ($claims[self::DEEP.'data'] ?? null) === $selection
                && is_int($claims['iat'] ?? null) && $claims['iat'] <= time() + 30 && $claims['iat'] >= time() - 1800
                && is_int($claims['exp'] ?? null) && $claims['exp'] > time() && $claims['exp'] <= time() + 600, 403);
            $items = $claims[self::DEEP.'content_items'] ?? [];
            abort_unless(is_array($items) && count($items) <= 1, 422, 'Chỉ chọn một bài cho mỗi hoạt động.');
            if ($items === []) {
                $this->recordSelection($selection, $course->id);
                Cache::forget($cacheKey);

                return $this->selectionResult($course->id, $pending['embedded'] ?? false, 'Đã hủy chọn bài.');
            }
            $item = json_decode(json_encode($items[0]), true);
            abort_unless(is_array($item), 422, 'Dữ liệu bài học không hợp lệ.');
            $data = validator($item, ['type' => 'required|in:ltiResourceLink', 'title' => 'required|string|max:255', 'url' => 'required|url:https|max:2048', 'custom' => 'sometimes|array|max:30', 'custom.*' => 'string|max:2048'])->validate();
            abort_unless(LearningAccess::origin($data['url']) && LearningAccess::origin($data['url']) === LearningAccess::origin($tool->redirect_uri), 422, 'Bài được chọn phải thuộc Hub đã đăng ký.');
            DB::transaction(function () use ($course, $tool, $data, $selection) {
                $this->recordSelection($selection, $course->id);
                $id = DB::table('activities')->insertGetId([
                    'course_id' => $course->id, 'lti_registration_id' => $tool->id, 'title' => $data['title'],
                    'target_uri' => $data['url'], 'hub_custom' => json_encode($data['custom'] ?? []),
                    'resource_link_id' => (string) Str::uuid(), 'maximum' => 100, 'published' => false,
                    'sort_order' => (int) DB::table('activities')->where('course_id', $course->id)->max('sort_order') + 1,
                ]);
                DB::table('audit_logs')->insert(['event' => 'activity.hub_selected', 'user_id' => Auth::id(), 'resource_id' => $id]);
                DB::table('lti_selection_receipts')->where('state_hash', hash('sha256', $selection))->update(['activity_id' => $id]);
            });
            Cache::forget($cacheKey);

            return $this->selectionResult($course->id, $pending['embedded'] ?? false,
                'Đã thêm bài từ Hub dưới dạng nháp. Thiết lập lịch, thang điểm và giao bài trước khi xuất bản.');
        }) ?: abort(409);
    }

    public function authorize(Request $request)
    {
        $data = $request->validate([
            'login_hint' => 'required|string|size:64', 'lti_message_hint' => 'required|same:login_hint',
            'client_id' => 'required|string|max:255', 'redirect_uri' => 'required|string|max:2048',
            'state' => 'required|string|max:1024', 'nonce' => 'required|string|max:1024',
            'scope' => 'required|in:openid', 'response_type' => 'required|in:id_token',
            'response_mode' => 'required|in:form_post', 'prompt' => 'required|in:none',
        ]);
        $cacheKey = 'lti:hint:'.hash('sha256', $data['login_hint']);

        return Cache::lock($cacheKey.':lock', 10)->get(function () use ($request, $data, $cacheKey) {
            $pending = Cache::get($cacheKey);
            abort_unless($pending && $pending['user'] === Auth::id() && $pending['session'] === $request->session()->getId(), 403);
            if (isset($pending['selection'])) {
                return $this->authorizeSelection($pending, $data, $cacheKey);
            }
            if (isset($pending['account_form'])) {
                return $this->authorizeAccountForm($pending, $data, $cacheKey);
            }
            $item = $this->launchable($pending['course'], $pending['activity']);
            abort_unless($item->client_id === $data['client_id'] && $item->redirect_uri === $data['redirect_uri'], 403);
            $key = DB::table('lti_keys')->whereNull('retired_at')->latest('created_at')->first();
            abort_unless($key, 503, 'Run php artisan lti:rotate-key first.');
            abort_unless(Cache::add('lti:nonce:'.hash('sha256', $item->client_id.'|'.$data['nonce']), true, 300), 409);
            $line = $this->endpoint('lti.line', ['course' => $item->course_id, 'activity' => $item->id]);
            $claims = [
                'iss' => config('app.url'), 'sub' => (string) Auth::id(), 'aud' => $item->client_id, 'name' => Auth::user()->name,
                'iat' => time(), 'exp' => time() + 300, 'nonce' => $data['nonce'],
                self::LTI.'version' => '1.3.0', self::LTI.'message_type' => 'LtiResourceLinkRequest',
                self::LTI.'deployment_id' => $item->deployment_id, self::LTI.'target_link_uri' => $item->target_uri,
                self::LTI.'resource_link' => ['id' => $item->resource_link_id, 'title' => $item->title],
                self::LTI.'context' => ['id' => (string) $item->course_id, 'title' => $item->course_title,
                    'type' => ['http://purl.imsglobal.org/vocab/lis/v2/course#CourseOffering']],
                self::LTI.'roles' => $this->launchRoles(),
                'https://purl.imsglobal.org/spec/lti-ags/claim/endpoint' => [
                    'scope' => array_map(fn ($scope) => self::AGS.$scope, self::SCOPES),
                    'lineitem' => $line, 'lineitems' => $this->endpoint('lti.lines', ['course' => $item->course_id]),
                ],
            ];
            if ($item->hub_custom) {
                $claims[self::LTI.'custom'] = json_decode($item->hub_custom, true);
            }
            $token = JWT::encode($claims, Crypt::decryptString($key->encrypted_key), 'RS256', $key->kid);
            Cache::forget($cacheKey);
            DB::table('audit_logs')->insert(['event' => 'lti.launch', 'user_id' => Auth::id(), 'resource_id' => $item->id]);

            return $this->form($item->redirect_uri, ['id_token' => $token, 'state' => $data['state']]);
        }) ?: abort(409);
    }

    private function authorizeAccountForm(array $pending, array $data, string $cacheKey)
    {
        $tenant = LearningAccess::tenantManager();
        $tool = HubProvisioningConfig::accountTool($tenant);
        abort_unless($tool && $tenant === $pending['tenant'] && $tool->id === $pending['tool']
            && $tool->client_id === $data['client_id'] && $tool->redirect_uri === $data['redirect_uri'], 403);
        $key = DB::table('lti_keys')->whereNull('retired_at')->latest('created_at')->first();
        abort_unless($key, 503, 'Chưa có khóa ký LTI.');
        abort_unless(Cache::add('lti:nonce:'.hash('sha256', $tool->client_id.'|'.$data['nonce']), true, 300), 409);
        $formToken = Str::random(64);
        Cache::put('lti:account-form:'.hash('sha256', $formToken), [
            'user' => $pending['user'], 'tenant' => $tenant, 'session' => $pending['session'],
        ], 1800);
        $claims = [
            'iss' => config('app.url'), 'sub' => (string) Auth::id(), 'aud' => $tool->client_id, 'name' => Auth::user()->name,
            'iat' => time(), 'exp' => time() + 300, 'nonce' => $data['nonce'],
            self::LTI.'version' => '1.3.0', self::LTI.'message_type' => 'LtiDeepLinkingRequest',
            self::LTI.'deployment_id' => $tool->deployment_id, self::LTI.'target_link_uri' => $tool->redirect_uri,
            self::LTI.'roles' => $this->launchRoles(),
            self::DEEP.'deep_linking_settings' => [
                'deep_link_return_url' => $this->endpoint('lti.account.return'), 'accept_types' => ['ltiResourceLink'],
                'accept_presentation_document_targets' => ['iframe'], 'accept_multiple' => false,
                'client_account_form' => true, 'data' => $formToken,
            ],
        ];
        Cache::forget($cacheKey);

        return $this->form($tool->redirect_uri, [
            'id_token' => JWT::encode($claims, Crypt::decryptString($key->encrypted_key), 'RS256', $key->kid),
            'state' => $data['state'],
        ]);
    }

    private function recordSelection(string $selection, int $course): void
    {
        $created = DB::table('lti_selection_receipts')->insertOrIgnore([
            'state_hash' => hash('sha256', $selection), 'course_id' => $course, 'user_id' => Auth::id(),
        ]);
        abort_unless($created === 1, 409, 'Phản hồi chọn bài đã được xử lý.');
    }

    private function authorizeSelection(array $pending, array $data, string $cacheKey)
    {
        $course = LearningAccess::manageCourse($pending['course']);
        $tool = DB::table('lti_registrations')->where('id', $pending['tool'])->where('tenant_id', $course->tenant_id)->firstOrFail();
        abort_unless($tool->client_id === $data['client_id'] && $tool->redirect_uri === $data['redirect_uri'] && $tool->deep_link_url, 403);
        abort_unless(Cache::has('lti:selection:'.hash('sha256', $pending['selection'])), 403);
        abort_unless(Cache::add('lti:nonce:'.hash('sha256', $tool->client_id.'|'.$data['nonce']), true, 300), 409);
        $key = DB::table('lti_keys')->whereNull('retired_at')->latest('created_at')->first();
        abort_unless($key, 503, 'Chưa có khóa ký LTI.');
        $claims = [
            'iss' => config('app.url'), 'sub' => (string) Auth::id(), 'aud' => $tool->client_id,
            'iat' => time(), 'exp' => time() + 300, 'nonce' => $data['nonce'],
            'name' => Auth::user()->name,
            self::LTI.'version' => '1.3.0', self::LTI.'message_type' => 'LtiDeepLinkingRequest',
            self::LTI.'deployment_id' => $tool->deployment_id, self::LTI.'target_link_uri' => $tool->deep_link_url,
            self::LTI.'roles' => $this->launchRoles(),
            self::LTI.'context' => ['id' => (string) $course->id, 'title' => $course->title],
            self::DEEP.'deep_linking_settings' => [
                'deep_link_return_url' => $this->endpoint('lti.deep.return'), 'accept_types' => ['ltiResourceLink'],
                'accept_presentation_document_targets' => ['window', 'iframe'], 'accept_multiple' => false,
                'auto_create' => false, 'data' => $pending['selection'],
            ],
        ];
        Cache::forget($cacheKey);

        return $this->form($tool->redirect_uri, ['id_token' => JWT::encode($claims, Crypt::decryptString($key->encrypted_key), 'RS256', $key->kid), 'state' => $data['state']]);
    }

    public function jwks()
    {
        $keys = DB::table('lti_keys')->whereNull('retired_at')->orWhere('retired_at', '>=', now()->subDay())->get();

        return response()->json(['keys' => $keys->map(fn ($key) => [
            'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $key->kid, 'n' => $key->modulus, 'e' => $key->exponent,
        ])]);
    }

    public function token(Request $request)
    {
        $assertion = $request->input('client_assertion');
        if ($request->input('grant_type') !== 'client_credentials' ||
            $request->input('client_assertion_type') !== 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer' ||
            ! is_string($assertion) || strlen($assertion) > 8192) {
            return response()->json(['error' => 'invalid_request'], 400);
        }

        $parts = explode('.', $assertion);
        $subject = count($parts) === 3 ? json_decode(JWT::urlsafeB64Decode($parts[1])) : null;
        $tool = is_string($subject->sub ?? null) ? DB::table('lti_registrations')->where('client_id', $subject->sub)->first() : null;
        try {
            $claims = $tool ? JWT::decode($assertion, new Key($tool->public_key, 'RS256')) : null;
        } catch (\UnexpectedValueException|\InvalidArgumentException|\DomainException) {
            $claims = null;
        }
        $deployment = $claims->{self::LTI.'deployment_id'} ?? $tool?->deployment_id;
        if (! $claims || ! $tool->tenant_id || ($claims->iss ?? null) !== $tool->client_id || $claims->sub !== $tool->client_id ||
            ! in_array($this->endpoint('lti.token'), (array) ($claims->aud ?? []), true) ||
            $deployment !== $tool->deployment_id || ! is_int($claims->exp ?? null) || $claims->exp > time() + 300 ||
            ! is_string($claims->jti ?? null) || $claims->jti === '' || strlen($claims->jti) > 255) {
            return response()->json(['error' => 'invalid_client'], 401);
        }

        $scope = $request->input('scope');
        $scopes = is_string($scope) && strlen($scope) < 1024 ? preg_split('/\s+/', trim($scope)) : [];
        if (! $scopes || array_diff($scopes, array_map(fn ($s) => self::AGS.$s, self::SCOPES))) {
            return response()->json(['error' => 'invalid_scope'], 400);
        }
        if (! Cache::add('lti:jti:'.hash('sha256', $tool->id.'|'.$claims->jti), true, 300)) {
            return response()->json(['error' => 'invalid_client'], 401);
        }
        $token = Str::random(64);
        Cache::put('lti:token:'.hash('sha256', $token), [
            'tool' => $tool->id, 'tenant' => $tool->tenant_id, 'scopes' => $scopes,
        ], 300);

        return response()->json(['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 300, 'scope' => implode(' ', $scopes)])
            ->header('Cache-Control', 'no-store');
    }

    public function lines(Request $request, int $course)
    {
        $query = $this->items($request, $course, 'lineitem.readonly');
        if ($request->filled('resource_link_id')) {
            $query->where('resource_link_id', $request->validate(['resource_link_id' => 'required|uuid'])['resource_link_id']);
        }
        if ($request->filled('resource_id') || $request->filled('tag')) {
            return response()->json([], 200, ['Content-Type' => 'application/vnd.ims.lis.v2.lineitemcontainer+json']);
        }

        return response()->json($query->get()->map($this->lineData(...)), 200, ['Content-Type' => 'application/vnd.ims.lis.v2.lineitemcontainer+json']);
    }

    public function line(Request $request, int $course, int $activity)
    {
        $item = $this->items($request, $course, 'lineitem.readonly')->where('id', $activity)->firstOrFail();

        return response()->json($this->lineData($item), 200, ['Content-Type' => 'application/vnd.ims.lis.v2.lineitem+json']);
    }

    public function results(Request $request, int $course, int $activity)
    {
        $item = $this->items($request, $course, 'result.readonly')->where('id', $activity)->firstOrFail();
        $results = DB::table('grades')->where('activity_id', $activity)->orderByDesc('reported_at');
        if ($request->filled('user_id')) {
            $results->where('user_id', $request->validate(['user_id' => 'required|integer|min:1'])['user_id']);
        }
        $line = $this->lineData($item)['id'];

        $rows = $results->get()->each(fn ($grade) => $grade->grade_policy = $item->grade_policy);

        return response()->json(Gradebook::official($rows)->whereNotNull('score')->values()->map(fn ($grade) => [
            'id' => $line.'/results?user_id='.$grade->user_id, 'scoreOf' => $line,
            'userId' => (string) $grade->user_id, 'resultScore' => (float) $grade->score, 'resultMaximum' => $item->maximum,
        ]), 200, ['Content-Type' => 'application/vnd.ims.lis.v2.resultcontainer+json']);
    }

    public function score(Request $request, int $course, int $activity)
    {
        $items = $this->items($request, $course, 'score')->where('id', $activity);
        $data = $request->validate([
            'userId' => 'required|integer|min:1', 'timestamp' => 'required|date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s.vP,Y-m-d\TH:i:s.uP,Y-m-d\TH:i:s\Z,Y-m-d\TH:i:s.v\Z,Y-m-d\TH:i:s.u\Z',
            'scoreGiven' => 'nullable|numeric|min:0|max:1000000000', 'scoreMaximum' => 'required_with:scoreGiven|numeric|gt:0|max:1000000000',
            'activityProgress' => 'required|in:Initialized,Started,InProgress,Submitted,Completed',
            'gradingProgress' => 'required|in:FullyGraded,Pending,PendingManual,Failed,NotReady',
        ]);
        DB::transaction(function () use ($items, $course, $activity, $data) {
            $item = $items->lockForUpdate()->firstOrFail();
            abort_unless(DB::table('enrollments')->join('users', 'users.id', '=', 'enrollments.user_id')->where('course_id', $course)->where('user_id', $data['userId'])->where('users.role', 'student')->where('users.locked', false)->where('users.tenant_id', DB::table('courses')->where('id', $course)->value('tenant_id'))->exists(), 404);
            abort_unless(DB::table('activity_assignments')->where('activity_id', $activity)->where('user_id', $data['userId'])->exists(), 404);
            $timestamp = Carbon::parse($data['timestamp'])->utc();
            abort_if($timestamp->gt(now()->addMinutes(5)), 422);
            abort_if(($item->opens_at && $timestamp->lt($item->opens_at)) || ($item->closes_at && $timestamp->gte($item->closes_at)), 422, 'Kết quả nằm ngoài thời gian mở bài.');
            abort_if(isset($data['scoreGiven'], $data['scoreMaximum']) && $data['scoreGiven'] > $data['scoreMaximum'], 422);
            $score = $data['gradingProgress'] === 'FullyGraded' && isset($data['scoreGiven'])
                ? round(($data['scoreGiven'] / $data['scoreMaximum']) * $item->maximum, 4) : null;
            abort_if($score !== null && $score >= 100000000, 422);
            $inserted = DB::table('grades')->insertOrIgnore([
                'activity_id' => $activity, 'user_id' => $data['userId'],
                'score' => $score, 'reported_at' => $timestamp->format('Y-m-d H:i:s.uP'),
                'activity_progress' => $data['activityProgress'],
            ]);
            // A tool may finish grading the same event after reporting progress.
            // Fill the pending score without allowing retries to overwrite a grade.
            if (! $inserted && $score !== null) {
                $inserted = DB::table('grades')->where('activity_id', $activity)->where('user_id', $data['userId'])
                    ->where('reported_at', $timestamp->format('Y-m-d H:i:s.uP'))->whereNull('score')
                    ->update(['score' => $score, 'activity_progress' => $data['activityProgress']]);
            }
            if ($inserted) {
                DB::table('audit_logs')->insert(['event' => 'ags.score', 'user_id' => $data['userId'], 'resource_id' => $activity]);
            }
        });

        return response()->noContent();
    }

    private function items(Request $request, int $course, string $scope)
    {
        $token = $request->bearerToken();
        $grant = $token ? Cache::get('lti:token:'.hash('sha256', $token)) : null;
        abort_unless($grant, 401, '', ['WWW-Authenticate' => 'Bearer']);
        abort_unless(in_array(self::AGS.$scope, $grant['scopes'], true), 403);

        $tenant = DB::table('lti_registrations')->where('id', $grant['tool'])->value('tenant_id');
        abort_unless($tenant && $tenant === ($grant['tenant'] ?? $tenant), 401, '', ['WWW-Authenticate' => 'Bearer']);
        abort_unless(DB::table('courses')->where('id', $course)->where('tenant_id', $tenant)->exists(), 404);

        return DB::table('activities')->where('course_id', $course)->where('lti_registration_id', $grant['tool'])->where('published', true)->whereNull('archived_at');
    }

    private function lineData(object $item): array
    {
        return [
            'id' => $this->endpoint('lti.line', ['course' => $item->course_id, 'activity' => $item->id]),
            'label' => $item->title, 'scoreMaximum' => (int) $item->maximum, 'resourceLinkId' => $item->resource_link_id,
        ];
    }

    private function endpoint(string $name, array $parameters = []): string
    {
        return rtrim(config('app.url'), '/').route($name, $parameters, false);
    }

    private function launchRoles(): array
    {
        $roles = match (Auth::user()->role) {
            'tenant_admin' => ['Administrator', 'Instructor'],
            'teacher' => ['Instructor'],
            'student' => ['Learner'],
        };

        return array_map(fn (string $role) => 'http://purl.imsglobal.org/vocab/lis/v2/membership#'.$role, $roles);
    }

    private function selectionResult(int $course, bool $embedded, string $message)
    {
        $destination = '/courses/'.$course.'/activities';
        if (! $embedded) {
            return redirect($destination)->with('status', $message);
        }

        session()->flash('status', $message);

        return response()->view('hub-selection-result', compact('destination', 'message'))
            ->header('Cache-Control', 'no-store')
            ->header('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'; frame-ancestors 'self'; base-uri 'none'");
    }

    private function launchable(int $course, int $activity, bool $checkWindow = true): object
    {
        abort_if(Auth::user()?->role === 'admin', 403);
        $context = LmsController::courses()->where('id', $course)->firstOrFail();

        $item = DB::table('activities')->join('lti_registrations as tool', 'tool.id', '=', 'activities.lti_registration_id')
            ->where('activities.id', $activity)->where('course_id', $course)->where('tool.tenant_id', $context->tenant_id)->where('published', true)->whereNull('archived_at')
            ->when(Auth::user()->role === 'student', fn ($query) => $query->whereIn('activities.id', LmsController::assignedActivities(Auth::id())))
            ->select('activities.*', 'tool.login_url', 'tool.client_id', 'tool.deployment_id', 'tool.redirect_uri')
            ->selectRaw('? as course_title', [$context->title])->firstOrFail();
        abort_if($checkWindow && Auth::user()->role === 'student' && ! LearningAccess::available($item), 403,
            LearningAccess::availability($item) === 'scheduled' ? 'Bài học chưa đến giờ mở.' : 'Bài học đã đóng.');

        return $item;
    }

    private function form(string $action, array $fields)
    {
        $nonce = Str::random(32);

        return response()->view('lti-post', compact('action', 'fields', 'nonce'))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'; base-uri 'none'; script-src 'nonce-$nonce'; form-action https:");
    }
}
