<?php

namespace App\Tests\Controller;

use App\Entity\Creneau;
use App\Entity\Demande;
use App\Entity\DocumentRequis;
use App\Entity\Procedure;
use App\Entity\Service;

/**
 * Données de test pour les rendez-vous : services « Rdv-test », créneaux, procédures, citoyens avec une demande.
 * Appeler nettoyerRendezVous() dans tearDown(). À utiliser avec OutilsDeTestTrait.
 */
trait OutilsRendezVousTrait
{
    /** @var list<int> */
    private array $services = [];

    /** Supprime les comptes (et leurs demandes, rendez-vous) puis les créneaux, procédures et services de test. */
    protected function nettoyerRendezVous(): void
    {
        $this->nettoyerComptes();
        $em = $this->em();
        $em->clear();
        if ($this->services !== []) {
            $em->createQuery('DELETE App\Entity\Creneau c WHERE c.service IN (:ids)')->setParameter('ids', $this->services)->execute();
            foreach ($em->getRepository(Procedure::class)->findBy(['service' => $this->services]) as $procedure) {
                $em->remove($procedure);
            }
            $em->flush();
            $em->createQuery('DELETE App\Entity\Service s WHERE s.id IN (:ids)')->setParameter('ids', $this->services)->execute();
        }
        $this->services = [];
    }

    private function creerService(string $adresse = 'Analakely, Antananarivo'): int
    {
        $em = $this->em();
        $service = new Service();
        $service->setNom('Rdv-test service ' . bin2hex(random_bytes(4)));
        $service->setActif(true);
        $service->setAdresse($adresse);
        $em->persist($service);
        $em->flush();
        $this->services[] = $service->getId();

        return $service->getId();
    }

    private function jetonAgent(int $serviceId): string
    {
        $agent = $this->creerUtilisateur('agent');
        $agent->setService($this->em()->find(Service::class, $serviceId));
        $this->em()->flush();

        return $this->jetonDe($agent);
    }

    /** @return int identifiant du créneau */
    private function creerCreneau(int $serviceId, string $dateRelative, string $heure = '09:00', int $capacite = 1): int
    {
        $em = $this->em();
        $creneau = (new Creneau())->setService($em->find(Service::class, $serviceId))
            ->setDate(new \DateTime($dateRelative))->setHeure($heure)->setDisponible(true)->setCapacite($capacite);
        $em->persist($creneau);
        $em->flush();

        return $creneau->getId();
    }

    /** Citoyen (prévenu par SMS si $parSms) avec une demande orientée vers le service : [jeton, demandeId]. */
    private function citoyenAvecDemande(int $serviceId, bool $parSms = false): array
    {
        $em = $this->em();
        $citoyen = $this->creerUtilisateur('citoyen');
        if ($parSms) {
            $citoyen->setTelephone('+261340000' . random_int(100, 999))->setCanalNotification('sms');
        }
        $demande = new Demande();
        $demande->setTextDemande('Je veux un acte de naissance')->setStatus('orientee')
            ->setDateCreation(new \DateTimeImmutable())->setUtilisateur($citoyen)
            ->setServiceFinal($em->find(Service::class, $serviceId));
        $em->persist($demande);
        $em->flush();

        return [$this->jetonDe($citoyen), $demande->getId()];
    }

    /** Procédure du service avec deux pièces. @return int */
    private function creerProcedure(int $serviceId): int
    {
        $em = $this->em();
        $procedure = (new Procedure())->setNom('Rdv-test acte de naissance')->setService($em->find(Service::class, $serviceId));
        $em->persist($procedure);
        foreach ([['Livret de famille', true, 2, 3, 'Fokontany'], ['Photo', false, 1, null, null]] as [$libelle, $obligatoire, $exemplaires, $anciennete, $ou]) {
            $d = (new DocumentRequis())->setLibelle($libelle)->setObligatoire($obligatoire)->setExemplaires($exemplaires)
                ->setAncienneteMaxMois($anciennete)->setOuObtenir($ou);
            $procedure->addDocumentRequi($d);
            $em->persist($d);
        }
        $em->flush();

        return $procedure->getId();
    }

    /** Demain (AAAA-MM-JJ) dans le fuseau de l'application : « demain 00:00 » est alors toujours à moins de 24 h. */
    private function demain(): string
    {
        return (new \DateTime('tomorrow', new \DateTimeZone($_SERVER['APP_TIMEZONE'] ?? $_ENV['APP_TIMEZONE'] ?? 'Indian/Antananarivo')))->format('Y-m-d');
    }

    private function statut(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): int
    {
        return $client->getResponse()->getStatusCode();
    }

    private function reserver($client, string $jeton, int $demande, int $creneau, ?int $procedure = null): array
    {
        $this->appeler($client, 'POST', '/api/rendez-vous', $jeton, json_encode(array_filter(
            ['demandeId' => $demande, 'creneauId' => $creneau, 'procedureId' => $procedure], fn($v) => $v !== null
        )));

        return $this->json($client) ?? [];
    }

    /** @return list<array<string, mixed>> */
    private function notifications($client, string $jeton): array
    {
        $this->appeler($client, 'GET', '/api/notifications', $jeton);
        $liste = $this->json($client);

        return $liste['items'] ?? $liste;
    }

    private function jetonCitoyenDe(int $demandeId): string
    {
        return $this->jetonDe($this->em()->find(Demande::class, $demandeId)->getUtilisateur());
    }

}
