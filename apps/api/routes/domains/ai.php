<?php

declare(strict_types=1);

use App\Domains\Ai\Http\Controllers\AdminAiConfigController;
use App\Domains\Ai\Http\Controllers\AdminAiObservabilityController;
use App\Domains\Ai\Http\Controllers\AdminAiPromptController;
use App\Domains\Ai\Http\Controllers\AiJobController;
use App\Domains\Identity\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AI gateway
|--------------------------------------------------------------------------
| Two surfaces, and the split is the important part:
|
|   /ai/jobs/*        a customer asking after their own work. There is no
|                     endpoint here that *starts* a job — that is always done
|                     by the feature which knows what to do with the answer,
|                     because a generic "run this prompt" endpoint would let
|                     anyone with an account spend the provider budget.
|
|   /admin/ai/*       configuration and observability. Gated on the same
|                     permission as system settings: whoever can point a task
|                     at a different model can change what every customer gets.
*/

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])
    ->prefix('ai')
    ->as('ai.')
    ->group(function (): void {
        Route::get('jobs/{job}', [AiJobController::class, 'show'])->name('jobs.show');
        Route::post('jobs/{job}/cancel', [AiJobController::class, 'cancel'])->name('jobs.cancel');
    });

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])
    ->prefix('admin/ai')
    ->as('admin.ai.')
    ->group(function (): void {

        // --- configuration ---------------------------------------------------
        Route::get('overview', [AdminAiConfigController::class, 'overview'])->name('overview');

        Route::post('providers', [AdminAiConfigController::class, 'storeProvider'])->name('providers.store');
        Route::patch('providers/{provider}', [AdminAiConfigController::class, 'updateProvider'])
            ->name('providers.update');
        Route::post('providers/{provider}/credentials', [AdminAiConfigController::class, 'storeCredential'])
            ->name('providers.credentials.store');
        Route::post('providers/{provider}/models', [AdminAiConfigController::class, 'storeModel'])
            ->name('providers.models.store');

        Route::patch('models/{model}', [AdminAiConfigController::class, 'updateModel'])->name('models.update');
        Route::post('models/{model}/rates', [AdminAiConfigController::class, 'storeRate'])->name('models.rates.store');

        // One endpoint for create-or-update: a task has at most one route, so "add" and
        // "edit" are the same act and two endpoints would only invite them to disagree.
        Route::put('routes', [AdminAiConfigController::class, 'saveRoute'])->name('routes.save');
        Route::post('routes/{route}/pause', [AdminAiConfigController::class, 'pauseRoute'])->name('routes.pause');
        Route::post('routes/{route}/resume', [AdminAiConfigController::class, 'resumeRoute'])->name('routes.resume');

        // --- prompts ----------------------------------------------------------
        Route::get('prompts', [AdminAiPromptController::class, 'index'])->name('prompts.index');
        Route::post('prompts', [AdminAiPromptController::class, 'storeTemplate'])->name('prompts.store');
        Route::post('prompts/{template}/versions', [AdminAiPromptController::class, 'storeVersion'])
            ->name('prompts.versions.store');
        Route::patch('prompt-versions/{version}', [AdminAiPromptController::class, 'updateVersion'])
            ->name('prompts.versions.update');
        Route::post('prompt-versions/{version}/publish', [AdminAiPromptController::class, 'publishVersion'])
            ->name('prompts.versions.publish');
        Route::post('prompt-versions/{version}/preview', [AdminAiPromptController::class, 'preview'])
            ->name('prompts.versions.preview');

        // --- observability -----------------------------------------------------
        Route::get('jobs', [AdminAiObservabilityController::class, 'jobs'])->name('jobs.index');
        Route::get('jobs/{job}', [AdminAiObservabilityController::class, 'job'])->name('jobs.show');
        Route::get('usage', [AdminAiObservabilityController::class, 'usage'])->name('usage');
    });
