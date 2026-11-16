<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Category;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\Services\ExcelTaskImportService;
use App\Services\TaskService;
use App\Support\Deadline;
use App\Support\DeadlineBackfill;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class DeadlineEndOfDayTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{0: mixed, 1: ?string}> */
    public static function inputs(): array
    {
        return [
            'bare date' => ['2026-11-19', '2026-11-19 23:59:00'],
            'local datetime keeps its date' => ['2026-11-19 14:30', '2026-11-19 23:59:00'],
            'local midnight' => ['2026-11-19 00:00:00', '2026-11-19 23:59:00'],
            'utc midnight is the date as written' => ['2026-11-19T00:00:00Z', '2026-11-19 23:59:00'],
            'kyiv midnight sent as utc (winter)' => ['2026-11-18T22:00:00Z', '2026-11-19 23:59:00'],
            'kyiv midnight sent as utc (summer)' => ['2026-07-14T21:00:00Z', '2026-07-15 23:59:00'],
            'late evening kyiv sent as utc' => ['2026-11-19T21:30:00Z', '2026-11-19 23:59:00'],
            'offset east of kyiv, small hours' => ['2026-11-19T00:30:00+03:00', '2026-11-18 23:59:00'],
            'offset west of kyiv, evening' => ['2026-11-19T23:30:00-05:00', '2026-11-20 23:59:00'],
            'already end of day' => ['2026-11-19 23:59:00', '2026-11-19 23:59:00'],
            'day of spring clock change' => ['2026-03-29', '2026-03-29 23:59:00'],
            'day of autumn clock change' => ['2026-10-25', '2026-10-25 23:59:00'],
            'carbon at utc midnight (excel cell)' => [Carbon::parse('2026-07-15 00:00:00', 'UTC'), '2026-07-15 23:59:00'],
            'carbon in kyiv with a time' => [Carbon::parse('2026-07-15 09:00:00', 'Europe/Kyiv'), '2026-07-15 23:59:00'],
            'empty string' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('inputs')]
    public function test_any_input_lands_on_the_end_of_its_day_in_kyiv(mixed $input, ?string $expected): void
    {
        $result = Deadline::normalize($input);

        $this->assertSame($expected, $result?->format('Y-m-d H:i:s'));

        if ($result !== null) {
            $this->assertSame('Europe/Kyiv', $result->timezoneName);
        }
    }

    public function test_format_hides_midnight_and_end_of_day_but_keeps_a_real_time(): void
    {
        $this->assertSame('15.07.2026', Deadline::format('2026-07-15 00:00:00'));
        $this->assertSame('15.07.2026', Deadline::format('2026-07-15 23:59:00'));
        $this->assertSame('15.07.2026 14:30', Deadline::format('2026-07-15 14:30:00'));
        $this->assertSame('', Deadline::format(null));
    }

    public function test_created_and_edited_tasks_store_the_end_of_day(): void
    {
        [$user, $category] = $this->userAndCategory();
        $this->actingAs($user);

        $task = app(TaskService::class)->create($user, [
            'title' => 'Deadline task',
            'description' => 'Body',
            'priority' => 5,
            'department_id' => $user->department_id,
            'assignee_id' => $user->id,
            'category_id' => $category->id,
            'deadline' => '2026-12-01',
        ]);

        $this->assertSame('2026-12-01 23:59:00', $task->fresh()->deadline->toDateTimeString());

        app(TaskService::class)->update($task, $user, ['deadline' => '2026-12-05T00:00:00Z']);

        $this->assertSame('2026-12-05 23:59:00', $task->fresh()->deadline->toDateTimeString());

        $subtask = app(TaskService::class)->createSubtask($user, $task, ['title' => 'Child', 'deadline' => '2026-12-03']);

        $this->assertSame('2026-12-03 23:59:00', $subtask->fresh()->deadline->toDateTimeString());
    }

    public function test_saving_the_same_date_on_a_legacy_midnight_deadline_writes_no_history(): void
    {
        [$user, $category] = $this->userAndCategory();
        $task = $this->createTask($user, $user, $category, ['deadline' => '2026-07-10 00:00:00']);

        app(TaskService::class)->update($task, $user, ['deadline' => '2026-07-10', 'title' => 'Renamed']);

        $this->assertSame('2026-07-10 00:00:00', $task->fresh()->deadline->toDateTimeString());
        $this->assertSame(0, TaskHistory::query()->where('task_id', $task->id)->where('field', 'deadline')->count());
    }

    public function test_due_today_is_not_overdue_until_the_day_ends(): void
    {
        [$user, $category] = $this->userAndCategory();
        Carbon::setTestNow(Carbon::parse('2026-11-11 08:00:00', 'Europe/Kyiv'));

        $today = $this->createTask($user, $user, $category, ['deadline' => '2026-11-11 23:59:00', 'status' => TaskStatus::InProgress]);
        $yesterday = $this->createTask($user, $user, $category, ['deadline' => '2026-11-10 23:59:00', 'status' => TaskStatus::InProgress]);
        $tomorrow = $this->createTask($user, $user, $category, ['deadline' => '2026-11-12 23:59:00', 'status' => TaskStatus::InProgress]);
        $nextWeek = $this->createTask($user, $user, $category, ['deadline' => '2026-11-18 23:59:00', 'status' => TaskStatus::InProgress]);
        $longOverdue = $this->createTask($user, $user, $category, ['deadline' => '2026-11-01 23:59:00', 'status' => TaskStatus::InProgress]);

        $this->assertSame(0, Deadline::daysFromToday($today->deadline));
        $this->assertSame(__(':date (today)', ['date' => '11.11.2026']), Deadline::meta($today)['text']);
        $this->assertStringContainsString('amber', Deadline::meta($today)['class']);

        // Overdue since last midnight, eight hours ago: that is one day, not zero.
        $this->assertSame(__(':date (overdue :count days)', ['date' => '10.11.2026', 'count' => 1]), Deadline::meta($yesterday)['text']);
        $this->assertStringContainsString('red', Deadline::meta($yesterday)['class']);
        $this->assertSame(__(':date (overdue :count days)', ['date' => '01.11.2026', 'count' => 10]), Deadline::meta($longOverdue)['text']);

        $this->assertSame(__(':date (tomorrow)', ['date' => '12.11.2026']), Deadline::meta($tomorrow)['text']);
        $this->assertSame('18.11.2026', Deadline::meta($nextWeek)['text']);

        $overdueIds = Task::query()->where('deadline', '<', now())->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$yesterday->id, $longOverdue->id], $overdueIds);
    }

    public function test_backfill_moves_every_stored_time_to_the_end_of_the_same_date(): void
    {
        [$user, $category] = $this->userAndCategory();
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00', 'Europe/Kyiv'));

        $midnight = $this->createTask($user, $user, $category, ['deadline' => '2026-12-01 00:00:00']);
        $excelWinter = $this->createTask($user, $user, $category, ['deadline' => '2026-12-02 02:00:00']);
        $excelSummer = $this->createTask($user, $user, $category, ['deadline' => '2026-07-02 03:00:00', 'status' => TaskStatus::Completed]);
        $already = $this->createTask($user, $user, $category, ['deadline' => '2026-12-03 23:59:00']);
        $none = $this->createTask($user, $user, $category, ['deadline' => null]);
        $deleted = $this->createTask($user, $user, $category, ['deadline' => '2026-12-04 00:00:00']);
        $deleted->delete();
        $updatedAt = $this->raw($midnight->id)->updated_at;

        $stats = (new DeadlineBackfill)->apply();

        $this->assertSame(4, $stats['moved']);
        $this->assertSame('2026-12-01 23:59:00', $this->raw($midnight->id)->deadline);
        $this->assertSame('2026-12-02 23:59:00', $this->raw($excelWinter->id)->deadline);
        $this->assertSame('2026-07-02 23:59:00', $this->raw($excelSummer->id)->deadline);
        $this->assertSame('2026-12-03 23:59:00', $this->raw($already->id)->deadline);
        $this->assertNull($this->raw($none->id)->deadline);
        $this->assertSame('2026-12-04 23:59:00', $this->raw($deleted->id)->deadline);
        $this->assertSame($updatedAt, $this->raw($midnight->id)->updated_at);
        $this->assertSame(0, TaskHistory::query()->count());
        $this->assertSame(0, DB::table('tasks')->whereNotNull('deadline')->where('deadline', 'not like', '% 23:59:00')->count());

        // A second run finds nothing to do.
        $this->assertSame(0, (new DeadlineBackfill)->apply()['moved']);
    }

    public function test_backfill_touches_reminder_flags_only_for_tasks_that_stop_being_overdue(): void
    {
        [$user, $category] = $this->userAndCategory();
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00', 'Europe/Kyiv'));
        $notified = '2026-11-11 00:30:00';

        // Due today: was "overdue" since midnight, now runs until 23:59.
        $dueToday = $this->createTask($user, $user, $category, [
            'deadline' => '2026-11-11 00:00:00', 'status' => TaskStatus::InProgress,
            'overdue_notified_at' => $notified, 'deadline_reminder_sent_at' => null,
        ]);
        // Overdue for real: stays overdue, flags stay.
        $overdue = $this->createTask($user, $user, $category, [
            'deadline' => '2026-11-05 00:00:00', 'status' => TaskStatus::InProgress,
            'overdue_notified_at' => $notified, 'deadline_reminder_sent_at' => null,
        ]);
        // Ten days ahead, never reminded: must still get its reminder later.
        $future = $this->createTask($user, $user, $category, [
            'deadline' => '2026-11-21 00:00:00', 'status' => TaskStatus::InProgress,
        ]);
        // Closed task due today: nothing to remind about.
        $closed = $this->createTask($user, $user, $category, [
            'deadline' => '2026-11-11 00:00:00', 'status' => TaskStatus::Completed,
            'overdue_notified_at' => $notified,
        ]);

        $stats = (new DeadlineBackfill)->apply();

        $this->assertSame(1, $stats['overdue_flag_cleared']);
        $this->assertSame(1, $stats['reminder_flag_set']);

        $this->assertNull($this->raw($dueToday->id)->overdue_notified_at);
        $this->assertSame('2026-11-11 10:00:00', $this->raw($dueToday->id)->deadline_reminder_sent_at);

        $this->assertSame($notified, $this->raw($overdue->id)->overdue_notified_at);
        $this->assertNull($this->raw($overdue->id)->deadline_reminder_sent_at);

        $this->assertNull($this->raw($future->id)->overdue_notified_at);
        $this->assertNull($this->raw($future->id)->deadline_reminder_sent_at);

        $this->assertSame($notified, $this->raw($closed->id)->overdue_notified_at);

        // The scheduler right after the backfill: no "due within a day" for the task due today.
        $this->artisan('tasks:send-reminders', ['--dry-run' => true])
            ->expectsOutputToContain('deadline approaching: 0')
            ->assertSuccessful();
    }

    public function test_revert_restores_only_what_the_backfill_wrote(): void
    {
        [$user, $category] = $this->userAndCategory();
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00', 'Europe/Kyiv'));

        $untouched = $this->createTask($user, $user, $category, [
            'deadline' => '2026-11-11 00:00:00', 'status' => TaskStatus::InProgress,
            'overdue_notified_at' => '2026-11-11 00:30:00',
        ]);
        $editedByPerson = $this->createTask($user, $user, $category, ['deadline' => '2026-11-12 00:00:00']);
        $remindedLater = $this->createTask($user, $user, $category, [
            'deadline' => '2026-11-11 00:00:00', 'status' => TaskStatus::InProgress,
        ]);
        $futureRemindedLater = $this->createTask($user, $user, $category, [
            'deadline' => '2026-11-13 00:00:00', 'status' => TaskStatus::InProgress,
        ]);

        (new DeadlineBackfill)->apply();

        // After the backfill: a person moves one deadline, the scheduler reminds about two others.
        DB::table('tasks')->where('id', $editedByPerson->id)->update(['deadline' => '2026-11-20 23:59:00']);
        DB::table('tasks')->where('id', $remindedLater->id)->update(['deadline_reminder_sent_at' => '2026-11-11 10:30:00']);
        DB::table('tasks')->where('id', $futureRemindedLater->id)->update(['deadline_reminder_sent_at' => '2026-11-12 23:59:30']);

        $stats = (new DeadlineBackfill)->revert();

        $this->assertSame(['restored' => 3, 'kept' => 1], $stats);
        $this->assertFalse(Schema::hasTable(DeadlineBackfill::BACKUP_TABLE));

        $this->assertSame('2026-11-11 00:00:00', $this->raw($untouched->id)->deadline);
        $this->assertSame('2026-11-11 00:30:00', $this->raw($untouched->id)->overdue_notified_at);
        $this->assertNull($this->raw($untouched->id)->deadline_reminder_sent_at);

        // The person's edit survives.
        $this->assertSame('2026-11-20 23:59:00', $this->raw($editedByPerson->id)->deadline);

        // Flags the scheduler set after the backfill are real sends: they stay, so nothing is sent twice.
        $this->assertSame('2026-11-11 10:30:00', $this->raw($remindedLater->id)->deadline_reminder_sent_at);
        $this->assertSame('2026-11-12 23:59:30', $this->raw($futureRemindedLater->id)->deadline_reminder_sent_at);
    }

    public function test_excel_date_cell_becomes_the_end_of_the_written_date_and_created_at_parsing_is_untouched(): void
    {
        $import = app(ExcelTaskImportService::class);
        $deadlineParser = new \ReflectionMethod($import, 'parseExcelDeadline');
        $dateParser = new \ReflectionMethod($import, 'parseDate');

        // 46218 is the Excel serial for 2026-07-15, 46345 for 2026-11-19 (summer and winter offsets).
        foreach ([46218 => '2026-07-15', 46345 => '2026-11-19'] as $serial => $date) {
            $this->assertSame(
                $date.' 23:59:00',
                Deadline::normalize($deadlineParser->invoke($import, $serial))->format('Y-m-d H:i:s'),
            );
        }

        $this->assertSame(
            '2026-07-15 23:59:00',
            Deadline::normalize($deadlineParser->invoke($import, '15.07.2026'))->format('Y-m-d H:i:s'),
        );
        $this->assertNull(Deadline::normalize($deadlineParser->invoke($import, null)));

        // created_at keeps the old behaviour: the UTC midnight of the cell shown in Kyiv time.
        $this->assertSame('2026-07-15 03:00:00', $dateParser->invoke($import, 46218)->format('Y-m-d H:i:s'));
    }

    private function raw(int $taskId): object
    {
        return DB::table('tasks')->where('id', $taskId)->first();
    }

    /** @return array{0: User, 1: Category} */
    private function userAndCategory(): array
    {
        $dept = $this->createDepartment();

        return [$this->createUserInDepartment($dept, 'Deadline User'), $this->createCategory()];
    }
}
