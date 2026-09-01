<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SupplierPhone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class SupplierImportService
{
    /**
     * Parse and preview a Supplier Excel file without writing to DB.
     */
    public function preview($file): array
    {
        $filePath = $file instanceof UploadedFile ? $file->getRealPath() : $file;

        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException('Uploaded file not found.');
        }

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rawRows = $sheet->toArray();

        if (count($rawRows) < 2) {
            throw new \InvalidArgumentException('The spreadsheet is empty or has no data rows.');
        }

        // Header mapping
        $headerRow = $rawRows[0];
        $colMap = $this->mapHeaders($headerRow);

        $parsedRows = [];
        $newCount = 0;
        $duplicateCount = 0;
        $invalidCount = 0;

        // Fetch existing codes and names for fast duplicate detection
        $existingCodes = Supplier::withTrashed()->pluck('id', 'code')->mapWithKeys(function ($id, $code) {
            return [strtoupper(trim((string)$code)) => $id];
        })->toArray();

        $existingNames = Supplier::withTrashed()->pluck('id', 'name')->mapWithKeys(function ($id, $name) {
            return [strtoupper(trim((string)$name)) => $id];
        })->toArray();

        for ($i = 1; $i < count($rawRows); $i++) {
            $row = $rawRows[$i];
            
            // Skip empty rows
            $nameVal = trim((string)($row[$colMap['name']] ?? ''));
            $glcdVal = trim((string)($row[$colMap['glcd']] ?? ''));
            if ($nameVal === '' && $glcdVal === '') {
                continue;
            }

            $srNo = $colMap['sr_no'] !== null ? trim((string)($row[$colMap['sr_no']] ?? $i)) : $i;
            $cityVal = $colMap['city'] !== null ? trim((string)($row[$colMap['city']] ?? '')) : '';
            $pincodeVal = $colMap['pincode'] !== null ? trim((string)($row[$colMap['pincode']] ?? '')) : '';
            $contactVal = $colMap['contact_person'] !== null ? trim((string)($row[$colMap['contact_person']] ?? '')) : '';
            $rawPhone = $colMap['phone'] !== null ? trim((string)($row[$colMap['phone']] ?? '')) : '';

            $parsedPhones = $this->extractPhoneNumbers($rawPhone);
            $primaryPhone = count($parsedPhones) > 0 ? $parsedPhones[0] : '';

            // Validation
            $errors = [];
            if ($nameVal === '') {
                $errors[] = 'Supplier Name is required.';
            }

            $status = 'new';
            $existingId = null;

            if (count($errors) > 0) {
                $status = 'invalid';
                $invalidCount++;
            } else {
                $codeKey = strtoupper($glcdVal);
                $nameKey = strtoupper($nameVal);

                if ($glcdVal !== '' && isset($existingCodes[$codeKey])) {
                    $status = 'duplicate';
                    $existingId = $existingCodes[$codeKey];
                    $duplicateCount++;
                } elseif (isset($existingNames[$nameKey])) {
                    $status = 'duplicate';
                    $existingId = $existingNames[$nameKey];
                    $duplicateCount++;
                } else {
                    $status = 'new';
                    $newCount++;
                }
            }

            $parsedRows[] = [
                'row_index' => $i,
                'sr_no' => $srNo,
                'name' => $nameVal,
                'code' => $glcdVal ?: Str::slug($nameVal),
                'glcd' => $glcdVal,
                'city' => $cityVal,
                'pincode' => $pincodeVal,
                'contact_person' => $contactVal,
                'raw_phone' => $rawPhone,
                'phones' => $parsedPhones,
                'primary_phone' => $primaryPhone,
                'status' => $status,
                'existing_id' => $existingId,
                'errors' => $errors,
            ];
        }

        return [
            'success' => true,
            'total_rows' => count($parsedRows),
            'new_count' => $newCount,
            'duplicate_count' => $duplicateCount,
            'invalid_count' => $invalidCount,
            'rows' => $parsedRows,
        ];
    }

    /**
     * Commit parsed rows to PostgreSQL in a single atomic transaction.
     */
    public function commit(array $rows, ?int $userId = null): array
    {
        return DB::transaction(function () use ($rows, $userId) {
            $createdCount = 0;
            $updatedCount = 0;
            $skippedCount = 0;
            $createdSuppliers = [];

            foreach ($rows as $item) {
                if (($item['status'] ?? '') === 'invalid' || empty($item['name'])) {
                    $skippedCount++;
                    continue;
                }

                $code = !empty($item['glcd']) ? trim($item['glcd']) : (!empty($item['code']) ? trim($item['code']) : Str::slug(trim($item['name'])));
                $name = trim($item['name']);
                $city = !empty($item['city']) ? trim($item['city']) : null;
                $pincode = !empty($item['pincode']) ? trim($item['pincode']) : null;
                $contact = !empty($item['contact_person']) ? trim($item['contact_person']) : null;
                $primaryPhone = !empty($item['primary_phone']) ? trim($item['primary_phone']) : null;

                // Find existing supplier by code or name
                $supplier = Supplier::withTrashed()
                    ->where(function ($q) use ($code, $name) {
                        $q->where('code', $code)->orWhere('name', $name);
                    })
                    ->first();

                if ($supplier) {
                    // Update existing
                    if ($supplier->trashed()) {
                        $supplier->restore();
                    }
                    $supplier->update([
                        'name' => $name,
                        'code' => $code,
                        'city' => $city ?: $supplier->city,
                        'pincode' => $pincode ?: $supplier->pincode,
                        'contact_person' => $contact ?: $supplier->contact_person,
                        'phone' => $primaryPhone ?: $supplier->phone,
                        'is_active' => true,
                    ]);
                    $updatedCount++;
                } else {
                    // Create new
                    $supplier = Supplier::create([
                        'name' => $name,
                        'code' => $code,
                        'city' => $city,
                        'pincode' => $pincode,
                        'state' => 'Maharashtra',
                        'country' => 'India',
                        'contact_person' => $contact,
                        'phone' => $primaryPhone,
                        'is_active' => true,
                        'is_test_data' => false,
                    ]);
                    $createdCount++;
                }

                // Sync phones
                $phonesList = is_array($item['phones'] ?? null) ? $item['phones'] : $this->extractPhoneNumbers($item['raw_phone'] ?? $item['phone'] ?? '');
                
                if (count($phonesList) > 0) {
                    foreach ($phonesList as $idx => $phoneNum) {
                        $cleanNum = trim((string)$phoneNum);
                        if ($cleanNum === '') continue;

                        SupplierPhone::firstOrCreate(
                            [
                                'supplier_id' => $supplier->id,
                                'phone_number' => $cleanNum,
                            ],
                            [
                                'label' => $idx === 0 ? 'Primary' : 'Office',
                                'is_primary' => $idx === 0,
                            ]
                        );
                    }
                }

                $createdSuppliers[] = $supplier;
            }

            return [
                'success' => true,
                'created_count' => $createdCount,
                'updated_count' => $updatedCount,
                'skipped_count' => $skippedCount,
                'total_processed' => count($createdSuppliers),
            ];
        });
    }

    /**
     * Map Excel column headers to known keys.
     */
    protected function mapHeaders(array $headers): array
    {
        $map = [
            'sr_no' => null,
            'name' => 1,
            'glcd' => 2,
            'city' => 3,
            'pincode' => 4,
            'contact_person' => 5,
            'phone' => 6,
        ];

        foreach ($headers as $idx => $h) {
            $hClean = strtolower(trim((string)$h));
            if (str_contains($hClean, 'sr.') || str_contains($hClean, 'sr_no') || str_contains($hClean, 's.no')) {
                $map['sr_no'] = $idx;
            } elseif ($hClean === 'pincode' || str_contains($hClean, 'pin code') || str_contains($hClean, 'pincode') || str_contains($hClean, 'postal')) {
                $map['pincode'] = $idx;
            } elseif ($hClean === 'name' || str_contains($hClean, 'supplier name') || str_contains($hClean, 'vendor')) {
                $map['name'] = $idx;
            } elseif ($hClean === 'glcd' || str_contains($hClean, 'gl code') || str_contains($hClean, 'supplier code') || $hClean === 'code') {
                $map['glcd'] = $idx;
            } elseif ($hClean === 'city') {
                $map['city'] = $idx;
            } elseif (str_contains($hClean, 'contact') || str_contains($hClean, 'person')) {
                $map['contact_person'] = $idx;
            } elseif (str_contains($hClean, 'phone') || str_contains($hClean, 'mobile') || str_contains($hClean, 'contact no')) {
                $map['phone'] = $idx;
            }
        }

        return $map;
    }

    /**
     * Extract multiple phone numbers from a formatted string (e.g. "98765/91234, 99887").
     */
    public function extractPhoneNumbers(string $phoneStr): array
    {
        if (trim($phoneStr) === '') return [];

        // Split by common delimiters: /, comma, semicolon, newline
        $delimiters = ['/', ',', ';', "\n", "\r"];
        $normalized = str_replace($delimiters, '|', $phoneStr);
        $parts = explode('|', $normalized);

        $phones = [];
        foreach ($parts as $p) {
            $clean = trim($p);
            // Remove redundant spaces or non-phone noise
            if ($clean !== '' && strlen($clean) >= 6) {
                $phones[] = $clean;
            }
        }

        return array_values(array_unique($phones));
    }
}
