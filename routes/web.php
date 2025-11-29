<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DropboxController;

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
Route::post('/browse', [DropboxController::class, 'browse'])->name('dropbox.browse');
Route::post('/browse/folder', [DropboxController::class, 'browseFolder'])->name('dropbox.folder');
Route::post('/download', [DropboxController::class, 'download'])->name('dropbox.download');
Route::post('/preview', [DropboxController::class, 'preview'])->name('dropbox.preview');