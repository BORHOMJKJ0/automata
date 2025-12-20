<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;

class TestDropboxController extends Controller
{
    public function testConnection(Request $request)
    {
        $accessToken = Session::get('dropbox_access_token');
        $sharedUrl = $request->input('shared_url') ?? Session::get('current_shared_url');

        if (! $accessToken) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        if (! $sharedUrl) {
            return response()->json(['error' => 'No shared URL'], 400);
        }

        $results = [];

        // Test 1: Get metadata
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->post('https://api.dropboxapi.com/2/sharing/get_shared_link_metadata', [
                'url' => $sharedUrl,
            ]);

            $results['metadata'] = [
                'success' => $response->successful(),
                'status' => $response->status(),
                'data' => $response->json(),
            ];
        } catch (\Exception $e) {
            $results['metadata'] = [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        // Test 2: List folder (non-recursive)
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->post('https://api.dropboxapi.com/2/files/list_folder', [
                'path' => '',
                'shared_link' => ['url' => $sharedUrl],
                'recursive' => false,
            ]);

            $data = $response->json();
            $results['list_folder'] = [
                'success' => $response->successful(),
                'status' => $response->status(),
                'entries_count' => count($data['entries'] ?? []),
                'entries' => array_map(function ($entry) {
                    return [
                        'name' => $entry['name'] ?? 'unknown',
                        'type' => $entry['.tag'] ?? 'unknown',
                        'path' => $entry['path_display'] ?? 'unknown',
                    ];
                }, array_slice($data['entries'] ?? [], 0, 5)),
            ];
        } catch (\Exception $e) {
            $results['list_folder'] = [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        // Test 3: Try to download first file
        if (! empty($results['list_folder']['entries'])) {
            $firstFile = null;
            foreach ($results['list_folder']['entries'] as $entry) {
                if ($entry['type'] === 'file') {
                    $firstFile = $entry;
                    break;
                }
            }

            if ($firstFile) {
                try {
                    $response = Http::timeout(10)->withHeaders([
                        'Authorization' => 'Bearer '.$accessToken,
                        'Dropbox-API-Arg' => json_encode([
                            'path' => $firstFile['path'],
                            'shared_link' => ['url' => $sharedUrl],
                        ]),
                    ])->get('https://content.dropboxapi.com/2/files/download');

                    $results['download_test'] = [
                        'success' => $response->successful(),
                        'status' => $response->status(),
                        'file' => $firstFile['name'],
                        'size' => $response->successful() ? strlen($response->body()) : 0,
                    ];
                } catch (\Exception $e) {
                    $results['download_test'] = [
                        'success' => false,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return response()->json($results, 200, [], JSON_PRETTY_PRINT);
    }
}
