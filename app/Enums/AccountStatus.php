<?php

namespace App\Enums;

/** Mirrors users.account_status: the single source of truth for suspension (all roles). */
enum AccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
