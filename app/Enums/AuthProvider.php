<?php

namespace App\Enums;

enum AuthProvider: string
{
    case Password = 'password';
    case Google = 'google';
}
