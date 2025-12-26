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
Route::prefix('dropbox')->name('dropbox.')->controller(DropboxAuthController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/connect', 'connect')->name('connect');
    Route::get('/callback', 'callback')->name('callback');
    Route::get('/logout', 'logout')->name('logout');
});

// ==================== FILE BROWSING ====================
Route::prefix('dropbox/browse')->name('dropbox.browse.')->controller(DropboxBrowserController::class)->group(function () {
    Route::match(['get', 'post'], '/shared', 'browseSharedLink')
        ->name('shared');
    Route::get('/shared/folder', 'browseSharedSubfolder')
        ->name('shared.folder');
});

// ==================== FILE OPERATIONS ====================
Route::prefix('dropbox/file')->name('dropbox.shared.')->controller(DropboxFileController::class)->group(function () {
    Route::post('/download', 'download')
        ->name('download');
    Route::get('/preview', 'preview')
        ->name('preview');
});

// ==================== SEARCH & MATCH ====================
Route::prefix('dropbox/search')->name('dropbox.search.')->controller(DropboxSearchController::class)->group(function () {
    Route::match(['get', 'post'], '/match', 'searchAndMatch')
        ->name('match');
});

// ==================== EXCEL PROCESSING ====================
Route::prefix('dropbox/excel')->name('dropbox.process.')->controller(DropboxExcelController::class)->group(function () {
    Route::post('/process', 'processExcelUpdate')
        ->name('excel');
    Route::get('/results', 'showProcessResults')
        ->name('results');
});
