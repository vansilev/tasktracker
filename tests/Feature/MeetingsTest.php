<?php

namespace Tests\Feature;

use App\Enums\SystemType;
use App\Jobs\AnalyzeMeeting;
use App\Models\AuditLog;
use App\Models\Meeting;
use App\Models\MeetingTaskDraft;
use App\Models\Task;
use App\Models\User;
use App\Services\Meetings\FirefliesClient;
use App\Services\Meetings\MeetingImporter;
use App\Services\Meetings\MeetingPeople;
use App\Services\Meetings\MeetingTaskCreator;
use App\Services\Meetings\MeetingTaskExtractor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class MeetingsTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        // Real keys in a developer's .env must never reach the tests.
        config(['services.fireflies.api_key' => null, 'services.fireflies.extra_api_keys' => null, 'services.openai.api_key' => null]);
    }

    private function meeting(array $overrides = []): Meeting
    {
        return Meeting::query()->create(array_merge([
            'fireflies_id' => 'ff-'.uniqid(),
            'title' => 'Weekly',
            'started_at' => '2026-11-05 12:30:00',
            'duration_minutes' => 60,
            'organizer_email' => 'organizer@tcsavant.com',
            'attendees' => [
                ['email' => 'organizer@tcsavant.com', 'name' => null],
                ['email' => 'sergey.salamaha@tcsavant.com', 'name' => null],
            ],
            'sentences' => [
                ['start' => 10, 'end' => 12, 'speaker' => 'Natalia Svitenko', 'text' => 'Сергей, подготовьте отчёт.'],
                ['start' => 20, 'end' => 22, 'speaker' => 'Сергій Саламаха', 'text' => 'Сделаю до пятницы.'],
                ['start' => 30, 'end' => 32, 'speaker' => 'Борис Буянов', 'text' => 'Добавьте ветку без проверки.'],
            ],
        ], $overrides));
    }

    public function test_names_match_across_scripts_and_spellings(): void
    {
        $dept = $this->createDepartment();
        $natalia = $this->createUserInDepartment($dept, 'Свитенко Наталья');
        $yulia = $this->createUserInDepartment($dept, 'Юлия Станиславовна Васильева');
        $sergey = $this->createUserInDepartment($dept, 'Саламаха Сергей Васильевич');
        $pavel = $this->createUserInDepartment($dept, 'Павел Бондарчук');

        $people = app(MeetingPeople::class);

        $this->assertSame($natalia->id, $people->resolve(null, 'Natalia Svitenko')['user']?->id);
        $this->assertSame($yulia->id, $people->resolve(null, 'Yuliia Vasylieva')['user']?->id);
        $this->assertSame($sergey->id, $people->resolve(null, 'Сергій Саламаха')['user']?->id);
        $this->assertSame($pavel->id, $people->resolve(null, 'Pavlo Bondarchuk')['user']?->id);
        $this->assertNull($people->resolve(null, 'Борис Буянов')['user']);
        // A bare first name is not enough to guess.
        $this->assertNull($people->resolve(null, 'Наталья')['user']);
    }

    public function test_alias_set_by_hand_wins_and_can_mark_external(): void
    {
        $dept = $this->createDepartment();
        $admin = $this->createUserInDepartment($dept, 'Admin', SystemType::Admin);
        $alina = $this->createUserInDepartment($dept, 'Алина Бондар');
        $people = app(MeetingPeople::class);

        $this->assertNull($people->resolve('bondar.alina22@gmail.com', null)['user']);

        $people->remember('Bondar.Alina22@gmail.com', $alina->id, false, $admin);
        $people->remember('Борис Буянов', null, true, $admin);

        $this->assertSame($alina->id, $people->resolve('bondar.alina22@gmail.com', null)['user']?->id);
        $this->assertTrue($people->resolve(null, 'Буянов Борис')['external']);
    }

    public function test_assignment_rule(): void
    {
        $dept = $this->createDepartment();
        $named = $this->createUserInDepartment($dept, 'Named');
        $speaker = $this->createUserInDepartment($dept, 'Speaker');
        $organizer = $this->createUserInDepartment($dept, 'Organizer');
        $extractor = app(MeetingTaskExtractor::class);

        // Named and present → named.
        $this->assertSame([$named->id, 'named'], $this->ids($extractor->decide($named, 'Named', $speaker, $organizer, [$named->id, $speaker->id])));
        // Named but absent → whoever said it.
        $this->assertSame([$speaker->id, 'absent_speaker'], $this->ids($extractor->decide($named, 'Named', $speaker, $organizer, [$speaker->id])));
        // Named somebody we do not know → whoever said it.
        $this->assertSame([$speaker->id, 'absent_speaker'], $this->ids($extractor->decide(null, 'Максим', $speaker, $organizer, [$speaker->id])));
        // Nobody named → whoever said it.
        $this->assertSame([$speaker->id, 'speaker'], $this->ids($extractor->decide(null, null, $speaker, $organizer, [$speaker->id])));
        // Said by an outsider → organizer.
        $this->assertSame([$organizer->id, 'organizer'], $this->ids($extractor->decide(null, 'Максим', null, $organizer, [])));
        $this->assertSame([null, 'unassigned'], $this->ids($extractor->decide(null, null, null, null, [])));
    }

    public function test_analysis_turns_model_answer_into_drafts_with_the_rule_applied(): void
    {
        config(['services.openai.api_key' => 'test', 'services.openai.meeting_model' => 'test-model']);
        $dept = $this->createDepartment();
        $organizer = $this->createUserInDepartment($dept, 'Свитенко Наталья');
        $organizer->update(['email' => 'organizer@tcsavant.com']);
        $sergey = $this->createUserInDepartment($dept, 'Саламаха Сергей');
        $sergey->update(['email' => 'sergey.salamaha@tcsavant.com']);
        $absent = $this->createUserInDepartment($dept, 'Absent Person');
        $meeting = $this->meeting();

        $answer = [
            'speakers' => [
                ['speaker' => 'Борис Буянов', 'user_id' => null, 'external' => true],
                ['speaker' => 'Сергій Саламаха', 'user_id' => $sergey->id, 'external' => false],
            ],
            'tasks' => [
                ['title' => 'Подготовить отчёт', 'details' => 'Отчёт по продажам.', 'assignee_name' => 'Сергей', 'assignee_user_id' => $sergey->id, 'speaker' => 'Natalia Svitenko', 'deadline' => '2026-11-06', 'deadline_phrase' => 'до пятницы', 'quote' => 'Сергей, подготовьте отчёт.', 'start_seconds' => 10],
                ['title' => 'Позвонить партнёру', 'details' => '', 'assignee_name' => 'Absent', 'assignee_user_id' => $absent->id, 'speaker' => 'Сергій Саламаха', 'deadline' => 'не дата', 'deadline_phrase' => null, 'quote' => 'Сделаю.', 'start_seconds' => 20],
                ['title' => 'Добавить ветку', 'details' => '', 'assignee_name' => 'Максим', 'assignee_user_id' => 999999, 'speaker' => 'Борис Буянов', 'deadline' => null, 'deadline_phrase' => null, 'quote' => 'Добавьте ветку без проверки.', 'start_seconds' => 30],
            ],
        ];
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode($answer)]]]])]);

        $this->assertSame(3, app(MeetingTaskExtractor::class)->analyze($meeting));

        $drafts = $meeting->drafts()->get();
        $this->assertSame([$sergey->id, 'named', '2026-11-06'], [$drafts[0]->assignee_id, $drafts[0]->assignment_reason, $drafts[0]->deadline->toDateString()]);
        $this->assertSame([$sergey->id, 'absent_speaker', null], [$drafts[1]->assignee_id, $drafts[1]->assignment_reason, $drafts[1]->deadline]);
        $this->assertSame($absent->id, $drafts[1]->mentioned_user_id);
        $this->assertSame([$organizer->id, 'organizer'], [$drafts[2]->assignee_id, $drafts[2]->assignment_reason]);
        $this->assertSame('test-model', $meeting->fresh()->analysis_model);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/chat/completions')
            && $request['response_format']['type'] === 'json_schema'
            && str_contains($request['messages'][1]['content'], '[20] Сергій Саламаха: Сделаю до пятницы.'));
    }

    public function test_reanalysis_keeps_drafts_that_already_became_tasks(): void
    {
        config(['services.openai.api_key' => 'test']);
        $meeting = $this->meeting();
        $kept = MeetingTaskDraft::query()->create(['meeting_id' => $meeting->id, 'title' => 'Done', 'assignment_reason' => 'named', 'status' => 'created']);
        MeetingTaskDraft::query()->create(['meeting_id' => $meeting->id, 'title' => 'Old', 'assignment_reason' => 'named']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['speakers' => [], 'tasks' => []])]]]])]);

        app(MeetingTaskExtractor::class)->analyze($meeting);

        $this->assertSame([$kept->id], $meeting->drafts()->pluck('id')->all());
    }

    public function test_failed_analysis_is_recorded(): void
    {
        config(['services.openai.api_key' => 'test']);
        $meeting = $this->meeting();
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'You have no credits remaining.']], 429)]);

        try {
            app(MeetingTaskExtractor::class)->analyze($meeting);
            $this->fail('expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no credits', $e->getMessage());
        }

        $this->assertStringContainsString('no credits', (string) $meeting->fresh()->analysis_error);
        $this->assertNull($meeting->fresh()->analyzed_at);
    }

    public function test_confirmed_draft_becomes_a_task_with_quote_links_and_absent_person_as_watcher(): void
    {
        $dept = $this->createDepartment('Sales');
        $admin = $this->createUserInDepartment($dept, 'Admin', SystemType::Admin);
        $speaker = $this->createUserInDepartment($dept, 'Speaker');
        $absent = $this->createUserInDepartment($dept, 'Absent');
        $category = $this->createCategory();
        $meeting = $this->meeting();
        $draft = MeetingTaskDraft::query()->create([
            'meeting_id' => $meeting->id,
            'title' => 'Позвонить партнёру',
            'description' => 'Контекст <b>с тегом</b>',
            'assignee_id' => $speaker->id,
            'assignment_reason' => 'absent_speaker',
            'mentioned_name' => 'Absent',
            'mentioned_user_id' => $absent->id,
            'speaker_name' => 'Speaker',
            'deadline' => '2026-11-12',
            'deadline_phrase' => 'в четверг',
            'quote' => 'Позвоню партнёру.',
            'start_seconds' => 441.29,
        ]);

        $task = app(MeetingTaskCreator::class)->create($admin, $draft, $category->id);

        $this->assertSame($speaker->id, $task->assignee_id);
        $this->assertSame('2026-11-12', $task->deadline->toDateString());
        $this->assertStringContainsString('Позвоню партнёру.', $task->description);
        $this->assertStringContainsString('&lt;b&gt;', $task->description);
        $this->assertStringContainsString('app.fireflies.ai/view/'.$meeting->fireflies_id.'?t=441.29', $task->description);
        $this->assertStringContainsString(route('meetings.show', $meeting), $task->description);
        $this->assertTrue($task->watchers()->whereKey($absent->id)->exists());
        $this->assertSame(['created', $task->id], [$draft->fresh()->status, $draft->fresh()->task_id]);

        // A second confirmation does not make a second task.
        $refused = false;
        try {
            app(MeetingTaskCreator::class)->create($admin, $draft->fresh(), $category->id);
        } catch (ValidationException) {
            $refused = true;
        }
        $this->assertTrue($refused);
        $this->assertSame(1, Task::query()->count());
    }

    public function test_meetings_are_visible_only_to_participants_and_admins(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions([...$this->defaultPermissions(), 'view_meetings'], [$dept->id]);
        $admin = $this->createUserInDepartment($dept, 'Admin', SystemType::Admin);
        $participant = $this->createUserInDepartment($dept, 'Sergey Salamaha', role: $role);
        $stranger = $this->createUserInDepartment($dept, 'Stranger', role: $role);
        $meeting = $this->meeting(['title' => 'Секретная встреча']);

        $this->actingAs($admin)->get('/meetings')->assertOk()->assertSee('Секретная встреча');
        $this->actingAs($participant)->get('/meetings')->assertOk()->assertSee('Секретная встреча');
        $this->actingAs($stranger)->get('/meetings')->assertOk()->assertDontSee('Секретная встреча');

        $this->actingAs($participant)->get('/meetings/'.$meeting->id.'?tab=transcript')->assertOk()->assertSee('Сделаю до пятницы.', false);
        $this->actingAs($stranger)->get('/meetings/'.$meeting->id)->assertForbidden();
    }

    public function test_meetings_tab_needs_the_view_meetings_permission(): void
    {
        $dept = $this->createDepartment();
        $without = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $with = $this->createRoleWithPermissions([...$this->defaultPermissions(), 'view_meetings'], [$dept->id]);
        $admin = $this->createUserInDepartment($dept, 'Admin', SystemType::Admin);
        // Took part in the meeting, but the role does not open the tab.
        $participant = $this->createUserInDepartment($dept, 'Sergey Salamaha', role: $without);
        $allowed = $this->createUserInDepartment($dept, 'Allowed', role: $with);
        $noRole = $this->createUserInDepartment($dept, 'No Role');
        $meeting = $this->meeting();

        $this->actingAs($participant)->get('/meetings')->assertForbidden();
        $this->actingAs($participant)->get('/meetings/'.$meeting->id)->assertForbidden();
        $this->actingAs($participant)->get('/tasks')->assertOk()->assertDontSee(route('meetings.index'), false);
        $this->actingAs($noRole)->get('/meetings')->assertForbidden();

        $this->actingAs($admin)->get('/tasks')->assertOk()->assertSee(route('meetings.index'), false);
        $this->actingAs($allowed)->get('/tasks')->assertOk()->assertSee(route('meetings.index'), false);
        $this->actingAs($allowed)->get('/meetings')->assertOk();
        // The tab is open, but this meeting is not theirs.
        $this->actingAs($allowed)->get('/meetings/'.$meeting->id)->assertForbidden();

        // Granting the permission to the role opens the participant's own meeting.
        $without->syncPermissions([...$this->defaultPermissions(), 'view_meetings']);
        $this->actingAs($participant->fresh())->get('/meetings/'.$meeting->id)->assertOk();
    }

    public function test_admin_restricts_access_per_meeting(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions([...$this->defaultPermissions(), 'view_meetings'], [$dept->id]);
        $admin = $this->createUserInDepartment($dept, 'Admin', SystemType::Admin);
        $participant = $this->createUserInDepartment($dept, 'Sergey Salamaha', role: $role);
        $guest = $this->createUserInDepartment($dept, 'Guest', role: $role);
        $meeting = $this->meeting(['title' => 'Секретная встреча']);
        $url = '/meetings/'.$meeting->id;

        // Default: participants see it, others do not.
        $this->actingAs($participant)->get($url)->assertOk();
        $this->actingAs($guest)->get($url)->assertForbidden();

        $page = Volt::actingAs($admin)->test('pages.meetings.show', ['meeting' => $meeting]);

        // Letting a person in by hand.
        $this->actingAs($admin);
        $page->call('addViewer', (string) $guest->id);
        $this->actingAs($guest)->get($url)->assertOk();
        $this->actingAs($guest)->get('/meetings')->assertSee('Секретная встреча');

        // Only the selected: the participant loses access, the guest keeps it.
        $this->actingAs($admin);
        $page->call('setAccessMode', 'selected');
        $this->actingAs($participant)->get($url)->assertForbidden();
        $this->actingAs($participant)->get('/meetings')->assertDontSee('Секретная встреча');
        $this->actingAs($guest)->get($url)->assertOk();

        // Admins only: nobody else, the admin still does.
        $this->actingAs($admin);
        $page->call('setAccessMode', 'admins');
        $this->actingAs($guest)->get($url)->assertForbidden();
        $this->actingAs($admin)->get($url)->assertOk()->assertSee('data-ui="meeting-access"', false);

        // Back to participants, guest removed.
        $this->actingAs($admin);
        $page->call('setAccessMode', 'participants')->call('removeViewer', $guest->id);
        $this->actingAs($participant)->get($url)->assertOk();
        $this->actingAs($guest)->get($url)->assertForbidden();

        $this->assertSame(3, AuditLog::query()->where('action', 'meeting.access_changed')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'meeting.viewer_added')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'meeting.viewer_removed')->count());

        // Unknown mode is ignored.
        $this->actingAs($admin);
        $page->call('setAccessMode', 'everyone');
        $this->assertSame('participants', $meeting->fresh()->access_mode);
    }

    public function test_only_admin_changes_access_and_others_do_not_see_the_access_card(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions([...$this->defaultPermissions(), 'view_meetings'], [$dept->id]);
        $participant = $this->createUserInDepartment($dept, 'Sergey Salamaha', role: $role);
        $guest = $this->createUserInDepartment($dept, 'Guest', role: $role);
        $meeting = $this->meeting();

        $this->actingAs($participant)->get('/meetings/'.$meeting->id)->assertOk()->assertDontSee('data-ui="meeting-access"', false);

        Volt::actingAs($participant)->test('pages.meetings.show', ['meeting' => $meeting])->call('setAccessMode', 'admins')->assertForbidden();
        Volt::actingAs($participant)->test('pages.meetings.show', ['meeting' => $meeting])->call('addViewer', (string) $guest->id)->assertForbidden();

        $this->assertSame('participants', $meeting->fresh()->access_mode);
        $this->assertSame(0, $meeting->viewers()->count());
    }

    public function test_only_admin_maps_people_and_mapping_reassigns_open_drafts(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions([...$this->defaultPermissions(), 'view_meetings'], [$dept->id]);
        $admin = $this->createUserInDepartment($dept, 'Admin', SystemType::Admin);
        $participant = $this->createUserInDepartment($dept, 'Sergey Salamaha', role: $role);
        $boris = $this->createUserInDepartment($dept, 'Somebody Else');
        $meeting = $this->meeting();
        $draft = MeetingTaskDraft::query()->create([
            'meeting_id' => $meeting->id, 'title' => 'Ветка', 'assignment_reason' => 'unassigned', 'speaker_name' => 'Борис Буянов',
        ]);
        $index = collect(app(MeetingPeople::class)->participants($meeting))->search(fn ($p) => $p['speaker'] === 'Борис Буянов');

        Volt::actingAs($participant)->test('pages.meetings.show', ['meeting' => $meeting])
            ->call('mapPerson', $index, (string) $boris->id)
            ->assertForbidden();

        Volt::actingAs($admin)->test('pages.meetings.show', ['meeting' => $meeting])
            ->call('mapPerson', $index, (string) $boris->id);

        $this->assertSame([$boris->id, 'speaker'], [$draft->fresh()->assignee_id, $draft->fresh()->assignment_reason]);
    }

    public function test_analysis_runs_after_the_response_and_the_page_picks_up_the_result(): void
    {
        config(['services.openai.api_key' => 'test']);
        Bus::fake();
        $dept = $this->createDepartment();
        $admin = $this->createUserInDepartment($dept, 'Admin', SystemType::Admin);
        $meeting = $this->meeting();

        $page = Volt::actingAs($admin)->test('pages.meetings.show', ['meeting' => $meeting])->call('analyze');

        Bus::assertDispatchedAfterResponse(AnalyzeMeeting::class, fn ($job) => $job->meetingId === $meeting->id);
        $this->assertTrue($meeting->fresh()->isBeingAnalyzed());
        $page->assertSee(__('meetings.analyzing'));

        // A second press while it runs does not start another run.
        $page->call('analyze');
        Bus::assertDispatchedAfterResponseTimes(AnalyzeMeeting::class, 1);

        // The job does the work and clears the flag; the poll shows the result.
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['speakers' => [], 'tasks' => [
            ['title' => 'Сделать отчёт', 'details' => '', 'assignee_name' => null, 'assignee_user_id' => null, 'speaker' => 'Natalia Svitenko', 'deadline' => null, 'deadline_phrase' => null, 'quote' => 'q', 'start_seconds' => 1],
        ]])]]]])]);
        (new AnalyzeMeeting($meeting->id))->handle(app(MeetingTaskExtractor::class));
        $this->assertNull($meeting->fresh()->analysis_started_at);

        $page->call('checkAnalysis')->assertSee('Сделать отчёт');
    }

    public function test_failed_background_analysis_clears_the_flag_and_shows_the_error(): void
    {
        config(['services.openai.api_key' => 'test']);
        $meeting = $this->meeting(['analysis_started_at' => now()]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'no credits']], 429)]);

        (new AnalyzeMeeting($meeting->id))->handle(app(MeetingTaskExtractor::class));

        $this->assertNull($meeting->fresh()->analysis_started_at);
        $this->assertStringContainsString('no credits', (string) $meeting->fresh()->analysis_error);
        // A run that hung for more than 10 minutes no longer blocks the button.
        $this->assertFalse(Meeting::query()->create(['fireflies_id' => 'old', 'title' => 'x', 'started_at' => now(), 'analysis_started_at' => now()->subMinutes(11)])->isBeingAnalyzed());
    }

    /** Fireflies GraphQL stub: the list call and one-transcript calls, counted. */
    private function fakeFireflies(array $ids, array &$fetched, ?string &$summary = 'Коротко'): void
    {
        config(['services.fireflies.api_key' => 'test']);
        Http::fake(['api.fireflies.ai/*' => function ($request) use ($ids, &$fetched, &$summary) {
            if (str_contains($request['query'], 'transcripts(')) {
                return Http::response(['data' => ['transcripts' => array_map(fn ($id) => ['id' => $id, 'title' => $id, 'date' => 1], $ids)]]);
            }
            $id = $request['variables']['id'];
            $fetched[] = $id;

            return Http::response(['data' => ['transcript' => [
                'id' => $id, 'title' => 'Meeting '.$id, 'date' => now()->subHour()->getTimestampMs(), 'duration' => 30,
                'organizer_email' => 'assistant@tcsavant.com', 'meeting_link' => null,
                'meeting_attendees' => [], 'sentences' => [['speaker_name' => 'A', 'text' => 'Привет', 'start_time' => 1, 'end_time' => 2]],
                'summary' => ['keywords' => [], 'action_items' => null, 'overview' => null, 'short_summary' => $summary],
            ]]]);
        }]);
    }

    public function test_scheduled_import_fetches_only_new_meetings(): void
    {
        $fetched = [];
        $this->meeting(['fireflies_id' => 'old', 'summary' => 'Готово']);
        $this->fakeFireflies(['new-1', 'old', 'new-2'], $fetched);

        $this->artisan('meetings:import --limit=10 --new-only')
            ->expectsOutput('New meetings: 2')
            ->assertSuccessful();

        $this->assertSame(['new-1', 'new-2'], $fetched);
        $this->assertSame(3, Meeting::query()->count());

        // Next run: nothing new, nothing fetched, nothing printed.
        $fetched = [];
        $this->artisan('meetings:import --limit=10 --new-only')->doesntExpectOutputToContain('New meetings')->assertSuccessful();
        $this->assertSame([], $fetched);
    }

    public function test_fresh_meeting_without_summary_is_fetched_again_until_fireflies_finishes(): void
    {
        $fetched = [];
        $summary = null;
        $this->fakeFireflies(['fresh'], $fetched, $summary);
        $this->artisan('meetings:import --new-only')->assertSuccessful();
        $this->assertNull(Meeting::query()->where('fireflies_id', 'fresh')->value('summary'));

        $summary = 'Теперь готово';
        $this->artisan('meetings:import --new-only')->assertSuccessful();
        $this->assertSame('Теперь готово', Meeting::query()->where('fireflies_id', 'fresh')->value('summary'));
        $this->assertSame(['fresh', 'fresh'], $fetched);

        // An old meeting with no summary is not fetched forever.
        Meeting::query()->where('fireflies_id', 'fresh')->update(['summary' => null, 'started_at' => now()->subDays(5)]);
        $fetched = [];
        $this->artisan('meetings:import --new-only')->assertSuccessful();
        $this->assertSame([], $fetched);
    }

    public function test_scheduled_import_without_a_key_does_nothing(): void
    {
        config(['services.fireflies.api_key' => null]);
        Http::fake();

        $this->artisan('meetings:import --new-only')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_scheduled_import_fails_loudly_when_fireflies_is_down(): void
    {
        config(['services.fireflies.api_key' => 'test']);
        Http::fake(['api.fireflies.ai/*' => Http::response(['errors' => [['message' => 'Too many requests']]], 429)]);

        $this->artisan('meetings:import --new-only')->expectsOutputToContain('Too many requests')->assertFailed();
    }

    public function test_meetings_are_collected_over_several_keys_without_duplicates(): void
    {
        config(['services.fireflies.api_key' => 'max', 'services.fireflies.extra_api_keys' => ' natalia , max ,']);
        $calls = [];
        Http::fake(['api.fireflies.ai/*' => function ($request) use (&$calls) {
            $key = substr($request->header('Authorization')[0], 7);
            if (str_contains($request['query'], 'transcripts(')) {
                $calls[] = "list:{$key}";
                $ids = $key === 'max' ? [['shared', 2]] : [['shared', 2], ['only-natalia', 3]];

                return Http::response(['data' => ['transcripts' => array_map(fn ($r) => ['id' => $r[0], 'title' => $r[0], 'date' => $r[1]], $ids)]]);
            }
            $id = $request['variables']['id'];
            $calls[] = "get:{$id}:{$key}";

            return Http::response(['data' => ['transcript' => [
                'id' => $id, 'title' => $id, 'date' => now()->getTimestampMs(), 'duration' => 10, 'organizer_email' => null, 'meeting_link' => null,
                'meeting_attendees' => [], 'sentences' => [], 'summary' => ['short_summary' => 'ok'],
            ]]]);
        }]);

        $this->assertSame(['max', 'natalia'], app(FirefliesClient::class)->keys());
        $this->artisan('meetings:import --new-only')->expectsOutput('New meetings: 2')->assertSuccessful();

        // Newest first, each meeting fetched once, with the key that listed it.
        $this->assertSame(['list:max', 'list:natalia', 'get:only-natalia:natalia', 'get:shared:max'], $calls);
        $this->assertSame(2, Meeting::query()->count());
    }

    public function test_one_failing_key_does_not_stop_the_others(): void
    {
        config(['services.fireflies.api_key' => 'revoked', 'services.fireflies.extra_api_keys' => 'natalia']);
        Http::fake(['api.fireflies.ai/*' => function ($request) {
            $key = substr($request->header('Authorization')[0], 7);
            if ($key === 'revoked') {
                return Http::response(['errors' => [['message' => 'Invalid API key']]], 401);
            }
            if (str_contains($request['query'], 'transcripts(')) {
                return Http::response(['data' => ['transcripts' => [['id' => 'n1', 'title' => 'n1', 'date' => 1]]]]);
            }

            return Http::response(['data' => ['transcript' => [
                'id' => 'n1', 'title' => 'n1', 'date' => now()->getTimestampMs(), 'duration' => 10, 'organizer_email' => null, 'meeting_link' => null,
                'meeting_attendees' => [], 'sentences' => [], 'summary' => ['short_summary' => 'ok'],
            ]]]);
        }]);

        $this->artisan('meetings:import --new-only')->expectsOutput('New meetings: 1')->assertSuccessful();
    }

    public function test_new_meetings_are_drafted_automatically_but_old_ones_are_left_to_the_button(): void
    {
        config(['services.openai.api_key' => 'test']);
        $fetched = [];
        $summary = 'ok';
        $this->fakeFireflies(['fresh'], $fetched, $summary);
        // Imported yesterday and never analysed: stays on the button.
        $old = $this->meeting(['fireflies_id' => 'old', 'summary' => 'ok']);
        Meeting::query()->whereKey($old->id)->update(['created_at' => now()->subDay()]);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['speakers' => [], 'tasks' => [
            ['title' => 'Сделать отчёт', 'details' => '', 'assignee_name' => null, 'assignee_user_id' => null, 'speaker' => 'A', 'deadline' => null, 'deadline_phrase' => null, 'quote' => 'q', 'start_seconds' => 1],
        ]])]]]])]);

        $this->artisan('meetings:import --new-only --analyze-new')
            ->expectsOutput('New meetings: 1')
            ->expectsOutputToContain('Analysed: Meeting fresh → 1 drafts')
            ->assertSuccessful();

        $fresh = Meeting::query()->where('fireflies_id', 'fresh')->first();
        $this->assertNotNull($fresh->analyzed_at);
        $this->assertNull($fresh->analysis_started_at);
        $this->assertSame(1, $fresh->drafts()->count());
        $this->assertNull($old->fresh()->analyzed_at);
        // Drafts only: nothing becomes a task by itself.
        $this->assertSame(0, Task::query()->count());
        Http::assertSentCount(3); // Fireflies list + transcript + one OpenAI call

        // The next run does not analyse it again.
        $this->artisan('meetings:import --new-only --analyze-new')->doesntExpectOutputToContain('Analysed')->assertSuccessful();
    }

    public function test_failed_automatic_analysis_is_retried_only_while_the_meeting_is_fresh(): void
    {
        config(['services.openai.api_key' => 'test']);
        $fetched = [];
        $summary = 'ok';
        $this->fakeFireflies(['fresh'], $fetched, $summary);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'no credits']], 429)]);

        $this->artisan('meetings:import --new-only --analyze-new')->expectsOutputToContain('Analysis failed: Meeting fresh: OpenAI: no credits')->assertSuccessful();
        $this->artisan('meetings:import --new-only --analyze-new')->expectsOutputToContain('Analysis failed')->assertSuccessful();

        Meeting::query()->where('fireflies_id', 'fresh')->update(['created_at' => now()->subHours(4)]);
        $this->artisan('meetings:import --new-only --analyze-new')->doesntExpectOutputToContain('Analysis')->assertSuccessful();
        $this->assertNull(Meeting::query()->where('fireflies_id', 'fresh')->value('analysis_started_at'));
    }

    public function test_import_is_scheduled_hourly_on_weekdays(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'meetings:import'));

        $this->assertNotNull($event);
        $this->assertStringContainsString('--new-only', $event->command);
        $this->assertStringContainsString('--analyze-new', $event->command);
        $this->assertSame('5 * * * 1-5', $event->expression);
        $this->assertTrue($event->runInBackground);
    }

    public function test_import_copies_a_fireflies_meeting_and_is_idempotent(): void
    {
        config(['services.fireflies.api_key' => 'test']);
        Http::fake(['api.fireflies.ai/*' => Http::response(['data' => ['transcript' => [
            'id' => 'ff-1', 'title' => 'Weekly', 'date' => 1762338600000, 'duration' => 107.06,
            'organizer_email' => 'assistant@tcsavant.com', 'meeting_link' => 'https://meet.google.com/x',
            'meeting_attendees' => [['displayName' => null, 'email' => 'rop@tcsavant.com', 'name' => null]],
            'sentences' => [['speaker_name' => 'Сергій Саламаха', 'text' => 'Добрый день', 'start_time' => 1.5, 'end_time' => 2.5], ['speaker_name' => 'X', 'text' => ' ', 'start_time' => 3, 'end_time' => 4]],
            'summary' => ['keywords' => ['Продажи'], 'action_items' => '**Сергій**', 'overview' => '- **x**', 'short_summary' => 'Коротко'],
        ]]])]);

        $importer = app(MeetingImporter::class);
        $importer->import('ff-1');
        $meeting = $importer->import('ff-1');

        $this->assertSame(1, Meeting::query()->count());
        $this->assertSame('Коротко', $meeting->summary);
        $this->assertSame(107, $meeting->duration_minutes);
        $this->assertCount(1, $meeting->sentences);
        // 2025-11-05 10:30 UTC is 12:30 in Kyiv.
        $this->assertSame('12:30', $meeting->fresh()->started_at->timezone('Europe/Kyiv')->format('H:i'));
    }

    /** @param array{0: ?User, 1: string} $pair */
    private function ids(array $pair): array
    {
        return [$pair[0]?->id, $pair[1]];
    }
}
