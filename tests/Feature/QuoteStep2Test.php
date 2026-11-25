<?php

use App\Mail\QuoteMail;
use App\Models\Article;
use App\Models\Client;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmailSignature;
use App\Models\Project;
use App\Models\QuoteTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/** Devis TVA avec une section et des lignes : centrale, 2 caméras, câble, pose. */
function quoteWithLines(): array
{
    $client = Client::factory()->create(['name' => 'Hôtel Teranga']);
    $quote = test()->postJson('/api/v1/quotes', ['client_id' => $client->id, 'title' => 'Vidéosurveillance', 'tax_mode' => 'vat'])->json('data');
    $section = $quote['sections'][0]['id'];
    foreach ([
        ['designation' => 'Enregistreur 8 voies', 'category' => 'central', 'unit_cost' => 150000, 'coefficient' => 1.4],
        ['designation' => 'Caméra IP 4 MP', 'reference' => 'DS-2CD1043', 'category' => 'device', 'quantity' => 2, 'unit_cost' => 50000, 'coefficient' => 1.5],
        ['designation' => 'Câble RJ45', 'category' => 'cable', 'quantity' => 100, 'unit' => 'm', 'unit_cost' => 300, 'coefficient' => 2],
        ['designation' => 'Pose et paramétrage', 'category' => 'service', 'unit_cost' => 100000],
    ] as $line) {
        $quote = test()->postJson("/api/v1/quote-sections/{$section}/lines", $line)->assertOk()->json('data');
    }

    return $quote;
}

// --- Systèmes et modèles ---------------------------------------------------------------

it('enregistre une section comme modèle, rangé dans un système, et l\'insère dans un autre devis', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = quoteWithLines();
    $system = $this->postJson('/api/v1/quote-systems', ['name' => 'Vidéosurveillance'])->assertCreated()->json('data.id');

    $template = $this->postJson("/api/v1/quote-sections/{$quote['sections'][0]['id']}/save-as-template", [
        'name' => 'Kit 2 caméras', 'quote_system_id' => $system,
    ])->assertCreated()->json('data');
    expect($template['lines'])->toHaveCount(4)->and($template['lines'][1]['unit_cost'])->toEqual(50000);

    $this->getJson('/api/v1/quote-systems')
        ->assertJsonPath('data.systems.0.name', 'Vidéosurveillance')
        ->assertJsonPath('data.systems.0.templates.0.lines_count', 4);

    $other = $this->postJson('/api/v1/quotes', ['client_id' => Client::factory()->create()->id, 'title' => 'Autre', 'tax_mode' => 'vat'])->json('data');
    $this->postJson("/api/v1/quotes/{$other['id']}/insert-template", ['template_id' => $template['id']])
        ->assertOk()
        ->assertJsonPath('data.sections.1.title', 'Kit 2 caméras')
        ->assertJsonPath('data.sections.1.lines.1.unit_price', 75000)
        ->assertJsonPath('data.subtotal', $quote['subtotal']);

    // Dans une section existante, à la suite des lignes
    $this->postJson("/api/v1/quotes/{$other['id']}/insert-template", ['template_id' => $template['id'], 'quote_section_id' => $other['sections'][0]['id']])
        ->assertJsonCount(4, 'data.sections.0.lines');
});

it('crée et modifie un modèle avec ses lignes, et garde les modèles d\'un système supprimé', function () {
    Sanctum::actingAs(userWithRole('manager'));
    $system = $this->postJson('/api/v1/quote-systems', ['name' => 'Alarme'])->json('data.id');

    $id = $this->postJson('/api/v1/quote-templates', [
        'name' => 'Alarme maison', 'quote_system_id' => $system,
        'lines' => [['designation' => 'Centrale', 'category' => 'central', 'unit_cost' => 80000, 'coefficient' => 1.5], ['designation' => 'Détecteur', 'quantity' => 4]],
    ])->assertCreated()->assertJsonCount(2, 'data.lines')->json('data.id');

    $this->patchJson("/api/v1/quote-templates/{$id}", ['lines' => [['designation' => 'Centrale seule', 'unit_cost' => 80000]]])
        ->assertJsonCount(1, 'data.lines')->assertJsonPath('data.lines.0.unit_price', 80000);

    $this->deleteJson("/api/v1/quote-systems/{$system}")->assertOk();
    $this->getJson('/api/v1/quote-systems')->assertJsonPath('data.templates_without_system.0.id', $id);
});

