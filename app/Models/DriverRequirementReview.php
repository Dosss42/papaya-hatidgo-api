<?php

namespace App\Models;

use App\Enums\ReviewAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One event in a document's review history (append-only; written by the review service). */
#[Fillable([])]
class DriverRequirementReview extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'action' => ReviewAction::class,
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DriverDocument::class, 'driver_document_id');
    }

    /** NULL when the system acted (automatic expiry). */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
