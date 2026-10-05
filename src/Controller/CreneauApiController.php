<?php

namespace App\Controller;

use App\Entity\Creneau;
use App\Entity\Service;
use App\Entity\Utilisateur;
use App\Repository\CreneauRepository;
use App\Repository\RendezVousRepository;
use App\Repository\ServiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Créneaux de rendez-vous d'un service. Lecture : tout utilisateur connecté
 * (le citoyen ne voit que les créneaux disponibles). Écriture : agent du
 * service concerné, ou administrateur.
 */
#[Route('/api/creneaux')]
class CreneauApiController extends AbstractController
{
    private const HEURE_REGEX = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public function __construct(
        private EntityManagerInterface $em,
        private CreneauRepository $creneauRepository,
        private RendezVousRepository $rendezVousRepository,
        private ServiceRepository $serviceRepository,
    ) {
    }

    #[Route('', name: 'api_creneaux_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $serviceId = $request->query->get('serviceId');
        $date = $request->query->get('date');

        if ($date !== null && !$this->dateValide($date)) {
            return $this->json(['error' => 'date invalide (format AAAA-MM-JJ)'], 400);
        }

        $estPersonnel = $this->isGranted('ROLE_AGENT') || $this->isGranted('ROLE_RESPONSABLE');
        if ($this->isGranted('ROLE_AGENT') && !$this->isGranted('ROLE_ADMIN') && $serviceId === null) {
            // Un agent sans filtre voit les créneaux de son propre service.
            $serviceId = $utilisateur->getService()?->getId();
            if ($serviceId === null) {
                return $this->json([]);
            }
        }

        $creneaux = $this->creneauRepository->rechercher(
            $serviceId !== null ? (int) $serviceId : null,
            $date,
            !$estPersonnel
        );

        return $this->json(array_map(fn(Creneau $c) => $this->toArray($c), $creneaux));
    }

    /**
     * Corps : { "date": "2026-10-12", "heures": ["08:00", "08:30"] }
     * (ou "heure" pour un seul créneau). Un admin ajoute "serviceId".
     * Les créneaux déjà existants pour ce service/date/heure sont ignorés.
     */
    #[Route('', name: 'api_creneaux_create', methods: ['POST'])]
    #[IsGranted('ROLE_AGENT')]
    public function create(Request $request, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $service = $this->serviceCible($utilisateur, $data['serviceId'] ?? null);
        if (!$service) {
            return $this->json(['error' => 'Service introuvable ou non rattaché à votre compte'], 400);
        }

        $date = $data['date'] ?? '';
        if (!is_string($date) || !$this->dateValide($date)) {
            return $this->json(['error' => 'date invalide (format AAAA-MM-JJ)'], 400);
        }
        $dateObj = new \DateTime($date);
        if ($dateObj < new \DateTime('today')) {
            return $this->json(['error' => 'La date ne peut pas être dans le passé'], 400);
        }

        $heures = $data['heures'] ?? (isset($data['heure']) ? [$data['heure']] : []);
        if (!is_array($heures) || $heures === []) {
            return $this->json(['error' => 'heures (ou heure) est requis'], 400);
        }
        foreach ($heures as $h) {
            if (!is_string($h) || !preg_match(self::HEURE_REGEX, $h)) {
                return $this->json(['error' => 'Heure invalide : ' . json_encode($h) . ' (format HH:MM)'], 400);
            }
        }

        $crees = [];
        $ignores = [];
        foreach (array_unique($heures) as $h) {
            $existe = $this->creneauRepository->findOneBy(['service' => $service, 'date' => $dateObj, 'heure' => $h]);
            if ($existe) {
                $ignores[] = $h;
                continue;
            }
            $creneau = new Creneau();
            $creneau->setService($service);
            $creneau->setDate(clone $dateObj);
            $creneau->setHeure($h);
            $creneau->setDisponible(true);
            $this->em->persist($creneau);
            $crees[] = $creneau;
        }
        $this->em->flush();

        return $this->json([
            'crees' => array_map(fn(Creneau $c) => $this->toArray($c), $crees),
            'ignores' => $ignores,
        ], 201);
    }

    /** Ouvre ou ferme un créneau : { "disponible": true|false }. */
    #[Route('/{id}', name: 'api_creneaux_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_AGENT')]
    public function update(int $id, Request $request, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $creneau = $this->creneauRepository->find($id);
        if (!$creneau) {
            return $this->json(['error' => 'Créneau non trouvé'], 404);
        }
        if (!$this->peutGerer($creneau, $utilisateur)) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        if (!isset($data['disponible']) || !is_bool($data['disponible'])) {
            return $this->json(['error' => 'disponible (booléen) est requis'], 400);
        }
        if ($data['disponible'] && $this->aRendezVousActif($creneau)) {
            return $this->json(['error' => 'Ce créneau a un rendez-vous en cours'], 409);
        }

        $creneau->setDisponible($data['disponible']);
        $this->em->flush();

        return $this->json($this->toArray($creneau));
    }

    #[Route('/{id}', name: 'api_creneaux_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_AGENT')]
    public function delete(int $id, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $creneau = $this->creneauRepository->find($id);
        if (!$creneau) {
            return $this->json(['error' => 'Créneau non trouvé'], 404);
        }
        if (!$this->peutGerer($creneau, $utilisateur)) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }
        // RendezVous.creneau est non nul : on ne supprime pas un créneau déjà
        // réservé (même annulé, l'historique du rendez-vous y est rattaché).
        if ($this->rendezVousRepository->findOneBy(['creneau' => $creneau])) {
            return $this->json(['error' => 'Ce créneau a déjà été réservé : fermez-le plutôt (disponible = false)'], 409);
        }

        $this->em->remove($creneau);
        $this->em->flush();

        return $this->json(null, 204);
    }

    private function serviceCible(Utilisateur $utilisateur, mixed $serviceId): ?Service
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return $serviceId ? $this->serviceRepository->find($serviceId) : $utilisateur->getService();
        }

        return $utilisateur->getService();
    }

    private function peutGerer(Creneau $creneau, Utilisateur $utilisateur): bool
    {
        return $this->isGranted('ROLE_ADMIN')
            || ($utilisateur->getService() !== null && $creneau->getService() === $utilisateur->getService());
    }

    private function aRendezVousActif(Creneau $creneau): bool
    {
        return $this->rendezVousRepository->findOneBy(['creneau' => $creneau, 'statut' => ['en_attente', 'confirme']]) !== null;
    }

    private function dateValide(string $date): bool
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $date);

        return $d !== false && $d->format('Y-m-d') === $date;
    }

    private function toArray(Creneau $creneau): array
    {
        return [
            'id' => $creneau->getId(),
            'date' => $creneau->getDate()?->format('Y-m-d'),
            'heure' => $creneau->getHeure(),
            'disponible' => $creneau->isDisponible(),
            'service' => [
                'id' => $creneau->getService()?->getId(),
                'nom' => $creneau->getService()?->getNom(),
            ],
        ];
    }
}
