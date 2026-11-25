<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\InstalledSystem;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Task;
use App\Support\QuotePdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Support\PdfName;

/**
 * GET /api/v1/projects/{project}/summary-pdf — résumé du projet en une ou deux pages : client, devis,
 * factures et encaissements, dépenses, tâches, avancement du chantier, derniers rapports.
 * Sans `prices.view`, aucun montant n'est imprimé.
 */
class ProjectSummaryController extends Controller
{
    public function __invoke(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);
        $user = $request->user();
        $prices = $user->can('prices.view');
        $project->load(['client.phones', 'client.emails', 'manager:id,firstname,lastname']);

        $quotes = $prices || $user->can('quotes.view') || $user->can('quotes.view.own')
            ? Quote::query()->where('project_id', $project->id)->orderBy('issued_at')->get(['id', 'number', 'title', 'status', 'issued_at', 'total'])
            : collect();
        $invoices = $prices && $user->can('invoices.view')
            ? Invoice::query()->where('project_id', $project->id)->whereNotIn('status', ['draft', 'cancelled'])->orderBy('issued_at')->get()
            : collect();
        $expenses = $prices && $user->can('accounting.view')
            ? (int) Expense::query()->where('project_id', $project->id)->where('status', 'approved')->sum('amount')
            : null;
        $tasks = Task::query()->where('project_id', $project->id)->get(['id', 'status', 'due_date']);
        $systems = InstalledSystem::query()->where('project_id', $project->id)->orderBy('position')->get(['id', 'name', 'progress']);
        $journals = Journal::query()->where('project_id', $project->id)->orderByDesc('date')->limit(10)->get(['id', 'title', 'date', 'type']);

        $pdf = QuotePdf::projectSummary([
            'project' => $project,
            'prices' => $prices,
            'quotes' => $quotes,
            'invoices' => $invoices,
            'expenses' => $expenses,
            'tasks' => [
                'total' => $tasks->count(),
                'done' => $tasks->filter(fn ($t) => $t->status->value === 'done')->count(),
                'late' => $tasks->filter(fn ($t) => $t->status->value !== 'done' && $t->due_date && $t->due_date->isPast() && ! $t->due_date->isToday())->count(),
            ],
            'systems' => $systems,
            'journals' => $journals,
        ]);
        $name = PdfName::project($project->loadMissing('client'), 'Résumé');

        return $request->boolean('inline') ? $pdf->stream($name) : $pdf->download($name);
    }
}
