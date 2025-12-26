<?php

namespace App\Http\Controllers\Dropbox;

use App\Http\Controllers\Controller;
use App\Services\BitrixNotificationService;
use App\Services\Dropbox\DropboxApiService;
use App\Services\Excel\ExcelProcessorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class DropboxExcelController extends Controller
{
    private DropboxApiService $dropboxApi;

    private ExcelProcessorService $excelProcessor;

    private BitrixNotificationService $bitrixService;

    public function __construct(
        DropboxApiService $dropboxApi,
        ExcelProcessorService $excelProcessor,
        BitrixNotificationService $bitrixService
    ) {
        $this->middleware('web');
        $this->dropboxApi = $dropboxApi;
        $this->excelProcessor = $excelProcessor;
        $this->bitrixService = $bitrixService;
    }

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

            $result = $this->excelProcessor->processExcelWithPdfs(
                $accessToken,
                $sharedUrl,
                $excelPath,
                $matchingFiles
            );

            if ($result['success']) {
                $this->bitrixService->notifyExcelProcessed(
                    count($matchingFiles),
                    $result['updatedCount'],
                    $excelPath
                );

                return view('dropbox.process-results', [
                    'success' => true,
                    'updatedCount' => $result['updatedCount'],
                    'processedFiles' => $result['processedFiles'],
                    'newFilePath' => $excelPath,
                    'sharedUrl' => $sharedUrl,
                ]);
            }

            return back()->with('error', 'فشل معالجة الملف');
        } catch (\Exception $e) {
            Log::error('Process Excel Error: '.$e->getMessage());

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
}
