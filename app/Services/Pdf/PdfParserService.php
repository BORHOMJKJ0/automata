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
        Log::info('=== extractPdfData called ===');

        $text = $this->extractText($pdfContent);

        if (! $text) {
            Log::error('extractText returned null or empty');

            return null;
        }

        $textLength = strlen($text);
        Log::info("PDF text extracted, length: {$textLength}");
        Log::info('PDF text (first 1500 chars): '.substr($text, 0, 1500));

        if ($textLength < 50) {
            Log::error('PDF text too short (< 50 chars) - likely empty or scanned image');

            return null;
        }

        // Extract all fields
        $manifestNumber = $this->extractValue($text, 'Manifest Number');
        $manifestDate = $this->extractValue($text, 'Manifest Date');
        $wastesLocation = $this->extractValue($text, 'Wastes Location');
        $wasteDescription = $this->extractWasteFromPart3($text);
        $quantity = $this->extractQuantityFromPart3($text);

        Log::info('Extracted values:');
        Log::info("  - Manifest Number: '{$manifestNumber}'");
        Log::info("  - Manifest Date: '{$manifestDate}'");
        Log::info("  - Wastes Location: '{$wastesLocation}'");
        Log::info("  - Waste Description: '{$wasteDescription}'");
        Log::info("  - Quantity: {$quantity}");

        $data = [
            'manifest_number' => $manifestNumber,
            'manifest_date' => $manifestDate,
            'wastes_location' => $wastesLocation,
            'waste_description' => $wasteDescription,
            'quantity' => $quantity,
        ];

        // Validate
        if ($manifestNumber === 'Not Found' || empty($manifestNumber)) {
            Log::error("❌ Manifest Number is 'Not Found' or empty - returning null");

            return null;
        }

        // Calculate recycled materials
        $wasteDesc = strtolower($wasteDescription);
        $data['recycled_plastic'] = (strpos($wasteDesc, 'plastic') !== false) ? $quantity : 0;
        $data['recycled_paper'] = (strpos($wasteDesc, 'paper') !== false || strpos($wasteDesc, 'cb') !== false) ? $quantity : 0;
        $data['recycled_wood'] = (strpos($wasteDesc, 'wood') !== false) ? $quantity : 0;
        $data['recycled_steel'] = (strpos($wasteDesc, 'steel') !== false || strpos($wasteDesc, 'metal') !== false || strpos($wasteDesc, 'inert') !== false) ? $quantity : 0;

        Log::info('Recycled materials calculated:');
        Log::info("  - Plastic: {$data['recycled_plastic']}");
        Log::info("  - Paper: {$data['recycled_paper']}");
        Log::info("  - Wood: {$data['recycled_wood']}");
        Log::info("  - Steel: {$data['recycled_steel']}");

        Log::info('✓ PDF data extraction successful');

        return $data;
    }

    public function extractValue(string $content, string $fieldName): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = preg_replace('/\r\n/', "\n", $content);

        // Manifest Number
        if ($fieldName === 'Manifest Number') {
            $patterns = [
                '/Manifest\s+Number\s*:?\s*([0-9]+)/i',
                '/Manifest\s+No\.?\s*:?\s*([0-9]+)/i',
                '/Manifest\s+#\s*:?\s*([0-9]+)/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $content, $matches)) {
                    Log::info("✓ Found Manifest Number: '{$matches[1]}' using pattern: {$pattern}");

                    return trim($matches[1]);
                }
            }
            Log::warning('✗ Manifest Number not found');
        }

        // Manifest Date
        if ($fieldName === 'Manifest Date') {
            $patterns = [
                '/Manifest\s+Date\s*:?\s*([0-9]{1,2}[\/\-][0-9]{1,2}[\/\-][0-9]{2,4})/i',
                '/Date\s*:?\s*([0-9]{1,2}[\/\-][0-9]{1,2}[\/\-][0-9]{2,4})/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $content, $matches)) {
                    Log::info("✓ Found Manifest Date: '{$matches[1]}'");

                    return trim($matches[1]);
                }
            }
            Log::warning('✗ Manifest Date not found');
        }

        // Producer Name
        if ($fieldName === 'Producer Name') {
            if (preg_match('/Producer\s+Name\s*:?\s*\n?\s*([^\n]+(?:\n[^\n:]+)*?)(?=\n\s*(?:Wastes Location|Trade License|Mobile|Email|Part \d+|$))/is', $content, $matches)) {
                $value = trim(preg_replace('/\s+/', ' ', $matches[1]));
                if (! empty($value)) {
                    Log::info("✓ Found Producer Name: '{$value}'");

                    return $value;
                }
            }
            Log::warning('✗ Producer Name not found');
        }

        // Wastes Location
        if ($fieldName === 'Wastes Location') {
            if (preg_match('/Wastes?\s+Location\s*:?\s*\n?\s*([^\n]+)/is', $content, $matches)) {
                $value = trim(preg_replace('/\s+/', ' ', $matches[1]));
                if (! empty($value)) {
                    Log::info("✓ Found Wastes Location: '{$value}'");

                    return $value;
                }
            }
            Log::warning('✗ Wastes Location not found');
        }

        return 'Not Found';
    }

    private function extractWasteFromPart3(string $text): string
    {
        // Try multiple patterns
        $patterns = [
            '/Part 3.*?Waste Description\s+Physical State\s+Quantity.*?\n\s*([^\n]+)/is',
            '/Part 3.*?Storage.*?Waste Description.*?\n\s*([^\n]+)/is',
            '/Waste\s+Description\s*:?\s*\n?\s*([^\n]+)/is',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $value = trim($matches[1]);
                Log::info("✓ Found Waste Description: '{$value}'");

                return $value;
            }
        }

        Log::warning('✗ Waste Description not found');

        return 'Not Found';
    }

    private function extractQuantityFromPart3(string $text): int
    {
        $patterns = [
            '/Part 3.*?Waste Description\s+Physical State\s+Quantity.*?\n[^\d]*(\d+)/is',
            '/Part 3.*?Solid\s+(\d+)/is',
            '/Quantity\s*:?\s*(\d+)/is',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $quantity = (int) $matches[1];
                Log::info("✓ Found Quantity: {$quantity}");

                return $quantity;
            }
        }

        Log::warning('✗ Quantity not found, returning 0');

        return 0;
    }
}
