<?php

namespace App\Http\Controllers\Dropbox;

use App\Http\Controllers\Controller;
use App\Services\Dropbox\DropboxApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class DropboxBrowserController extends Controller
{
    private DropboxApiService $dropboxApi;

    public function __construct(DropboxApiService $dropboxApi)
    {
        $this->middleware('web');
        $this->dropboxApi = $dropboxApi;
    }

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
            $metadata = $this->dropboxApi->getSharedLinkMetadata($accessToken, $sharedUrl);

            if (! $metadata || $metadata['.tag'] !== 'folder') {
                return redirect()->route('dropbox.index')
                    ->with('error', 'الرابط يجب أن يكون لمجلد وليس لملف');
            }

            $items = $this->dropboxApi->listFolderContents($accessToken, $sharedUrl, '');

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
            $items = $this->dropboxApi->listFolderContents($accessToken, $sharedUrl, $path);

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
}
