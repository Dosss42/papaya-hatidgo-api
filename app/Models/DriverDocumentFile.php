<?php

namespace App\Models;

use App\Enums\DocumentSide;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An uploaded file of a document. Files are never edited (no updated_at).
 * file_path is HIDDEN: the private storage path must never appear in an API response;
 * admins view files only through an authorized streaming endpoint.
 */
#[Fillable([])]
#[Hidden(['file_path'])]
class DriverDocumentFile extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'side' => DocumentSide::class,
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DriverDocument::class, 'driver_document_id');
    }
}
