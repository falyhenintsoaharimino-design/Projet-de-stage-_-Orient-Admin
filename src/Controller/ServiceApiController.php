<?php

namespace App\Controller;

use App\Entity\Service;
use App\Repository\ServiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/services')]
class ServiceApiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ServiceRepository $serviceRepository,
    ) {
    }

    #[Route('', name: 'api_services_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $services = $this->serviceRepository->findAll();

        $data = array_map(fn(Service $s) => $this->toArray($s), $services);

        return $this->json($data);
    }

    #[Route('/{id}', name: 'api_services_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $service = $this->serviceRepository->find($id);

        if (!$service) {
            return $this->json(['error' => 'Service non trouvé'], 404);
        }

        return $this->json($this->toArray($service));
    }

    #[Route('', name: 'api_services_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $service = new Service();
        $service->setNom($data['nom'] ?? '');
        $service->setMission($data['mission'] ?? null);
        $service->setCategorie($data['categorie'] ?? null);
        $service->setAdresse($data['adresse'] ?? null);
        $service->setHoraires($data['horaires'] ?? null);
        $service->setContact($data['contact'] ?? null);
        $service->setActif($data['actif'] ?? true);
        $service->setMotsCles($data['motsCles'] ?? null);

        $this->em->persist($service);
        $this->em->flush();

        return $this->json($this->toArray($service), 201);
    }

    #[Route('/{id}', name: 'api_services_update', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $service = $this->serviceRepository->find($id);

        if (!$service) {
            return $this->json(['error' => 'Service non trouvé'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['nom'])) $service->setNom($data['nom']);
        if (isset($data['mission'])) $service->setMission($data['mission']);
        if (isset($data['categorie'])) $service->setCategorie($data['categorie']);
        if (isset($data['adresse'])) $service->setAdresse($data['adresse']);
        if (isset($data['horaires'])) $service->setHoraires($data['horaires']);
        if (isset($data['contact'])) $service->setContact($data['contact']);
        if (isset($data['actif'])) $service->setActif($data['actif']);
        if (isset($data['motsCles'])) $service->setMotsCles($data['motsCles']);

        $this->em->flush();

        return $this->json($this->toArray($service));
    }

    #[Route('/{id}', name: 'api_services_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        $service = $this->serviceRepository->find($id);

        if (!$service) {
            return $this->json(['error' => 'Service non trouvé'], 404);
        }

        $this->em->remove($service);
        $this->em->flush();

        return $this->json(['message' => 'Service supprimé'], 204);
    }

    private function toArray(Service $service): array
    {
        return [
            'id' => $service->getId(),
            'nom' => $service->getNom(),
            'mission' => $service->getMission(),
            'categorie' => $service->getCategorie(),
            'adresse' => $service->getAdresse(),
            'horaires' => $service->getHoraires(),
            'contact' => $service->getContact(),
            'actif' => $service->isActif(),
            'motsCles' => $service->getMotsCles(),
        ];
    }
}