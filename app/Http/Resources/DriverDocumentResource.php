<?php

namespace App\Http\Resources;

use App\Models\DriverDocumentFile;
use App\Models\DriverRequirementReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One submission, as its OWN driver sees it: status, details, file metadata (never a path or a
 * URL) and the review history (what was decided and why; not which admin).
 */
class DriverDocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'requirement' => new RequirementResource($this->whenLoaded('requirement')),
            'vehicle_id' => $this->vehicle_id,
            'status' => $this->status->value,
            'is_current' => $this->is_current,
            'document_number' => $this->document_number,
            'issued_at' => $this->issued_at?->toDateString(),
            'expires_at' => $this->expires_at?->toDateString(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'files' => $this->whenLoaded('files', fn () => $this->files->map(fn (DriverDocumentFile $f) => [
                'id' => $f->id,
                'side' => $f->side?->value,
                'mime_type' => $f->mime_type,
                'file_size' => $f->file_size,
                'original_filename' => $f->original_filename,
            ])->values()),
            'reviews' => $this->whenLoaded('reviews', fn () => $this->reviews->sortByDesc('id')->map(fn (DriverRequirementReview $r) => [
                'action' => $r->action->value,
                'reason' => $r->reason,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values()),
        ];
    }
}
