<?php

namespace App\Support\Dash3;

use App\Enums\QuoteLineCategory;
use App\Enums\TaxMode;
use App\Models\Document;
use App\Models\Plan;
use App\Models\User;
use App\Support\TaxCalculator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use stdClass;

/**
 * Reprise des données de Dash3 (base MariaDB lue par la connexion `dash3`, en lecture seule).
 *
 * - Chaque fiche reprise garde son identifiant Dash3 dans `legacy_id` : une fiche déjà reprise n'est
 *   jamais recréée ni modifiée. On peut donc relancer la reprise pour ajouter ce qui a été saisi dans
 *   Dash3 entre-temps (période de transition).
 * - Les lignes qui pointent vers une fiche absente (lignes orphelines) sont rejetées et listées.
 * - Les fichiers (logos, photos, documents, plans) sont copiés depuis le dossier de stockage de Dash3
 *   si on le donne ; sinon la fiche est reprise sans son fichier, et le fichier est listé comme manquant.
 * - Correspondances : voir MODELE_DONNEES.md et docs/REPRISE_DASH3.md.
 */
class Dash3Importer
{
    /** Ordre de reprise (MODELE_DONNEES.md §6). */
    public const STEPS = [
        'clients', 'contacts', 'brands', 'providers', 'articles', 'projects', 'site', 'quote_templates',
        'quotes', 'invoices', 'flat_rates', 'purchases', 'transactions', 'tasks', 'journals', 'cvs', 'attachments',
    ];

    private ConnectionInterface $src;

    /** @var array<string, array<int, int>> table Dash4 → [id Dash3 → id Dash4] */
    private array $maps = [];

    /** @var array<string, array{created: int, existing: int, rejected: int}> */
    private array $counts = [];

    /** @var list<string> */
    private array $rejected = [];

    /** @var list<string> */
    private array $missingFiles = [];

    /** @var array<string, float> contrôles : totaux Dash3 / Dash4 */
    private array $checks = [];

    private int $filesCopied = 0;

    private string $now;

    /**
     * @param  string|null  $filesRoot  dossier `storage/app/public` de Dash3 (fichiers à copier)
     * @param  bool  $copyFiles  false pour un essai (rien n'est écrit sur le disque)
     */
    public function __construct(
        private User $owner,
        private ?string $filesRoot = null,
        private bool $copyFiles = true,
        string $connection = 'dash3',
    ) {
        $this->src = DB::connection($connection);
        $this->now = now()->toDateTimeString();
        $this->filesRoot = $filesRoot ? rtrim($filesRoot, '/') : null;
    }

    /** @param  list<string>  $only  étapes à faire (toutes par défaut) */
    public function run(array $only = []): array
    {
        foreach (self::STEPS as $step) {
            if ($only === [] || in_array($step, $only, true)) {
                $this->{'import'.Str::studly($step)}();
            }
        }

        return $this->report();
    }

    public function report(): array
    {
        return [
            'counts' => $this->counts,
            'rejected' => $this->rejected,
            'missing_files' => $this->missingFiles,
            'files_copied' => $this->filesCopied,
            'checks' => $this->checks,
        ];
    }

    // ─── Clients et contacts ─────────────────────────────────────────────────────

    private function importClients(): void
    {
        foreach ($this->rows('clients') as $r) {
            $this->insertOnce('clients', $r, fn () => [
                'name' => $this->text($r->name, 255) ?? 'Client sans nom',
                'type' => $this->clientType($this->v($r, 'type')),
                'status' => 'active',
                'description' => $this->text($this->v($r, 'description')),
                'address' => $this->text($this->v($r, 'address'), 255),
                'logo_path' => $this->copyPublic($this->v($r, 'avatar'), 'clients/logos', "dash3-{$r->id}"),
                'is_favorite' => (bool) $this->v($r, 'favorite'),
                'created_by' => $this->owner->id,
                'deleted_at' => $this->v($r, 'deleted_at'),
            ]);
        }
    }

    private function importContacts(): void
    {
        $projectClient = $this->src->table('projets')->pluck('client_id', 'id');
        foreach ($this->rows('contacts') as $r) {
            // Dans Dash3, client_id contient parfois l'id du projet : on reprend alors le client du projet
            $legacyClient = $this->v($r, 'client_id');
            $clientId = $this->id('clients', $legacyClient)
                ?? $this->id('clients', $projectClient[$this->v($r, 'projet_id')] ?? null)
                ?? $this->id('clients', $projectClient[$legacyClient] ?? null);
            $this->insertOnce('contacts', $r, fn () => [
                'client_id' => $clientId,
                'civility' => match ($this->v($r, 'genre')) {
                    'Femme' => 'mrs',
                    default => null, // « Homme » est la valeur par défaut de Dash3, jamais choisie
                },
                'firstname' => $this->text($r->firstname, 255) ?? '—',
                'lastname' => $this->text($this->v($r, 'lastname'), 255),
                'job_title' => $this->text($this->v($r, 'fonction'), 255),
                'company' => $this->text($this->v($r, 'societe'), 255),
                'email' => $this->text($this->v($r, 'email'), 255),
                'phone' => $this->text($this->v($r, 'phone'), 50),
                'created_by' => $this->owner->id,
                'deleted_at' => $this->v($r, 'deleted_at'),
            ]);
        }
    }

    // ─── Stock ───────────────────────────────────────────────────────────────────

    private function importBrands(): void
    {
        // Le nom d'une marque est unique dans Dash4 : les doublons de Dash3 sont fusionnés
        // (comparaison sans casse ni accents, comme la collation de MariaDB)
        $key = fn (string $name) => mb_strtolower(Str::ascii(trim($name)));
        $byName = DB::table('brands')->get(['id', 'name'])->mapWithKeys(fn ($b) => [$key($b->name) => $b->id])->all();
        foreach ($this->rows('brands') as $r) {
            $name = $this->text($r->name, 255) ?? "Marque {$r->id}";
            $nameKey = $key($name);
            if ($this->id('brands', $r->id)) {
                $this->count('brands', 'existing');

                continue;
            }
            if (isset($byName[$nameKey])) {
                $this->maps['brands'][$r->id] = $byName[$nameKey];
                $this->count('brands', 'existing');

                continue;
            }
            $id = DB::table('brands')->insertGetId([
                'name' => $name,
                'description' => $this->text($this->v($r, 'description')),
                'logo_path' => $this->copyPublic($this->v($r, 'logo'), 'brands/logos', "dash3-{$r->id}"),
                'legacy_id' => $r->id,
                ...$this->stamps($r),
            ]);
            $byName[$nameKey] = $id;
            $this->maps['brands'][$r->id] = $id;
            $this->count('brands', 'created');
        }
    }

    private function importProviders(): void
    {
        foreach ($this->rows('providers') as $r) {
            $this->insertOnce('providers', $r, fn () => [
                'name' => $this->text($r->name, 255) ?? "Fournisseur {$r->id}",
                'phone' => $this->text($this->v($r, 'phone'), 50),
                'email' => $this->text($this->v($r, 'email'), 255),
                'address' => $this->text($this->v($r, 'address')),
                'website' => $this->text($this->v($r, 'website'), 255),
                'description' => $this->text($this->v($r, 'description')),
                'logo_path' => $this->copyPublic($this->v($r, 'logo'), 'providers/logos', "dash3-{$r->id}"),
                'latitude' => $this->v($r, 'latitude'),
                'longitude' => $this->v($r, 'longitude'),
                'created_by' => $this->owner->id,
                'deleted_at' => $this->v($r, 'deleted_at'),
            ]);
        }
    }