it('lie une ligne de modèle à un article du stock, photo comprise, même si l\'article est supprimé ensuite', function () {
    Storage::fake('public');
    Sanctum::actingAs(userWithRole('manager'));
    $article = Article::factory()->create(['designation' => 'Caméra IP 4 MP']);
    $article->forceFill(['image_path' => 'articles/camera.jpg'])->save();

    $template = $this->postJson('/api/v1/quote-templates', [
        'name' => 'Kit caméra',
        'lines' => [['article_id' => $article->id, 'designation' => $article->designation, 'unit_cost' => 50000, 'coefficient' => 1.5]],
    ])->assertCreated()->json('data');
    expect($template['lines'][0]['article_id'])->toBe($article->id)
        ->and($template['lines'][0]['article']['designation'])->toBe('Caméra IP 4 MP')
        ->and($template['lines'][0]['article']['image_url'])->toContain('articles/camera.jpg')
        ->and($template['lines'][0]['article']['deleted'])->toBeFalse();

    $article->delete();
    $this->getJson("/api/v1/quote-templates/{$template['id']}")
        ->assertJsonPath('data.lines.0.article.deleted', true)
        ->assertJsonPath('data.lines.0.article.designation', 'Caméra IP 4 MP');

    $this->postJson('/api/v1/quote-templates', ['name' => 'X', 'lines' => [['article_id' => 999999, 'designation' => 'Y']]])
        ->assertJsonValidationErrors('lines.0.article_id');
});

it('laisse le chef de projet insérer un modèle sans voir ni gérer les coûts', function () {
    Sanctum::actingAs(userWithRole('manager'));
    $template = $this->postJson('/api/v1/quote-templates', ['name' => 'Kit', 'lines' => [['designation' => 'Caméra', 'unit_cost' => 50000, 'coefficient' => 1.5]]])->json('data.id');

    $chef = userWithRole('project_manager');
    $client = Client::factory()->create();
    Project::factory()->create(['client_id' => $client->id, 'manager_id' => $chef->id]);
    Sanctum::actingAs($chef);

    $this->getJson("/api/v1/quote-templates/{$template}")->assertOk()
        ->assertJsonPath('data.lines.0.unit_price', 75000)
        ->assertJsonMissingPath('data.lines.0.unit_cost');
    $quote = $this->postJson('/api/v1/quotes', ['client_id' => $client->id, 'title' => 'X'])->json('data');
    $this->postJson("/api/v1/quotes/{$quote['id']}/insert-template", ['template_id' => $template])
        ->assertOk()->assertJsonPath('data.sections.1.lines.0.unit_price', 75000);

    $this->postJson('/api/v1/quote-templates', ['name' => 'Y'])->assertForbidden();
    $this->postJson('/api/v1/quote-systems', ['name' => 'Y'])->assertForbidden();
    expect(QuoteTemplate::count())->toBe(1);
});

// --- Proposition technique -------------------------------------------------------------

