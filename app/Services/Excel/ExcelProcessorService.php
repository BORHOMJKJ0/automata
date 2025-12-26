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
        $worksheet = $spreadsheet->getActiveSheet();

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
            $highestRow = $worksheet->getHighestRow();
            $manifestNumber = trim($pdfData['manifest_number']);

            // Check for duplicates
            for ($row = 2; $row <= $highestRow; $row++) {
                $cellValue = trim((string) $worksheet->getCell("A{$row}")->getValue());
                if ($cellValue == $manifestNumber) {
                    Log::info("⚠️ Manifest {$manifestNumber} already exists - SKIPPING");

                    return false;
                }
            }

            // Add new row
            $newRow = $highestRow + 1;

            $worksheet->setCellValue("A{$newRow}", $pdfData['manifest_number']);
            $worksheet->setCellValue("B{$newRow}", $pdfData['manifest_date']);
            $worksheet->setCellValue("C{$newRow}", $pdfData['waste_description']);
            $worksheet->setCellValue("D{$newRow}", $pdfData['wastes_location']);
            $worksheet->setCellValue("E{$newRow}", $pdfData['recycled_plastic']);
            $worksheet->setCellValue("F{$newRow}", $pdfData['recycled_paper']);
            $worksheet->setCellValue("G{$newRow}", $pdfData['recycled_wood']);
            $worksheet->setCellValue("H{$newRow}", $pdfData['recycled_steel']);

            Log::info("✓ Row {$newRow} added successfully");

            return true;
        } catch (\Exception $e) {
            Log::error('Excel Update Error: '.$e->getMessage());

            return false;
        }
    }
}