    private function importArticles(): void
    {
        foreach ($this->rows('articles') as $r) {
            $quantity = (float) ($this->v($r, 'quantity') ?? 0);
            $id = $this->insertOnce('articles', $r, fn () => [
                'designation' => $this->text($r->designation, 255) ?? "Article {$r->id}",
                'reference' => $this->text($this->v($r, 'reference'), 100),
                'description' => $this->join([
                    $this->text($this->v($r, 'description')),
                    $this->labelled('Technique', $this->v($r, 'technique')),
                    $this->labelled('Caractéristiques', $this->v($r, 'spec')),
                ]),
                'category' => QuoteLineCategory::fromLegacy((int) ($this->v($r, 'priority_id') ?? -1))->value,
                'brand_id' => $this->id('brands', $this->v($r, 'brand_id')),
                'provider_id' => $this->id('providers', $this->v($r, 'provider_id')),
                'unit_cost' => (float) ($this->v($r, 'price') ?? 0),
                'coefficient' => 1,
                'quantity' => $quantity,
                'min_quantity' => ((float) ($this->v($r, 'quantity_min') ?? 0)) > 0 ? (float) $r->quantity_min : null,
                'weight' => $this->v($r, 'weight'),
                'image_path' => $this->copyPublic($this->v($r, 'image'), 'articles/images', "dash3-{$r->id}"),
                'is_active' => true,
                'created_by' => $this->owner->id,
                'deleted_at' => $this->v($r, 'deleted_at'),
            ]);
            // Stock de départ : un mouvement d'inventaire explique la quantité reprise
            if ($id && $quantity != 0) {
                DB::table('stock_movements')->insert([
                    'article_id' => $id, 'type' => 'inventory', 'quantity' => $quantity, 'quantity_after' => $quantity,
                    'unit_cost' => (float) ($this->v($r, 'price') ?? 0), 'reason' => 'Reprise du stock de Dash3',
                    'created_by' => $this->owner->id, 'moved_at' => $this->now, 'created_at' => $this->now, 'updated_at' => $this->now,
                ]);
            }
        }

        foreach ($this->rows('article_dependences') as $r) {
            $a = $this->id('articles', $r->article_id);
            $d = $this->id('articles', $r->dependence_id);
            if (! $a || ! $d || $a === $d) {
                $this->reject('article_dependences', $r->id, 'article inconnu');

                continue;
            }
            DB::table('article_dependencies')->insertOrIgnore([
                'article_id' => $a, 'dependency_id' => $d, 'quantity' => (float) ($this->v($r, 'quantity') ?? 1), ...$this->stamps($r),
            ]);
        }
    }

    // ─── Projets et chantier ─────────────────────────────────────────────────────

    private function importProjects(): void
    {
        // Pas de statut de projet dans Dash3 : terminé si tous ses devis sont terminés ou annulés
        $quoteStatuses = $this->hasTable('invoices')
            ? $this->src->table('invoices')->whereNull('deleted_at')->get(['projet_id', 'statut'])->groupBy('projet_id')
            : collect();
        foreach ($this->rows('projets') as $r) {
            $clientId = $this->id('clients', $r->client_id);
            if (! $clientId) {
                $this->reject('projets', $r->id, 'client inconnu');

                continue;
            }
            $statuses = ($quoteStatuses[$r->id] ?? collect())->pluck('statut')->map(fn ($s) => trim((string) $s));
            $done = $statuses->isNotEmpty() && $statuses->every(fn ($s) => in_array($s, ['Terminé', 'Annulé'], true));
            $this->insertOnce('projects', $r, fn () => [
                'client_id' => $clientId,
                'name' => $this->text($r->name, 255) ?? "Projet {$r->id}",
                'description' => $this->text($this->v($r, 'description')),
                'status' => $done ? 'completed' : 'in_progress',
                'start_date' => $this->v($r, 'start_date'),
                'end_date' => $this->v($r, 'end_date'),
                'address' => $this->text($this->v($r, 'address'), 255),
                'latitude' => $this->v($r, 'latitude'),
                'longitude' => $this->v($r, 'longitude'),
                'logo_path' => $this->copyPublic($this->v($r, 'logo'), 'projects/logos', "dash3-{$r->id}"),
                'is_favorite' => (bool) $this->v($r, 'favorite'),
                'created_by' => $this->owner->id,
                'deleted_at' => $this->v($r, 'deleted_at'),
            ]);
        }
    }

