<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One checkout attempt with the gateway. Written only by PaymentService / the webhook. */
#[Fillable([])]
#[Hidden(['paid_key'])]
class SubscriptionTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'status' => TransactionStatus::class,
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
