<?php

use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('envoie un lien de mot de passe oublié et change le mot de passe avec', function () {
    Notification::fake();
    $user = userWithRole('technician');
    $user->createToken('téléphone');

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
    $token = null;
    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($n) use (&$token, $user) {
        $token = $n->token;

        return str_contains($n->toMail($user)->actionUrl, '/mot-de-passe/nouveau?token=');
    });

    $this->postJson('/api/v1/auth/reset-password', ['token' => 'faux', 'email' => $user->email, 'password' => 'Nouveau2026x', 'password_confirmation' => 'Nouveau2026x'])
        ->assertJsonValidationErrors('token');
    $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Nouveau2026x', 'password_confirmation' => 'Nouveau2026x'])
        ->assertOk();
    expect(Hash::check('Nouveau2026x', $user->refresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
    // Le lien ne sert qu'une fois
    $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Encore2026x', 'password_confirmation' => 'Encore2026x'])
        ->assertJsonValidationErrors('token');
});

it('ne révèle pas les adresses et n\'envoie rien à un compte désactivé', function () {
    Notification::fake();
    $off = userWithRole('sales');
    $off->forceFill(['is_active' => false])->save();

    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'personne@example.com'])->assertOk()->json('message');
    $inactive = $this->postJson('/api/v1/auth/forgot-password', ['email' => $off->email])->assertOk()->json('message');
    expect($unknown)->toBe($inactive);
    Notification::assertNothingSent();
});
