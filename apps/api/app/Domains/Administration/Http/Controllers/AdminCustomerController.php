<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Credits\Models\CreditTransaction;
use App\Domains\Credits\Models\CreditWallet;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use App\Domains\Projects\Models\DesignAsset;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\RoomMedia;
use App\Domains\Projects\Services\RoomPhotoStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every customer, for the person answering the phone.
 *
 * Read-only, like the order screen next to it and for the same reason: support needs to
 * *see* an account to answer a question about it. Suspending one, verifying an address by
 * hand or moving credits are all real actions with their own rules, and a button for them
 * here would be a way around those rules rather than an implementation of them.
 *
 * **The photographs are the hard part.** A customer's projects contain pictures of the
 * inside of their home, and support genuinely needs to open one when somebody writes in to
 * say their design came back wrong. So they can be opened — and every single opening is
 * written to the audit log with who looked and at what. That is the trade being made: the
 * screen is useful, and nobody looks at somebody's living room without leaving a name.
 *
 * The list deliberately shows no imagery at all. Browsing is not a reason to see anybody's
 * home, and a thumbnail grid would make looking the default rather than a decision.
 */
final class AdminCustomerController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RoomPhotoStorage $storage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        /*
         * Customers, which means everybody who is not staff.
         *
         * A screen called "customers" that lists your own administrators alongside them is
         * a screen somebody will eventually use to answer a question about the wrong person.
         * Platform roles are the line: anybody holding one is on this side of the business.
         */
        $query = User::query()
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('user_roles')
                    ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                    ->whereColumn('user_roles.user_id', 'users.id')
                    ->where('roles.scope', 'platform');
            })
            ->orderByDesc('created_at');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $term = trim((string) $validated['search']);

            /*
             * E-mail, name or phone — the three things somebody on the phone actually has.
             * Not the id: nobody reads a UUID aloud.
             */
            $query->where(function ($inner) use ($term): void {
                $inner->where('email', 'ilike', '%'.$term.'%')
                    ->orWhere('phone', 'ilike', '%'.$term.'%')
                    ->orWhereHas('profile', fn ($p) => $p
                        ->where('first_name', 'ilike', '%'.$term.'%')
                        ->orWhere('last_name', 'ilike', '%'.$term.'%'));
            });
        }

        $users = $query->with('profile')->limit(100)->get();

        // One query for every wallet rather than one per row: a hundred customers is a
        // hundred round trips otherwise, and this screen is the one support keeps open.
        $wallets = CreditWallet::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->get()
            ->keyBy('user_id');

        // Counted with a query rather than a relation. Ownership is a `user_id` column on
        // the project, and adding an `ownedProjects` relation to the identity model would
        // put one admin screen's convenience inside the identity domain.
        $projectCounts = Project::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $orderCounts = Order::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        return response()->json([
            'data' => $users->map(fn (User $user): array => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->displayName(),
                'phone' => $user->phone,
                'status' => $user->status->value,
                'status_label' => $user->status->label(),
                'email_verified' => $user->email_verified_at !== null,
                'project_count' => (int) ($projectCounts[$user->id] ?? 0),
                'order_count' => (int) ($orderCounts[$user->id] ?? 0),
                'credit_balance' => (int) ($wallets[$user->id]->balance ?? 0),
                'created_at' => $user->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * One customer, with everything support asks about.
     *
     * Orders, credits and projects together, because the question is almost never about one
     * of them alone — "I paid and my design did not appear" spans all three.
     */
    public function show(Request $request, User $customer): JsonResponse
    {
        $customer->loadMissing('profile');

        $wallet = CreditWallet::query()->where('user_id', $customer->getKey())->first();

        $orders = Order::query()
            ->where('user_id', $customer->getKey())
            ->orderByDesc('placed_at')
            ->limit(50)
            ->get();

        // Transactions hang off the wallet, not the user: the ledger's subject is the
        // balance, and a customer without one has no history rather than an empty one.
        $transactions = $wallet === null
            ? collect()
            : CreditTransaction::query()
                ->where('wallet_id', $wallet->getKey())
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

        $projects = Project::query()
            ->where('user_id', $customer->getKey())
            ->withCount(['rooms'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => [
                'id' => $customer->id,
                'email' => $customer->email,
                'name' => $customer->displayName(),
                'phone' => $customer->phone,
                'status' => $customer->status->value,
                'status_label' => $customer->status->label(),
                'email_verified_at' => $customer->email_verified_at?->toIso8601String(),
                'locale' => $customer->locale,
                'created_at' => $customer->created_at?->toIso8601String(),

                'credits' => [
                    'balance' => (int) ($wallet->balance ?? 0),
                    'reserved' => (int) ($wallet->reserved ?? 0),
                    /** @var list<array<string, mixed>> */
                    'transactions' => $transactions->map(fn (CreditTransaction $t): array => [
                        'id' => $t->id,
                        'type' => $t->type->value,
                        'amount' => (int) $t->amount,
                        'description' => $t->description,
                        'created_at' => $t->created_at?->toIso8601String(),
                    ])->all(),
                ],

                'orders' => $orders->map(fn (Order $order): array => [
                    'id' => $order->id,
                    'number' => $order->order_number,
                    'status' => $order->status->value,
                    'total_minor' => (int) $order->grand_total_minor,
                    'currency' => $order->currency,
                    'placed_at' => $order->placed_at?->toIso8601String(),
                ])->all(),

                /*
                 * Counts and names, never pictures. Opening one is a separate request that
                 * writes the operator's name to the audit log — see media().
                 */
                'projects' => $projects->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'status' => $project->status->value,
                    'room_count' => (int) ($project->rooms_count ?? 0),
                    'created_at' => $project->created_at?->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    /**
     * A link to one photograph or render, and a record that somebody asked for it.
     *
     * The audit entry is written *before* the link is issued, deliberately. Written after,
     * a failure between the two would hand out a picture of somebody's home with no trace
     * of it — and the whole justification for this endpoint existing is the trace.
     */
    public function media(Request $request, User $customer, string $media): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'in:room,design'],
            'reason' => ['required', 'string', 'min:8', 'max:200'],
        ]);

        $subject = $validated['kind'] === 'room'
            ? RoomMedia::query()->findOrFail($media)
            : DesignAsset::query()->findOrFail($media);

        // Belongs to this customer, or it is not this customer's screen. Checked rather
        // than trusted from the URL: two ids in a path is two chances to swap one.
        abort_unless($this->belongsTo($subject, $customer), 404);

        $this->audit->record(
            action: 'administration.customer.media_viewed',
            subject: $subject,
            context: [
                'customer_id' => $customer->id,
                'kind' => $validated['kind'],
                // The object key stays out of it. The audit log is read by more people than
                // this endpoint is called by, and a key is most of a link.
            ],
            reason: (string) $validated['reason'],
            actor: $request->user(),
        );

        return response()->json([
            'data' => [
                'url' => $subject instanceof RoomMedia
                    ? $this->storage->temporaryUrl($subject)
                    : $this->storage->temporaryAssetUrl($subject),
                'expires_in' => 300,
            ],
        ]);
    }

    private function belongsTo(RoomMedia|DesignAsset $subject, User $customer): bool
    {
        if ($subject instanceof RoomMedia) {
            return $subject->room?->project?->user_id === $customer->getKey();
        }

        return $subject->version?->design?->room?->project?->user_id === $customer->getKey();
    }
}
