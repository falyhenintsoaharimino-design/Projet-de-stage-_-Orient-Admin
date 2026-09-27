<?php

namespace App\Service;

use App\Entity\Service;
use App\Repository\ServiceRepository;

/**
 * Moteur d'orientation — version 1 (par mots-clés).
 *
 * Objectif : à partir du texte libre d'un citoyen, recommander le service
 * administratif le plus probable, avec un score de confiance et des
 * alternatives, comme le demande le cahier des charges (chapitre 7).
 *
 * Fonctionnement actuel : chaque Service porte une liste de mots-clés
 * (Service::motsCles, séparés par des virgules), modifiable par un agent ou
 * l'administrateur via l'API sans toucher au code. On compte, pour chaque
 * service actif, combien de ses mots-clés apparaissent dans le texte du
 * citoyen ; le score de confiance est la proportion de mots-clés du service
 * qui matchent, ramenée à une fourchette réaliste (jamais 0 %, jamais 100 %
 * pour rester honnête sur l'incertitude).
 *
 * C'est volontairement simple pour être livrable rapidement et 100 %
 * explicable dans le mémoire. Le cahier des charges (chapitre 7) prévoit une
 * évolution vers une approche sémantique (embeddings) : le jour où on voudra
 * la faire, il suffira d'ajouter une autre classe qui implémente la même
 * méthode `orienter()` et de l'injecter à sa place — le reste du code
 * (DemandeApiController) n'a pas à changer.
 */
class OrientationEngine
{
    /** En dessous de ce score, on ne recommande rien : mieux vaut demander
     *  une précision que d'orienter au hasard (voir EF-16 du cahier des charges). */
    private const SEUIL_CONFIANCE = 0.25;

    public function __construct(
        private readonly ServiceRepository $serviceRepository,
    ) {
    }

    /**
     * @return array{
     *   service: ?Service,
     *   confiance: float,
     *   alternatives: array<array{service: Service, confiance: float}>
     * }
     */
    public function orienter(string $texteDemande): array
    {
        $texte = $this->normaliser($texteDemande);
        $motsDuTexte = array_filter(explode(' ', $texte), fn(string $m) => $m !== '');

        $scores = [];
        foreach ($this->serviceRepository->findBy(['actif' => true]) as $service) {
            $motsCles = $service->getMotsClesListe();
            if ($motsCles === []) {
                continue;
            }

            $nbTrouves = 0;
            foreach ($motsCles as $motCle) {
                // Un mot-clé peut être composé de plusieurs mots ("carte
                // d'identité") : on cherche la sous-chaîne entière plutôt que
                // de comparer mot à mot.
                if (str_contains($texte, $this->normaliser($motCle))) {
                    $nbTrouves++;
                }
            }

            if ($nbTrouves === 0) {
                continue;
            }

            // Score = proportion de mots-clés du service qui matchent,
            // légèrement remonté pour qu'un seul mot-clé très spécifique
            // ("bulletin n3") donne déjà une confiance raisonnable.
            $proportion = $nbTrouves / count($motsCles);
            $confiance = min(0.97, 0.55 + $proportion * 0.42);

            $scores[] = ['service' => $service, 'confiance' => round($confiance, 2)];
        }

        if ($scores === []) {
            return ['service' => null, 'confiance' => 0.0, 'alternatives' => []];
        }

        usort($scores, fn($a, $b) => $b['confiance'] <=> $a['confiance']);

        $meilleur = $scores[0];
        if ($meilleur['confiance'] < self::SEUIL_CONFIANCE) {
            return ['service' => null, 'confiance' => $meilleur['confiance'], 'alternatives' => []];
        }

        return [
            'service' => $meilleur['service'],
            'confiance' => $meilleur['confiance'],
            // Jusqu'à 2 alternatives, pour le cas où le premier choix n'est
            // pas le bon (EF-15 : proposer les 3 meilleures).
            'alternatives' => array_slice($scores, 1, 2),
        ];
    }

    private function normaliser(string $texte): string
    {
        $texte = trim($texte);

        // Retire les accents les plus courants (majuscules et minuscules)
        // avant de mettre en minuscules : évite toute dépendance à
        // l'extension mbstring, qui n'est pas listée dans composer.json
        // (seules ctype et iconv le sont).
        $recherche = ['à','â','ä','é','è','ê','ë','î','ï','ô','ö','ù','û','ü','ç',
                      'À','Â','Ä','É','È','Ê','Ë','Î','Ï','Ô','Ö','Ù','Û','Ü','Ç'];
        $remplace  = ['a','a','a','e','e','e','e','i','i','o','o','u','u','u','c',
                      'a','a','a','e','e','e','e','i','i','o','o','u','u','u','c'];
        $texte = str_replace($recherche, $remplace, $texte);
        $texte = strtolower($texte);

        // Ponctuation -> espace, puis espaces multiples -> un seul espace.
        $texte = preg_replace('/[^a-z0-9\s]/', ' ', $texte) ?? $texte;

        return trim(preg_replace('/\s+/', ' ', $texte) ?? $texte);
    }
}
