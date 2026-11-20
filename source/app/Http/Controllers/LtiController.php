<?php

namespace App\Http\Controllers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LtiController extends Controller
{
    private const LTI = 'https://purl.imsglobal.org/spec/lti/claim/';
    private const AGS = 'https://purl.imsglobal.org/spec/lti-ags/scope/';
    private const SCOPES = ['score', 'lineitem.readonly', 'result.readonly'];

    public function learn(int $course, int $activity)
    {
        $item = $this->launchable($course, $activity);

        return response()->view('learn', ['item' => $item])->header('Cache-Control', 'private, no-store');
    }

    public function learningStatus(int $course, int $activity)
    {
        $item = $this->launchable($course, $activity);
        $grade = DB::table('grades')->where('activity_id', $activity)->where('user_id', Auth::id())
            ->orderByDesc('reported_at')->first();

        return response()->json([
            'completed' => $grade?->activity_progress === 'Completed',
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
            $item = $this->launchable($pending['course'], $pending['activity']);
            abort_unless($item->client_id === $data['client_id'] && $item->redirect_uri === $data['redirect_uri'], 403);
            $key = DB::table('lti_keys')->whereNull('retired_at')->latest('created_at')->first();
            abort_unless($key, 503, 'Run php artisan lti:rotate-key first.');
            abort_unless(Cache::add('lti:nonce:'.hash('sha256', $item->client_id.'|'.$data['nonce']), true, 300), 409);
            $line = $this->endpoint('lti.line', ['course' => $item->course_id, 'activity' => $item->id]);
            $claims = [
                'iss' => config('app.url'), 'sub' => (string) Auth::id(), 'aud' => $item->client_id,
                'iat' => time(), 'exp' => time() + 300, 'nonce' => $data['nonce'],
                self::LTI.'version' => '1.3.0', self::LTI.'message_type' => 'LtiResourceLinkRequest',
                self::LTI.'deployment_id' => $item->deployment_id, self::LTI.'target_link_uri' => $item->target_uri,
                self::LTI.'resource_link' => ['id' => $item->resource_link_id, 'title' => $item->title],
                self::LTI.'context' => ['id' => (string) $item->course_id, 'title' => $item->course_title,
                    'type' => ['http://purl.imsglobal.org/vocab/lis/v2/course#CourseOffering']],
                self::LTI.'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#'.(Auth::user()->role === 'student' ? 'Learner' : 'Instructor')],
                'https://purl.imsglobal.org/spec/lti-ags/claim/endpoint' => [
                    'scope' => array_map(fn ($scope) => self::AGS.$scope, self::SCOPES),
                    'lineitem' => $line, 'lineitems' => $this->endpoint('lti.lines', ['course' => $item->course_id]),
                ],
            ];
            $token = JWT::encode($claims, Crypt::decryptString($key->encrypted_key), 'RS256', $key->kid);
            Cache::forget($cacheKey);
            DB::table('audit_logs')->insert(['event' => 'lti.launch', 'user_id' => Auth::id(), 'resource_id' => $item->id]);

            return $this->form($item->redirect_uri, ['id_token' => $token, 'state' => $data['state']]);
        }) ?: abort(409);
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
        } catch (\UnexpectedValueException | \InvalidArgumentException | \DomainException) {
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
        $results = DB::table('grades')->where('activity_id', $activity)->whereNotNull('score')
            ->whereNotExists(function ($query) {
                $query->from('grades as newer')
                    ->whereColumn('newer.activity_id', 'grades.activity_id')
                    ->whereColumn('newer.user_id', 'grades.user_id')
                    ->whereColumn('newer.reported_at', '>', 'grades.reported_at');
            });
        if ($request->filled('user_id')) {
            $results->where('user_id', $request->validate(['user_id' => 'required|integer|min:1'])['user_id']);
        }
        $line = $this->lineData($item)['id'];

        return response()->json($results->get()->map(fn ($grade) => [
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
            abort_unless(DB::table('enrollments')->where('course_id', $course)->where('user_id', $data['userId'])->exists(), 404);
            abort_unless(DB::table('activity_assignments')->where('activity_id', $activity)->where('user_id', $data['userId'])->exists(), 404);
            $timestamp = \Illuminate\Support\Carbon::parse($data['timestamp'])->utc();
            abort_if($timestamp->gt(now()->addMinutes(5)), 422);
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

        return DB::table('activities')->where('course_id', $course)->where('lti_registration_id', $grant['tool'])->where('published', true);
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

    private function launchable(int $course, int $activity): object
    {
        abort_if(Auth::user()?->role === 'admin', 403);
        $context = LmsController::courses()->where('id', $course)->firstOrFail();

        return DB::table('activities')->join('lti_registrations as tool', 'tool.id', '=', 'activities.lti_registration_id')
            ->where('activities.id', $activity)->where('course_id', $course)->where('tool.tenant_id', $context->tenant_id)->where('published', true)
            ->when(Auth::user()->role === 'student', fn ($query) => $query->whereIn('activities.id', LmsController::assignedActivities(Auth::id())))
            ->select('activities.*', 'tool.login_url', 'tool.client_id', 'tool.deployment_id', 'tool.redirect_uri')
            ->selectRaw('? as course_title', [$context->title])->firstOrFail();
    }

    private function form(string $action, array $fields)
    {
        $nonce = Str::random(32);

        return response()->view('lti-post', compact('action', 'fields', 'nonce'))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'; base-uri 'none'; script-src 'nonce-$nonce'; form-action https:");
    }
}
