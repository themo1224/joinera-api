<?php

namespace App\Enums;

enum Role: string
{
    case User = 'user';
    case Staff = 'staff';
    case Admin = 'admin';
}