    private function importSite(): void
    {
        foreach ($this->rows('buildings') as $r) {
            $project = $this->id('projects', $r->projet_id);
            if (! $project) {
                $this->reject('buildings', $r->id, 'projet inconnu');

                continue;
            }
            $this->insertOnce('buildings', $r, fn () => [
                'project_id' => $project, 'name' => $this->text($r->name, 255) ?? 'Bâtiment',
                'description' => $this->text($this->v($r, 'description')), 'position' => (int) ($this->v($r, 'order') ?? 0),
            ], deletedAt: false);
        }
        foreach ($this->rows('stages') as $r) {
            $building = $this->id('buildings', $r->building_id);
            if (! $building) {
                $this->reject('stages', $r->id, 'bâtiment inconnu');

                continue;
            }
            $this->insertOnce('levels', $r, fn () => [
                'building_id' => $building, 'name' => $this->text($r->name, 255) ?? 'Niveau',
                'description' => $this->text($this->v($r, 'description')), 'position' => (int) ($this->v($r, 'order') ?? 0),
            ], deletedAt: false);
        }
        foreach ($this->rows('rooms') as $r) {
            $level = $this->id('levels', $r->stage_id);
            if (! $level) {
                $this->reject('rooms', $r->id, 'niveau inconnu');

                continue;
            }
            $this->insertOnce('rooms', $r, fn () => [
                'level_id' => $level, 'name' => $this->text($r->name, 255) ?? 'Pièce',
                'description' => $this->text($this->v($r, 'description')), 'position' => (int) ($this->v($r, 'order') ?? 0),
            ], deletedAt: false);
        }
        foreach ($this->rows('systemes') as $r) {
            $this->insertOnce('systems', $r, fn () => [
                'name' => $this->text($r->name, 255) ?? 'Système', 'icon' => $this->text($this->v($r, 'icon'), 60),
                'description' => $this->text($this->v($r, 'description'), 255),
            ], deletedAt: false);
        }
        foreach ($this->rows('installation_systemes') as $r) {
            $project = $this->id('projects', $r->projet_id);
            if (! $project) {
                $this->reject('installation_systemes', $r->id, 'projet inconnu');

                continue;
            }
            $this->insertOnce('installed_systems', $r, fn () => [
                'project_id' => $project, 'building_id' => $this->id('buildings', $this->v($r, 'building_id')),
                'system_id' => $this->id('systems', $this->v($r, 'systeme_id')), 'name' => $this->text($r->name, 255) ?? 'Système',
                'description' => $this->text($this->v($r, 'description')), 'position' => (int) ($this->v($r, 'order') ?? 0),
            ], deletedAt: false);
        }
        $this->importEquipment();

        // Anciennes « installations » et leurs objets : fusionnés avec les systèmes installés et équipements.
        // Leur legacy_id est décalé (+1 000 000) pour ne pas se mêler aux systèmes installés de Dash3.
        $systemNames = $this->hasTable('systemes') ? $this->src->table('systemes')->pluck('name', 'id') : collect();
        foreach ($this->rows('installations') as $r) {
            $project = $this->id('projects', $r->projet_id);
            if (! $project) {
                $this->reject('installations', $r->id, 'projet inconnu');

                continue;
            }
            $systemLegacy = is_numeric($this->v($r, 'type')) ? (int) $r->type : null;
            $name = $this->text($systemNames[$systemLegacy] ?? null, 255);
            $description = $this->text($this->v($r, 'description'));
            $fake = (object) ['id' => 1_000_000 + $r->id, 'created_at' => $this->v($r, 'created_at'), 'updated_at' => $this->v($r, 'updated_at')];
            $this->insertOnce('installed_systems', $fake, fn () => [
                'project_id' => $project, 'system_id' => $this->id('systems', $systemLegacy),
                'name' => $name ?? ($description && $description !== '_' ? Str::limit($description, 250) : 'Installation'),
                'description' => $description === '_' ? null : $description, 'position' => 100 + $r->id,
            ], deletedAt: false);
        }
        $attributes = $this->hasTable('attributs') ? $this->src->table('attributs')->get()->groupBy('objet_id') : collect();
        $objects = $this->rows('objets')->sortBy(fn ($o) => [$o->parent_id ? 1 : 0, $o->id]);
        foreach ($objects as $r) {
            $system = $this->id('installed_systems', 1_000_000 + (int) $this->v($r, 'installation_id'));
            if (! $system) {
                $this->reject('objets', $r->id, 'installation inconnue');

                continue;
            }
            $characteristics = [];
            foreach ((array) json_decode((string) $this->v($r, 'attributs'), true) as $k => $v) {
                if (is_scalar($v) && ! is_int($k)) {
                    $characteristics[$k] = (string) $v;
                }
            }
            foreach ($attributes[$r->id] ?? [] as $a) {
                if ($this->text($a->name)) {
                    $characteristics[$this->text($a->name)] = (string) $a->value;
                }
            }
            $fake = (object) ['id' => 1_000_000 + $r->id, 'created_at' => $this->v($r, 'created_at'), 'updated_at' => $this->v($r, 'updated_at')];
            $this->insertOnce('equipment', $fake, fn () => [
                'installed_system_id' => $system,
                'parent_id' => $this->v($r, 'parent_id') ? $this->id('equipment', 1_000_000 + (int) $r->parent_id) : null,
                'article_id' => $this->id('articles', $this->v($r, 'article_id')),
                'name' => $this->text($r->name, 255) ?? 'Équipement', 'type' => $this->text($this->v($r, 'type'), 100),
                'quantity' => 1, 'status' => 'installed', 'characteristics' => $characteristics ? json_encode($characteristics, JSON_UNESCAPED_UNICODE) : null,
                'description' => $this->text($this->v($r, 'description')), 'position' => $r->id,
            ], deletedAt: false);
        }

        foreach ($this->rows('plans') as $r) {
            $project = $this->id('projects', $r->projet_id);
            if (! $project) {
                $this->reject('plans', $r->id, 'projet inconnu');

                continue;
            }
            if ($this->id('plans', $r->id)) {
                $this->count('plans', 'existing');

                continue;
            }
            $file = $this->copyPrivate($this->v($r, 'file'), "plans/{$project}", Plan::class);
            if (! $file) {
                $this->reject('plans', $r->id, 'fichier du plan absent');

                continue;
            }
            $this->insertOnce('plans', $r, fn () => [
                'project_id' => $project, 'building_id' => $this->id('buildings', $this->v($r, 'building_id')),
                'name' => $this->text($r->name, 255) ?? 'Plan', 'path' => $file['path'],
                'mime' => $this->v($r, 'mime') ?: $file['mime'], 'kind' => $this->v($r, 'kind') === 'pdf' ? 'pdf' : 'image',
                'size' => $file['size'], 'created_by' => $this->owner->id,
            ], deletedAt: false);
        }
        foreach ($this->rows('plan_markers') as $r) {
            $plan = $this->id('plans', $r->plan_id);
            if (! $plan) {
                $this->reject('plan_markers', $r->id, 'plan inconnu');

                continue;
            }
            $this->insertOnce('plan_markers', $r, fn () => [
                'plan_id' => $plan, 'equipment_id' => $this->id('equipment', $this->v($r, 'equipement_id')),
                'page' => max(1, (int) ($this->v($r, 'page') ?? 1)), 'x' => (float) $r->x, 'y' => (float) $r->y,
                'label' => $this->text($this->v($r, 'label'), 255), 'color' => $this->text($this->v($r, 'color'), 20),
            ], deletedAt: false);
        }

        $buildingProject = DB::table('buildings')->whereNotNull('legacy_id')->pluck('project_id', 'id');
        foreach ($this->rows('fiches') as $r) {
            $building = $this->id('buildings', $r->building_id);
            if (! $building) {
                $this->reject('fiches', $r->id, 'bâtiment inconnu');

                continue;
            }
            $sheet = $this->insertOnce('sheets', $r, fn () => [
                'project_id' => $buildingProject[$building], 'building_id' => $building,
                'title' => $this->text($this->v($r, 'titre'), 255) ?? 'Fiche', 'type' => $this->text($this->v($r, 'type'), 100),
                'date' => $this->v($r, 'date'), 'contact_phone' => $this->text($this->v($r, 'phone'), 50),
                'contact_email' => $this->text($this->v($r, 'email'), 255),
                'notes' => $this->labelled('Client', $this->v($r, 'client')), 'created_by' => $this->owner->id,
            ], deletedAt: false);
            if ($sheet) {
                foreach ($this->src->table('fiche_zones')->where('fiche_id', $r->id)->orderBy('order')->orderBy('id')->get() as $z) {
                    DB::table('sheet_zones')->insert([
                        'sheet_id' => $sheet, 'position' => (int) ($z->order ?? 0), 'number' => $this->text((string) $z->number, 50) ?? '—',
                        'name' => $this->text($z->name, 255) ?? '—', 'code' => $this->text($z->code, 100),
                        'equipment_label' => $this->text($z->equipement, 255), 'legacy_id' => $z->id, ...$this->stamps($z),
                    ]);
                }
            }
        }
    }

