<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Exports\AccountingExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports comptables (exports.run et prices.view) : CSV pour un tableur (séparateur « ; », UTF-8 avec BOM
 * pour Excel), journal des écritures en texte tabulé (format FEC).
 */
class ExportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allow($request);

        return response()->json(['data' => collect(AccountingExport::TYPES)->map(fn ($label, $value) => compact('value', 'label'))->values()]);
    }

    /** GET /api/v1/exports/{type}?from=AAAA-MM-JJ&to=AAAA-MM-JJ */
    public function download(Request $request, string $type): StreamedResponse
    {
        $this->allow($request);
        abort_unless(array_key_exists($type, AccountingExport::TYPES), 404, 'Export inconnu.');
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ], ['to.after_or_equal' => 'La fin de la période doit suivre son début.'], ['from' => 'début', 'to' => 'fin']);
        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();
        abort_if($from->diffInDays($to) > 1100, 422, 'Période trop longue : trois ans au plus.');

        [$headers, $rows] = (new AccountingExport($from, $to))->table($type);
        activity('exports')->causedBy($request->user())->withProperties(['type' => $type, 'from' => $from->toDateString(), 'to' => $to->toDateString()])
            ->log('Export : '.AccountingExport::TYPES[$type]);

        $fec = $type === 'fec';
        $name = $fec
            ? 'FEC'.$to->format('Ymd').'.txt'
            : str_replace('_', '-', $type).'-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($headers, $rows, $fec) {
            $out = fopen('php://output', 'w');
            if (! $fec) {
                fwrite($out, "\xEF\xBB\xBF");
            }
            $write = $fec
                ? fn (array $line) => fwrite($out, implode("\t", array_map(fn ($v) => str_replace(["\t", "\r", "\n"], ' ', (string) $v), $line))."\r\n")
                : fn (array $line) => fputcsv($out, $line, ';', '"', '');
            $write($headers);
            foreach ($rows as $row) {
                $write(array_values((array) $row));
            }
            fclose($out);
        }, $name, ['Content-Type' => $fec ? 'text/plain; charset=UTF-8' : 'text/csv; charset=UTF-8']);
    }

    private function allow(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->can('exports.run') && $user->can('prices.view'), 403, "Vous n'avez pas le droit de faire cette action.");
    }
}
