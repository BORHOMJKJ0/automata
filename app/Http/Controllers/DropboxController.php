<?php
namespace App\Http\Controllers;

use App\Services\BitrixNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Smalot\PdfParser\Parser as PdfParser;

class DropboxController extends Controller
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private BitrixNotificationService $bitrixService;

    public function __construct()
    {
        $this->clientId = env('DROPBOX_CLIENT_ID');
        $this->clientSecret = env('DROPBOX_CLIENT_SECRET');
        $this->redirectUri = env('DROPBOX_REDIRECT_URI', url('/dropbox/callback'));
        $this->middleware('web');
        $this->bitrixService = new BitrixNotificationService;
    }

    // ==================== AUTHENTICATION ====================
    public function index()
    {
        return view('dropbox.index');
    }

    public function connect()
    {
        $authUrl = 'https://www.dropbox.com/oauth2/authorize?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'token_access_type' => 'offline',
        ]);
        return redirect($authUrl);
    }

    public function callback(Request $request)
    {
        $code = $request->get('code');
        if (! $code) {
            return redirect()->route('dropbox.index')
                ->with('error', 'فشل الاتصال بـ Dropbox');
        }

        try {
            $response = Http::asForm()->post('https://api.dropboxapi.com/oauth2/token', [
                'code' => $code,
                'grant_type' => 'authorization_code',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $this->redirectUri,
            ]);

            if ($response->failed()) {
                return redirect()->route('dropbox.index')
                    ->with('error', 'فشل الحصول على صلاحيات الوصول');
            }

            $data = $response->json();
            Session::put('dropbox_access_token', $data['access_token']);
            Session::put('dropbox_account_id', $data['account_id']);

            return redirect()->route('dropbox.index')
                ->with('success', 'تم الاتصال بـ Dropbox بنجاح!');
        } catch (\Exception $e) {
            Log::error('OAuth Error: '.$e->getMessage());
            return redirect()->route('dropbox.index')
                ->with('error', 'خطأ: '.$e->getMessage());
        }
    }

    public function logout()
    {
        Session::forget(['dropbox_access_token', 'dropbox_account_id', 'current_shared_url']);
        return redirect()->route('dropbox.index')->with('success', 'تم تسجيل الخروج بنجاح');
    }

    // ==================== FILE BROWSING ====================
    public function browseSharedLink(Request $request)
    {
        $accessToken = Session::get('dropbox_access_token');
        if (! $accessToken) {
            return redirect()->route('dropbox.index')->with('error', 'يجب تسجيل الدخول أولاً');
        }

        $sharedUrl = $request->input('shared_url');
        if (! $sharedUrl) {
            return redirect()->route('dropbox.index')->with('error', 'الرجاء إدخال رابط مشارك');
        }

        $request->validate(['shared_url' => 'required|url']);
        $sharedUrl = str_replace('dl=0', 'dl=1', $sharedUrl);
        Session::put('current_shared_url', $sharedUrl);

        try {
            $metadata = $this->getSharedLinkMetadata($accessToken, $sharedUrl);
            if (! $metadata || $metadata['.tag'] !== 'folder') {
                return redirect()->route('dropbox.index')
                    ->with('error', 'الرابط يجب أن يكون لمجلد وليس لملف');
            }

            $items = $this->listFolderContents($accessToken, $sharedUrl, '');

            return view('dropbox.browse', [
                'items' => $items,
                'currentPath' => '',
                'sharedUrl' => $sharedUrl,
            ]);
        } catch (\Exception $e) {
            Log::error('Browse Error: '.$e->getMessage());
            return redirect()->route('dropbox.index')
                ->with('error', 'خطأ: '.$e->getMessage());
        }
    }

    public function browseSharedSubfolder(Request $request)
    {
        $accessToken = Session::get('dropbox_access_token');
        if (! $accessToken) {
            return redirect()->route('dropbox.index')->with('error', 'يجب تسجيل الدخول أولاً');
        }

        $sharedUrl = $request->query('shared_url')
            ?? $request->input('shared_url')
            ?? Session::get('current_shared_url');
        $path = $request->query('path') ?? $request->input('path', '') ?? '';

        if (! $sharedUrl) {
            return redirect()->route('dropbox.index')->with('error', 'رابط مشارك مطلوب');
        }

        Session::put('current_shared_url', $sharedUrl);

        try {
            $items = $this->listFolderContents($accessToken, $sharedUrl, $path);

            return view('dropbox.browse', [
                'items' => $items,
                'currentPath' => $path,
                'sharedUrl' => $sharedUrl,
            ]);
        } catch (\Exception $e) {
            Log::error('Browse Subfolder Error: '.$e->getMessage());
            return back()->with('error', 'لا يمكن فتح هذا المجلد');
        }
    }

    // ==================== FILE OPERATIONS ====================
    public function downloadSharedFile(Request $request)
    {
        $accessToken = Session::get('dropbox_access_token');
        if (! $accessToken) {
            return back()->with('error', 'يجب تسجيل الدخول أولاً');
        }

        $sharedUrl = $request->input('shared_url');
        $path = $request->input('path');

        if (! $sharedUrl || ! $path) {
            return back()->with('error', 'معلومات غير كاملة');
        }

        try {
            Log::info("Downloading file: {$path}");
            Log::info("Shared URL: {$sharedUrl}");

            $content = $this->downloadFileContent($accessToken, $sharedUrl, $path);
            if (! $content) {
                Log::error("Failed to download file: {$path}");
                return back()->with('error', 'فشل تحميل الملف - تحقق من الصلاحيات');
            }

            $filename = basename($path);
            Log::info("File downloaded successfully: {$filename}, size: ".strlen($content).' bytes');

            return response($content)
                ->header('Content-Type', 'application/octet-stream')
                ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
        } catch (\Exception $e) {
            Log::error('Download Error: '.$e->getMessage());
            Log::error('Stack trace: '.$e->getTraceAsString());
            return back()->with('error', 'خطأ: '.$e->getMessage());
        }
    }

    public function previewFile(Request $request)
    {
        $accessToken = Session::get('dropbox_access_token');
        if (! $accessToken) {
            return back()->with('error', 'يجب تسجيل الدخول أولاً');
        }

        $sharedUrl = $request->input('shared_url') ?? $request->query('shared_url');
        $path = $request->input('path') ?? $request->query('path');

        if (! $sharedUrl || ! $path) {
            return back()->with('error', 'معلومات غير كاملة');
        }

        try {
            $content = $this->downloadFileContent($accessToken, $sharedUrl, $path);
            if (! $content) {
                return back()->with('error', 'فشل قراءة الملف');
            }

            if (strlen($content) > 1048576) {
                return back()->with('error', 'الملف كبير جداً للمعاينة (أكثر من 1MB)');
            }

            $filename = basename($path);
            $extension = pathinfo($filename, PATHINFO_EXTENSION);

            return view('dropbox.preview', [
                'path' => $path,
                'filename' => $filename,
                'content' => $content,
                'extension' => strtolower($extension),
                'sharedUrl' => $sharedUrl,
            ]);
        } catch (\Exception $e) {
            Log::error('Preview Error: '.$e->getMessage());
            return back()->with('error', 'خطأ: '.$e->getMessage());
        }
    }

    // ==================== SEARCH & MATCH ====================
    public function searchAndMatch(Request $request)
    {
        $accessToken = Session::get('dropbox_access_token');
        if (! $accessToken) {
            return back()->with('error', 'يجب تسجيل الدخول أولاً');
        }

        if ($request->isMethod('get')) {
            return view('dropbox.search-results', [
                'producerName' => '',
                'wastesLocation' => '',
                'matchingFiles' => [],
                'nonMatchingFiles' => [],
                'totalFiles' => 0,
                'allExcelFiles' => [],
                'sharedUrl' => $request->query('shared_url') ?? Session::get('current_shared_url'),
                'currentPath' => $request->query('current_path', '') ?? '',
            ]);
        }

        $producerName = trim($request->input('producer_name', ''));
        $wastesLocation = trim($request->input('wastes_location', ''));
        $sharedUrl = $request->input('shared_url') ?? Session::get('current_shared_url');
        $currentPath = $request->input('current_path', '') ?? '';

        if (empty($producerName) && empty($wastesLocation)) {
            return back()->with('error', 'الرجاء إدخال قيمة واحدة على الأقل للبحث');
        }

        if (! $sharedUrl) {
            return back()->with('error', 'رابط مشارك مطلوب');
        }

        try {
            Log::info('=== Starting Search ===');
            Log::info("Shared URL: {$sharedUrl}");
            Log::info("Current Path: {$currentPath}");
            Log::info("Producer: {$producerName}, Location: {$wastesLocation}");

            $allEntries = $this->getAllFilesRecursive($accessToken, $sharedUrl, $currentPath);
            Log::info('Total entries found: '.count($allEntries));

            if (count($allEntries) > 0) {
                Log::info('Sample entries: '.json_encode(array_slice($allEntries, 0, 3)));
            } else {
                Log::warning('No entries found! This might be a problem.');
            }

            $pdfFiles = [];
            $excelFiles = [];
            $textFiles = [];

            foreach ($allEntries as $entry) {
                if ($entry['.tag'] !== 'file' || ! isset($entry['name'])) {
                    continue;
                }

                $extension = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION));
                $filePath = $entry['path_display'] ?? $entry['path_lower'];

                if ($extension === 'pdf') {
                    $pdfFiles[] = [
                        'name' => $entry['name'],
                        'path' => $filePath,
                        'size' => $entry['size'] ?? 0,
                    ];
                } elseif (in_array($extension, ['xlsx', 'xls'])) {
                    $excelFiles[] = [
                        'name' => $entry['name'],
                        'path' => $filePath,
                        'size' => $entry['size'] ?? 0,
                    ];
                } elseif ($this->isPreviewable($extension)) {
                    $textFiles[] = [
                        'name' => $entry['name'],
                        'path' => $filePath,
                        'size' => $entry['size'] ?? 0,
                    ];
                }
            }

            Log::info('Files found - PDFs: '.count($pdfFiles).', Excel: '.count($excelFiles).', Text: '.count($textFiles));

            $matchingFiles = [];
            $nonMatchingFiles = [];

            foreach ($pdfFiles as $file) {
                $result = $this->processFile($accessToken, $sharedUrl, $file, $producerName, $wastesLocation, 'pdf');
                if ($result['matches']) {
                    $matchingFiles[] = $result['info'];
                } else {
                    $nonMatchingFiles[] = $result['info'];
                }
            }

            foreach ($textFiles as $file) {
                $result = $this->processFile($accessToken, $sharedUrl, $file, $producerName, $wastesLocation, 'text');
                if ($result['matches']) {
                    $matchingFiles[] = $result['info'];
                } else {
                    $nonMatchingFiles[] = $result['info'];
                }
            }

            Log::info('Results - Matching: '.count($matchingFiles).', Non-matching: '.count($nonMatchingFiles));

            if (count($matchingFiles) > 0) {
                $criteria = "Producer: {$producerName}, Location: {$wastesLocation}";
                $this->bitrixService->notifySearchResults(
                    count($matchingFiles),
                    count($pdfFiles) + count($textFiles),
                    $criteria
                );
            }

            return view('dropbox.search-results', [
                'producerName' => $producerName,
                'wastesLocation' => $wastesLocation,
                'matchingFiles' => $matchingFiles,
                'nonMatchingFiles' => $nonMatchingFiles,
                'totalFiles' => count($pdfFiles) + count($textFiles),
                'allExcelFiles' => $excelFiles,
                'sharedUrl' => $sharedUrl,
                'currentPath' => $currentPath,
            ]);
        } catch (\Exception $e) {
            Log::error('Search Error: '.$e->getMessage());
            Log::error($e->getTraceAsString());
            return back()->with('error', 'خطأ: '.$e->getMessage());
        }
    }

    // ==================== EXCEL PROCESSING ====================
    public function processExcelUpdate(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        $accessToken = Session::get('dropbox_access_token');
        if (! $accessToken) {
            return back()->with('error', 'يجب تسجيل الدخول أولاً');
        }

        $sharedUrl = $request->input('shared_url') ?? Session::get('current_shared_url');
        $matchingFiles = json_decode($request->input('matching_files'), true);
        $excelPath = $request->input('excel_file');

        if (! $sharedUrl || ! $excelPath || empty($matchingFiles)) {
            return back()->with('error', 'معلومات غير كاملة');
        }

        try {
            Log::info('=== Starting Excel Update ===');
            Log::info("Excel file: {$excelPath}");
            Log::info('Files to process: '.count($matchingFiles));

            $excelContent = $this->downloadFileContent($accessToken, $sharedUrl, $excelPath);
            if (! $excelContent) {
                return back()->with('error', 'فشل تحميل ملف Excel');
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

                Log::info("Processing: {$file['name']}");

                $pdfContent = $this->downloadFileContent($accessToken, $sharedUrl, $file['path']);
                if (! $pdfContent) {
                    $processedFiles[] = [
                        'name' => $file['name'],
                        'status' => 'error',
                        'message' => 'Failed to download PDF',
                    ];
                    continue;
                }

                $pdfData = $this->extractPdfData($pdfContent);
                if ($pdfData && $pdfData['manifest_number'] !== 'Not Found') {
                    $updated = $this->updateExcelRow($worksheet, $pdfData);
                    if ($updated) {
                        $updatedCount++;
                        $processedFiles[] = [
                            'name' => $file['name'],
                            'status' => 'success',
                            'manifest' => $pdfData['manifest_number'],
                            'quantity' => $pdfData['quantity'],
                        ];
                        Log::info("✓ Updated: {$file['name']}");
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

            // Upload with overwrite mode
            $uploaded = $this->uploadToDropbox($accessToken, $excelPath, $updatedContent, true);

            if ($uploaded) {
                Log::info('=== Excel Update Complete ===');
                Log::info("Updated {$updatedCount} files");

                $this->bitrixService->notifyExcelProcessed(
                    count($matchingFiles),
                    $updatedCount,
                    $excelPath
                );

                return view('dropbox.process-results', [
                    'success' => true,
                    'updatedCount' => $updatedCount,
                    'processedFiles' => $processedFiles,
                    'newFilePath' => $excelPath,
                    'sharedUrl' => $sharedUrl,
                ]);
            }

            return back()->with('error', 'فشل رفع الملف المحدث');
        } catch (\Exception $e) {
            Log::error('Process Excel Error: '.$e->getMessage());
            Log::error($e->getTraceAsString());
            return back()->with('error', 'خطأ: '.$e->getMessage());
        }
    }

    public function showProcessResults()
    {
        return view('dropbox.process-results', [
            'success' => session('success', false),
            'updatedCount' => session('updatedCount', 0),
            'processedFiles' => session('processedFiles', []),
            'newFilePath' => session('newFilePath', ''),
            'sharedUrl' => session('sharedUrl', ''),
        ]);
    }

    // ==================== HELPER METHODS ====================
    private function getSharedLinkMetadata(string $accessToken, string $sharedUrl): ?array
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

    private function extractWasteFromPart3(string $text): string
    {
        if (preg_match('/Part 3.*?Waste Description\s+Physical State\s+Quantity.*?\n\s*([^\n]+)/is', $text, $matches)) {
            return trim($matches[1]);
        }
        if (preg_match('/Part 3.*?Storage.*?Waste Description.*?\n\s*([^\n]+)/is', $text, $matches)) {
            return trim($matches[1]);
        }
        return 'Not Found';
    }

    private function extractQuantityFromPart3(string $text): int
    {
        if (preg_match('/Part 3.*?Waste Description\s+Physical State\s+Quantity.*?\n[^\d]*(\d+)/is', $text, $matches)) {
            return (int) $matches[1];
        }
        if (preg_match('/Part 3.*?Solid\s+(\d+)/is', $text, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    private function updateExcelRow($worksheet, array $pdfData): bool
    {
        try {
            $highestRow = $worksheet->getHighestRow();
            $manifestNumber = trim($pdfData['manifest_number']);

            $foundRow = null;
            for ($row = 2; $row <= $highestRow; $row++) {
                $cellValue = trim((string) $worksheet->getCell("A{$row}")->getValue());
                if ($cellValue == $manifestNumber) {
                    $foundRow = $row;
                    Log::info("Found existing row {$row} for manifest {$manifestNumber}");
                    break;
                }
            }

            if (! $foundRow) {
                $foundRow = $highestRow + 1;
                Log::info("Creating new row {$foundRow} for manifest {$manifestNumber}");
            }

            $worksheet->setCellValue("A{$foundRow}", $pdfData['manifest_number']);
            $worksheet->setCellValue("B{$foundRow}", $pdfData['manifest_date']);
            $worksheet->setCellValue("C{$foundRow}", $pdfData['waste_description']);
            $worksheet->setCellValue("D{$foundRow}", $pdfData['quantity']);
            $worksheet->setCellValue("E{$foundRow}", $pdfData['wastes_location']);
            $worksheet->setCellValue("F{$foundRow}", $pdfData['recycled_plastic']);
            $worksheet->setCellValue("G{$foundRow}", $pdfData['recycled_paper']);
            $worksheet->setCellValue("H{$foundRow}", $pdfData['recycled_wood']);
            $worksheet->setCellValue("I{$foundRow}", $pdfData['recycled_steel']);

            Log::info("Row {$foundRow} updated successfully");
            return true;
        } catch (\Exception $e) {
            Log::error('Excel Update Error: '.$e->getMessage());
            return false;
        }
    }

    private function uploadToDropbox(string $accessToken, string $path, string $content, bool $overwrite = false): bool
    {
        try {
            Log::info("=== Starting Upload to Dropbox ===");
            Log::info("Path: {$path}");
            Log::info("Content size: " . strlen($content) . " bytes");
            Log::info("Overwrite mode: " . ($overwrite ? 'YES' : 'NO'));

            $normalizedPath = $path;
            if (strpos($normalizedPath, '/') !== 0) {
                $normalizedPath = '/' . $normalizedPath;
            }

            Log::info("Normalized path: {$normalizedPath}");

            $mode = $overwrite ? 'overwrite' : 'add';

            $apiArg = [
                'path' => $normalizedPath,
                'mode' => $mode,
                'autorename' => !$overwrite,
                'mute' => false,
            ];

            Log::info("API Args: " . json_encode($apiArg));

            $response = Http::timeout(120)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Dropbox-API-Arg' => json_encode($apiArg),
                    'Content-Type' => 'application/octet-stream',
                ])
                ->withBody($content, 'application/octet-stream')
                ->post('https://content.dropboxapi.com/2/files/upload');

            if ($response->successful()) {
                $responseData = $response->json();
                Log::info("✓ File uploaded successfully!");
                Log::info("Response: " . json_encode($responseData));

                $this->bitrixService->notifyFileUploaded(
                    basename($path),
                    $path,
                    []
                );

                return true;
            }

            Log::error('✗ Upload failed!');
            Log::error('Status: ' . $response->status());
            Log::error('Response: ' . $response->body());

            return false;

        } catch (\Exception $e) {
            Log::error('Upload Exception: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            $this->bitrixService->notifyError('File Upload', $e->getMessage());
            return false;
        }
    }

    private function organizeItems(array $entries): array
    {
        $items = ['folders' => [], 'files' => []];

        foreach ($entries as $entry) {
            $path = $entry['path_display'] ?? $entry['path_lower'] ?? null;
            if (! $path) {
                Log::warning('Entry without path: '.json_encode($entry));
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

    private function listFolderContents(string $accessToken, string $sharedUrl, string $path): array
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

    private function getAllFilesRecursive(string $accessToken, string $sharedUrl, ?string $path = '', ?string $cursor = null): array
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
                Log::info('Found '.count($entries)." entries in path: {$path}");

                foreach ($entries as $entry) {
                    $allEntries[] = $entry;

                    if ($entry['.tag'] === 'folder') {
                        $folderPath = $entry['path_display'] ?? $entry['path_lower'];
                        if ($folderPath) {
                            Log::info("Recursively scanning folder: {$folderPath}");
                            $subEntries = $this->getAllFilesRecursive($accessToken, $sharedUrl, $folderPath);
                            $allEntries = array_merge($allEntries, $subEntries);
                        }
                    }
                }

                if (($data['has_more'] ?? false) && isset($data['cursor'])) {
                    $moreEntries = $this->getAllFilesRecursive($accessToken, $sharedUrl, $path, $data['cursor']);
                    $allEntries = array_merge($allEntries, $moreEntries);
                }
            } else {
                Log::error('List folder failed: '.$response->body());
            }
        } catch (\Exception $e) {
            Log::error('Recursive List Error: '.$e->getMessage());
            Log::error('Stack trace: '.$e->getTraceAsString());
        }

        return $allEntries;
    }

    private function downloadFileContent(string $accessToken, string $sharedUrl, string $path): ?string
    {
        try {
            Log::info("=== Attempting Download ===");
            Log::info("Original path: {$path}");
            Log::info("Shared URL: {$sharedUrl}");

            $metadata = $this->getSharedLinkMetadata($accessToken, $sharedUrl);

            if (!$metadata) {
                Log::error("Could not get shared link metadata");
                return null;
            }

            $sharedLinkPath = $metadata['path_lower'] ?? '';
            Log::info("Shared link root path: {$sharedLinkPath}");

            $relativePath = $path;

            if (!empty($sharedLinkPath) && strpos($path, $sharedLinkPath) === 0) {
                $relativePath = substr($path, strlen($sharedLinkPath));
            }

            if (empty($relativePath)) {
                $relativePath = '/';
            } elseif (strpos($relativePath, '/') !== 0) {
                $relativePath = '/' . $relativePath;
            }

            Log::info("Calculated relative path: {$relativePath}");

            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Dropbox-API-Arg' => json_encode([
                        'url' => $sharedUrl,
                        'path' => $relativePath,
                    ]),
                ])
                ->withBody('', 'application/octet-stream')
                ->post('https://content.dropboxapi.com/2/sharing/get_shared_link_file');

            if ($response->successful()) {
                $size = strlen($response->body());
                Log::info("✓ Download successful! Size: {$size} bytes");
                return $response->body();
            }

            Log::error("✗ Download failed!");
            Log::error('Status: ' . $response->status());
            Log::error('Response: ' . $response->body());

            return null;

        } catch (\Exception $e) {
            Log::error("Download exception: " . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return null;
        }
    }

    private function processFile(string $accessToken, string $sharedUrl, array $file, string $producerName, string $wastesLocation, string $type): array
    {
        $content = $this->downloadFileContent($accessToken, $sharedUrl, $file['path']);

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

        Log::info("Processing {$file['name']}, content length: ".strlen($content));

        if ($type === 'pdf') {
            $tempPdfPath = tempnam(sys_get_temp_dir(), 'pdf_').'.pdf';
            file_put_contents($tempPdfPath, $content);

            try {
                $parser = new PdfParser;
                $pdf = $parser->parseFile($tempPdfPath);
                $content = $pdf->getText();
                unlink($tempPdfPath);

                Log::info('PDF parsed, text length: '.strlen($content));
                Log::info('PDF text sample (first 500 chars): '.substr($content, 0, 500));
            } catch (\Exception $e) {
                Log::error('Failed to parse PDF: '.$e->getMessage());
                unlink($tempPdfPath);
                return [
                    'matches' => false,
                    'info' => array_merge($file, [
                        'type' => $type,
                        'has_producer' => false,
                        'has_wastes' => false,
                        'producer_found' => 'PDF parse error',
                        'wastes_found' => 'PDF parse error',
                        'missing' => ['Failed to parse PDF'],
                    ]),
                ];
            }
        }

        $hasProducer = empty($producerName) || $this->matchesField($content, 'Producer Name', $producerName);
        $hasWastes = empty($wastesLocation) || $this->matchesField($content, 'Wastes Location', $wastesLocation);

        $producerFound = $this->extractValue($content, 'Producer Name');
        $wastesFound = $this->extractValue($content, 'Wastes Location');

        $fileInfo = array_merge($file, [
            'type' => $type,
            'has_producer' => $hasProducer,
            'has_wastes' => $hasWastes,
            'producer_found' => $producerFound,
            'wastes_found' => $wastesFound,
        ]);

        $matches = $hasProducer && $hasWastes;

        if (! $matches) {
            $missing = [];
            if (! $hasProducer && ! empty($producerName)) {
                $missing[] = "Producer Name: Expected '{$producerName}', Found '{$producerFound}'";
            }
            if (! $hasWastes && ! empty($wastesLocation)) {
                $missing[] = "Wastes Location: Expected '{$wastesLocation}', Found '{$wastesFound}'";
            }
            $fileInfo['missing'] = $missing;
        }

        Log::info($matches ? '✓ Match' : '✗ No match'." - {$file['name']}");

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

        $extractedValue = $this->extractValue($content, $fieldName);

        if ($extractedValue === 'Not Found') {
            return false;
        }

        $extractedLower = mb_strtolower(trim($extractedValue), 'UTF-8');
        $searchLower = mb_strtolower(trim($searchValue), 'UTF-8');

        return strpos($extractedLower, $searchLower) !== false;
    }

    private function extractValue(string $content, string $fieldName): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = preg_replace('/\r\n/', "\n", $content);

        if (stripos($content, $fieldName) !== false) {
            $pos = stripos($content, $fieldName);
            $snippet = substr($content, max(0, $pos - 50), 200);
            Log::info("Found '{$fieldName}' in content, snippet: ".json_encode($snippet));
        } else {
            Log::warning("Field '{$fieldName}' not found in content at all!");
        }

        if ($fieldName === 'Producer Name') {
            if (preg_match('/Producer\s+Name\s*:\s*\n\s*([^\n]+(?:\n[^\n:]+)*?)(?=\n\s*(?:Wastes Location|Trade License|Mobile|Email|Company Name|Part \d+|$))/is', $content, $matches)) {
                $value = trim($matches[1]);
                $value = preg_replace('/\s+/', ' ', $value);
                if (! empty($value)) {
                    Log::info("Extracted Producer Name: '{$value}'");
                    return $value;
                }
            }

            if (preg_match('/Producer\s+Name\s*:\s*([^\n]+)/i', $content, $matches)) {
                $value = trim($matches[1]);
                if (! empty($value)) {
                    Log::info("Extracted Producer Name (same line): '{$value}'");
                    return $value;
                }
            }
        }

        if ($fieldName === 'Wastes Location') {
            if (preg_match('/Wastes?\s+Location\s*:\s*\n?\s*([^\n]+)/is', $content, $matches)) {
                $value = trim($matches[1]);
                $value = preg_replace('/\s+/', ' ', $value);
                if (! empty($value)) {
                    Log::info("Extracted Wastes Location: '{$value}'");
                    return $value;
                }
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

        $patterns = [
            '/'.preg_quote($fieldName, '/').'\s*:\s*([^\n]+)/i',
            '/'.preg_quote($fieldName, '/').'\s*:\s*\n\s*([^\n]+)/i',
            '/'.preg_quote($fieldName, '/').'\s*:\s*\n?(.*?)(?=\n(?:[A-Z][a-zA-Z\s]+\s*:|Part \d+|Manifest|Trade License|Mobile No\.|Email|Company Name|Driver Name|License Plate|Phone No\.|Facility|City|Street Name|Waste Description|Collection Point)|$)/is',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $value = trim($matches[1]);
                $value = preg_replace('/\s+/', ' ', $value);
                $value = trim($value);

                if (! empty($value)) {
                    Log::info("Extracted '{$fieldName}' using pattern: '{$value}'");
                    return $value;
                }
            }
        }

        Log::warning("Could not extract '{$fieldName}' from content");
        return 'Not Found';
    }

    private function extractPdfData(string $pdfContent): ?array
    {
        try {
            $tempPdfPath = tempnam(sys_get_temp_dir(), 'pdf_').'.pdf';
            file_put_contents($tempPdfPath, $pdfContent);

            $parser = new PdfParser;
            $pdf = $parser->parseFile($tempPdfPath);
            $text = $pdf->getText();
            unlink($tempPdfPath);

            Log::info('PDF text length: '.strlen($text));
            Log::info('PDF text (first 500 chars): '.substr($text, 0, 500));

            if (strlen($text) < 50) {
                Log::error('PDF appears to be empty or an image! Text length: '.strlen($text));
                Log::error('Full text: '.$text);
                return null;
            }

            $data = [
                'manifest_number' => $this->extractValue($text, 'Manifest Number'),
                'manifest_date' => $this->extractValue($text, 'Manifest Date'),
                'wastes_location' => $this->extractValue($text, 'Wastes Location'),
                'waste_description' => $this->extractWasteFromPart3($text),
                'quantity' => $this->extractQuantityFromPart3($text),
            ];

            $wasteDesc = strtolower($data['waste_description']);
            $data['recycled_plastic'] = (strpos($wasteDesc, 'plastic') !== false) ? $data['quantity'] : 0;
            $data['recycled_paper'] = (strpos($wasteDesc, 'paper') !== false || strpos($wasteDesc, 'cb') !== false) ? $data['quantity'] : 0;
            $data['recycled_wood'] = (strpos($wasteDesc, 'wood') !== false) ? $data['quantity'] : 0;
            $data['recycled_steel'] = (strpos($wasteDesc, 'steel') !== false || strpos($wasteDesc, 'metal') !== false) ? $data['quantity'] : 0;

            Log::info('PDF Data extracted', $data);

            return $data;
        } catch (\Exception $e) {
            Log::error('PDF Extraction Error: '.$e->getMessage());
            return null;
        }
    }
}