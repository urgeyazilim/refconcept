<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Administration\Services\AdminPermissionMatrix;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reading the audit trail, and the permission matrix behind it.
 *
 * The trail answers "who did this and why". The matrix answers "who *could* have" — and
 * having both on one screen is what turns an audit log from a list of rows into something
 * somebody can reason with.
 */
final class AdminAuditController
{
    public function __construct(
        private readonly AdminPermissionMatrix $matrix,
        private readonly AccessControl $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['sometimes', 'nullable', 'string', 'max:120'],
            'actor' => ['sometimes', 'nullable', 'uuid'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        $query = AuditLog::query()
            ->with('actor')
            ->where('created_at', '>=', now()->subDays((int) ($validated['days'] ?? 30)))
            ->orderByDesc('created_at');

        if (! empty($validated['action'])) {
            // A prefix, so "payments." finds everything financial without an operator
            // having to know the leaf names.
            $query->where('action', 'like', $validated['action'].'%');
        }

        if (! empty($validated['actor'])) {
            $query->where('actor_id', $validated['actor']);
        }

        return $this->json([
            'data' => $query->limit(200)->get()->map(static fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor?->email,
                'actor_type' => $log->actor_type,
                'subject_type' => class_basename((string) $log->auditable_type),
                'subject_id' => $log->auditable_id,
                'reason' => $log->reason,
                'changes' => $log->changes,
                'context' => $log->context,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Who may do what, and what the caller themselves may do.
     *
     * The second half is the useful one: an operator looking at a button they cannot press
     * deserves to find out why from a screen rather than from a 403.
     */
    public function matrix(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return $this->json([
            'data' => [
                'routes' => $this->matrix->all(),
                'permissions' => array_map(
                    fn (Permission $permission): array => [
                        'value' => $permission->value,
                        'description' => $permission->description(),
                        'granted' => $this->access->hasPermission($user, $permission),
                    ],
                    Permission::cases(),
                ),
                // Empty in a release: the suite fails on a non-empty list. Surfaced anyway,
                // because a gap here is the kind of thing worth seeing the moment it exists.
                'uncovered_routes' => $this->matrix->uncovered(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'no-store, private');
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
