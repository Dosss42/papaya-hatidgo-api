<?php

namespace App\Http\Requests\Admin;

use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;

/** Approve a document; optionally correct the expiry date the admin reads on the photo. */
class ApproveDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:admin is checked by the route middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['expires_at' => ['nullable', 'date_format:Y-m-d', 'after:'.BusinessDate::todayString()]];
    }
}
