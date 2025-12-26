<?php

namespace App\Http\Controllers\Dropbox;

use App\Http\Controllers\Controller;
use App\Services\Dropbox\DropboxApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class DropboxFileController extends Controller
{
    private DropboxApiService $dropboxApi;

    public function __construct(DropboxApiService $dropboxApi)
    {
        $this->middleware('web');
        $this->dropboxApi = $dropboxApi;
    }

    public function download(Request $request)
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

            $content = $this->dropboxApi->downloadFileContent($accessToken, $sharedUrl, $path);

            if (! $content) {
                return back()->with('error', 'فشل تحميل الملف');
            }

            $filename = basename($path);

            return response($content)
                ->header('Content-Type', 'application/octet-stream')
                ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
        } catch (\Exception $e) {
            Log::error('Download Error: '.$e->getMessage());

            return back()->with('error', 'خطأ: '.$e->getMessage());
        }
    }

    public function preview(Request $request)
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
            $content = $this->dropboxApi->downloadFileContent($accessToken, $sharedUrl, $path);

            if (! $content) {
                return back()->with('error', 'فشل قراءة الملف');
            }

            if (strlen($content) > 1048576) {
                return back()->with('error', 'الملف كبير جداً للمعاينة');
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
}
