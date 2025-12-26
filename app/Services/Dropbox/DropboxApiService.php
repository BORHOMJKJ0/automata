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

            // ===== FIX: حساب الـ relative path بشكل صحيح =====
            $relativePath = '';

            // تحويل كل شيء إلى lowercase للمقارنة
            $pathLower = strtolower($path);
            $sharedLinkPathLower = strtolower($sharedLinkPath);

            if (! empty($sharedLinkPathLower) && strpos($pathLower, $sharedLinkPathLower) === 0) {
                // إزالة الـ shared link path من البداية
                $relativePath = substr($path, strlen($sharedLinkPath));
            } else {
                // إذا لم يكن الـ path يبدأ بـ shared link path، استخدم الـ path كامل
                $relativePath = $path;
            }

            // تنظيف الـ relative path
            $relativePath = ltrim($relativePath, '/');

            Log::info("Calculated relative path: '{$relativePath}'");

            // ===== إعداد المحاولات المختلفة =====
            $attempts = [];

            if (! empty($relativePath)) {
                // محاولة 1: مع slash في البداية
                $attempts[] = [
                    'path' => '/'.$relativePath,
                    'desc' => 'With leading slash',
                ];

                // محاولة 2: بدون slash
                $attempts[] = [
                    'path' => $relativePath,
                    'desc' => 'Without leading slash',
                ];

                // محاولة 3: اسم الملف فقط
                $filename = basename($path);
                $attempts[] = [
                    'path' => '/'.$filename,
                    'desc' => 'Filename only with slash',
                ];

                $attempts[] = [
                    'path' => $filename,
                    'desc' => 'Filename only without slash',
                ];
            } else {
                // الملف في جذر المجلد المشترك
                $filename = basename($path);
                $attempts[] = [
                    'path' => '/'.$filename,
                    'desc' => 'Root: filename with slash',
                ];

                $attempts[] = [
                    'path' => $filename,
                    'desc' => 'Root: filename without slash',
                ];
            }

            // ===== محاولة كل path =====
            foreach ($attempts as $index => $attempt) {
                Log::info('Attempt '.($index + 1).": {$attempt['desc']} = '{$attempt['path']}'");

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
                    Log::info("✓ SUCCESS with {$attempt['desc']}! Size: ".number_format($size).' bytes');

                    return $response->body();
                }

                $status = $response->status();
                $body = $response->body();
                Log::warning("✗ Failed: Status {$status}, Response: {$body}");
            }

            // ===== محاولة أخيرة: تحميل بدون path (للملفات في الجذر مباشرة) =====
            Log::info('Final attempt: Download without path parameter');

            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$accessToken,
                    'Dropbox-API-Arg' => json_encode([
                        'url' => $sharedUrl,
                    ]),
                ])
                ->withBody('', 'application/octet-stream')
                ->post('https://content.dropboxapi.com/2/sharing/get_shared_link_file');

            if ($response->successful()) {
                $size = strlen($response->body());
                Log::info('✓ SUCCESS without path parameter! Size: '.number_format($size).' bytes');

                return $response->body();
            }

            Log::error('✗ ALL ATTEMPTS FAILED!');
            Log::error('Last status: '.$response->status());
            Log::error('Last response: '.$response->body());

            return null;

        } catch (\Exception $e) {
            Log::error('Download exception: '.$e->getMessage());
            Log::error('Stack trace: '.$e->getTraceAsString());

            return null;
        }
    }

    public function downloadFileContentDirect(string $accessToken, string $filePath): ?string
    {
        try {
            Log::info('=== Direct Download Attempt ===');
            Log::info("File path: {$filePath}");

            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$accessToken,
                    'Dropbox-API-Arg' => json_encode([
                        'path' => $filePath,
                    ]),
                ])
                ->post('https://content.dropboxapi.com/2/files/download');

            if ($response->successful()) {
                $size = strlen($response->body());
                Log::info('✓ Direct download SUCCESS! Size: '.number_format($size).' bytes');

                return $response->body();
            }

            Log::error('✗ Direct download failed: Status '.$response->status());
            Log::error('Response: '.$response->body());

            return null;

        } catch (\Exception $e) {
            Log::error('Direct download exception: '.$e->getMessage());

            return null;
        }
    }

    /**
     * دالة محسّنة تجرب كلا الطريقتين
     */
    public function downloadFileContentSmart(string $accessToken, string $sharedUrl, string $path): ?string
    {
        Log::info('=== Smart Download (trying both methods) ===');

        // محاولة 1: التحميل من shared link (الطريقة الأصلية)
        Log::info('Method 1: Trying shared link download...');
        $content = $this->downloadFileContent($accessToken, $sharedUrl, $path);

        if ($content !== null) {
            Log::info('✓ Shared link method succeeded!');

            return $content;
        }

        // محاولة 2: التحميل المباشر
        Log::info('Method 2: Trying direct download...');
        $content = $this->downloadFileContentDirect($accessToken, $path);

        if ($content !== null) {
            Log::info('✓ Direct download method succeeded!');

            return $content;
        }

        Log::error('✗ Both methods failed!');

        return null;
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
