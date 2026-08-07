<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Category;
use App\Models\Department;
use App\Models\TaskHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TaskHistoryPresenter
{
    /**
     * @return array{field: string, old: ?string, new: ?string}
     */
    public function present(TaskHistory $entry): array
    {
        $presented = $this->presentMany(collect([$entry]))->first();

        return [
            'field' => $presented['field'],
            'old' => $presented['old'],
            'new' => $presented['new'],
        ];
    }

    /**
     * @return Collection<int, array{entry: TaskHistory, field: string, old: ?string, new: ?string}>
     */
    public function presentMany(Collection $entries): Collection
    {
        $context = $this->buildContext($entries);

        return $entries->map(fn (TaskHistory $entry) => [
            'entry' => $entry,
            'field' => $this->presentField($entry->field),
            'old' => $this->presentValue($entry->field, $entry->old_value, $context),
            'new' => $this->presentValue($entry->field, $entry->new_value, $context),
        ]);
    }

    /**
     * @return array{users: array<int, string>, departments: array<int, string>, categories: array<int, string>}
     */
    private function buildContext(Collection $entries): array
    {
        $userIds = [];
        $departmentIds = [];
        $categoryIds = [];

        foreach ($entries as $entry) {
            match ($entry->field) {
                'assignee_id' => $this->collectIds($userIds, $entry->old_value, $entry->new_value),
                'department_id' => $this->collectIds($departmentIds, $entry->old_value, $entry->new_value),
                'category_id' => $this->collectIds($categoryIds, $entry->old_value, $entry->new_value),
                default => null,
            };
        }

        return [
            'users' => $userIds === []
                ? []
                : User::query()->whereIn('id', $userIds)->pluck('name', 'id')->all(),
            'departments' => $departmentIds === []
                ? []
                : Department::query()->whereIn('id', $departmentIds)->pluck('name', 'id')->all(),
            'categories' => $categoryIds === []
                ? []
                : Category::query()->whereIn('id', $categoryIds)->pluck('name', 'id')->all(),
        ];
    }

    /**
     * @param  list<int>  $ids
     */
    private function collectIds(array &$ids, ?string $old, ?string $new): void
    {
        foreach ([$old, $new] as $value) {
            if ($value !== null && $value !== '' && ctype_digit($value)) {
                $ids[] = (int) $value;
            }
        }
    }

    private function presentField(string $field): string
    {
        $key = 'task.history_field.'.$field;
        $label = __($key);

        return $label === $key ? $field : $label;
    }

    /**
     * @param  array{users: array<int, string>, departments: array<int, string>, categories: array<int, string>}  $context
     */
    private function presentValue(string $field, ?string $value, array $context): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($field) {
            'status' => TaskStatus::tryFrom($value)?->label() ?? $value,
            'assignee_id' => $this->resolveUserName($value, $context['users']),
            'department_id' => $this->resolveEntityName($value, $context['departments']),
            'category_id' => $this->resolveEntityName($value, $context['categories']),
            'deadline' => $this->formatDeadline($value),
            'description' => $this->truncateDescription($value),
            default => $value,
        };
    }

    /**
     * @param  array<int, string>  $names
     */
    private function resolveUserName(string $value, array $names): string
    {
        if (! ctype_digit($value)) {
            return $value;
        }

        $id = (int) $value;

        return $names[$id] ?? '#'.$id;
    }

    /**
     * @param  array<int, string>  $names
     */
    private function resolveEntityName(string $value, array $names): string
    {
        if (! ctype_digit($value)) {
            return $value;
        }

        $id = (int) $value;

        return $names[$id] ?? '#'.$id;
    }

    private function formatDeadline(string $value): string
    {
        $date = Carbon::parse($value);

        if ($date->format('H:i:s') === '00:00:00') {
            return $date->format('d.m.Y');
        }

        return $date->format('d.m.Y H:i');
    }

    private function truncateDescription(string $value): string
    {
        $plain = app(HtmlContentService::class)->toPlainText($value);

        if (mb_strlen($plain) <= 80) {
            return $plain;
        }

        return mb_substr($plain, 0, 80).'…';
    }
}
