<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Service\JournalService;
use App\Service\ParametreService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paramétrage (EF-46) : modèles de messages, jours fériés, durée par défaut des créneaux.
 * Écriture réservée à l'administrateur ; les jours fériés sont lisibles par l'agent (génération de créneaux).
 */
class ParametreApiController extends AbstractController
{
    public function __construct(
        private ParametreService $parametres,
        private JournalService $journal,
    ) {
    }

    #[Route('/api/admin/parametres', name: 'api_admin_parametres_lire', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function lire(): JsonResponse
    {
        return $this->json($this->parametres->tout());
    }

    /** Modification partielle, voir ParametreService::modifier(). */
    #[Route('/api/admin/parametres', name: 'api_admin_parametres_modifier', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function modifier(Request $request, #[CurrentUser] Utilisateur $admin): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            return $this->json(['error' => 'Le corps de la requête doit être un objet JSON'], 400);
        }

        try {
            $cles = $this->parametres->modifier($data, $admin);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 400);
        }
        $this->journal->enregistrer('parametres_modifies', $admin, 'cles=' . implode(',', $cles));

        return $this->json($this->parametres->tout());
    }

    /** Jours fériés (filtre facultatif ?du=AAAA-MM-JJ&au=AAAA-MM-JJ), pour l'agent qui prépare ses créneaux. */
    #[Route('/api/jours-feries', name: 'api_jours_feries', methods: ['GET'])]
    #[IsGranted('ROLE_AGENT')]
    public function joursFeries(Request $request): JsonResponse
    {
        $du = $request->query->get('du');
        $au = $request->query->get('au');
        foreach (['du' => $du, 'au' => $au] as $nom => $valeur) {
            if ($valeur !== null && (!is_string($valeur) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur))) {
                return $this->json(['error' => "$nom invalide (format AAAA-MM-JJ)"], 400);
            }
        }
        $feries = array_values(array_filter(
            $this->parametres->joursFeries(),
            fn(array $j) => ($du === null || $j['date'] >= $du) && ($au === null || $j['date'] <= $au)
        ));

        return $this->json($feries);
    }
}
