<?php

namespace App\Enums;

enum SystemType: string
{
    case User = 'user';
    case DeptHead = 'dept_head';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::User => __('User'),
            self::DeptHead => __('Department head'),
            self::Admin => __('Administrator'),
        };
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }
}
