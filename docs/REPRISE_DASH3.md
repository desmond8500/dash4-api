# Reprise des données de Dash3

Commande : `php artisan dash3:import`. Elle lit la base de Dash3 (sans jamais y écrire) et crée les fiches dans Dash4.

## Principe

- **Sans doublon, relançable.** Chaque fiche reprise garde son identifiant Dash3 (`legacy_id`). Une fiche déjà reprise n'est ni recréée ni modifiée. Pendant la période de transition, relancer la commande ajoute seulement ce qui a été saisi dans Dash3 depuis la dernière reprise.
- **Essai d'abord.** Avec `--dry-run`, tout est repris puis annulé : on lit le rapport sans rien écrire.
- **Fichiers.** Avec `--files`, les logos, photos, documents et plans sont copiés depuis le stockage de Dash3. Sans cette option, les fiches sont reprises sans leurs fichiers ; on peut relancer plus tard avec `--files` pour les fiches nouvelles seulement (voir « Limites »).
- **Lignes orphelines** (qui pointent vers une fiche absente) : rejetées et listées dans le rapport.
- **Auteur.** Dash3 n'a qu'un compte. Les fiches reprises ont pour auteur le premier administrateur de Dash4 (ou `--owner=e-mail`), qui reçoit aussi les CV.

## Sur le serveur

1. Dans le `.env` de Dash4, indiquer la base de Dash3 (même serveur MariaDB qu'aujourd'hui) :

   ```
   DASH3_DB_DATABASE=smi_dash3
   # si différents de la base de Dash4 :
   # DASH3_DB_HOST=mysql-smi.alwaysdata.net
   # DASH3_DB_USERNAME=...
   # DASH3_DB_PASSWORD=...
   ```

2. `php artisan config:clear`

3. Essai, avec les fichiers (adapter le chemin du dossier Dash3) :

   ```
   php artisan dash3:import --dry-run --files=/home/smi/www/<dossier-dash3>/storage/app/public --report=storage/app/reprise-essai.json
   ```

   Lire le tableau (fiches créées, rejetées), les contrôles de totaux et la liste des fichiers introuvables.

4. Reprise :

   ```
   php artisan dash3:import --files=/home/smi/www/<dossier-dash3>/storage/app/public --report=storage/app/reprise.json
   ```

5. `php artisan optimize:clear` puis vérifier quelques devis et factures dans Dash4.

On peut ne reprendre qu'une partie avec `--only=clients --only=contacts…` (dans l'ordre ci-dessous, chaque étape a besoin des précédentes).

## Correspondances