    private function importEquipment(): void
    {
        // Parents d'abord
        $rows = $this->rows('equipements')->sortBy(fn ($e) => [$e->parent_id ? 1 : 0, $e->id]);
        foreach ($rows as $r) {
            $system = $this->id('installed_systems', $r->installation_systeme_id);
            if (! $system) {
                $this->reject('equipements', $r->id, 'système installé inconnu');

                continue;
            }
            $characteristics = json_decode((string) $this->v($r, 'caracteristiques'), true);
            $this->insertOnce('equipment', $r, fn () => [
                'installed_system_id' => $system,
                'parent_id' => $this->v($r, 'parent_id') ? $this->id('equipment', $r->parent_id) : null,
                'article_id' => $this->id('articles', $this->v($r, 'article_id')),
                'level_id' => $this->id('levels', $this->v($r, 'stage_id')), 'room_id' => $this->id('rooms', $this->v($r, 'room_id')),
                'name' => $this->text($r->name, 255) ?? 'Équipement', 'type' => $this->text($this->v($r, 'type'), 100),
                'model' => $this->text($this->v($r, 'modele'), 255), 'quantity' => max(1, (int) ($this->v($r, 'quantite') ?? 1)),
                'status' => 'installed', 'characteristics' => is_array($characteristics) && $characteristics ? json_encode($characteristics, JSON_UNESCAPED_UNICODE) : null,
                'description' => $this->text($this->v($r, 'description')), 'position' => (int) ($this->v($r, 'order') ?? 0),
            ], deletedAt: false);
        }
    }

    // ─── Devis ───────────────────────────────────────────────────────────────────

    private function importQuoteTemplates(): void
    {
        foreach ($this->rows('invoice_systems') as $r) {
            $this->insertOnce('quote_systems', $r, fn () => [
                'name' => $this->text($r->name, 255) ?? 'Système', 'description' => $this->text($this->v($r, 'description')),
                'icon' => $this->text($this->v($r, 'icon'), 50),
            ], deletedAt: false);
        }
        foreach ($this->rows('invoice_models') as $r) {
            $id = $this->insertOnce('quote_templates', $r, fn () => [
                'quote_system_id' => $this->id('quote_systems', $this->v($r, 'invoice_system_id')),
                'name' => $this->text($r->name, 255) ?? 'Modèle', 'description' => $this->text($this->v($r, 'description')),
                'icon' => $this->text($this->v($r, 'icon'), 50), 'created_by' => $this->owner->id,
            ], deletedAt: false);
            if (! $id) {
                continue;
            }
            foreach ($this->src->table('invoice_model_rows')->where('invoice_model_id', $r->id)->orderBy('id')->get()->values() as $i => $l) {
                DB::table('quote_template_lines')->insert([
                    'quote_template_id' => $id, 'position' => $i, 'article_id' => $this->id('articles', $l->article_id),
                    'designation' => $this->text($l->designation, 255) ?? $this->text($l->description, 255) ?? '—',
                    'reference' => $this->ref($l->reference), 'category' => QuoteLineCategory::fromLegacy((int) $l->priorite_id)->value,
                    'quantity' => (float) $l->quantite, 'unit_cost' => (float) $l->prix, 'coefficient' => (float) ($l->coef ?: 1),
                    ...$this->stamps($l),
                ]);
            }
        }
    }

    private function importQuotes(): void
    {
        $projectClient = DB::table('projects')->pluck('client_id', 'id');
        $sections = $this->src->table('invoice_sections')->whereNull('deleted_at')->orderBy('ordre')->orderBy('id')->get()->groupBy('invoice_id');
        $lines = $this->src->table('invoice_rows')->whereNull('deleted_at')->orderBy('id')->get()->groupBy('invoice_section_id');
        $dash3Total = 0;
        $dash4Total = 0;

        foreach ($this->rows('invoices') as $r) {
            $project = $this->id('projects', $r->projet_id);
            if (! $project) {
                $this->reject('invoices', $r->id, 'projet inconnu');

                continue;
            }
            $tax = mb_strtolower(trim((string) $this->v($r, 'tax'))) === 'brs' ? TaxMode::Brs : (((float) $this->v($r, 'tax')) > 0 ? TaxMode::Vat : TaxMode::None);
            $discountRate = round(((float) ($this->v($r, 'remise') ?? 0)) * ((float) $r->remise <= 1 ? 100 : 1), 2);
            $quoteId = $this->insertOnce('quotes', $r, fn () => [
                'number' => $this->uniqueNumber('quotes', $this->text($r->reference, 50) ?? "DEV-D3-{$r->id}"),
                'client_id' => $projectClient[$project],
                'project_id' => $project,
                'title' => $this->text($r->description, 255) ?? $this->text($r->reference, 255) ?? 'Devis',
                'payment_terms' => $this->text($this->v($r, 'modalite')),
                'notes' => $this->text($this->v($r, 'note')),
                'status' => $this->quoteStatus($r->statut),
                'issued_at' => $this->v($r, 'emitdate') ?: substr((string) ($r->created_at ?? $this->now), 0, 10),
                'tax_mode' => $tax->value,
                'vat_rate' => 18,
                'brs_rate' => 5,
                'discount_rate' => $discountRate,
                'is_favorite' => (bool) $this->v($r, 'favorite'),
                'created_by' => $this->owner->id,
                'deleted_at' => $this->v($r, 'deleted_at'),
            ]);
            if (! $quoteId) {
                continue;
            }

            $subtotal = 0;
            $cost = 0.0;
            foreach ($sections[$r->id] ?? [] as $si => $s) {
                $visible = (bool) ($s->show ?? true);
                $sectionId = DB::table('quote_sections')->insertGetId([
                    'quote_id' => $quoteId, 'title' => $this->text($s->section, 255) ?? 'Section', 'position' => $si,
                    'is_visible' => $visible, 'proposal' => $this->text($s->proposition ?? null), 'legacy_id' => $s->id, ...$this->stamps($s),
                ]);
                foreach (($lines[$s->id] ?? collect())->values() as $li => $l) {
                    $unitCost = (float) $l->prix;
                    $coef = (float) ($l->coef ?: 1);
                    $unitPrice = (int) round($unitCost * $coef);
                    $lineTotal = (int) round($unitPrice * (float) $l->quantite);
                    if ($l->article_id && ! $this->id('articles', $l->article_id)) {
                        $this->reject('invoice_rows', $l->id, 'article inconnu : ligne reprise sans lien vers l\'article');
                    }
                    DB::table('quote_lines')->insert([
                        'quote_section_id' => $sectionId, 'position' => $li, 'article_id' => $this->id('articles', $l->article_id),
                        'designation' => $this->text($l->designation, 255) ?? '—', 'reference' => $this->ref($l->reference),
                        'comment' => $this->text($l->comment), 'category' => QuoteLineCategory::fromLegacy((int) $l->priorite_id)->value,
                        'quantity' => (float) $l->quantite, 'unit_cost' => $unitCost, 'coefficient' => $coef,
                        'unit_price' => $unitPrice, 'line_total' => $lineTotal, 'legacy_id' => $l->id, ...$this->stamps($l),
                    ]);
                    if ($visible) {
                        $subtotal += $lineTotal;
                        $cost += $unitCost * (float) $l->quantite;
                    }
                }
            }
            $discount = (int) round($subtotal * $discountRate / 100);
            $totals = TaxCalculator::totals($subtotal - $discount, $tax, 18, 5);
            DB::table('quotes')->where('id', $quoteId)->update([
                'subtotal' => $subtotal, 'discount_amount' => $discount, 'tax_amount' => $totals['tax'],
                'total' => $totals['total'], 'cost_total' => round($cost, 2),
            ]);
            $dash3Total += $subtotal;
            $dash4Total += (int) DB::table('quote_lines')->join('quote_sections', 'quote_sections.id', '=', 'quote_lines.quote_section_id')
                ->where('quote_sections.quote_id', $quoteId)->where('quote_sections.is_visible', true)->sum('line_total');

            $this->importQuoteChildren($r->id, $quoteId);
        }
        // Acomptes, dépenses, bordereaux et documents dont le devis n'existe pas (ou a été rejeté) : listés
        foreach (['invoice_acomptes' => 'acompte', 'invoice_spents' => 'dépense', 'invoice_bls' => 'bordereau', 'invoice_documents' => 'document'] as $table => $label) {
            foreach ($this->rows($table) as $child) {
                if (! $this->v($child, 'deleted_at') && ! $this->id('quotes', $this->v($child, 'invoice_id'))) {
                    $this->reject($table, $child->id, "{$label} d'un devis inconnu (#{$this->v($child, 'invoice_id')})");
                }
            }
        }
        $this->checks['Devis repris : total HT avant remise (Dash3)'] = $dash3Total;
        $this->checks['Devis repris : total HT avant remise (Dash4)'] = $dash4Total;
    }

