<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

class TelegramGroupMessageBuilder
{
    private const DESCRIPTION_LIMIT = 400;

    /** Priority from which the level is shown even in status/comment messages. */
    private const PRIORITY_HIGHLIGHT_FROM = 7;

    private const ROLE_EMOJI = [
        'initiator' => '🙋',
        'assignee' => '🎯',
        'watcher' => '👀',
        'mentioned' => '📣',
        'replied' => '💬',
        'reacted' => '👍',
    ];

    public function __construct(private TelegramMentionFormatter $mentions) {}

    public function forCreated(Task $task, User $actor): string
    {
        $lines = [$this->headerLine('🆕', 'notification.group.created_header', $task)];

        $this->pushBlock($lines, [$this->descriptionLine($task)]);
        $this->pushBlock($lines, [
            $this->roleLine(
                $this->usersForRole($task, $actor, 'assignee'),
                'notification.group.created_assignee',
                role: 'assignee',
            ),
            $this->roleLine(
                $this->usersForRole($task, $actor, 'watcher'),
                'notification.group.created_watchers',
                role: 'watcher',
            ),
        ]);
        $this->pushBlock($lines, $this->metaLines($task));
        $this->pushBlock($lines, [$this->openLink($task)]);

        return $this->join($lines);
    }

    public function forReassigned(Task $task, User $actor): string
    {
        $lines = [$this->headerLine('🔄', 'notification.group.reassigned_header', $task)];

        $this->pushBlock($lines, [$this->descriptionLine($task)]);
        $this->pushBlock($lines, [
            $this->roleLine(
                $this->usersForRole($task, $actor, 'assignee'),
                'notification.group.reassigned_assignee',
                role: 'assignee',
            ),
            $this->roleLine(
                $this->usersForRole($task, $actor, 'watcher'),
                'notification.group.reassigned_watchers',
                role: 'watcher',
            ),
        ]);
        $this->pushBlock($lines, $this->metaLines($task));
        $this->pushBlock($lines, [$this->openLink($task)]);

        return $this->join($lines);
    }

    public function forStatusChanged(
        Task $task,
        User $actor,
        TaskStatus $from,
        TaskStatus $to,
        ?string $reasonExcerpt = null,
    ): string {
        $old = $this->statusLabel($from);
        $new = $this->statusLabel($to);

        $lines = [
            $this->statusEmoji($to).' <b>'.$this->t('notification.group.status_header', [
                'number' => (string) $task->number,
                'title' => $this->mentions->escape((string) $task->title),
                'old' => $this->mentions->escape($old),
                'new' => $this->mentions->escape($new),
            ]).'</b>',
        ];

        $statusReplace = [
            'old' => $this->mentions->escape($old),
            'new' => $this->mentions->escape($new),
        ];

        $this->pushBlock($lines, [
            $this->roleLine(
                $this->usersForRole($task, $actor, 'initiator'),
                $this->initiatorStatusKey($to),
                $statusReplace,
                'initiator',
            ),
            $this->roleLine(
                $this->usersForRole($task, $actor, 'assignee'),
                $this->assigneeStatusKey($from, $to),
                $statusReplace,
                'assignee',
            ),
            $this->roleLine(
                $this->usersForRole($task, $actor, 'watcher'),
                'notification.group.status_watchers',
                $statusReplace,
                'watcher',
            ),
        ]);
        $this->pushBlock($lines, [$this->highlightedPriorityLine($task)]);
        $this->pushBlock($lines, [$this->quotedLine($actor, $reasonExcerpt)]);
        $this->pushBlock($lines, [$this->openLink($task)]);

        return $this->join($lines);
    }

    /**
     * @param  Collection<int, User>  $mentioned
     * @param  Collection<int, User>  $repliedTo
     */
    public function forCommented(
        Task $task,
        User $actor,
        string $excerpt,
        Collection $mentioned,
        Collection $repliedTo,
    ): string {
        $lines = [$this->headerLine('💬', 'notification.group.commented_header', $task)];

        $roles = $this->commentRoles($task, $actor, $mentioned, $repliedTo);

        $this->pushBlock($lines, [
            $this->roleLine($roles['initiator'], 'notification.group.commented_initiator', role: 'initiator'),
            $this->roleLine($roles['assignee'], 'notification.group.commented_assignee', role: 'assignee'),
            $this->roleLine($roles['watcher'], 'notification.group.commented_watchers', role: 'watcher'),
            $this->roleLine($roles['mentioned'], 'notification.group.commented_mentioned', role: 'mentioned'),
            $this->roleLine($roles['replied'], 'notification.group.commented_replied', role: 'replied'),
        ]);
        $this->pushBlock($lines, [$this->highlightedPriorityLine($task)]);
        $this->pushBlock($lines, [$this->quotedLine($actor, $excerpt)]);
        $this->pushBlock($lines, [$this->openLink($task)]);

        return $this->join($lines);
    }