it('écrit la proposition technique par section et règle son PDF', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = quoteWithLines();
    $section = $quote['sections'][0]['id'];

    $this->patchJson("/api/v1/quote-sections/{$section}", ['proposal' => "Caméras 4 MP avec vision de nuit 30 m.\nEnregistrement 30 jours."])
        ->assertJsonPath('data.sections.0.proposal', "Caméras 4 MP avec vision de nuit 30 m.\nEnregistrement 30 jours.");
    $this->patchJson("/api/v1/quotes/{$quote['id']}", ['proposal_options' => ['client_label' => 'Un hôtel de Dakar', 'show_prices' => false]])
        ->assertJsonPath('data.proposal_options.client_label', 'Un hôtel de Dakar')
        ->assertJsonPath('data.proposal_options.show_logo', true);

    $response = $this->get("/api/v1/quotes/{$quote['id']}/proposal-pdf")->assertOk();
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');

    // Modifiable même une fois le devis accepté
    $this->postJson("/api/v1/quotes/{$quote['id']}/status", ['status' => 'accepted']);
    $this->patchJson("/api/v1/quote-sections/{$section}", ['proposal' => 'Texte revu'])->assertOk();
    $this->patchJson("/api/v1/quote-sections/{$section}", ['title' => 'Nouveau titre'])->assertStatus(422);
});

// --- Acomptes -------------------------------------------------------------------------

it('ajoute des acomptes en montant ou en pourcentage, et calcule le reste à payer', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = quoteWithLines(); // total TTC
    $total = $quote['total'];

    $quote = $this->postJson("/api/v1/quotes/{$quote['id']}/deposits", ['label' => 'Acompte à la commande', 'percent' => 50])->assertOk()->json('data');
    expect($quote['deposits'][0]['amount'])->toBe((int) round($total / 2));

    $quote = $this->postJson("/api/v1/quotes/{$quote['id']}/deposits", ['amount' => 100000, 'due_date' => '2026-12-15'])->json('data');
    $first = $quote['deposits'][0]['id'];
    $quote = $this->patchJson("/api/v1/quote-deposits/{$first}", ['is_paid' => true])->json('data');

    expect($quote['deposits_total'])->toBe((int) round($total / 2) + 100000)
        ->and($quote['deposits_paid'])->toBe((int) round($total / 2))
        ->and($quote['remaining_amount'])->toBe($total - (int) round($total / 2))
        ->and(collect($quote['deposits'])->firstWhere('id', $first)['paid_at'])->toBe(now()->toDateString());

    $this->postJson("/api/v1/quotes/{$quote['id']}/deposits", [])
        ->assertJsonPath('errors.amount.0', "Indiquez le montant ou le pourcentage de l'acompte.");
    $this->get("/api/v1/quotes/{$quote['id']}/pdf")->assertOk();
});

// --- Dépenses -------------------------------------------------------------------------

it('enregistre les dépenses réelles avec justificatif et calcule la marge réelle (costs.view)', function () {
    Storage::fake('local');
    Sanctum::actingAs(userWithRole('sales'));
    $quote = quoteWithLines();

    $quote = $this->post("/api/v1/quotes/{$quote['id']}/expenses", [
        'label' => 'Achat caméras', 'amount' => 95000, 'file' => UploadedFile::fake()->create('facture.pdf', 50),
    ], ['Accept' => 'application/json'])->assertOk()->json('data');

    expect($quote['expenses'][0]['label'])->toBe('Achat caméras')
        ->and($quote['expenses'][0]['document']['name'])->toBe('Dépense — Achat caméras.pdf')
        ->and($quote['expenses_total'])->toEqual(95000)
        ->and($quote['real_margin'])->toBe($quote['net_amount'] - 95000);

    $this->deleteJson("/api/v1/quote-expenses/{$quote['expenses'][0]['id']}")->assertOk()->assertJsonPath('data.expenses_total', 0);
});

it('cache acomptes, dépenses et marge réelle à qui n\'a pas les droits', function () {
    $chef = userWithRole('project_manager');
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id, 'manager_id' => $chef->id]);
    Sanctum::actingAs($chef);
    $quote = $this->postJson('/api/v1/quotes', ['client_id' => $client->id, 'project_id' => $project->id, 'title' => 'X'])->json('data');

    expect($quote)->toHaveKey('deposits')->not->toHaveKey('expenses')->not->toHaveKey('real_margin');
    $this->postJson("/api/v1/quotes/{$quote['id']}/expenses", ['label' => 'X', 'amount' => 1])->assertForbidden();
});