    /** Acomptes, dépenses réelles, bordereaux et documents d'un devis tout juste repris. */
    private function importQuoteChildren(int $legacyQuote, int $quoteId): void
    {
        foreach ($this->src->table('invoice_acomptes')->where('invoice_id', $legacyQuote)->whereNull('deleted_at')->orderBy('id')->get() as $a) {
            DB::table('quote_deposits')->insert([
                'quote_id' => $quoteId, 'label' => $this->text($a->name, 255) ?? 'Acompte', 'description' => $this->text($a->description),
                'amount' => (int) round((float) $a->montant), 'due_date' => $a->date, 'is_paid' => (bool) $a->statut,
                'paid_at' => $a->statut ? $a->date : null, 'show_on_pdf' => (bool) ($a->show ?? true),
                'created_by' => $this->owner->id, 'legacy_id' => $a->id, ...$this->stamps($a),
            ]);
            $this->count('quote_deposits', 'created');
        }
        foreach ($this->src->table('invoice_spents')->where('invoice_id', $legacyQuote)->whereNull('deleted_at')->orderBy('id')->get() as $e) {
            // Dépenses réelles d'un devis : des dépenses validées, rattachées au devis et à son projet
            $expenseId = DB::table('expenses')->insertGetId([
                'quote_id' => $quoteId, 'project_id' => DB::table('quotes')->where('id', $quoteId)->value('project_id'),
                'label' => $this->text($e->name, 255) ?? 'Dépense', 'description' => $this->text($e->description),
                'amount' => (int) round((float) $e->montant), 'spent_at' => $e->date, 'is_paid' => mb_strtolower((string) $e->status) !== 'prevue',
                'status' => 'approved', 'decided_at' => $e->created_at ?? $this->now, 'decided_by' => $this->owner->id,
                'created_by' => $this->owner->id, 'legacy_id' => $e->id, ...$this->stamps($e),
            ]);
            // Décalage de l'identifiant : un devis a aussi des documents venant de invoice_documents
            if ($doc = $this->document('quote', $quoteId, $e->file ?? null, $this->text($e->name, 255) ?? 'Justificatif', 'invoice_spents', 10_000_000 + $e->id)) {
                DB::table('expenses')->where('id', $expenseId)->update(['document_id' => $doc]);
            }
            $this->count('expenses', 'created');
        }
        foreach ($this->src->table('invoice_bls')->where('invoice_id', $legacyQuote)->orderBy('id')->get() as $b) {
            DB::table('delivery_notes')->insert([
                'number' => $this->uniqueNumber('delivery_notes', "BL-D3-{$b->id}"), 'quote_id' => $quoteId,
                'type' => mb_strtolower((string) $b->type) === 'livraison' ? 'delivery' : 'works',
                'title' => $this->text($b->name, 255) ?? 'Bordereau', 'delivered_at' => $b->date,
                'done' => $this->text($b->done), 'todo' => $this->text($b->todo), 'show_logo' => (bool) ($b->logo ?? false),
                'created_by' => $this->owner->id, 'legacy_id' => $b->id, ...$this->stamps($b),
            ]);
            $this->count('delivery_notes', 'created');
        }
        foreach ($this->src->table('invoice_documents')->where('invoice_id', $legacyQuote)->orderBy('id')->get() as $d) {
            $this->document('quote', $quoteId, $d->file, $this->text($d->name, 255) ?? $this->text($d->type, 255) ?? 'Document', 'invoice_documents', $d->id);
        }
    }

    private function importInvoices(): void
    {
        $sum = 0;
        foreach ($this->rows('factures') as $r) {
            $quoteId = $this->id('quotes', $r->invoice_id);
            $quote = $quoteId ? DB::table('quotes')->find($quoteId) : null;
            if (! $quote) {
                $this->reject('factures', $r->id, 'devis inconnu');

                continue;
            }
            $amount = (int) $r->montant;
            $paid = mb_strtolower((string) $r->status) === 'paye';
            $date = $this->v($r, 'date') ?: substr((string) $r->created_at, 0, 10);
            $title = $this->text($r->description, 255) ?? $quote->title;
            $id = $this->insertOnce('invoices', $r, fn () => [
                'number' => $this->uniqueNumber('invoices', $this->text($r->reference, 50) ?? "FAC-D3-{$r->id}"),
                'quote_id' => $quoteId, 'client_id' => $quote->client_id, 'project_id' => $quote->project_id,
                'title' => $title, 'scope' => 'all', 'percent' => 100, 'status' => $paid ? 'paid' : 'issued',
                'issued_at' => $date, 'tax_mode' => 'none', 'subtotal' => $amount, 'tax_amount' => 0, 'total' => $amount,
                'paid_amount' => $paid ? $amount : 0, 'paid_at' => $paid ? $date : null,
                'issued_by' => $this->owner->id, 'created_by' => $this->owner->id,
            ], deletedAt: false);
            if (! $id) {
                continue;
            }
            DB::table('invoice_lines')->insert([
                'invoice_id' => $id, 'position' => 0, 'designation' => $title, 'quantity' => 1,
                'unit_price' => $amount, 'line_total' => $amount, ...$this->stamps($r),
            ]);
            if ($paid) {
                DB::table('invoice_payments')->insert([
                    'invoice_id' => $id, 'amount' => $amount, 'paid_at' => $date, 'method' => 'other',
                    'note' => 'Reprise de Dash3 (moyen de paiement non renseigné)', 'created_by' => $this->owner->id, ...$this->stamps($r),
                ]);
            }
            $sum += $amount;
        }
        $this->checks['Factures reprises : montant total'] = $sum;
    }

    private function importFlatRates(): void
    {
        foreach ($this->rows('forfaits') as $r) {
            $this->insertOnce('flat_rates', $r, fn () => [
                'client_id' => $this->id('clients', $this->v($r, 'client_id')), 'article_id' => $this->id('articles', $this->v($r, 'article_id')),
                'designation' => $this->text($r->designation, 255) ?? 'Forfait', 'description' => $this->text($this->v($r, 'description')),
                'price' => (float) ($this->v($r, 'price') ?? 0),
            ], deletedAt: false);
        }
    }

    // ─── Achats et comptabilité ──────────────────────────────────────────────────

