<?php

use App\Http\Controllers\DropboxController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});
Route::get('/', [DropboxController::class, 'index'])->name('dropbox.index');

// OAuth Authentication
Route::get('/connect', [DropboxController::class, 'connect'])->name('dropbox.connect');
Route::get('/dropbox/callback', [DropboxController::class, 'callback'])->name('dropbox.callback');
Route::get('/logout', [DropboxController::class, 'logout'])->name('dropbox.logout');

// Shared Links Routes
Route::match(['get', 'post'], '/browse-shared', [DropboxController::class, 'browseSharedLink'])->name('dropbox.browse.shared');
Route::get('/browse-shared-folder', [DropboxController::class, 'browseSharedSubfolder'])->name('dropbox.browse.shared.folder');
Route::post('/shared/download', [DropboxController::class, 'downloadSharedFile'])->name('dropbox.shared.download');
Route::get('/shared/preview', [DropboxController::class, 'previewFile'])->name('dropbox.shared.preview');
