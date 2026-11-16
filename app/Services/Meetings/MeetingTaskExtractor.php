<?php

namespace App\Services\Meetings;

use App\Models\Meeting;
use App\Models\MeetingTaskDraft;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Reads a meeting transcript with an OpenAI model and turns what people agreed
 * to do into task drafts.
 *
 * The model only says WHAT was agreed, WHO was named and WHO said it. Who gets
 * the task is decided here, by the rule from task 456:
 *   named person was in the meeting → on them;
 *   named person was not there      → on the speaker (the named one is mentioned);
 *   nobody named                    → on the speaker;
 *   speaker is not ours / unknown   → on the organizer.
 */
class MeetingTaskExtractor
{
    public function __construct(private MeetingPeople $people) {}

    public function configured(): bool
    {
        return filled(config('services.openai.api_key'));
    }

    /** Replaces the meeting's open drafts; drafts that already became tasks stay. */
    public function analyze(Meeting $meeting): int
    {
        try {
            $result = $this->ask($meeting);
        } catch (Throwable $e) {
            $meeting->update(['analysis_error' => mb_substr($e->getMessage(), 0, 1000)]);
            throw $e;
        }

        return $this->store($meeting, $result, (string) config('services.openai.meeting_model'));
    }

    /**
     * @param  array{speakers?: list<array{speaker: string, user_id: ?int, external: bool}>, tasks?: list<array<string, mixed>>}  $result
     */
    public function store(Meeting $meeting, array $result, string $model): int
    {
        $userIds = $this->people->users()->pluck('id')->all();

        $speakerMap = [];
        foreach ($result['speakers'] ?? [] as $row) {
            $name = trim((string) ($row['speaker'] ?? ''));
            if ($name === '') {
                continue;
            }
            if (! empty($row['external'])) {
                $speakerMap[$name] = 'external';
            } elseif (isset($row['user_id']) && in_array((int) $row['user_id'], $userIds, true)) {
                $speakerMap[$name] = (int) $row['user_id'];
            }
        }

        return DB::transaction(function () use ($meeting, $result, $model, $speakerMap, $userIds) {
            $meeting->update([
                'speaker_map' => $speakerMap,
                'analyzed_at' => now(),
                'analysis_model' => $model,
                'analysis_error' => null,
            ]);
            $meeting->refresh();

            $meeting->drafts()->where('status', MeetingTaskDraft::STATUS_DRAFT)->delete();

            $present = $this->people->presentUserIds($meeting);
            $organizer = $this->people->organizer($meeting);
            $order = 0;

            foreach ($result['tasks'] ?? [] as $task) {
                $title = trim((string) ($task['title'] ?? ''));
                if ($title === '') {
                    continue;
                }

                $speakerName = trim((string) ($task['speaker'] ?? '')) ?: null;
                $speaker = $speakerName ? $this->people->resolve(null, $speakerName, $meeting) : ['user' => null, 'external' => false];
                $speakerUser = $speaker['external'] ? null : $speaker['user'];

                $mentionedName = trim((string) ($task['assignee_name'] ?? '')) ?: null;
                $mentioned = null;
                if (isset($task['assignee_user_id']) && in_array((int) $task['assignee_user_id'], $userIds, true)) {
                    $mentioned = $this->people->users()->firstWhere('id', (int) $task['assignee_user_id']);
                } elseif ($mentionedName) {
                    $r = $this->people->resolve(null, $mentionedName, $meeting);
                    $mentioned = $r['external'] ? null : $r['user'];
                }

                [$assignee, $reason] = $this->decide($mentioned, $mentionedName, $speakerUser, $organizer, $present);

                MeetingTaskDraft::query()->create([
                    'meeting_id' => $meeting->id,
                    'title' => mb_substr($title, 0, 250),
                    'description' => trim((string) ($task['details'] ?? '')) ?: null,
                    'assignee_id' => $assignee?->id,
                    'assignment_reason' => $reason,
                    'mentioned_name' => $mentionedName,
                    'mentioned_user_id' => $mentioned?->id,
                    'speaker_name' => $speakerName,
                    'speaker_user_id' => $speakerUser?->id,
                    'deadline' => $this->date($task['deadline'] ?? null),
                    'deadline_phrase' => trim((string) ($task['deadline_phrase'] ?? '')) ?: null,
                    'quote' => trim((string) ($task['quote'] ?? '')) ?: null,
                    'start_seconds' => isset($task['start_seconds']) && is_numeric($task['start_seconds']) ? (float) $task['start_seconds'] : null,
                    'status' => MeetingTaskDraft::STATUS_DRAFT,
                    'sort_order' => $order++,
                ]);
            }

            return $order;
        });
    }

