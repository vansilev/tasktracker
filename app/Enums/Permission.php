<?php

namespace App\Enums;

enum Permission: string
{
    case CreateTask = 'create_task';
    case CreateTaskAnyDepartment = 'create_task_any_department';
    case ViewTask = 'view_task';
    case EditOwnTask = 'edit_own_task';
    case EditAnyTask = 'edit_any_task';
    case ChangeInitiator = 'change_initiator';
    case AssignTask = 'assign_task';
    case ChangeStatus = 'change_status';
    case Comment = 'comment';
    case ReviewTask = 'review_task';
    case ManageDepartment = 'manage_department';
    case ViewBilling = 'view_billing';
    case ManageBilling = 'manage_billing';

    public function label(): string
    {
        return match ($this) {
            self::CreateTask => __('permission.create_task'),
            self::CreateTaskAnyDepartment => __('permission.create_task_any_department'),
            self::ViewTask => __('permission.view_task'),
            self::EditOwnTask => __('permission.edit_own_task'),
            self::EditAnyTask => __('permission.edit_any_task'),
            self::ChangeInitiator => __('permission.change_initiator'),
            self::AssignTask => __('permission.assign_task'),
            self::ChangeStatus => __('permission.change_status'),
            self::Comment => __('permission.comment'),
            self::ReviewTask => __('permission.review_task'),
            self::ManageDepartment => __('permission.manage_department'),
            self::ViewBilling => __('permission.view_billing'),
            self::ManageBilling => __('permission.manage_billing'),
        };
    }

    /** @return list<self> */
    public static function defaults(): array
    {
        return [
            self::CreateTask,
            self::CreateTaskAnyDepartment,
            self::ViewTask,
            self::EditOwnTask,
            self::ChangeStatus,
            self::Comment,
            self::ReviewTask,
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}
