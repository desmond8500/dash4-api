<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Dashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tableau de bord selon les droits de chacun ; ordre et widgets masqués propres à l'utilisateur. */
class DashboardController extends Controller
{
    /** GET /api/v1/dashboard */
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAny(['dashboard.view', 'dashboard.view.own']), 403, "Vous n'avez pas le droit de faire cette action.");

        return response()->json(['data' => Dashboard::build($request->user())]);
    }

    /** PUT /api/v1/dashboard/layout — `order` (clés des widgets), `hidden`. */
    public function layout(Request $request): JsonResponse
    {
        $user = $request->user();
        $available = Dashboard::available($user);
        $data = $request->validate([
            'order' => ['present', 'array'],
            'order.*' => ['string', Rule::in($available)],
            'hidden' => ['present', 'array'],
            'hidden.*' => ['string', Rule::in($available)],
        ]);
        $user->forceFill(['dashboard_layout' => ['order' => array_values(array_unique($data['order'])), 'hidden' => array_values(array_unique($data['hidden']))]])->save();

        return response()->json(['data' => Dashboard::build($user->refresh())]);
    }
}