    private function importPurchases(): void
    {
        $unknownProvider = null;
        foreach ($this->rows('achats') as $r) {
            $provider = $this->id('providers', $this->v($r, 'provider_id'));
            if (! $provider) {
                $unknownProvider ??= DB::table('providers')->where('name', 'Fournisseur non renseigné (Dash3)')->value('id')
                    ?? DB::table('providers')->insertGetId(['name' => 'Fournisseur non renseigné (Dash3)', 'created_at' => $this->now, 'updated_at' => $this->now]);
                $provider = $unknownProvider;
            }
            $rows = $this->src->table('achat_rows')->where('achat_id', $r->id)->whereNull('deleted_at')->orderBy('id')->get();
            $received = in_array(trim((string) $r->status), ['Acheté', 'Achete', 'Reçu'], true);
            $discount = (float) ($this->v($r, 'remise') ?? 0);
            $rate = $discount > 0 && $discount < 1 ? $discount : 0;
            $vat = $rows->contains(fn ($l) => (float) $l->tva > 0);
            $subtotal = (int) round($rows->sum(fn ($l) => (float) $l->prix * (int) $l->quantite) * (1 - $rate));
            $totals = TaxCalculator::totals($subtotal, $vat ? TaxMode::Vat : TaxMode::None, 18);
            $id = $this->insertOnce('purchases', $r, fn () => [
                'number' => $this->uniqueNumber('purchases', "BC-D3-{$r->id}"), 'provider_id' => $provider,
                'title' => $this->text($r->name, 255), 'status' => $received ? 'received' : 'draft',
                'ordered_at' => $this->v($r, 'date'), 'received_at' => $received ? ($this->v($r, 'date') ?: $r->created_at) : null,
                'tax_mode' => $vat ? 'vat' : 'none', 'vat_rate' => 18, 'subtotal' => $subtotal, 'tax_amount' => $totals['tax'],
                'total' => $totals['total'],
                'notes' => $this->join([$this->text($this->v($r, 'description')), $rate ? 'Remise Dash3 de '.round($rate * 100).' % comprise dans le total.' : null]),
                'created_by' => $this->owner->id, 'deleted_at' => $this->v($r, 'deleted_at'),
            ]);
            if (! $id) {
                continue;
            }
            foreach ($rows->values() as $i => $l) {
                DB::table('purchase_lines')->insert([
                    'purchase_id' => $id, 'position' => $i, 'article_id' => $this->id('articles', $l->article_id),
                    'designation' => $this->text($l->designation, 255) ?? '—', 'reference' => $this->ref($l->reference),
                    'quantity' => (int) $l->quantite, 'received_quantity' => $received ? (int) $l->quantite : 0,
                    'unit_cost' => (float) $l->prix, 'line_total' => (int) round((float) $l->prix * (int) $l->quantite), ...$this->stamps($l),
                ]);
            }
            foreach ($this->src->table('achat_factures')->where('achat_id', $r->id)->get() as $f) {
                $this->document('purchase', $id, $f->folder, 'Facture fournisseur', 'achat_factures', $f->id);
            }
        }
    }

    private function importTransactions(): void
    {
        foreach ($this->rows('transactions') as $r) {
            $this->insertOnce('transactions', $r, fn () => [
                'direction' => mb_strtolower((string) $r->type) === 'debit' ? 'out' : 'in',
                'label' => $this->text($this->v($r, 'objet'), 255) ?? 'Transaction', 'amount' => (int) round((float) $r->montant),
                'transacted_at' => $this->v($r, 'date') ?: substr((string) $r->created_at, 0, 10),
                'project_id' => $this->id('projects', $this->v($r, 'projet_id')), 'note' => $this->text($this->v($r, 'description')),
                'created_by' => $this->owner->id,
            ], deletedAt: false);
        }
    }

    // ─── Tâches et journaux ──────────────────────────────────────────────────────

    private function importTasks(): void
    {
        foreach ($this->rows('tasks') as $r) {
            $project = $this->id('projects', $this->v($r, 'projet_id'));
            $status = match ((int) $this->v($r, 'statut_id')) {
                2 => 'in_progress', 3 => 'blocked', 4 => 'done', default => 'todo',
            };
            $this->insertOnce('tasks', $r, fn () => [
                'title' => $this->text($r->name, 255) ?? 'Tâche', 'description' => $this->text($this->v($r, 'description')),
                'status' => $status, 'priority' => match ((int) $this->v($r, 'priority_id')) { 1 => 'low', 3 => 'high', default => 'normal' },
                'project_id' => $project,
                'client_id' => $this->id('clients', $this->v($r, 'client_id')) ?? ($project ? DB::table('projects')->where('id', $project)->value('client_id') : null),
                'quote_id' => $this->id('quotes', $this->v($r, 'devis_id')),
                'starts_at' => $this->v($r, 'start_date'), 'ends_at' => $this->v($r, 'end_date'),
                'due_date' => $this->v($r, 'expiration_date') ? substr((string) $r->expiration_date, 0, 10) : null,
                'completed_at' => $status === 'done' ? $r->updated_at : null,
                'completed_by' => $status === 'done' ? $this->owner->id : null,
                'created_by' => $this->owner->id,
            ], deletedAt: false);
        }
    }

    private function importJournals(): void
    {
        $teams = $this->hasTable('teams') ? $this->src->table('teams')->get()->keyBy('id') : collect();
        foreach ($this->rows('journals') as $r) {
            $project = $this->id('projects', $this->v($r, 'projet_id'));
            $id = $this->insertOnce('journals', $r, fn () => [
                'title' => $this->text($r->title, 255) ?? 'Rapport',
                'type' => $this->journalType($this->v($r, 'type')),
                'date' => $this->v($r, 'date') ?: substr((string) $r->created_at, 0, 10),
                'project_id' => $project,
                'client_id' => $this->id('clients', $this->v($r, 'client_id')) ?? ($project ? DB::table('projects')->where('id', $project)->value('client_id') : null),
                'quote_id' => $this->id('quotes', $this->v($r, 'devis_id')),
                'summary' => $this->text($this->v($r, 'description')),
                'created_by' => $this->owner->id,
                'deleted_at' => $this->v($r, 'deleted_at'),
            ]);
            if (! $id) {
                continue;
            }
            foreach ($this->src->table('journal_sections')->where('journal_id', $r->id)->orderBy('order')->orderBy('id')->get()->values() as $i => $s) {
                DB::table('journal_sections')->insert([
                    'journal_id' => $id, 'position' => $i, 'title' => $this->text($s->name, 255) ?? 'Section',
                    'body' => $this->text($s->description), ...$this->stamps($s),
                ]);
            }
            foreach ($this->src->table('journal_intervenants')->where('journal_id', $r->id)->orderBy('id')->get() as $p) {
                $team = $p->team_id ? ($teams[$p->team_id] ?? null) : null;
                $contact = $this->id('contacts', $p->contact_id);
                if (! $contact && ! $team) {
                    continue;
                }
                DB::table('journal_participants')->insert([
                    'journal_id' => $id, 'contact_id' => $contact,
                    'name' => $team ? trim("{$team->firstname} {$team->lastname}") : null,
                    'role' => $team ? $this->text($team->function ?? null, 255) : null, ...$this->stamps($p),
                ]);
            }
        }
    }

    // ─── Espace personnel ────────────────────────────────────────────────────────

