<?php

namespace App\Controller;

use App\Entity\Creneau;
use App\Entity\Service;
use App\Entity\Utilisateur;
use App\Repository\CreneauRepository;
use App\Repository\RendezVousRepository;
use App\Repository\ServiceRepository;
use App\Service\Fuseau;
use App\Service\GenerateurCreneaux;
use App\Service\JournalService;
use App\Service\ParametreService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Créneaux de rendez-vous d'un service. Lecture : tout utilisateur connecté
 * (le citoyen ne voit que les créneaux ouverts qui ont encore une place). Écriture : agent du
 * service concerné, ou administrateur.
 *
 * Un créneau a une durée et une capacité (nombre de rendez-vous simultanés). `disponible` = ouvert par
 * l'agent (false = bloqué : absence, fermeture exceptionnelle) ; `placesRestantes` = capacité - réservations.
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
        private JournalService $journal,
        private GenerateurCreneaux $generateur,
        private Fuseau $fuseau,
        private ParametreService $parametres,
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
        $reservations = $this->rendezVousRepository->compterActifsParCreneau(array_map(fn(Creneau $c) => $c->getId(), $creneaux));
        if (!$estPersonnel) {
            // Le citoyen ne voit que les créneaux à venir qui ont encore une place.
            $maintenant = new \DateTimeImmutable();
            $creneaux = array_values(array_filter($creneaux, fn(Creneau $c) => ($reservations[$c->getId()] ?? 0) < $c->getCapacite()
                && ($c->getDebut($this->fuseau->get()) ?? $maintenant) >= $maintenant));
        }

        return $this->json(array_map(fn(Creneau $c) => $this->toArray($c, $reservations[$c->getId()] ?? 0), $creneaux));
    }

    /**
     * Corps : { "date": "2026-10-12", "heures": ["08:00", "08:30"], "capacite": 2, "dureeMinutes": 30 }
     * (ou "heure" pour un seul créneau ; capacite et dureeMinutes sont facultatifs : par défaut ceux du service).
     * Un admin ajoute "serviceId".
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
        if ($dateObj->format('Y-m-d') < $this->fuseau->aujourdhui()->format('Y-m-d')) {
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

        $capacite = $data['capacite'] ?? $service->getCapaciteCreneau();
        $duree = $data['dureeMinutes'] ?? $service->getDureeCreneauMinutes();
        if (!$this->entierDans($capacite, 1, 100) || !$this->entierDans($duree, 5, 240)) {
            return $this->json(['error' => 'capacite (1 à 100) et dureeMinutes (5 à 240) doivent être des entiers'], 400);
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
            $creneau->setCapacite($capacite);
            $creneau->setDureeMinutes($duree);
            $this->em->persist($creneau);
            $crees[] = $creneau;
        }
        $this->em->flush();
        if ($crees !== []) {
            $this->journal->enregistrer('creneau_cree', $utilisateur, sprintf('service=%d date=%s creneaux=%s', $service->getId(), $date, implode(',', array_map(fn(Creneau $c) => $c->getId(), $crees))));
        }

        return $this->json([
            'crees' => array_map(fn(Creneau $c) => $this->toArray($c, 0), $crees),
            'ignores' => $ignores,
        ], 201);
    }

    /**
     * Génération en lot (EF-23). Corps :
     * { "dateDebut": "2026-11-02", "dateFin": "2026-11-27", "heureDebut": "08:00", "heureFin": "15:00",
     *   "pauseDebut": "12:00", "pauseFin": "13:00",            (facultatif : aucun créneau pendant la pause)
     *   "dureeMinutes": 30, "capacite": 2,                      (facultatifs : par défaut ceux du service)
     *   "joursFermes": [6, 7],                                  (facultatif : 1 = lundi ... 7 = dimanche ; par défaut ceux du service)
     *   "datesFermees": ["2026-11-01"] }                        (facultatif : fermetures exceptionnelles)
     * Un admin ajoute "serviceId". Les créneaux déjà existants sont ignorés ; ceux déjà passés ne sont pas créés.
     */
    #[Route('/generer', name: 'api_creneaux_generer', methods: ['POST'])]
    #[IsGranted('ROLE_AGENT')]
    public function generer(Request $request, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Le corps de la requête doit être un objet JSON'], 400);
        }
        $service = $this->serviceCible($utilisateur, $data['serviceId'] ?? null);
        if (!$service) {
            return $this->json(['error' => 'Service introuvable ou non rattaché à votre compte'], 400);
        }

        $dateDebut = $data['dateDebut'] ?? null;
        $dateFin = $data['dateFin'] ?? $dateDebut;
        foreach (['dateDebut' => $dateDebut, 'dateFin' => $dateFin] as $nom => $valeur) {
            if (!is_string($valeur) || !$this->dateValide($valeur)) {
                return $this->json(['error' => "$nom invalide (format AAAA-MM-JJ)"], 400);
            }
        }
        if ($dateFin < $dateDebut) {
            return $this->json(['error' => 'dateFin doit être après dateDebut'], 400);
        }
        if ($dateFin < $this->fuseau->aujourdhui()->format('Y-m-d')) {
            return $this->json(['error' => 'La période ne peut pas être dans le passé'], 400);
        }
        if ((new \DateTime($dateDebut))->diff(new \DateTime($dateFin))->days > 92) {
            return $this->json(['error' => 'La période ne peut pas dépasser 93 jours'], 400);
        }

        foreach (['heureDebut', 'heureFin'] as $cle) {
            if (!is_string($data[$cle] ?? null) || !preg_match(self::HEURE_REGEX, $data[$cle])) {
                return $this->json(['error' => "$cle invalide (format HH:MM)"], 400);
            }
        }
        if ($data['heureFin'] <= $data['heureDebut']) {
            return $this->json(['error' => 'heureFin doit être après heureDebut'], 400);
        }
        $pauseDebut = $data['pauseDebut'] ?? null;
        $pauseFin = $data['pauseFin'] ?? null;
        if (($pauseDebut === null) !== ($pauseFin === null)
            || ($pauseDebut !== null && (!is_string($pauseDebut) || !is_string($pauseFin)
                || !preg_match(self::HEURE_REGEX, $pauseDebut) || !preg_match(self::HEURE_REGEX, $pauseFin) || $pauseFin <= $pauseDebut))) {
            return $this->json(['error' => 'pauseDebut et pauseFin vont ensemble (HH:MM, la fin après le début)'], 400);
        }

        $duree = $data['dureeMinutes'] ?? $service->getDureeCreneauMinutes();
        $capacite = $data['capacite'] ?? $service->getCapaciteCreneau();
        if (!$this->entierDans($duree, 5, 240) || !$this->entierDans($capacite, 1, 100)) {
            return $this->json(['error' => 'dureeMinutes (5 à 240) et capacite (1 à 100) doivent être des entiers'], 400);
        }
        $joursFermes = $data['joursFermes'] ?? $service->getJoursFermes();
        if (!is_array($joursFermes) || !array_is_list($joursFermes) || array_filter($joursFermes, fn($j) => !$this->entierDans($j, 1, 7)) !== []) {
            return $this->json(['error' => 'joursFermes doit être une liste d\'entiers de 1 (lundi) à 7 (dimanche)'], 400);
        }
        $datesFermees = $data['datesFermees'] ?? [];
        if (!is_array($datesFermees) || count($datesFermees) > 366
            || array_filter($datesFermees, fn($d) => !is_string($d) || !$this->dateValide($d)) !== []) {
            return $this->json(['error' => 'datesFermees doit être une liste de dates AAAA-MM-JJ'], 400);
        }

        // Jours fériés du paramétrage (EF-46) : jamais de créneaux ces jours-là.
        $joursFeriesExclus = $this->parametres->datesFeriees($dateDebut, $dateFin);
        $jours = $this->generateur->jours($dateDebut, $dateFin, $joursFermes, array_merge($datesFermees, $joursFeriesExclus));
        $heures = $this->generateur->horaires($data['heureDebut'], $data['heureFin'], $duree, $pauseDebut, $pauseFin);
        if ($heures === []) {
            return $this->json(['error' => 'Aucun créneau ne tient dans cette plage horaire avec cette durée'], 400);
        }
        if (count($jours) * count($heures) > 3000) {
            return $this->json(['error' => 'Trop de créneaux d\'un coup (3000 au plus) : réduisez la période'], 400);
        }

        $existants = [];
        foreach ($this->creneauRepository->createQueryBuilder('c')
            ->where('c.service = :s')->andWhere('c.date BETWEEN :d AND :f')
            ->setParameter('s', $service)->setParameter('d', $dateDebut)->setParameter('f', $dateFin)
            ->getQuery()->getResult() as $c) {
            $existants[$c->getDate()->format('Y-m-d') . ' ' . $c->getHeure()] = true;
        }

        $maintenant = new \DateTimeImmutable();
        $crees = 0;
        $ignores = 0;
        foreach ($jours as $jour) {
            foreach ($heures as $heure) {
                if (isset($existants["$jour $heure"])) {
                    ++$ignores;
                    continue;
                }
                $creneau = (new Creneau())->setService($service)->setDate(new \DateTime($jour))->setHeure($heure)
                    ->setDisponible(true)->setCapacite($capacite)->setDureeMinutes($duree);
                if (($creneau->getDebut($this->fuseau->get()) ?? $maintenant) < $maintenant) {
                    continue; // créneau déjà passé aujourd'hui
                }
                $this->em->persist($creneau);
                ++$crees;
            }
        }
        $this->em->flush();
        if ($crees > 0) {
            $this->journal->enregistrer('creneaux_generes', $utilisateur, sprintf('service=%d du=%s au=%s crees=%d', $service->getId(), $dateDebut, $dateFin, $crees));
        }

        return $this->json([
            'crees' => $crees,
            'ignores' => $ignores,
            'joursOuverts' => count($jours),
            'creneauxParJour' => count($heures),
            'dureeMinutes' => $duree,
            'capacite' => $capacite,
            'joursFermes' => $joursFermes,
            'joursFeriesExclus' => $joursFeriesExclus,
        ], 201);
    }

    /** Ouvre ou bloque un créneau et/ou change sa capacité : { "disponible": true|false, "capacite": 3 } (au moins un des deux). */
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
        if (!array_key_exists('disponible', $data) && !array_key_exists('capacite', $data)) {
            return $this->json(['error' => 'disponible (booléen) ou capacite (entier) est requis'], 400);
        }
        if (array_key_exists('disponible', $data) && !is_bool($data['disponible'])) {
            return $this->json(['error' => 'disponible (booléen) est requis'], 400);
        }
        $reservations = $this->rendezVousRepository->compterActifs($creneau->getId());
        if (array_key_exists('capacite', $data)) {
            if (!$this->entierDans($data['capacite'], 1, 100)) {
                return $this->json(['error' => 'capacite doit être un entier de 1 à 100'], 400);
            }
            if ($data['capacite'] < $reservations) {
                return $this->json(['error' => sprintf('Ce créneau a déjà %d rendez-vous actifs : la capacité ne peut pas être plus basse', $reservations)], 409);
            }
            $creneau->setCapacite($data['capacite']);
        }
        if (array_key_exists('disponible', $data)) {
            $creneau->setDisponible($data['disponible']);
        }
        $this->em->flush();
        $detail = sprintf('creneau=%d', $creneau->getId());
        if (array_key_exists('disponible', $data)) {
            $detail .= ' disponible=' . ($data['disponible'] ? 'oui' : 'non');
        }
        if (array_key_exists('capacite', $data)) {
            $detail .= ' capacite=' . $data['capacite'];
        }
        $this->journal->enregistrer('creneau_modifie', $utilisateur, $detail);

        return $this->json($this->toArray($creneau, $reservations));
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

        $detail = sprintf('creneau=%d service=%d date=%s heure=%s', $creneau->getId(), $creneau->getService()?->getId() ?? 0, $creneau->getDate()?->format('Y-m-d'), $creneau->getHeure());
        $this->em->remove($creneau);
        $this->em->flush();
        $this->journal->enregistrer('creneau_supprime', $utilisateur, $detail);

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

    private function entierDans(mixed $valeur, int $min, int $max): bool
    {
        return is_int($valeur) && $valeur >= $min && $valeur <= $max;
    }

    private function dateValide(string $date): bool
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $date);

        return $d !== false && $d->format('Y-m-d') === $date;
    }

    private function toArray(Creneau $creneau, int $reservations): array
    {
        $places = max(0, $creneau->getCapacite() - $reservations);

        return [
            'id' => $creneau->getId(),
            'date' => $creneau->getDate()?->format('Y-m-d'),
            'heure' => $creneau->getHeure(),
            'heureFin' => $creneau->getFin()?->format('H:i'),
            'dureeMinutes' => $creneau->getDureeMinutes(),
            'disponible' => $creneau->isDisponible(),
            'capacite' => $creneau->getCapacite(),
            'reservations' => $reservations,
            'placesRestantes' => $places,
            'reservable' => $creneau->isDisponible() && $places > 0,
            'service' => [
                'id' => $creneau->getService()?->getId(),
                'nom' => $creneau->getService()?->getNom(),
            ],
        ];
    }
}
