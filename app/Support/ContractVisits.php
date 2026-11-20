<?php

namespace App\Support;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\ContractVisit;
use App\Models\Task;
use Illuminate\Support\Carbon;

/**
 * Visites de maintenance : `visits_per_year` visites réparties sur l'année du contrat, à partir de sa date
 * de début (4 par an : tous les 3 mois). Chaque visite des `horizon` prochains jours devient une tâche
 * du planning, une seule fois.
 */
final class ContractVisits
{
    /** @return list<array{number: int, date: Carbon}> visites prévues entre `$from` et `$to` */
    public static function dates(Contract $contract, Carbon $from, Carbon $to): array
    {
        $perYear = (int) $contract->visits_per_year;
        if ($perYear <= 0 || ! $contract->start_date) {
            return [];
        }
        $result = [];
        for ($k = 0; $k < 2000; $k++) {
            $date = $contract->start_date->copy()->addMonthsNoOverflow(intdiv($k * 12, $perYear));
            if ($date->gt($to) || ($contract->end_date && $date->gt($contract->end_date))) {
                break;
            }
            if ($date->gte($from)) {
                $result[] = ['number' => ($k % $perYear) + 1, 'date' => $date];
            }
        }

        return $result;
    }

    /** Crée les visites (et leurs tâches) des prochains jours ; renvoie le nombre de visites créées. */
    public static function plan(int $horizon = 30): int
    {
        $today = today();
        $created = 0;
        Contract::query()->where('status', ContractStatus::Active->value)->where('visits_per_year', '>', 0)->get()
            ->each(function (Contract $c) use ($today, $horizon, &$created) {
                foreach (self::dates($c, $today, $today->copy()->addDays($horizon)) as $visit) {
                    if (ContractVisit::query()->where('contract_id', $c->id)->whereDate('planned_on', $visit['date'])->exists()) {
                        continue;
                    }
                    $task = new Task([
                        'title' => mb_substr("Visite de maintenance {$visit['number']}/{$c->visits_per_year} — {$c->title}", 0, 255),
                        'description' => "Visite prévue par le contrat {$c->number}.",
                        'priority' => 'normal',
                    ]);
                    $task->forceFill([
                        'status' => 'todo', 'project_id' => $c->project_id, 'client_id' => $c->client_id,
                        'due_date' => $visit['date']->toDateString(), 'created_by' => $c->created_by,
                    ])->save();
                    $row = new ContractVisit(['number' => $visit['number'], 'planned_on' => $visit['date']]);
                    $row->task_id = $task->id;
                    $c->visits()->save($row);
                    $created++;
                }
            });

        return $created;
    }
}
