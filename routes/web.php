<?php

use App\Http\Controllers\DropboxController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return redirect()->route('dropbox.index');
});
Route::get('/dropbox/test-connection', [TestDropboxController::class, 'testConnection'])
    ->name('dropbox.test.connection');

// ==================== AUTHENTICATION ====================
Route::get('/dropbox', [DropboxController::class, 'index'])->name('dropbox.index');
Route::get('/dropbox/connect', [DropboxController::class, 'connect'])->name('dropbox.connect');
Route::get('/dropbox/callback', [DropboxController::class, 'callback'])->name('dropbox.callback');
Route::get('/dropbox/logout', [DropboxController::class, 'logout'])->name('dropbox.logout');

// ==================== FILE BROWSING ====================
Route::match(['get', 'post'], '/dropbox/browse-shared', [DropboxController::class, 'browseSharedLink'])
    ->name('dropbox.browse.shared');
Route::get('/dropbox/browse-shared-folder', [DropboxController::class, 'browseSharedSubfolder'])
    ->name('dropbox.browse.shared.folder');

// ==================== FILE OPERATIONS ====================
Route::post('/dropbox/shared/download', [DropboxController::class, 'downloadSharedFile'])
    ->name('dropbox.shared.download');
Route::get('/dropbox/shared/preview', [DropboxController::class, 'previewFile'])
    ->name('dropbox.shared.preview');

// ==================== SEARCH & MATCH ====================
Route::match(['get', 'post'], '/dropbox/search-match', [DropboxController::class, 'searchAndMatch'])
    ->name('dropbox.search.match');

// ==================== EXCEL PROCESSING ====================
Route::post('/dropbox/process-excel', [DropboxController::class, 'processExcelUpdate'])
    ->name('dropbox.process.excel');
Route::get('/dropbox/process-results', [DropboxController::class, 'showProcessResults'])
    ->name('dropbox.process.results');
