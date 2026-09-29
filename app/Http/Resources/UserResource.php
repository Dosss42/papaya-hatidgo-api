<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The ONLY shape in which a user leaves the API. Listing fields explicitly means a new
 * column added to `users` later is never exposed by accident (password, tokens, etc.).
 *
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role->value,
            'account_status' => $this->account_status->value,
        ];
    }
}
