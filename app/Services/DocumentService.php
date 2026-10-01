<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\DocumentStatus;
use App\Enums\RequirementScope;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\DriverDocumentFile;
use App\Models\DriverRequirement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A driver submits one requirement (phase-0 § D.1 + § D.5, brief § 4.2 + § 5).
 *
 * - Files go to the PRIVATE disk ('local' = storage/app/private) under random names; the database
 *   keeps only the path and metadata. No URL is ever returned to anyone.
 * - Which submission "counts" (is_current) follows the renewal rule: an approved, unexpired
 *   document stays current until its replacement is approved, so the driver stays eligible.
 * - A submission the admin hasn't reviewed yet can be replaced (e.g. a blurry photo): it is
 *   deleted with its files. Anything already reviewed is history and is never deleted.
 */
class DocumentService
{
    public const DISK = 'local';

    public function __construct(private readonly DriverComplianceService $compliance) {}

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<string|null>  $sides  same order as $files
     * @param  array{document_number?: ?string, expires_at?: ?string, issued_at?: ?string}  $fields
     *
     * @throws ApiException ACCOUNT_SUSPENDED · TRICYCLE_REQUIRED · FILES_INVALID
     */
    public function submit(Driver $driver, DriverRequirement $requirement, array $files, array $sides, array $fields): DriverDocument
    {
        $this->assertActive($driver);
        $vehicleId = $this->vehicleFor($driver, $requirement);
        $sides = $this->checkFiles($requirement, $files, $sides);

        // 1) Store the files first (a disk write can't be rolled back by a DB transaction).
        $stored = [];
        foreach ($files as $i => $file) {
            $stored[] = [
                'file_path' => $file->store("driver-documents/{$driver->id}", self::DISK), // random name
                'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime_type' => $file->getMimeType(), // detected from the content, not the name
                'file_size' => $file->getSize(),
                'side' => $sides[$i],
                'sort_order' => $i,
            ];
        }

        // 2) Save the submission; if anything fails, remove the files we just wrote.
        $replacedPaths = [];
        try {
            $document = DB::transaction(function () use ($driver, $requirement, $vehicleId, $fields, $stored, &$replacedPaths) {
                $mine = DriverDocument::query()
                    ->where('driver_id', $driver->id)
                    ->where('driver_requirement_id', $requirement->id)
                    ->where(fn ($q) => $vehicleId === null ? $q->whereNull('vehicle_id') : $q->where('vehicle_id', $vehicleId))
                    ->withCount('reviews')
                    ->with('files')
                    ->lockForUpdate() // two quick taps on "Isumite" can't create two current documents
                    ->get();

                // Replace submissions nobody has reviewed yet (their files are deleted after commit).
                foreach ($mine->where('status', DocumentStatus::Pending)->where('reviews_count', 0) as $unreviewed) {
                    $replacedPaths = [...$replacedPaths, ...$unreviewed->files->pluck('file_path')->all()];
                    $unreviewed->delete(); // its file rows go with it (cascade)
                }

                // The renewal rule.
                $current = DriverDocument::query()
                    ->whereKey($mine->pluck('id'))
                    ->where('is_current', true)
                    ->first();
                $keepCurrent = $current !== null
                    && $this->compliance->effectiveStatus($current) === DocumentStatus::Approved;

                if ($current && ! $keepCurrent) {
                    $current->is_current = false; // first, so the "one current" UNIQUE index stays happy
                    $current->save();
                }

                $document = new DriverDocument([
                    'document_number' => $fields['document_number'] ?? null,
                    'expires_at' => $fields['expires_at'] ?? null,
                    'issued_at' => $fields['issued_at'] ?? null,
                ]);
                $document->driver_id = $driver->id;
                $document->driver_requirement_id = $requirement->id;
                $document->vehicle_id = $vehicleId;
                $document->status = DocumentStatus::Pending;
                $document->is_current = ! $keepCurrent;
                $document->submitted_at = now();
                $document->save();

                foreach ($stored as $file) {
                    DriverDocumentFile::forceCreate(['driver_document_id' => $document->id] + $file);
                }

                return $document;
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete(array_column($stored, 'file_path'));
            throw $e;
        }

        Storage::disk(self::DISK)->delete($replacedPaths);
        $this->compliance->recalculate($driver);

        return $document->load(['files', 'requirement', 'reviews']);
    }

    /** Tricycle papers attach to the driver's active tricycle; there must be one. */
    private function vehicleFor(Driver $driver, DriverRequirement $requirement): ?int
    {
        if ($requirement->applies_to !== RequirementScope::Vehicle) {
            return null;
        }
        if ($driver->active_vehicle_id === null) {
            throw new ApiException(__('api.tricycle_required'), 'TRICYCLE_REQUIRED', 422);
        }

        return (int) $driver->active_vehicle_id;
    }

    /**
     * The right number of files, and for a two-sided paper (the license) one front AND one back.
     *
     * @return list<string> the side of each file
     */
    private function checkFiles(DriverRequirement $requirement, array $files, array $sides): array
    {
        if (count($files) !== $requirement->max_files) {
            throw new ApiException(
                __('api.files_invalid'), 'FILES_INVALID', 422,
                ['files' => [trans_choice('api.files_count', $requirement->max_files, ['count' => $requirement->max_files])]],
            );
        }

        if ($requirement->max_files === 1) {
            return ['page'];
        }

        $sides = array_values(array_map(fn ($s) => $s ?: null, array_slice($sides, 0, count($files))));
        $given = $sides;
        sort($given);
        if ($given !== ['back', 'front']) {
            throw new ApiException(__('api.files_invalid'), 'FILES_INVALID', 422, ['files' => [__('api.files_front_back')]]);
        }

        return $sides;
    }

    private function assertActive(Driver $driver): void
    {
        if ($driver->user->account_status === AccountStatus::Suspended) {
            throw new ApiException(__('api.account_suspended'), 'ACCOUNT_SUSPENDED', 403);
        }
    }
}
