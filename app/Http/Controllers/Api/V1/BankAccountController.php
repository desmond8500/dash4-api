<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BankAccountResource;
use App\Models\BankAccount;
use App\Notifications\CompanyChangeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Comptes bancaires de l'entreprise : lecture bank.view, modification bank.manage.
 * Toute modification est notée au journal et signalée aux administrateurs.
 */
class BankAccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->allow($request, 'bank.view');

        return BankAccountResource::collection(BankAccount::query()->orderByDesc('is_default')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->allow($request, 'bank.manage');
        $account = BankAccount::create($this->validated($request, true));

        $this->notify($request, 'Compte bancaire ajouté', $account);

        return (new BankAccountResource($account))->response()->setStatusCode(201);
    }

    public function update(Request $request, BankAccount $bankAccount): BankAccountResource
    {
        $this->allow($request, 'bank.manage');
        $bankAccount->fill($this->validated($request, false));
        $numbersChanged = $bankAccount->isDirty(['account_number', 'iban']);
        $bankAccount->save();

        if ($numbersChanged) {
            // Le journal ne garde pas les numéros en clair, seulement le fait qu'ils ont changé
            activity('bank')->performedOn($bankAccount)->causedBy($request->user())->log('Numéro de compte ou IBAN modifié');
        }
        $this->notify($request, $numbersChanged ? 'Numéro de compte bancaire modifié' : 'Compte bancaire modifié', $bankAccount);

        return new BankAccountResource($bankAccount->refresh());
    }

    public function destroy(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $this->allow($request, 'bank.manage');
        $bankAccount->delete();

        $this->notify($request, 'Compte bancaire supprimé', $bankAccount);

        return response()->json(['message' => 'Compte bancaire supprimé.']);
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'bank_name' => [$required, 'string', 'max:150'],
            'holder' => [$required, 'string', 'max:150'],
            'account_number' => [$required, 'string', 'max:60'],
            'iban' => ['sometimes', 'nullable', 'string', 'max:50'],
            'bic' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9]{8}([A-Za-z0-9]{3})?$/'],
            'is_default' => ['sometimes', 'boolean'],
        ], ['bic.regex' => 'Le code BIC / SWIFT doit contenir 8 ou 11 lettres et chiffres.'], [
            'bank_name' => 'banque', 'holder' => 'titulaire', 'account_number' => 'numéro de compte (RIB)',
            'iban' => 'IBAN', 'bic' => 'BIC', 'label' => 'libellé',
        ]);
    }

    private function notify(Request $request, string $title, BankAccount $account): void
    {
        CompanyChangeNotification::send('bank', $title, $account->bank_name.' ('.$account->holder.')', $request->user());
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403, "Vous n'avez pas le droit de faire cette action.");
    }
}
