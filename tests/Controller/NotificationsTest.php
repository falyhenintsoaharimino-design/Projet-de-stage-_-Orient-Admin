<?php

namespace App\Tests\Controller;

use App\Entity\Demande;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Étape 9 : notifications (EF-31 rappel de la veille, EF-32 pièces manquantes et changement de procédure,
 * EF-33 SMS simulé) et messages de l'agent au citoyen. Données de test supprimées à la fin de chaque test.
 */
class NotificationsTest extends WebTestCase
{
    use OutilsDeTestTrait;
    use OutilsRendezVousTrait;

    protected function tearDown(): void
    {
        $this->nettoyerRendezVous();
        parent::tearDown();
    }

    /** @return array{0: int, 1: string} code de sortie et texte affiché */
    private function rappels($client, array $options = []): array
    {
        $application = new Application($client->getKernel());
        $testeur = new CommandTester($application->find('app:rappels-rdv'));
        $code = $testeur->execute($options);

        return [$code, $testeur->getDisplay()];
    }

    /** @return list<string> types des notifications du compte, les plus récentes d'abord */
    private function types($client, string $jeton): array
    {
        return array_column($this->notifications($client, $jeton), 'type');
    }

    /** Réserve puis, si $confirmer, fait confirmer par l'agent. @return int identifiant du rendez-vous */
    private function rendezVous($client, string $jeton, int $demande, int $creneau, ?int $procedure = null, ?string $jetonAgent = null): int
    {
        $id = $this->reserver($client, $jeton, $demande, $creneau, $procedure)['id'];
        if ($jetonAgent !== null) {
            $this->appeler($client, 'PATCH', "/api/rendez-vous/$id/decision", $jetonAgent, '{"decision":"confirmer"}');
        }

        return $id;
    }

    private function compterJournal(string $action): int
    {
        return (int) $this->em()->createQuery('SELECT COUNT(j.id) FROM App\Entity\Journal j WHERE j.action = :a')
            ->setParameter('a', $action)->getSingleScalarResult();
    }

