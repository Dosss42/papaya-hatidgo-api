<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Admin: suspend or reactivate a driver's account (phase-0 § D.4).
 * Suspension lives on users.account_status (one place for every role). A suspended driver can't
 * log in again, is set offline, and can't change anything; they can still see why if logged in.
 */
class DriverAccountService
{
    public function __construct(
        private readonly DriverComplianceService $compliance,
        private readonly AuditService $audit,
    ) {}

    public function suspend(User $admin, Driver $driver, string $reason): Driver
    {
        DB::transaction(function () use ($admin, $driver, $reason) {
            $user = $driver->user;
            $old = $user->account_status->value;

            $user->account_status = AccountStatus::Suspended;
            $user->save();
            $driver->is_online = false;
            $driver->save();

            $this->audit->record($admin, 'driver.suspended', $driver,
                ['account_status' => $old], ['account_status' => 'suspended', 'reason' => $reason]);
        });

        return $driver->refresh()->load('user');
    }

    public function reactivate(User $admin, Driver $driver): Driver
    {
        DB::transaction(function () use ($admin, $driver) {
            $user = $driver->user;
            $old = $user->account_status->value;

            $user->account_status = AccountStatus::Active;
            $user->save();

            $this->audit->record($admin, 'driver.reactivated', $driver,
                ['account_status' => $old], ['account_status' => 'active']);
        });

        $this->compliance->recalculate($driver);

        return $driver->refresh()->load('user');
    }
}
