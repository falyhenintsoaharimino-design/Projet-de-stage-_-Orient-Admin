<?php

namespace App\Controller;

use App\Entity\Service;
use App\Entity\Utilisateur;
use App\Repository\ServiceRepository;
use App\Service\JournalService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/services')]
class ServiceApiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ServiceRepository $serviceRepository,
        private JournalService $journal,
    ) {
    }

    #[Route('', name: 'api_services_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        // Le public ne voit que les services actifs ; l'admin voit tout (jeton facultatif sur cette route).
        $services = $this->isGranted('ROLE_ADMIN')
            ? $this->serviceRepository->findBy([], ['id' => 'ASC'])
            : $this->serviceRepository->findBy(['actif' => true], ['id' => 'ASC']);

        $data = array_map(fn(Service $s) => $this->toArray($s), $services);

        return $this->json($data);
    }

    #[Route('/{id}', name: 'api_services_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $service = $this->serviceRepository->find($id);

        if (!$service || (!$service->isActif() && !$this->isGranted('ROLE_ADMIN'))) {
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
    public function delete(int $id, #[CurrentUser] ?Utilisateur $admin): Response
    {
        $service = $this->serviceRepository->find($id);

        if (!$service) {
            return $this->json(['error' => 'Service non trouvé'], 404);
        }

        // Suppression logique : le service est désactivé (les demandes, rendez-vous et statistiques
        // qui s'y rattachent restent intacts) ; l'admin peut le réactiver avec PUT {"actif": true}.
        $service->setActif(false);
        $this->em->flush();
        $this->journal->enregistrer('service_desactive', $admin, 'service=' . $id);

        return new Response(null, Response::HTTP_NO_CONTENT);
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