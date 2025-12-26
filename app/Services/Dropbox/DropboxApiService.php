<?php

namespace App\Services\Dropbox;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DropboxApiService
{
    public function getSharedLinkMetadata(string $accessToken, string $sharedUrl): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->post('https://api.dropboxapi.com/2/sharing/get_shared_link_metadata', [
                'url' => $sharedUrl,
            ]);

            return $response->successful() ? $response->json() : null;
        } catch (\Exception $e) {
            Log::error('Metadata Error: '.$e->getMessage());

            return null;
        }
    }

    public function listFolderContents(string $accessToken, string $sharedUrl, string $path): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->post('https://api.dropboxapi.com/2/files/list_folder', [
                'path' => $path,
                'shared_link' => ['url' => $sharedUrl],
            ]);

            if ($response->failed()) {
                throw new \Exception('Failed to list folder');
            }

            $data = $response->json();

            return $this->organizeItems($data['entries'] ?? []);
        } catch (\Exception $e) {
            Log::error('List Folder Error: '.$e->getMessage());
            throw $e;
        }
    }

    public function getAllFilesRecursive(string $accessToken, string $sharedUrl, ?string $path = '', ?string $cursor = null): array
    {
        $allEntries = [];
        $path = $path ?? '';

        try {
            if ($cursor) {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$accessToken,
                    'Content-Type' => 'application/json',
                ])->post('https://api.dropboxapi.com/2/files/list_folder/continue', [
                    'cursor' => $cursor,
                ]);
            } else {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$accessToken,
                    'Content-Type' => 'application/json',
                ])->post('https://api.dropboxapi.com/2/files/list_folder', [
                    'path' => $path,
                    'shared_link' => ['url' => $sharedUrl],
                    'recursive' => false,
                    'limit' => 2000,
                ]);
            }

            if ($response->successful()) {
                $data = $response->json();
                $entries = $data['entries'] ?? [];

                foreach ($entries as $entry) {
                    $allEntries[] = $entry;

                    if ($entry['.tag'] === 'folder') {
                        $folderPath = $entry['path_display'] ?? $entry['path_lower'];
                        if ($folderPath) {
                            $subEntries = $this->getAllFilesRecursive($accessToken, $sharedUrl, $folderPath);
                            $allEntries = array_merge($allEntries, $subEntries);
                        }
                    }
                }

                if (($data['has_more'] ?? false) && isset($data['cursor'])) {
                    $moreEntries = $this->getAllFilesRecursive($accessToken, $sharedUrl, $path, $data['cursor']);
                    $allEntries = array_merge($allEntries, $moreEntries);
                }
            }
        } catch (\Exception $e) {
            Log::error('Recursive List Error: '.$e->getMessage());
        }

        return $allEntries;
    }

    public function downloadFileContent(string $accessToken, string $sharedUrl, string $path): ?string
    {
        try {
            Log::info('=== Attempting Download ===');
            Log::info("Original path: {$path}");
            Log::info("Shared URL: {$sharedUrl}");

            // Get metadata first
            $metadata = $this->getSharedLinkMetadata($accessToken, $sharedUrl);

            if (! $metadata) {
                Log::error('Could not get shared link metadata');

                return null;
            }

            $sharedLinkPath = $metadata['path_lower'] ?? '';
            Log::info("Shared link root path: {$sharedLinkPath}");

            // Calculate relative path
            $pathLower = strtolower($path);
            $relativePath = $path;

            if (! empty($sharedLinkPath) && strpos($pathLower, $sharedLinkPath) === 0) {
                $relativePath = substr($path, strlen($sharedLinkPath));
            }

            // Clean up the relative path
            $relativePath = ltrim($relativePath, '/');

            Log::info("Calculated relative path: '{$relativePath}'");

            // Prepare attempts
            $attempts = [];

            if (! empty($relativePath)) {
                $attempts[] = ['path' => '/'.$relativePath, 'desc' => 'With leading slash'];
                $attempts[] = ['path' => $relativePath, 'desc' => 'Without leading slash'];
            } else {
                // File is in root of shared folder
                $filename = basename($path);
                $attempts[] = ['path' => $filename, 'desc' => 'Filename only'];
                $attempts[] = ['path' => '/'.$filename, 'desc' => 'Filename with slash'];
            }

            // Try each attempt
            foreach ($attempts as $attempt) {
                Log::info("Trying: {$attempt['desc']} = '{$attempt['path']}'");

                $response = Http::timeout(60)
                    ->withHeaders([
                        'Authorization' => 'Bearer '.$accessToken,
                        'Dropbox-API-Arg' => json_encode([
                            'url' => $sharedUrl,
                            'path' => $attempt['path'],
                        ]),
                    ])
                    ->withBody('', 'application/octet-stream')
                    ->post('https://content.dropboxapi.com/2/sharing/get_shared_link_file');

                if ($response->successful()) {
                    $size = strlen($response->body());
                    Log::info("✓ SUCCESS with {$attempt['desc']}! Size: {$size} bytes");

                    return $response->body();
                }

                Log::info("✗ Failed with {$attempt['desc']}: Status ".$response->status());
            }

            Log::error('✗ ALL ATTEMPTS FAILED!');

            return null;

        } catch (\Exception $e) {
            Log::error('Download exception: '.$e->getMessage());

            return null;
        }
    }

    public function uploadFile(string $accessToken, string $path, string $content, bool $overwrite = false): bool
    {
        try {
            Log::info('=== Starting Upload ===');
            Log::info("Path: {$path}");

            $normalizedPath = (strpos($path, '/') !== 0) ? '/'.$path : $path;
            $mode = $overwrite ? 'overwrite' : 'add';

            $response = Http::timeout(120)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$accessToken,
                    'Dropbox-API-Arg' => json_encode([
                        'path' => $normalizedPath,
                        'mode' => $mode,
                        'autorename' => ! $overwrite,
                        'mute' => false,
                    ]),
                    'Content-Type' => 'application/octet-stream',
                ])
                ->withBody($content, 'application/octet-stream')
                ->post('https://content.dropboxapi.com/2/files/upload');

            if ($response->successful()) {
                Log::info('✓ File uploaded successfully!');

                return true;
            }

            Log::error('✗ Upload failed! Status: '.$response->status());

            return false;

        } catch (\Exception $e) {
            Log::error('Upload Exception: '.$e->getMessage());

            return false;
        }
    }

    private function organizeItems(array $entries): array
    {
        $items = ['folders' => [], 'files' => []];

        foreach ($entries as $entry) {
            $path = $entry['path_display'] ?? $entry['path_lower'] ?? null;
            if (! $path) {
                continue;
            }

            if ($entry['.tag'] === 'folder') {
                $items['folders'][] = [
                    'name' => $entry['name'] ?? basename($path),
                    'path' => $path,
                ];
            } elseif ($entry['.tag'] === 'file') {
                $fileName = $entry['name'] ?? basename($path);
                $extension = pathinfo($fileName, PATHINFO_EXTENSION);

                $items['files'][] = [
                    'name' => $fileName,
                    'path' => $path,
                    'size' => $entry['size'] ?? 0,
                    'modified' => $entry['server_modified'] ?? null,
                    'extension' => strtolower($extension),
                    'is_editable' => $this->isEditable($extension),
                    'is_previewable' => $this->isPreviewable($extension),
                ];
            }
        }

        return $items;
    }

    private function isEditable(string $extension): bool
    {
        $editable = ['txt', 'md', 'json', 'xml', 'html', 'css', 'js', 'php', 'py', 'java'];

        return in_array(strtolower($extension), $editable);
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