    /**
     * After someone fixed who is who on the meeting page: apply the rule again to
     * open drafts. A draft whose assignee a person picked by hand is left alone.
     */
    public function reassign(Meeting $meeting): void
    {
        $present = $this->people->presentUserIds($meeting);
        $organizer = $this->people->organizer($meeting);

        foreach ($meeting->drafts()->where('status', MeetingTaskDraft::STATUS_DRAFT)->get() as $draft) {
            if ($draft->assignment_reason === 'manual') {
                continue;
            }

            $speaker = $draft->speaker_name ? $this->people->resolve(null, $draft->speaker_name, $meeting) : ['user' => null, 'external' => false];
            $speakerUser = $speaker['external'] ? null : $speaker['user'];

            $mentioned = $draft->mentionedUser;
            if (! $mentioned && $draft->mentioned_name) {
                $r = $this->people->resolve(null, $draft->mentioned_name, $meeting);
                $mentioned = $r['external'] ? null : $r['user'];
            }

            [$assignee, $reason] = $this->decide($mentioned, $draft->mentioned_name, $speakerUser, $organizer, $present);

            $draft->update([
                'assignee_id' => $assignee?->id,
                'assignment_reason' => $reason,
                'speaker_user_id' => $speakerUser?->id,
                'mentioned_user_id' => $mentioned?->id,
            ]);
        }
    }

    /**
     * @param  list<int>  $present
     * @return array{0: ?User, 1: string}
     */
    public function decide(?User $mentioned, ?string $mentionedName, ?User $speaker, ?User $organizer, array $present): array
    {
        if ($mentioned && in_array($mentioned->id, $present, true)) {
            return [$mentioned, 'named'];
        }
        if (($mentioned || $mentionedName) && $speaker) {
            return [$speaker, 'absent_speaker'];
        }
        if ($speaker) {
            return [$speaker, 'speaker'];
        }
        if ($organizer) {
            return [$organizer, 'organizer'];
        }

        return [$mentioned, $mentioned ? 'named' : 'unassigned'];
    }