    public function testRappelDeLaVeilleUneSeuleFoisParRendezVous(): void
    {
        $client = static::createClient();
        $service = $this->creerService('12 rue de la Mairie, Antananarivo');
        $jetonAgent = $this->jetonAgent($service);
        $jetonAdmin = $this->jetonDe($this->creerUtilisateur('admin'));
        $demain = $this->creerCreneau($service, $this->demain(), '10:00');
        $demainSms = $this->creerCreneau($service, $this->demain(), '11:00');
        $demainAttente = $this->creerCreneau($service, $this->demain(), '12:00');
        $loin = $this->creerCreneau($service, '+10 days', '09:00');
        $procedure = $this->creerProcedure($service);
        [$jetonA, $demandeA] = $this->citoyenAvecDemande($service);
        [$jetonB, $demandeB] = $this->citoyenAvecDemande($service, true);
        [$jetonC, $demandeC] = $this->citoyenAvecDemande($service);
        [$jetonD, $demandeD] = $this->citoyenAvecDemande($service);
        $avant = $this->compterJournal('rappels_envoyes');
        $this->em()->clear();

        $rdvA = $this->rendezVous($client, $jetonA, $demandeA, $demain, $procedure, $jetonAgent);
        $this->rendezVous($client, $jetonB, $demandeB, $demainSms, null, $jetonAgent);
        $this->rendezVous($client, $jetonC, $demandeC, $loin, null, $jetonAgent);
        $this->rendezVous($client, $jetonD, $demandeD, $demainAttente); // jamais confirmé : pas de rappel

        // Options invalides.
        self::assertSame(2, $this->rappels($client, ['--jours' => 'abc'])[0]);
        self::assertSame(2, $this->rappels($client, ['--date' => '2026-13-45'])[0]);

        // Simulation : rien n'est envoyé.
        [$code, $texte] = $this->rappels($client, ['--dry-run' => true]);
        self::assertSame(0, $code);
        self::assertStringContainsString('2 rappel(s) seraient envoyés', $texte);
        self::assertNotContains('rdv_rappel', $this->types($client, $jetonA));

        // Rappel de la veille : les 2 rendez-vous confirmés de demain, pas celui en attente ni celui dans 10 jours.
        [$code, $texte] = $this->rappels($client);
        self::assertSame(0, $code);
        self::assertStringContainsString('2 rappel(s) envoyé(s)', $texte);
        $rappel = array_values(array_filter($this->notifications($client, $jetonA), fn($n) => $n['type'] === 'rdv_rappel'));
        self::assertCount(1, $rappel);
        self::assertStringContainsString('Rappel', $rappel[0]['message']);
        self::assertStringContainsString('12 rue de la Mairie', $rappel[0]['message']);
        self::assertStringContainsString('Livret de famille (2 exemplaires, de moins de 3 mois)', $rappel[0]['message']);
        self::assertNotContains('rdv_rappel', $this->types($client, $jetonC));
        self::assertNotContains('rdv_rappel', $this->types($client, $jetonD));
        // Le citoyen B a choisi le SMS : le rappel part en SMS simulé.
        $this->appeler($client, 'GET', '/api/sms-simules', $jetonAdmin);
        self::assertStringContainsString('Rappel', implode(' ', array_column($this->json($client), 'message')));
        self::assertSame($avant + 1, $this->compterJournal('rappels_envoyes'));

        // Rejouer ne renvoie rien.
        self::assertStringContainsString('0 rappel(s) envoyé(s)', $this->rappels($client)[1]);

        // Un autre jour : --date ; --jours=10 donne le même résultat.
        self::assertStringContainsString('1 rappel(s) envoyé(s)', $this->rappels($client, ['--date' => (new \DateTime('+10 days'))->format('Y-m-d')])[1]);
        self::assertContains('rdv_rappel', $this->types($client, $jetonC));

        // Le rendez-vous de D est confirmé plus tard : il sera rappelé à la prochaine exécution.
        $idD = $this->em()->getRepository(\App\Entity\RendezVous::class)->findOneBy(['demande' => $demandeD])->getId();
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$idD/decision", $jetonAgent, '{"decision":"confirmer"}');
        self::assertStringContainsString('1 rappel(s) envoyé(s)', $this->rappels($client)[1]);

        // Un rendez-vous reporté est de nouveau rappelé, à sa nouvelle date.
        $autreJour = $this->creerCreneau($service, '+11 days', '09:00');
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$rdvA/reporter", $jetonAgent, json_encode(['creneauId' => $autreJour]));
        self::assertSame(200, $this->statut($client));
        self::assertStringContainsString('1 rappel(s) envoyé(s)', $this->rappels($client, ['--date' => (new \DateTime('+11 days'))->format('Y-m-d')])[1]);
        self::assertSame(2, count(array_filter($this->types($client, $jetonA), fn($t) => $t === 'rdv_rappel')));
    }

    public function testSmsSimuleSelonLeCanalDuCitoyen(): void
    {
        $client = static::createClient();
        $service = $this->creerService();
        $jetonAgent = $this->jetonAgent($service);
        $jetonAdmin = $this->jetonDe($this->creerUtilisateur('admin'));
        $creneau1 = $this->creerCreneau($service, '+10 days', '09:00');
        $creneau2 = $this->creerCreneau($service, '+10 days', '10:00');
        [$jetonSms, $demandeSms] = $this->citoyenAvecDemande($service, true);
        [$jetonMail, $demandeMail] = $this->citoyenAvecDemande($service);
        $citoyenSms = $this->em()->find(Demande::class, $demandeSms)->getUtilisateur();
        $telephone = $citoyenSms->getTelephone();
        $idSms = $citoyenSms->getId();
        $this->em()->clear();

        $this->rendezVous($client, $jetonSms, $demandeSms, $creneau1, null, $jetonAgent);
        $this->rendezVous($client, $jetonMail, $demandeMail, $creneau2, null, $jetonAgent);

        // Le citoyen « sms » : SMS simulés (réservation, confirmation) ; le citoyen « email » : aucun SMS.
        $this->appeler($client, 'GET', '/api/sms-simules', $jetonAdmin);
        self::assertSame(200, $this->statut($client));
        $liste = $this->json($client);
        self::assertCount(2, $liste);
        self::assertSame([$idSms, $telephone, 'envoye'], [$liste[0]['destinataire']['id'], $liste[0]['telephone'], $liste[0]['statut']]);
        self::assertStringStartsWith('Orient-Admin : Votre rendez-vous', $liste[0]['message']);
        self::assertLessThanOrEqual(320, mb_strlen($liste[0]['message']));
        $this->appeler($client, 'GET', '/api/sms-simules?limite=1', $jetonAdmin);
        self::assertCount(1, $this->json($client));

        // Les SMS ne polluent pas le centre de notifications : seulement les 2 notifications de l'application.
        self::assertSame(['rdv_confirme', 'rdv_en_attente'], $this->types($client, $jetonSms));
        $this->appeler($client, 'GET', '/api/notifications/compteur', $jetonSms);
        self::assertSame(2, $this->json($client)['nonLues']);

        // Boîte d'envoi réservée à l'administrateur.
        $this->appeler($client, 'GET', '/api/sms-simules', $jetonAgent);
        self::assertSame(403, $this->statut($client));
        $this->appeler($client, 'GET', '/api/sms-simules', $jetonSms);
        self::assertSame(403, $this->statut($client));
        $this->appeler($client, 'GET', '/api/sms-simules', null);
        self::assertSame(401, $this->statut($client));
    }