    public function forReacted(
        Task $task,
        User $actor,
        User $author,
        string $excerpt,
        string $emoji,
    ): string {
        $lines = [$this->headerLine($emoji, 'notification.group.reacted_header', $task)];

        $this->pushBlock($lines, [
            $this->roleLine([$author], 'notification.group.reacted_author', [
                'actor' => $this->mentions->escape($actor->name),
                'emoji' => $emoji,
            ], 'reacted'),
        ]);
        $this->pushBlock($lines, [$this->quotedLine($actor, $excerpt)]);
        $this->pushBlock($lines, [$this->openLink($task)]);

        return $this->join($lines);
    }

    /**
     * @param  Collection<int, User>  $mentioned
     * @param  Collection<int, User>  $repliedTo
     * @return array{initiator: list<User>, assignee: list<User>, watcher: list<User>, mentioned: list<User>, replied: list<User>}
     */
    private function commentRoles(Task $task, User $actor, Collection $mentioned, Collection $repliedTo): array
    {
        $tagAssignee = (bool) config('services.telegram.group_tag_assignee_on_comment', true);
        $byId = [];

        foreach ($task->watchers as $user) {
            $byId[$user->id] = ['user' => $user, 'role' => 'watcher'];
        }

        if ($tagAssignee && $task->assignee) {
            $byId[$task->assignee->id] = ['user' => $task->assignee, 'role' => 'assignee'];
        }

        if ($task->initiator) {
            $byId[$task->initiator->id] = ['user' => $task->initiator, 'role' => 'initiator'];
        }

        // MentionService adds mentioned users as watchers before we build the message,
        // so an explicit mention must win over the watcher role to keep its own wording.
        foreach ($mentioned as $user) {
            if (! isset($byId[$user->id]) || $byId[$user->id]['role'] === 'watcher') {
                $byId[$user->id] = ['user' => $user, 'role' => 'mentioned'];
            }
        }

        foreach ($repliedTo as $user) {
            $byId[$user->id] = ['user' => $user, 'role' => 'replied'];
        }

        unset($byId[$actor->id]);

        $grouped = [
            'initiator' => [],
            'assignee' => [],
            'watcher' => [],
            'mentioned' => [],
            'replied' => [],
        ];

        foreach ($byId as $row) {
            /** @var User $user */
            $user = $row['user'];
            if (! $user->is_active) {
                continue;
            }
            $grouped[$row['role']][] = $user;
        }

        return $grouped;
    }

    /**
     * @return list<User>
     */
    private function usersForRole(Task $task, User $actor, string $role): array
    {
        $byId = [];

        foreach ($task->watchers as $user) {
            $byId[$user->id] = ['user' => $user, 'role' => 'watcher'];
        }

        if ($task->assignee) {
            $byId[$task->assignee->id] = ['user' => $task->assignee, 'role' => 'assignee'];
        }

        if ($task->initiator) {
            $byId[$task->initiator->id] = ['user' => $task->initiator, 'role' => 'initiator'];
        }

        unset($byId[$actor->id]);

        $users = [];

        foreach ($byId as $row) {
            /** @var User $user */
            $user = $row['user'];
            if ($row['role'] === $role && $user->is_active) {
                $users[] = $user;
            }
        }

        return $users;
    }

    /**
     * @param  list<User>  $users
     * @param  array<string, string>  $replace
     */
    private function roleLine(array $users, string $key, array $replace = [], string $role = ''): ?string
    {
        if ($users === []) {
            return null;
        }

        $line = $this->t($key, [
            'mentions' => $this->mentions->mentions($users),
            ...$replace,
        ]);

        $emoji = self::ROLE_EMOJI[$role] ?? '';

        return $emoji === '' ? $line : $emoji.' '.$line;
    }

    private function headerLine(string $emoji, string $key, Task $task): string
    {
        return $emoji.' <b>'.$this->header($key, $task).'</b>';
    }

    private function header(string $key, Task $task): string
    {
        return $this->t($key, [
            'number' => (string) $task->number,
            'title' => $this->mentions->escape((string) $task->title),
        ]);
    }

    private function descriptionLine(Task $task): ?string
    {
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", $task->plainDescription())));

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > self::DESCRIPTION_LIMIT) {
            $text = mb_substr($text, 0, self::DESCRIPTION_LIMIT).'…';
        }

