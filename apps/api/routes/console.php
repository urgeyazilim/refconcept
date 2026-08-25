<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Closure-based console commands and the schedule
|--------------------------------------------------------------------------
| Domain commands are classes registered in bootstrap/app.php via withCommands(),
| because they live with their domain rather than in app/Console/Commands where
| Laravel would discover them automatically.
*/

/*
 * Stock held by abandoned baskets goes back on sale.
 *
 * Every five minutes rather than hourly: the window between a hold expiring and the
 * stock becoming buyable again is time a customer spends looking at "sold out" for
 * something that is sitting in a warehouse. Reserving a row already clears its own
 * stale holds, so this is the sweep for everything nobody has tried to buy since.
 */
Schedule::command('refconcept:release-expired-reservations')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Dated credits stop being spendable, and holds nobody came back for go back.
 *
 * Hourly. A hold left over from an abandoned render is credits a customer cannot spend
 * while their screen says they can, and waiting until midnight to return them turns a
 * two-minute annoyance into a support ticket. Expiry itself would be fine once a day;
 * the holds are what set the cadence.
 */
Schedule::command('refconcept:sweep-credits')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Every listable product gets a search vector.
 *
 * Nightly rather than hourly: a description changes when a seller edits it, which is not
 * an event worth a provider call within the hour. The hash means an unchanged catalogue
 * costs one query and nothing else, so the run is cheap even when there is nothing to do.
 */
Schedule::command('refconcept:embed-catalogue')
    ->dailyAt('03:20')
    ->withoutOverlapping()
    ->runInBackground();
