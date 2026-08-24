<?php

declare(strict_types=1);

namespace App\Domains\Ai\Http\Controllers;

use App\Domains\Ai\Http\Resources\AiJobResource;
use App\Domains\Ai\Models\AiJob;
use App\Domains\Ai\Services\AiJobDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A customer asking after their own AI work.
 *
 * Two endpoints and no way to start anything. Jobs are created by the feature that
 * needs one — a design version, a room analysis — because only that feature knows what
 * to do with the answer; a generic "run this task" endpoint would let anybody with an
 * account spend our provider budget on prompts of their own choosing.
 */
final class AiJobController
{
    public function __construct(private readonly AiJobDispatcher $dispatcher) {}

    public function show(Request $request, AiJob $job): JsonResponse
    {
        abort_unless($request->user()?->can('view', $job) === true, 403);

        return response()->json(['data' => new AiJobResource($job)])
            /*
             * Never cached. This is the endpoint a client polls precisely because the
             * answer changes, and a proxy that helpfully held onto the `queued` reply
             * would leave a finished render showing a spinner.
             */
            ->header('Cache-Control', 'no-store, private');
    }

    public function cancel(Request $request, AiJob $job): JsonResponse
    {
        abort_unless($request->user()?->can('cancel', $job) === true, 403);

        $stopped = $this->dispatcher->cancel($job);

        return response()->json([
            'data' => new AiJobResource($job->fresh() ?? $job),
            // The two outcomes are different sentences to show somebody, so the client
            // is told which happened rather than left to infer it from the status.
            'message' => $stopped
                ? 'İşlem iptal edildi.'
                : 'İşlem zaten tamamlanmıştı.',
        ]);
    }
}
