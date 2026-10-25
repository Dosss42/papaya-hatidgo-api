<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CancelledBy;
use App\Enums\RideStatus;
use App\Enums\TransactionStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Rating;
use App\Models\RideRequest;
use App\Models\SubscriptionTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Admin reports (Phase 14), each for ?from=&to= (Philippine calendar dates, default the last
 * 30 days, at most 92). Days are grouped in Asia/Manila, like the drivers' earnings.
 *   GET /admin/reports/rides     per day: requested · completed · cancelled (by whom) · fares
 *   GET /admin/reports/revenue   per day: subscription money received; by plan and audience
 *   GET /admin/reports/drivers   the top 20 drivers: rides, cash collected, cancels, rating
 * Every day of the range is listed (zero days included), so charts have no holes.
 */
class ReportController extends Controller
{
    public function rides(Request $request): JsonResponse
    {
        [$from, $to, $days] = $this->range($request);
        $rides = RideRequest::query()
            ->whereBetween('requested_at', [$from->utc(), $to->utc()])
            ->get(['id', 'status', 'ride_type', 'requested_at', 'cancelled_by', 'final_total']);

        $byDay = $days->map(fn (string $day) => ['date' => $day, 'requested' => 0, 'completed' => 0, 'cancelled' => 0, 'no_driver' => 0, 'fares' => 0.0])->keyBy('date');
        foreach ($rides as $ride) {
            $day = $ride->requested_at->setTimezone($this->tz())->format('Y-m-d');
            $row = $byDay[$day];
            $row['requested']++;
            if ($ride->status === RideStatus::Completed) {
                $row['completed']++;
                $row['fares'] += (float) $ride->final_total;
            } elseif ($ride->status === RideStatus::Cancelled) {
                $ride->cancelled_by === CancelledBy::System ? $row['no_driver']++ : $row['cancelled']++;
            }
            $byDay[$day] = $row;
        }

        $completed = $rides->where('status', RideStatus::Completed);

        return response()->json(['data' => [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'days' => $byDay->values()->map(fn ($r) => ['fares' => $this->money($r['fares'])] + $r),
            'totals' => [
                'requested' => $rides->count(),
                'completed' => $completed->count(),
                'cancelled' => $rides->where('status', RideStatus::Cancelled)->where('cancelled_by', '!=', CancelledBy::System)->count(),
                'no_driver' => $rides->where('status', RideStatus::Cancelled)->where('cancelled_by', CancelledBy::System)->count(),
                'fares' => $this->money($completed->sum(fn ($r) => (float) $r->final_total)),
                'one_way' => $rides->where('ride_type.value', 'one_way')->count(),
                'two_way' => $rides->where('ride_type.value', 'two_way')->count(),
            ],
        ]]);
    }

    public function revenue(Request $request): JsonResponse
    {
        [$from, $to, $days] = $this->range($request);
        $paid = SubscriptionTransaction::query()
            ->where('status', TransactionStatus::Paid)
            ->whereBetween('paid_at', [$from->utc(), $to->utc()])
            ->with('subscription.plan')
            ->get();

        $byDay = $days->mapWithKeys(fn (string $day) => [$day => ['date' => $day, 'payments' => 0, 'amount' => 0.0]]);
        foreach ($paid as $t) {
            $day = $t->paid_at->setTimezone($this->tz())->format('Y-m-d');
            $row = $byDay[$day];
            $row['payments']++;
            $row['amount'] += (float) $t->amount;
            $byDay[$day] = $row;
        }

        return response()->json(['data' => [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'days' => $byDay->values()->map(fn ($r) => ['amount' => $this->money($r['amount'])] + $r),
            'by_plan' => $paid->groupBy(fn ($t) => $t->subscription->plan->id)->map(fn (Collection $group) => [
                'plan' => $group->first()->subscription->plan->name,
                'audience' => $group->first()->subscription->plan->user_type->value,
                'payments' => $group->count(),
                'amount' => $this->money($group->sum(fn ($t) => (float) $t->amount)),
            ])->sortByDesc('payments')->values(),
            'totals' => [
                'payments' => $paid->count(),
                'amount' => $this->money($paid->sum(fn ($t) => (float) $t->amount)),
            ],
        ]]);
    }

    public function drivers(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $window = [$from->utc(), $to->utc()];
        $completed = RideRequest::query()
            ->where('status', RideStatus::Completed)->whereBetween('completed_at', $window)
            ->get(['id', 'driver_id', 'final_total']);
        $cancels = RideRequest::query()
            ->where('status', RideStatus::Cancelled)->where('cancelled_by', CancelledBy::Driver)->whereBetween('cancelled_at', $window)
            ->get(['id', 'driver_id'])->countBy('driver_id');
        $ratings = Rating::query()
            ->whereHas('rideRequest', fn ($q) => $q->whereIn('id', $completed->pluck('id')))
            ->with('rideRequest:id,driver_id')
            ->get(['id', 'ride_request_id', 'score'])
            ->groupBy(fn ($r) => $r->rideRequest->driver_id);

        $top = $completed->groupBy('driver_id')->map(fn (Collection $rides, $driverId) => [
            'driver_id' => (int) $driverId,
            'rides' => $rides->count(),
            'earnings' => $rides->sum(fn ($r) => (float) $r->final_total),
            'cancelled' => $cancels[$driverId] ?? 0,
            'rating' => isset($ratings[$driverId]) ? round($ratings[$driverId]->avg('score'), 1) : null,
            'ratings' => isset($ratings[$driverId]) ? $ratings[$driverId]->count() : 0,
        ])->sortByDesc('rides')->take(20);
        $names = Driver::query()->whereIn('id', $top->keys())->with('user')->get()->keyBy('id');

        return response()->json(['data' => [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'drivers' => $top->map(fn ($row) => [
                'full_name' => $names[$row['driver_id']]?->user->full_name,
                'earnings' => $this->money($row['earnings']),
            ] + $row)->values(),
        ]]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: Collection<int, string>} the range in Manila time + each day in it */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], $this->tz())->endOfDay() : CarbonImmutable::now($this->tz())->endOfDay();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], $this->tz())->startOfDay() : $to->subDays(29)->startOfDay();
        if ($from->diffInDays($to) > 92) {
            throw new ApiException(__('api.report_range_too_long'), 'RANGE_TOO_LONG', 422);
        }
        $days = collect();
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $days->push($d->format('Y-m-d'));
        }

        return [$from, $to, $days];
    }

    private function tz(): string
    {
        return config('app.business_timezone');
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
