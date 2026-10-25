<?php

namespace App\Models;

use App\Enums\PlanAudience;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Lang;

/** A plan the admin offers (1 / 6 / 12 months per audience to start). */
#[Fillable([
    'code', 'name', 'user_type', 'duration_months', 'price', 'currency',
    'benefits', 'is_active', 'sort_order',
])]
class SubscriptionPlan extends Model
{
    protected function casts(): array
    {
        return [
            'user_type' => PlanAudience::class,
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The name in the request's language (lang/{en,fil}/plans.php by code) while the admin hasn't
     * renamed the plan; once renamed on the admin website (Phase 14), the stored name wins, so an
     * edit is never hidden behind a translation.
     */
    public function displayName(): string
    {
        $key = "plans.{$this->code}";

        $renamed = ! in_array($this->name, [__($key, [], 'en'), __($key, [], 'fil')], true);

        return Lang::has($key) && ! $renamed ? __($key) : $this->name;
    }
}
