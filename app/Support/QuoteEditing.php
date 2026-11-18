<?php

namespace App\Support;

use App\Enums\QuoteStatus;
use App\Models\BankAccount;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Règles communes de modification d'un devis. */
final class QuoteEditing
{
    /** Relations chargées pour renvoyer un devis complet. */
    public const FULL = ['client:id,name', 'project:id,name,manager_id', 'bankAccount', 'creator:id,firstname,lastname', 'sections.lines'];

    /**
     * Nouveau devis en brouillon : numéro, taxe et taux en vigueur, validité de 30 jours,
     * compte bancaire par défaut et une première section.
     *
     * @param  array<string, mixed>  $data  champs de l'en-tête (QuoteForm)
     */
    public static function createDraft(array $data, User $by): Quote
    {
        return DB::transaction(function () use ($data, $by) {
            $quote = new Quote([
                'issued_at' => now()->toDateString(),
                'valid_until' => now()->addDays(30)->toDateString(),
                'tax_mode' => Settings::defaultTax()->value,
                'bank_account_id' => BankAccount::query()->where('is_default', true)->value('id'),
                ...$data,
            ]);
            $quote->vat_rate = (float) Settings::get('vat_rate');
            $quote->brs_rate = (float) Settings::get('brs_rate');
            $quote->number = DocumentNumber::next('quote', $quote->issued_at);
            $quote->created_by = $by->id;
            $quote->save();
            $quote->sections()->create(['title' => 'Fournitures et installation', 'position' => 0]);

            return $quote;
        });
    }

    /** Seuls les brouillons et les devis envoyés se modifient ; un devis accepté se duplique. */
    public static function ensureEditable(Quote $quote): void
    {
        abort_unless(
            in_array($quote->status, [QuoteStatus::Draft, QuoteStatus::Sent], true),
            422,
            "Ce devis est « {$quote->status->label()} » : dupliquez-le pour le modifier.",
        );
    }

    /** Le coût et le coefficient d'une ligne demandent costs.view. */
    public static function ensureCanEditPrices(User $user, array $data): void
    {
        if (array_intersect(array_keys($data), ['unit_cost', 'coefficient']) !== []) {
            abort_unless($user->can('costs.view'), 403, 'Vous ne pouvez pas modifier les coûts : demandez à un commercial ou au manager de chiffrer cette ligne.');
        }
    }

    /**
     * Changements de statut permis. Accepter ou refuser demande quotes.validate.
     *
     * @return list<QuoteStatus>
     */
    public static function allowedTransitions(QuoteStatus $from): array
    {
        return match ($from) {
            QuoteStatus::Draft => [QuoteStatus::Sent, QuoteStatus::Accepted, QuoteStatus::Cancelled],
            QuoteStatus::Sent => [QuoteStatus::Draft, QuoteStatus::Accepted, QuoteStatus::Refused, QuoteStatus::Cancelled],
            QuoteStatus::Accepted => [QuoteStatus::Sent, QuoteStatus::Cancelled],
            QuoteStatus::Refused, QuoteStatus::Cancelled => [QuoteStatus::Draft],
        };
    }
}
