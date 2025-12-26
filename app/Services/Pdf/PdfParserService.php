<?php

namespace App\Services\Pdf;

use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;

class PdfParserService
{
    public function extractText(string $pdfContent): ?string
    {
        try {
            $tempPdfPath = tempnam(sys_get_temp_dir(), 'pdf_').'.pdf';
            file_put_contents($tempPdfPath, $pdfContent);

            $parser = new PdfParser;
            $pdf = $parser->parseFile($tempPdfPath);
            $text = $pdf->getText();
            unlink($tempPdfPath);

            return $text;
        } catch (\Exception $e) {
            Log::error('PDF Parse Error: '.$e->getMessage());

            return null;
        }
    }

    public function extractPdfData(string $pdfContent): ?array
    {
        $text = $this->extractText($pdfContent);

        if (! $text || strlen($text) < 50) {
            return null;
        } $data = ['manifest_number' => $this->extractValue($text, 'Manifest
    Number'),
            'manifest_date' => $this->extractValue($text, 'Manifest Date'),
            'wastes_location' => $this->extractValue($text, 'Wastes Location'),
            'waste_description' => $this->extractWasteFromPart3($text),
            'quantity' => $this->extractQuantityFromPart3($text),
        ];

        $wasteDesc = strtolower($data['waste_description']);
        $data['recycled_plastic'] = (strpos($wasteDesc, 'plastic') !== false) ? $data['quantity'] : 0;
        $data['recycled_paper'] = (strpos($wasteDesc, 'paper') !== false || strpos($wasteDesc, 'cb') !== false) ?
        $data['quantity'] : 0;
        $data['recycled_wood'] = (strpos($wasteDesc, 'wood') !== false) ? $data['quantity'] : 0;
        $data['recycled_steel'] = (strpos($wasteDesc, 'steel') !== false || strpos($wasteDesc, 'metal') !== false) ?
        $data['quantity'] : 0;

        return $data;
    }

    public function extractValue(string $content, string $fieldName): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = preg_replace('/\r\n/', "\n", $content);

        if ($fieldName === 'Producer Name') {
            if (preg_match('/Producer\s+Name\s*:\s*\n\s*([^\n]+(?:\n[^\n:]+)*?)(?=\n\s*(?:Wastes Location|Trade
    License|Mobile|Email|Company Name|Part \d+|$))/is', $content, $matches)) {
                return trim(preg_replace('/\s+/', ' ', $matches[1]));
            }
        }

        if ($fieldName === 'Wastes Location') {
            if (preg_match('/Wastes?\s+Location\s*:\s*\n?\s*([^\n]+)/is', $content, $matches)) {
                return trim(preg_replace('/\s+/', ' ', $matches[1]));
            }
        }

        if ($fieldName === 'Manifest Number') {
            if (preg_match('/Manifest\s+Number\s*:\s*([0-9]+)/i', $content, $matches)) {
                return trim($matches[1]);
            }
        }

        if ($fieldName === 'Manifest Date') {
            if (preg_match('/Manifest\s+Date\s*:\s*([0-9\/\-]+)/i', $content, $matches)) {
                return trim($matches[1]);
            }
        }

        return 'Not Found';
    }

    private function extractWasteFromPart3(string $text): string
    {
        if (preg_match('/Part 3.*?Waste Description\s+Physical State\s+Quantity.*?\n\s*([^\n]+)/is', $text, $matches)) {
            return trim($matches[1]);
        }

        return 'Not Found';
    }

    private function extractQuantityFromPart3(string $text): int
    {
        if (preg_match('/Part 3.*?Waste Description\s+Physical State\s+Quantity.*?\n[^\d]*(\d+)/is', $text, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }
}
