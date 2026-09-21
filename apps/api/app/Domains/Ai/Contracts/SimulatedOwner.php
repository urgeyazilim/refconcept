<?php

declare(strict_types=1);

namespace App\Domains\Ai\Contracts;

use App\Domains\Identity\Models\User;

/**
 * Something an AI job is done for, which knows whose it is.
 *
 * Only the simulator asks. A job started by a request carries the person who made it; a job
 * started inside the design pipeline carries nobody, because nobody pressed a button for it —
 * the engine read the room and then planned the layout on its own. That gap is why the
 * end-to-end suite repointed the platform's shared routing table at the simulator for the
 * length of a run instead of relying on per-account simulation, and a run that died halfway
 * left the whole installation answering from the simulator until somebody noticed. Somebody
 * did not, for six hours: the product owner photographed their living room, waited, and was
 * handed canned furniture.
 *
 * So a subject can name its owner and the gateway can decide per person again. This is for
 * that decision and nothing else — not billing, not the concurrency cap, not who the job is
 * listed under. A job with nobody behind it is still a job with nobody behind it.
 */
interface SimulatedOwner
{
    public function simulatedOwner(): ?User;
}