    /** @return array<string, mixed> */
    private function ask(Meeting $meeting): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('OpenAI API key is not set (OPENAI_API_KEY).');
        }

        $roster = $this->people->users()
            ->map(fn (User $u) => "{$u->id} | {$u->name} | {$u->email} | ".($u->department?->name ?? '—'))
            ->implode("\n");

        $attendees = collect($meeting->attendees ?? [])
            ->map(fn ($a) => trim(($a['name'] ?? '').' '.($a['email'] ?? '')))
            ->filter()
            ->implode(', ');

        $started = $meeting->started_at->copy()->timezone('Europe/Kyiv');
        $weekday = $started->locale('ru')->isoFormat('dddd');

        $system = <<<'TXT'
        Ты помощник руководителя компании AVANT (морской учебный центр). Тебе дают транскрипт рабочей встречи.
        Найди ДОГОВОРЁННОСТИ О ДЕЛАХ: что конкретный человек должен сделать после встречи — ему поручили или он сам взялся.
        Не включай: общие рассуждения, отчёты о сделанном, пожелания без исполнителя и без действия, то, что сделали прямо на встрече.
        Одна задача = одно действие. Повторы одной и той же договорённости объединяй. Не больше 40 задач.
        Пройди транскрипт ПОСЛЕДОВАТЕЛЬНО от начала до конца, по каждому выступающему: на еженедельной встрече обычно 15–35 таких договорённостей,
        и у каждого отчитывающегося (продажи, маркетинг, IT, учебный отдел, бухгалтерия) почти всегда есть свои. Не пропускай короткие «сделаю», «возьму», «посмотрю».

        Для каждой задачи:
        - title: коротко, по-русски, до 90 символов, ВСЕГДА с глагола в неопределённой форме («Подготовить…», «Проверить…», «Выслать…»), не «Вышли…» и не «Подготовьте…».
        - details: 1–3 предложения контекста, чтобы исполнитель понял задачу без записи. По-русски.
        - assignee_name: кому поручили, как его назвали на встрече (или null, если не назвали).
        - assignee_user_id: id из справочника сотрудников, если уверен, кто это; иначе null. Не угадывай.
        - speaker: ТОЧНО как подписан в транскрипте тот, кто произнёс поручение или взял задачу на себя.
        - deadline: дата YYYY-MM-DD, если срок назван («до пятницы», «к 28-му»), считая от даты встречи; иначе null.
        - deadline_phrase: как срок прозвучал дословно, или null.
        - quote: дословная короткая цитата (до 200 символов), где это прозвучало.
        - start_seconds: время начала цитаты в секундах (число из меток [секунды]).

        Также для каждого спикера транскрипта укажи user_id из справочника, если это сотрудник (имена бывают латиницей,
        по-украински, без фамилии), или external=true, если это человек со стороны (подрядчик, клиент, партнёр).
        TXT;

        $user = "Встреча: «{$meeting->title}», {$started->format('Y-m-d H:i')} ({$weekday}), Киев.\n"
            ."Приглашены: {$attendees}\n\n"
            ."Справочник сотрудников (id | имя | email | отдел):\n{$roster}\n\n"
            ."Транскрипт (в квадратных скобках — секунда начала реплики):\n".$this->transcriptText($meeting);

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['speakers', 'tasks'],
            'properties' => [
                'speakers' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['speaker', 'user_id', 'external'],
                        'properties' => [
                            'speaker' => ['type' => 'string'],
                            'user_id' => ['type' => ['integer', 'null']],
                            'external' => ['type' => 'boolean'],
                        ],
                    ],
                ],
                'tasks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'details', 'assignee_name', 'assignee_user_id', 'speaker', 'deadline', 'deadline_phrase', 'quote', 'start_seconds'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'details' => ['type' => 'string'],
                            'assignee_name' => ['type' => ['string', 'null']],
                            'assignee_user_id' => ['type' => ['integer', 'null']],
                            'speaker' => ['type' => 'string'],
                            'deadline' => ['type' => ['string', 'null']],
                            'deadline_phrase' => ['type' => ['string', 'null']],
                            'quote' => ['type' => 'string'],
                            'start_seconds' => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
        ];

        $response = Http::withToken((string) config('services.openai.api_key'))
            ->acceptJson()
            ->timeout(300)
            ->post(rtrim((string) config('services.openai.url'), '/').'/chat/completions', [
                'model' => (string) config('services.openai.meeting_model'),
                'reasoning_effort' => (string) config('services.openai.meeting_reasoning', 'medium'),
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => 'meeting_tasks', 'strict' => true, 'schema' => $schema],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('OpenAI: '.($response->json('error.message') ?? 'HTTP '.$response->status()));
        }

        $content = $response->json('choices.0.message.content');
        $decoded = is_string($content) ? json_decode($content, true) : null;
        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI: the answer is not the expected JSON.');
        }

        return $decoded;
    }

    /** Consecutive sentences of one speaker are joined: the text gets about a third shorter. */
    private function transcriptText(Meeting $meeting): string
    {
        $lines = [];
        $current = null;
        foreach ($meeting->sentences ?? [] as $s) {
            if ($current && $current['speaker'] === $s['speaker'] && mb_strlen($current['text']) < 1200) {
                $current['text'] .= ' '.$s['text'];

                continue;
            }
            if ($current) {
                $lines[] = '['.(int) $current['start'].'] '.$current['speaker'].': '.$current['text'];
            }
            $current = $s;
        }
        if ($current) {
            $lines[] = '['.(int) $current['start'].'] '.$current['speaker'].': '.$current['text'];
        }

        return implode("\n", $lines);
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