// --- Bordereaux -----------------------------------------------------------------------

it('crée un bordereau numéroté avec les équipements du devis, sans prix, et son PDF', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = quoteWithLines();

    $quote = $this->postJson("/api/v1/quotes/{$quote['id']}/delivery-notes", ['type' => 'works', 'done' => 'Pose des caméras', 'todo' => 'Formation'])
        ->assertOk()->json('data');
    $note = $quote['delivery_notes'][0];

    expect($note['number'])->toBe('BL-'.now()->year.'-0001')
        ->and($note['type']['label'])->toBe('Bordereau de travaux')
        ->and(array_column($note['lines'], 'designation'))->toBe(['Enregistreur 8 voies', 'Caméra IP 4 MP', 'Câble RJ45'])
        ->and($note['lines'][0])->not->toHaveKey('unit_price');

    $this->patchJson("/api/v1/delivery-notes/{$note['id']}", ['lines' => [['designation' => 'Caméra IP 4 MP', 'quantity' => 1]]])
        ->assertJsonCount(1, 'data.delivery_notes.0.lines');

    $response = $this->get("/api/v1/delivery-notes/{$note['id']}/pdf")->assertOk();
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

// --- Envoi par e-mail -----------------------------------------------------------------

it('propose les adresses du client et de ses contacts pour l\'envoi', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = quoteWithLines();
    $client = Client::find($quote['client_id']);
    $client->syncPhonesAndEmails(null, [['address' => 'contact@teranga.sn', 'label' => 'Accueil']]);
    Contact::factory()->create(['client_id' => $client->id, 'firstname' => 'Awa', 'lastname' => 'Ndiaye', 'email' => 'awa@teranga.sn']);

    $this->getJson("/api/v1/quotes/{$quote['id']}/email-draft")->assertOk()
        ->assertJsonPath('data.suggestions.0.email', 'contact@teranga.sn')
        ->assertJsonPath('data.suggestions.1.name', 'Awa Ndiaye')
        ->assertJsonPath('data.subject', "Devis {$quote['number']} — Vidéosurveillance");
});

it('envoie le devis par e-mail avec le PDF, le passe à « Envoyé » et garde l\'envoi', function () {
    Mail::fake();
    $sales = userWithRole('sales', ['email' => 'moussa@yonkou.info']);
    Sanctum::actingAs($sales);
    $quote = quoteWithLines();

    $this->postJson("/api/v1/quotes/{$quote['id']}/send", [
        'to' => ['client@example.sn'], 'cc' => ['direction@example.sn'],
        'subject' => 'Votre devis', 'message' => "Bonjour,\nci-joint notre devis.", 'attach_proposal' => true,
    ])->assertOk()
        ->assertJsonPath('data.status.value', 'sent')
        ->assertJsonPath('data.emails.0.to', ['client@example.sn'])
        ->assertJsonPath('data.emails.0.attachments', [
            "Hôtel Teranga - Vidéosurveillance - {$quote['number']}.pdf",
            "Proposition technique - Hôtel Teranga - Vidéosurveillance - {$quote['number']}.pdf",
        ]);

    Mail::assertSent(QuoteMail::class, fn (QuoteMail $mail) => $mail->hasTo('client@example.sn')
        && $mail->hasCc('direction@example.sn')
        && $mail->replyToAddress === 'moussa@yonkou.info'
        && count($mail->attachments()) === 2);
});

