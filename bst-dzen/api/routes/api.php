<?php

use App\Http\Controllers\ChannelController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DraftController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\RuleController;
use App\Http\Controllers\SettingController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'time' => now()->toIso8601String(),
]));

Route::get('/system-status', \App\Http\Controllers\SystemStatusController::class);
Route::get('/competitive', [DashboardController::class, 'index']);
Route::get('/events', [EventController::class, 'index']);

Route::apiResource('channels', ChannelController::class)->only(['index', 'update']);
Route::get('/channels/{channel}/posts', [ChannelController::class, 'posts']);
Route::get('/channels/{channel}/stats', [ChannelController::class, 'stats']);

Route::get('/drafts', [DraftController::class, 'index']);
Route::get('/drafts/{draft}', [DraftController::class, 'show'])->whereNumber('draft');
Route::put('/drafts/{draft}', [DraftController::class, 'update'])->whereNumber('draft');
Route::post('/drafts/{draft}/approve', [DraftController::class, 'approve'])->whereNumber('draft');
Route::post('/drafts/{draft}/reject', [DraftController::class, 'reject'])->whereNumber('draft');

Route::get('/rules', [RuleController::class, 'index']);
Route::post('/rules/{rule}/activate', [RuleController::class, 'activate'])->whereNumber('rule');

Route::get('/settings', [SettingController::class, 'index']);
Route::put('/settings', [SettingController::class, 'update']);
