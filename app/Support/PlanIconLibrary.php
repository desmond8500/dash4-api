<?php

namespace App\Support;

use App\Models\PlanIcon;

/**
 * Bibliothèque standard des icônes de plans, reprise de la légende CAO de l'entreprise (modèle A3) :
 * mêmes codes (CDI, DOF, NC2, E6, P&C, CAI, RIA…) et mêmes couleurs par métier.
 * Corrections par rapport à la légende d'origine : NC2 = CO2 2 kg et NC5 = CO2 5 kg (inversés),
 * P50 = poudre 50 kg (indiqué « eau 50 L »).
 */
final class PlanIconLibrary
{
    private const RED = '#e30613';

    private const FIRE_DARK = '#111827';

    private const WATER = '#1d4ed8';

    private const POWDER = '#facc15';

    private const GREEN = '#15803d';

    private const VIDEO = '#1d4ed8';

    private const ACCESS = '#166534';

    private const INTRUSION = '#c026d3';

    private const RIA = '#ea580c';

    private const NETWORK = '#2563eb';

    /** Ordre des catégories dans la bibliothèque et dans les légendes. */
    public const CATEGORIES = [
        'Détection incendie', 'Extincteurs', 'Évacuation et signalisation', 'Vidéosurveillance', 'Contrôle d\'accès',
        'Intrusion', 'RIA et colonnes', 'Réseau et courants faibles',
    ];

