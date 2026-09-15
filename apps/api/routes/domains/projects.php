<?php

declare(strict_types=1);

use App\Domains\Identity\Http\Middleware\EnsureEmailIsVerified;
use App\Domains\Identity\Http\Middleware\EnsureUserIsActive;
use App\Domains\Matching\Http\Controllers\DesignMatchController;
use App\Domains\Projects\Http\Controllers\DesignController;
use App\Domains\Projects\Http\Controllers\ProjectController;
use App\Domains\Projects\Http\Controllers\ProjectMemberController;
use App\Domains\Projects\Http\Controllers\RoomController;
use App\Domains\Projects\Http\Controllers\RoomLayoutController;
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
        Route::get('{project}/rooms/{room}/programme', [RoomController::class, 'programme'])
            ->name('rooms.programme');

        Route::post('{project}/rooms/{room}/constraints', [RoomController::class, 'storeConstraint'])
            ->name('rooms.constraints.store');
        Route::patch('{project}/rooms/{room}/constraints/{constraint}', [RoomController::class, 'updateConstraint'])
            ->name('rooms.constraints.update');
        Route::delete('{project}/rooms/{room}/constraints/{constraint}', [RoomController::class, 'destroyConstraint'])
            ->name('rooms.constraints.destroy');

        /*
         * --- the plan ---------------------------------------------------------
         *
         * Measurements are proposed and then confirmed rather than used as they arrive. A
         * photograph read by a model gives numbers that are usually close and occasionally
         * wrong by half a metre, and everything downstream rests on them: whether the sofa
         * fits, what the render is told, what the customer is invited to buy.
         */
        Route::get('{project}/rooms/{room}/layout', [RoomLayoutController::class, 'show'])
            ->name('rooms.layout.show');
        Route::put('{project}/rooms/{room}/layout', [RoomLayoutController::class, 'save'])
            ->name('rooms.layout.save');
        // Arranging what a design settled on. Spends no credits — the design was paid for
        // and this is arithmetic against the room, not another trip to a provider.
        Route::post('{project}/rooms/{room}/layout/compose', [RoomLayoutController::class, 'compose'])
            ->name('rooms.layout.compose');
        // Where one product would go. Writes nothing — the editor holds the arrangement while
        // the page is open, and this answers with a position it can undo like any other move.
        Route::post('{project}/rooms/{room}/layout/place', [RoomLayoutController::class, 'place'])
            ->name('rooms.layout.place');
        /*
         * A picture of the plan, for the renderer to follow.
         *
         * It is a picture of the inside of somebody's home — their walls, their windows,
         * their furniture — so it lands on the private disk under the same rules as their
         * photographs, and no response ever carries a path to it.
         */
        Route::post('{project}/rooms/{room}/layout/snapshot', [RoomLayoutController::class, 'storeSnapshot'])
            ->name('rooms.layout.snapshot');
        // The point of the whole module: a plan is a list of real products at real sizes,
        // checked against a real room, and one press from being an order.
        Route::post('{project}/rooms/{room}/layout/cart', [RoomLayoutController::class, 'addToCart'])
            ->name('rooms.layout.cart');
        Route::post('{project}/rooms/{room}/geometry', [RoomLayoutController::class, 'storeGeometry'])
            ->name('rooms.geometry.store');
        Route::post('{project}/rooms/{room}/geometry/{version}/confirm', [RoomLayoutController::class, 'confirmGeometry'])
            ->name('rooms.geometry.confirm');

        // --- photographs -----------------------------------------------------
        Route::get('{project}/rooms/{room}/media', [RoomMediaController::class, 'index'])
            ->name('rooms.media.index');
        Route::post('{project}/rooms/{room}/media', [RoomMediaController::class, 'store'])
            ->name('rooms.media.store');
        // A link is a separate, deliberate request: it runs the ownership check and
        // returns a URL that expires in five minutes.
        Route::get('{project}/rooms/{room}/media/{medium}/link', [RoomMediaController::class, 'link'])
            ->name('rooms.media.link');
        // The plate: this photograph with the furniture taken out, made in the background.
        Route::post('{project}/rooms/{room}/media/{medium}/clear', [RoomMediaController::class, 'clear'])
            ->name('rooms.media.clear');
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

        /*
         * The shopping list. Nested under the project like everything else in this
         * subtree, so one authorisation check on the parent covers the branch and there is
         * no match id that opens a stranger's flat.
         */
        /*
         * The room tour.
         *
         * Starting one spends credits, so it sits behind the project's `update` ability
         * rather than `view`: somebody invited to look at a design may watch the film and
         * may not pay for another.
         */
        Route::post('{project}/rooms/{room}/designs/{design}/versions/{version}/video', [DesignController::class, 'startVideo'])
            ->name('designs.version.video.start');
        Route::get('{project}/rooms/{room}/designs/{design}/versions/{version}/videos', [DesignController::class, 'videos'])
            ->name('designs.version.videos');

        Route::get('{project}/rooms/{room}/designs/{design}/versions/{version}/matches', [DesignMatchController::class, 'index'])
            ->name('designs.version.matches');
        Route::post('{project}/rooms/{room}/designs/{design}/versions/{version}/matches/rebuild', [DesignMatchController::class, 'rebuild'])
            ->name('designs.version.matches.rebuild');
        Route::post('{project}/rooms/{room}/designs/{design}/versions/{version}/matches/{match}/choose', [DesignMatchController::class, 'choose'])
            ->name('designs.version.matches.choose');
        Route::post('{project}/rooms/{room}/designs/{design}/versions/{version}/matches/{match}/feedback', [DesignMatchController::class, 'feedback'])
            ->name('designs.version.matches.feedback');
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
