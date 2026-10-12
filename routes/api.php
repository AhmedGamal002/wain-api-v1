<?php

use App\Http\Controllers\Api\V1\CategoryController;
use Illuminate\Support\Facades\Route;

// المسار الأساسي /api/v1 (06 القسم 1). أي تغيير في الـ API يتكتب في Scribe الأول.
Route::prefix('v1')->group(function () {
    Route::get('categories', [CategoryController::class, 'index']);
});
