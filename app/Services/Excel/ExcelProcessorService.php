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
        $excelContent = $this->dropboxApi->downloadFileContent(
            $accessToken,
            $sharedUrl,
            $excelPath
        );

        if (! $excelContent) {
            throw new \Exception('Failed to download Excel file');
        }

        $tempExcelPath = tempnam(sys_get_temp_dir(), 'excel_').'.xlsx';
        file_put_contents($tempExcelPath, $excelContent);

        $spreadsheet = IOFactory::load($tempExcelPath);

        // ===== الحصول على الشيت الصحيح =====
        $worksheet = $spreadsheet->getSheetByName('ManifestDetails (2)')
            ?? $spreadsheet->getActiveSheet();

        Log::info('Using sheet: '.$worksheet->getTitle());

        $updatedCount = 0;
        $processedFiles = [];

        foreach ($matchingFiles as $file) {

            if (! str_ends_with(strtolower($file['name']), '.pdf')) {
                continue;
            }

            $pdfContent = $this->dropboxApi->downloadFileContent(
                $accessToken,
                $sharedUrl,
                $file['path']
            );

            if (! $pdfContent) {
                $processedFiles[] = [
                    'name' => $file['name'],
                    'status' => 'error',
                    'message' => 'Failed to download PDF',
                ];

                continue;
            }

            $pdfData = $this->pdfParser->extractPdfData($pdfContent);

            if (
                ! $pdfData ||
                empty($pdfData['manifest_number']) ||
                $pdfData['manifest_number'] === 'Not Found'
            ) {
                $processedFiles[] = [
                    'name' => $file['name'],
                    'status' => 'warning',
                    'message' => 'No valid data found',
                ];

                continue;
            }

            $updated = $this->updateExcelRow($worksheet, $pdfData);

            if ($updated) {
                $updatedCount++;
                $processedFiles[] = [
                    'name' => $file['name'],
                    'status' => 'success',
                    'manifest' => $pdfData['manifest_number'],
                ];
            } else {
                $processedFiles[] = [
                    'name' => $file['name'],
                    'status' => 'skipped',
                    'message' => 'Duplicate manifest number',
                ];
            }
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempExcelPath);

        $updatedContent = file_get_contents($tempExcelPath);
        unlink($tempExcelPath);

        $uploaded = $this->dropboxApi->uploadFile(
            $accessToken,
            $excelPath,
            $updatedContent,
            true
        );

        return [
            'success' => $uploaded,
            'updatedCount' => $updatedCount,
            'processedFiles' => $processedFiles,
        ];
    }

    private function updateExcelRow($worksheet, array $pdfData): bool
    {
        try {
            $highestRow = $worksheet->getHighestRow();
            $manifestNumber = trim($pdfData['manifest_number']);

            Log::info('=== Updating Excel ===');
            Log::info('Sheet: '.$worksheet->getTitle());
            Log::info("Highest Row: {$highestRow}");
            Log::info("Checking Manifest: {$manifestNumber}");

            // ===== التحسين 1: التحقق أن الـ manifest number صالح =====
            if (empty($manifestNumber) || $manifestNumber === 'Not Found') {
                Log::error("Invalid manifest number: '{$manifestNumber}' - SKIPPING");

                return false;
            }

            // Find where data starts
            $dataStartRow = null;
            for ($row = 1; $row <= $highestRow; $row++) {
                $cellA = trim((string) $worksheet->getCell("A{$row}")->getValue());

                if (is_numeric($cellA) && strlen($cellA) >= 6) {
                    $dataStartRow = $row;
                    Log::info("Found data start at row {$row}");
                    break;
                }
            }

            if (! $dataStartRow) {
                Log::warning('Could not find data start row, using row 2 as default');
                $dataStartRow = 2; // استخدم السطر 2 كافتراضي
            }

            // ===== التحسين 2: فحص أكثر دقة للتكرار =====
            Log::info("Checking for duplicates from row {$dataStartRow} to {$highestRow}...");

            for ($row = $dataStartRow; $row <= $highestRow; $row++) {
                $cellValue = trim((string) $worksheet->getCell("A{$row}")->getValue());

                // تحويل كلاهما لنفس النوع للمقارنة
                $cellValue = (string) $cellValue;
                $manifestNumber = (string) $manifestNumber;

                if ($cellValue === $manifestNumber) {
                    Log::warning('⚠️ DUPLICATE FOUND!');
                    Log::warning("Manifest {$manifestNumber} already exists in row {$row}");
                    Log::warning("Existing data in row {$row}:");
                    Log::warning('  A: '.$worksheet->getCell("A{$row}")->getValue());
                    Log::warning('  B: '.$worksheet->getCell("B{$row}")->getValue());
                    Log::warning('  C: '.$worksheet->getCell("C{$row}")->getValue());
                    Log::warning('SKIPPING this PDF to prevent duplicate entry.');

                    return false;
                }
            }

            Log::info('✓ No duplicates found, proceeding to add new row...');

            // Add new row
            $newRow = $highestRow + 1;

            Log::info("Adding new row at: {$newRow}");

            // ===== الترتيب الصحيح حسب الصورة =====
            $worksheet->setCellValue("A{$newRow}", $pdfData['manifest_number']);        // Manifest Number
            $worksheet->setCellValue("B{$newRow}", $pdfData['manifest_date']);          // Manifest Date
            $worksheet->setCellValue("C{$newRow}", $pdfData['waste_description']);      // Waste Description
            $worksheet->setCellValue("D{$newRow}", $pdfData['quantity']);               // General Waste Quantity
            $worksheet->setCellValue("E{$newRow}", $pdfData['recycled_steel']);         // Recycled Steel
            $worksheet->setCellValue("F{$newRow}", 0);                                  // Recycled Concrete
            $worksheet->setCellValue("G{$newRow}", $pdfData['recycled_wood']);          // Recycled Wood
            $worksheet->setCellValue("H{$newRow}", $pdfData['recycled_paper']);         // Recycled Paper & CB
            $worksheet->setCellValue("I{$newRow}", $pdfData['recycled_plastic']);       // Recycled Plastic
            $worksheet->setCellValue("J{$newRow}", $pdfData['wastes_location']);        // Wastes Location

            // ===== التحسين 3: التحقق المزدوج بعد الإضافة =====
            $verifyA = $worksheet->getCell("A{$newRow}")->getValue();
            $verifyB = $worksheet->getCell("B{$newRow}")->getValue();
            $verifyC = $worksheet->getCell("C{$newRow}")->getValue();
            $verifyJ = $worksheet->getCell("J{$newRow}")->getValue();

            if (empty($verifyA)) {
                Log::error("Failed to write data! Cell A{$newRow} is empty.");

                return false;
            }

            Log::info("✓✓✓ Row {$newRow} added successfully!");
            Log::info('Written data verification:');
            Log::info("  A (Manifest): {$verifyA}");
            Log::info("  B (Date): {$verifyB}");
            Log::info("  C (Description): {$verifyC}");
            Log::info("  J (Location): {$verifyJ}");

            return true;
        } catch (\Exception $e) {
            Log::error('Excel Update Error: '.$e->getMessage());
            Log::error('Stack trace: '.$e->getTraceAsString());

            return false;
        }
    }
}
