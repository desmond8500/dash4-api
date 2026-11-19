<?php

namespace App\Support;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Article;
use App\Models\ClientRequest;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Project;
use App\Models\Purchase;
use App\Models\Quote;
use App\Models\Task;
use App\Models\TechAccessRequest;
use App\Models\TechShare;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Tableau de bord : chaque widget n'est calculé que pour qui a les droits correspondants
 * (un technicien ne reçoit jamais de montant). Ordre et masquage choisis par l'utilisateur.
 */
final class Dashboard
{
    /** Widgets dans l'ordre par défaut, avec la condition pour les voir. */
    public static function available(User $user): array
    {
        $money = $user->can('prices.view');
        $widgets = [
            'my_tasks' => $user->canAny(['tasks.view', 'tasks.view.own']),
            'client_requests' => $user->canAny(['client_requests.view', 'client_requests.view.own']),
            'revenue' => $money && $user->can('invoices.view'),
            'receivables' => $money && $user->can('invoices.view'),
            'quotes' => $user->canAny(['quotes.view', 'quotes.view.own']),
            'team_tasks' => $user->can('tasks.view'),
            'sites' => $user->canAny(['sites.view', 'sites.view.own']) && ! $user->isTechnician(),
            'contracts' => $user->can('contracts.view'),
            'expenses' => $user->canAny(['accounting.view', 'accounting.view.own']),
            'stock' => $user->can('stock.view') && ! $user->isTechnician(),
            'purchases' => $user->canAny(['purchases.view', 'purchases.view.own']),
            'tech_space' => $user->canAny(['tech_shares.view.own', 'tech_access_requests.view.own', 'tech_access_requests.decide']),
        ];

        return array_keys(array_filter($widgets));
    }

    /** Ordre choisi (les widgets nouveaux ou non classés à la fin) et widgets masqués. */
    public static function layout(User $user): array
    {
        $available = self::available($user);
        $saved = $user->dashboard_layout ?? [];
        $order = array_values(array_intersect($saved['order'] ?? [], $available));
        $order = [...$order, ...array_values(array_diff($available, $order))];

        return ['order' => $order, 'hidden' => array_values(array_intersect($saved['hidden'] ?? [], $available))];
    }

    public static function build(User $user): array
    {
        $layout = self::layout($user);
        $data = [];
        foreach ($layout['order'] as $key) {
            if (! in_array($key, $layout['hidden'], true)) {
                $data[$key] = self::$key($user);
            }
        }

        return ['layout' => $layout, 'widgets' => $data];
    }

    private static function my_tasks(User $user): array
    {
        $mine = Task::query()->whereIn('status', TaskStatus::open())->whereHas('assignees', fn ($q) => $q->whereKey($user->id));
        $today = today();
        $list = (clone $mine)->where(fn ($q) => $q->whereDate('due_date', '<=', $today)->orWhereDate('starts_at', $today))
            ->orderBy('due_date')->limit(6)->get(['id', 'title', 'due_date', 'status', 'priority', 'starts_at']);

        return [
            'open' => (clone $mine)->count(),
            'overdue' => (clone $mine)->whereDate('due_date', '<', $today)->count(),
            'today' => (clone $mine)->where(fn ($q) => $q->whereDate('due_date', $today)->orWhereDate('starts_at', $today))->count(),
            'items' => $list->map(fn (Task $t) => [
                'id' => $t->id, 'title' => $t->title, 'due_date' => $t->due_date?->toDateString(),
                'status' => $t->status->label(), 'overdue' => $t->isOverdue(),
            ])->values(),
        ];
    }

    private static function team_tasks(User $user): array
    {
        $open = Task::query()->whereIn('status', TaskStatus::open());

        return [
            'open' => (clone $open)->count(),
            'overdue' => (clone $open)->whereDate('due_date', '<', today())->count(),
            'blocked' => Task::query()->where('status', TaskStatus::Blocked->value)->count(),
            'unassigned' => (clone $open)->whereDoesntHave('assignees')->count(),
        ];
    }

    private static function client_requests(User $user): array
    {
        $open = ClientRequest::query()->visibleTo($user)->whereIn('status', ['new', 'in_progress']);

        return [
            'open' => (clone $open)->count(),
            'new' => (clone $open)->where('status', 'new')->count(),
            'late' => (clone $open)->where('status', 'new')->where('created_at', '<', now()->subDays(2))->count(),
            'reminders' => (clone $open)->whereNotNull('remind_at')->where('remind_at', '<=', now()->endOfDay())->count(),
        ];
    }

