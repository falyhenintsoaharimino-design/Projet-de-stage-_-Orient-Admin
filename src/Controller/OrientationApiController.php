<?php

namespace App\Controller;

use App\Service\OrientationEngine;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Permet à un VISITEUR (non connecté) d'essayer l'assistant d'orientation
 * depuis pages/public/assistant.html, exactement comme le prévoit le cahier
 * des charges (EF-06 : « Utilisation de l'assistant sans compte »).
 *
 * Différence avec DemandeApiController::create() : ici, rien n'est persisté
 * en base (pas de Demande, pas d'historique) puisqu'il n'y a pas encore de
 * citoyen identifié à qui rattacher la demande. Le citoyen connecté, lui,
 * passe par POST /api/demandes, qui garde une trace exploitable.
 */
#[Route('/api/orientation')]
class OrientationApiController extends AbstractController
{
    public function __construct(
        private OrientationEngine $orientationEngine,
    ) {
    }

    #[Route('/tester', name: 'api_orientation_tester', methods: ['POST'])]
    public function tester(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $texte = trim($data['texte'] ?? '');

        if ($texte === '') {
            return $this->json(['error' => 'Le texte de la demande est requis'], 400);
        }

        $resultat = $this->orientationEngine->orienter($texte);

        return $this->json([
            'service' => $this->serviceResume($resultat['service']),
            'confiance' => $resultat['confiance'],
            'alternatives' => array_map(
                fn(array $a) => [
                    'service' => $this->serviceResume($a['service']),
                    'confiance' => $a['confiance'],
                ],
                $resultat['alternatives']
            ),
        ]);
    }

    private function serviceResume(?\App\Entity\Service $service): ?array
    {
        if (!$service) {
            return null;
        }

        return [
            'id' => $service->getId(),
            'nom' => $service->getNom(),
            'adresse' => $service->getAdresse(),
            'horaires' => $service->getHoraires(),
        ];
    }
}
