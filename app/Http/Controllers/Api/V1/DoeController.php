<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Support\QuotePdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** GET /api/v1/projects/{project}/doe — dossier des ouvrages exécutés, en PDF, à partir du chantier. */
class DoeController extends Controller
{
    public function __invoke(Request $request, Project $project): Response
    {
        $this->authorize('viewSite', $project);
        $pdf = QuotePdf::doe($project);
        $name = 'DOE — '.mb_substr($project->name, 0, 80).'.pdf';

        return $request->boolean('inline') ? $pdf->stream($name) : $pdf->download($name);
    }
}
