<?php

namespace App\Support;

use App\Enums\AccountType;

/**
 * Liste des permissions et des 7 profils de Dash4, plus le compte « Lecture seule » (démonstrations).
 *
 * Source : PROFILS_PERMISSIONS.md (validé le 17 novembre 2026), §4, §5 et §7.
 * Format des permissions : `module.action`, le suffixe `.own` limite aux éléments de l'utilisateur.
 */
final class Permissions
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_PROJECT_MANAGER = 'project_manager';
    public const ROLE_SALES = 'sales';
    public const ROLE_ACCOUNTANT = 'accountant';
    public const ROLE_STOREKEEPER = 'storekeeper';
    public const ROLE_TECHNICIAN = 'technician';
    /** Lecture seule (démonstrations, maquettes) : voit tout sauf les coûts et la banque, ne modifie rien. */
    public const ROLE_VIEWER = 'viewer';

    /** Libellés affichés dans l'interface. */
    public const ROLE_LABELS = [
        self::ROLE_ADMIN => 'Administrateur',
        self::ROLE_MANAGER => 'Manager',
        self::ROLE_PROJECT_MANAGER => 'Chef de projet',
        self::ROLE_SALES => 'Commercial',
        self::ROLE_ACCOUNTANT => 'Comptable',
        self::ROLE_STOREKEEPER => 'Magasinier',
        self::ROLE_TECHNICIAN => 'Technicien',
        self::ROLE_VIEWER => 'Lecture seule',
    ];

    /** Actions disponibles par module (§7). */
    public const MODULES = [
        'dashboard' => ['view', 'view.own'],
        'clients' => ['view', 'view.own', 'create', 'update', 'delete'],
        'client_requests' => ['view', 'view.own', 'create', 'update', 'delete', 'convert'],
        'projects' => ['view', 'view.own', 'create', 'update', 'update.own', 'delete'],
        'sites' => ['view', 'view.own', 'create', 'update', 'update.own', 'delete'],
        'quotes' => ['view', 'view.own', 'create', 'update', 'update.own', 'delete', 'validate', 'export'],
        'delivery_notes' => ['view', 'create', 'update', 'delete'],
        'invoices' => ['view', 'create', 'update', 'delete', 'issue'],
        'purchases' => ['view', 'view.own', 'create', 'update', 'delete', 'receive'],
        'stock' => ['view', 'create', 'update', 'delete', 'move'],
        'accounting' => ['view', 'view.own', 'create', 'create.own', 'update', 'delete'],
        'tasks' => ['view', 'view.own', 'create', 'update', 'update.own', 'delete', 'assign'],
        'journals' => ['view', 'view.own', 'create', 'update', 'update.own', 'delete'],
        'tech_shares' => ['view', 'view.own', 'create', 'update', 'delete'],
        'tech_access_requests' => ['view', 'view.own', 'create', 'decide'],
        'badges' => ['view', 'create', 'update', 'delete'],
        'contracts' => ['view', 'create', 'update', 'delete'],
        // Parc automobile ; `.own` : véhicules dont on est le conducteur attitré (lecture et relevés du compteur)
        'vehicles' => ['view', 'view.own', 'create', 'update', 'delete'],
        // Outillage ; `.own` : outils qu'on a en prêt ; `lend` : prêter, enregistrer les retours, relancer
        'tools' => ['view', 'view.own', 'create', 'update', 'delete', 'lend'],
        'company' => ['view', 'update'],
        'users' => ['view', 'manage'],
        'settings' => ['view', 'update'],
    ];

    /** Permissions sensibles, indépendantes des modules (§5). */
    public const SENSITIVE = [
        'prices.view',
        'costs.view',
        'bank.view',
        'bank.manage',
        'signature.manage',
        'exports.run',
        'records.force_delete',
        'audit.view',
        // Sauvegardes de la base et des fichiers : administrateurs seulement
        'backups.manage',
    ];

    /**
     * Permissions qu'un compte technicien ne peut JAMAIS avoir,
     * même si un rôle ou un administrateur les lui donne (§5).
     */
    public const FORBIDDEN_FOR_TECHNICIANS = [
        'prices.view',
        'costs.view',
        'bank.view',
        'bank.manage',
        'signature.manage',
        'exports.run',
        'records.force_delete',
        'audit.view',
        'backups.manage',
        'users.view',
        'users.manage',
        'company.update',
        'settings.update',
    ];

    /** @return list<string> toutes les permissions de Dash4 */
    public static function all(): array
    {
        $permissions = [];

        foreach (self::MODULES as $module => $actions) {
            foreach ($actions as $action) {
                $permissions[] = "{$module}.{$action}";
            }
        }

        return [...$permissions, ...self::SENSITIVE];
    }

    /**
     * Permissions de chaque profil (matrice §4 et §5).
     *
     * @return array<string, list<string>>
     */
    public static function roles(): array
    {
        $all = self::all();

        return [
            self::ROLE_ADMIN => $all,

            // Tout, sauf : modifier l'entreprise et la banque, la signature, la suppression définitive.
            // Gère les utilisateurs, sauf les administrateurs (règle appliquée dans UserPolicy).
            self::ROLE_MANAGER => array_values(array_diff($all, [
                'company.update',
                'bank.manage',
                'signature.manage',
                'records.force_delete',
                'settings.update',
                'backups.manage',
            ])),

            self::ROLE_PROJECT_MANAGER => [
                'dashboard.view.own',
                'clients.view', 'clients.create', 'clients.update',
                'client_requests.view', 'client_requests.create', 'client_requests.update', 'client_requests.convert',
                'projects.view.own', 'projects.create', 'projects.update.own',
                'sites.view.own', 'sites.create', 'sites.update.own',
                // Pas de quotes.validate pour le moment (décision du 17 novembre 2026).
                'quotes.view.own', 'quotes.create', 'quotes.update.own',
                'delivery_notes.view', 'delivery_notes.create', 'delivery_notes.update',
                'invoices.view',
                'purchases.view.own', 'purchases.create',
                'stock.view',
                'accounting.view.own', 'accounting.create.own',
                'tasks.view.own', 'tasks.create', 'tasks.update.own', 'tasks.delete', 'tasks.assign',
                'journals.view.own', 'journals.create', 'journals.update.own',
                'tech_shares.view.own', 'tech_shares.create', 'tech_shares.update',
                'tech_access_requests.view.own', 'tech_access_requests.decide',
                'badges.view', 'badges.create', 'badges.update',
                'contracts.view',
                'vehicles.view.own',
                'tools.view', 'tools.lend',
                'prices.view',
            ],

            self::ROLE_SALES => [
                'dashboard.view.own',
                'clients.view', 'clients.create', 'clients.update',
                'client_requests.view', 'client_requests.create', 'client_requests.update', 'client_requests.convert',
                'projects.view', 'projects.create', 'projects.update',
                'sites.view',
                'quotes.view', 'quotes.create', 'quotes.update', 'quotes.validate', 'quotes.export',
                'delivery_notes.view',
                'invoices.view',
                'stock.view',
                'accounting.view.own', 'accounting.create.own',
                'tasks.view.own', 'tasks.create', 'tasks.update.own',
                'journals.view',
                'contracts.view', 'contracts.create', 'contracts.update',
                'vehicles.view.own',
                'tools.view.own',
                'prices.view', 'costs.view',
            ],

            self::ROLE_ACCOUNTANT => [
                'dashboard.view.own',
                'clients.view',
                'client_requests.view',
                'projects.view',
                'quotes.view',
                'delivery_notes.view',
                'invoices.view', 'invoices.create', 'invoices.update', 'invoices.delete', 'invoices.issue',
                'purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete', 'purchases.receive',
                'stock.view',
                'accounting.view', 'accounting.create', 'accounting.update', 'accounting.delete',
                'tasks.view.own', 'tasks.update.own',
                'contracts.view',
                'vehicles.view',
                'tools.view',
                'company.view', 'company.update',
                'settings.view',
                'prices.view', 'costs.view', 'bank.view', 'bank.manage', 'signature.manage', 'exports.run', 'audit.view',
            ],

            self::ROLE_STOREKEEPER => [
                'dashboard.view.own',
                'clients.view',
                'projects.view',
                'sites.view',
                'delivery_notes.view', 'delivery_notes.create', 'delivery_notes.update',
                'purchases.view', 'purchases.receive',
                'stock.view', 'stock.create', 'stock.update', 'stock.delete', 'stock.move',
                'accounting.view.own', 'accounting.create.own',
                'tasks.view.own', 'tasks.update.own',
                'vehicles.view.own',
                'tools.view', 'tools.create', 'tools.update', 'tools.delete', 'tools.lend',
                'costs.view', 'exports.run',
            ],

            // Toutes les permissions de consultation, et les prix de vente ; aucune écriture
            // (en plus, le middleware ReadOnlyViewer refuse toute requête qui modifie quelque chose).
            self::ROLE_VIEWER => [
                ...array_values(array_filter($all, fn (string $p) => str_ends_with($p, '.view') && ! in_array($p, ['costs.view', 'bank.view', 'audit.view'], true))),
            ],

            self::ROLE_TECHNICIAN => [
                'dashboard.view.own',
                'clients.view.own',
                'client_requests.view.own', 'client_requests.create',
                'projects.view.own',
                'sites.view.own',
                'stock.view',
                'accounting.view.own', 'accounting.create.own',
                'tasks.view.own', 'tasks.update.own',
                'journals.view.own', 'journals.create', 'journals.update.own',
                'tech_shares.view.own',
                'tech_access_requests.view.own', 'tech_access_requests.create',
                'vehicles.view.own',
                'tools.view.own',
            ],
        ];
    }

    /** Rôles autorisés pour chaque type de compte. */
    public static function rolesForAccountType(AccountType $type): array
    {
        return $type === AccountType::Technician
            ? [self::ROLE_TECHNICIAN]
            : array_values(array_diff(array_keys(self::ROLE_LABELS), [self::ROLE_TECHNICIAN]));
    }
}