it('refuse un envoi sans destinataire ou avec une adresse invalide', function () {
    Mail::fake();
    Sanctum::actingAs(userWithRole('sales'));
    $quote = quoteWithLines();

    $this->postJson("/api/v1/quotes/{$quote['id']}/send", ['subject' => 'X', 'message' => 'Y'])
        ->assertJsonPath('errors.to.0', 'Indiquez au moins un destinataire.');
    $this->postJson("/api/v1/quotes/{$quote['id']}/send", ['to' => ['pas-une-adresse'], 'subject' => 'X', 'message' => 'Y'])
        ->assertJsonValidationErrors('to.0');
    Mail::assertNothingSent();
});

it('réserve l\'envoi à qui peut modifier et exporter le devis', function () {
    Mail::fake();
    Sanctum::actingAs(userWithRole('sales'));
    $quote = quoteWithLines();

    Sanctum::actingAs(userWithRole('accountant'));
    $this->postJson("/api/v1/quotes/{$quote['id']}/send", ['to' => ['a@b.sn'], 'subject' => 'X', 'message' => 'Y'])->assertForbidden();
    Mail::assertNothingSent();
});

it('refuse d\'annoncer un envoi quand les e-mails ne sont pas configurés (MAIL_MAILER=log)', function () {
    config(['mail.default' => 'log']);
    Sanctum::actingAs(userWithRole('sales'));
    $quote = quoteWithLines();

    $this->postJson("/api/v1/quotes/{$quote['id']}/send", ['to' => ['client@example.sn'], 'subject' => 'X', 'message' => 'Y'])
        ->assertStatus(503)
        ->assertJsonPath('message', fn ($m) => str_contains($m, "L'envoi des e-mails n'est pas configuré sur le serveur (MAIL_MAILER=log)"));

    $this->getJson("/api/v1/quotes/{$quote['id']}")->assertJsonCount(0, 'data.emails')->assertJsonPath('data.status.value', 'draft');
});

// --- Signatures d'e-mail -------------------------------------------------------------

it('gère ses signatures, une seule par défaut, avec les variables remplies', function () {
    Company::current()->update(['name' => 'Yonkou SARL', 'website' => 'https://yonkou.info']);
    $sales = userWithRole('sales', ['firstname' => 'Moussa', 'lastname' => 'Diop', 'job_title' => 'Commercial', 'phone' => '77 000 00 00']);
    Sanctum::actingAs($sales);

    $a = $this->postJson('/api/v1/email-signatures', ['name' => 'Complète', 'body' => "Cordialement,\n{nom}\n{fonction} — {entreprise}\n{telephone}\n{site}", 'is_default' => true])
        ->assertCreated()
        ->assertJsonPath('data.preview', "Cordialement,\nMoussa Diop\nCommercial — Yonkou SARL\n77 000 00 00\nhttps://yonkou.info")
        ->json('data.id');
    $b = $this->postJson('/api/v1/email-signatures', ['name' => 'Courte', 'body' => '{prenom}', 'is_default' => true])->json('data.id');

    expect(EmailSignature::find($a)->is_default)->toBeFalse()->and(EmailSignature::find($b)->is_default)->toBeTrue();
    $this->getJson('/api/v1/email-signatures')->assertJsonCount(2, 'data')->assertJsonPath('meta.default_id', $b);
});

it('retire les lignes des variables non renseignées', function () {
    $user = userWithRole('sales', ['firstname' => 'Awa', 'lastname' => 'Ndiaye', 'job_title' => null, 'phone' => null]);
    Company::current()->update(['phone' => null]);
    $signature = new EmailSignature(['name' => 'x', 'body' => "{nom}\n{fonction}\n{telephone}\n{entreprise}"]);

    expect($signature->render($user))->toBe("Awa Ndiaye\n".Company::current()->name);
});

