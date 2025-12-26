<?php

use App\Http\Controllers\Dropbox\DropboxAuthController;
use App\Http\Controllers\Dropbox\DropboxBrowserController;
use App\Http\Controllers\Dropbox\DropboxExcelController;
use App\Http\Controllers\Dropbox\DropboxFileController;
use App\Http\Controllers\Dropbox\DropboxSearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return redirect()->route('dropbox.index');
});

// ==================== AUTHENTICATION ====================
Route::prefix('dropbox')->controller(DropboxAuthController::class)->name('dropbox.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/connect', 'connect')->name('connect');
    Route::get('/callback', 'callback')->name('callback');
    Route::get('/logout', 'logout')->name('logout');
});

// ==================== FILE BROWSING ====================
Route::prefix('dropbox/browse')->controller(DropboxBrowserController::class)->name('dropbox.browse.')->group(function () {
    Route::match(['get', 'post'], '/shared', 'browseSharedLink')
        ->name('shared');
    Route::get('/shared/folder', 'browseSharedSubfolder')
        ->name('shared.folder');
});

// ==================== FILE OPERATIONS ====================
Route::prefix('dropbox/file')->controller(DropboxFileController::class)->name('dropbox.file.')->group(function () {
    Route::post('/download', 'download')
        ->name('download');
    Route::get('/preview', 'preview')
        ->name('preview');
});

// ==================== SEARCH & MATCH ====================
Route::prefix('dropbox/search')->controller(DropboxSearchController::class)->name('dropbox.search.')->group(function () {
    Route::match(['get', 'post'], '/match', 'searchAndMatch')
        ->name('match');
});

// ==================== EXCEL PROCESSING ====================
Route::prefix('dropbox/excel')->controller(DropboxExcelController::class)->name('dropbox.excel.')->group(function () {
    Route::post('/process', 'processExcelUpdate')
        ->name('process');
    Route::get('/results', 'showProcessResults')
        ->name('results');
});