    /**
     * @return list<array{slug: string, name: string, category: string, symbol: string, code?: string, color: string, text_color?: string, secondary_color?: string}>
     */
    public static function defaults(): array
    {
        $i = fn (string $slug, string $name, string $category, string $symbol, string $color, ?string $code = null, array $extra = []) => [
            'slug' => $slug, 'name' => $name, 'category' => $category, 'symbol' => $symbol, 'color' => $color,
            ...($code ? ['code' => $code, 'text_color' => '#ffffff'] : []), ...$extra,
        ];
        $fire = 'Détection incendie';
        $ext = 'Extincteurs';
        $evac = 'Évacuation et signalisation';
        $video = 'Vidéosurveillance';
        $access = 'Contrôle d\'accès';
        $intr = 'Intrusion';
        $ria = 'RIA et colonnes';
        $net = 'Réseau et courants faibles';
        $extinguisher = fn (string $slug, string $name, string $code, string $bottom, string $text = '#ffffff') => $i($slug, $name, $ext, 'dot', self::RED, $code, ['secondary_color' => $bottom, 'text_color' => $text]);

        return [
            $i('cdi', 'Centrale de détection incendie', $fire, 'rounded', self::RED, 'CDI'),
            $i('dof', 'Détecteur optique de fumée', $fire, 'dot', self::RED, 'DOF'),
            $i('dos', 'Détecteur optique avec buzzer', $fire, 'dot', self::RED, 'DOS'),
            $i('dt', 'Détecteur thermique', $fire, 'dot', self::RED, 'DT'),
            $i('dot', 'Détecteur optique et thermique', $fire, 'dot', self::RED, 'DOT'),
            $i('da', 'Détecteur autonome', $fire, 'dot', self::RED, 'DA'),
            $i('dm', 'Déclencheur manuel', $fire, 'square', self::RED, 'DM'),
            $i('sirene-incendie', 'Sirène incendie', $fire, 'siren', self::RED),
            $i('indicateur-action', "Indicateur d'action", $fire, 'beacon', self::RED),
            $i('flash-incendie', 'Flash incendie', $fire, 'light', self::RED),

            $extinguisher('nc2', 'Extincteur CO2 2 kg', 'NC2', self::FIRE_DARK),
            $extinguisher('nc5', 'Extincteur CO2 5 kg', 'NC5', self::FIRE_DARK),
            $extinguisher('e6', 'Extincteur à eau 6 L', 'E6', self::WATER),
            $extinguisher('e9', 'Extincteur à eau 9 L', 'E9', self::WATER),
            $extinguisher('e50', 'Extincteur à eau 50 L', 'E50', self::WATER),
            $extinguisher('p6', 'Extincteur à poudre 6 kg', 'P6', self::POWDER, '#111827'),
            $extinguisher('p9', 'Extincteur à poudre 9 kg', 'P9', self::POWDER, '#111827'),
            $extinguisher('p50', 'Extincteur à poudre 50 kg', 'P50', self::POWDER, '#111827'),
            $i('bac-sable', 'Bac à sable', $ext, 'square', '#b45309', 'BAS'),

            $i('plan-consigne', "Plan d'évacuation et consigne", $evac, 'rect', self::GREEN, 'P&C'),
            $i('plan-evacuation', "Plan d'évacuation", $evac, 'rect', self::GREEN, 'PE'),
            $i('consigne-securite', 'Consigne générale de sécurité', $evac, 'rect', self::GREEN, 'CGS'),
            $i('point-rassemblement', 'Point de rassemblement', $evac, 'assembly', self::GREEN),
            $i('bloc-autonome', "Bloc autonome d'éclairage de sécurité", $evac, 'rect', self::GREEN, 'BAES'),
            $i('sortie-secours', 'Sortie de secours', $evac, 'exit', self::GREEN),
            $i('fleche', 'Flèche directionnelle', $evac, 'arrow', self::GREEN),

            $i('camera-dome', 'Caméra dôme', $video, 'dome', self::VIDEO),
            $i('camera-exterieure', 'Caméra extérieure (tube)', $video, 'camera', self::VIDEO),
            $i('camera-ptz', 'Caméra motorisée (PTZ)', $video, 'dot', self::VIDEO, 'PTZ'),
            $i('moniteur', 'Moniteur', $video, 'monitor', self::VIDEO),
            $i('alim-video', "Boîtier d'alimentation", $video, 'square', self::VIDEO, '12V'),
            $i('dvr', 'Enregistreur vidéo analogique', $video, 'rect', self::VIDEO, 'DVR'),
            $i('nvr', 'Enregistreur vidéo numérique', $video, 'rect', self::VIDEO, 'NVR'),
            $i('hvr', 'Enregistreur vidéo hybride', $video, 'rect', self::VIDEO, 'HVR'),

            $i('cca', "Centrale de contrôle d'accès", $access, 'rect', self::ACCESS, 'CCA'),
            $i('lecteur-proximite', 'Lecteur de proximité', $access, 'reader', self::ACCESS),
            $i('lecteur-code', 'Lecteur à code', $access, 'keypad', self::ACCESS),
            $i('ventouse', 'Ventouse', $access, 'maglock', self::ACCESS),
            $i('gache', 'Gâche électrique', $access, 'square', self::ACCESS, 'GE'),
            $i('bouton-ouverture', "Bouton d'ouverture", $access, 'square', self::ACCESS, 'BO'),
            $i('bouton-urgence', "Bouton d'urgence (bris de glace)", $access, 'call_point', self::GREEN),
            $i('alim-acces', "Boîtier d'alimentation", $access, 'square', self::ACCESS, '12V'),
            $i('porte', 'Porte contrôlée', $access, 'door', self::ACCESS),

            $i('cai', "Centrale d'alarme intrusion", $intr, 'rounded', self::INTRUSION, 'CAI'),
            $i('volumetrique', 'Détecteur volumétrique', $intr, 'motion', self::INTRUSION),
            $i('volumetrique-360', 'Détecteur volumétrique 360°', $intr, 'dot', self::INTRUSION, '360'),
            $i('sismique', 'Détecteur sismique', $intr, 'dot', self::INTRUSION, 'SIS'),
            $i('contact-ouverture', "Contact d'ouverture", $intr, 'contact', self::INTRUSION),
            $i('sirene-interieure', "Sirène intérieure d'alarme", $intr, 'siren', self::INTRUSION),
            $i('sirene-exterieure', "Sirène extérieure d'alarme", $intr, 'square', self::INTRUSION, 'SE'),
            $i('bouton-panique', 'Bouton panique', $intr, 'dot', self::INTRUSION, 'BP'),
            $i('pedale', "Pédale d'alarme", $intr, 'rect', self::INTRUSION, 'PED'),
            $i('clavier', 'Clavier à codes', $intr, 'keypad', self::INTRUSION),
            $i('alim-intrusion', "Boîtier d'alimentation", $intr, 'square', self::INTRUSION, '12V'),
            $i('module-rio', 'Module RIO 8 zones 2 sorties', $intr, 'rect', self::INTRUSION, 'RIO'),

            $i('ria', 'Robinet incendie armé (RIA)', $ria, 'hose_reel', self::RIA),
            $i('armoire-ria', 'Armoire RIA', $ria, 'rect', '#0891b2', 'FHC'),
            $i('colonne-seche', 'Colonne sèche', $ria, 'dot', self::RIA, 'CS'),
            $i('prise-alim-ext', "Prise d'alimentation extérieure", $ria, 'dot', self::RIA, 'PAE'),
            $i('surpresseur', 'Groupe de pompage incendie (surpresseur)', $ria, 'rect', self::RIA, 'GPI'),
            $i('sprinkler', 'Sprinkler', $ria, 'sprinkler', self::RIA),

            $i('prise-electrique', 'Prise électrique', $net, 'socket', self::RED),
            $i('prise-ethernet', 'Prise Ethernet (RJ45)', $net, 'square', self::NETWORK, 'RJ'),
            $i('prise-hdmi', 'Prise HDMI', $net, 'rect', self::NETWORK, 'HDMI'),
            $i('prise-vga', 'Prise VGA', $net, 'rect', self::NETWORK, 'VGA'),
            $i('disjoncteur', 'Disjoncteur', $net, 'square', self::GREEN, 'DJ'),
            $i('wifi', "Point d'accès Wi-Fi", $net, 'wifi', self::NETWORK),
            $i('telephone', 'Téléphone', $net, 'phone', self::INTRUSION),
            $i('baie', 'Baie de brassage', $net, 'panel', self::NETWORK),
            $i('haut-parleur', 'Haut-parleur', $net, 'speaker', self::NETWORK),
        ];
    }

    /**
     * Ajoute les icônes standard qui manquent (repérées par leur clé). Avec `$reset`, remet aussi celles qui
     * existent à leur forme d'origine (nom, symbole, code, couleurs) ; les icônes créées à la main ne bougent pas.
     *
     * @return array{created: int, updated: int, unchanged: int}
     */
    public static function install(bool $reset = false, ?int $userId = null): array
    {
        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        $order = array_flip(self::CATEGORIES);
        foreach (self::defaults() as $index => $data) {
            $data += ['code' => null, 'text_color' => null, 'secondary_color' => null];
            $data['position'] = ($order[$data['category']] ?? 99) * 100 + $index;
            $icon = PlanIcon::withTrashed()->where('slug', $data['slug'])->first();
            if (! $icon) {
                $icon = new PlanIcon(array_diff_key($data, ['slug' => true]));
                $icon->forceFill(['slug' => $data['slug'], 'created_by' => $userId])->save();
                $result['created']++;
            } elseif ($reset) {
                if ($icon->trashed()) {
                    $icon->restore();
                }
                $icon->fill(array_diff_key($data, ['slug' => true]))->forceFill(['path' => null, 'mime' => null])->save();
                $result['updated']++;
            } else {
                $result['unchanged']++;
            }
        }

        return $result;
    }
}
