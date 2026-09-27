<?php

namespace App\Controller;

use App\Entity\Procedure;
use App\Repository\ProcedureRepository;
use App\Repository\ServiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/procedures')]
class ProcedureApiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProcedureRepository $procedureRepository,
        private ServiceRepository $serviceRepository,
    ) {
    }

    #[Route('', name: 'api_procedures_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        // Le frontend a besoin de lister les procédures d'un service donné
        // (fiche service -> "Procédures liées") : /api/procedures?serviceId=3
        $serviceId = $request->query->get('serviceId');

        $procedures = $serviceId
            ? $this->procedureRepository->findBy(['service' => (int) $serviceId])
            : $this->procedureRepository->findAll();

        $data = array_map(fn(Procedure $p) => $this->toArray($p), $procedures);

        return $this->json($data);
    }

    #[Route('/{id}', name: 'api_procedures_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $procedure = $this->procedureRepository->find($id);

        if (!$procedure) {
            return $this->json(['error' => 'Procédure non trouvée'], 404);
        }

        return $this->json($this->toArray($procedure));
    }

    #[Route('', name: 'api_procedures_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['serviceId'])) {
            return $this->json(['error' => 'serviceId est requis'], 400);
        }

        $service = $this->serviceRepository->find($data['serviceId']);
        if (!$service) {
            return $this->json(['error' => 'Service introuvable'], 404);
        }

        $procedure = new Procedure();
        $procedure->setNom($data['nom'] ?? '');
        $procedure->setDescription($data['description'] ?? null);
        $procedure->setDelaiEstime($data['delaiEstime'] ?? null);
        $procedure->setFrais($data['frais'] ?? null);
        $procedure->setVersion($data['version'] ?? null);
        $procedure->setService($service);

        $this->em->persist($procedure);
        $this->em->flush();

        return $this->json($this->toArray($procedure), 201);
    }

    #[Route('/{id}', name: 'api_procedures_update', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $procedure = $this->procedureRepository->find($id);

        if (!$procedure) {
            return $this->json(['error' => 'Procédure non trouvée'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['nom'])) $procedure->setNom($data['nom']);
        if (isset($data['description'])) $procedure->setDescription($data['description']);
        if (isset($data['delaiEstime'])) $procedure->setDelaiEstime($data['delaiEstime']);
        if (isset($data['frais'])) $procedure->setFrais($data['frais']);
        if (isset($data['version'])) $procedure->setVersion($data['version']);

        if (isset($data['serviceId'])) {
            $service = $this->serviceRepository->find($data['serviceId']);
            if (!$service) {
                return $this->json(['error' => 'Service introuvable'], 404);
            }
            $procedure->setService($service);
        }

        $this->em->flush();

        return $this->json($this->toArray($procedure));
    }

    #[Route('/{id}', name: 'api_procedures_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        $procedure = $this->procedureRepository->find($id);

        if (!$procedure) {
            return $this->json(['error' => 'Procédure non trouvée'], 404);
        }

        $this->em->remove($procedure);
        $this->em->flush();

        return $this->json(['message' => 'Procédure supprimée'], 204);
    }

    private function toArray(Procedure $procedure): array
    {
        return [
            'id' => $procedure->getId(),
            'nom' => $procedure->getNom(),
            'description' => $procedure->getDescription(),
            'delaiEstime' => $procedure->getDelaiEstime(),
            'frais' => $procedure->getFrais(),
            'version' => $procedure->getVersion(),
            'service' => [
                'id' => $procedure->getService()->getId(),
                'nom' => $procedure->getService()->getNom(),
            ],
            // Sans ces deux listes, la fiche procédure du frontend (étapes +
            // pièces à fournir) n'avait aucune donnée réelle à afficher.
            'etapes' => array_map(fn($e) => [
                'id' => $e->getId(),
                'ordre' => $e->getOrdre(),
                'description' => $e->getDescription(),
            ], $procedure->getEtapeProcedures()->toArray()),
            'documentsRequis' => array_map(fn($d) => [
                'id' => $d->getId(),
                'libelle' => $d->getLibelle(),
                'obligatoire' => $d->isObligatoire(),
                'remarque' => $d->getRemarque(),
            ], $procedure->getDocumentRequis()->toArray()),
        ];
    }
}