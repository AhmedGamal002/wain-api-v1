<?php

use App\Http\Controllers\TeamInvitationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// قبول دعوة فريق اللوحة (A7): الرابط اللي في الإيميل. الـ token في الـ path والـ limiter `team-invitation`.
Route::prefix('team/invitations/{token}')->middleware('throttle:team-invitation')->group(function () {
    Route::get('/', [TeamInvitationController::class, 'show'])->name('team.invitation.show');
    Route::post('/', [TeamInvitationController::class, 'accept'])->name('team.invitation.accept');
});
