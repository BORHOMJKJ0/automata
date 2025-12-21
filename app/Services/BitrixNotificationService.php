<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BitrixNotificationService
{
    private string $webhookUrl;

    public function __construct()
    {
        $this->webhookUrl = env('BITRIX_WEBHOOK_URL');
    }

    public function notifyFileUploaded(string $fileName, string $filePath, array $details = []): bool
    {
        $message = "📤 *تم رفع ملف جديد*\n\n";
        $message .= "📄 الملف: {$fileName}\n";
        $message .= "📁 المسار: {$filePath}\n";

        if (! empty($details)) {
            if (isset($details['manifest_number'])) {
                $message .= "🔢 Manifest Number: {$details['manifest_number']}\n";
            }
            if (isset($details['manifest_date'])) {
                $message .= "📅 التاريخ: {$details['manifest_date']}\n";
            }
            if (isset($details['quantity'])) {
                $message .= "⚖️ الكمية: {$details['quantity']} Kg\n";
            }
        }

        return $this->sendToBitrix('file_uploaded', $message, $details);
    }

    public function notifyExcelProcessed(int $filesProcessed, int $filesUpdated, string $excelFile): bool
    {
        $message = "✅ *تمت معالجة ملف Excel*\n\n";
        $message .= '📊 ملف Excel: '.basename($excelFile)."\n";
        $message .= "📝 ملفات تمت معالجتها: {$filesProcessed}\n";
        $message .= "✔️ صفوف محدثة: {$filesUpdated}\n";

        return $this->sendToBitrix('excel_processed', $message, [
            'excel_file' => basename($excelFile),
            'files_processed' => $filesProcessed,
            'files_updated' => $filesUpdated,
        ]);
    }

    public function notifySearchResults(int $matchingFiles, int $totalFiles, string $searchCriteria): bool
    {
        $message = "🔍 *نتائج البحث*\n\n";
        $message .= "✅ ملفات متطابقة: {$matchingFiles}\n";
        $message .= "📊 إجمالي الملفات: {$totalFiles}\n";
        $message .= "🔎 معايير البحث: {$searchCriteria}\n";

        return $this->sendToBitrix('search_completed', $message, [
            'matching_files' => $matchingFiles,
            'total_files' => $totalFiles,
            'search_criteria' => $searchCriteria,
        ]);
    }

    public function notifyError(string $operation, string $errorMessage): bool
    {
        $message = "⚠️ *حدث خطأ*\n\n";
        $message .= "العملية: {$operation}\n";
        $message .= "الخطأ: {$errorMessage}\n";

        return $this->sendToBitrix('error', $message, [
            'operation' => $operation,
            'error' => $errorMessage,
        ]);
    }

    private function sendToBitrix(string $eventType, string $message, array $data = []): bool
    {
        if (empty($this->webhookUrl)) {
            Log::warning('Bitrix webhook URL not configured');

            return false;
        }

        try {
            $to = env('BITRIX_NOTIFY_CHAT_ID')
    ? 'chat'.env('BITRIX_NOTIFY_CHAT_ID')
    : (int) env('BITRIX_NOTIFY_USER_ID');
            if (! env('BITRIX_NOTIFY_CHAT_ID') && ! env('BITRIX_NOTIFY_USER_ID')) {
                Log::warning('No Bitrix notification target configured');

                return false;
            }
            $response = Http::timeout(10)->post($this->webhookUrl.'/im.notify', [
                'to' => $to,
                'message' => $message,
                'type' => 'SYSTEM',
            ]);

            // أو طريقة 2: إنشاء Task في Bitrix24
            // $response = Http::timeout(10)->post($this->webhookUrl . '/tasks.task.add', [
            //     'fields' => [
            //         'TITLE' => "تحديث Dropbox - {$eventType}",
            //         'DESCRIPTION' => $message,
            //         'RESPONSIBLE_ID' => 1,
            //         'CREATED_BY' => 1,
            //     ]
            // ]);

            // أو طريقة 3: إرسال إلى CRM Activity
            // $response = Http::timeout(10)->post($this->webhookUrl . '/crm.activity.add', [
            //     'fields' => [
            //         'OWNER_TYPE_ID' => 3, // Contact
            //         'OWNER_ID' => 1,
            //         'TYPE_ID' => 4, // Call
            //         'SUBJECT' => "Dropbox Update: {$eventType}",
            //         'DESCRIPTION' => $message,
            //     ]
            // ]);

            if ($response->successful()) {
                $result = $response->json();
                Log::info('Bitrix notification sent successfully', [
                    'event' => $eventType,
                    'response' => $result,
                ]);

                return true;
            }

            Log::error('Bitrix notification failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;

        } catch (\Exception $e) {
            Log::error('Bitrix notification error: '.$e->getMessage());

            return false;
        }
    }

    public function sendCustomWebhook(string $method, array $params = []): bool
    {
        if (empty($this->webhookUrl)) {
            return false;
        }

        try {
            $response = Http::timeout(10)->post($this->webhookUrl.'/'.$method, $params);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Custom webhook error: '.$e->getMessage());

            return false;
        }
    }
}
