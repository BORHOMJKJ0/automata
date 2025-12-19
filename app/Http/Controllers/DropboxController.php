<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class DropboxController extends Controller
{
    private $clientId;

    private $clientSecret;

    private $redirectUri;

    public function __construct()
    {
        $this->clientId = env('DROPBOX_CLIENT_ID');
        $this->clientSecret = env('DROPBOX_CLIENT_SECRET');
        $this->redirectUri = env('DROPBOX_REDIRECT_URI', url('/dropbox/callback'));
        $this->middleware('web');
    }

    /**
     * الصفحة الرئيسية
     */
    public function index()
    {
        return view('dropbox.index');
    }

    /**
     * توجيه المستخدم لتسجيل الدخول بـ Dropbox
     */
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

    /**
     * معالجة رد Dropbox بعد التصريح
     */
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
            return redirect()->route('dropbox.index')
                ->with('error', 'خطأ: '.$e->getMessage());
        }
    }

    /**
     * تصفح رابط Dropbox مشارك
     */
    public function browseSharedLink(Request $request)
    {
        $accessToken = Session::get('dropbox_access_token');

        if (! $accessToken) {
            return redirect()->route('dropbox.index')
                ->with('error', 'يجب تسجيل الدخول أولاً');
        }

        $sharedUrl = $request->input('shared_url');

        if (! $sharedUrl) {
            return redirect()->route('dropbox.index')
                ->with('error', 'الرجاء إدخال رابط مشارك');
        }

        $request->validate([
            'shared_url' => 'required|url',
        ]);

        $sharedUrl = str_replace('dl=0', 'dl=1', $sharedUrl);
        Session::put('current_shared_url', $sharedUrl);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->post('https://api.dropboxapi.com/2/sharing/get_shared_link_metadata', [
                'url' => $sharedUrl,
            ]);

            if ($response->failed()) {
                $errorData = $response->json();
                $errorMsg = $errorData['error_summary'] ?? 'فشل قراءة الرابط المشارك';
                Log::error('Shared Link Metadata Error: '.$errorMsg);

                return redirect()->route('dropbox.index')
                    ->with('error', 'خطأ: '.$errorMsg);
            }

            $metadata = $response->json();

            if ($metadata['.tag'] !== 'folder') {
                return redirect()->route('dropbox.index')
                    ->with('error', 'الرابط يجب أن يكون لمجلد وليس لملف');
            }

            $listResponse = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->post('https://api.dropboxapi.com/2/files/list_folder', [
                'path' => '',
                'shared_link' => [
                    'url' => $sharedUrl,
                ],
            ]);

            if ($listResponse->failed()) {
                $errorData = $listResponse->json();
                $errorMsg = $errorData['error_summary'] ?? 'فشل قراءة محتوى المجلد';
                Log::error('List Folder Error: '.$errorMsg);

                return redirect()->route('dropbox.index')
                    ->with('error', 'خطأ: '.$errorMsg);
            }

            $data = $listResponse->json();

            if (! isset($data['entries']) || ! is_array($data['entries'])) {
                Log::error('Invalid response format: '.json_encode($data));

                return redirect()->route('dropbox.index')
                    ->with('error', 'تنسيق استجابة غير صالح من Dropbox');
            }

            $items = $this->organizeItems($data['entries']);

            return view('dropbox.browse', [
                'items' => $items,
                'currentPath' => '',
                'sharedUrl' => $sharedUrl,
            ]);

        } catch (\Exception $e) {
            Log::error('Exception in browseSharedLink: '.$e->getMessage());

            return redirect()->route('dropbox.index')
                ->with('error', 'خطأ: '.$e->getMessage());
        }
    }

    /**
     * تصفح مجلد فرعي من رابط مشارك
     */
    public function browseSharedSubfolder(Request $request)
    {
        $accessToken = Session::get('dropbox_access_token');

        if (! $accessToken) {
            return redirect()->route('dropbox.index')
                ->with('error', 'يجب تسجيل الدخول أولاً');
        }

        $sharedUrl = $request->query('shared_url') ??
                     $request->input('shared_url') ??
                     Session::get('current_shared_url');

        $path = $request->query('path') ?? $request->input('path', '');

        if (! $sharedUrl) {
            return redirect()->route('dropbox.index')
                ->with('error', 'رابط مشارك مطلوب');
        }

        $sharedUrl = str_replace('dl=0', 'dl=1', $sharedUrl);
        Session::put('current_shared_url', $sharedUrl);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->post('https://api.dropboxapi.com/2/files/list_folder', [
                'path' => $path,
                'shared_link' => [
                    'url' => $sharedUrl,
                ],
            ]);

            if ($response->failed()) {
                $errorData = $response->json();
                $errorMsg = $errorData['error_summary'] ?? 'فشل قراءة المجلد';
                Log::error('Browse Subfolder Error: '.$errorMsg);

                return back()->with('error', 'لا يمكن فتح هذا المجلد');
            }

            $data = $response->json();

            if (! isset($data['entries']) || ! is_array($data['entries'])) {
                Log::error('Invalid subfolder response: '.json_encode($data));

                return back()->with('error', 'تنسيق استجابة غير صالح من Dropbox');
            }

            $items = $this->organizeItems($data['entries']);

            return view('dropbox.browse', [
                'items' => $items,
                'currentPath' => $path,
                'sharedUrl' => $sharedUrl,
            ]);

        } catch (\Exception $e) {
            Log::error('Exception in browseSharedSubfolder: '.$e->getMessage());

            return back()->with('error', 'خطأ: '.$e->getMessage());
        }
    }

    /**
     * تحميل ملف من رابط مشارك
     */
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
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Dropbox-API-Arg' => json_encode([
                    'path' => $path,
                    'shared_link' => [
                        'url' => $sharedUrl,
                    ],
                ]),
            ])->get('https://content.dropboxapi.com/2/files/download');

            if ($response->failed()) {
                return back()->with('error', 'فشل تحميل الملف');
            }

            $filename = basename($path);

            return response($response->body())
                ->header('Content-Type', $response->header('Content-Type'))
                ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');

        } catch (\Exception $e) {
            return back()->with('error', 'خطأ: '.$e->getMessage());
        }
    }

    /**
     * معاينة ملف نصي من رابط مشارك
     */
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
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Dropbox-API-Arg' => json_encode([
                    'path' => $path,
                    'shared_link' => [
                        'url' => $sharedUrl,
                    ],
                ]),
            ])->get('https://content.dropboxapi.com/2/files/download');

            if ($response->failed()) {
                return back()->with('error', 'فشل قراءة الملف');
            }

            $content = $response->body();
            $filename = basename($path);
            $extension = pathinfo($filename, PATHINFO_EXTENSION);

            if (strlen($content) > 1048576) {
                return back()->with('error', 'الملف كبير جداً للمعاينة (أكثر من 1MB)');
            }

            return view('dropbox.preview', [
                'path' => $path,
                'filename' => $filename,
                'content' => $content,
                'extension' => strtolower($extension),
                'sharedUrl' => $sharedUrl,
            ]);

        } catch (\Exception $e) {
            return back()->with('error', 'خطأ: '.$e->getMessage());
        }
    }

    /**
     * البحث والمطابقة في الملفات
     */
    public function searchAndMatch(Request $request)
    {
        $accessToken = Session::get('dropbox_access_token');

        if (! $accessToken) {
            return back()->with('error', 'يجب تسجيل الدخول أولاً');
        }

        // إذا كان GET request، نعرض الصفحة فقط
        if ($request->isMethod('get')) {
            return view('dropbox.search-results', [
                'producerName' => '',
                'wastesLocation' => '',
                'matchingFiles' => [],
                'nonMatchingFiles' => [],
                'totalFiles' => 0,
                'sharedUrl' => $request->query('shared_url') ?? Session::get('current_shared_url'),
                'currentPath' => $request->query('current_path', ''),
            ]);
        }

        $producerName = trim($request->input('producer_name', ''));
        $wastesLocation = trim($request->input('wastes_location', ''));
        $sharedUrl = $request->input('shared_url') ?? Session::get('current_shared_url');
        $currentPath = $request->input('current_path', '');

        if (empty($producerName) && empty($wastesLocation)) {
            return back()->with('error', 'الرجاء إدخال قيمة واحدة على الأقل للبحث');
        }

        if (! $sharedUrl) {
            return back()->with('error', 'رابط مشارك مطلوب');
        }

        try {
            // جلب جميع الملفات بشكل تكراري
            $allEntries = $this->getAllFilesRecursive($accessToken, $sharedUrl, $currentPath);

            Log::info('Total entries found: '.count($allEntries));

            // تصفية الملفات النصية فقط
            $textFiles = array_filter($allEntries, function ($entry) {
                if ($entry['.tag'] !== 'file') {
                    return false;
                }
                if (! isset($entry['name'])) {
                    return false;
                }
                $extension = pathinfo($entry['name'], PATHINFO_EXTENSION);

                return $this->isPreviewable($extension);
            });

            Log::info('Text files found: '.count($textFiles));

            $matchingFiles = [];
            $nonMatchingFiles = [];
            $processedCount = 0;

            foreach ($textFiles as $file) {
                $filePath = $file['path_display'] ?? ($file['path_lower'] ?? null);
                if (! $filePath) {
                    Log::warning('File without path: '.json_encode($file));

                    continue;
                }

                $processedCount++;
                Log::info("Processing file {$processedCount}: {$filePath}");

                $fileContent = $this->downloadFileContent($accessToken, $sharedUrl, $filePath);

                if ($fileContent === null) {
                    Log::warning("Could not read file: {$filePath}");

                    continue;
                }

                // استخدام matchesField بدلاً من البحث المباشر
                $hasProducer = empty($producerName) || $this->matchesField($fileContent, 'Producer Name', $producerName);
                $hasWastes = empty($wastesLocation) || $this->matchesField($fileContent, 'Wastes Location', $wastesLocation);

                $fileName = $file['name'] ?? basename($filePath);

                $fileInfo = [
                    'name' => $fileName,
                    'path' => $filePath,
                    'size' => $file['size'] ?? 0,
                    'has_producer' => $hasProducer,
                    'has_wastes' => $hasWastes,
                    'producer_found' => $this->extractValue($fileContent, 'Producer Name'),
                    'wastes_found' => $this->extractValue($fileContent, 'Wastes Location'),
                ];

                if ($hasProducer && $hasWastes) {
                    $matchingFiles[] = $fileInfo;
                    Log::info("✓ Matching file: {$fileName}");
                } else {
                    $fileInfo['missing'] = [];
                    if (! $hasProducer && ! empty($producerName)) {
                        $fileInfo['missing'][] = 'Producer Name: '.$producerName;
                    }
                    if (! $hasWastes && ! empty($wastesLocation)) {
                        $fileInfo['missing'][] = 'Wastes Location: '.$wastesLocation;
                    }
                    $nonMatchingFiles[] = $fileInfo;
                    Log::info("✗ Non-matching file: {$fileName}");
                }
            }

            Log::info('Search completed. Matching: '.count($matchingFiles).', Non-matching: '.count($nonMatchingFiles));

            return view('dropbox.search-results', [
                'producerName' => $producerName,
                'wastesLocation' => $wastesLocation,
                'matchingFiles' => $matchingFiles,
                'nonMatchingFiles' => $nonMatchingFiles,
                'totalFiles' => count($textFiles),
                'sharedUrl' => $sharedUrl,
                'currentPath' => $currentPath,
            ]);

        } catch (\Exception $e) {
            Log::error('Search Error: '.$e->getMessage());
            Log::error('Stack trace: '.$e->getTraceAsString());

            return back()->with('error', 'خطأ: '.$e->getMessage());
        }
    }

    /**
     * جلب جميع الملفات بشكل تكراري (حل مشكلة التكرار)
     */
    private function getAllFilesRecursive($accessToken, $sharedUrl, $path = '', $cursor = null)
    {
        $allEntries = [];

        try {
            if ($cursor) {
                // Continue with cursor
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$accessToken,
                    'Content-Type' => 'application/json',
                ])->post('https://api.dropboxapi.com/2/files/list_folder/continue', [
                    'cursor' => $cursor,
                ]);
            } else {
                // Initial request
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$accessToken,
                    'Content-Type' => 'application/json',
                ])->post('https://api.dropboxapi.com/2/files/list_folder', [
                    'path' => $path,
                    'shared_link' => ['url' => $sharedUrl],
                    'recursive' => true,
                    'limit' => 2000,
                ]);
            }

            if ($response->failed()) {
                Log::error('Failed to list folder: '.$response->body());

                return $allEntries;
            }

            $data = $response->json();

            if (isset($data['entries']) && is_array($data['entries'])) {
                $allEntries = array_merge($allEntries, $data['entries']);
            }

            // If there are more entries, continue fetching
            if (isset($data['has_more']) && $data['has_more'] && isset($data['cursor'])) {
                $moreEntries = $this->getAllFilesRecursive($accessToken, $sharedUrl, $path, $data['cursor']);
                $allEntries = array_merge($allEntries, $moreEntries);
            }

        } catch (\Exception $e) {
            Log::error('Exception in getAllFilesRecursive: '.$e->getMessage());
        }

        return $allEntries;
    }

    /**
     * تحميل محتوى الملف
     */
    private function downloadFileContent($accessToken, $sharedUrl, $path)
    {
        try {
            $response = Http::timeout(30)->withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Dropbox-API-Arg' => json_encode([
                    'path' => $path,
                    'shared_link' => ['url' => $sharedUrl],
                ]),
            ])->get('https://content.dropboxapi.com/2/files/download');

            if ($response->failed()) {
                Log::error("Failed to download file {$path}: ".$response->body());

                return null;
            }

            $content = $response->body();

            // تجاهل الملفات الكبيرة جداً (أكثر من 5MB)
            if (strlen($content) > 5242880) {
                Log::warning("File too large, skipping: {$path}");

                return null;
            }

            return $content;

        } catch (\Exception $e) {
            Log::error('Download Error for '.$path.': '.$e->getMessage());

            return null;
        }
    }

    /**
     * التحقق من مطابقة حقل معين (الدالة المفقودة - تم إصلاحها)
     */
    private function matchesField($content, $fieldName, $searchValue)
    {
        if (empty($searchValue)) {
            return true;
        }

        // استخراج القيمة من المحتوى
        $extractedValue = $this->extractValue($content, $fieldName);

        // تجاهل "Not Found"
        if ($extractedValue === 'Not Found') {
            return false;
        }

        // تحويل القيم لأحرف صغيرة للمقارنة
        $extractedLower = mb_strtolower(trim($extractedValue), 'UTF-8');
        $searchLower = mb_strtolower(trim($searchValue), 'UTF-8');

        // البحث الجزئي: إذا كانت القيمة المستخرجة تحتوي على القيمة المطلوبة
        $matches = strpos($extractedLower, $searchLower) !== false;

        if ($matches) {
            Log::info("✓ Field '{$fieldName}' matches: '{$extractedValue}' contains '{$searchValue}'");
        } else {
            Log::info("✗ Field '{$fieldName}' does not match: '{$extractedValue}' vs '{$searchValue}'");
        }

        return $matches;
    }

    /**
     * استخراج القيمة من المحتوى (محسّن)
     */
    private function extractValue($content, $fieldName)
    {
        // إزالة BOM إذا كان موجوداً
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // نمط محسّن يدعم القيم متعددة الأسطر
        $patterns = [
            // Pattern 1: Field followed by colon and value on same or next line
            '/'.preg_quote($fieldName, '/').'\s*:\s*\n?\s*([^\n]+(?:\n(?![A-Z][a-zA-Z\s]+\s*:|Part \d+|Manifest |Trade License|Mobile No\.|Email|Company Name|Driver Name|License Plate|Phone No\.|Facility\s*:|City\s*:|Street Name|Waste Description|Collection Point)[^\n]+)*)/is',

            // Pattern 2: More flexible - captures until next field or end
            '/'.preg_quote($fieldName, '/').'\s*:\s*\n?\s*(.+?)(?=\n\s*[A-Z][a-zA-Z\s]+\s*:|$)/is',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $value = trim($matches[1]);
                // تنظيف القيمة: إزالة الأسطر الفارغة والمسافات الزائدة
                $value = preg_replace('/\s+/', ' ', $value);
                $value = trim($value);

                if (! empty($value)) {
                    Log::info("Extracted '{$fieldName}': {$value}");

                    return $value;
                }
            }
        }

        Log::info("Field '{$fieldName}' not found in content");

        return 'Not Found';
    }

    /**
     * تسجيل الخروج
     */
    public function logout()
    {
        Session::forget('dropbox_access_token');
        Session::forget('dropbox_account_id');
        Session::forget('current_shared_url');

        return redirect()->route('dropbox.index')
            ->with('success', 'تم تسجيل الخروج بنجاح');
    }

    /**
     * تنظيم الملفات والمجلدات
     */
    private function organizeItems($entries)
    {
        $items = ['folders' => [], 'files' => []];

        foreach ($entries as $entry) {
            $path = $entry['path_display'] ?? ($entry['path_lower'] ?? null);
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

    private function isEditable($extension)
    {
        $editable = ['txt', 'md', 'json', 'xml', 'html', 'css', 'js', 'php', 'py', 'java'];

        return in_array(strtolower($extension), $editable);
    }

    private function isPreviewable($extension)
    {
        $previewable = [
            'txt', 'md', 'json', 'xml', 'html', 'css', 'js',
            'php', 'py', 'java', 'c', 'cpp', 'h', 'yml', 'yaml',
            'ini', 'conf', 'log', 'sql', 'sh', 'bat',
        ];

        return in_array(strtolower($extension), $previewable);
    }
}
