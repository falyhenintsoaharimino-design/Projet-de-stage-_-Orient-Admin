<?php

namespace App\Service;

use App\Entity\Parametre;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Paramétrage de l'application par l'administrateur (EF-46) :
 *  - durée par défaut des créneaux d'un NOUVEAU service (les services existants gardent leur réglage) ;
 *  - jours fériés, exclus automatiquement de la génération de créneaux ;
 *  - modèles de messages (sujet + texte) des notifications adressées au citoyen. Un modèle personnalisé
 *    remplace le texte par défaut ; les {variables} sont remplacées au moment de l'envoi.
 *
 * Les erreurs de validation sont des \DomainException de code 400 (convention du projet).
 */
class ParametreService
{
    public const CLE_DUREE = 'duree_creneau_defaut';
    public const CLE_FERIES = 'jours_feries';
    public const CLE_MODELES = 'modeles_messages';

    public const DUREE_PAR_DEFAUT = 30;
    public const FERIES_MAX = 100;
    public const SUJET_MAX = 150;
    public const MESSAGE_MAX = 1000;

    private const VARIABLES_RDV = ['quand', 'lieu', 'service', 'pieces'];

    /**
     * Modèles par défaut (identiques aux textes d'origine). Variables : {quand} « du 12/11/2026 à 09:00 »,
     * {lieu} « Service, adresse », {service} nom du service seul, {pieces} « Pièces à apporter : ... » (vide s'il n'y en a pas),
     * {ancien} ancienne date (report), {motif} « Motif : ... » (report, vide sans motif),
     * {liste} pièces manquantes séparées par « ; » et {message} remarque de l'agent (pièces manquantes).
     */
    public const MODELES = [
        'rdv_en_attente' => [
            'sujet' => 'Demande de rendez-vous enregistrée',
            'message' => 'Votre demande de rendez-vous {quand} ({lieu}) est enregistrée. Un agent doit la confirmer.{pieces}',
            'variables' => self::VARIABLES_RDV,
        ],
        'rdv_confirme' => [
            'sujet' => 'Rendez-vous confirmé',
            'message' => 'Votre rendez-vous {quand} est confirmé. Lieu : {lieu}.{pieces}',
            'variables' => self::VARIABLES_RDV,
        ],
        'rdv_modifie' => [
            'sujet' => 'Rendez-vous modifié',
            'message' => 'Votre rendez-vous est maintenant fixé {quand} ({lieu}). Un agent doit le confirmer de nouveau.{pieces}',
            'variables' => self::VARIABLES_RDV,
        ],
        'rdv_reporte' => [
            'sujet' => 'Rendez-vous reporté',
            'message' => 'Votre rendez-vous {ancien} est reporté : il a lieu {quand} ({lieu}).{motif}{pieces}',
            'variables' => ['quand', 'lieu', 'service', 'pieces', 'ancien', 'motif'],
        ],
        'rdv_refuse' => [
            'sujet' => 'Rendez-vous refusé',
            'message' => 'Votre rendez-vous {quand} ({service}) a été refusé. Vous pouvez choisir un autre créneau.',
            'variables' => self::VARIABLES_RDV,
        ],
        'rdv_termine' => [
            'sujet' => 'Votre rendez-vous est terminé',
            'message' => 'Votre rendez-vous {quand} ({service}) est terminé. Donnez votre avis pour nous aider à améliorer le service.',
            'variables' => self::VARIABLES_RDV,
        ],
        'rdv_rappel' => [
            'sujet' => 'Rappel de rendez-vous',
            'message' => 'Rappel : vous avez rendez-vous {quand} ({lieu}).{pieces}',
            'variables' => self::VARIABLES_RDV,
        ],
        'pieces_manquantes' => [
            'sujet' => 'Pièces manquantes pour votre rendez-vous',
            'message' => 'Pour votre rendez-vous {quand} ({lieu}), il manque : {liste}.{message} Merci de les apporter.',
            'variables' => ['quand', 'lieu', 'service', 'liste', 'message'],
        ],
    ];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** Durée par défaut (minutes) d'un créneau pour un nouveau service. */
    public function dureeCreneauDefaut(): int
    {
        $valeur = $this->lire(self::CLE_DUREE);

        return is_int($valeur) ? $valeur : self::DUREE_PAR_DEFAUT;
    }