    public function testPiecesManquantes(): void
    {
        $client = static::createClient();
        $service = $this->creerService();
        $autreService = $this->creerService();
        $jetonAgent = $this->jetonAgent($service);
        $jetonAutreAgent = $this->jetonAgent($autreService);
        $creneau = $this->creerCreneau($service, '+10 days', '09:00');
        [$jeton, $demande] = $this->citoyenAvecDemande($service);
        $this->em()->clear();

        $id = $this->rendezVous($client, $jeton, $demande, $creneau, null, $jetonAgent);
        $corps = json_encode(['pieces' => ['Photo d\'identité', 'Livret de famille'], 'message' => 'Sans ces pièces, le dossier sera refusé.']);

        $this->appeler($client, 'POST', "/api/rendez-vous/$id/pieces-manquantes", $jeton, $corps);
        self::assertSame(403, $this->statut($client), 'le citoyen ne signale pas');
        $this->appeler($client, 'POST', "/api/rendez-vous/$id/pieces-manquantes", $jetonAutreAgent, $corps);
        self::assertSame(403, $this->statut($client), 'agent d\'un autre service');
        $this->appeler($client, 'POST', '/api/rendez-vous/999999999/pieces-manquantes', $jetonAgent, $corps);
        self::assertSame(404, $this->statut($client));
        foreach (['{}', '{"pieces":[]}', '{"pieces":"Photo"}', '{"pieces":["  "]}', '{"pieces":[1]}', '{"pieces":["x"],"message":5}',
                     json_encode(['pieces' => [str_repeat('a', 151)]]), json_encode(['pieces' => array_fill(0, 21, 'x')])] as $mauvais) {
            $this->appeler($client, 'POST', "/api/rendez-vous/$id/pieces-manquantes", $jetonAgent, $mauvais);
            self::assertSame(400, $this->statut($client), $mauvais);
        }

        $this->appeler($client, 'POST', "/api/rendez-vous/$id/pieces-manquantes", $jetonAgent, $corps);
        self::assertSame(200, $this->statut($client));
        self::assertSame(['Photo d\'identité', 'Livret de famille'], $this->json($client)['pieces']);
        $alerte = array_values(array_filter($this->notifications($client, $jeton), fn($n) => $n['type'] === 'pieces_manquantes'));
        self::assertCount(1, $alerte);
        self::assertStringContainsString('il manque : Photo d\'identité ; Livret de famille', $alerte[0]['message']);
        self::assertStringContainsString('Sans ces pièces', $alerte[0]['message']);

        // Plus d'alerte sur un rendez-vous annulé.
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$id/annuler", $jeton);
        $this->appeler($client, 'POST', "/api/rendez-vous/$id/pieces-manquantes", $jetonAgent, $corps);
        self::assertSame(409, $this->statut($client));
    }

