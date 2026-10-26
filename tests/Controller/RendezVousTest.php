<?php

namespace App\Tests\Controller;

use App\Entity\Creneau;
use App\Entity\Demande;
use App\Entity\DocumentRequis;
use App\Entity\Procedure;
use App\Entity\Service;
use App\Entity\Utilisateur;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Étape 8 : rendez-vous (EF-23 à EF-29) : capacité des créneaux, génération en lot, délai limite du citoyen,
 * modification, report par l'agent, récapitulatif. Les données (services « Rdv-test », créneaux, procédures,
 * comptes) sont supprimées à la fin de chaque test.
 */
class RendezVousTest extends WebTestCase
{
    use OutilsDeTestTrait;

    /** @var list<int> */
    private array $services = [];

    protected function tearDown(): void
    {
        $this->nettoyerComptes(); // supprime aussi les demandes et rendez-vous des comptes
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
        parent::tearDown();
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

    /** Citoyen avec une demande orientée vers le service : [jeton, demandeId]. */
    private function citoyenAvecDemande(int $serviceId): array
    {
        $em = $this->em();
        $citoyen = $this->creerUtilisateur('citoyen');
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

    public function testCapaciteDuCreneauEtPlacesRestantes(): void
    {
        $client = static::createClient();
        $service = $this->creerService();
        $jetonAgent = $this->jetonAgent($service);
        $creneau = $this->creerCreneau($service, '+10 days', '09:00', 2);
        [$jeton1, $demande1] = $this->citoyenAvecDemande($service);
        [$jeton2, $demande2] = $this->citoyenAvecDemande($service);
        [$jeton3, $demande3] = $this->citoyenAvecDemande($service);
        $this->em()->clear();

        $r1 = $this->reserver($client, $jeton1, $demande1, $creneau);
        self::assertSame(201, $this->statut($client));
        self::assertSame('en_attente', $r1['statut']);
        $this->reserver($client, $jeton2, $demande2, $creneau);
        self::assertSame(201, $this->statut($client));

        // Troisième citoyen : le créneau est complet.
        $r3 = $this->reserver($client, $jeton3, $demande3, $creneau);
        self::assertSame(409, $this->statut($client));
        self::assertStringContainsString('complet', $r3['error']);
        // Une même demande ne peut pas prendre deux rendez-vous.
        $this->reserver($client, $jeton1, $demande1, $creneau);
        self::assertSame(409, $this->statut($client));

        // Le citoyen ne voit plus le créneau complet ; l'agent le voit avec ses chiffres.
        $this->appeler($client, 'GET', "/api/creneaux?serviceId=$service", $jeton3);
        self::assertSame([], $this->json($client));
        $this->appeler($client, 'GET', "/api/creneaux?serviceId=$service", $jetonAgent);
        $c = $this->json($client)[0];
        self::assertSame([2, 2, 0, false, true], [$c['capacite'], $c['reservations'], $c['placesRestantes'], $c['reservable'], $c['disponible']]);
        self::assertSame('09:30', $c['heureFin']);

        // Une annulation libère une place.
        $this->appeler($client, 'PATCH', "/api/rendez-vous/{$r1['id']}/annuler", $jeton1);
        self::assertSame(200, $this->statut($client));
        $this->appeler($client, 'GET', "/api/creneaux?serviceId=$service", $jeton3);
        self::assertSame(1, $this->json($client)[0]['placesRestantes']);
        $this->reserver($client, $jeton3, $demande3, $creneau);
        self::assertSame(201, $this->statut($client));
    }

    public function testBlocageDUnCreneauEtChangementDeCapacite(): void
    {
        $client = static::createClient();
        $service = $this->creerService();
        $jetonAgent = $this->jetonAgent($service);
        $creneau = $this->creerCreneau($service, '+10 days', '09:00', 2);
        [$jeton, $demande] = $this->citoyenAvecDemande($service);
        [$jeton2, $demande2] = $this->citoyenAvecDemande($service);
        $this->em()->clear();

        $this->reserver($client, $jeton, $demande, $creneau);
        self::assertSame(201, $this->statut($client));

        // L'agent bloque le créneau : plus de réservation possible, invisible du citoyen. Le rendez-vous existant reste.
        $this->appeler($client, 'PATCH', "/api/creneaux/$creneau", $jetonAgent, '{"disponible":false}');
        self::assertSame(200, $this->statut($client));
        $this->reserver($client, $jeton2, $demande2, $creneau);
        self::assertSame(409, $this->statut($client));
        $this->appeler($client, 'GET', "/api/creneaux?serviceId=$service", $jeton2);
        self::assertSame([], $this->json($client));

        // Rouvrir un créneau qui a déjà un rendez-vous est permis (plus de blocage « rendez-vous en cours »).
        $this->appeler($client, 'PATCH', "/api/creneaux/$creneau", $jetonAgent, '{"disponible":true}');
        self::assertSame(200, $this->statut($client));
        self::assertSame(1, $this->json($client)['reservations']);

        // Capacité : pas en dessous des rendez-vous actifs, entier de 1 à 100.
        $this->appeler($client, 'PATCH', "/api/creneaux/$creneau", $jetonAgent, '{"capacite":0}');
        self::assertSame(400, $this->statut($client));
        $this->appeler($client, 'PATCH', "/api/creneaux/$creneau", $jetonAgent, '{}');
        self::assertSame(400, $this->statut($client));
        $this->appeler($client, 'PATCH', "/api/creneaux/$creneau", $jetonAgent, '{"capacite":3}');
        self::assertSame(3, $this->json($client)['capacite']);
        $this->appeler($client, 'PATCH', "/api/creneaux/$creneau", $jetonAgent, '{"capacite":1}');
        self::assertSame(200, $this->statut($client));
        $this->reserver($client, $jeton2, $demande2, $creneau);
        self::assertSame(409, $this->statut($client), 'capacité ramenée à 1 : créneau complet');
    }

    public function testDelaiLimiteDuCitoyenMaisPasDeLAgent(): void
    {
        $client = static::createClient();
        $service = $this->creerService();
        $jetonAgent = $this->jetonAgent($service);
        // Demain 00:00 : toujours à moins de 24 h, mais encore dans le futur.
        $proche = $this->creerCreneau($service, $this->demain(), '00:00');
        $autre = $this->creerCreneau($service, '+10 days', '09:00');
        [$jeton, $demande] = $this->citoyenAvecDemande($service);
        $this->em()->clear();

        $rdv = $this->reserver($client, $jeton, $demande, $proche);
        self::assertSame(201, $this->statut($client));
        self::assertFalse($rdv['modifiableParLeCitoyen']);
        self::assertNotEmpty($rdv['modifiableJusquau']);

        $this->appeler($client, 'PATCH', "/api/rendez-vous/{$rdv['id']}", $jeton, json_encode(['creneauId' => $autre]));
        self::assertSame(409, $this->statut($client));
        self::assertStringContainsString('délai', $this->json($client)['error']);
        $this->appeler($client, 'PATCH', "/api/rendez-vous/{$rdv['id']}/annuler", $jeton);
        self::assertSame(409, $this->statut($client));

        // L'agent n'est pas soumis au délai.
        $this->appeler($client, 'PATCH', "/api/rendez-vous/{$rdv['id']}/annuler", $jetonAgent);
        self::assertSame(200, $this->statut($client));
        self::assertSame('annule', $this->json($client)['statut']);
    }

    public function testUnCreneauPasseOuLointainNEstPasSoumisAuDelaiMaisUnCreneauPasseEstRefuse(): void
    {
        $client = static::createClient();
        $service = $this->creerService();
        $hier = $this->creerCreneau($service, 'yesterday', '09:00');
        $loin = $this->creerCreneau($service, '+10 days', '09:00');
        [$jeton, $demande] = $this->citoyenAvecDemande($service);
        $this->em()->clear();

        $this->reserver($client, $jeton, $demande, $hier);
        self::assertSame(409, $this->statut($client));
        $rdv = $this->reserver($client, $jeton, $demande, $loin);
        self::assertSame(201, $this->statut($client));
        self::assertTrue($rdv['modifiableParLeCitoyen']);
    }

    public function testModificationParLeCitoyen(): void
    {
        $client = static::createClient();
        $service = $this->creerService();
        $autreService = $this->creerService();
        $jetonAgent = $this->jetonAgent($service);
        $a = $this->creerCreneau($service, '+10 days', '09:00');
        $b = $this->creerCreneau($service, '+11 days', '10:00');
        $plein = $this->creerCreneau($service, '+12 days', '11:00');
        $ailleurs = $this->creerCreneau($autreService, '+10 days', '09:00');
        $procedure = $this->creerProcedure($service);
        $procedureAilleurs = $this->creerProcedure($autreService);
        [$jeton, $demande] = $this->citoyenAvecDemande($service);
        [$jetonAutre, $demandeAutre] = $this->citoyenAvecDemande($service);
        $this->em()->clear();

        $this->reserver($client, $jetonAutre, $demandeAutre, $plein);
        $rdv = $this->reserver($client, $jeton, $demande, $a);
        $id = $rdv['id'];
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id/decision", $jetonAgent, '{"decision":"confirmer"}');
        self::assertSame('confirme', $this->json($client)['statut']);

        // Refus : autre créneau complet, créneau d'un autre service, même créneau, rien à modifier, corps invalide.
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id", $jeton, json_encode(['creneauId' => $plein]));
        self::assertSame(409, $this->statut($client));
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id", $jeton, json_encode(['creneauId' => $ailleurs]));
        self::assertSame(422, $this->statut($client));
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id", $jeton, json_encode(['creneauId' => $a]));
        self::assertSame(422, $this->statut($client));
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id", $jeton, '{}');
        self::assertSame(422, $this->statut($client));
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id", $jeton, json_encode(['creneauId' => 'x']));
        self::assertSame(400, $this->statut($client));
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id", $jeton, json_encode(['procedureId' => $procedureAilleurs]));
        self::assertSame(422, $this->statut($client));
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id", $jetonAutre, json_encode(['creneauId' => $b]));
        self::assertSame(403, $this->statut($client), 'le rendez-vous d\'un autre citoyen');
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id", $jetonAgent, json_encode(['creneauId' => $b]));
        self::assertSame(403, $this->statut($client), 'l\'agent utilise /reporter');

        // Changement de créneau : le rendez-vous repasse « en attente » et l'ancienne place est libérée.
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id", $jeton, json_encode(['creneauId' => $b, 'procedureId' => $procedure]));
        self::assertSame(200, $this->statut($client));
        $modifie = $this->json($client);
        self::assertSame(['en_attente', $b, $procedure], [$modifie['statut'], $modifie['creneauId'], $modifie['procedureId']]);
        $this->appeler($client, 'GET', "/api/creneaux?serviceId=$service&date=" . (new \DateTime('+10 days'))->format('Y-m-d'), $jetonAgent);
        self::assertSame(0, $this->json($client)[0]['reservations']);

        // Retirer seulement la procédure : le statut ne change pas.
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id", $jeton, json_encode(['procedureId' => null]));
        self::assertNull($this->json($client)['procedureId']);
        self::assertSame('en_attente', $this->json($client)['statut']);
    }

    public function testReportParLAgent(): void
    {
        $client = static::createClient();
        $service = $this->creerService();
        $autreService = $this->creerService();
        $jetonAgent = $this->jetonAgent($service);
        $jetonAutreAgent = $this->jetonAgent($autreService);
        $a = $this->creerCreneau($service, '+10 days', '09:00');
        $b = $this->creerCreneau($service, '+13 days', '14:00');
        $ailleurs = $this->creerCreneau($autreService, '+13 days', '14:00');
        [$jeton, $demande] = $this->citoyenAvecDemande($service);
        $this->em()->clear();

        $id = $this->reserver($client, $jeton, $demande, $a)['id'];

        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id/reporter", $jeton, json_encode(['creneauId' => $b]));
        self::assertSame(403, $this->statut($client), 'le citoyen ne reporte pas');
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id/reporter", $jetonAutreAgent, json_encode(['creneauId' => $ailleurs]));
        self::assertSame(403, $this->statut($client), 'agent d\'un autre service');
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id/reporter", $jetonAgent, '{}');
        self::assertSame(400, $this->statut($client));
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id/reporter", $jetonAgent, json_encode(['creneauId' => $a]));
        self::assertSame(422, $this->statut($client));
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id/reporter", $jetonAgent, json_encode(['creneauId' => $ailleurs]));
        self::assertSame(422, $this->statut($client));

        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id/reporter", $jetonAgent, json_encode(['creneauId' => $b, 'motif' => 'Absence de l\'agent']));
        self::assertSame(200, $this->statut($client));
        $rdv = $this->json($client);
        self::assertSame(['confirme', $b], [$rdv['statut'], $rdv['creneauId']]);

        // Le citoyen est prévenu, avec l'ancienne date, la nouvelle et le motif.
        $reporte = array_values(array_filter($this->notifications($client, $jeton), fn($n) => $n['type'] === 'rdv_reporte'));
        self::assertCount(1, $reporte);
        self::assertStringContainsString('reporté', $reporte[0]['message']);
        self::assertStringContainsString('Absence de l\'agent', $reporte[0]['message']);
        self::assertStringContainsString((new \DateTime('+10 days'))->format('d/m/Y'), $reporte[0]['message']);
        self::assertStringContainsString((new \DateTime('+13 days'))->format('d/m/Y'), $reporte[0]['message']);

        // Un rendez-vous annulé ne se reporte plus.
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id/annuler", $jetonAgent);
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id/reporter", $jetonAgent, json_encode(['creneauId' => $a]));
        self::assertSame(409, $this->statut($client));
    }

    public function testRecapitulatifAvecPiecesAApporter(): void
    {
        $client = static::createClient();
        $service = $this->creerService('12 rue de la Mairie, Antananarivo');
        $jetonAgent = $this->jetonAgent($service);
        $creneau = $this->creerCreneau($service, '+10 days', '09:00');
        $procedure = $this->creerProcedure($service);
        $creneau2 = $this->creerCreneau($service, '+11 days', '10:00');
        [$jeton, $demande] = $this->citoyenAvecDemande($service);
        [$jetonAutre] = $this->citoyenAvecDemande($service);
        [, $demande2] = $this->citoyenAvecDemande($service);
        $this->em()->clear();

        $id = $this->reserver($client, $jeton, $demande, $creneau, $procedure)['id'];
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id/decision", $jetonAgent, '{"decision":"confirmer"}');

        $this->appeler($client, 'GET', "/api/rendez-vous/$id/recapitulatif", $jeton);
        self::assertSame(200, $this->statut($client));
        $r = $this->json($client);
        self::assertSame((new \DateTime('+10 days'))->format('Y-m-d'), $r['quand']['date']);
        self::assertSame(['09:00', '09:30', 30], [$r['quand']['heureDebut'], $r['quand']['heureFin'], $r['quand']['dureeMinutes']]);
        self::assertSame('12 rue de la Mairie, Antananarivo', $r['lieu']['adresse']);
        self::assertSame('Rdv-test acte de naissance', $r['procedure']['nom']);
        self::assertCount(2, $r['piecesAApporter']);
        self::assertSame(['Livret de famille', true, 2, 3, 'Fokontany'], [
            $r['piecesAApporter'][0]['libelle'], $r['piecesAApporter'][0]['obligatoire'], $r['piecesAApporter'][0]['exemplaires'],
            $r['piecesAApporter'][0]['ancienneteMaxMois'], $r['piecesAApporter'][0]['ouObtenir'],
        ]);
        self::assertFalse($r['piecesAApporter'][1]['obligatoire']);
        self::assertTrue($r['modifiableParLeCitoyen']);
        self::assertStringContainsString('indicative', $r['avertissement']);

        // L'agent du service le consulte ; un autre citoyen non.
        $this->appeler($client, 'GET', "/api/rendez-vous/$id/recapitulatif", $jetonAgent);
        self::assertSame(200, $this->statut($client));
        $this->appeler($client, 'GET', "/api/rendez-vous/$id/recapitulatif", $jetonAutre);
        self::assertSame(403, $this->statut($client));

        // La notification de confirmation contient le lieu et les pièces à apporter (e-mail de confirmation, EF-30).
        $confirme = array_values(array_filter($this->notifications($client, $jeton), fn($n) => $n['type'] === 'rdv_confirme'));
        self::assertCount(1, $confirme);
        self::assertStringContainsString('12 rue de la Mairie', $confirme[0]['message']);
        self::assertStringContainsString('Livret de famille (2 exemplaires, de moins de 3 mois)', $confirme[0]['message']);
        self::assertStringContainsString('Photo (facultatif)', $confirme[0]['message']);

        // Sans procédure choisie : liste de pièces vide, sans erreur.
        $sansProcedure = $this->reserver($client, $this->jetonCitoyenDe($demande2), $demande2, $creneau2)['id'];
        $this->appeler($client, 'GET', "/api/rendez-vous/$sansProcedure/recapitulatif", $jetonAgent);
        self::assertSame([], $this->json($client)['piecesAApporter']);
        self::assertNull($this->json($client)['procedure']);

        $this->appeler($client, 'GET', '/api/rendez-vous/999999999/recapitulatif', $jeton);
        self::assertSame(404, $this->statut($client));
    }

    private function jetonCitoyenDe(int $demandeId): string
    {
        return $this->jetonDe($this->em()->find(Demande::class, $demandeId)->getUtilisateur());
    }

    public function testReglagesDuServiceEtGenerationEnLot(): void
    {
        $client = static::createClient();
        $service = $this->creerService();
        $autreService = $this->creerService();
        $jetonAgent = $this->jetonAgent($service);
        $jetonCitoyen = $this->jetonDe($this->creerUtilisateur('citoyen'));
        $this->em()->clear();

        // L'agent règle durée, capacité et jours fermés de son service.
        foreach (['{"capaciteCreneau":0}', '{"dureeCreneauMinutes":"20"}', '{"joursFermes":[0]}', '{"joursFermes":"samedi"}', '{"dureeCreneauMinutes":3}'] as $corps) {
            $this->appeler($client, 'PUT', "/api/services/$service", $jetonAgent, $corps);
            self::assertSame(400, $this->statut($client), $corps);
        }
        $this->appeler($client, 'PUT', "/api/services/$autreService", $jetonAgent, '{"capaciteCreneau":5}');
        self::assertSame(403, $this->statut($client), 'pas le service d\'un autre');
        $this->appeler($client, 'PUT', "/api/services/$service", $jetonAgent, '{"capaciteCreneau":3,"dureeCreneauMinutes":20,"joursFermes":[7,7,6]}');
        self::assertSame(200, $this->statut($client));
        self::assertSame([3, 20, [7, 6]], [$this->json($client)['capaciteCreneau'], $this->json($client)['dureeCreneauMinutes'], $this->json($client)['joursFermes']]);

        // Génération : du lundi au dimanche suivant, week-end fermé, de 08:00 à 09:00 par pas de 20 min : 3 créneaux/jour.
        $lundi = (new \DateTime('next monday'))->format('Y-m-d');
        $dimanche = (new \DateTime('next monday +6 days'))->format('Y-m-d');
        $corps = ['dateDebut' => $lundi, 'dateFin' => $dimanche, 'heureDebut' => '08:00', 'heureFin' => '09:00'];
        $this->appeler($client, 'POST', '/api/creneaux/generer', $jetonCitoyen, json_encode($corps));
        self::assertSame(403, $this->statut($client));
        $this->appeler($client, 'POST', '/api/creneaux/generer', null, json_encode($corps));
        self::assertSame(401, $this->statut($client));
        $this->appeler($client, 'POST', '/api/creneaux/generer', $jetonAgent, json_encode($corps));
        self::assertSame(201, $this->statut($client));
        $g = $this->json($client);
        self::assertSame([15, 0, 5, 3, 20, 3], [$g['crees'], $g['ignores'], $g['joursOuverts'], $g['creneauxParJour'], $g['dureeMinutes'], $g['capacite']]);

        $this->appeler($client, 'GET', "/api/creneaux?serviceId=$service&date=$lundi", $jetonAgent);
        $jour = $this->json($client);
        self::assertSame(['08:00', '08:20', '08:40'], array_column($jour, 'heure'));
        self::assertSame([3, 20, '08:20'], [$jour[0]['capacite'], $jour[0]['dureeMinutes'], $jour[0]['heureFin']]);
        $this->appeler($client, 'GET', "/api/creneaux?serviceId=$service&date=" . (new \DateTime('next monday +5 days'))->format('Y-m-d'), $jetonAgent);
        self::assertSame([], $this->json($client), 'samedi fermé');

        // Rejouer la génération ne duplique rien ; une date fermée, une pause et d'autres réglages s'ajoutent.
        $this->appeler($client, 'POST', '/api/creneaux/generer', $jetonAgent, json_encode($corps));
        self::assertSame([0, 15], [$this->json($client)['crees'], $this->json($client)['ignores']]);
        $this->appeler($client, 'POST', '/api/creneaux/generer', $jetonAgent, json_encode([
            'dateDebut' => $lundi, 'dateFin' => $dimanche, 'heureDebut' => '13:00', 'heureFin' => '15:00', 'pauseDebut' => '14:00', 'pauseFin' => '14:30',
            'dureeMinutes' => 30, 'capacite' => 1, 'datesFermees' => [$lundi], 'joursFermes' => [5, 6, 7],
        ]));
        $g = $this->json($client);
        // Lundi fermé, vendredi fermé : mardi, mercredi, jeudi ; créneaux 13:00, 13:30, 14:30 (la pause 14:00-14:30 saute 14:00).
        self::assertSame([9, 3, 3], [$g['crees'], $g['joursOuverts'], $g['creneauxParJour']]);
    }

    public function testGenerationEnLotRefuseLesDonneesInvalides(): void
    {
        $client = static::createClient();
        $service = $this->creerService();
        $jetonAgent = $this->jetonAgent($service);
        $this->em()->clear();
        $demain = (new \DateTime('+1 day'))->format('Y-m-d');
        $base = ['dateDebut' => $demain, 'dateFin' => $demain, 'heureDebut' => '08:00', 'heureFin' => '12:00'];

        $cas = [
            'sans corps' => null,
            'date invalide' => array_merge($base, ['dateDebut' => '2026-13-45']),
            'période dans le passé' => array_merge($base, ['dateDebut' => '2020-01-01', 'dateFin' => '2020-01-02']),
            'fin avant début' => array_merge($base, ['dateFin' => (new \DateTime('-1 day'))->format('Y-m-d')]),
            'période trop longue' => array_merge($base, ['dateFin' => (new \DateTime('+200 days'))->format('Y-m-d')]),
            'heure invalide' => array_merge($base, ['heureDebut' => '25:00']),
            'heureFin avant heureDebut' => array_merge($base, ['heureFin' => '07:00']),
            'pause seule' => array_merge($base, ['pauseDebut' => '10:00']),
            'pause à l\'envers' => array_merge($base, ['pauseDebut' => '11:00', 'pauseFin' => '10:00']),
            'durée trop courte' => array_merge($base, ['dureeMinutes' => 1]),
            'capacité à 0' => array_merge($base, ['capacite' => 0]),
            'jour fermé hors 1-7' => array_merge($base, ['joursFermes' => [8]]),
            'date fermée invalide' => array_merge($base, ['datesFermees' => ['demain']]),
            'plage trop courte pour la durée' => array_merge($base, ['heureFin' => '08:10']),
        ];
        foreach ($cas as $nom => $corps) {
            $this->appeler($client, 'POST', '/api/creneaux/generer', $jetonAgent, $corps === null ? '[]' : json_encode($corps));
            self::assertSame(400, $this->statut($client), $nom);
        }
        $this->appeler($client, 'GET', "/api/creneaux?serviceId=$service", $jetonAgent);
        self::assertSame([], $this->json($client), 'aucun créneau créé par les requêtes refusées');
    }

    public function testCreationManuelleAvecCapaciteEtValeursParDefautDuService(): void
    {
        $client = static::createClient();
        $service = $this->creerService();
        $jetonAgent = $this->jetonAgent($service);
        $this->em()->clear();
        $date = (new \DateTime('+5 days'))->format('Y-m-d');

        $this->appeler($client, 'POST', '/api/creneaux', $jetonAgent, json_encode(['date' => $date, 'heures' => ['08:00'], 'capacite' => 4, 'dureeMinutes' => 45]));
        self::assertSame(201, $this->statut($client));
        $c = $this->json($client)['crees'][0];
        self::assertSame([4, 45, '08:45', 4], [$c['capacite'], $c['dureeMinutes'], $c['heureFin'], $c['placesRestantes']]);
        $this->appeler($client, 'POST', '/api/creneaux', $jetonAgent, json_encode(['date' => $date, 'heure' => '09:00']));
        $c = $this->json($client)['crees'][0];
        self::assertSame([1, 30], [$c['capacite'], $c['dureeMinutes']], 'réglages par défaut du service');
        $this->appeler($client, 'POST', '/api/creneaux', $jetonAgent, json_encode(['date' => $date, 'heure' => '10:00', 'capacite' => 0]));
        self::assertSame(400, $this->statut($client));
    }
}
