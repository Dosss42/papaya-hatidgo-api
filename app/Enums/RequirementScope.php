<?php

namespace App\Enums;

/** Mirrors driver_requirements.applies_to: about the person or about the tricycle. */
enum RequirementScope: string
{
    case Driver = 'driver';
    case Vehicle = 'vehicle';
}
