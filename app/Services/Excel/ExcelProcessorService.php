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

        try {
            $worksheet = $spreadsheet->getSheetByName('ManifestDetails (2)');
            if (! $worksheet) {
                Log::warning("Sheet 'ManifestDetails (2)' not found, trying by index...");
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
                } else {
                    $processedFiles[] = [
                        'name' => $file['name'],
                        'status' => 'skipped',
                        'message' => 'Duplicate manifest number',
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
            $highestRow = $worksheet->getHighestRow();
            $manifestNumber = trim($pdfData['manifest_number']);

            Log::info('=== Updating Excel ===');
            Log::info('Sheet: '.$worksheet->getTitle());
            Log::info("Highest Row: {$highestRow}");
            Log::info("Looking for Manifest: {$manifestNumber}");

            // ===== FIX: البحث عن بداية البيانات من Column B (Manifest Number) =====
            $dataStartRow = null;
            for ($row = 1; $row <= $highestRow; $row++) {
                $cellB = trim((string) $worksheet->getCell("B{$row}")->getValue());

                // التحقق من أن القيمة رقم manifest صحيح
                if (is_numeric($cellB) && strlen($cellB) >= 7) {
                    $dataStartRow = $row;
                    Log::info("Found data start at row {$row} with manifest: {$cellB}");
                    break;
                }
            }

            if (! $dataStartRow) {
                Log::error('Could not find data start row in column B!');

                return false;
            }

            // ===== التحقق من عدم وجود تكرار في Column B =====
            for ($row = $dataStartRow; $row <= $highestRow; $row++) {
                $cellValue = trim((string) $worksheet->getCell("B{$row}")->getValue());

                if ($cellValue == $manifestNumber) {
                    Log::info("⚠️ Manifest {$manifestNumber} already exists in row {$row} - SKIPPING");

                    return false;
                }
            }

            // ===== إضافة صف جديد =====
            $newRow = $highestRow + 1;

            Log::info("Adding new row at: {$newRow}");
            Log::info('Data to insert: '.json_encode($pdfData));

            // ===== تعيين القيم في الأعمدة الصحيحة حسب الصورة =====
            // Column A: # (Row Number - يمكن تركه فارغ أو وضع رقم)
            $worksheet->setCellValue("A{$newRow}", $newRow - $dataStartRow + 1);

            // Column B: Manifest Number
            $worksheet->setCellValue("B{$newRow}", $pdfData['manifest_number']);

            // Column C: Manifest Date
            $worksheet->setCellValue("C{$newRow}", $pdfData['manifest_date']);

            // Column D: Waste Description
            $worksheet->setCellValue("D{$newRow}", $pdfData['waste_description']);

            // Column E: General Waste Quantity
            $worksheet->setCellValue("E{$newRow}", $pdfData['general_waste_quantity'] ?? '');

            // Column F: Recycled Steel
            $worksheet->setCellValue("F{$newRow}", $pdfData['recycled_steel'] ?? '0');

            // Column G: Recycled Concrete
            $worksheet->setCellValue("G{$newRow}", $pdfData['recycled_concrete'] ?? '0');

            // Column H: Recycled Wood
            $worksheet->setCellValue("H{$newRow}", $pdfData['recycled_wood'] ?? '0');

            // Column I: Recycled Paper & CB
            $worksheet->setCellValue("I{$newRow}", $pdfData['recycled_paper'] ?? '0');

            // Column J: Recycled Plastic
            $worksheet->setCellValue("J{$newRow}", $pdfData['recycled_plastic'] ?? '0');

            // Column K: Wastes Location
            $worksheet->setCellValue("K{$newRow}", $pdfData['wastes_location']);

            // ===== التحقق من الكتابة =====
            $verifyB = $worksheet->getCell("B{$newRow}")->getValue();
            $verifyC = $worksheet->getCell("C{$newRow}")->getValue();
            $verifyD = $worksheet->getCell("D{$newRow}")->getValue();

            Log::info("✓ Row {$newRow} added successfully");
            Log::info("Verification - Manifest: {$verifyB}, Date: {$verifyC}, Description: {$verifyD}");

            return true;

        } catch (\Exception $e) {
            Log::error('Excel Update Error: '.$e->getMessage());
            Log::error('Stack trace: '.$e->getTraceAsString());

            return false;
        }
    }
}
