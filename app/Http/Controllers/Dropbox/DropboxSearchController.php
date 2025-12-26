<?php

namespace App\Http\Controllers\Dropbox;

use App\Http\Controllers\Controller;
use App\Services\BitrixNotificationService;
use App\Services\Dropbox\DropboxApiService;
use App\Services\Dropbox\FileSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class DropboxSearchController extends Controller
{
    private DropboxApiService $dropboxApi;

    private FileSearchService $searchService;

    private BitrixNotificationService $bitrixService;

    public function __construct(
        DropboxApiService $dropboxApi,
        FileSearchService $searchService,
        BitrixNotificationService $bitrixService
    ) {
        $this->middleware('web');
        $this->dropboxApi = $dropboxApi;
        $this->searchService = $searchService;
        $this->bitrixService = $bitrixService;
    }

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

            $allEntries = $this->dropboxApi->getAllFilesRecursive($accessToken, $sharedUrl, $currentPath);

            $files = $this->searchService->categorizeFiles($allEntries);

            $results = $this->searchService->searchFiles(
                $accessToken,
                $sharedUrl,
                $files,
                $producerName,
                $wastesLocation
            );

            if (count($results['matching']) > 0) {
                $this->bitrixService->notifySearchResults(
                    count($results['matching']),
                    $results['total'],
                    "Producer: {$producerName}, Location: {$wastesLocation}"
                );
            }

            return view('dropbox.search-results', [
                'producerName' => $producerName,
                'wastesLocation' => $wastesLocation,
                'matchingFiles' => $results['matching'],
                'nonMatchingFiles' => $results['nonMatching'],
                'totalFiles' => $results['total'],
                'allExcelFiles' => $files['excel'],
                'sharedUrl' => $sharedUrl,
                'currentPath' => $currentPath,
            ]);
        } catch (\Exception $e) {
            Log::error('Search Error: '.$e->getMessage());

            return back()->with('error', 'خطأ: '.$e->getMessage());
        }
    }
}
