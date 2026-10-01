<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveDocumentRequest;
use App\Http\Requests\Admin\ReasonRequest;
use App\Http\Resources\AdminDriverDocumentResource;
use App\Models\DriverDocument;
use App\Services\AuditService;
use App\Services\DocumentReviewService;
use App\Services\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** /api/v1/admin/driver-documents/*: look at a document and decide (role:admin). */
class DriverDocumentController extends Controller
{
    public function __construct(
        private readonly DocumentReviewService $reviews,
        private readonly AuditService $audit,
    ) {}

    public function show(int $document): AdminDriverDocumentResource
    {
        return new AdminDriverDocumentResource($this->find($document));
    }

    /**
     * GET …/{document}/files/{file}: THE ONLY WAY to a document file. Streams it from the private
     * disk to an admin, never cached, never sniffed into another type, and logs who looked
     * (these are people's IDs).
     */
    public function file(Request $request, int $document, int $file): StreamedResponse
    {
        $doc = DriverDocument::findOrFail($document);
        $stored = $doc->files()->findOrFail($file); // the file must belong to THIS document
        abort_unless(Storage::disk(DocumentService::DISK)->exists($stored->file_path), 404);

        $this->audit->record($request->user(), 'driver_document.file_viewed', $doc, null, ['file_id' => $stored->id]);

        return Storage::disk(DocumentService::DISK)->response($stored->file_path, $stored->original_filename, [
            'Content-Type' => $stored->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function approve(ApproveDocumentRequest $request, int $document): AdminDriverDocumentResource
    {
        return new AdminDriverDocumentResource(
            $this->reviews->approve($request->user(), $this->find($document), $request->validated('expires_at')),
        );
    }

    public function reject(ReasonRequest $request, int $document): AdminDriverDocumentResource
    {
        return new AdminDriverDocumentResource(
            $this->reviews->reject($request->user(), $this->find($document), $request->validated('reason')),
        );
    }

    public function requestResubmission(ReasonRequest $request, int $document): AdminDriverDocumentResource
    {
        return new AdminDriverDocumentResource(
            $this->reviews->requestResubmission($request->user(), $this->find($document), $request->validated('reason')),
        );
    }

    private function find(int $id): DriverDocument
    {
        return DriverDocument::with(['requirement', 'files', 'reviews.reviewer', 'driver.user', 'vehicle'])->findOrFail($id);
    }
}