    private function importCvs(): void
    {
        // Dash3 n'a qu'un compte (l'administrateur) : ses CV vont au compte qui reprend les données
        $types = ['experience' => 'experience', 'formation' => 'education', 'competence' => 'skills', 'certification' => 'certifications', 'langue' => 'languages', 'projet' => 'projects'];
        foreach ($this->rows('c_v_s') as $r) {
            $id = $this->insertOnce('cvs', $r, fn () => [
                'user_id' => $this->owner->id, 'name' => $this->text($r->name, 255) ?? 'CV', 'title' => $this->text($this->v($r, 'title'), 255),
                'fullname' => $this->text($this->v($r, 'fullname'), 255), 'summary' => $this->text($this->v($r, 'summary')),
                'photo_path' => $this->copyPublic($this->v($r, 'photo'), 'cvs', "dash3-{$r->id}"),
                'phone' => $this->text($this->v($r, 'phone'), 50), 'email' => $this->text($this->v($r, 'email'), 255),
                'address' => $this->text($this->v($r, 'address'), 255), 'website' => $this->text($this->v($r, 'website'), 255),
                'accent_color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $this->v($r, 'accent_color')) ? $r->accent_color : '#1690b7',
                'font' => in_array($this->v($r, 'font'), ['sans', 'serif', 'mono'], true) ? $r->font : 'sans',
                'layout' => $this->v($r, 'layout') === 'une_colonne' ? 'one_column' : 'two_columns',
                'show_photo' => (bool) ($this->v($r, 'show_photo') ?? true), 'shared' => false,
            ], deletedAt: false);
            if (! $id) {
                continue;
            }
            foreach ($this->src->table('cv_sections')->where('c_v_id', $r->id)->orderBy('order')->orderBy('id')->get()->values() as $i => $s) {
                $sectionId = DB::table('cv_sections')->insertGetId([
                    'cv_id' => $id, 'name' => $this->text($s->name, 255) ?? 'Section', 'type' => $types[$s->type] ?? 'free', 'position' => $i, ...$this->stamps($s),
                ]);
                foreach ($this->src->table('cv_items')->where('cv_section_id', $s->id)->orderBy('order')->orderBy('id')->get()->values() as $j => $item) {
                    DB::table('cv_items')->insert([
                        'cv_section_id' => $sectionId, 'title' => $this->text($item->title, 255) ?? '—', 'subtitle' => $this->text($item->subtitle, 255),
                        'place' => $this->text($item->place, 255), 'start_date' => $this->text($item->start_date, 20), 'end_date' => $this->text($item->end_date, 20),
                        'current' => (bool) $item->current, 'description' => $this->text($item->description),
                        'level' => $item->level ? max(1, min(5, (int) $item->level)) : null, 'position' => $j, ...$this->stamps($item),
                    ]);
                }
            }
        }
    }

    // ─── Notes, liens et documents (tables polymorphes) ──────────────────────────

    private function importAttachments(): void
    {
        $notes = [
            ['projet_notes', 'projet_id', 'projects', 'project', 'titre', 'description'],
            ['brand_notes', 'brand_id', 'brands', 'brand', 'title', 'description'],
            ['client_notes', 'client_id', 'clients', 'client', 'title', 'description'],
        ];
        foreach ($notes as [$table, $fk, $parentTable, $morph, $titleCol, $bodyCol]) {
            foreach ($this->rows($table) as $r) {
                $parent = $this->id($parentTable, $this->v($r, $fk));
                if (! $parent) {
                    $this->reject($table, $r->id, 'fiche inconnue');

                    continue;
                }
                $body = $this->join([$this->text($this->v($r, $titleCol)), $this->text(strip_tags((string) $this->v($r, $bodyCol)))]);
                if (! $body) {
                    continue;
                }
                $this->insertAttachment('notes', 'noteable', $morph, $parent, $r, ['body' => $body, 'is_pinned' => false, 'created_by' => $this->owner->id]);
            }
        }

        $links = [
            ['article_links', 'article_id', 'articles', 'article', 'name', 'link'],
            ['brand_links', 'brand_id', 'brands', 'brand', 'name', 'link'],
            ['provider_links', 'provider_id', 'providers', 'provider', 'name', 'link'],
            ['building_documents', 'building_id', 'buildings', 'building', 'name', 'link'],
        ];
        foreach ($links as [$table, $fk, $parentTable, $morph, $labelCol, $urlCol]) {
            foreach ($this->rows($table) as $r) {
                $url = $this->text($this->v($r, $urlCol), 2048);
                $parent = $this->id($parentTable, $this->v($r, $fk));
                if (! $url || ! preg_match('#^https?://#i', $url)) {
                    continue;
                }
                if (! $parent) {
                    $this->reject($table, $r->id, 'fiche inconnue');

                    continue;
                }
                $this->insertAttachment('links', 'linkable', $morph, $parent, $r, ['label' => $this->text($this->v($r, $labelCol), 255), 'url' => $url, 'created_by' => $this->owner->id]);
            }
        }

        $documents = [
            ['article_documents', 'article_id', 'articles', 'article', 'name', 'folder'],
            ['building_documents', 'building_id', 'buildings', 'building', 'name', 'folder'],
            ['brand_links', 'brand_id', 'brands', 'brand', 'name', 'folder'],
            ['provider_documents', 'provider_id', 'providers', 'provider', 'name', 'folder'],
            ['project_docs', 'projet_id', 'projects', 'project', 'document_name', 'document_path'],
        ];
        foreach ($documents as [$table, $fk, $parentTable, $morph, $nameCol, $pathCol]) {
            foreach ($this->rows($table) as $r) {
                $path = $this->v($r, $pathCol);
                if (! $this->text($path) || $path === '_') {
                    continue;
                }
                $parent = $this->id($parentTable, $this->v($r, $fk));
                if (! $parent) {
                    $this->reject($table, $r->id, 'fiche inconnue');

                    continue;
                }
                $this->document($morph, $parent, $path, $this->text($this->v($r, $nameCol), 255) ?? basename((string) $path), $table, $r->id);
            }
        }
    }

    // ─── Outils ──────────────────────────────────────────────────────────────────

    /**
     * Insère la fiche si son legacy_id n'existe pas encore ; renvoie l'id Dash4 si elle vient d'être créée,
     * null si elle existait déjà (ses lignes enfants ne sont alors pas reprises une deuxième fois).
     */
    private function insertOnce(string $table, stdClass $r, \Closure $values, bool $deletedAt = true): ?int
    {
        if ($this->id($table, $r->id)) {
            $this->count($table, 'existing');

            return null;
        }
        $data = $values() + ['legacy_id' => $r->id] + $this->stamps($r);
        if (! $deletedAt) {
            unset($data['deleted_at']);
        }
        $id = DB::table($table)->insertGetId($data);
        $this->maps[$table][$r->id] = $id;
        $this->count($table, 'created');

        return $id;
    }

    private function insertAttachment(string $table, string $morph, string $type, int $parent, stdClass $r, array $values): void
    {
        $exists = DB::table($table)->where("{$morph}_type", $type)->where('legacy_id', $r->id)->exists();
        if ($exists) {
            $this->count($table, 'existing');

            return;
        }
        DB::table($table)->insert($values + ["{$morph}_type" => $type, "{$morph}_id" => $parent, 'legacy_id' => $r->id] + $this->stamps($r));
        $this->count($table, 'created');
    }

