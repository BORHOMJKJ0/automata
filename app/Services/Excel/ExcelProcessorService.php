<?php

namespace App\Services\Excel;

use App\Services\Dropbox\DropboxApiService;
use App\Services\Pdf\PdfParserService;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelProcessorService
{
    private DropboxApiService $dropboxApi;

    private PdfParserService $pdfParser;

    public function __construct(DropboxApiService $dropboxApi, PdfParserService $pdfParser)
    {
        $this->dropboxApi = $dropboxApi;
        $this->pdfParser = $pdfParser;
    }

    public function processExcelWithPdfs(
        string $accessToken,
        string $sharedUrl,
        string $excelPath,
        array $matchingFiles
    ): array {
        $excelContent = $this->dropboxApi->downloadFileContent($accessToken, $sharedUrl, $excelPath);

        if (! $excelContent) {
            throw new \Exception('Failed to download Excel file');
        }

        $tempExcelPath = tempnam(sys_get_temp_dir(), 'excel_').'.xlsx';
        file_put_contents($tempExcelPath, $excelContent);

        $spreadsheet = IOFactory::load($tempExcelPath);

        // ===== FIX 1: Get the correct worksheet by name =====
        try {
            $worksheet = $spreadsheet->getSheetByName('ManifestDetails (2)');
            if (! $worksheet) {
                Log::warning("Sheet 'ManifestDetails (2)' not found, trying by index...");
                // If not found by name, try to get second sheet (index 1)
                if ($spreadsheet->getSheetCount() > 1) {
                    $worksheet = $spreadsheet->getSheet(1);
                    Log::info('Using sheet: '.$worksheet->getTitle());
                } else {
                    $worksheet = $spreadsheet->getActiveSheet();
                    Log::info('Using active sheet: '.$worksheet->getTitle());
                }
            } else {
                Log::info('✓ Found sheet: ManifestDetails (2)');
            }
        } catch (\Exception $e) {
            Log::error('Error getting worksheet: '.$e->getMessage());
            $worksheet = $spreadsheet->getActiveSheet();
        }

        $updatedCount = 0;
        $processedFiles = [];

        foreach ($matchingFiles as $file) {
            if (! str_ends_with(strtolower($file['name']), '.pdf')) {
                continue;
            }

            $pdfContent = $this->dropboxApi->downloadFileContent($accessToken, $sharedUrl, $file['path']);

            if (! $pdfContent) {
                $processedFiles[] = [
                    'name' => $file['name'],
                    'status' => 'error',
                    'message' => 'Failed to download PDF',
                ];

                continue;
            }

            $pdfData = $this->pdfParser->extractPdfData($pdfContent);

            if ($pdfData && $pdfData['manifest_number'] !== 'Not Found') {
                $updated = $this->updateExcelRow($worksheet, $pdfData);

                if ($updated) {
                    $updatedCount++;
                    $processedFiles[] = [
                        'name' => $file['name'],
                        'status' => 'success',
                        'manifest' => $pdfData['manifest_number'],
                    ];
                }
            } else {
                $processedFiles[] = [
                    'name' => $file['name'],
                    'status' => 'warning',
                    'message' => 'No valid data found',
                ];
            }
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempExcelPath);
        $updatedContent = file_get_contents($tempExcelPath);
        unlink($tempExcelPath);

        $uploaded = $this->dropboxApi->uploadFile($accessToken, $excelPath, $updatedContent, true);

        return [
            'success' => $uploaded,
            'updatedCount' => $updatedCount,
            'processedFiles' => $processedFiles,
        ];
    }

    private function updateExcelRow($worksheet, array $pdfData): bool
    {
        try {
            // ===== FIX 2: Find the correct data range =====
            $highestRow = $worksheet->getHighestRow();
            $manifestNumber = trim($pdfData['manifest_number']);

            Log::info('=== Updating Excel ===');
            Log::info('Sheet: '.$worksheet->getTitle());
            Log::info("Highest Row: {$highestRow}");
            Log::info("Looking for Manifest: {$manifestNumber}");

            // ===== FIX 3: Find where data actually starts =====
            $dataStartRow = null;
            for ($row = 1; $row <= $highestRow; $row++) {
                $cellA = trim((string) $worksheet->getCell("A{$row}")->getValue());

                // Check if this looks like a manifest number (numeric)
                if (is_numeric($cellA) && strlen($cellA) >= 6) {
                    $dataStartRow = $row;
                    Log::info("Found data start at row {$row}");
                    break;
                }
            }

            if (! $dataStartRow) {
                Log::error('Could not find data start row!');

                return false;
            }

            // ===== FIX 4: Check for duplicates in the actual data range =====
            for ($row = $dataStartRow; $row <= $highestRow; $row++) {
                $cellValue = trim((string) $worksheet->getCell("A{$row}")->getValue());

                if ($cellValue == $manifestNumber) {
                    Log::info("⚠️ Manifest {$manifestNumber} already exists in row {$row} - SKIPPING");

                    return false;
                }
            }

            // ===== FIX 5: Add new row right after the last data row =====
            $newRow = $highestRow + 1;

            Log::info("Adding new row at: {$newRow}");
            Log::info('Data to insert: '.json_encode($pdfData));

            // Insert data in correct columns
            $worksheet->setCellValue("A{$newRow}", $pdfData['manifest_number']);        // Manifest Number
            $worksheet->setCellValue("B{$newRow}", $pdfData['manifest_date']);          // Date
            $worksheet->setCellValue("C{$newRow}", $pdfData['waste_description']);      // Waste Description
            $worksheet->setCellValue("D{$newRow}", $pdfData['wastes_location']);        // Location
            $worksheet->setCellValue("E{$newRow}", $pdfData['recycled_plastic']);       // Plastic (0)
            $worksheet->setCellValue("F{$newRow}", $pdfData['recycled_paper']);         // Paper (0)
            $worksheet->setCellValue("G{$newRow}", $pdfData['recycled_wood']);          // Wood (0)
            $worksheet->setCellValue("H{$newRow}", $pdfData['recycled_steel']);         // Steel (0)

            // ===== FIX 6: Verify data was written =====
            $verifyA = $worksheet->getCell("A{$newRow}")->getValue();
            $verifyB = $worksheet->getCell("B{$newRow}")->getValue();
            $verifyC = $worksheet->getCell("C{$newRow}")->getValue();

            Log::info("✓ Row {$newRow} added successfully");
            Log::info("Verification - A: {$verifyA}, B: {$verifyB}, C: {$verifyC}");

            return true;
        } catch (\Exception $e) {
            Log::error('Excel Update Error: '.$e->getMessage());
            Log::error('Stack trace: '.$e->getTraceAsString());

            return false;
        }
    }
}
