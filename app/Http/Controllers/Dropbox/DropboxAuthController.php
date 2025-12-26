<?php

namespace App\Http\Controllers\Dropbox;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class DropboxAuthController extends Controller
{
    private string $clientId;

    private string $clientSecret;

    private string $redirectUri;

    public function __construct()
    {
        $this->clientId = env('DROPBOX_CLIENT_ID');
        $this->clientSecret = env('DROPBOX_CLIENT_SECRET');
        $this->redirectUri = env('DROPBOX_REDIRECT_URI', url('/dropbox/callback'));
        $this->middleware('web');
    }

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
}
