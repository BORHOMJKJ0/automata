<?php

namespace App\Services\Dropbox;

use App\Services\Pdf\PdfParserService;

class FileSearchService
{
    private DropboxApiService $dropboxApi;

    private PdfParserService $pdfParser;

    public function __construct(DropboxApiService $dropboxApi, PdfParserService $pdfParser)
    {
        $this->dropboxApi = $dropboxApi;
        $this->pdfParser = $pdfParser;
    }

    public function categorizeFiles(array $entries): array
    {
        $files = [
            'pdf' => [],
            'excel' => [],
            'text' => [],
        ];

        foreach ($entries as $entry) {
            if ($entry['.tag'] !== 'file' || ! isset($entry['name'])) {
                continue;
            }

            $extension = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION));
            $filePath = $entry['path_display'] ?? $entry['path_lower'];

            $fileInfo = [
                'name' => $entry['name'],
                'path' => $filePath,
                'size' => $entry['size'] ?? 0,
            ];

            if ($extension === 'pdf') {
                $files['pdf'][] = $fileInfo;
            } elseif (in_array($extension, ['xlsx', 'xls'])) {
                $files['excel'][] = $fileInfo;
            } elseif ($this->isPreviewable($extension)) {
                $files['text'][] = $fileInfo;
            }
        }

        return $files;
    }

    public function searchFiles(
        string $accessToken,
        string $sharedUrl,
        array $files,
        string $producerName,
        string $wastesLocation
    ): array {
        $matching = [];
        $nonMatching = [];

        foreach ($files['pdf'] as $file) {
            $result = $this->processFile($accessToken, $sharedUrl, $file, $producerName, $wastesLocation, 'pdf');
            if ($result['matches']) {
                $matching[] = $result['info'];
            } else {
                $nonMatching[] = $result['info'];
            }
        }

        foreach ($files['text'] as $file) {
            $result = $this->processFile($accessToken, $sharedUrl, $file, $producerName, $wastesLocation, 'text');
            if ($result['matches']) {
                $matching[] = $result['info'];
            } else {
                $nonMatching[] = $result['info'];
            }
        }

        return [
            'matching' => $matching,
            'nonMatching' => $nonMatching,
            'total' => count($files['pdf']) + count($files['text']),
        ];
    }

    private function processFile(
        string $accessToken,
        string $sharedUrl,
        array $file,
        string $producerName,
        string $wastesLocation,
        string $type
    ): array {
        $content = $this->dropboxApi->downloadFileContent($accessToken, $sharedUrl, $file['path']);

        if (! $content) {
            return [
                'matches' => false,
                'info' => array_merge($file, [
                    'type' => $type,
                    'has_producer' => false,
                    'has_wastes' => false,
                    'producer_found' => 'Failed to download',
                    'wastes_found' => 'Failed to download',
                    'missing' => ['File download failed'],
                ]),
            ];
        }

        if ($type === 'pdf') {
            $content = $this->pdfParser->extractText($content);
            if (! $content) {
                return [
                    'matches' => false,
                    'info' => array_merge($file, [
                        'type' => $type,
                        'has_producer' => false,
                        'has_wastes' => false,
                        'missing' => ['Failed to parse PDF'],
                    ]),
                ];
            }
        }

        $hasProducer = empty($producerName) || $this->matchesField($content, 'Producer Name', $producerName);
        $hasWastes = empty($wastesLocation) || $this->matchesField($content, 'Wastes Location', $wastesLocation);

        $fileInfo = array_merge($file, [
            'type' => $type,
            'has_producer' => $hasProducer,
            'has_wastes' => $hasWastes,
            'producer_found' => $this->pdfParser->extractValue($content, 'Producer Name'),
            'wastes_found' => $this->pdfParser->extractValue($content, 'Wastes Location'),
        ]);

        $matches = $hasProducer && $hasWastes;

        if (! $matches) {
            $missing = [];
            if (! $hasProducer && ! empty($producerName)) {
                $missing[] = 'Producer Name mismatch';
            }
            if (! $hasWastes && ! empty($wastesLocation)) {
                $missing[] = 'Wastes Location mismatch';
            }
            $fileInfo['missing'] = $missing;
        }

        return [
            'matches' => $matches,
            'info' => $fileInfo,
        ];
    }

    private function matchesField(string $content, string $fieldName, string $searchValue): bool
    {
        if (empty($searchValue)) {
            return true;
        }

        $extractedValue = $this->pdfParser->extractValue($content, $fieldName);

        if ($extractedValue === 'Not Found') {
            return false;
        }

        $extractedLower = mb_strtolower(trim($extractedValue), 'UTF-8');
        $searchLower = mb_strtolower(trim($searchValue), 'UTF-8');

        return strpos($extractedLower, $searchLower) !== false;
    }

    private function isPreviewable(string $extension): bool
    {
        $previewable = [
            'txt', 'md', 'json', 'xml', 'html', 'css', 'js',
            'php', 'py', 'java', 'c', 'cpp', 'h', 'yml', 'yaml',
            'ini', 'conf', 'log', 'sql', 'sh', 'bat',
        ];

        return in_array(strtolower($extension), $previewable);
    }
}
