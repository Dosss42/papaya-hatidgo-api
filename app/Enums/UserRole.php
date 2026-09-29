<?php

namespace App\Enums;

/** Mirrors users.role. The database rejects other values; PHP code gets type safety. */
enum UserRole: string
{
    case Passenger = 'passenger';
    case Driver = 'driver';
    case Admin = 'admin';
}
