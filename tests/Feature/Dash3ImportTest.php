<?php

use App\Support\Dash3\Dash3Importer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Dash3Schema;

/** Base Dash3 de test : les vraies colonnes (Dash3Schema), quelques lignes représentatives des cas rencontrés. */
function dash3(string $table, array $rows): void
{
    // Toutes les lignes d'un même insert doivent avoir les mêmes colonnes
    $keys = array_fill_keys(array_merge(...array_map('array_keys', $rows)), null);
    foreach ($rows as $row) {
        DB::connection('dash3')->table($table)->insert(array_replace($keys, $row) + ['created_at' => '2025-06-01 10:00:00', 'updated_at' => '2025-06-02 10:00:00']);
    }
}

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
    config(['database.connections.dash3' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
    DB::purge('dash3');
    foreach (Dash3Schema::TABLES as $name => $columns) {
        Schema::connection('dash3')->create($name, function (Blueprint $t) use ($columns) {
            foreach ($columns as $column => $type) {
                if ($column === 'id') {
                    $t->unsignedBigInteger('id')->primary();
                } else {
                    match ($type) {
                        'i' => $t->bigInteger($column)->nullable(),
                        'f' => $t->float($column)->nullable(),
                        default => $t->text($column)->nullable(),
                    };
                }
            }
        });
    }

    $this->files = sys_get_temp_dir().'/dash3-files-'.uniqid();
    foreach (['erp/clients/1/logo.png', 'stock/articles/5/images/camera.jpg', 'stock/5/document/manuel.pdf', 'erp/invoices/100/bc.pdf', 'erp/projets/10/plans/plan.pdf'] as $file) {
        @mkdir(dirname("{$this->files}/{$file}"), 0777, true);
        file_put_contents("{$this->files}/{$file}", 'contenu '.$file);
    }

    dash3('clients', [
        ['id' => 1, 'name' => 'Banque Atlantique', 'type' => 'Entreprise', 'status' => 1, 'avatar' => 'storage/erp/clients/1/logo.png', 'favorite' => 1],
        ['id' => 2, 'name' => 'Mrs Fall', 'type' => 'Particulier', 'status' => 1, 'description' => ''],
    ]);
    dash3('projets', [
        ['id' => 10, 'client_id' => 1, 'name' => 'Siège', 'favorite' => 0],
        ['id' => 11, 'client_id' => 2, 'name' => 'Villa', 'favorite' => 0],
    ]);
    dash3('contacts', [
        ['id' => 1, 'genre' => 'Homme', 'firstname' => 'Moussa', 'lastname' => 'Diop', 'client_id' => 1, 'phone' => '77 000 00 00'],
        // Bogue de Dash3 : client_id contient l'id du projet
        ['id' => 2, 'genre' => 'Homme', 'firstname' => 'M', 'lastname' => 'Badji', 'client_id' => 11, 'projet_id' => 11],
    ]);
    dash3('brands', [['id' => 1, 'name' => 'Hikvision'], ['id' => 2, 'name' => 'hikvision ']]);
    dash3('providers', [['id' => 1, 'name' => 'Sonatel', 'phone' => 771234567]]);
    dash3('articles', [
        ['id' => 5, 'designation' => 'Caméra dôme', 'reference' => 'DS-2CD', 'priority_id' => 2, 'brand_id' => 2, 'provider_id' => 1, 'price' => 30000, 'quantity' => 4, 'quantity_min' => 1, 'image' => 'storage/stock/articles/5/images/camera.jpg', 'spec' => '4 MP'],
        ['id' => 6, 'designation' => 'Câble SYT', 'priority_id' => 5, 'price' => 700, 'quantity' => 0, 'quantity_min' => 0],
    ]);
    dash3('article_dependences', [['id' => 1, 'article_id' => 5, 'dependence_id' => 6, 'quantity' => 2]]);
    dash3('article_links', [['id' => 1, 'article_id' => 5, 'name' => 'Fiche', 'link' => 'https://example.com/camera']]);
    dash3('article_documents', [['id' => 1, 'article_id' => 5, 'name' => 'Manuel', 'folder' => 'storage/stock/5/document/manuel.pdf']]);

    dash3('invoices', [
        ['id' => 100, 'projet_id' => 10, 'reference' => 'BAN-100-25', 'description' => 'Vidéo surveillance', 'statut' => 'Terminé', 'tax' => '0', 'remise' => 0.1, 'favorite' => 0, 'comptabilise' => 0],
        ['id' => 101, 'projet_id' => 11, 'reference' => 'FAL-101-25', 'description' => 'Alarme', 'statut' => 'Proforma', 'tax' => 'brs', 'remise' => 0, 'favorite' => 0, 'comptabilise' => 0],
        ['id' => 102, 'projet_id' => 999, 'reference' => 'XXX-102-25', 'description' => 'Orphelin', 'statut' => 'Nouveau', 'tax' => '0', 'remise' => 0, 'favorite' => 0, 'comptabilise' => 0],
    ]);
    dash3('invoice_sections', [
        ['id' => 1, 'invoice_id' => 100, 'section' => 'Caméras', 'ordre' => 1, 'show' => 1, 'status' => 0],
        ['id' => 2, 'invoice_id' => 100, 'section' => 'Option', 'ordre' => 2, 'show' => 0, 'status' => 0],
        ['id' => 3, 'invoice_id' => 101, 'section' => 'Supprimée', 'ordre' => 1, 'show' => 1, 'status' => 0, 'deleted_at' => '2025-07-01 00:00:00'],
    ]);
    dash3('invoice_rows', [
        ['id' => 1, 'invoice_section_id' => 1, 'designation' => 'Caméra dôme', 'coef' => 1.3, 'reference' => '_', 'quantite' => 2, 'prix' => 30000, 'priorite_id' => 2, 'article_id' => 5],
        ['id' => 2, 'invoice_section_id' => 1, 'designation' => 'Pose', 'coef' => 1, 'reference' => 'MO', 'quantite' => 3, 'prix' => 10000, 'priorite_id' => 7, 'article_id' => 999],
        ['id' => 3, 'invoice_section_id' => 2, 'designation' => 'Disque', 'coef' => 1, 'reference' => '_', 'quantite' => 1, 'prix' => 5000, 'priorite_id' => 6],
    ]);
    dash3('invoice_acomptes', [['id' => 1, 'invoice_id' => 100, 'name' => 'Acompte', 'montant' => '50000', 'statut' => 1, 'date' => '2025-06-05', 'show' => 1]]);
    dash3('invoice_spents', [['id' => 1, 'invoice_id' => 100, 'name' => 'Quincaillerie', 'montant' => '8000', 'date' => '2025-06-06', 'status' => 'achete']]);
    dash3('invoice_bls', [['id' => 1, 'invoice_id' => 100, 'name' => 'BL', 'date' => '2025-06-10', 'type' => 'travaux', 'done' => 'Pose des caméras', 'logo' => 0]]);
    dash3('invoice_documents', [
        ['id' => 1, 'invoice_id' => 100, 'name' => 'Bon de commande', 'type' => 'Bon de commande', 'file' => '_'],
        ['id' => 2, 'invoice_id' => 100, 'name' => 'BC signé', 'type' => 'Bon de commande', 'file' => 'storage/erp/invoices/100/bc.pdf'],
    ]);
    dash3('factures', [['id' => 1, 'invoice_id' => 100, 'status' => 'paye', 'reference' => 'BAN-100-25', 'description' => 'Siège', 'montant' => 97200, 'date' => '2025-06-20']]);
    dash3('invoice_systems', [['id' => 1, 'name' => 'Vidéo', 'icon' => 'camera']]);
    dash3('invoice_models', [['id' => 1, 'invoice_system_id' => 1, 'name' => 'Kit 4 caméras']]);
    dash3('invoice_model_rows', [['id' => 1, 'invoice_model_id' => 1, 'designation' => null, 'description' => 'Câble', 'coef' => 1.2, 'reference' => '_', 'quantite' => 50, 'prix' => 700, 'priorite_id' => 5, 'article_id' => 6]]);
    dash3('forfaits', [['id' => 1, 'designation' => 'Diagnostic', 'price' => 30000, 'client_id' => 1]]);

    dash3('achats', [['id' => 1, 'provider_id' => null, 'name' => 'Câble', 'date' => '2025-06-03', 'status' => 'Acheté', 'remise' => 0.2]]);
    dash3('achat_rows', [['id' => 1, 'achat_id' => 1, 'designation' => 'Câble SYT', 'quantite' => 10, 'prix' => 1000, 'tva' => 0.18, 'article_id' => 6]]);
    dash3('transactions', [['id' => 1, 'projet_id' => 10, 'objet' => 'Main-d\'œuvre', 'type' => 'credit', 'montant' => 70000, 'date' => '2025-06-15']]);

    dash3('tasks', [
        ['id' => 1, 'projet_id' => 10, 'client_id' => 1, 'name' => 'Câbler le local', 'priority_id' => 3, 'statut_id' => 4, 'favoris' => 0],
        ['id' => 2, 'name' => 'Commander les disques', 'priority_id' => 1, 'statut_id' => 1, 'favoris' => 0],
    ]);
    dash3('teams', [['id' => 1, 'firstname' => 'Lamine', 'lastname' => 'Niabaly', 'function' => 'Technicien']]);
    dash3('journals', [['id' => 1, 'user_id' => 8, 'projet_id' => 10, 'title' => 'Début des travaux', 'date' => '2025-09-03', 'type' => 'Rapport de travaux ']]);
    dash3('journal_sections', [['id' => 1, 'journal_id' => 1, 'name' => 'Vidéo', 'description' => 'Pose de 2 caméras', 'order' => 1]]);
    dash3('journal_intervenants', [['id' => 1, 'journal_id' => 1, 'contact_id' => 1], ['id' => 2, 'journal_id' => 1, 'team_id' => 1]]);

    dash3('buildings', [['id' => 1, 'projet_id' => 10, 'name' => 'Bâtiment A', 'order' => 1]]);
    dash3('stages', [['id' => 1, 'building_id' => 1, 'name' => 'RDC', 'order' => 0]]);
    dash3('rooms', [['id' => 1, 'stage_id' => 1, 'name' => 'Accueil', 'order' => 1]]);
    dash3('systemes', [['id' => 1, 'name' => "Contrôle d\\'accès ", 'icon' => 'lock']]);
    dash3('installation_systemes', [['id' => 1, 'projet_id' => 10, 'building_id' => 1, 'systeme_id' => 1, 'name' => 'Contrôle d\'accès', 'order' => 0]]);
    dash3('equipements', [
        ['id' => 2, 'installation_systeme_id' => 1, 'parent_id' => 1, 'stage_id' => 1, 'room_id' => 1, 'name' => 'Lecteur', 'modele' => 'PS70F', 'quantite' => 1, 'caracteristiques' => '{"AdresseIP":"192.168.1.1"}', 'order' => 2],
        ['id' => 1, 'installation_systeme_id' => 1, 'name' => 'Centrale', 'quantite' => 1, 'order' => 1],
    ]);
    dash3('installations', [['id' => 1, 'projet_id' => 10, 'type' => '1', 'description' => 'Galaxy Flex']]);
    dash3('objets', [
        ['id' => 1, 'installation_id' => 1, 'name' => 'Centrale', 'attributs' => '[]'],
        ['id' => 2, 'installation_id' => 1, 'parent_id' => 1, 'name' => 'Caméra', 'attributs' => '[]'],
    ]);
    dash3('attributs', [['id' => 1, 'objet_id' => 2, 'name' => 'Adresse IP', 'value' => '10.1.1.128']]);
    dash3('plans', [['id' => 1, 'projet_id' => 10, 'building_id' => 1, 'name' => 'Plan RDC', 'file' => 'storage/erp/projets/10/plans/plan.pdf', 'mime' => 'application/pdf', 'kind' => 'pdf']]);
    dash3('plan_markers', [['id' => 1, 'plan_id' => 1, 'equipement_id' => 2, 'page' => 1, 'x' => 0.27, 'y' => 0.35]]);
    dash3('fiches', [['id' => 1, 'building_id' => 1, 'titre' => 'Fiche incendie', 'type' => 'teletek_ssi_pdf', 'client' => 'Banque Atlantique']]);
    dash3('fiche_zones', [['id' => 1, 'fiche_id' => 1, 'order' => 1, 'equipement' => 'Déclencheur manuel', 'name' => 'Entrée', 'number' => '7']]);

    dash3('projet_notes', [['id' => 1, 'projet_id' => 10, 'titre' => 'Accès', 'description' => 'Badge à l\'accueil']]);
    dash3('brand_notes', [['id' => 1, 'brand_id' => 1, 'title' => 'Logiciel', 'description' => '<a href="https://x">iVMS</a>']]);
    dash3('building_documents', [['id' => 1, 'building_id' => 1, 'name' => 'Dossier', 'link' => 'https://sharepoint.example.com/x']]);
    dash3('c_v_s', [['id' => 1, 'user_id' => 8, 'name' => 'CV technique', 'accent_color' => '#1690b7', 'font' => 'sans', 'layout' => 'deux_colonnes', 'show_photo' => 1]]);
    dash3('cv_sections', [['id' => 1, 'c_v_id' => 1, 'name' => 'Langues', 'type' => 'langue', 'order' => 1]]);
    dash3('cv_items', [['id' => 1, 'cv_section_id' => 1, 'title' => 'Anglais', 'current' => 0, 'level' => 4, 'order' => 0]]);
});

