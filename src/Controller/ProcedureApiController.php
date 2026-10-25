<?php

namespace App\Controller;

use App\Entity\Procedure;
use App\Entity\Service;
use App\Entity\Utilisateur;
use App\Repository\ProcedureRepository;
use App\Repository\ServiceRepository;
use App\Service\CatalogueService;
use App\Service\RechercheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/procedures')]
class ProcedureApiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProcedureRepository $procedureRepository,
        private ServiceRepository $serviceRepository,
        private CatalogueService $catalogue,
        private RechercheService $recherche,
    ) {
    }

    /**
     * GET /api/procedures?serviceId=3&q=pasport&rubrique=État civil&inclureInactifs=1
     * Public : procédures actives d'un service actif. `inclureInactifs` (jeton agent ou admin) ajoute les
     * procédures désactivées (toutes pour l'admin, celles de son service pour l'agent).
     */
    #[Route('', name: 'api_procedures_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] ?Utilisateur $utilisateur): JsonResponse
    {
        $serviceId = $request->query->get('serviceId');
        $procedures = $this->procedureRepository->visibles(
            filter_var($request->query->get('inclureInactifs'), FILTER_VALIDATE_BOOLEAN),
            $this->isGranted('ROLE_ADMIN'),
            $this->isGranted('ROLE_AGENT') ? $utilisateur?->getService()?->getId() : null,
            $serviceId !== null && $serviceId !== '' ? (int) $serviceId : null,
        );

        $rubrique = trim((string) $request->query->get('rubrique', ''));
        if ($rubrique !== '') {
            $voulue = $this->recherche->normaliser($rubrique);
            $procedures = array_values(array_filter(
                $procedures,
                fn(Procedure $p) => $this->recherche->normaliser((string) $p->getService()->getCategorie()) === $voulue
            ));
        }

        $procedures = $this->recherche->filtrer(
            $procedures,
            (string) $request->query->get('q', ''),
            fn(Procedure $p) => [
                [$p->getNom(), 4],
                [$p->getService()->getNom(), 2],
                [$p->getService()->getCategorie(), 2],
                [$p->getService()->getMotsCles(), 2],
                [$p->getDescription(), 1],
                [implode(' ', array_map(fn($d) => $d->getLibelle(), $p->getDocumentRequis()->toArray())), 1],
            ]
        );

        return $this->json(array_map(fn(Procedure $p) => $this->catalogue->serialiser($p), $procedures));
    }

    #[Route('/{id}', name: 'api_procedures_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, #[CurrentUser] ?Utilisateur $utilisateur): JsonResponse
    {
        $procedure = $this->procedureRepository->find($id);

        if (!$procedure || !$this->catalogue->estVisible($procedure, $utilisateur)) {
            return $this->json(['error' => 'Procédure non trouvée'], 404);
        }

        return $this->json($this->catalogue->serialiser($procedure));
    }

    #[Route('', name: 'api_procedures_create', methods: ['POST'])]
    #[IsGranted('ROLE_AGENT')]
    public function create(Request $request, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        try {
            $data = $this->corps($request);
            if (empty($data['serviceId']) || !is_scalar($data['serviceId'])) {
                return $this->json(['error' => 'serviceId est requis'], 400);
            }
            $service = $this->serviceRepository->find($data['serviceId']);
            if (!$service) {
                return $this->json(['error' => 'Service introuvable'], 404);
            }
            $this->catalogue->exigerDroit($utilisateur, $service);
            $procedure = $this->catalogue->enregistrer(null, $service, $data, $utilisateur);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 400);
        }

        return $this->json($this->catalogue->serialiser($procedure), 201);
    }

    #[Route('/{id}', name: 'api_procedures_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_AGENT')]
    public function update(int $id, Request $request, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $procedure = $this->procedureRepository->find($id);
        if (!$procedure) {
            return $this->json(['error' => 'Procédure non trouvée'], 404);
        }

        try {
            $this->catalogue->exigerDroit($utilisateur, $procedure->getService());
            $data = $this->corps($request);
            $service = $this->serviceCible($procedure, $data, $utilisateur);
            $this->catalogue->enregistrer($procedure, $service, $data, $utilisateur);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 400);
        }

        return $this->json($this->catalogue->serialiser($procedure));
    }

    /** Suppression logique : la procédure est désactivée (réactivable avec PUT {"actif": true}). */
    #[Route('/{id}', name: 'api_procedures_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_AGENT')]
    public function delete(int $id, #[CurrentUser] Utilisateur $utilisateur): Response
    {
        $procedure = $this->procedureRepository->find($id);
        if (!$procedure) {
            return $this->json(['error' => 'Procédure non trouvée'], 404);
        }

        try {
            $this->catalogue->exigerDroit($utilisateur, $procedure->getService());
            $this->catalogue->desactiver($procedure, $utilisateur);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 400);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Historique des versions (instantanés complets), de la plus récente à la plus ancienne. */
    #[Route('/{id}/versions', name: 'api_procedures_versions', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_AGENT')]
    public function versions(int $id, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $procedure = $this->procedureRepository->find($id);
        if (!$procedure) {
            return $this->json(['error' => 'Procédure non trouvée'], 404);
        }

        try {
            $this->catalogue->exigerDroit($utilisateur, $procedure->getService());
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode());
        }

        return $this->json($this->catalogue->versions($procedure));
    }

    /** « Information vérifiée » : met à jour la date de vérification sans créer de nouvelle version. */
    #[Route('/{id}/verification', name: 'api_procedures_verification', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_AGENT')]
    public function verification(int $id, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $procedure = $this->procedureRepository->find($id);
        if (!$procedure) {
            return $this->json(['error' => 'Procédure non trouvée'], 404);
        }

        try {
            $this->catalogue->exigerDroit($utilisateur, $procedure->getService());
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode());
        }
        $this->catalogue->verifier($procedure, $utilisateur);

        return $this->json($this->catalogue->serialiser($procedure));
    }

    /** @return array<string, mixed> */
    private function corps(Request $request): array
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new \DomainException('Le corps de la requête doit être un objet JSON', 400);
        }

        return $data;
    }

    /** Déplacer une procédure vers un autre service est réservé à l'admin. */
    private function serviceCible(Procedure $procedure, array $data, Utilisateur $utilisateur): Service
    {
        if (!isset($data['serviceId']) || !is_scalar($data['serviceId']) || (int) $data['serviceId'] === $procedure->getService()->getId()) {
            return $procedure->getService();
        }
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw new \DomainException('Seul l\'administrateur peut changer le service d\'une procédure', 403);
        }
        $service = $this->serviceRepository->find($data['serviceId']);
        if (!$service) {
            throw new \DomainException('Service introuvable', 404);
        }

        return $service;
    }
}
