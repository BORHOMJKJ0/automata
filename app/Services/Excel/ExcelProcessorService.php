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
            $manifestNumber = trim($pdfData['manifest_number']);

            // ===== ثوابت حسب الصورة =====
            $headerRow = 7;
            $dataStartRow = 8;

            // ===== الحصول على آخر صف بيانات فعلي =====
            $lastDataRow = $dataStartRow - 1;

            $highestRow = $worksheet->getHighestRow();

            for ($row = $dataStartRow; $row <= $highestRow; $row++) {
                if (trim((string) $worksheet->getCell("B{$row}")->getValue()) !== '') {
                    $lastDataRow = $row;
                }
            }

            // ===== منع التكرار =====
            for ($row = $dataStartRow; $row <= $lastDataRow; $row++) {
                if (trim((string) $worksheet->getCell("B{$row}")->getValue()) === $manifestNumber) {
                    Log::info("Duplicate manifest {$manifestNumber} at row {$row}");

                    return false;
                }
            }

            // ===== الصف الجديد =====
            $newRow = $lastDataRow + 1;

            // ===== كتابة البيانات (مطابقة للصورة) =====
            $worksheet->setCellValue("A{$newRow}", $newRow - ($dataStartRow - 1));
            $worksheet->setCellValue("B{$newRow}", $pdfData['manifest_number']);
            $worksheet->setCellValue("C{$newRow}", $pdfData['manifest_date']);
            $worksheet->setCellValue("D{$newRow}", $pdfData['waste_description']);
            $worksheet->setCellValue("E{$newRow}", $pdfData['general_waste_quantity'] ?? '');
            $worksheet->setCellValue("F{$newRow}", $pdfData['recycled_steel'] ?? '');
            $worksheet->setCellValue("G{$newRow}", $pdfData['recycled_concrete'] ?? '');
            $worksheet->setCellValue("H{$newRow}", $pdfData['recycled_wood'] ?? '');
            $worksheet->setCellValue("I{$newRow}", $pdfData['recycled_paper'] ?? '');
            $worksheet->setCellValue("J{$newRow}", $pdfData['recycled_plastic'] ?? '');
            $worksheet->setCellValue("K{$newRow}", $pdfData['wastes_location']);

            Log::info("✅ Data written to row {$newRow}");

            return true;

        } catch (\Exception $e) {
            Log::error('Excel Update Error: '.$e->getMessage());

            return false;
        }
    }
}
