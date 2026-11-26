<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Gradebook;
use Database\Seeders\ResetClientLearningDataSeeder;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LearningManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $teacher;

    private User $student;

    private User $foreign;

    private static string $privateKey = '';

    private static string $publicKey = '';

    private const LTI = 'https://purl.imsglobal.org/spec/lti/claim/';

    private const DEEP = 'https://purl.imsglobal.org/spec/lti-dl/claim/';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, self::$privateKey);
        self::$publicKey = openssl_pkey_get_details($key)['key'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://client.example', 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        DB::table('tenants')->insert([['id' => 1, 'name' => 'School A'], ['id' => 2, 'name' => 'School B']]);
        $this->manager = User::factory()->create(['role' => 'tenant_admin', 'tenant_id' => 1]);
        $this->teacher = User::factory()->create(['role' => 'teacher', 'tenant_id' => 1]);
        $this->student = User::factory()->create(['role' => 'student', 'tenant_id' => 1]);
        $this->foreign = User::factory()->create(['role' => 'student', 'tenant_id' => 2]);
        DB::table('courses')->insert(['id' => 1, 'tenant_id' => 1, 'teacher_id' => $this->teacher->id, 'title' => 'Course A']);
        DB::table('lti_registrations')->insert([
            'id' => 1, 'tenant_id' => 1, 'name' => 'Hub A', 'client_id' => 'hub-a', 'deployment_id' => 'deployment-a',
            'login_url' => 'https://hub.example/lti/login', 'redirect_uri' => 'https://hub.example/lti/launch',
            'deep_link_url' => 'https://hub.example/lti/launch', 'public_key' => self::$publicKey,
        ]);
        DB::table('activities')->insert([
            'id' => 1, 'course_id' => 1, 'lti_registration_id' => 1, 'title' => 'Lesson A', 'target_uri' => 'https://hub.example/lti/launch?content_id=one',
            'resource_link_id' => 'one', 'maximum' => 10, 'published' => true,
        ]);
        DB::table('enrollments')->insert(['course_id' => 1, 'user_id' => $this->student->id]);
        DB::table('activity_assignments')->insert(['activity_id' => 1, 'user_id' => $this->student->id]);
        $this->actingAs($this->manager);
    }

    private function classroom(int $tenant = 1): int
    {
        return DB::table('classrooms')->insertGetId(['tenant_id' => $tenant, 'name' => 'Class '.$tenant]);
    }

    private function activityData(array $changes = []): array
    {
        return array_replace(['title' => 'Lesson A', 'lti_registration_id' => 1, 'target_uri' => 'https://hub.example/lti/launch?content_id=one', 'maximum' => 10, 'published' => 1], $changes);
    }

    public function test_manager_classroom_members_and_course_enrollment_are_tenant_scoped(): void
    {
        $this->post('/classrooms', ['name' => 'Class A'])->assertRedirect();
        $class = DB::table('classrooms')->value('id');
        $this->put('/classrooms/'.$class.'/members', ['user_ids' => [$this->student->id]])->assertRedirect();
        $this->putJson('/classrooms/'.$class.'/members', ['user_ids' => [$this->foreign->id]])->assertUnprocessable();
        $this->assertDatabaseHas('classroom_members', ['classroom_id' => $class, 'user_id' => $this->student->id]);
        $this->actingAs($this->teacher)->post('/courses/1/classrooms', ['classroom_id' => $class])->assertRedirect();
        $this->assertDatabaseHas('course_classrooms', ['course_id' => 1, 'classroom_id' => $class]);
        $this->post('/courses/1/classrooms', ['classroom_id' => $this->classroom(2)])->assertNotFound();
        $this->get('/classrooms')->assertForbidden();
    }

    public function test_bulk_grants_are_snapshot_based_and_individual_revocation_is_preserved_until_cleared(): void
    {
        $class = $this->classroom();
        DB::table('classroom_members')->insert(['classroom_id' => $class, 'user_id' => $this->student->id]);
        $this->post('/courses/1/classrooms', ['classroom_id' => $class])->assertRedirect();
        $data = ['activity_id' => 1, 'audience' => 'classroom', 'classroom_id' => $class, 'granted' => 1];
        $this->post('/courses/1/assign-bulk', $data)->assertRedirect();
        $new = User::factory()->create(['tenant_id' => 1, 'role' => 'student']);
        DB::table('classroom_members')->insert(['classroom_id' => $class, 'user_id' => $new->id]);
        $this->assertDatabaseMissing('enrollments', ['course_id' => 1, 'user_id' => $new->id]);
        $this->post('/courses/1/classrooms', ['classroom_id' => $class]);
        $this->assertDatabaseMissing('activity_assignments', ['activity_id' => 1, 'user_id' => $new->id]);
        $this->post('/courses/1/assign-bulk', $data);
        $this->assertDatabaseHas('activity_assignments', ['activity_id' => 1, 'user_id' => $new->id]);
        $this->post('/courses/1/assign', ['activity_id' => 1, 'user_id' => $new->id, 'granted' => 0]);
        $this->assertDatabaseMissing('activity_assignments', ['activity_id' => 1, 'user_id' => $new->id]);
        $this->post('/courses/1/assign-bulk', $data + ['unused' => true]);
        $this->assertDatabaseMissing('activity_assignments', ['activity_id' => 1, 'user_id' => $new->id]);
        $this->post('/courses/1/assign', ['activity_id' => 1, 'user_id' => $new->id, 'mode' => 'inherit'])->assertRedirect();
        $this->assertDatabaseHas('activity_assignments', ['activity_id' => 1, 'user_id' => $new->id]);
        $this->post('/courses/1/assign-bulk', array_replace($data, ['granted' => 0]));
        $this->assertDatabaseCount('activity_assignments', 0);
    }

    public function test_coteacher_access_can_be_revoked_and_does_not_allow_managing_teachers_or_deleting_course(): void
    {
        $co = User::factory()->create(['tenant_id' => 1, 'role' => 'teacher']);
        $this->post('/courses/1/teachers', ['user_id' => $co->id, 'granted' => 1])->assertRedirect();
        $this->actingAs($co)->get('/courses/1/activities')->assertOk();
        $this->post('/courses/1/teachers', ['user_id' => $this->teacher->id, 'granted' => 1])->assertForbidden();
        $this->delete('/courses/1')->assertForbidden();
        $this->actingAs($this->manager)->post('/courses/1/teachers', ['user_id' => $co->id, 'granted' => 0]);
        $this->actingAs($co)->get('/courses/1/activities')->assertNotFound();
    }

    public function test_activity_schedule_is_validated_in_vietnam_time_and_enforced_for_students(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 25)->setTime(2, 0));
        $this->put('/courses/1/activities/1', $this->activityData(['opens_at' => '2026-11-25T10:00', 'due_at' => '2026-11-25T11:00', 'closes_at' => '2026-11-25T12:00']))->assertRedirect();
        $this->assertSame('2026-11-25 03:00:00', DB::table('activities')->value('opens_at'));
        $this->actingAs($this->student)->get('/courses/1/activities/1/learn')->assertForbidden();
        $this->travel(2)->hours();
        $this->get('/courses/1/activities/1/learn')->assertOk()->assertSee('Mở bài ở tab mới');
        $this->travel(2)->hours();
        $this->post('/courses/1/activities/1/launch')->assertForbidden();
        $this->get('/courses/1/activities/1/status')->assertOk();
        $this->actingAs($this->manager)->putJson('/courses/1/activities/1', $this->activityData(['opens_at' => '2026-11-25T12:00', 'closes_at' => '2026-11-25T10:00']))->assertUnprocessable();
    }

    public function test_used_activity_keeps_original_binding_and_scale_and_archive_keeps_grades(): void
    {
        DB::table('grades')->insert(['activity_id' => 1, 'user_id' => $this->student->id, 'score' => 8, 'reported_at' => now()]);
        $this->putJson('/courses/1/activities/1', $this->activityData(['maximum' => 100]))->assertUnprocessable();
        $this->putJson('/courses/1/activities/1', $this->activityData(['target_uri' => 'https://hub.example/lti/launch?content_id=two']))->assertUnprocessable();
        $this->post('/courses/1/activities/1/archive', ['archived' => 1])->assertRedirect();
        $this->postJson('/courses/1/activities/1', ['published' => 1])->assertUnprocessable();
        $this->actingAs($this->student)->get('/courses/1/activities/1/learn')->assertNotFound();
        $this->actingAs($this->manager)->deleteJson('/courses/1')->assertUnprocessable();
        $this->assertDatabaseCount('grades', 1);
        $this->post('/courses/1/activities/1/archive', ['archived' => 0]);
        $this->assertDatabaseHas('activities', ['id' => 1, 'archived_at' => null, 'published' => false]);
    }

    public function test_grade_policy_keeps_pending_progress_separate_and_reports_are_scoped(): void
    {
        foreach ([[8, '2026-11-25 01:00:00', 'Completed'], [3, '2026-11-25 02:00:00', 'Completed'], [null, '2026-11-25 03:00:00', 'InProgress']] as [$score, $at, $progress]) {
            DB::table('grades')->insert(['activity_id' => 1, 'user_id' => $this->student->id, 'score' => $score, 'reported_at' => $at, 'activity_progress' => $progress]);
        }
        $official = Gradebook::official(Gradebook::records(1)->get())->first();
        $this->assertSame(3.0, (float) $official->score);
        $this->assertTrue($official->completed);
        DB::table('activities')->where('id', 1)->update(['grade_policy' => 'highest']);
        $this->assertSame(8.0, (float) Gradebook::official(Gradebook::records(1)->get())->first()->score);
        $this->get('/courses/1/grades')->assertOk()->assertSee('Sổ điểm chính thức');
        $this->get('/courses/1/report.csv')->assertOk()->assertDownload('grades-1.csv');
        $this->actingAs($this->student)->getJson('/courses/1/activities/1/status')->assertJson(['score' => 8, 'completed' => true]);
        $this->get('/courses/1/grades?classroom_id=1')->assertForbidden();
        $this->actingAs($this->foreign)->get('/courses/1/report.csv')->assertNotFound();
    }

    public function test_csv_report_protects_spreadsheet_formulas(): void
    {
        DB::table('users')->where('id', $this->student->id)->update(['name' => '=1+1']);
        DB::table('grades')->insert(['activity_id' => 1, 'user_id' => $this->student->id, 'score' => 8, 'reported_at' => now()]);
        $this->assertStringContainsString("'=1+1", $this->get('/courses/1/report.csv')->streamedContent());
        $this->assertStringContainsString("'=1+1", $this->get('/courses/1/roster.csv')->streamedContent());
        $this->actingAs($this->student)->get('/courses/1/roster.csv')->assertForbidden();
        $this->actingAs($this->foreign)->get('/courses/1/roster.csv')->assertForbidden();
    }

    public function test_password_reset_revokes_sessions_and_cannot_cross_tenant(): void
    {
        DB::table('sessions')->insert(['id' => 'old', 'user_id' => $this->student->id, 'payload' => '', 'last_activity' => time()]);
        $data = ['password' => 'New-password-1234', 'password_confirmation' => 'New-password-1234'];
        $this->post('/admin/users/'.$this->student->id.'/password', $data)->assertRedirect();
        $this->assertDatabaseMissing('sessions', ['id' => 'old']);
        $this->assertTrue(Hash::check($data['password'], $this->student->fresh()->password));
        $this->post('/admin/users/'.$this->foreign->id.'/password', $data)->assertNotFound();
        $this->putJson('/admin/users/'.$this->student->id, ['name' => 'Student', 'email' => strtoupper($this->manager->email)])->assertUnprocessable();
    }

    public function test_student_csv_import_is_atomic_and_never_updates_existing_accounts(): void
    {
        $bad = "name,email,password\nFirst,first@example.com,Secure-pass-1234\nOther,".$this->student->email.",Secure-pass-1234\n";
        $this->post('/admin/users/import', ['csv' => UploadedFile::fake()->createWithContent('students.csv', $bad)])->assertSessionHasErrors('csv');
        $this->assertDatabaseMissing('users', ['email' => 'first@example.com']);
        $good = "name,email,password\nFirst,first@example.com,Secure-pass-1234\n";
        $this->post('/admin/users/import', ['csv' => UploadedFile::fake()->createWithContent('students.csv', $good)])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'first@example.com', 'tenant_id' => 1, 'role' => 'student']);
    }

    private function selection(bool $embedded = false): array
    {
        DB::table('lti_keys')->insertOrIgnore(['kid' => 'test', 'encrypted_key' => Crypt::encryptString(self::$privateKey), 'modulus' => '', 'exponent' => '']);
        $response = $this->post('/courses/1/hub/select', ['lti_registration_id' => 1, 'embedded' => $embedded])->assertOk();
        $fields = $response->viewData('fields');
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());
        $auth = $this->get('/lti/authorize?'.http_build_query([
            'login_hint' => $fields['login_hint'], 'lti_message_hint' => $fields['lti_message_hint'], 'client_id' => 'hub-a',
            'redirect_uri' => 'https://hub.example/lti/launch', 'state' => 'hub-state', 'nonce' => bin2hex(random_bytes(24)),
            'scope' => 'openid', 'response_type' => 'id_token', 'response_mode' => 'form_post', 'prompt' => 'none',
        ]))->assertOk();
        $claims = (array) JWT::decode($auth->viewData('fields')['id_token'], new Key(self::$publicKey, 'RS256'));
        $this->assertSame('LtiDeepLinkingRequest', $claims[self::LTI.'message_type']);
        $this->assertSame((string) auth()->id(), $claims['sub']);
        $this->assertSame('deployment-a', $claims[self::LTI.'deployment_id']);
        $this->assertContains('http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor', $claims[self::LTI.'roles']);
        $this->assertSame(auth()->user()->role === 'tenant_admin', in_array('http://purl.imsglobal.org/vocab/lis/v2/membership#Administrator', $claims[self::LTI.'roles'], true));
        $this->assertArrayNotHasKey('password', $claims);
        $settings = (array) $claims[self::DEEP.'deep_linking_settings'];
        $this->assertSame('https://client.example/lti/deep-link/return', $settings['deep_link_return_url']);

        return [
            'iss' => 'hub-a', 'aud' => 'https://client.example', 'iat' => time(), 'exp' => time() + 300,
            self::LTI.'deployment_id' => 'deployment-a', self::LTI.'version' => '1.3.0',
            self::LTI.'message_type' => 'LtiDeepLinkingResponse', self::DEEP.'data' => $settings['data'],
            self::DEEP.'content_items' => [['type' => 'ltiResourceLink', 'title' => 'Selected from Hub', 'url' => 'https://hub.example/lti/launch?content_id=two', 'custom' => ['content_id' => 'two']]],
        ];
    }

    private function returnSelection(array $claims)
    {
        return $this->post('/lti/deep-link/return', ['JWT' => JWT::encode($claims, self::$privateKey, 'RS256', 'hub-key')]);
    }

    public function test_signed_hub_selection_creates_one_draft_with_custom_claims_and_replay_is_rejected(): void
    {
        $claims = $this->selection();
        $this->returnSelection($claims)->assertRedirect('/courses/1/activities');
        $this->assertDatabaseHas('activities', ['title' => 'Selected from Hub', 'published' => false, 'maximum' => 100]);
        $this->assertSame(['content_id' => 'two'], json_decode(DB::table('activities')->where('title', 'Selected from Hub')->value('hub_custom'), true));
        $this->returnSelection($claims)->assertForbidden();
        $this->assertDatabaseCount('activities', 2);
    }

    public function test_teacher_selects_a_hub_activity_inside_the_client_and_learner_cannot_select(): void
    {
        $this->actingAs($this->student)->post('/courses/1/hub/select', ['lti_registration_id' => 1])->assertForbidden();
        $this->actingAs($this->teacher)->get('/courses/1/activities')->assertOk()
            ->assertSee('target="hub-picker"', false)->assertSee('<iframe name="hub-picker"', false);
        $this->returnSelection($this->selection(true))->assertOk()
            ->assertViewIs('hub-selection-result')->assertSessionHas('status');
        $this->assertDatabaseHas('activities', ['title' => 'Selected from Hub', 'published' => false]);
    }

    public function test_reset_seed_keeps_provisioning_and_clears_learning_data_idempotently(): void
    {
        $this->classroom();
        $this->selection();
        DB::table('grades')->insert(['activity_id' => 1, 'user_id' => $this->student->id, 'score' => 7, 'reported_at' => now()]);
        DB::table('sessions')->insert(['id' => 'learner', 'user_id' => $this->student->id, 'payload' => '', 'last_activity' => time()]);
        $this->seed(ResetClientLearningDataSeeder::class);
        $this->seed(ResetClientLearningDataSeeder::class);

        foreach (['courses', 'activities', 'grades', 'enrollments', 'activity_assignments', 'classrooms', 'sessions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $this->manager->id, 'role' => 'tenant_admin']);
        $this->assertDatabaseCount('tenants', 2);
        $this->assertDatabaseCount('lti_registrations', 1);
        $this->assertDatabaseCount('lti_keys', 1);
    }

    public function test_reset_seed_rejects_production(): void
    {
        $this->app->instance('env', 'production');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('runs only in local or testing environments');
        (new ResetClientLearningDataSeeder)->run();
    }

    public function test_hub_return_rejects_wrong_audience_deployment_target_signature_and_expiry(): void
    {
        $claims = $this->selection();
        foreach ([['aud' => 'https://other.example'], [self::LTI.'deployment_id' => 'other'], ['exp' => time() - 10], [self::DEEP.'content_items' => [42]], [self::DEEP.'content_items' => [['type' => 'ltiResourceLink', 'title' => 'Bad', 'url' => 'https://evil.example/launch']]]] as $changes) {
            $response = $this->returnSelection(array_replace($claims, $changes));
            $this->assertContains($response->status(), [403, 422]);
        }
        $otherKey = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($otherKey, $otherPrivate);
        $this->post('/lti/deep-link/return', ['JWT' => JWT::encode($claims, $otherPrivate, 'RS256')])->assertForbidden();
        $this->assertDatabaseCount('activities', 1);
        $this->returnSelection($claims)->assertRedirect();
    }

    public function test_hub_return_requires_original_user_and_session_and_cancellation_creates_no_activity(): void
    {
        $claims = $this->selection();
        $this->actingAs($this->teacher);
        $this->returnSelection($claims)->assertForbidden();
        $this->actingAs($this->manager);
        $cookie = $this->app['session']->getId();
        $this->withCookie(config('session.cookie'), 'different-browser-session-12345678901234567890');
        $this->returnSelection($claims)->assertForbidden();
        $this->withCookie(config('session.cookie'), $cookie);
        $claims[self::DEEP.'content_items'] = [];
        $this->returnSelection($claims)->assertRedirect('/courses/1/activities');
        $this->assertDatabaseCount('activities', 1);
        $this->returnSelection($claims)->assertForbidden();
    }

    public function test_selected_custom_content_survives_a_resource_link_launch(): void
    {
        $claims = $this->selection();
        $this->returnSelection($claims)->assertRedirect();
        $activity = DB::table('activities')->where('title', 'Selected from Hub')->first();
        DB::table('activities')->where('id', $activity->id)->update(['published' => true]);
        DB::table('activity_assignments')->insert(['activity_id' => $activity->id, 'user_id' => $this->student->id]);
        $this->actingAs($this->student);
        $start = $this->post('/courses/1/activities/'.$activity->id.'/launch')->assertOk()->viewData('fields');
        $response = $this->get('/lti/authorize?'.http_build_query([
            'login_hint' => $start['login_hint'], 'lti_message_hint' => $start['lti_message_hint'],
            'client_id' => 'hub-a', 'redirect_uri' => 'https://hub.example/lti/launch', 'state' => 'resource-state',
            'nonce' => 'resource-nonce', 'scope' => 'openid', 'response_type' => 'id_token', 'response_mode' => 'form_post', 'prompt' => 'none',
        ]))->assertOk();
        $token = JWT::decode($response->viewData('fields')['id_token'], new Key(self::$publicKey, 'RS256'));
        $this->assertSame('LtiResourceLinkRequest', $token->{self::LTI.'message_type'});
        $this->assertSame('two', $token->{self::LTI.'custom'}->content_id);
        $this->assertSame((string) $this->student->id, $token->sub);
        $this->assertSame(['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'], $token->{self::LTI.'roles'});
        $this->assertObjectNotHasProperty('password', $token);
    }

    public function test_student_cannot_select_hub_or_manage_classes_and_locked_users_cannot_receive_scores(): void
    {
        $this->actingAs($this->student)->post('/courses/1/hub/select', ['lti_registration_id' => 1])->assertForbidden();
        $this->post('/classrooms', ['name' => 'No'])->assertForbidden();
        DB::table('users')->where('id', $this->student->id)->update(['locked' => true]);
        Cache::put('lti:token:'.hash('sha256', 'token'), ['tool' => 1, 'tenant' => 1, 'scopes' => ['https://purl.imsglobal.org/spec/lti-ags/scope/score']], 60);
        $this->withToken('token')->postJson('/lti/courses/1/lineitems/1/scores', [
            'userId' => $this->student->id, 'timestamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'scoreGiven' => 8, 'scoreMaximum' => 10,
            'activityProgress' => 'Completed', 'gradingProgress' => 'FullyGraded',
        ])->assertNotFound();
        $this->assertDatabaseCount('grades', 0);
    }

    public function test_management_views_render_without_exposing_foreign_tenant(): void
    {
        $this->classroom();
        foreach (['/classrooms', '/courses/1/activities', '/courses/1/enrollment', '/courses/1/settings', '/courses/1/grades', '/admin/tenants', '/admin/users', '/admin/tools', '/admin/logs', '/reports', '/account/profile', '/admin/tools/1/check'] as $path) {
            $this->get($path)->assertOk()->assertDontSee($this->foreign->email);
        }
    }

    public function test_explicit_grant_survives_bulk_revocation_and_inheritance_uses_the_latest_batch(): void
    {
        $key = ['activity_id' => 1, 'user_id' => $this->student->id];
        $this->post('/courses/1/assign', $key + ['granted' => 1])->assertRedirect();
        $this->post('/courses/1/assign-bulk', ['activity_id' => 1, 'audience' => 'course', 'granted' => 0])->assertRedirect();
        $this->assertDatabaseHas('activity_assignments', $key);
        $this->assertDatabaseHas('activity_assignment_overrides', $key + ['granted' => true, 'baseline_granted' => false]);
        $this->post('/courses/1/assign', $key + ['mode' => 'inherit'])->assertRedirect();
        $this->assertDatabaseMissing('activity_assignments', $key);
        $this->assertDatabaseMissing('activity_assignment_overrides', $key);
    }

    public function test_unenrollment_clears_personal_exceptions_but_keeps_grade_history(): void
    {
        $key = ['activity_id' => 1, 'user_id' => $this->student->id];
        $this->post('/courses/1/assign', $key + ['granted' => 0]);
        DB::table('grades')->insert($key + ['score' => 8, 'reported_at' => now()]);
        $this->delete('/courses/1/enroll/'.$this->student->id)->assertRedirect();
        $this->assertDatabaseMissing('activity_assignment_overrides', $key);
        $this->assertDatabaseCount('grades', 1);
        $this->assertCount(0, Gradebook::overview(1));
        $this->assertCount(1, Gradebook::records(1)->get());
    }

    public function test_official_gradebook_lists_assigned_students_with_no_result_and_filters_csv(): void
    {
        $reported = User::factory()->create(['name' => 'Reported Learner', 'tenant_id' => 1, 'role' => 'student']);
        DB::table('enrollments')->insert(['course_id' => 1, 'user_id' => $reported->id]);
        DB::table('activity_assignments')->insert(['activity_id' => 1, 'user_id' => $reported->id]);
        DB::table('grades')->insert(['activity_id' => 1, 'user_id' => $reported->id, 'score' => 7, 'reported_at' => now(), 'activity_progress' => 'Completed']);
        $rows = Gradebook::overview(1);
        $this->assertCount(2, $rows);
        $this->assertFalse($rows->firstWhere('user_id', $this->student->id)->has_result);
        $this->assertNull($rows->firstWhere('user_id', $this->student->id)->reported_at);
        $this->get('/courses/1/grades?result_status=not_reported')->assertOk()->assertSee($this->student->name)->assertDontSee('Reported Learner');
        $csv = $this->get('/courses/1/report.csv?result_status=not_reported')->streamedContent();
        $this->assertStringContainsString('Chưa nhận kết quả', $csv);
        $this->assertStringNotContainsString('Reported Learner', $csv);
        $this->actingAs($reported)->get('/courses/1/grades')->assertOk()->assertDontSee($this->student->name);
    }

    public function test_transfer_teacher_revokes_previous_owner_without_changing_learning_data(): void
    {
        $next = User::factory()->create(['role' => 'teacher', 'tenant_id' => 1]);
        $co = User::factory()->create(['role' => 'teacher', 'tenant_id' => 1]);
        DB::table('course_teachers')->insert(['course_id' => 1, 'user_id' => $co->id]);
        $this->actingAs($co)->post('/courses/1/teacher-transfer', ['teacher_id' => $next->id])->assertForbidden();
        $this->actingAs($this->teacher)->post('/courses/1/teacher-transfer', ['teacher_id' => $next->id])->assertRedirect('/');
        $this->get('/courses/1')->assertNotFound();
        $this->assertDatabaseHas('courses', ['id' => 1, 'teacher_id' => $next->id]);
        $this->assertDatabaseCount('activities', 1);
        $this->assertDatabaseCount('enrollments', 1);
        $this->actingAs($next)->get('/courses/1')->assertOk();
        $this->actingAs($this->manager)->post('/courses/1/teacher-transfer', ['teacher_id' => $this->teacher->id, 'retain_previous' => 1])->assertRedirect('/');
        $this->assertDatabaseHas('course_teachers', ['course_id' => 1, 'user_id' => $next->id]);
    }

    public function test_transfer_rejects_foreign_or_locked_teacher_and_locked_coteacher_can_be_removed(): void
    {
        $foreign = User::factory()->create(['role' => 'teacher', 'tenant_id' => 2]);
        $locked = User::factory()->create(['role' => 'teacher', 'tenant_id' => 1, 'locked' => true]);
        $this->post('/courses/1/teacher-transfer', ['teacher_id' => $foreign->id])->assertNotFound();
        $this->post('/courses/1/teacher-transfer', ['teacher_id' => $locked->id])->assertNotFound();
        DB::table('course_teachers')->insert(['course_id' => 1, 'user_id' => $locked->id]);
        $this->post('/courses/1/teachers', ['user_id' => $locked->id, 'granted' => 0])->assertRedirect();
        $this->assertDatabaseMissing('course_teachers', ['course_id' => 1, 'user_id' => $locked->id]);
    }

    public function test_archive_and_detach_classroom_keep_access_and_history_until_explicit_revocation(): void
    {
        $class = $this->classroom();
        DB::table('classroom_members')->insert(['classroom_id' => $class, 'user_id' => $this->student->id]);
        $this->post('/courses/1/classrooms', ['classroom_id' => $class]);
        $this->post('/classrooms/'.$class.'/archive', ['archived' => 1])->assertRedirect();
        $this->putJson('/classrooms/'.$class.'/members', ['user_ids' => []])->assertUnprocessable();
        $this->postJson('/courses/1/classrooms', ['classroom_id' => $class])->assertUnprocessable();
        $this->postJson('/courses/1/assign-bulk', ['activity_id' => 1, 'audience' => 'classroom', 'classroom_id' => $class, 'granted' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseCount('activity_assignments', 1);
        $this->delete('/courses/1/classrooms/'.$class)->assertRedirect();
        $this->assertDatabaseCount('course_classrooms', 0);
        $this->assertDatabaseCount('enrollments', 1);
        $this->post('/classrooms/'.$class.'/archive', ['archived' => 0])->assertRedirect();
        $this->post('/courses/1/classrooms', ['classroom_id' => $class])->assertRedirect();
        $this->post('/classrooms/'.$this->classroom(2).'/archive', ['archived' => 1])->assertNotFound();
    }

    public function test_profile_changes_require_password_for_email_and_do_not_allow_role_escalation(): void
    {
        $this->student->forceFill(['password' => Hash::make('Profile-old-1234')])->save();
        $this->actingAs($this->student)->put('/account/profile', ['name' => 'Updated learner', 'email' => $this->student->email, 'role' => 'admin', 'tenant_id' => 2])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $this->student->id, 'name' => 'Updated learner', 'role' => 'student', 'tenant_id' => 1]);
        $this->putJson('/account/profile', ['name' => 'Updated learner', 'email' => 'new-login@example.test'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->put('/account/profile', ['name' => 'Updated learner', 'email' => 'new-login@example.test', 'current_password' => 'Profile-old-1234'])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $this->student->id, 'email' => 'new-login@example.test']);
    }

    public function test_report_course_selector_respects_course_permissions(): void
    {
        $unassigned = User::factory()->create(['role' => 'teacher', 'tenant_id' => 1]);
        $this->actingAs($unassigned)->get('/reports')->assertOk()->assertDontSee('Course A');
        $this->get('/reports?course_id=1')->assertNotFound();
        $this->actingAs($this->teacher)->get('/reports?course_id=1')->assertRedirect('/courses/1/grades');
        $this->actingAs($this->student)->get('/reports')->assertForbidden();
    }

    public function test_tool_configuration_check_is_scoped_and_does_not_expose_keys(): void
    {
        config(['session.secure' => true, 'session.same_site' => 'none']);
        DB::table('lti_keys')->insert(['kid' => 'diagnostic', 'encrypted_key' => Crypt::encryptString(self::$privateKey), 'modulus' => '', 'exponent' => '']);
        $response = $this->get('/admin/tools/1/check')->assertOk();
        $this->assertTrue(collect($response->viewData('checks'))->every(fn ($check) => (bool) $check['ok']));
        $response->assertDontSee(self::$privateKey)->assertDontSee(self::$publicKey);
        DB::table('lti_registrations')->where('id', 1)->update(['public_key' => 'invalid']);
        $this->get('/admin/tools/1/check')->assertOk()->assertSee('Cần kiểm tra');
        $this->actingAs($this->student)->get('/admin/tools/1/check')->assertForbidden();
    }

    public function test_selection_receipt_prevents_duplicates_even_if_pending_cache_is_restored(): void
    {
        $claims = $this->selection();
        $key = 'lti:selection:'.hash('sha256', $claims[self::DEEP.'data']);
        $pending = Cache::get($key);
        $this->returnSelection($claims)->assertRedirect();
        Cache::put($key, $pending, 60);
        $this->returnSelection($claims)->assertStatus(409);
        $this->assertDatabaseCount('activities', 2);
        $this->assertDatabaseCount('lti_selection_receipts', 1);
    }

    public function test_new_migration_can_upgrade_existing_learning_data_without_deleting_it(): void
    {
        $class = $this->classroom();
        DB::table('grades')->insert(['activity_id' => 1, 'user_id' => $this->student->id, 'score' => 8, 'reported_at' => now()]);
        $migration = require database_path('migrations/2026_11_25_000002_complete_learning_workflows.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('classrooms', ['id' => $class, 'archived_at' => null]);
        $this->assertDatabaseHas('courses', ['id' => 1, 'teacher_id' => $this->teacher->id]);
        $this->assertDatabaseHas('grades', ['activity_id' => 1, 'user_id' => $this->student->id, 'score' => 8]);
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseCount('activity_assignments', 1);
    }
}
