<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicles\StoreVehicleRequest;
use App\Http\Requests\Vehicles\UpdateVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Driver;
use App\Services\VehicleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * /api/v1/vehicles: a driver's own tricycles (role:driver on the routes).
 * A driver only ever sees or edits THEIR vehicles: another driver's id answers 404, not 403,
 * so the API doesn't even confirm that vehicle exists.
 */
class VehicleController extends Controller
{
    public function __construct(private readonly VehicleService $vehicles) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $driver = $this->driver($request);

        return VehicleResource::collection($driver->vehicles()->with('driver')->orderBy('id')->get());
    }

    public function store(StoreVehicleRequest $request): JsonResponse
    {
        $vehicle = $this->vehicles->create($this->driver($request), $request->validated());

        return (new VehicleResource($vehicle->load('driver')))->response()->setStatusCode(201);
    }

    public function update(UpdateVehicleRequest $request, int $vehicle): VehicleResource
    {
        $owned = $this->driver($request)->vehicles()->findOrFail($vehicle); // 404 if not theirs

        return new VehicleResource($this->vehicles->update($owned, $request->validated())->load('driver'));
    }

    private function driver(Request $request): Driver
    {
        return $request->user()->driver()->firstOrFail();
    }
}