it('reprend les données de Dash3 avec les cas difficiles', function () {
    $owner = userWithRole('admin');
    $report = sys_get_temp_dir().'/dash3-report-'.uniqid().'.json';

    $this->artisan('dash3:import', ['--files' => $this->files, '--report' => $report])->assertSuccessful();

    // Clients, contacts (client retrouvé par le projet), marques fusionnées
    expect(DB::table('clients')->count())->toBe(2);
    $bank = DB::table('clients')->where('legacy_id', 1)->first();
    expect($bank->type)->toBe('company')->and((bool) $bank->is_favorite)->toBeTrue();
    Storage::disk('public')->assertExists($bank->logo_path);
    expect(DB::table('clients')->where('legacy_id', 2)->value('type'))->toBe('individual');
    expect(DB::table('contacts')->where('legacy_id', 2)->value('client_id'))->toBe(DB::table('clients')->where('legacy_id', 2)->value('id'));
    expect(DB::table('contacts')->where('legacy_id', 1)->value('civility'))->toBeNull();
    expect(DB::table('brands')->count())->toBe(1);

    // Articles : marque fusionnée, catégorie, stock de départ par un inventaire, dépendance
    $camera = DB::table('articles')->where('legacy_id', 5)->first();
    expect($camera->brand_id)->toBe(DB::table('brands')->value('id'))->and($camera->category)->toBe('device')
        ->and((float) $camera->quantity)->toBe(4.0)->and($camera->description)->toContain('4 MP');
    expect(DB::table('stock_movements')->where('article_id', $camera->id)->value('type'))->toBe('inventory');
    expect(DB::table('article_dependencies')->count())->toBe(1);

    // Devis : prix = coût × coefficient, section masquée hors total, remise avant taxe, orphelin rejeté
    $quote = DB::table('quotes')->where('legacy_id', 100)->first();
    expect($quote->number)->toBe('BAN-100-25')->and($quote->status)->toBe('accepted')
        ->and((int) $quote->subtotal)->toBe(108000)->and((int) $quote->discount_amount)->toBe(10800)->and((int) $quote->total)->toBe(97200);
    $line = DB::table('quote_lines')->where('legacy_id', 1)->first();
    expect((int) $line->unit_price)->toBe(39000)->and($line->reference)->toBeNull()->and($line->article_id)->toBe($camera->id);
    expect(DB::table('quote_lines')->where('legacy_id', 2)->value('article_id'))->toBeNull();
    $brs = DB::table('quotes')->where('legacy_id', 101)->first();
    expect($brs->tax_mode)->toBe('brs')->and($brs->status)->toBe('sent');
    expect(DB::table('quote_sections')->where('quote_id', $brs->id)->count())->toBe(0);
    expect(DB::table('quotes')->where('legacy_id', 102)->exists())->toBeFalse();
    expect(DB::table('quote_deposits')->where('quote_id', $quote->id)->value('amount'))->toBe(50000);
    expect(DB::table('quote_expenses')->where('quote_id', $quote->id)->count())->toBe(1);
    expect(DB::table('delivery_notes')->where('quote_id', $quote->id)->value('type'))->toBe('works');
    expect(DB::table('documents')->where('documentable_type', 'quote')->where('documentable_id', $quote->id)->count())->toBe(1);
    expect(DB::table('quote_template_lines')->value('designation'))->toBe('Câble');

    // Facture payée, achat (fournisseur manquant, remise, TVA), transaction
    $invoice = DB::table('invoices')->where('legacy_id', 1)->first();
    expect($invoice->status)->toBe('paid')->and((int) $invoice->total)->toBe(97200)->and($invoice->quote_id)->toBe($quote->id);
    expect(DB::table('invoice_payments')->where('invoice_id', $invoice->id)->sum('amount'))->toEqual(97200);
    $purchase = DB::table('purchases')->where('legacy_id', 1)->first();
    expect($purchase->status)->toBe('received')->and((int) $purchase->subtotal)->toBe(8000)->and((int) $purchase->total)->toBe(9440);
    expect(DB::table('providers')->where('id', $purchase->provider_id)->value('name'))->toBe('Fournisseur non renseigné (Dash3)');
    expect(DB::table('transactions')->value('direction'))->toBe('in');

    // Tâches, journaux et intervenants
    $task = DB::table('tasks')->where('legacy_id', 1)->first();
    expect($task->status)->toBe('done')->and($task->priority)->toBe('high')->and($task->completed_at)->not->toBeNull();
    $journal = DB::table('journals')->where('legacy_id', 1)->first();
    expect($journal->type)->toBe('installation')->and($journal->client_id)->toBe($bank->id);
    expect(DB::table('journal_participants')->where('journal_id', $journal->id)->pluck('name')->filter()->values()->all())->toBe(['Lamine Niabaly']);

    // Chantier : systèmes, équipements (parent avant enfant), anciennes installations fusionnées, plan et repère, fiche
    expect(DB::table('systems')->value('name'))->toBe("Contrôle d'accès");
    $reader = DB::table('equipment')->where('legacy_id', 2)->first();
    expect($reader->parent_id)->toBe(DB::table('equipment')->where('legacy_id', 1)->value('id'))
        ->and(json_decode($reader->characteristics, true))->toBe(['AdresseIP' => '192.168.1.1']);
    expect(DB::table('installed_systems')->count())->toBe(2);
    $camera2 = DB::table('equipment')->where('legacy_id', 1_000_002)->first();
    expect(json_decode($camera2->characteristics, true))->toBe(['Adresse IP' => '10.1.1.128'])
        ->and($camera2->parent_id)->toBe(DB::table('equipment')->where('legacy_id', 1_000_001)->value('id'));
    $plan = DB::table('plans')->where('legacy_id', 1)->first();
    Storage::disk('local')->assertExists($plan->path);
    expect(DB::table('plan_markers')->value('equipment_id'))->toBe($reader->id);
    expect(DB::table('sheet_zones')->value('number'))->toBe('7');

    // Notes, liens, documents ; CV de l'administrateur
    expect(DB::table('notes')->where('noteable_type', 'project')->value('body'))->toContain('Accès');
    expect(DB::table('notes')->where('noteable_type', 'brand')->value('body'))->not->toContain('<a');
    expect(DB::table('links')->count())->toBe(2);
    expect(DB::table('documents')->where('documentable_type', 'article')->count())->toBe(1);
    $cv = DB::table('cvs')->first();
    expect($cv->user_id)->toBe($owner->id)->and($cv->layout)->toBe('two_columns');
    expect(DB::table('cv_sections')->value('type'))->toBe('languages');

    // Rapport : l'orphelin et la ligne sans article sont listés
    $json = json_decode(file_get_contents($report), true);
    expect(implode("\n", $json['rejected']))->toContain('invoices #102')->toContain('invoice_rows #2');
    expect($json['missing_files'])->toBe([]);

    // Relancée : rien n'est créé deux fois
    $before = collect(['clients', 'quotes', 'quote_lines', 'stock_movements', 'documents', 'notes', 'links', 'equipment', 'journal_sections', 'cv_items'])
        ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);
    dash3('clients', [['id' => 3, 'name' => 'Nouveau client', 'type' => 'Entreprise', 'status' => 1]]);
    $this->artisan('dash3:import', ['--files' => $this->files])->assertSuccessful();
    foreach ($before as $table => $count) {
        expect(DB::table($table)->count())->toBe($table === 'clients' ? $count + 1 : $count);
    }
});

it('ne garde rien après un essai', function () {
    userWithRole('admin');
    $this->artisan('dash3:import', ['--dry-run' => true])->assertSuccessful()->expectsOutputToContain('Essai terminé');
    expect(DB::table('clients')->count())->toBe(0)->and(DB::table('quotes')->count())->toBe(0);
    Storage::disk('public')->assertDirectoryEmpty('/');
});

it('convertit les statuts et types de Dash3', function () {
    expect(Dash3Importer::quoteStatus('Nouveau'))->toBe('draft')
        ->and(Dash3Importer::quoteStatus('En Pause'))->toBe('sent')
        ->and(Dash3Importer::quoteStatus('A Facturer'))->toBe('accepted')
        ->and(Dash3Importer::quoteStatus('Annulé'))->toBe('cancelled');
    expect(Dash3Importer::journalType("rapport d'intervention "))->toBe('intervention')
        ->and(Dash3Importer::journalType('Rapport de visite'))->toBe('visit')
        ->and(Dash3Importer::journalType(null))->toBe('other');
});
