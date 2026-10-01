<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Resources\DriverDocumentResource;
use App\Models\DriverRequirement;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A driver's own document submissions (role:driver on the routes).
 * Another driver's document answers 404, never 403: the API doesn't confirm it exists.
 */
class DriverDocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    /** POST /drivers/me/documents (multipart). */
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();
        $requirement = DriverRequirement::findOrFail($request->integer('requirement_id'));

        $document = $this->documents->submit(
            $driver,
            $requirement,
            array_values($request->file('files', [])),
            array_values($request->input('sides', [])),
            $request->only(['document_number', 'expires_at', 'issued_at']),
        );

        return (new DriverDocumentResource($document))->response()->setStatusCode(201);
    }

    /** GET /drivers/me/documents/{id}: status + review history. */
    public function show(Request $request, int $document): DriverDocumentResource
    {
        $owned = $request->user()->driver()->firstOrFail()
            ->documents()
            ->with(['requirement', 'files', 'reviews'])
            ->findOrFail($document); // 404 if not theirs

        return new DriverDocumentResource($owned);
    }
}
