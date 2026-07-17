<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LogsController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\ProcessesController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\VideoController;
use App\Http\Middleware\EnsureAdvancedModeEnabled;
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
    Route::patch('/channels/{channel}/settings', [ChannelController::class, 'updateSettings'])->name('channels.settings.update');
    Route::post('/channels/{channel}/check-new-videos', [ChannelController::class, 'checkNewVideos'])->name('channels.check-new-videos');
    Route::delete('/channels/{channel}', [ChannelController::class, 'destroy'])->name('channels.destroy');
    Route::get('/videos', [VideoController::class, 'index']);
    Route::get('/videos/{video}', [VideoController::class, 'show']);
    Route::post('/videos/{video}/retry', [VideoController::class, 'retry'])->name('videos.retry');
    Route::delete('/videos/{video}', [VideoController::class, 'destroy'])->name('videos.destroy');
    Route::get('/media/{path}', [MediaController::class, 'show'])->where('path', '.*')->name('media.show');
    Route::get('/channel-media/{path}', [MediaController::class, 'showPublicDisk'])->where('path', '.*')->name('media.channel.show');
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::post('/settings/profile', [SettingsController::class, 'updateProfile']);
    Route::post('/settings/update-tools', [SettingsController::class, 'updateTools']);
    Route::post('/settings/storage-path', [SettingsController::class, 'updateStoragePath']);
    Route::post('/settings/ytdlp-delay', [SettingsController::class, 'updateYtdlpDelay']);
    Route::post('/settings/advanced-mode', [SettingsController::class, 'updateAdvancedMode']);
    Route::post('/settings/cookies', [SettingsController::class, 'updateCookies'])->name('settings.cookies.update');
    Route::delete('/settings/cookies', [SettingsController::class, 'deleteCookies'])->name('settings.cookies.delete');
    Route::get('/settings/check-missing-videos', [SettingsController::class, 'checkMissingVideos']);
    Route::post('/settings/clean-missing-videos', [SettingsController::class, 'cleanMissingVideos']);
    Route::post('/settings/reset-cache', [SettingsController::class, 'resetCache']);
    Route::delete('/settings/warnings/{warning}', [SettingsController::class, 'deleteWarning'])->name('settings.warnings.delete');
    Route::delete('/settings/warnings', [SettingsController::class, 'clearWarnings'])->name('settings.warnings.clear-all');
    Route::post('/settings/backups', [SettingsController::class, 'createBackup'])->name('settings.backups.create');
    Route::get('/settings/backups/{filename}/download', [SettingsController::class, 'downloadBackup'])->where('filename', '.*')->name('settings.backups.download');
    Route::delete('/settings/backups/{filename}', [SettingsController::class, 'deleteBackup'])->where('filename', '.*')->name('settings.backups.delete');
    Route::post('/settings/backups/{filename}/restore', [SettingsController::class, 'restoreBackup'])->where('filename', '.*')->name('settings.backups.restore');
    Route::post('/settings/backups/restore-upload', [SettingsController::class, 'restoreBackupUpload'])->name('settings.backups.restore-upload');

    Route::middleware(EnsureAdvancedModeEnabled::class)->group(function () {
        Route::get('/processes', [ProcessesController::class, 'index'])->name('processes.index');
        Route::delete('/processes/videos/{video}', [ProcessesController::class, 'destroyVideo'])->name('processes.videos.destroy');
        Route::post('/processes/failed-videos/retry-all', [ProcessesController::class, 'retryAllFailedVideos'])->name('processes.failed-videos.retry-all');
        Route::delete('/processes/failed-videos', [ProcessesController::class, 'destroyAllFailedVideos'])->name('processes.failed-videos.destroy-all');
        Route::delete('/processes/jobs/{id}', [ProcessesController::class, 'destroyJob'])->name('processes.jobs.destroy');
        Route::post('/processes/failed-jobs/{uuid}/retry', [ProcessesController::class, 'retryFailedJob'])->name('processes.failed-jobs.retry');
        Route::delete('/processes/failed-jobs/{uuid}', [ProcessesController::class, 'destroyFailedJob'])->name('processes.failed-jobs.destroy');

        Route::get('/logs', [LogsController::class, 'index'])->name('logs.index');
        Route::delete('/logs', [LogsController::class, 'clear'])->name('logs.clear');
    });
});
