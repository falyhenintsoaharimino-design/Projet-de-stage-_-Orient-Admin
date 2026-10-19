<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Statistiques pour le tableau de bord du responsable (EF-39 à EF-43).
 * Lecture seule : uniquement des requêtes SQL agrégées, aucune écriture.
 *
 * Filtres (tous facultatifs), passés sous forme de tableau :
 *   du, au      : \DateTimeImmutable (jours inclus)
 *                 - demandes : date de création de la demande
 *                 - rendez-vous / affluence : date du créneau
 *                 - avis : date de l'avis
 *   serviceId   : int
 *                 - demandes : demandes recommandées OU finalement orientées vers ce service
 *                   (même règle que la liste des demandes d'un agent)
 *                 - rendez-vous / affluence / avis : service du créneau
 *   pas         : 'jour' ou 'mois' (regroupement des courbes d'évolution, défaut 'mois')
 *
 * Définitions retenues pour la performance de l'orientation (EF-42) :
 *   - orientée par le moteur : le moteur a recommandé un service (serviceRecommande renseigné) ;
 *   - réorientée : un agent a ensuite choisi un AUTRE service (serviceFinal différent) ;
 *   - bonne orientation : orientée par le moteur et non réorientée vers un autre service
 *     (taux = bonnes / orientées par le moteur) ;
 *   - non comprise : le moteur a été sollicité sans rien recommander (confiance renseignée,
 *     pas de service recommandé) ;
 *   - choix du citoyen : service choisi par le citoyen lui-même (le moteur n'est pas sollicité),
 *     exclu de ces taux.
 * Pour changer une définition, il suffit de modifier la méthode orientation().
 */
class StatistiquesService
{
    public const TYPES_EXPORT = ['synthese', 'demandes', 'rendez-vous', 'avis'];

    /** Rendez-vous comptés dans l'affluence : ceux qui occupent (ou ont occupé) un créneau. */
    private const STATUTS_AFFLUENCE = ['en_attente', 'confirme', 'termine', 'absent'];

    private const JOURS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    public function __construct(private Connection $connection)
    {
    }

    /** Tout le tableau de bord en une seule réponse. */
    public function tableauDeBord(array $f): array
    {
        $demandes = $this->demandes($f);
        $orientation = $this->orientation($f);
        $rendezVous = $this->rendezVous($f);
        $satisfaction = $this->satisfaction($f);

        return [
            'indicateurs' => [
                'totalDemandes' => $demandes['total'],
                'totalRendezVous' => $rendezVous['total'],
                'tauxAbsencePct' => $rendezVous['tauxAbsencePct'],
                'noteMoyenne' => $satisfaction['noteMoyenne'],
                'nombreAvis' => $satisfaction['nombreAvis'],
                'tauxBonneOrientationPct' => $orientation['tauxBonneOrientationPct'],
                'tauxNonComprisesPct' => $orientation['tauxNonComprisesPct'],
            ],
            'demandes' => $demandes,
            'rendezVous' => $rendezVous,
            'affluence' => $this->affluence($f),
            'satisfaction' => $satisfaction,
            'orientation' => $orientation,
        ];
    }

    /** Demandes par statut, par service, par type (catégorie du service) et dans le temps. */
    public function demandes(array $f): array
    {
        [$c, $p] = $this->conditionsDemande($f);
        $w = $this->where($c);
        $serviceEffectif = 'LEFT JOIN service s ON s.id = COALESCE(d.service_final_id, d.service_recommande_id)';

        $parStatut = array_map(
            fn(array $r) => ['statut' => $r['statut'], 'total' => (int) $r['nb']],
            $this->connection->fetchAllAssociative(
                "SELECT d.status AS statut, COUNT(*) AS nb FROM demande d $w GROUP BY d.status ORDER BY nb DESC, d.status",
                $p
            )
        );

        $parService = array_map(
            fn(array $r) => [
                'serviceId' => $r['id'] === null ? null : (int) $r['id'],
                'service' => $r['nom'] ?? 'Non orientée',
                'categorie' => $r['categorie'],
                'total' => (int) $r['nb'],
            ],
            $this->connection->fetchAllAssociative(
                "SELECT s.id AS id, s.nom AS nom, s.categorie AS categorie, COUNT(d.id) AS nb
                 FROM demande d $serviceEffectif $w
                 GROUP BY s.id, s.nom, s.categorie ORDER BY nb DESC, s.nom",
                $p
            )
        );

        // « Type » de demande = catégorie du service vers lequel elle est orientée.
        $parType = array_map(
            fn(array $r) => [
                'type' => (int) $r['orientee'] === 0 ? 'Non orientée' : ($r['categorie'] ?? 'Sans catégorie'),
                'total' => (int) $r['nb'],
            ],
            $this->connection->fetchAllAssociative(
                "SELECT CASE WHEN s.id IS NULL THEN 0 ELSE 1 END AS orientee, s.categorie AS categorie, COUNT(d.id) AS nb
                 FROM demande d $serviceEffectif $w
                 GROUP BY orientee, s.categorie ORDER BY nb DESC, s.categorie",
                $p
            )
        );

        $jours = $this->connection->fetchAllAssociative(
            "SELECT CAST(d.date_creation AS DATE) AS jour, COUNT(*) AS nb FROM demande d $w GROUP BY jour ORDER BY jour",
            $p
        );

        $evolution = [];
        foreach ($this->regrouper($jours, $f['pas'] ?? 'mois', ['nb']) as $periode => $v) {
            $evolution[] = ['periode' => $periode, 'total' => $v['nb']];
        }

        return [
            'total' => array_sum(array_column($parStatut, 'total')),
            'parStatut' => $parStatut,
            'parService' => $parService,
            'parType' => $parType,
            'evolution' => $evolution,
        ];
    }

    /** Performance du moteur d'orientation (voir les définitions en tête de classe). */
    public function orientation(array $f): array
    {
        [$c, $p] = $this->conditionsDemande($f);
        $r = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS total,
                COUNT(*) FILTER (WHERE d.service_recommande_id IS NOT NULL) AS orientees_moteur,
                COUNT(*) FILTER (WHERE d.service_recommande_id IS NOT NULL AND d.service_final_id IS NOT NULL
                                   AND d.service_final_id <> d.service_recommande_id) AS reorientees,
                COUNT(*) FILTER (WHERE d.service_recommande_id IS NULL AND d.confiance IS NOT NULL) AS non_comprises,
                COUNT(*) FILTER (WHERE d.service_recommande_id IS NULL AND d.confiance IS NULL) AS choix_citoyen,
                AVG(d.confiance) FILTER (WHERE d.service_recommande_id IS NOT NULL) AS confiance_moyenne
             FROM demande d " . $this->where($c),
            $p
        ) ?: [];

        $orientees = (int) ($r['orientees_moteur'] ?? 0);
        $reorientees = (int) ($r['reorientees'] ?? 0);
        $nonComprises = (int) ($r['non_comprises'] ?? 0);

        return [
            'totalDemandes' => (int) ($r['total'] ?? 0),
            'orienteesParMoteur' => $orientees,
            'reorientees' => $reorientees,
            'bonnesOrientations' => $orientees - $reorientees,
            'nonComprises' => $nonComprises,
            'choixDuCitoyen' => (int) ($r['choix_citoyen'] ?? 0),
            'tauxBonneOrientationPct' => $this->pct($orientees - $reorientees, $orientees),
            'tauxReorientationPct' => $this->pct($reorientees, $orientees),
            'tauxNonComprisesPct' => $this->pct($nonComprises, $orientees + $nonComprises),
            'confianceMoyenne' => isset($r['confiance_moyenne']) ? round((float) $r['confiance_moyenne'], 2) : null,
        ];
    }

    /** Rendez-vous par statut (période = date du créneau). */
    public function rendezVous(array $f): array
    {
        [$c, $p] = $this->conditionsRdv($f);
        $parStatut = array_map(
            fn(array $r) => ['statut' => $r['statut'], 'total' => (int) $r['nb']],
            $this->connection->fetchAllAssociative(
                'SELECT r.statut AS statut, COUNT(*) AS nb FROM rendez_vous r JOIN creneau c ON c.id = r.creneau_id '
                . $this->where($c) . ' GROUP BY r.statut ORDER BY nb DESC, r.statut',
                $p
            )
        );
        $nb = array_column($parStatut, 'total', 'statut');
        $termines = (int) ($nb['termine'] ?? 0);
        $absents = (int) ($nb['absent'] ?? 0);

        return [
            'total' => array_sum($nb),
            'parStatut' => $parStatut,
            // Parmi les rendez-vous clôturés (terminé ou absent).
            'tauxAbsencePct' => $this->pct($absents, $termines + $absents),
        ];
    }

    /**
     * Affluence (EF-40) : rendez-vous par jour de la semaine et par heure.
     * `matrice` ne contient que les cases non vides (jour 1 = lundi ... 7 = dimanche),
     * de quoi dessiner une carte de chaleur jours x heures.
     */
    public function affluence(array $f): array
    {
        [$c, $p] = $this->conditionsRdv($f);
        $c[] = "r.statut IN ('" . implode("','", self::STATUTS_AFFLUENCE) . "')";
        $lignes = $this->connection->fetchAllAssociative(
            'SELECT c.date AS jour, c.heure AS heure, COUNT(*) AS nb FROM rendez_vous r JOIN creneau c ON c.id = r.creneau_id '
            . $this->where($c) . ' GROUP BY c.date, c.heure',
            $p
        );

        $parJour = array_fill(1, 7, 0);
        $parHeure = [];
        $matrice = [];
        $total = 0;
        $heureInconnue = 0;
        foreach ($lignes as $l) {
            $nb = (int) $l['nb'];
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $l['jour']);
            if (!$date) {
                continue;
            }
            $total += $nb;
            $jour = (int) $date->format('N');
            $parJour[$jour] += $nb;
            if (!preg_match('/^\s*(\d{1,2})\s*(?:[:hH]|$)/', (string) $l['heure'], $m) || (int) $m[1] > 23) {
                $heureInconnue += $nb;
                continue;
            }
            $heure = (int) $m[1];
            $parHeure[$heure] = ($parHeure[$heure] ?? 0) + $nb;
            $matrice[$jour][$heure] = ($matrice[$jour][$heure] ?? 0) + $nb;
        }

        ksort($parHeure);
        $cases = [];
        ksort($matrice);
        foreach ($matrice as $jour => $heures) {
            ksort($heures);
            foreach ($heures as $heure => $nb) {
                $cases[] = ['jour' => $jour, 'heure' => $heure, 'total' => $nb];
            }
        }

        return [
            'total' => $total,
            'parJour' => array_map(fn(int $j) => ['jour' => $j, 'nom' => self::JOURS[$j], 'total' => $parJour[$j]], array_keys(self::JOURS)),
            'parHeure' => array_map(fn(int $h) => ['heure' => $h, 'total' => $parHeure[$h]], array_keys($parHeure)),
            'matrice' => $cases,
            'heureNonReconnue' => $heureInconnue,
        ];
    }

    /** Satisfaction (EF-41) : note moyenne, répartition des notes, par service, évolution. */
    public function satisfaction(array $f): array
    {
        [$c, $p] = $this->conditionsAvis($f);
        $w = $this->where($c);
        $from = 'FROM avis a JOIN rendez_vous r ON r.id = a.rendez_vous_id JOIN creneau c ON c.id = r.creneau_id';

        $global = $this->connection->fetchAssociative("SELECT COUNT(*) AS nb, AVG(a.note) AS moyenne $from $w", $p) ?: [];

        $repartition = array_fill_keys([1, 2, 3, 4, 5], 0);
        foreach ($this->connection->fetchAllAssociative("SELECT a.note AS note, COUNT(*) AS nb $from $w GROUP BY a.note", $p) as $r) {
            $repartition[(int) $r['note']] = (int) $r['nb'];
        }

        $parService = array_map(
            fn(array $r) => [
                'serviceId' => (int) $r['id'],
                'service' => $r['nom'],
                'nombreAvis' => (int) $r['nb'],
                'noteMoyenne' => round((float) $r['moyenne'], 2),
            ],
            $this->connection->fetchAllAssociative(
                "SELECT s.id AS id, s.nom AS nom, COUNT(*) AS nb, AVG(a.note) AS moyenne
                 $from JOIN service s ON s.id = c.service_id $w
                 GROUP BY s.id, s.nom ORDER BY moyenne DESC, s.nom",
                $p
            )
        );

        $jours = $this->connection->fetchAllAssociative(
            "SELECT CAST(a.date_creation AS DATE) AS jour, COUNT(*) AS nb, SUM(a.note) AS somme $from $w GROUP BY jour ORDER BY jour",
            $p
        );
        $evolution = [];
        foreach ($this->regrouper($jours, $f['pas'] ?? 'mois', ['nb', 'somme']) as $periode => $v) {
            $evolution[] = ['periode' => $periode, 'nombreAvis' => $v['nb'], 'noteMoyenne' => round($v['somme'] / max(1, $v['nb']), 2)];
        }

        return [
            'nombreAvis' => (int) ($global['nb'] ?? 0),
            'noteMoyenne' => isset($global['moyenne']) ? round((float) $global['moyenne'], 2) : null,
            'repartition' => array_map(fn(int $n) => ['note' => $n, 'total' => $repartition[$n]], array_keys($repartition)),
            'parService' => $parService,
            'evolution' => $evolution,
        ];
    }

    /** Lignes des exports CSV : [en-têtes, lignes]. */
    public function exporter(string $type, array $f): array
    {
        return match ($type) {
            'synthese' => $this->exportSynthese($f),
            'demandes' => $this->exportDemandes($f),
            'rendez-vous' => $this->exportRendezVous($f),
            'avis' => $this->exportAvis($f),
            default => throw new \DomainException('type invalide. Valeurs possibles : ' . implode(', ', self::TYPES_EXPORT), 400),
        };
    }

    private function exportSynthese(array $f): array
    {
        [$cd, $pd] = $this->conditionsDemande($f, false);
        [$cr, $pr] = $this->conditionsRdv($f, false);
        [$ca, $pa] = $this->conditionsAvis($f, false);
        $params = array_merge($pd, $pr, $pa);
        $filtreService = '';
        if ($f['serviceId'] ?? null) {
            $filtreService = 'WHERE s.id = ?';
            $params[] = (int) $f['serviceId'];
        }

        $lignes = $this->connection->fetchAllAssociative(
            'SELECT s.nom AS nom, s.categorie AS categorie,
                COALESCE(dm.nb, 0) AS demandes, COALESCE(rv.total, 0) AS rdv, COALESCE(rv.termines, 0) AS termines,
                COALESCE(rv.absents, 0) AS absents, COALESCE(av.nb, 0) AS avis, av.moyenne AS moyenne
             FROM service s
             LEFT JOIN (SELECT COALESCE(d.service_final_id, d.service_recommande_id) AS sid, COUNT(*) AS nb
                        FROM demande d ' . $this->where($cd) . ' GROUP BY sid) dm ON dm.sid = s.id
             LEFT JOIN (SELECT c.service_id AS sid, COUNT(*) AS total,
                               COUNT(*) FILTER (WHERE r.statut = \'termine\') AS termines,
                               COUNT(*) FILTER (WHERE r.statut = \'absent\') AS absents
                        FROM rendez_vous r JOIN creneau c ON c.id = r.creneau_id ' . $this->where($cr) . ' GROUP BY c.service_id) rv ON rv.sid = s.id
             LEFT JOIN (SELECT c.service_id AS sid, COUNT(*) AS nb, AVG(a.note) AS moyenne
                        FROM avis a JOIN rendez_vous r ON r.id = a.rendez_vous_id JOIN creneau c ON c.id = r.creneau_id ' . $this->where($ca) . ' GROUP BY c.service_id) av ON av.sid = s.id '
            . $filtreService . ' ORDER BY s.nom',
            $params
        );

        return [
            ['Service', 'Catégorie', 'Demandes', 'Rendez-vous', 'Rendez-vous terminés', 'Absents', 'Avis', 'Note moyenne'],
            array_map(fn(array $r) => [
                $r['nom'], $r['categorie'], (int) $r['demandes'], (int) $r['rdv'], (int) $r['termines'], (int) $r['absents'],
                (int) $r['avis'], $r['moyenne'] === null ? null : round((float) $r['moyenne'], 2),
            ], $lignes),
        ];
    }

    private function exportDemandes(array $f): array
    {
        [$c, $p] = $this->conditionsDemande($f);
        $lignes = $this->connection->fetchAllAssociative(
            "SELECT d.id AS id, d.date_creation AS date, d.status AS statut,
                CASE WHEN d.service_recommande_id IS NOT NULL AND d.service_final_id IS NOT NULL
                          AND d.service_final_id <> d.service_recommande_id THEN 'reorientee'
                     WHEN d.service_recommande_id IS NOT NULL THEN 'moteur'
                     WHEN d.confiance IS NOT NULL THEN 'non_comprise'
                     ELSE 'choix_citoyen' END AS orientation,
                d.confiance AS confiance, sr.nom AS service_recommande, sf.nom AS service_final
             FROM demande d
             LEFT JOIN service sr ON sr.id = d.service_recommande_id
             LEFT JOIN service sf ON sf.id = d.service_final_id "
            . $this->where($c) . ' ORDER BY d.date_creation, d.id',
            $p
        );

        return [
            ['Numéro', 'Date de création', 'Statut', 'Orientation', 'Confiance', 'Service recommandé', 'Service final'],
            array_map(fn(array $r) => [
                $r['id'], $r['date'], $r['statut'], $r['orientation'], $r['confiance'] === null ? null : round((float) $r['confiance'], 2),
                $r['service_recommande'], $r['service_final'],
            ], $lignes),
        ];
    }

    private function exportRendezVous(array $f): array
    {
        [$c, $p] = $this->conditionsRdv($f);
        $lignes = $this->connection->fetchAllAssociative(
            'SELECT r.id AS id, c.date AS date, c.heure AS heure, s.nom AS service, r.statut AS statut
             FROM rendez_vous r JOIN creneau c ON c.id = r.creneau_id JOIN service s ON s.id = c.service_id '
            . $this->where($c) . ' ORDER BY c.date, c.heure, r.id',
            $p
        );

        return [
            ['Numéro', 'Date', 'Heure', 'Service', 'Statut'],
            array_map(fn(array $r) => [$r['id'], $r['date'], $r['heure'], $r['service'], $r['statut']], $lignes),
        ];
    }

    private function exportAvis(array $f): array
    {
        [$c, $p] = $this->conditionsAvis($f);
        $lignes = $this->connection->fetchAllAssociative(
            'SELECT a.id AS id, a.date_creation AS date, s.nom AS service, a.note AS note, a.commentaire AS commentaire
             FROM avis a JOIN rendez_vous r ON r.id = a.rendez_vous_id JOIN creneau c ON c.id = r.creneau_id
             JOIN service s ON s.id = c.service_id ' . $this->where($c) . ' ORDER BY a.date_creation, a.id',
            $p
        );

        return [
            ['Numéro', 'Date', 'Service', 'Note', 'Commentaire'],
            array_map(fn(array $r) => [$r['id'], $r['date'], $r['service'], $r['note'], $r['commentaire']], $lignes),
        ];
    }

    // --- Outils --------------------------------------------------------------

    /** @return array{0: string[], 1: array} conditions SQL (alias d) et paramètres positionnels */
    private function conditionsDemande(array $f, bool $avecService = true): array
    {
        $c = [];
        $p = [];
        if (!empty($f['du'])) {
            $c[] = 'd.date_creation >= ?';
            $p[] = $f['du']->format('Y-m-d') . ' 00:00:00';
        }
        if (!empty($f['au'])) {
            $c[] = 'd.date_creation < ?';
            $p[] = $f['au']->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        if ($avecService && !empty($f['serviceId'])) {
            $c[] = '(d.service_recommande_id = ? OR d.service_final_id = ?)';
            $p[] = (int) $f['serviceId'];
            $p[] = (int) $f['serviceId'];
        }

        return [$c, $p];
    }

    /** Conditions sur les rendez-vous (alias r) et leur créneau (alias c). */
    private function conditionsRdv(array $f, bool $avecService = true): array
    {
        $c = [];
        $p = [];
        if (!empty($f['du'])) {
            $c[] = 'c.date >= ?';
            $p[] = $f['du']->format('Y-m-d');
        }
        if (!empty($f['au'])) {
            $c[] = 'c.date <= ?';
            $p[] = $f['au']->format('Y-m-d');
        }
        if ($avecService && !empty($f['serviceId'])) {
            $c[] = 'c.service_id = ?';
            $p[] = (int) $f['serviceId'];
        }

        return [$c, $p];
    }

    /** Conditions sur les avis (alias a) et le créneau de leur rendez-vous (alias c). */
    private function conditionsAvis(array $f, bool $avecService = true): array
    {
        $c = [];
        $p = [];
        if (!empty($f['du'])) {
            $c[] = 'a.date_creation >= ?';
            $p[] = $f['du']->format('Y-m-d') . ' 00:00:00';
        }
        if (!empty($f['au'])) {
            $c[] = 'a.date_creation < ?';
            $p[] = $f['au']->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        if ($avecService && !empty($f['serviceId'])) {
            $c[] = 'c.service_id = ?';
            $p[] = (int) $f['serviceId'];
        }

        return [$c, $p];
    }

    private function where(array $conditions): string
    {
        return $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
    }

    private function pct(int $partie, int $total): ?float
    {
        return $total > 0 ? round($partie * 100 / $total, 1) : null;
    }

    /**
     * Regroupe des lignes journalières (colonne `jour` = AAAA-MM-JJ) par jour ou par mois
     * en additionnant les colonnes numériques demandées.
     *
     * @param string[] $cles colonnes à additionner
     * @return array<string, array<string, int>> période (AAAA-MM-JJ ou AAAA-MM) => sommes
     */
    private function regrouper(array $lignes, string $pas, array $cles): array
    {
        $groupes = [];
        foreach ($lignes as $l) {
            $periode = $pas === 'jour' ? (string) $l['jour'] : substr((string) $l['jour'], 0, 7);
            foreach ($cles as $cle) {
                $groupes[$periode][$cle] = ($groupes[$periode][$cle] ?? 0) + (int) $l[$cle];
            }
        }

        return $groupes;
    }
}
