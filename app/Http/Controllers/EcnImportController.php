<?php

namespace App\Http\Controllers;

use App\Models\EcnImportBatch;
use App\Services\EcnImportService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class EcnImportController extends Controller
{
    public function __construct(
        protected EcnImportService $ecnImportService = new EcnImportService()
    ) {}

    public function preview(Request $request)
    {
        $this->authorizeEcnImport($request);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xls,xlsx', 'max:20480'],
        ]);

        $file = $request->file('file');
        if ($file instanceof UploadedFile) {
            $temporaryPath = $file->getRealPath();
            if ($temporaryPath === false || !is_file($temporaryPath)) {
                return response()->json(['message' => 'Invalid uploaded file.'], 422);
            }

            $preview = $this->ecnImportService->previewFromPath($temporaryPath, $file->getClientOriginalName());
            return response()->json($preview);
        }

        return response()->json(['message' => 'No file uploaded.'], 422);
    }

    public function import(Request $request)
    {
        $this->authorizeEcnImport($request);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xls,xlsx', 'max:20480'],
            'filename' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        if ($file instanceof UploadedFile) {
            $temporaryPath = $file->getRealPath();
            if ($temporaryPath === false || !is_file($temporaryPath)) {
                return response()->json(['message' => 'Invalid uploaded file.'], 422);
            }

            $filename = $request->input('filename') ?: $file->getClientOriginalName();
            $result = $this->ecnImportService->importFromPath($temporaryPath, $filename, $request->user()?->id);

            return response()->json($result, $result['success'] ? 200 : 422);
        }

        return response()->json(['message' => 'No file uploaded.'], 422);
    }

    public function history(Request $request)
    {
        $this->authorizeEcnImport($request);

        $batches = EcnImportBatch::with(['project:id,project_code,name', 'importer:id,name'])
            ->orderByDesc('id')
            ->paginate((int)$request->query('per_page', 20));

        return response()->json($batches);
    }

    protected function authorizeEcnImport(Request $request): void
    {
        if (!$request->user()) {
            abort(401);
        }

        if (!$request->user()->hasAnyRole(['ADMIN', 'MANAGER'])) {
            abort(403, 'Unauthorized. Only Administrators and Managers can manage ECN imports.');
        }
    }
}
