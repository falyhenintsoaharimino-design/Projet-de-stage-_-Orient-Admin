<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client HTTP du service Python d'orientation (dossier moteur/).
 * Toutes les méthodes renvoient null si le service est désactivé (MOTEUR_URL vide),
 * arrêté, trop lent ou répond une erreur : l'appelant décide alors du repli.
 */
class MoteurClient
{
    public function __construct(
        private HttpClientInterface $http,
        private LoggerInterface $logger,
        #[Autowire(env: 'MOTEUR_URL')] private string $url,
        #[Autowire(env: 'MOTEUR_TOKEN')] private string $jeton,
        #[Autowire('%env(float:MOTEUR_SEUIL)%')] private float $seuil,
    ) {
    }

    public function actif(): bool
    {
        return trim($this->url) !== '';
    }

    /**
     * @return ?array{service: string, confiance: float, compris: bool, alternatives: array<array{service: string, confiance: float}>}
     */
    public function orienter(string $texte): ?array
    {
        $r = $this->appeler('POST', '/orienter', ['texte' => $texte, 'seuil' => $this->seuil], 3);
        if (!is_array($r) || !isset($r['service'], $r['confiance'], $r['alternatives'])
            || !is_string($r['service']) || !is_numeric($r['confiance']) || !is_array($r['alternatives'])) {
            return null;
        }

        return [
            'service' => $r['service'],
            'confiance' => (float) $r['confiance'],
            'compris' => (bool) ($r['compris'] ?? false),
            'alternatives' => array_values(array_filter(
                $r['alternatives'],
                fn($a) => is_array($a) && is_string($a['service'] ?? null) && is_numeric($a['confiance'] ?? null)
            )),
        ];
    }

    public function sante(): ?array
    {
        return $this->appeler('GET', '/sante', null, 3);
    }

    /** Dernières métriques calculées par `python evaluer.py` (null si indisponibles). */
    public function metriques(): ?array
    {
        return $this->appeler('GET', '/metriques', null, 5);
    }

    /** @param array<int, array{texte: string, service: string}> $exemples */
    public function reentrainer(array $exemples): ?array
    {
        return $this->appeler('POST', '/reentrainer', ['exemples' => $exemples], 120);
    }

    private function appeler(string $methode, string $chemin, ?array $json, int $delai): ?array
    {
        if (!$this->actif()) {
            return null;
        }

        $options = ['timeout' => $delai, 'max_duration' => $delai + 2, 'headers' => ['Accept' => 'application/json']];
        if ($this->jeton !== '') {
            $options['headers']['X-Moteur-Token'] = $this->jeton;
        }
        if ($json !== null) {
            $options['json'] = $json;
        }

        try {
            return $this->http->request($methode, rtrim($this->url, '/') . $chemin, $options)->toArray();
        } catch (\Throwable $e) {
            $this->logger->warning('Moteur d\'orientation indisponible : ' . $e->getMessage(), ['chemin' => $chemin]);

            return null;
        }
    }
}
