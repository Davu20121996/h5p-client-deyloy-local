from pathlib import Path
import shutil
root=Path('E:/laragon/www/project-docker/H5P-Hub')
backup=Path('backups/2026-11-19-hub-ags'); backup.mkdir(parents=True,exist_ok=True)
paths=['app/Http/Controllers/H5pController.php','resources/views/workspace/player.blade.php','tests/Feature/LtiIntegrationTest.php']
for p in paths: shutil.copy2(root/p,backup/Path(p).name)
f=root/paths[0]; s=f.read_text(encoding='utf-8')
s=s.replace('use App\\H5p\\Runtime;', 'use App\\H5p\\Runtime;\nuse App\\Lti\\PublishScore;\nuse App\\Models\\H5pAttempt;\nuse Illuminate\\Support\\Facades\\DB;')
s=s.replace('public function result(LtiLaunch $launch, string $uuid): Response','public function result(Request $request, LtiLaunch $launch, string $uuid): Response')
s=s.replace('''        $this->authorizeRead($launch, $uuid);
        abort_unless(! $launch->can_author && $launch->message_type === 'LtiResourceLinkRequest', 403);

        return response()->noContent();''','''        $content = $this->authorizeRead($launch, $uuid);
        abort_unless(! $launch->can_author && $launch->message_type === 'LtiResourceLinkRequest', 403);
        $data = $request->validate([
            'event_id' => 'required|uuid',
            'score' => 'nullable|numeric|min:0|max:99999999|lte:max_score',
            'max_score' => 'nullable|required_with:score|numeric|gt:0|max:99999999',
            'completed' => 'required|boolean',
            'result' => 'required|array',
        ]);
        DB::transaction(function () use ($launch, $content, $data): void {
            $attempt = H5pAttempt::firstOrCreate([
                'lti_launch_id' => $launch->id,
                'event_id' => $data['event_id'],
            ], [
                'h5p_content_id' => $content->id,
                'score' => $data['score'] ?? null,
                'max_score' => $data['max_score'] ?? null,
                'completed' => $data['completed'],
                'result' => $data['result'],
            ]);
            if ($attempt->wasRecentlyCreated && $attempt->completed && $attempt->score !== null && $launch->ags_lineitem_url) {
                PublishScore::dispatch($attempt->id)->afterCommit();
            }
        });

        return response()->noContent();''')
f.write_text(s,encoding='utf-8')
f=root/paths[1];s=f.read_text(encoding='utf-8')
s=s.replace('<div class="hub-card"><div class="h5p-content" data-content-id="{{ $content->id }}"></div></div>', '<div class="hub-card"><div class="h5p-content" data-content-id="{{ $content->id }}"></div></div>\n@if(!$launch->can_author)<p id="result-status" role="status" aria-live="polite"></p>@endif')
s=s.replace('\n@endsection\n', '\n@endsection\n',1)
s=s.replace('@foreach(array_merge($coreScripts, $scripts) as $script)<script src="{{ $script }}"></script>@endforeach','''@foreach(array_merge($coreScripts, $scripts) as $script)<script src="{{ $script }}"></script>@endforeach
@if(!$launch->can_author)
<script src="{{ asset('js/hub-results.js') }}?v={{ filemtime(public_path('js/hub-results.js')) }}"></script>
<script>H5PHubResults.attach({{ Illuminate\\Support\\Js::from(['endpoint' => route('content.results', ['launch' => $launch->id, 'uuid' => $content->uuid]), 'csrfToken' => csrf_token()]) }});</script>
@endif''')
f.write_text(s,encoding='utf-8')
f=root/paths[2];s=f.read_text(encoding='utf-8')
s=s.replace('use Illuminate\\Support\\Facades\\Http;', 'use Illuminate\\Support\\Facades\\Http;\nuse Illuminate\\Support\\Facades\\Bus;')
s=s.replace('test_learner_resource_launch_binds_content_without_saving_results', 'test_learner_resource_launch_binds_content_and_saves_result_once')
s=s.replace('test_completed_result_does_not_save_attempts_or_queue_scores', 'test_completed_result_saves_attempt_and_dispatches_score_once')
s=s.replace("        $lineitem = $this->platform->issuer.'/lineitems/42';", "        Bus::fake([PublishScore::class]);\n        $lineitem = $this->platform->issuer.'/lineitems/42';")
s=s.replace("$this->assertDatabaseCount('h5p_attempts', 0);", "$this->assertDatabaseCount('h5p_attempts', 1);")
s=s.replace("        $this->assertDatabaseCount('jobs', 0);", "        Bus::assertDispatchedTimes(PublishScore::class, 1);")
s=s.replace("->assertDontSee('hub-results.js')->assertDontSee('H5PHubResults')", "->assertSee('hub-results.js')->assertSee('H5PHubResults')")
f.write_text(s,encoding='utf-8')
print('Updated Hub result capture, storage, dispatch and regression tests')