| Dash3 | Dash4 | Règles |
|---|---|---|
| `clients` | `clients` | Particulier → particulier, sinon entreprise ; statut actif ; avatar → logo |
| `contacts` | `contacts` | Quand `client_id` contient l'id d'un projet (bogue de Dash3), le client du projet est repris ; civilité « Homme » (valeur par défaut) non reprise |
| `brands` | `brands` | Noms identiques (casse, espaces) fusionnés : le nom est unique dans Dash4 |
| `providers` | `providers` | |
| `articles` | `articles` | `priority_id` → catégorie ; prix → coût, coefficient 1 ; technique et caractéristiques ajoutées à la description ; quantité de départ expliquée par un mouvement d'inventaire « Reprise du stock de Dash3 » |
| `article_dependences` | `article_dependencies` | |
| `projets` | `projects` | Pas de statut dans Dash3 : **terminé** si tous ses devis sont terminés ou annulés, sinon **en cours** |
| `buildings`, `stages`, `rooms` | `buildings`, `levels`, `rooms` | |
| `systemes`, `installation_systemes`, `equipements` | `systems`, `installed_systems`, `equipment` | Équipements à l'état « installé » ; caractéristiques reprises |
| `installations`, `objets`, `attributs` | `installed_systems`, `equipment` | Fusion décidée : une installation devient un système installé (son type = le système), ses objets des équipements avec leurs attributs en caractéristiques |
| `plans`, `plan_markers` | `plans`, `plan_markers` | Un plan sans fichier est rejeté |
| `fiches`, `fiche_zones` | `sheets`, `sheet_zones` | Le nom du client de la fiche va dans ses notes |
| `invoice_systems`, `invoice_models`, `invoice_model_rows` | `quote_systems`, `quote_templates`, `quote_template_lines` | |
| `invoices` | `quotes` | Numéro = référence Dash3 (`CBA-060-25`) ; titre = description ; statut ci-dessous ; taxe `brs` → BRS, sinon sans taxe ; remise 0,1 → 10 % |
| `invoice_sections`, `invoice_rows` | `quote_sections`, `quote_lines` | Section masquée gardée mais hors total ; prix unitaire = coût × coefficient arrondi, comme dans Dash3 ; référence « _ » = aucune ; article inconnu : ligne gardée sans lien |
| `invoice_acomptes` | `quote_deposits` | |
| `invoice_spents` | `expenses` (dépenses du devis) | Validées, rattachées au devis et à son projet ; « prévue » = non payée |
| `invoice_bls` | `delivery_notes` | Numéro `BL-D3-{id}` ; « livraison » → bordereau de livraison, sinon de travaux |
| `invoice_documents` | `documents` du devis | Lignes sans fichier (« _ ») ignorées |
| `factures` | `invoices` | Numéro = référence ; une ligne au montant de la facture ; « payé » → payée, avec un paiement (moyen « autre ») |
| `forfaits` | `flat_rates` | |
| `achats`, `achat_rows`, `achat_factures` | `purchases`, `purchase_lines`, documents | Numéro `BC-D3-{id}` ; « Acheté » → reçu ; remise de Dash3 comprise dans le total ; TVA 18 % si une ligne en a ; sans fournisseur → « Fournisseur non renseigné (Dash3) » ; le stock n'est pas modifié (déjà dans la quantité des articles) |
| `transactions` | `transactions` | crédit → entrée, débit → sortie |
| `tasks` | `tasks` | Statuts 1 à 4 → à faire, en cours, bloquée, terminée ; priorités basse, normale, haute ; sans personne affectée (Dash3 n'a qu'un compte) |
| `journals`, `journal_sections`, `journal_intervenants` | `journals`, sections, intervenants | Type : « travaux » / « mise en service » → installation, « intervention » → intervention, « visite » → visite, sinon autre ; intervenant membre d'équipe → nom et fonction |
| `c_v_s`, `cv_sections`, `cv_items` | `cvs`, `cv_sections`, `cv_items` | Au compte qui reprend les données |
| `projet_notes`, `brand_notes`, `client_notes` | `notes` | Titre puis texte, balises HTML retirées |
| `article_links`, `brand_links`, `provider_links`, liens de `building_documents` | `links` | |
| `article_documents`, `building_documents`, `brand_links` (fichier), `provider_documents`, `project_docs` | `documents` | |

**Statuts des devis** (Dash3 suit les travaux, Dash4 la décision du client) :

| Dash3 | Dash4 |
|---|---|
| Nouveau | brouillon |
| Proforma, En pause | envoyé |
| En cours, À facturer, Paiement en attente, BL à faire, Terminé | accepté |
| Annulé | annulé |

## Non repris

- `timelines` (historique) : le journal d'activité de Dash4 commence à la reprise.
- `commandes` (quantités commandées par ligne de devis) : remplacé par les achats de Dash4.
- `invoice_proposals` : simples options d'affichage, à refaire sur le devis si besoin.
- `tags` / `taggables`, `erp_docs` (2 documents sans fiche), `settings`, `teams` (repris seulement comme intervenants des journaux).
- Modules supprimés : médias, outils personnels, Myprojects, pages de test.
- Comptes : seul l'administrateur existe dans Dash3 ; les autres comptes se créent dans Dash4.

## Limites

- Une fiche déjà reprise n'est pas mise à jour si elle est modifiée ensuite dans Dash3.
- Les fichiers d'une fiche reprise sans `--files` ne sont pas rattrapés en relançant : faire l'essai, puis la vraie reprise, avec `--files`.
- Remise des devis : Dash4 l'applique avant la taxe (Dash3 après). Seuls les devis avec remise **et** BRS peuvent différer de quelques francs.
