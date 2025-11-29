<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class DropboxController extends Controller
{
    private $accessToken;

    public function __construct()
    {
        $this->accessToken = env('DROPBOX_ACCESS_TOKEN');
    }

    /**
     * الصفحة الرئيسية - إدخال الرابط
     */
    public function index()
    {
        return view('dropbox.index');
    }

    /**
     * معالجة رابط المشاركة وعرض المحتوى
     */
    public function browse(Request $request)
    {
        $request->validate([
            'dropbox_url' => 'required|url'
        ]);

        $sharedUrl = $this->normalizeUrl($request->dropbox_url);
        $path = $request->get('path', '');

        try {
            // استدعاء API لقراءة محتوى المجلد المشارك
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ])->post('https://api.dropboxapi.com/2/files/list_folder', [
                'path' => $path,
                'shared_link' => [
                    'url' => $sharedUrl
                ]
            ]);

            if ($response->failed()) {
                $error = $response->json();
                return back()->with('error', 'خطأ: ' . ($error['error_summary'] ?? 'فشل الوصول للرابط'));
            }

            $data = $response->json();
            $items = $this->organizeItems($data['entries']);

            return view('dropbox.browse', [
                'items' => $items,
                'sharedUrl' => $sharedUrl,
                'currentPath' => $path,
                'hasMore' => $data['has_more'] ?? false
            ]);

        } catch (\Exception $e) {
            return back()->with('error', 'خطأ: ' . $e->getMessage());
        }
    }

    /**
     * تصفح مجلد فرعي
     */
    public function browseFolder(Request $request)
    {
        $sharedUrl = $request->get('shared_url');
        $path = $request->get('path', '');

        if (!$sharedUrl) {
            return back()->with('error', 'رابط المشاركة مفقود');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ])->post('https://api.dropboxapi.com/2/files/list_folder', [
                'path' => $path,
                'shared_link' => [
                    'url' => $sharedUrl
                ]
            ]);

            if ($response->failed()) {
                return back()->with('error', 'فشل الوصول للمجلد');
            }

            $data = $response->json();
            $items = $this->organizeItems($data['entries']);

            return view('dropbox.browse', [
                'items' => $items,
                'sharedUrl' => $sharedUrl,
                'currentPath' => $path,
                'hasMore' => $data['has_more'] ?? false
            ]);

        } catch (\Exception $e) {
            return back()->with('error', 'خطأ: ' . $e->getMessage());
        }
    }

    /**
     * تحميل ملف
     */
    public function download(Request $request)
    {
        $sharedUrl = $request->get('shared_url');
        $path = $request->get('path');

        if (!$sharedUrl || !$path) {
            return back()->with('error', 'معلومات الملف ناقصة');
        }

        try {
            // استخدام API لتحميل الملف من الرابط المشارك
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Dropbox-API-Arg' => json_encode([
                    'path' => $path,
                    'shared_link' => [
                        'url' => $sharedUrl
                    ]
                ])
            ])->get('https://content.dropboxapi.com/2/files/download');

            if ($response->failed()) {
                return back()->with('error', 'فشل تحميل الملف');
            }

            // استخراج اسم الملف
            $filename = basename($path);

            // إرجاع الملف للتحميل
            return response($response->body())
                ->header('Content-Type', $response->header('Content-Type') ?? 'application/octet-stream')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');

        } catch (\Exception $e) {
            return back()->with('error', 'خطأ في التحميل: ' . $e->getMessage());
        }
    }

    /**
     * معاينة الملف (للصور والـ PDFs)
     */
    public function preview(Request $request)
    {
        $sharedUrl = $request->get('shared_url');
        $path = $request->get('path');

        if (!$sharedUrl || !$path) {
            return back()->with('error', 'معلومات الملف ناقصة');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Dropbox-API-Arg' => json_encode([
                    'path' => $path,
                    'shared_link' => [
                        'url' => $sharedUrl
                    ]
                ])
            ])->get('https://content.dropboxapi.com/2/files/download');

            if ($response->failed()) {
                return back()->with('error', 'فشل تحميل الملف');
            }

            $contentType = $response->header('Content-Type') ?? 'application/octet-stream';

            return response($response->body())
                ->header('Content-Type', $contentType);

        } catch (\Exception $e) {
            return back()->with('error', 'خطأ في المعاينة: ' . $e->getMessage());
        }
    }

    /**
     * تنظيم الملفات والمجلدات
     */
    private function organizeItems($entries)
    {
        $items = [
            'folders' => [],
            'files' => []
        ];

        foreach ($entries as $entry) {
            if ($entry['.tag'] === 'folder') {
                $items['folders'][] = [
                    'name' => $entry['name'],
                    'path' => $entry['path_display'] ?? $entry['path_lower'],
                    'id' => $entry['id'] ?? null
                ];
            } else {
                $extension = pathinfo($entry['name'], PATHINFO_EXTENSION);

                $items['files'][] = [
                    'name' => $entry['name'],
                    'path' => $entry['path_display'] ?? $entry['path_lower'],
                    'size' => $entry['size'] ?? 0,
                    'modified' => $entry['server_modified'] ?? $entry['client_modified'] ?? null,
                    'id' => $entry['id'] ?? null,
                    'extension' => strtolower($extension),
                    'is_previewable' => $this->isPreviewable($extension)
                ];
            }
        }

        return $items;
    }

    /**
     * تحقق إذا الملف قابل للمعاينة
     */
    private function isPreviewable($extension)
    {
        $previewable = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'md'];
        return in_array(strtolower($extension), $previewable);
    }

    /**
     * تطبيع رابط Dropbox
     */
    private function normalizeUrl($url)
    {
        // التأكد من أن dl=0 (للمعاينة) وليس dl=1 (تحميل مباشر)
        $url = str_replace('dl=1', 'dl=0', $url);

        // إذا لم يكن فيه dl parameter، أضفه
        if (strpos($url, 'dl=') === false) {
            $separator = strpos($url, '?') !== false ? '&' : '?';
            $url .= $separator . 'dl=0';
        }

        return $url;
    }
}