it('partage des signatures d\'entreprise (company.update) et cache celles des autres', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $this->postJson('/api/v1/email-signatures', ['name' => 'Commune', 'body' => 'X', 'shared' => true])->assertForbidden();
    $mine = $this->postJson('/api/v1/email-signatures', ['name' => 'Perso', 'body' => 'Y'])->json('data.id');

    Sanctum::actingAs(userWithRole('accountant'));
    $shared = $this->postJson('/api/v1/email-signatures', ['name' => 'Commune', 'body' => '{entreprise}', 'shared' => true, 'is_default' => true])
        ->assertCreated()->assertJsonPath('data.shared', true)->json('data.id');
    $this->patchJson("/api/v1/email-signatures/{$mine}", ['name' => 'Piratée'])->assertNotFound();

    // Un autre utilisateur voit la commune, et l'a par défaut s'il n'a pas la sienne
    Sanctum::actingAs(userWithRole('manager'));
    $this->getJson('/api/v1/email-signatures')->assertJsonCount(1, 'data')->assertJsonPath('meta.default_id', $shared)
        ->assertJsonPath('data.0.can_edit', false);
    $this->deleteJson("/api/v1/email-signatures/{$shared}")->assertForbidden();
});

it('ajoute la signature choisie sous le message envoyé', function () {
    Mail::fake();
    $sales = userWithRole('sales', ['firstname' => 'Moussa', 'lastname' => 'Diop']);
    Sanctum::actingAs($sales);
    $quote = quoteWithLines();
    $sig = $this->postJson('/api/v1/email-signatures', ['name' => 'Moi', 'body' => "Cordialement,\n{nom}", 'is_default' => true])->json('data.id');

    $draft = $this->getJson("/api/v1/quotes/{$quote['id']}/email-draft")->assertJsonPath('data.default_signature_id', $sig)->json('data');
    expect($draft['message'])->not->toContain('Moussa Diop')->and($draft['signatures'][0]['preview'])->toBe("Cordialement,\nMoussa Diop");

    $this->postJson("/api/v1/quotes/{$quote['id']}/send", ['to' => ['client@example.sn'], 'subject' => 'Devis', 'message' => 'Bonjour,', 'signature_id' => $sig])
        ->assertOk();

    Mail::assertSent(QuoteMail::class, fn (QuoteMail $mail) => $mail->body === "Bonjour,\n\n-- \nCordialement,\nMoussa Diop" && $mail->hasSignature);

    // Pas la signature d'un autre
    $other = EmailSignature::query()->forceCreate(['user_id' => userWithRole('sales')->id, 'name' => 'Autre', 'body' => 'Z']);
    $this->postJson("/api/v1/quotes/{$quote['id']}/send", ['to' => ['a@b.sn'], 'subject' => 'X', 'message' => 'Y', 'signature_id' => $other->id])
        ->assertStatus(422);
});