    /** @return list<array{date: string, libelle: string}> triés par date */
    public function joursFeries(): array
    {
        $valeur = $this->lire(self::CLE_FERIES);

        return is_array($valeur) ? array_values($valeur) : [];
    }

    /**
     * Dates fériées comprises dans [$du, $au] (AAAA-MM-JJ, bornes incluses).
     *
     * @return list<string>
     */
    public function datesFeriees(?string $du = null, ?string $au = null): array
    {
        $dates = [];
        foreach ($this->joursFeries() as $jour) {
            if (($du === null || $jour['date'] >= $du) && ($au === null || $jour['date'] <= $au)) {
                $dates[] = $jour['date'];
            }
        }

        return $dates;
    }

    /**
     * Sujet et texte d'une notification : le modèle personnalisé s'il existe, sinon le modèle par défaut.
     *
     * @param array<string, string> $variables
     *
     * @return array{0: string, 1: string} [sujet, message]
     */
    public function rendre(string $type, array $variables): array
    {
        $modele = $this->modelesPersonnalises()[$type] ?? null;
        $sujet = $modele['sujet'] ?? self::MODELES[$type]['sujet'];
        $message = $modele['message'] ?? self::MODELES[$type]['message'];
        $remplacer = static fn(string $texte): string => preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static fn(array $m): string => $variables[$m[1]] ?? $m[0],
            $texte
        );

