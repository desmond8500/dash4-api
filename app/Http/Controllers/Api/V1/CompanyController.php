<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClientLogoRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Notifications\CompanyChangeNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Identité de l'entreprise (company.view / company.update), logo, signature et cachet
 * (signature.manage). Signature et cachet restent sur le disque privé.
 */
class CompanyController extends Controller
{
    private const IMAGE_KINDS = ['signature' => 'signature_path', 'stamp' => 'stamp_path'];

    private const KIND_LABELS = ['signature' => 'La signature', 'stamp' => 'Le cachet'];

    public function show(Request $request): CompanyResource
    {
        $this->allow($request, 'company.view');

        return new CompanyResource(Company::current());
    }

    public function update(Request $request): CompanyResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'legal_form' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'website' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'ninea' => ['sometimes', 'nullable', 'string', 'max:50'],
            'rccm' => ['sometimes', 'nullable', 'string', 'max:100'],
            'share_capital' => ['sometimes', 'nullable', 'string', 'max:100'],
            'legal_mentions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'signature_on' => ['sometimes', 'array'],
            'signature_on.*' => ['boolean'],
        ], [], [
            'name' => 'raison sociale', 'legal_form' => 'forme juridique', 'website' => 'site web',
            'share_capital' => 'capital social', 'legal_mentions' => 'mentions légales', 'terms' => 'conditions générales',
        ]);

        $company = Company::current();

        if (array_key_exists('signature_on', $data)) {
            $this->allow($request, 'signature.manage');
            $company->signature_on = array_intersect_key(
                array_map('boolval', $data['signature_on']),
                Company::SIGNATURE_DOCUMENTS,
            ) + $company->signatureSettings();
            unset($data['signature_on']);
        }

        if ($data !== []) {
            $this->allow($request, 'company.update');
        }

        $company->fill($data)->save();

        return new CompanyResource($company->refresh());
    }

    public function storeLogo(ClientLogoRequest $request): CompanyResource
    {
        $this->allow($request, 'company.update');
        $company = Company::current();

        $old = $company->logo_path;
        $company->forceFill(['logo_path' => $request->file('logo')->store('company', 'public')])->save();
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return new CompanyResource($company->refresh());
    }

    public function destroyLogo(Request $request): CompanyResource
    {
        $this->allow($request, 'company.update');
        $company = Company::current();

        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
            $company->forceFill(['logo_path' => null])->save();
        }

        return new CompanyResource($company->refresh());
    }

    /** GET /api/v1/company/{signature|stamp} — l'image, pour l'aperçu (signature.manage). */
    public function showImage(Request $request, string $kind): StreamedResponse
    {
        $this->allow($request, 'signature.manage');
        $path = Company::current()->{self::column($kind)};
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'Aucune image enregistrée.');

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** POST /api/v1/company/{signature|stamp} — PNG (fond transparent conseillé), 2 Mo. */
    public function storeImage(Request $request, string $kind): CompanyResource
    {
        $this->allow($request, 'signature.manage');
        $column = self::column($kind);
        $request->validate(
            ['image' => ['required', 'file', 'mimes:png', 'max:2048', 'dimensions:max_width=3000,max_height=3000']],
            ['image.mimes' => "L'image doit être au format PNG (fond transparent conseillé)."],
            ['image' => 'image'],
        );

        $company = Company::current();
        $old = $company->{$column};
        $company->forceFill([$column => $request->file('image')->store('company', 'local')])->save();
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        CompanyChangeNotification::send(
            $kind,
            self::KIND_LABELS[$kind].' de l\'entreprise a été '.($old ? 'remplacé' : 'ajouté').($kind === 'signature' ? 'e' : ''),
            self::KIND_LABELS[$kind].' apparaîtra sur les documents choisis',
            $request->user(),
        );

        return new CompanyResource($company->refresh());
    }

    public function destroyImage(Request $request, string $kind): CompanyResource
    {
        $this->allow($request, 'signature.manage');
        $column = self::column($kind);
        $company = Company::current();

        if ($company->{$column}) {
            Storage::disk('local')->delete($company->{$column});
            $company->forceFill([$column => null])->save();
            CompanyChangeNotification::send(
                $kind,
                self::KIND_LABELS[$kind].' de l\'entreprise a été retiré'.($kind === 'signature' ? 'e' : ''),
                'Les documents ne le montreront plus',
                $request->user(),
            );
        }

        return new CompanyResource($company->refresh());
    }

    private static function column(string $kind): string
    {
        return self::IMAGE_KINDS[$kind] ?? abort(404);
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403, "Vous n'avez pas le droit de faire cette action.");
    }
}
