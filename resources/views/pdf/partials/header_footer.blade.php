{{-- En-tête et pied de page des PDF : logo, coordonnées, mentions légales (et banque si $bank).
     $neutral : document neutre, sans aucune information de l'entreprise (seulement le numéro de page). --}}
@if ($neutral ?? false)
<footer>
    <div class="page">Page </div>
</footer>
@else
<header>
    <table><tr>
        <td style="width:50%">@if ($logo && ($showLogo ?? true))<img class="logo" src="{{ $logo }}" alt="">@else<strong style="font-size:14px">{{ $company->name }}</strong>@endif</td>
        <td class="company">
            <strong>{{ $company->name }}</strong>@if ($company->legal_form) — {{ $company->legal_form }}@endif<br>
            @if ($company->address){{ $company->address }}@if ($company->city), {{ $company->city }}@endif<br>@endif
            @if ($company->phone){{ $company->phone }}@endif @if ($company->email) · {{ $company->email }}@endif
        </td>
    </tr></table>
</header>

<footer>
    {{ $company->name }}
    @if ($company->ninea) · NINEA {{ $company->ninea }}@endif
    @if ($company->rccm) · RCCM {{ $company->rccm }}@endif
    @if ($company->share_capital) · Capital {{ $company->share_capital }}@endif
    @if (isset($bank) && $bank)<br>{{ $bank->bank_name }} · {{ $bank->account_number }}@if ($bank->iban) · IBAN {{ $bank->iban }}@endif @if ($bank->bic) · BIC {{ $bank->bic }}@endif @endif
    @if ($company->legal_mentions)<br>{{ $company->legal_mentions }}@endif
    <div class="page">Page </div>
</footer>
@endif
