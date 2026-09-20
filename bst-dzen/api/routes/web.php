<?php

use App\Http\Controllers\FeedController;
use Illuminate\Support\Facades\Route;

// Публичный RSS-фид для автопубликации Дзена
Route::get('/feed/{key}.xml', FeedController::class);