        return [$remplacer($sujet), $remplacer($message)];
    }

    /**
     * Tout le paramétrage, tel que renvoyé par GET /api/admin/parametres.
     *
     * @return array<string, mixed>
     */
    public function tout(): array
    {
        $perso = $this->modelesPersonnalises();
        $modeles = [];
        foreach (self::MODELES as $type => $defaut) {
            $modeles[$type] = [
                'sujet' => $perso[$type]['sujet'] ?? $defaut['sujet'],
                'message' => $perso[$type]['message'] ?? $defaut['message'],
                'personnalise' => isset($perso[$type]),
                'variables' => $defaut['variables'],
                'defaut' => ['sujet' => $defaut['sujet'], 'message' => $defaut['message']],
            ];
        }

        return [
            'dureeCreneauDefautMinutes' => $this->dureeCreneauDefaut(),
            'joursFeries' => $this->joursFeries(),
            'modelesMessages' => $modeles,
        ];
    }

    /**
     * Modification partielle : seules les clés présentes changent.
     *  - dureeCreneauDefautMinutes : entier de 5 à 240 ;
     *  - joursFeries : liste COMPLÈTE de {date AAAA-MM-JJ, libelle} (remplace l'ancienne) ;
     *  - modelesMessages : { type: {sujet, message} } fusionné avec l'existant ; null = retour au modèle par défaut.
     * Tout est validé avant la moindre écriture.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string> clés modifiées
     */
    public function modifier(array $data, ?Utilisateur $admin): array
    {
        $inconnues = array_diff(array_keys($data), ['dureeCreneauDefautMinutes', 'joursFeries', 'modelesMessages']);
        if ($inconnues !== []) {
            throw new \DomainException('Paramètre inconnu : ' . implode(', ', $inconnues), 400);
        }
        if ($data === []) {
            throw new \DomainException('Aucun paramètre à modifier', 400);
        }

        $ecritures = [];
        if (array_key_exists('dureeCreneauDefautMinutes', $data)) {
            $duree = $data['dureeCreneauDefautMinutes'];
            if (!is_int($duree) || $duree < 5 || $duree > 240) {
                throw new \DomainException('dureeCreneauDefautMinutes doit être un entier de 5 à 240', 400);
            }
            $ecritures[self::CLE_DUREE] = $duree;
        }
        if (array_key_exists('joursFeries', $data)) {
            $ecritures[self::CLE_FERIES] = $this->validerFeries($data['joursFeries']);
        }
        if (array_key_exists('modelesMessages', $data)) {
            $ecritures[self::CLE_MODELES] = $this->validerModeles($data['modelesMessages']);
        }

        $maintenant = new \DateTimeImmutable();
        foreach ($ecritures as $cle => $valeur) {
            $parametre = $this->em->find(Parametre::class, $cle) ?? new Parametre($cle);
            $parametre->setValeur($valeur)->setModifieLe($maintenant)->setModifiePar($admin);
            $this->em->persist($parametre);
        }
        $this->em->flush();

        return array_keys($data);
    }

    /** @return list<array{date: string, libelle: string}> */
    private function validerFeries(mixed $liste): array
    {
        if (!is_array($liste) || !array_is_list($liste) || count($liste) > self::FERIES_MAX) {
            throw new \DomainException(sprintf('joursFeries doit être une liste de %d jours au plus', self::FERIES_MAX), 400);
        }
        $feries = [];
        foreach ($liste as $jour) {
            $date = is_array($jour) ? ($jour['date'] ?? null) : null;
            $libelle = is_array($jour) ? ($jour['libelle'] ?? null) : null;
            if (!is_string($date) || !self::dateValide($date)) {
                throw new \DomainException('Chaque jour férié a une date au format AAAA-MM-JJ', 400);
            }
            if (!is_string($libelle) || trim($libelle) === '' || mb_strlen($libelle) > 100) {
                throw new \DomainException("Le jour férié $date a besoin d'un libellé de 100 caractères au plus", 400);
            }
            if (isset($feries[$date])) {
                throw new \DomainException("Le jour férié $date est en double", 400);
            }
            $feries[$date] = ['date' => $date, 'libelle' => trim($libelle)];
        }
        ksort($feries);

        return array_values($feries);
    }

    /**
     * @return array<string, array{sujet: string, message: string}> modèles personnalisés après fusion
     */
    private function validerModeles(mixed $modeles): array
    {
        if (!is_array($modeles) || ($modeles !== [] && array_is_list($modeles))) {
            throw new \DomainException('modelesMessages doit être un objet { type: {sujet, message} }', 400);
        }
        $resultat = $this->modelesPersonnalises();
        foreach ($modeles as $type => $modele) {
            $type = (string) $type;
            if (!isset(self::MODELES[$type])) {
                throw new \DomainException("Type de message inconnu : $type (types possibles : " . implode(', ', array_keys(self::MODELES)) . ')', 400);
            }
            if ($modele === null) {
                unset($resultat[$type]);
                continue;
            }
            $sujet = is_array($modele) ? ($modele['sujet'] ?? null) : null;
            $message = is_array($modele) ? ($modele['message'] ?? null) : null;
            if (!is_string($sujet) || trim($sujet) === '' || mb_strlen($sujet) > self::SUJET_MAX
                || !is_string($message) || trim($message) === '' || mb_strlen($message) > self::MESSAGE_MAX) {
                throw new \DomainException(sprintf(
                    '%s : sujet (%d caractères au plus) et message (%d caractères au plus) sont obligatoires',
                    $type, self::SUJET_MAX, self::MESSAGE_MAX
                ), 400);
            }
            foreach ([$sujet, $message] as $texte) {
                preg_match_all('/\{([^{}]*)\}/', $texte, $trouvees);
                $inconnues = array_diff(array_unique($trouvees[1]), self::MODELES[$type]['variables']);
                if ($inconnues !== []) {
                    throw new \DomainException(sprintf(
                        '%s : variable inconnue {%s} (variables possibles : %s)',
                        $type, implode('}, {', $inconnues), '{' . implode('}, {', self::MODELES[$type]['variables']) . '}'
                    ), 400);
                }
            }
            $resultat[$type] = ['sujet' => trim($sujet), 'message' => trim($message)];
        }

        return $resultat;
    }

    /** @return array<string, array{sujet: string, message: string}> */
    private function modelesPersonnalises(): array
    {
        $valeur = $this->lire(self::CLE_MODELES);

        return is_array($valeur) ? $valeur : [];
    }

    private function lire(string $cle): mixed
    {
        return $this->em->find(Parametre::class, $cle)?->getValeur();
    }

    private static function dateValide(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