it('ajoute une image (JPEG ou PNG, 1 Mo) à sa signature et l\'intègre à l\'e-mail', function () {
    Storage::fake('public');
    Mail::fake();
    Sanctum::actingAs(userWithRole('sales'));
    $quote = quoteWithLines();
    $sig = $this->postJson('/api/v1/email-signatures', ['name' => 'Avec photo', 'body' => '{nom}', 'image_width' => 120])->json('data.id');

    $this->post("/api/v1/email-signatures/{$sig}/image", ['image' => UploadedFile::fake()->image('photo.webp')], ['Accept' => 'application/json'])
        ->assertJsonPath('errors.image.0', "L'image doit être au format JPEG ou PNG.");
    $url = $this->post("/api/v1/email-signatures/{$sig}/image", ['image' => UploadedFile::fake()->image('photo.jpg', 300, 300)], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.image_width', 120)->json('data.image_url');
    expect($url)->toContain('signatures/');

    $this->postJson("/api/v1/quotes/{$quote['id']}/send", ['to' => ['client@example.sn'], 'subject' => 'X', 'message' => 'Y', 'signature_id' => $sig])->assertOk();
    Mail::assertSent(QuoteMail::class, fn (QuoteMail $mail) => $mail->signatureImage !== null && $mail->signatureImageWidth === 120);

    $path = EmailSignature::find($sig)->image_path;
    $this->deleteJson("/api/v1/email-signatures/{$sig}/image")->assertJsonPath('data.image_url', null);
    Storage::disk('public')->assertMissing($path);
});

it('intègre vraiment l\'image dans le message envoyé', function () {
    Storage::fake('public');
    config(['mail.default' => 'array', 'mail.from' => ['address' => 'devis@yonkou.info', 'name' => 'Dash4']]);
    Log::spy();
    Sanctum::actingAs(userWithRole('sales'));
    $quote = quoteWithLines();
    $sig = $this->postJson('/api/v1/email-signatures', ['name' => 'Logo', 'body' => '{nom}'])->json('data.id');
    $this->post("/api/v1/email-signatures/{$sig}/image", ['image' => UploadedFile::fake()->image('logo.png', 200, 80)], ['Accept' => 'application/json'])->assertOk();

    $response = $this->postJson("/api/v1/quotes/{$quote['id']}/send", ['to' => ['client@example.sn'], 'subject' => 'X', 'message' => 'Y', 'signature_id' => $sig]);
    // En cas d'échec, l'erreur réelle de l'envoi est dans le journal
    Log::shouldNotHaveReceived('warning');
    $response->assertOk();

    $sent = app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
    expect($sent->getHtmlBody())->toContain('src="cid:');
});

it('envoie la proposition technique seule, sans changer le statut, avec les contacts du devis proposés en premier', function () {
    Mail::fake();
    Sanctum::actingAs(userWithRole('sales'));
    $quote = quoteWithLines();
    $architect = Contact::factory()->create(['firstname' => 'Awa', 'lastname' => 'Ndiaye', 'email' => 'awa@archi.sn']);
    $this->postJson("/api/v1/quotes/{$quote['id']}/contacts", ['contact_id' => $architect->id, 'role' => 'Architecte'])->assertCreated();

    $this->getJson("/api/v1/quotes/{$quote['id']}/email-draft?document=proposal")->assertOk()
        ->assertJsonPath('data.subject', "Proposition technique {$quote['number']} — Vidéosurveillance")
        ->assertJsonPath('data.suggestions.0.email', 'awa@archi.sn')
        ->assertJsonPath('data.suggestions.0.label', 'Architecte');

    $this->postJson("/api/v1/quotes/{$quote['id']}/send", [
        'document' => 'proposal', 'to' => ['awa@archi.sn'], 'subject' => 'Proposition', 'message' => 'Bonjour',
    ])->assertOk()
        ->assertJsonPath('data.status.value', 'draft')
        ->assertJsonPath('data.emails.0.attachments', ["Proposition technique - Hôtel Teranga - Vidéosurveillance - {$quote['number']}.pdf"]);

    Mail::assertSent(QuoteMail::class, fn (QuoteMail $mail) => $mail->hasTo('awa@archi.sn') && count($mail->attachments()) === 1);
});

it('laisse le chef de projet envoyer la proposition technique sans prix de son devis', function () {
    Mail::fake();
    $chef = userWithRole('project_manager');
    Sanctum::actingAs($chef);
    $client = Client::factory()->create();
    $quote = $this->postJson('/api/v1/quotes', ['client_id' => $client->id, 'title' => 'Alarme'])->assertCreated()->json('data');

    $this->postJson("/api/v1/quotes/{$quote['id']}/send", ['document' => 'proposal', 'to' => ['a@b.sn'], 'subject' => 'X', 'message' => 'Y'])->assertOk();

    // Le comptable voit le devis mais ne peut pas le modifier : pas d'envoi
    Sanctum::actingAs(userWithRole('accountant'));
    $this->postJson("/api/v1/quotes/{$quote['id']}/send", ['document' => 'proposal', 'to' => ['a@b.sn'], 'subject' => 'X', 'message' => 'Y'])->assertForbidden();
});
