<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DocumentStatus;
use App\Enums\RideStatus;
use App\Enums\SubscriptionState;
use App\Enums\TransactionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\RideRequest;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Services\DriverMatchingService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/admin/dashboard (Phase 14): the numbers on the admin's first screen. "Today" and
 * "this month" follow the Philippine calendar (Asia/Manila), like the drivers' earnings.
 */
class DashboardController extends Controller
{
    public function __invoke(DriverMatchingService $matching): JsonResponse
    {
        $tz = config('app.business_timezone');
        $today = now($tz)->startOfDay()->utc();
        $month = now($tz)->startOfMonth()->utc();
        $week = now($tz)->subDays(6)->startOfDay()->utc();

        $activeSubs = fn (UserRole $role) => Subscription::query()
            ->where('state', SubscriptionState::Paid)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->whereHas('user', fn ($q) => $q->where('role', $role))
            ->count();

        return response()->json(['data' => [
            // Drivers with at least one document waiting for the admin (the review queue).
            'to_review' => Driver::query()->whereHas('documents', fn ($q) => $q->where('status', DocumentStatus::Pending))->count(),
            // Online = what matching uses: switched on AND located in the last few minutes.
            'drivers_online' => Driver::query()->where('is_online', true)->where('location_updated_at', '>=', $matching->freshSince())->count(),
            'rides_active' => RideRequest::query()->whereIn('status', RideRequest::ACTIVE_STATUSES)->count(),
            'rides_today' => [
                'requested' => RideRequest::query()->where('requested_at', '>=', $today)->count(),
                'completed' => RideRequest::query()->where('status', RideStatus::Completed)->where('completed_at', '>=', $today)->count(),
                'cancelled' => RideRequest::query()->where('status', RideStatus::Cancelled)->where('cancelled_at', '>=', $today)->count(),
            ],
            // Cash the drivers collected today (final fares of completed rides).
            'fares_today' => number_format((float) RideRequest::query()
                ->where('status', RideStatus::Completed)->where('completed_at', '>=', $today)->sum('final_total'), 2, '.', ''),
            // Subscription money received this month (paid transactions).
            'revenue_month' => number_format((float) SubscriptionTransaction::query()
                ->where('status', TransactionStatus::Paid)->where('paid_at', '>=', $month)->sum('amount'), 2, '.', ''),
            'subscriptions_active' => [
                'drivers' => $activeSubs(UserRole::Driver),
                'passengers' => $activeSubs(UserRole::Passenger),
            ],
            'users' => [
                'passengers' => User::query()->where('role', UserRole::Passenger)->count(),
                'drivers' => User::query()->where('role', UserRole::Driver)->count(),
                'new_this_week' => User::query()->where('role', '!=', UserRole::Admin)->where('created_at', '>=', $week)->count(),
            ],
        ]]);
    }
}
