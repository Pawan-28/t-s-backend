<?php

use App\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Route;

// Public full-text search (PUBLISHED articles only).
Route::get('search', SearchController::class);
