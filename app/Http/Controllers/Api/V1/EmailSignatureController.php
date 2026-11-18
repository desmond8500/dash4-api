<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmailSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Signatures d'e-mail : chacun gère les siennes ; les signatures communes à l'entreprise
 * (`shared`) se gèrent avec company.update.
 */
class EmailSignatureController extends Controller
{
    /** GET /api/v1/email-signatures — les siennes, celles de l'entreprise, et les variables possibles. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $signatures = EmailSignature::query()->availableTo($user)
            ->orderByRaw('user_id is null')->orderByDesc('is_default')->orderBy('name')->get();

        return response()->json([
            'data' => $signatures->map(fn ($s) => $this->present($s, $request))->values(),
            'meta' => [
                'variables' => collect(EmailSignature::VARIABLES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
                'default_id' => EmailSignature::defaultFor($user)?->id,
                'can_manage_shared' => $user->can('company.update'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $shared = (bool) ($data['shared'] ?? false);
        if ($shared) {
            $this->allowShared($request);
        }

        $signature = new EmailSignature(array_diff_key($data, ['shared' => true]));
        $signature->user_id = $shared ? null : $request->user()->id;
        $signature->save();

        return response()->json(['data' => $this->present($signature, $request)], 201);
    }

    public function update(Request $request, EmailSignature $signature): JsonResponse
    {
        $this->allowChange($request, $signature);
        $signature->update($this->validated($request, false));

        return response()->json(['data' => $this->present($signature->refresh(), $request)]);
    }

    public function destroy(Request $request, EmailSignature $signature): JsonResponse
    {
        $this->allowChange($request, $signature);
        $signature->delete();

        return response()->json(['message' => 'Signature supprimée.']);
    }

    /** POST /api/v1/email-signatures/{id}/image — JPEG ou PNG, 1 Mo (pas de WebP : mal lu par les messageries). */
    public function storeImage(Request $request, EmailSignature $signature): JsonResponse
    {
        $this->allowChange($request, $signature);
        $request->validate(
            ['image' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:1024', 'dimensions:max_width=2000,max_height=2000']],
            ['image.mimes' => "L'image doit être au format JPEG ou PNG.", 'image.max' => "L'image ne peut pas dépasser 1 Mo."],
            ['image' => 'image'],
        );

        $old = $signature->image_path;
        $signature->forceFill(['image_path' => $request->file('image')->store('signatures', 'public')])->save();
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return response()->json(['data' => $this->present($signature->refresh(), $request)]);
    }

    public function destroyImage(Request $request, EmailSignature $signature): JsonResponse
    {
        $this->allowChange($request, $signature);
        if ($signature->image_path) {
            Storage::disk('public')->delete($signature->image_path);
            $signature->forceFill(['image_path' => null])->save();
        }

        return response()->json(['data' => $this->present($signature->refresh(), $request)]);
    }

    private function present(EmailSignature $signature, Request $request): array
    {
        return [
            'id' => $signature->id,
            'name' => $signature->name,
            'body' => $signature->body,
            'is_default' => $signature->is_default,
            'shared' => $signature->isShared(),
            // Aperçu avec les informations de l'utilisateur connecté
            'preview' => $signature->render($request->user()),
            'image_url' => $signature->imageUrl(),
            'image_width' => $signature->image_width,
            'can_edit' => $signature->isShared() ? $request->user()->can('company.update') : true,
        ];
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:100'],
            'body' => [$required, 'string', 'max:3000'],
            'is_default' => ['sometimes', 'boolean'],
            // Largeur de l'image dans l'e-mail, en pixels
            'image_width' => ['sometimes', 'integer', 'min:40', 'max:600'],
            'shared' => $creating ? ['sometimes', 'boolean'] : ['prohibited'],
        ], [], ['name' => 'nom de la signature', 'body' => 'texte de la signature']);
    }

    private function allowChange(Request $request, EmailSignature $signature): void
    {
        if ($signature->isShared()) {
            $this->allowShared($request);

            return;
        }
        // Les signatures des autres n'existent pas pour soi
        abort_unless($signature->user_id === $request->user()->id, 404);
    }

    private function allowShared(Request $request): void
    {
        abort_unless($request->user()->can('company.update'), 403, 'Seuls l\'administrateur et le comptable gèrent les signatures de l\'entreprise.');
    }
}