    /** Encaissements des 6 derniers mois (paiements reçus). */
    private static function revenue(User $user): array
    {
        $months = collect(range(5, 0))->map(fn ($i) => today()->startOfMonth()->subMonthsNoOverflow($i));
        $from = $months->first();
        $payments = InvoicePayment::query()->whereDate('paid_at', '>=', $from)->get(['amount', 'paid_at']);
        $series = $months->map(fn (Carbon $m) => [
            'month' => $m->format('Y-m'),
            'amount' => (int) $payments->filter(fn ($p) => $p->paid_at->format('Y-m') === $m->format('Y-m'))->sum('amount'),
        ])->values();
        $invoiced = Invoice::query()->whereNotIn('status', ['draft', 'cancelled'])->whereDate('issued_at', '>=', today()->startOfMonth())->sum('total');

        return [
            'this_month' => $series->last()['amount'],
            'last_month' => $series[$series->count() - 2]['amount'] ?? 0,
            'invoiced_this_month' => (int) $invoiced,
            'series' => $series,
        ];
    }

    private static function receivables(User $user): array
    {
        $unpaid = Invoice::query()->whereIn('status', ['issued', 'partially_paid'])->get(['id', 'total', 'paid_amount', 'due_at']);
        $overdue = $unpaid->filter(fn ($i) => $i->due_at !== null && $i->due_at->lt(today()));

        return [
            'count' => $unpaid->count(),
            'amount' => (int) $unpaid->sum(fn ($i) => $i->remainingAmount()),
            'overdue_count' => $overdue->count(),
            'overdue_amount' => (int) $overdue->sum(fn ($i) => $i->remainingAmount()),
        ];
    }

    private static function quotes(User $user): array
    {
        $visible = Quote::query()->visibleTo($user);
        $sent = (clone $visible)->where('status', 'sent');
        $money = $user->can('prices.view');

        return [
            'drafts' => (clone $visible)->where('status', 'draft')->count(),
            'sent' => (clone $sent)->count(),
            'accepted_this_month' => (clone $visible)->where('status', 'accepted')->where('updated_at', '>=', today()->startOfMonth())->count(),
            ...($money ? ['sent_amount' => (int) (clone $sent)->sum('total')] : []),
        ];
    }

    private static function sites(User $user): array
    {
        $projects = Project::query()->visibleTo($user)->whereIn('status', ProjectStatus::active())
            ->whereHas('installedSystems')->with('installedSystems:id,project_id,progress')
            ->latest('updated_at')->limit(6)->get(['id', 'name', 'status', 'updated_at']);

        return ['items' => $projects->map(fn (Project $p) => [
            'id' => $p->id, 'name' => $p->name,
            'progress' => (int) round($p->installedSystems->avg('progress') ?? 0),
            'systems' => $p->installedSystems->count(),
        ])->values()];
    }

    private static function contracts(User $user): array
    {
        return [
            'active' => Contract::query()->where('status', 'active')->count(),
            'due' => Contract::query()->where('status', 'active')->whereDate('next_invoice_date', '<=', today())->count(),
            'ending' => count(\App\Http\Controllers\Api\V1\ContractController::endingIds()),
        ];
    }

    private static function expenses(User $user): array
    {
        $validator = $user->can('accounting.update');
        $mine = Expense::query()->where('created_by', $user->id);

        return [
            'my_submitted' => (clone $mine)->where('status', 'submitted')->count(),
            'my_to_reimburse' => (clone $mine)->where('status', 'approved')->where('advanced', true)->count(),
            ...($validator ? [
                'to_validate' => Expense::query()->where('status', 'submitted')->count(),
                'to_reimburse' => Expense::query()->where('status', 'approved')->where('advanced', true)->count(),
            ] : []),
            // Ses propres dépenses : le montant est celui qu'on a saisi soi-même
            'my_submitted_amount' => (int) (clone $mine)->where('status', 'submitted')->sum('amount'),
        ];
    }

    private static function stock(User $user): array
    {
        $low = Article::query()->low();

        return [
            'low' => (clone $low)->count(),
            'items' => (clone $low)->orderBy('designation')->limit(6)->get(['id', 'designation', 'quantity', 'min_quantity'])
                ->map(fn ($a) => ['id' => $a->id, 'designation' => $a->designation, 'quantity' => $a->quantity, 'min_quantity' => $a->min_quantity])->values(),
        ];
    }

    private static function purchases(User $user): array
    {
        return [
            'drafts' => Purchase::query()->where('status', 'draft')->count(),
            'awaiting' => Purchase::query()->whereIn('status', ['ordered', 'partially_received'])->count(),
        ];
    }

    private static function tech_space(User $user): array
    {
        return [
            'unread_shares' => TechShare::query()->active()->whereHas('recipients', fn ($q) => $q->whereKey($user->id)->whereNull('tech_share_user.read_at'))->count(),
            'my_pending_requests' => TechAccessRequest::query()->where('user_id', $user->id)->where('status', 'pending')->count(),
            'to_decide' => TechAccessRequest::query()->visibleTo($user)->where('status', 'pending')->where('user_id', '!=', $user->id)->get()
                ->filter(fn ($r) => $user->can('decide', $r))->count(),
        ];
    }
}
