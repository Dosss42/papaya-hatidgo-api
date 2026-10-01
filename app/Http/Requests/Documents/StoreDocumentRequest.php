<?php

namespace App\Http\Requests\Documents;

use App\Models\DriverRequirement;
use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/drivers/me/documents (multipart/form-data): one submission of one requirement.
 *   requirement_id · files[] (1–2) · sides[] (front|back|page, same order as files[])
 *   document_number? · expires_at (YYYY-MM-DD, required when the requirement expires) · issued_at?
 * Messages: lang/{fil,en}/validation.php ('custom' + 'attributes').
 */
class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:driver is checked by the route middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $requirement = DriverRequirement::find($this->integer('requirement_id'));

        return [
            'requirement_id' => ['required', 'integer', Rule::exists('driver_requirements', 'id')->where('is_active', true)],
            'files' => ['required', 'array', 'min:1', 'max:2'],
            // `mimes` checks the file's CONTENT (PHP's finfo), not its name: "virus.exe" renamed
            // "license.jpg" is refused. 5120 KB = 5 MB per file (phase-0 § D.5).
            'files.*' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'sides' => ['nullable', 'array'],
            'sides.*' => ['nullable', Rule::in(['front', 'back', 'page'])],
            'document_number' => ['nullable', 'string', 'max:50'],
            'expires_at' => [Rule::requiredIf((bool) $requirement?->requires_expiry), 'nullable', 'date_format:Y-m-d', 'after:'.BusinessDate::todayString()],
            'issued_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::todayString()],
        ];
    }
}
