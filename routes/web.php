<?php

use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/setup', [SetupController::class, 'show']);
Route::post('/setup', [SetupController::class, 'store']);

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'authenticate'])->middleware('throttle:login');
Route::post('/logout', [LoginController::class, 'logout']);

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index']);

    Route::get('/channels', [ChannelController::class, 'index']);
    Route::get('/channels/{channel}', [ChannelController::class, 'show']);
    Route::post('/channels', [ChannelController::class, 'store'])->name('channels.store');
    Route::patch('/channels/{channel}/quality', [ChannelController::class, 'updateQuality']);
    Route::patch('/channels/{channel}/cutoff', [ChannelController::class, 'updateCutoff']);
    Route::delete('/channels/{channel}', [ChannelController::class, 'destroy']);
    Route::get('/videos', [VideoController::class, 'index']);
    Route::get('/videos/{video}', [VideoController::class, 'show']);
    Route::get('/media/{path}', [MediaController::class, 'show'])->where('path', '.*')->name('media.show');
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::post('/settings/profile', [SettingsController::class, 'updateProfile']);
    Route::post('/settings/update-tools', [SettingsController::class, 'updateTools']);
    Route::post('/settings/storage-path', [SettingsController::class, 'updateStoragePath']);
    Route::get('/settings/check-missing-videos', [SettingsController::class, 'checkMissingVideos']);
    Route::post('/settings/clean-missing-videos', [SettingsController::class, 'cleanMissingVideos']);
    Route::post('/settings/reset-cache', [SettingsController::class, 'resetCache']);
});
