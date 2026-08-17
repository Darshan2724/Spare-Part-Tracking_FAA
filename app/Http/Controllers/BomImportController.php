<?php

namespace App\Http\Controllers;

use App\Services\BomImportService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\BomImportBatch;

class BomImportController extends Controller
{
    public function __construct(protected BomImportService $bomImportService)
    {
    }

    public function preview(Request $request)
    {
        $this->authorizeBomImport($request);

        $request->validate([
            'file' => ['nullable', 'file', 'mimes:xls,xlsx', 'max:20480'],
            'path' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        $path = $this->sanitizePath($request->input('path'));

        if ($file instanceof UploadedFile) {
            $temporaryPath = $file->getRealPath();
            if ($temporaryPath === false || !is_file($temporaryPath)) {
                return response()->json(['message' => 'Invalid upload file.'], 422);
            }

            return response()->json($this->bomImportService->previewFromPath($temporaryPath, $file->getClientOriginalName()));
        }

        if (!empty($path)) {
            $resolvedPath = $this->resolveBomPath($path);
            if ($resolvedPath === null) {
                return response()->json(['message' => 'The supplied BOM path is invalid.'], 422);
            }

            return response()->json($this->bomImportService->previewFromPath($resolvedPath, basename($resolvedPath)));
        }

        return response()->json([
            'message' => 'No BOM file provided.',
        ], 422);
    }

    public function import(Request $request)
    {
        $this->authorizeBomImport($request);

        $request->validate([
            'file' => ['nullable', 'file', 'mimes:xls,xlsx', 'max:20480'],
            'path' => ['nullable', 'string', 'max:255'],
            'filename' => ['nullable', 'string', 'max:255'],
            'project_code' => ['nullable', 'string', 'max:100'],
            'project_name' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        $path = $this->sanitizePath($request->input('path'));

        if ($file instanceof UploadedFile) {
            $temporaryPath = $file->getRealPath();
            if ($temporaryPath === false || !is_file($temporaryPath)) {
                return response()->json(['message' => 'Invalid upload file.'], 422);
            }

            $importData = $request->all();
            $clientName = $file->getClientOriginalName();
            $importData['filename'] = basename(str_replace('\\', '/', $request->input('filename') ?: $clientName));

            return response()->json($this->bomImportService->importFromPath($temporaryPath, $importData, $request->user()->id));
        }

        if (!empty($path)) {
            $resolvedPath = $this->resolveBomPath($path);
            if ($resolvedPath === null) {
                return response()->json(['message' => 'The supplied BOM path is invalid.'], 422);
            }

            $importData = $request->all();
            $rawName = $request->input('filename') ?: basename($resolvedPath);
            $importData['filename'] = basename(str_replace('\\', '/', $rawName));

            return response()->json($this->bomImportService->importFromPath($resolvedPath, $importData, $request->user()->id));
        }

        return response()->json([
            'message' => 'No BOM file provided.',
        ], 422);
    }

    protected function authorizeBomImport(Request $request): void
    {
        if (!$request->user()) {
            abort(401);
        }

        if (!$request->user()->hasAnyRole(['ADMIN', 'MANAGER'])) {
            abort(403);
        }
    }

    protected function sanitizePath(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = str_replace('\\', '/', trim($path));
        $path = str_replace(['..', "\0"], '', $path);

        return $path;
    }

    protected function resolveBomPath(string $path): ?string
    {
        $basePath = rtrim(base_path(), DIRECTORY_SEPARATOR);
        $candidate = $basePath . DIRECTORY_SEPARATOR . $path;

        if (!is_file($candidate)) {
            return null;
        }

        $realBase = realpath($basePath);
        $realCandidate = realpath($candidate);

        if ($realBase === false || $realCandidate === false) {
            return null;
        }

        if (Str::startsWith($realCandidate, $realBase . DIRECTORY_SEPARATOR) === false) {
            return null;
        }

        return $realCandidate;
    }

    public function history(Request $request)
    {
        $this->authorizeBomImport($request);

        $batches = BomImportBatch::with(['project', 'importer'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'history' => $batches
        ]);
    }
}
