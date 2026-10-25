<?php

namespace App\Controller;

use App\Entity\Service;
use App\Entity\Utilisateur;
use App\Repository\ServiceRepository;
use App\Service\JournalService;
use App\Service\RechercheService;
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
        private RechercheService $recherche,
    ) {
    }

    #[Route('', name: 'api_services_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        // Le public ne voit que les services actifs ; l'admin voit tout (jeton facultatif sur cette route).
        $services = $this->isGranted('ROLE_ADMIN')
            ? $this->serviceRepository->findBy([], ['id' => 'ASC'])
            : $this->serviceRepository->findBy(['actif' => true], ['id' => 'ASC']);

        // Filtre par rubrique (= catégorie) puis recherche tolérante aux fautes : ?rubrique=État civil&q=pasport
        $rubrique = trim((string) $request->query->get('rubrique', ''));
        if ($rubrique !== '') {
            $voulue = $this->recherche->normaliser($rubrique);
            $services = array_values(array_filter(
                $services,
                fn(Service $s) => $this->recherche->normaliser((string) $s->getCategorie()) === $voulue
            ));
        }
        $services = $this->recherche->filtrer($services, (string) $request->query->get('q', ''), fn(Service $s) => [
            [$s->getNom(), 4], [$s->getMotsCles(), 3], [$s->getCategorie(), 2], [$s->getMission(), 1],
        ]);

        $data = array_map(fn(Service $s) => $this->toArray($s), $services);

        return $this->json($data);
    }

    /** Rubriques du catalogue (catégories des services actifs) avec le nombre de services de chacune. */
    #[Route('/rubriques', name: 'api_services_rubriques', methods: ['GET'])]
    public function rubriques(): JsonResponse
    {
        $comptes = [];
        foreach ($this->serviceRepository->findBy(['actif' => true]) as $service) {
            $nom = trim((string) $service->getCategorie());
            if ($nom !== '') {
                $comptes[$nom] = ($comptes[$nom] ?? 0) + 1;
            }
        }
        ksort($comptes, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->json(array_map(
            fn(string $nom, int $n) => ['nom' => $nom, 'nombreServices' => $n],
            array_keys($comptes),
            array_values($comptes)
        ));
    }

    #[Route('/{id}', name: 'api_services_show', methods: ['GET'], requirements: ['id' => '\d+'])]
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

    /**
     * L'admin modifie tout. L'agent modifie seulement la fiche de SON service (mission, adresse, horaires,
     * contact, motsCles) ; nom, categorie et actif sont réservés à l'admin (403 si l'agent les envoie).
     */
    #[Route('/{id}', name: 'api_services_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_AGENT')]
    public function update(int $id, Request $request, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $service = $this->serviceRepository->find($id);

        if (!$service) {
            return $this->json(['error' => 'Service non trouvé'], 404);
        }

        $admin = $this->isGranted('ROLE_ADMIN');
        if (!$admin && $utilisateur->getService()?->getId() !== $service->getId()) {
            return $this->json(['error' => 'Vous ne pouvez modifier que la fiche de votre service'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Le corps de la requête doit être un objet JSON'], 400);
        }
        if (!$admin && array_intersect(['nom', 'categorie', 'actif'], array_keys($data)) !== []) {
            return $this->json(['error' => 'Seul l\'administrateur peut modifier nom, categorie et actif'], 403);
        }

        $maxima = ['nom' => 150, 'mission' => 5000, 'categorie' => 100, 'adresse' => 255, 'horaires' => 150, 'contact' => 150, 'motsCles' => 5000];
        foreach ($maxima as $cle => $max) {
            if (array_key_exists($cle, $data) && ($data[$cle] !== null || $cle === 'nom')
                && (!is_string($data[$cle]) || strlen($data[$cle]) > $max || ($cle === 'nom' && trim($data[$cle]) === ''))) {
                return $this->json(['error' => sprintf('%s doit être un texte de %d caractères au plus', $cle, $max)], 400);
            }
        }
        if (array_key_exists('actif', $data) && !is_bool($data['actif'])) {
            return $this->json(['error' => 'actif doit être true ou false'], 400);
        }

        // array_key_exists : envoyer null vide un champ facultatif (ex. horaires).
        if (array_key_exists('nom', $data)) $service->setNom($data['nom']);
        if (array_key_exists('mission', $data)) $service->setMission($data['mission']);
        if (array_key_exists('categorie', $data)) $service->setCategorie($data['categorie']);
        if (array_key_exists('adresse', $data)) $service->setAdresse($data['adresse']);
        if (array_key_exists('horaires', $data)) $service->setHoraires($data['horaires']);
        if (array_key_exists('contact', $data)) $service->setContact($data['contact']);
        if (array_key_exists('actif', $data)) $service->setActif($data['actif']);
        if (array_key_exists('motsCles', $data)) $service->setMotsCles($data['motsCles']);

        $this->em->flush();
        $champs = array_values(array_intersect(['nom', 'mission', 'categorie', 'adresse', 'horaires', 'contact', 'motsCles', 'actif'], array_keys($data)));
        $this->journal->enregistrer('service_modifie', $utilisateur, 'service=' . $id . ' champs=' . implode(',', $champs));

        return $this->json($this->toArray($service));
    }

    #[Route('/{id}', name: 'api_services_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
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