<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/assignees — personnes à qui confier une demande ou une tâche, ou membres d'une caisse :
 * comptes actifs, nom et type de compte seulement (sans la permission users.view).
 */
class AssigneeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->canAny(['client_requests.update', 'tasks.assign', 'users.view', 'accounting.update']),
            403,
            "Vous n'avez pas le droit de faire cette action.",
        );

        $users = User::query()
            ->where('is_active', true)
            ->orderBy('firstname')
            ->orderBy('lastname')
            ->get(['id', 'firstname', 'lastname', 'account_type', 'job_title']);

        return response()->json(['data' => $users->map(fn (User $u) => [
            'id' => $u->id,
            'full_name' => $u->full_name,
            'job_title' => $u->job_title,
            'account_type' => $u->account_type->value,
        ])->values()]);
    }
}