        return '📝 '.$this->mentions->escape($text);
    }

    private function quotedLine(User $actor, ?string $excerpt): ?string
    {
        if (! filled($excerpt)) {
            return null;
        }

        return $this->t('notification.group.quoted', [
            'actor' => $this->mentions->escape($actor->name),
            'excerpt' => $this->mentions->escape($excerpt),
        ]);
    }

    /**
     * @return list<string>
     */
    private function metaLines(Task $task): array
    {
        $initiator = $task->initiator
            ? $this->mentions->escape($task->initiator->name)
            : '—';

        $deadline = $task->deadline
            ? $task->deadline->timezone(config('app.timezone', 'Europe/Kyiv'))->format('d.m.Y')
            : $this->t('notification.group.no_deadline');

        return [
            '👤 '.$this->t('notification.group.meta_initiator', ['initiator' => $initiator]),
            $this->priorityLine($task),
            '🗓 '.$this->t('notification.group.meta_deadline', ['deadline' => $deadline]),
        ];
    }

    /**
     * Status and comment messages stay compact: the priority is repeated only when
     * it is high enough to change how fast people should react.
     */
    private function highlightedPriorityLine(Task $task): ?string
    {
        return $this->priorityValue($task) >= self::PRIORITY_HIGHLIGHT_FROM
            ? $this->priorityLine($task)
            : null;
    }

    private function priorityLine(Task $task): string
    {
        $value = $this->priorityValue($task);

        [$emoji, $label] = match (true) {
            $value >= 9 => ['🔥', 'priority_critical'],
            $value >= 7 => ['🟠', 'priority_high'],
            $value >= 4 => ['🟡', 'priority_normal'],
            default => ['🟢', 'priority_low'],
        };

        return $emoji.' '.$this->t('notification.group.meta_priority', [
            'value' => (string) $value,
            'label' => $this->t('notification.group.'.$label),
        ]);
    }

    private function priorityValue(Task $task): int
    {
        return max(1, min(10, (int) $task->priority));
    }

    private function statusEmoji(TaskStatus $status): string
    {
        return match ($status) {
            TaskStatus::New => '🆕',
            TaskStatus::InProgress => '▶️',
            TaskStatus::AwaitingInitiator => '⏳',
            TaskStatus::OnReview => '🔍',
            TaskStatus::Rework => '↩️',
            TaskStatus::Completed => '✅',
            TaskStatus::Postponed => '⏸',
            TaskStatus::Rejected => '🚫',
            TaskStatus::Cancelled => '❌',
        };
    }

    private function initiatorStatusKey(TaskStatus $to): string
    {
        return match (true) {
            $to === TaskStatus::OnReview => 'notification.group.status_initiator_on_review',
            $to === TaskStatus::AwaitingInitiator => 'notification.group.status_initiator_awaiting',
            $to === TaskStatus::Completed => 'notification.group.status_initiator_completed',
            default => 'notification.group.status_initiator_generic',
        };
    }

    private function assigneeStatusKey(TaskStatus $from, TaskStatus $to): string
    {
        return match (true) {
            $from === TaskStatus::AwaitingInitiator && $to === TaskStatus::InProgress => 'notification.group.status_assignee_data_ready',
            $to === TaskStatus::Rework => 'notification.group.status_assignee_rework',
            $from === TaskStatus::Postponed && $to === TaskStatus::InProgress => 'notification.group.status_assignee_resumed',
            $from === TaskStatus::Completed && $to === TaskStatus::InProgress => 'notification.group.status_assignee_reopened',
            $to === TaskStatus::Cancelled || $to === TaskStatus::Rejected => 'notification.group.status_assignee_stopped',
            $to === TaskStatus::Completed => 'notification.group.status_assignee_completed',
            $to === TaskStatus::Postponed => 'notification.group.status_assignee_postponed',
            default => 'notification.group.status_assignee_generic',
        };
    }

    private function statusLabel(TaskStatus $status): string
    {
        return (string) __('task.status.'.$status->value, [], 'ru');
    }

    /** @param  array<string, string>  $replace */
    private function t(string $key, array $replace = []): string
    {
        return (string) __($key, $replace, 'ru');
    }

    /**
     * Appends a group of lines separated from the previous one by a blank line,
     * skipping the group (and its separator) when every line is empty.
     *
     * @param  list<string>  $lines
     * @param  list<string|null>  $block
     */
    private function pushBlock(array &$lines, array $block): void
    {
        $block = array_values(array_filter($block, fn (?string $line) => $line !== null && $line !== ''));

        if ($block === []) {
            return;
        }

        if ($lines !== []) {
            $lines[] = '';
        }

        foreach ($block as $line) {
            $lines[] = $line;
        }
    }

    private function openLink(Task $task): string
    {
        $url = $this->mentions->escape(route('tasks.show', $task));
        $label = $this->mentions->escape($this->t('notification.open_task'));

        return '🔗 <a href="'.$url.'">'.$label.'</a>';
    }

    /** @param  list<string>  $lines */
    private function join(array $lines): string
    {
        return trim(implode("\n", $lines));
    }
}