    public function testAlerteQuandLaProcedureDuRendezVousChange(): void
    {
        $client = static::createClient();
        $service = $this->creerService();
        $jetonAgent = $this->jetonAgent($service);
        $procedure = $this->creerProcedure($service);
        $autreProcedure = $this->creerProcedure($service);
        $creneau1 = $this->creerCreneau($service, '+10 days', '09:00');
        $creneau2 = $this->creerCreneau($service, '+10 days', '10:00');
        $creneau3 = $this->creerCreneau($service, '+10 days', '11:00');
        $creneau4 = $this->creerCreneau($service, '+10 days', '12:00');
        [$jeton, $demande] = $this->citoyenAvecDemande($service);
        [$jetonAnnule, $demandeAnnule] = $this->citoyenAvecDemande($service);
        [$jetonAutre, $demandeAutre] = $this->citoyenAvecDemande($service);
        [$jetonSans, $demandeSans] = $this->citoyenAvecDemande($service);
        $this->em()->clear();

        $this->rendezVous($client, $jeton, $demande, $creneau1, $procedure);
        $idAnnule = $this->rendezVous($client, $jetonAnnule, $demandeAnnule, $creneau2, $procedure);
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$idAnnule/annuler", $jetonAnnule);
        $this->rendezVous($client, $jetonAutre, $demandeAutre, $creneau3, $autreProcedure);
        $this->rendezVous($client, $jetonSans, $demandeSans, $creneau4);
        $compter = fn(string $jetonCitoyen) => count(array_filter($this->types($client, $jetonCitoyen), fn($t) => $t === 'procedure_modifiee'));

        // Frais modifiés : le citoyen concerné est prévenu, avec les pièces à apporter.
        $this->appeler($client, 'PUT', "/api/procedures/$procedure", $jetonAgent, '{"frais":"Gratuit"}');
        self::assertSame(200, $this->statut($client));
        $alerte = array_values(array_filter($this->notifications($client, $jeton), fn($n) => $n['type'] === 'procedure_modifiee'));
        self::assertCount(1, $alerte);
        self::assertStringContainsString('Rdv-test acte de naissance', $alerte[0]['message']);
        self::assertStringContainsString('frais', $alerte[0]['message']);
        self::assertStringContainsString('Livret de famille', $alerte[0]['message']);
        // Pas les autres : rendez-vous annulé, autre procédure, aucune procédure.
        self::assertSame([0, 0, 0], [$compter($jetonAnnule), $compter($jetonAutre), $compter($jetonSans)]);

        // Un simple changement de source ne dérange personne ; un changement de pièces, si.
        $this->appeler($client, 'PUT', "/api/procedures/$procedure", $jetonAgent, '{"source":"Décret 2026"}');
        self::assertSame(1, $compter($jeton));
        $this->appeler($client, 'PUT', "/api/procedures/$procedure", $jetonAgent, json_encode(['documentsRequis' => [['libelle' => 'CIN', 'exemplaires' => 1]]]));
        self::assertSame(2, $compter($jeton));
        $dernier = $this->notifications($client, $jeton)[0];
        self::assertSame('procedure_modifiee', $dernier['type']);
        self::assertStringContainsString('pièces requises', $dernier['message']);
        self::assertStringContainsString('CIN', $dernier['message']);

        // Procédure désactivée : le citoyen est prévenu ; réactivée : pas de nouvelle alerte.
        $this->appeler($client, 'DELETE', "/api/procedures/$procedure", $jetonAgent);
        self::assertSame(204, $this->statut($client));
        self::assertSame(3, $compter($jeton));
        self::assertStringContainsString('n\'est plus proposée', $this->notifications($client, $jeton)[0]['message']);
        $this->appeler($client, 'PUT', "/api/procedures/$procedure", $jetonAgent, '{"actif":true}');
        self::assertSame(3, $compter($jeton));
    }

