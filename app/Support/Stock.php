<?php

namespace App\Support;

use App\Enums\StockMovementType;
use App\Models\Article;
use App\Models\StockMovement;
use App\Models\User;
use App\Notifications\LowStockNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Seul chemin pour changer une quantité en stock : un mouvement, sous verrou, avec la quantité
 * obtenue. Alerte de seuil quand un article passe sous sa quantité minimale.
 */
final class Stock
{
    /**
     * @param  float  $delta  positif pour une entrée, négatif pour une sortie
     * @param  array{reason?: ?string, project_id?: ?int, unit_cost?: ?float, client_uuid?: ?string, moved_at?: mixed, source?: ?Model, allow_negative?: bool}  $options
     */
    public static function move(Article $article, float $delta, StockMovementType $type, ?User $by, array $options = []): StockMovement
    {
        return DB::transaction(function () use ($article, $delta, $type, $by, $options) {
            $locked = Article::query()->withTrashed()->lockForUpdate()->findOrFail($article->id);
            $before = $locked->quantity;
            $after = round($before + $delta, 2);

            abort_if(
                $after < 0 && ! ($options['allow_negative'] ?? false),
                422,
                'Stock insuffisant pour « '.$locked->designation.' » : '.self::format($before).' en stock, '.self::format(-$delta).' demandé(s).',
            );

            $locked->forceFill(['quantity' => $after])->save();
            $article->setRawAttributes($locked->getAttributes(), true);

            $movement = StockMovement::create([
                'client_uuid' => $options['client_uuid'] ?? null,
                'article_id' => $locked->id,
                'type' => $type,
                'quantity' => round($delta, 2),
                'quantity_after' => $after,
                'unit_cost' => $options['unit_cost'] ?? null,
                'reason' => $options['reason'] ?? null,
                'source_type' => isset($options['source']) ? $options['source']->getMorphClass() : null,
                'source_id' => ($options['source'] ?? null)?->getKey(),
                'project_id' => $options['project_id'] ?? null,
                'created_by' => $by?->id,
                'moved_at' => $options['moved_at'] ?? now(),
            ]);

            $min = $locked->min_quantity;
            if ($min !== null && $after <= $min && $before > $min) {
                DB::afterCommit(fn () => LowStockNotification::send($locked));
            }

            return $movement;
        });
    }

    /** Inventaire : fixe la quantité comptée (mouvement de la différence). */
    public static function count(Article $article, float $counted, ?User $by, array $options = []): StockMovement
    {
        $article->refresh();

        return self::move($article, round($counted - $article->quantity, 2), StockMovementType::Inventory, $by, [
            ...$options,
            'reason' => $options['reason'] ?? 'Inventaire',
            'allow_negative' => true,
        ]);
    }

    public static function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ',');
    }
}
