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

    public function __construct(
        DropboxApiService $dropboxApi,
        PdfParserService $pdfParser
    ) {
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

        $tempExcelPath = tempnam(sys_get_temp_dir(), 'excel_') . '.xlsx';
        file_put_contents($tempExcelPath, $excelContent);

        $spreadsheet = IOFactory::load($tempExcelPath);

        try {
            $worksheet = $spreadsheet->getSheetByName('ManifestDetails (2)');
            if (! $worksheet) {
                Log::warning("Sheet 'ManifestDetails (2)' not found, trying by index...");
                if ($spreadsheet->getSheetCount() > 1) {
                    $worksheet = $spreadsheet->getSheet(1);
                    Log::info('Using sheet: ' . $worksheet->getTitle());
                } else {
                    $worksheet = $spreadsheet->getActiveSheet();
                    Log::info('Using active sheet: ' . $worksheet->getTitle());
                }
            } else {
                Log::info('Using sheet: ManifestDetails (2)');
            }
        } catch (\Exception $e) {
            Log::error('Error getting worksheet: ' . $e->getMessage());
            $worksheet = $spreadsheet->getActiveSheet();
        }

        $updatedCount = 0;
        $processedFiles = [];

        Log::info('Processing ' . count($matchingFiles) . ' files...');

        foreach ($matchingFiles as $file) {
            Log::info("=== Processing file: {$file['name']} ===");

            if (! str_ends_with(strtolower($file['name']), '.pdf')) {
                Log::info('Skipping non-PDF file');

                continue;
            }

            $pdfContent = $this->dropboxApi->downloadFileContent($accessToken, $sharedUrl, $file['path']);

            if (! $pdfContent) {
                Log::error("Failed to download PDF: {$file['name']}");
                $processedFiles[] = [
                    'name' => $file['name'],
                    'status' => 'error',
                    'message' => 'Failed to download PDF',
                ];

                continue;
            }

            Log::info('PDF downloaded, size: ' . strlen($pdfContent) . ' bytes');
            Log::info('Extracting PDF data...');

            $pdfData = $this->pdfParser->extractPdfData($pdfContent);

            if (! $pdfData) {
                Log::error("extractPdfData returned NULL for {$file['name']}");
                $processedFiles[] = [
                    'name' => $file['name'],
                    'status' => 'error',
                    'message' => 'Failed to extract PDF data (returned null)',
                ];

                continue;
            }

            Log::info('PDF Data extracted: ' . json_encode($pdfData));

            if ($pdfData['manifest_number'] === 'Not Found' || empty($pdfData['manifest_number'])) {
                Log::warning("No valid manifest number found in {$file['name']}");
                $processedFiles[] = [
                    'name' => $file['name'],
                    'status' => 'warning',
                    'message' => 'No valid manifest number found',
                ];

                continue;
            }

            Log::info("Calling updateExcelRow for manifest: {$pdfData['manifest_number']}");

            $updated = $this->updateExcelRow($worksheet, $pdfData);

            if ($updated) {
                $updatedCount++;
                Log::info("✓ Successfully updated Excel with {$file['name']}");
                $processedFiles[] = [
                    'name' => $file['name'],
                    'status' => 'success',
                    'manifest' => $pdfData['manifest_number'],
                ];
            } else {
                Log::warning("updateExcelRow returned false for {$file['name']}");
                $processedFiles[] = [
                    'name' => $file['name'],
                    'status' => 'skipped',
                    'message' => 'Duplicate or update failed',
                ];
            }
        }

        Log::info('=== Processing Complete ===');
        Log::info('Total files processed: ' . count($matchingFiles));
        Log::info("Successfully updated: {$updatedCount}");

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempExcelPath);
        $updatedContent = file_get_contents($tempExcelPath);
        unlink($tempExcelPath);

        Log::info('Uploading updated Excel file...');
        $uploaded = $this->dropboxApi->uploadFile($accessToken, $excelPath, $updatedContent, true);

        if ($uploaded) {
            Log::info('✓ Excel file uploaded successfully');
        } else {
            Log::error('✗ Failed to upload Excel file');
        }

        return [
            'success' => $uploaded,
            'updatedCount' => $updatedCount,
            'processedFiles' => $processedFiles,
        ];
    }

    private function updateExcelRow($worksheet, array $pdfData): bool
    {
        try {
            $manifestNumber = trim($pdfData['manifest_number']);

            Log::info('=== Updating Excel ===');
            Log::info("Looking for Manifest: {$manifestNumber}");

            if (empty($manifestNumber) || $manifestNumber === 'Not Found') {
                Log::error('Invalid manifest number - SKIPPING');

                return false;
            }

            // ===== STEP 1: Check for duplicates in ENTIRE sheet =====
            $highestRow = $worksheet->getHighestDataRow();

            Log::info("Checking for duplicates in {$highestRow} rows...");

            for ($row = 1; $row <= $highestRow; $row++) {
                $cellA = trim((string) $worksheet->getCell("A{$row}")->getValue());

                // If manifest number exists, SKIP
                if ($cellA === $manifestNumber) {
                    Log::warning("⚠️ DUPLICATE FOUND! Manifest {$manifestNumber} already exists in row {$row}");
                    Log::warning('SKIPPING - Will not add duplicate entry');

                    return false;
                }
            }

            Log::info("✓ No duplicate found, manifest {$manifestNumber} is unique");

            // ===== STEP 2: Find where to insert (after last data row) =====
            $lastDataRow = null;

            // Scan to find last row with actual manifest data
            for ($row = 1; $row <= $highestRow; $row++) {
                $cellA = trim((string) $worksheet->getCell("A{$row}")->getValue());

                // Check if this is a data row (numeric manifest number)
                if (! empty($cellA) && is_numeric($cellA) && strlen($cellA) >= 6) {
                    $lastDataRow = $row;
                }
            }

            if (! $lastDataRow) {
                // No data found, look for header row and add after it
                for ($row = 1; $row <= 20; $row++) {
                    $cellA = trim((string) $worksheet->getCell("A{$row}")->getValue());
                    $cellB = trim((string) $worksheet->getCell("B{$row}")->getValue());
                    $cellC = trim((string) $worksheet->getCell("C{$row}")->getValue());

                    // Look for header indicators
                    if (
                        stripos($cellA . $cellB . $cellC, 'Manifest') !== false ||
                        stripos($cellA . $cellB . $cellC, 'Number') !== false ||
                        stripos($cellA . $cellB . $cellC, 'Date') !== false
                    ) {
                        $lastDataRow = $row;
                        Log::info("Found header-like row at {$row}, will add data after it");
                        break;
                    }
                }
            }

            if (! $lastDataRow) {
                Log::error('Could not determine where to insert data!');

                return false;
            }

            // ===== STEP 3: Insert at next row =====
            $newRow = $lastDataRow + 1;

            Log::info("Inserting new data at row: {$newRow}");

            // Clean waste description - keep only the type
            $wasteDesc = $pdfData['waste_description'];
            $wasteDesc = preg_replace('/\s+(Solid|Liquid|Gas)\s+.*/i', '', $wasteDesc);
            $wasteDesc = str_replace(["\t", "\r", "\n"], ' ', $wasteDesc);
            $wasteDesc = preg_replace('/\s+/', ' ', $wasteDesc);
            $wasteDesc = trim($wasteDesc);

            // Write data - EXACT column mapping from your image
            $worksheet->setCellValue("A{$newRow}", $manifestNumber);                   // Manifest Number
            $worksheet->setCellValue("B{$newRow}", $pdfData['manifest_date']);        // Manifest Date
            $worksheet->setCellValue("C{$newRow}", $wasteDesc);                       // Waste Description
            $worksheet->setCellValue("D{$newRow}", $pdfData['quantity']);             // General Waste Quantity
            $worksheet->setCellValue("E{$newRow}", $pdfData['recycled_steel']);       // Recycled Steel
            $worksheet->setCellValue("F{$newRow}", 0);                                // Recycled Concrete
            $worksheet->setCellValue("G{$newRow}", $pdfData['recycled_wood']);        // Recycled Wood
            $worksheet->setCellValue("H{$newRow}", $pdfData['recycled_paper']);       // Recycled Paper & CB
            $worksheet->setCellValue("I{$newRow}", $pdfData['recycled_plastic']);     // Recycled Plastic
            $worksheet->setCellValue("J{$newRow}", $pdfData['wastes_location']);      // Wastes Location

            // ===== STEP 4: Verify data was written =====
            $verifyA = $worksheet->getCell("A{$newRow}")->getValue();

            if (empty($verifyA)) {
                Log::error("❌ FAILED! Data was not written to row {$newRow}");

                return false;
            }

            Log::info("✅ SUCCESS! Row {$newRow} added");
            Log::info("   A: {$verifyA}");
            Log::info('   B: ' . $worksheet->getCell("B{$newRow}")->getValue());
            Log::info('   C: ' . $worksheet->getCell("C{$newRow}")->getValue());
            Log::info('   J: ' . $worksheet->getCell("J{$newRow}")->getValue());

            return true;
        } catch (\Exception $e) {
            Log::error('Excel Update Error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());

            return false;
        }
    }
}
