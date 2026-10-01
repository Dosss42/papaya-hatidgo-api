<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReasonRequest;
use App\Models\Driver;
use App\Services\DriverAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/v1/admin/drivers/{driver}/suspend · reactivate (role:admin). */
class DriverAccountController extends Controller
{
    public function __construct(private readonly DriverAccountService $accounts) {}

    public function suspend(ReasonRequest $request, int $driver): JsonResponse
    {
        $updated = $this->accounts->suspend($request->user(), Driver::findOrFail($driver), $request->validated('reason'));

        return $this->answer($updated);
    }

    public function reactivate(Request $request, int $driver): JsonResponse
    {
        return $this->answer($this->accounts->reactivate($request->user(), Driver::findOrFail($driver)));
    }

    private function answer(Driver $driver): JsonResponse
    {
        return response()->json(['data' => [
            'driver_id' => $driver->id,
            'account_status' => $driver->user->account_status->value,
            'compliance_status' => $driver->compliance_status->value,
            'is_online' => $driver->is_online,
        ]]);
    }
}
