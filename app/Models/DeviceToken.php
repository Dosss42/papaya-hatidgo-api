<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Firebase Cloud Messaging token for one of the user's phones (Phase 13). It lives only as long
 * as the login it was registered with (personal_access_token_id, deleted with it).
 */
#[Fillable(['token', 'platform', 'locale'])]
class DeviceToken extends Model
{
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
