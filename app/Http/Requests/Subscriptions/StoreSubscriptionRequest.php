<?php

namespace App\Http\Requests\Subscriptions;

use Illuminate\Foundation\Http\FormRequest;

/** POST /subscriptions {plan_id}. Whether the plan fits the user is a rule in SubscriptionService. */
class StoreSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:passenger,driver is checked by the route middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['plan_id' => ['required', 'integer', 'exists:subscription_plans,id']];
    }
}
