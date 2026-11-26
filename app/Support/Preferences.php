<?php

namespace App\Support;

use App\Models\User;
use App\Notifications;
use Illuminate\Notifications\Events\NotificationSending;

/**
 * Préférences de l'espace personnel : thème, et catégories de notifications que l'on reçoit.
 * Les alertes de sécurité (entreprise, banque, sauvegardes) et les e-mails de mot de passe ne se coupent pas.
 */
final class Preferences
{
    public const THEMES = ['system', 'light', 'dark'];

    /** Catégorie => libellé affiché. */
    public const CATEGORIES = [
        'mentions' => 'Mentions dans les commentaires',
        'tasks' => 'Tâches et chantiers qui me sont confiés',
        'requests' => 'Demandes clients',
        'tech' => 'Espace technicien (informations, demandes d\'accès)',
        'expenses' => 'Dépenses',
        'stock' => 'Stock bas',
        'contracts' => 'Contrats de maintenance',
        'invoices' => 'Factures à relancer',
        'fleet' => 'Parc automobile (papiers, entretiens)',
        'tools' => 'Outillage (prêts et retours)',
    ];

    /** Notification => catégorie. */
    private const CLASSES = [
        Notifications\CommentMentionNotification::class => 'mentions',
        Notifications\TaskAssignedNotification::class => 'tasks',
        Notifications\SiteAssignedNotification::class => 'tasks',
        Notifications\ClientRequestNotification::class => 'requests',
        Notifications\TechShareNotification::class => 'tech',
        Notifications\AccessRequestNotification::class => 'tech',
        Notifications\ExpenseNotification::class => 'expenses',
        Notifications\LowStockNotification::class => 'stock',
        Notifications\ContractNotification::class => 'contracts',
        Notifications\FleetNotification::class => 'fleet',
        Notifications\ToolLoanNotification::class => 'tools',
        Notifications\InvoiceReminderNotification::class => 'invoices',
    ];

    /** @return array{theme: string, notifications: array<string, bool>} */
    public static function for(User $user): array
    {
        $saved = is_array($user->preferences) ? $user->preferences : [];
        $notifications = [];
        foreach (array_keys(self::CATEGORIES) as $category) {
            $notifications[$category] = (bool) ($saved['notifications'][$category] ?? true);
        }

        return [
            'theme' => in_array($saved['theme'] ?? null, self::THEMES, true) ? $saved['theme'] : 'system',
            'notifications' => $notifications,
        ];
    }

    /** Fusionne les changements reçus avec les préférences enregistrées. */
    public static function merge(User $user, array $changes): array
    {
        $current = self::for($user);

        return [
            'theme' => $changes['theme'] ?? $current['theme'],
            'notifications' => array_merge($current['notifications'], array_map('boolval', $changes['notifications'] ?? [])),
        ];
    }

    /** Écouteur : annule une notification d'une catégorie que la personne a coupée. */
    public static function filter(NotificationSending $event): ?bool
    {
        $category = self::CLASSES[$event->notification::class] ?? null;
        if (! $category || ! $event->notifiable instanceof User) {
            return null;
        }

        return self::for($event->notifiable)['notifications'][$category] ? null : false;
    }
}
