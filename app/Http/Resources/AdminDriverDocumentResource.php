<?php

namespace App\Http\Resources;

use App\Models\DriverDocumentFile;
use App\Models\DriverRequirementReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One document as the ADMIN sees it: everything needed to decide (who, which tricycle, the
 * typed details to compare with the photo) and where to fetch each file. The file "url" is an
 * authenticated API path (admin token required), never a public link.
 */
class AdminDriverDocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'requirement' => new RequirementResource($this->requirement),
            'driver' => [
                'id' => $this->driver->id,
                'full_name' => $this->driver->user->full_name,
                'phone' => $this->driver->user->phone,
                'account_status' => $this->driver->user->account_status->value,
                'compliance_status' => $this->driver->compliance_status->value,
            ],
            // For tricycle papers: compare the plate on the photo with the registered one.
            'vehicle' => $this->vehicle === null ? null : [
                'id' => $this->vehicle->id,
                'plate_number' => $this->vehicle->plate_number,
                'body_number' => $this->vehicle->body_number,
                'color' => $this->vehicle->color,
                'status' => $this->vehicle->status->value,
            ],
            'status' => $this->status->value,
            'is_current' => $this->is_current, // false while pending = a renewal
            'document_number' => $this->document_number,
            'issued_at' => $this->issued_at?->toDateString(),
            'expires_at' => $this->expires_at?->toDateString(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'files' => $this->files->map(fn (DriverDocumentFile $f) => [
                'id' => $f->id,
                'side' => $f->side?->value,
                'mime_type' => $f->mime_type,
                'file_size' => $f->file_size,
                'original_filename' => $f->original_filename,
                'url' => "/api/v1/admin/driver-documents/{$this->id}/files/{$f->id}",
            ])->values(),
            'reviews' => $this->reviews->sortByDesc('id')->map(fn (DriverRequirementReview $r) => [
                'action' => $r->action->value,
                'reason' => $r->reason,
                'reviewer' => $r->reviewer?->full_name, // null = the system
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
