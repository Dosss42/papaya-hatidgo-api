<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One configuration value (key/value, typed by `type`). */
#[Fillable(['key', 'value', 'type', 'description'])]
class SystemSetting extends Model
{
    /** The value converted to its declared type (the column itself stores text). */
    public function typedValue(): int|float|bool|string
    {
        return match ($this->type) {
            'int' => (int) $this->value,
            'decimal' => (float) $this->value,
            'bool' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            default => $this->value,
        };
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
