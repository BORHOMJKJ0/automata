<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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

        // السماح بالوصول للصفحات العامة بدون middleware
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
            // تبادل الكود بـ Access Token
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

            // حفظ Access Token في الجلسة
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

        // تنظيف الرابط
        $sharedUrl = str_replace('dl=0', 'dl=1', $sharedUrl);

        // حفظ الرابط في الجلسة
        Session::put('current_shared_url', $sharedUrl);

        try {
            // استخدام get_shared_link_metadata للحصول على معلومات الرابط
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->post('https://api.dropboxapi.com/2/sharing/get_shared_link_metadata', [
                'url' => $sharedUrl,
            ]);

            if ($response->failed()) {
                $errorData = $response->json();
                $errorMsg = $errorData['error_summary'] ?? 'فشل قراءة الرابط المشارك';

                \Log::error('Shared Link Metadata Error: '.$errorMsg);

                return redirect()->route('dropbox.index')
                    ->with('error', 'خطأ: '.$errorMsg);
            }

            $metadata = $response->json();

            // التحقق من نوع الرابط
            if ($metadata['.tag'] !== 'folder') {
                return redirect()->route('dropbox.index')
                    ->with('error', 'الرابط يجب أن يكون لمجلد وليس لملف');
            }

            // الآن نستخدم list_folder مع الرابط المشارك
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

                \Log::error('List Folder Error: '.$errorMsg);

                return redirect()->route('dropbox.index')
                    ->with('error', 'خطأ: '.$errorMsg);
            }

            $data = $listResponse->json();
            $items = $this->organizeItems($data['entries']);

            return view('dropbox.browse', [
                'items' => $items,
                'currentPath' => '',
                'sharedUrl' => $sharedUrl,
            ]);

        } catch (\Exception $e) {
            \Log::error('Exception in browseSharedLink: '.$e->getMessage());

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

        \Log::info('Browse Subfolder - Shared URL: '.$sharedUrl);
        \Log::info('Browse Subfolder - Path: '.$path);

        if (! $sharedUrl) {
            return redirect()->route('dropbox.index')
                ->with('error', 'رابط مشارك مطلوب');
        }

        $sharedUrl = str_replace('dl=0', 'dl=1', $sharedUrl);
        Session::put('current_shared_url', $sharedUrl);

        try {
            // نستخدم list_folder مع المسار النسبي من الرابط المشارك
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->post('https://api.dropboxapi.com/2/files/list_folder', [
                'path' => $path, // المسار الكامل من الـ entry
                'shared_link' => [
                    'url' => $sharedUrl,
                ],
            ]);

            if ($response->failed()) {
                $errorData = $response->json();
                $errorMsg = $errorData['error_summary'] ?? 'فشل قراءة المجلد';

                \Log::error('Browse Subfolder Error: '.$errorMsg);
                \Log::error('Full Response: '.$response->body());

                return back()->with('error', 'لا يمكن فتح هذا المجلد. قد يكون محمياً أو الرابط لا يسمح بالوصول للمجلدات الفرعية.');
            }

            $data = $response->json();
            $items = $this->organizeItems($data['entries']);

            return view('dropbox.browse', [
                'items' => $items,
                'currentPath' => $path,
                'sharedUrl' => $sharedUrl,
            ]);

        } catch (\Exception $e) {
            \Log::error('Exception in browseSharedSubfolder: '.$e->getMessage());

            return back()->with('error', 'خطأ: '.$e->getMessage());
        }
    }

    /**
     * طريقة بديلة لتصفح المجلدات المشاركة
     * (لم تعد مستخدمة - محفوظة للرجوع إليها)
     */
    private function browseSharedSubfolderAlternative($accessToken, $sharedUrl, $path)
    {
        // هذه الدالة لم تعد تُستخدم
        return back()->with('error', 'الطريقة البديلة غير مدعومة حالياً');
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
                return back()->with('error', 'فشل قراءة الملف');
            }

            $content = $response->body();
            $filename = basename($path);
            $extension = pathinfo($filename, PATHINFO_EXTENSION);

            // التحقق من حجم الملف (حد أقصى 1MB للمعاينة)
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
     * تسجيل الخروج
     */
    public function logout()
    {
        Session::forget('dropbox_access_token');
        Session::forget('dropbox_account_id');

        return redirect()->route('dropbox.index')
            ->with('success', 'تم تسجيل الخروج بنجاح');
    }

    /**
     * تنظيم الملفات والمجلدات
     */
    private function organizeItems($entries)
    {
        $items = [
            'folders' => [],
            'files' => [],
        ];

        foreach ($entries as $entry) {
            if ($entry['.tag'] === 'folder') {
                $items['folders'][] = [
                    'name' => $entry['name'],
                    'path' => $entry['path_display'] ?? $entry['path_lower'],
                ];
            } else {
                $extension = pathinfo($entry['name'], PATHINFO_EXTENSION);

                $items['files'][] = [
                    'name' => $entry['name'],
                    'path' => $entry['path_display'] ?? $entry['path_lower'],
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

    /**
     * تحقق إذا الملف قابل للتعديل
     */
    private function isEditable($extension)
    {
        $editable = ['txt', 'md', 'json', 'xml', 'html', 'css', 'js', 'php', 'py', 'java'];

        return in_array(strtolower($extension), $editable);
    }

    /**
     * التحقق إذا الملف قابل للمعاينة
     */
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
