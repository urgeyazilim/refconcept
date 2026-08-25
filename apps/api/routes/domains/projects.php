<?php

declare(strict_types=1);

use App\Domains\Identity\Http\Middleware\EnsureEmailIsVerified;
use App\Domains\Identity\Http\Middleware\EnsureUserIsActive;
use App\Domains\Projects\Http\Controllers\DesignController;
use App\Domains\Projects\Http\Controllers\ProjectController;
use App\Domains\Projects\Http\Controllers\ProjectMemberController;
use App\Domains\Projects\Http\Controllers\RoomController;
use App\Domains\Projects\Http\Controllers\RoomMediaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Projects, rooms and designs
|--------------------------------------------------------------------------
| A customer's own home. Everything here requires a verified account, because a
| project is where room photographs live and an unverified address is not proof
| of anything.
|
| Rooms, media and designs are nested under the project on purpose: one
| authorisation check on the parent covers the whole subtree, and there is no
| room or design id that opens a stranger's flat.
*/

Route::middleware(['auth:sanctum', EnsureUserIsActive::class, EnsureEmailIsVerified::class])
    ->prefix('projects')
    ->as('projects.')
    ->group(function (): void {

        // Accepting an invitation is not scoped to a project the caller can already
        // see — that is the entire point of an invitation — so it sits above them.
        Route::post('invitations/accept', [ProjectMemberController::class, 'accept'])
            ->name('invitations.accept');

        Route::get('/', [ProjectController::class, 'index'])->name('index');
        Route::post('/', [ProjectController::class, 'store'])->name('store');
        Route::get('{project}', [ProjectController::class, 'show'])->name('show');
        Route::patch('{project}', [ProjectController::class, 'update'])->name('update');
        Route::patch('{project}/status', [ProjectController::class, 'setStatus'])->name('status');
        Route::delete('{project}', [ProjectController::class, 'destroy'])->name('destroy');

        // --- sharing -------------------------------------------------------
        Route::post('{project}/members', [ProjectMemberController::class, 'store'])->name('members.store');
        Route::patch('{project}/members/{member}', [ProjectMemberController::class, 'update'])
            ->name('members.update');
        Route::delete('{project}/members/{member}', [ProjectMemberController::class, 'destroy'])
            ->name('members.destroy');

        // --- rooms ----------------------------------------------------------
        Route::post('{project}/rooms', [RoomController::class, 'store'])->name('rooms.store');
        Route::get('{project}/rooms/{room}', [RoomController::class, 'show'])->name('rooms.show');
        Route::patch('{project}/rooms/{room}', [RoomController::class, 'update'])->name('rooms.update');
        Route::delete('{project}/rooms/{room}', [RoomController::class, 'destroy'])->name('rooms.destroy');

        Route::post('{project}/rooms/{room}/constraints', [RoomController::class, 'storeConstraint'])
            ->name('rooms.constraints.store');
        Route::patch('{project}/rooms/{room}/constraints/{constraint}', [RoomController::class, 'updateConstraint'])
            ->name('rooms.constraints.update');
        Route::delete('{project}/rooms/{room}/constraints/{constraint}', [RoomController::class, 'destroyConstraint'])
            ->name('rooms.constraints.destroy');

        // --- photographs -----------------------------------------------------
        Route::get('{project}/rooms/{room}/media', [RoomMediaController::class, 'index'])
            ->name('rooms.media.index');
        Route::post('{project}/rooms/{room}/media', [RoomMediaController::class, 'store'])
            ->name('rooms.media.store');
        // A link is a separate, deliberate request: it runs the ownership check and
        // returns a URL that expires in five minutes.
        Route::get('{project}/rooms/{room}/media/{medium}/link', [RoomMediaController::class, 'link'])
            ->name('rooms.media.link');
        Route::patch('{project}/rooms/{room}/media/{medium}', [RoomMediaController::class, 'update'])
            ->name('rooms.media.update');
        Route::delete('{project}/rooms/{room}/media/{medium}', [RoomMediaController::class, 'destroy'])
            ->name('rooms.media.destroy');

        // --- designs ----------------------------------------------------------
        Route::get('{project}/rooms/{room}/designs', [DesignController::class, 'index'])
            ->name('designs.index');
        Route::post('{project}/rooms/{room}/designs', [DesignController::class, 'store'])
            ->name('designs.store');
        Route::get('{project}/rooms/{room}/designs/{design}', [DesignController::class, 'show'])
            ->name('designs.show');
        Route::post('{project}/rooms/{room}/designs/{design}/branch', [DesignController::class, 'branch'])
            ->name('designs.branch');
        Route::patch('{project}/rooms/{room}/designs/{design}/current', [DesignController::class, 'setCurrentVersion'])
            ->name('designs.current');
        Route::get('{project}/rooms/{room}/designs/{design}/versions/{version}', [DesignController::class, 'version'])
            ->name('designs.version');

        // Polled every couple of seconds while a render runs, so it is its own endpoint
        // returning the smallest useful thing rather than a field on the whole design.
        Route::get('{project}/rooms/{room}/designs/{design}/versions/{version}/progress', [DesignController::class, 'progress'])
            ->name('designs.version.progress');
        Route::delete('{project}/rooms/{room}/designs/{design}', [DesignController::class, 'destroy'])
            ->name('designs.destroy');
    });

/*
 * Streaming fallbacks for storage drivers that cannot sign a URL — the local disk in
 * tests and bare setups. The bytes pass through the application so the policy still
 * applies; a public path would not have one.
 *
 * Outside the project prefix because the media id is enough to find its project, and
 * a signed URL should not have to carry the whole path.
 */
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])
    ->prefix('projects')
    ->as('projects.')
    ->group(function (): void {
        Route::get('room-media/{medium}/download', [RoomMediaController::class, 'download'])
            ->name('room-media.download');
        Route::get('design-assets/{asset}/download', [RoomMediaController::class, 'downloadAsset'])
            ->name('design-assets.download');
    });
