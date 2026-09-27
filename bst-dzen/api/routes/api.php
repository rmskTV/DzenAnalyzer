<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DraftController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\RuleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SystemStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'time' => now()->toIso8601String(),
]));

// Аутентификация (SPA cookie-режим Sanctum)
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Аналитика (вкладка «Конкуренты»): каналы скоупятся политикой ChannelPolicy
    Route::get('/competitive', [DashboardController::class, 'index']);
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/channels', [ChannelController::class, 'index']);
    Route::get('/channels/{channel}/posts', [ChannelController::class, 'posts']);
    Route::get('/channels/{channel}/posts/filters', [ChannelController::class, 'postFilters']);
    Route::get('/channels/{channel}/stats', [ChannelController::class, 'stats']);

    // Управление: только админы
    Route::middleware('admin')->group(function (): void {
        Route::get('/system-status', SystemStatusController::class);
        Route::put('/channels/{channel}', [ChannelController::class, 'update']);

        Route::get('/drafts', [DraftController::class, 'index']);
        Route::get('/drafts/{draft}', [DraftController::class, 'show'])->whereNumber('draft');
        Route::put('/drafts/{draft}', [DraftController::class, 'update'])->whereNumber('draft');
        Route::post('/drafts/{draft}/approve', [DraftController::class, 'approve'])->whereNumber('draft');
        Route::post('/drafts/{draft}/reject', [DraftController::class, 'reject'])->whereNumber('draft');

        Route::get('/rules', [RuleController::class, 'index']);
        Route::post('/rules/{rule}/activate', [RuleController::class, 'activate'])->whereNumber('rule');

        Route::get('/settings', [SettingController::class, 'index']);
        Route::put('/settings', [SettingController::class, 'update']);
    });
});