    public function testMessagesDeLAgentAuCitoyen(): void
    {
        $client = static::createClient();
        $service = $this->creerService();
        $autreService = $this->creerService();
        $jetonAgent = $this->jetonAgent($service);
        $jetonAutreAgent = $this->jetonAgent($autreService);
        $jetonResponsable = $this->jetonDe($this->creerUtilisateur('responsable'));
        $jetonAdmin = $this->jetonDe($this->creerUtilisateur('admin'));
        [$jeton, $demande] = $this->citoyenAvecDemande($service);
        [$jetonAutre] = $this->citoyenAvecDemande($service);
        [$jetonSms, $demandeSms] = $this->citoyenAvecDemande($service, true);
        $this->em()->clear();

        // Seuls l'agent du service (et l'admin) écrivent.
        $corps = json_encode(['message' => 'Merci d\'apporter un justificatif de domicile.', 'sujet' => 'Pièce à ajouter']);
        $this->appeler($client, 'POST', "/api/demandes/$demande/messages", $jeton, $corps);
        self::assertSame(403, $this->statut($client), 'le citoyen n\'écrit pas par cette route');
        $this->appeler($client, 'POST', "/api/demandes/$demande/messages", $jetonResponsable, $corps);
        self::assertSame(403, $this->statut($client));
        $this->appeler($client, 'POST', "/api/demandes/$demande/messages", $jetonAutreAgent, $corps);
        self::assertSame(403, $this->statut($client), 'agent d\'un autre service');
        $this->appeler($client, 'POST', '/api/demandes/999999999/messages', $jetonAgent, $corps);
        self::assertSame(404, $this->statut($client));
        foreach (['{}', '{"message":"  "}', json_encode(['message' => str_repeat('a', 2001)]), '{"message":"ok","sujet":5}',
                     json_encode(['message' => 'ok', 'sujet' => str_repeat('s', 151)]), '[]'] as $mauvais) {
            $this->appeler($client, 'POST', "/api/demandes/$demande/messages", $jetonAgent, $mauvais);
            self::assertSame(400, $this->statut($client), $mauvais);
        }

        $this->appeler($client, 'POST', "/api/demandes/$demande/messages", $jetonAgent, $corps);
        self::assertSame(201, $this->statut($client));
        $message = $this->json($client);
        self::assertSame(['Pièce à ajouter', $demande], [$message['sujet'], $message['demandeId']]);
        self::assertNotNull($message['auteur']['id']);
        $this->appeler($client, 'POST', "/api/demandes/$demande/messages", $jetonAdmin, json_encode(['message' => 'Bonjour, de la part de l\'administration.']));
        self::assertSame(201, $this->statut($client));

        // Lecture : le citoyen, l'agent, le responsable, l'admin ; pas un autre citoyen. Du plus ancien au plus récent.
        foreach ([$jeton, $jetonAgent, $jetonResponsable, $jetonAdmin] as $lecteur) {
            $this->appeler($client, 'GET', "/api/demandes/$demande/messages", $lecteur);
            self::assertSame(200, $this->statut($client));
            self::assertSame(['Merci d\'apporter un justificatif de domicile.', 'Bonjour, de la part de l\'administration.'], array_column($this->json($client), 'message'));
        }
        $this->appeler($client, 'GET', "/api/demandes/$demande/messages", $jetonAutre);
        self::assertSame(403, $this->statut($client));
        $this->appeler($client, 'GET', "/api/demandes/$demande/messages", $jetonAutreAgent);
        self::assertSame(403, $this->statut($client));

        // Le citoyen reçoit chaque message comme notification, avec le nom du service.
        $recus = array_values(array_filter($this->notifications($client, $jeton), fn($n) => $n['type'] === 'message_agent'));
        self::assertCount(2, $recus);
        self::assertStringContainsString('Message du service Rdv-test service', $recus[1]['message']);
        self::assertStringContainsString('justificatif de domicile', $recus[1]['message']);

        // Citoyen « sms » : le message part aussi en SMS simulé.
        $this->appeler($client, 'POST', "/api/demandes/$demandeSms/messages", $jetonAgent, '{"message":"Votre dossier est complet."}');
        self::assertSame(201, $this->statut($client));
        $this->appeler($client, 'GET', '/api/sms-simules?limite=1', $jetonAdmin);
        self::assertStringContainsString('Votre dossier est complet.', $this->json($client)[0]['message']);
    }
}
