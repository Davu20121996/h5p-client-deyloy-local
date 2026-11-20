<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmbeddedLearningTest extends TestCase
{
    use RefreshDatabase;

    private function lesson(): User
    {
        DB::table('tenants')->insert(['id' => 1, 'name' => 'Test']);
        $teacher = User::factory()->create(['role' => 'teacher', 'tenant_id' => 1]);
        $student = User::factory()->create(['role' => 'student', 'tenant_id' => 1]);
        DB::table('lti_registrations')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'H5P', 'client_id' => 'test', 'deployment_id' => 'test', 'login_url' => 'https://hub.example/login', 'redirect_uri' => 'https://hub.example/launch', 'public_key' => 'test']);
        DB::table('courses')->insert(['id' => 1, 'tenant_id' => 1, 'teacher_id' => $teacher->id, 'title' => 'Course']);
        DB::table('activities')->insert(['id' => 1, 'course_id' => 1, 'lti_registration_id' => 1, 'title' => 'Lesson', 'resource_link_id' => 'lesson', 'target_uri' => 'https://hub.example/launch?content_id=test', 'maximum' => 10, 'published' => true]);
        DB::table('enrollments')->insert(['course_id' => 1, 'user_id' => $student->id]);
        DB::table('activity_assignments')->insert(['activity_id' => 1, 'user_id' => $student->id]);

        return $student;
    }

    public function test_assigned_student_can_open_iframe_and_receive_authenticated_completion(): void
    {
        $student = $this->lesson();
        $this->actingAs($student)->get('/courses/1/activities/1/learn')->assertOk()->assertSee('target="h5p-lesson"', false)->assertSee('<iframe', false);
        $this->getJson('/courses/1/activities/1/status')->assertOk()->assertJson(['completed' => false, 'score' => null]);
        Cache::put('lti:token:'.hash('sha256', 'test-token'), ['tool' => 1, 'tenant' => 1, 'scopes' => ['https://purl.imsglobal.org/spec/lti-ags/scope/score']], 60);
        $this->withToken('test-token')->postJson('/lti/courses/1/lineitems/1/scores', [
            'userId' => $student->id, 'timestamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'scoreGiven' => 8, 'scoreMaximum' => 10, 'activityProgress' => 'Completed', 'gradingProgress' => 'FullyGraded',
        ])->assertNoContent();
        $this->getJson('/courses/1/activities/1/status')->assertOk()->assertJson(['completed' => true, 'score' => 8, 'maximum' => 10]);
        $other = User::factory()->create(['role' => 'student', 'tenant_id' => 1]);
        $this->actingAs($other)->getJson('/courses/1/activities/1/status')->assertNotFound();
    }

    public function test_score_without_completion_does_not_mark_lesson_completed(): void
    {
        $student = $this->lesson();
        DB::table('grades')->insert(['activity_id' => 1, 'user_id' => $student->id, 'score' => 5, 'reported_at' => now(), 'activity_progress' => 'InProgress']);
        $this->actingAs($student)->getJson('/courses/1/activities/1/status')->assertOk()->assertJson(['completed' => false, 'score' => 5]);
        DB::table('activity_assignments')->delete();
        $this->get('/courses/1/activities/1/learn')->assertNotFound();
    }

    private function scorePayload(User $student, array $overrides = []): array
    {
        Cache::put('lti:token:'.hash('sha256', 'test-token'), [
            'tool' => 1, 'tenant' => 1, 'scopes' => ['https://purl.imsglobal.org/spec/lti-ags/scope/score'],
        ], 60);

        return array_replace([
            'userId' => $student->id, 'timestamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'scoreGiven' => 8, 'scoreMaximum' => 10,
            'activityProgress' => 'Completed', 'gradingProgress' => 'FullyGraded',
        ], $overrides);
    }

    public function test_completion_is_saved_before_grading_and_same_event_can_receive_final_score(): void
    {
        $student = $this->lesson();
        $payload = $this->scorePayload($student, ['gradingProgress' => 'PendingManual']);
        $this->withToken('test-token')->postJson('/lti/courses/1/lineitems/1/scores', $payload)->assertNoContent();
        $this->actingAs($student)->getJson('/courses/1/activities/1/status')
            ->assertOk()->assertJson(['completed' => true, 'score' => null]);

        $payload['gradingProgress'] = 'FullyGraded';
        $this->withToken('test-token')->postJson('/lti/courses/1/lineitems/1/scores', $payload)->assertNoContent();
        $this->getJson('/courses/1/activities/1/status')->assertOk()->assertJson(['completed' => true, 'score' => 8]);
        $this->assertDatabaseCount('grades', 1);

        $payload['scoreGiven'] = 2;
        $this->postJson('/lti/courses/1/lineitems/1/scores', $payload)->assertNoContent();
        $this->getJson('/courses/1/activities/1/status')->assertJson(['completed' => true, 'score' => 8]);
    }

    public function test_completion_without_a_score_is_saved_while_grading_is_pending(): void
    {
        $student = $this->lesson();
        $payload = $this->scorePayload($student, ['gradingProgress' => 'Pending']);
        unset($payload['scoreGiven'], $payload['scoreMaximum']);
        $this->withToken('test-token')->postJson('/lti/courses/1/lineitems/1/scores', $payload)->assertNoContent();
        $this->actingAs($student)->getJson('/courses/1/activities/1/status')
            ->assertOk()->assertJson(['completed' => true, 'score' => null]);
    }

    public function test_revoked_assignment_rejects_scores_without_writing_grades_or_logs(): void
    {
        $student = $this->lesson();
        DB::table('activity_assignments')->delete();
        $payload = $this->scorePayload($student);
        $this->withToken('test-token')->postJson('/lti/courses/1/lineitems/1/scores', $payload)->assertNotFound();
        $payload['gradingProgress'] = 'PendingManual';
        $this->postJson('/lti/courses/1/lineitems/1/scores', $payload)->assertNotFound();
        $this->assertDatabaseCount('grades', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_unenrolled_student_cannot_submit_scores_even_with_an_assignment(): void
    {
        $student = $this->lesson();
        DB::table('enrollments')->delete();
        $payload = $this->scorePayload($student);
        $this->withToken('test-token')->postJson('/lti/courses/1/lineitems/1/scores', $payload)->assertNotFound();
        $this->assertDatabaseCount('grades', 0);
    }

    public function test_pending_progress_also_rejects_future_timestamps(): void
    {
        $student = $this->lesson();
        $payload = $this->scorePayload($student, [
            'gradingProgress' => 'PendingManual', 'timestamp' => now()->addHour()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
        $this->withToken('test-token')->postJson('/lti/courses/1/lineitems/1/scores', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('grades', 0);
    }
}
