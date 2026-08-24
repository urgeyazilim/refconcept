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