    /** Document rattaché à une fiche, fichier copié dans le stockage privé ; renvoie son id. */
    private function document(string $morph, int $parent, ?string $source, string $name, string $legacyTable, int $legacyId): ?int
    {
        if (! $this->text($source) || $source === '_') {
            return null;
        }
        $existing = DB::table('documents')->where('documentable_type', $morph)->where('documentable_id', $parent)->where('legacy_id', $legacyId)->value('id');
        if ($existing) {
            $this->count('documents', 'existing');

            return $existing;
        }
        $file = $this->copyPrivate($source, "documents/dash3/{$morph}/{$parent}", Document::class, "{$legacyTable} #{$legacyId}");
        if (! $file) {
            return null;
        }
        $id = DB::table('documents')->insertGetId([
            'documentable_type' => $morph, 'documentable_id' => $parent, 'name' => $name, 'path' => $file['path'],
            'mime_type' => $file['mime'], 'size' => $file['size'], 'created_by' => $this->owner->id, 'legacy_id' => $legacyId,
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        $this->count('documents', 'created');

        return $id;
    }

    /** Fichier Dash3 → disque public de Dash4 (logos, photos) ; renvoie le chemin, ou null. */
    private function copyPublic(?string $source, string $dir, string $basename): ?string
    {
        $abs = $this->locate($source);
        if (! $abs) {
            return null;
        }
        $path = "{$dir}/{$basename}.".(pathinfo($abs, PATHINFO_EXTENSION) ?: 'jpg');
        if ($this->copyFiles) {
            Storage::disk('public')->put($path, file_get_contents($abs));
            $this->filesCopied++;
        }

        return $path;
    }

    /** @return array{path: string, size: int, mime: string}|null */
    private function copyPrivate(?string $source, string $dir, string $modelClass, ?string $label = null): ?array
    {
        $abs = $this->locate($source, $label);
        if (! $abs) {
            return null;
        }
        $path = $dir.'/'.Str::limit(Str::slug(pathinfo($abs, PATHINFO_FILENAME)), 80, '').'-'.Str::random(6).'.'.(pathinfo($abs, PATHINFO_EXTENSION) ?: 'bin');
        if ($this->copyFiles) {
            Storage::disk($modelClass::DISK)->put($path, file_get_contents($abs));
            $this->filesCopied++;
        }

        return ['path' => $path, 'size' => (int) filesize($abs), 'mime' => (function_exists('mime_content_type') ? mime_content_type($abs) : null) ?: 'application/octet-stream'];
    }

    /** Chemin réel d'un fichier Dash3 (« storage/… » = dossier storage/app/public de Dash3). */
    private function locate(?string $source, ?string $label = null): ?string
    {
        $source = $this->text($source);
        if (! $source || $source === '_') {
            return null;
        }
        if (! $this->filesRoot) {
            $this->missingFiles[] = ($label ? "{$label} : " : '').$source;

            return null;
        }
        $relative = preg_replace('#^/?(public/)?(storage/)?#', '', $source);
        foreach ([$this->filesRoot.'/'.$relative, $this->filesRoot.'/'.ltrim($source, '/')] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        $this->missingFiles[] = ($label ? "{$label} : " : '').$source;

        return null;
    }

    /** Id Dash4 d'une fiche Dash3 déjà reprise (cette fois-ci ou lors d'une reprise précédente). */
    private function id(string $table, mixed $legacyId): ?int
    {
        if ($legacyId === null || $legacyId === '' || ! is_numeric($legacyId)) {
            return null;
        }
        if (! isset($this->maps[$table])) {
            $this->maps[$table] = DB::table($table)->whereNotNull('legacy_id')->pluck('id', 'legacy_id')->map(fn ($v) => (int) $v)->all();
        }

        return $this->maps[$table][(int) $legacyId] ?? null;
    }

    private function rows(string $table): \Illuminate\Support\Collection
    {
        return $this->hasTable($table) ? $this->src->table($table)->orderBy('id')->get() : collect();
    }

    private function hasTable(string $table): bool
    {
        return Schema::connection($this->src->getName())->hasTable($table);
    }

    private function v(stdClass $r, string $column): mixed
    {
        return property_exists($r, $column) ? $r->{$column} : null;
    }

    /** Texte nettoyé (espaces, apostrophes échappées par Dash3), coupé à la longueur de la colonne ; null si vide. */
    private function text(mixed $value, ?int $max = null): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim(str_replace("\\'", "'", (string) $value));
        if ($text === '') {
            return null;
        }

        return $max ? mb_substr($text, 0, $max) : $text;
    }

    /** Référence d'une ligne : « _ » veut dire « aucune » dans Dash3. */
    private function ref(mixed $value): ?string
    {
        $text = $this->text($value);

        return $text === '_' ? null : $text;
    }

    private function labelled(string $label, mixed $value): ?string
    {
        $text = $this->text($value);

        return $text ? "{$label} : {$text}" : null;
    }

    private function join(array $parts): ?string
    {
        $parts = array_values(array_filter($parts, fn ($p) => $p !== null && $p !== ''));

        return $parts ? implode("\n\n", $parts) : null;
    }

    private function stamps(stdClass $r): array
    {
        return ['created_at' => $this->v($r, 'created_at') ?? $this->now, 'updated_at' => $this->v($r, 'updated_at') ?? $this->v($r, 'created_at') ?? $this->now];
    }

    private function uniqueNumber(string $table, string $number): string
    {
        $candidate = $number;
        for ($i = 2; DB::table($table)->where('number', $candidate)->exists(); $i++) {
            $candidate = mb_substr($number, 0, 44)."-{$i}";
        }

        return $candidate;
    }

    private function count(string $table, string $kind): void
    {
        $this->counts[$table] ??= ['created' => 0, 'existing' => 0, 'rejected' => 0];
        $this->counts[$table][$kind]++;
    }

    private function reject(string $table, int|string $id, string $reason): void
    {
        $this->count($table, 'rejected');
        $this->rejected[] = "{$table} #{$id} : {$reason}";
    }

    private function clientType(?string $type): string
    {
        return match (mb_strtolower(trim((string) $type))) {
            'particulier' => 'individual',
            'administration', 'etat', 'état', 'public' => 'public_body',
            'association', 'ong' => 'association',
            default => 'company',
        };
    }

    /** Statuts de Dash3 (suivi des travaux) → statuts commerciaux de Dash4. */
    public static function quoteStatus(?string $status): string
    {
        return match (mb_strtolower(trim((string) $status))) {
            'nouveau' => 'draft',
            'proforma', 'en pause' => 'sent',
            'annulé', 'annule' => 'cancelled',
            'refusé', 'refuse' => 'refused',
            // En cours, À facturer, Paiement en attente, BL à faire, Terminé : le client a accepté
            default => 'accepted',
        };
    }

    public static function journalType(?string $type): string
    {
        $t = mb_strtolower(trim((string) $type));

        return match (true) {
            str_contains($t, 'travaux'), str_contains($t, 'mise en service') => 'installation',
            str_contains($t, 'intervention'), str_contains($t, 'récupération') => 'intervention',
            str_contains($t, 'visite') => 'visit',
            str_contains($t, 'maintenance') => 'maintenance',
            str_contains($t, 'dépannage'), str_contains($t, 'réparation') => 'repair',
            default => 'other',
        };
    }
}
