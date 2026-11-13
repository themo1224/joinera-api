<?php

use App\Http\Controllers\ApplicantController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\IdentityReadinessController;
use App\Http\Controllers\ListingImportController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileCvController;
use App\Http\Controllers\ProfileParseController;
use App\Http\Controllers\ProfilePrioritiesController;
use App\Http\Controllers\SiteIdentityController;
use App\Http\Controllers\StaffIdentityReadinessController;
use App\Http\Controllers\StaffMailboxController;
use App\Http\Controllers\StaffMatchController;
use App\Http\Controllers\StaffPrioritiesController;
use App\Http\Controllers\StaffProfileController;
use App\Http\Controllers\StaffSiteIdentityController;
use App\Http\Controllers\StaffUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('signup', [AuthController::class, 'signup']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('staff-users', [StaffUserController::class, 'store'])->middleware('admin');

        Route::get('profile', [ProfileController::class, 'show']);
        Route::put('profile', [ProfileController::class, 'update']);
        Route::post('profile/cv', [ProfileCvController::class, 'store']);
        Route::post('profile/parse', [ProfileParseController::class, 'store'])->middleware('user');
        Route::get('profile/priorities', [ProfilePrioritiesController::class, 'show'])->middleware('user');
        Route::put('profile/priorities', [ProfilePrioritiesController::class, 'update'])->middleware('user');

        // Candidate self-provision is disabled for GMX v1 (Admin attaches credentials).
        Route::post('mailbox', [MailboxController::class, 'store'])->middleware('user');
        Route::get('mailbox', [MailboxController::class, 'show'])->middleware('user');

        Route::post('site-identities', [SiteIdentityController::class, 'store'])->middleware('user');
        Route::get('site-identities', [SiteIdentityController::class, 'index'])->middleware('user');

        Route::get('identity/readiness', [IdentityReadinessController::class, 'show'])->middleware('user');
        Route::put('identity/readiness', [IdentityReadinessController::class, 'rejectPut'])->middleware('user');

        Route::post('listings/import', [ListingImportController::class, 'store'])->middleware('admin');

        Route::get('applicants', [ApplicantController::class, 'index'])->middleware('staff');
        Route::post('applicants', [ApplicantController::class, 'store'])->middleware('admin');
        Route::get('applicants/{user}', [ApplicantController::class, 'show'])->middleware('staff');
        Route::delete('applicants/{user}', [ApplicantController::class, 'destroy'])->middleware('staff');

        Route::get('users/{user}/profile', [StaffProfileController::class, 'show'])->middleware('staff');
        Route::put('users/{user}/profile', [StaffProfileController::class, 'update'])->middleware('staff');
        Route::get('users/{user}/profile/priorities', [StaffPrioritiesController::class, 'show'])->middleware('staff');
        Route::put('users/{user}/profile/priorities', [StaffPrioritiesController::class, 'update'])->middleware('staff');
        Route::post('users/{user}/profile/cv', [StaffProfileController::class, 'storeCv'])->middleware('staff');
        Route::post('users/{user}/profile/approvals', [StaffProfileController::class, 'approve'])->middleware('staff');
        Route::get('users/{user}/mailbox', [StaffMailboxController::class, 'show'])->middleware('staff');
        Route::put('users/{user}/mailbox/credentials', [StaffMailboxController::class, 'updateCredentials'])->middleware('admin');
        Route::post('users/{user}/mailbox/sync', [StaffMailboxController::class, 'sync'])->middleware('staff');
        Route::get('users/{user}/mailbox/messages', [StaffMailboxController::class, 'messages'])->middleware('staff');
        Route::get('users/{user}/mailbox/messages/{message}', [StaffMailboxController::class, 'showMessage'])->middleware('staff');
        Route::post('users/{user}/mailbox/messages/{message}/reply', [StaffMailboxController::class, 'reply'])->middleware('staff');
        Route::get('users/{user}/site-identities', [StaffSiteIdentityController::class, 'index'])->middleware('staff');
        Route::get('users/{user}/identity/readiness', [StaffIdentityReadinessController::class, 'show'])->middleware('staff');

        Route::get('matches', [StaffMatchController::class, 'index'])->middleware('staff');
        Route::post('matches/{match}/force-queue', [StaffMatchController::class, 'forceQueue'])->middleware('staff');
    });
});
