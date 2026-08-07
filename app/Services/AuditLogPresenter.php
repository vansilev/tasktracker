<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Category;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;

class AuditLogPresenter
{
    /**
     * @param  array<int, int>  $taskNumbers
     */
    public function entityLabel(?string $entityType, ?int $entityId, array $taskNumbers = []): string
    {
        if (! $entityType || ! $entityId) {
            return __('audit.empty');
        }

        $short = class_basename($entityType);
        $typeLabel = $this->lookupLabel('audit.entity', $short) ?? $short;

        if ($entityType === Task::class) {
            $number = $taskNumbers[$entityId] ?? null;

            return $number !== null
                ? $typeLabel.' #'.$number
                : $typeLabel.' #'.$entityId;
        }

        return $typeLabel.' #'.$entityId;
    }

    public function actionLabel(string $action): string
    {
        $labels = __('audit.action');

        if (is_array($labels) && array_key_exists($action, $labels)) {
            return $labels[$action];
        }

        return $action;
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function summarize(?array $oldValues, ?array $newValues, string $action): string
    {
        return match ($action) {
            'auth.login' => $this->summarizeLogin($newValues ?? []),
            'task.created' => $this->summarizeTaskCreated($newValues ?? []),
            'tasks.imported' => $this->summarizeImport($newValues ?? []),
            default => $this->summarizeFieldChanges($oldValues ?? [], $newValues ?? []),
        };
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function detailJson(?array $oldValues, ?array $newValues): string
    {
        if ($oldValues === null && $newValues === null) {
            return '';
        }

        return json_encode([
            'old' => $oldValues,
            'new' => $newValues,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function summarizeLogin(array $newValues): string
    {
        $email = (string) ($newValues['email'] ?? '');
        $method = (string) ($newValues['method'] ?? 'password');
        $methodLabel = $this->lookupLabel('audit.login_method', $method) ?? $method;

        return __('audit.login_summary', [
            'email' => $email !== '' ? $email : '—',
            'method' => $methodLabel,
        ]);
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function summarizeTaskCreated(array $newValues): string
    {
        return __('audit.task_created_summary', [
            'number' => $newValues['number'] ?? '—',
            'title' => $newValues['title'] ?? '—',
        ]);
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function summarizeImport(array $newValues): string
    {
        return __('audit.import_summary', [
            'filename' => $newValues['filename'] ?? '—',
            'imported' => $newValues['imported'] ?? 0,
            'skipped' => $newValues['skipped'] ?? 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function summarizeFieldChanges(array $oldValues, array $newValues): string
    {
        if ($oldValues === [] && $newValues === []) {
            return __('audit.empty');
        }

        $context = $this->buildContext($oldValues, $newValues);
        $keys = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));
        $lines = [];

        foreach ($keys as $key) {
            $old = array_key_exists($key, $oldValues) ? $oldValues[$key] : null;
            $new = array_key_exists($key, $newValues) ? $newValues[$key] : null;

            if ($old === $new) {
                continue;
            }

            $fieldLabel = $this->fieldLabel((string) $key);

            if ($oldValues === [] && $new !== null) {
                $lines[] = __('audit.value_line', [
                    'field' => $fieldLabel,
                    'value' => $this->formatValue((string) $key, $new, $context),
                ]);
            } else {
                $lines[] = __('audit.change_line', [
                    'field' => $fieldLabel,
                    'old' => $this->formatValue((string) $key, $old, $context),
                    'new' => $this->formatValue((string) $key, $new, $context),
                ]);
            }
        }

        return $lines === [] ? __('audit.empty') : implode('; ', $lines);
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @return array{users: array<int, string>, departments: array<int, string>, categories: array<int, string>}
     */
    private function buildContext(array $oldValues, array $newValues): array
    {
        $userIds = [];
        $departmentIds = [];
        $categoryIds = [];

        foreach (array_merge(array_keys($oldValues), array_keys($newValues)) as $key) {
            match ($key) {
                'assignee_id' => $this->collectIds($userIds, $oldValues[$key] ?? null, $newValues[$key] ?? null),
                'department_id', 'visible_department_ids' => $this->collectIds(
                    $departmentIds,
                    $oldValues[$key] ?? null,
                    $newValues[$key] ?? null,
                ),
                'category_id' => $this->collectIds($categoryIds, $oldValues[$key] ?? null, $newValues[$key] ?? null),
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
    private function collectIds(array &$ids, mixed $old, mixed $new): void
    {
        foreach ([$old, $new] as $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (is_numeric($item)) {
                        $ids[] = (int) $item;
                    }
                }

                continue;
            }

            if ($value !== null && $value !== '' && is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }
    }

    private function fieldLabel(string $field): string
    {
        $taskKey = 'task.history_field.'.$field;
        $taskLabel = __($taskKey);

        if ($taskLabel !== $taskKey) {
            return $taskLabel;
        }

        $auditLabel = $this->lookupLabel('audit.field', $field);

        return $auditLabel ?? $field;
    }

    private function lookupLabel(string $group, string $key): ?string
    {
        $labels = __($group);

        if (is_array($labels) && array_key_exists($key, $labels)) {
            return $labels[$key];
        }

        return null;
    }

    private function formatLoginMethod(string $value): string
    {
        return $this->lookupLabel('audit.login_method', $value) ?? $value;
    }

    /**
     * @param  array{users: array<int, string>, departments: array<int, string>, categories: array<int, string>}  $context
     */
    private function formatValue(string $field, mixed $value, array $context): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? __('audit.boolean_true') : __('audit.boolean_false');
        }

        if ($field === 'permissions' && is_array($value)) {
            return __('audit.permissions_count', ['count' => count($value)]);
        }

        if ($field === 'visible_department_ids' && is_array($value)) {
            if ($value === []) {
                return '—';
            }

            $names = array_map(
                fn ($id) => $context['departments'][(int) $id] ?? '#'.$id,
                $value,
            );

            if (count($names) > 3) {
                return __('audit.departments_count', ['count' => count($names)]);
            }

            return implode(', ', $names);
        }

        if ($field === 'allowed_email_domains' && is_array($value)) {
            return implode(', ', $value);
        }

        $stringValue = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);

        return match ($field) {
            'status' => TaskStatus::tryFrom($stringValue)?->label() ?? $stringValue,
            'assignee_id' => $this->resolveEntityName($stringValue, $context['users']),
            'department_id' => $this->resolveEntityName($stringValue, $context['departments']),
            'category_id' => $this->resolveEntityName($stringValue, $context['categories']),
            'deadline' => $this->formatDeadline($stringValue),
            'description' => $this->truncate(
                app(HtmlContentService::class)->toPlainText($stringValue),
                60,
            ),
            'title' => $this->truncate($stringValue, 60),
            'method' => $this->formatLoginMethod($stringValue),
            'google_sso_enabled', 'password_login_enabled', 'is_active' => $value
                ? __('audit.boolean_true')
                : __('audit.boolean_false'),
            default => $this->truncate($stringValue, 80),
        };
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
        try {
            $date = Carbon::parse($value);

            if ($date->format('H:i:s') === '00:00:00') {
                return $date->format('d.m.Y');
            }

            return $date->format('d.m.Y H:i');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max).'…';
    }
}
