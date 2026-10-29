<?php

namespace App\Tests\Controller;

use App\Entity\Parametre;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Étape 10a : paramétrage (EF-46) : durée par défaut des créneaux, jours fériés, modèles de messages.
 * Les paramètres de la base de TEST sont remis à zéro à la fin de chaque test.
 */
class ParametresTest extends WebTestCase
{
    use OutilsDeTestTrait;
    use OutilsRendezVousTrait;

    protected function tearDown(): void
    {
        $this->nettoyerRendezVous();
        $this->em()->createQuery('DELETE App\Entity\Parametre p')->execute();
        parent::tearDown();
    }

    private function compterJournal(string $action): int
    {
        return (int) $this->em()->createQuery('SELECT COUNT(j.id) FROM App\Entity\Journal j WHERE j.action = :a')
            ->setParameter('a', $action)->getSingleScalarResult();
    }

    private function modifier($client, string $jeton, array $corps): void
    {
        $this->appeler($client, 'PUT', '/api/admin/parametres', $jeton, json_encode($corps));
    }

    public function testAccesReserveALAdministrateur(): void
    {
        $client = static::createClient();
        $this->appeler($client, 'GET', '/api/admin/parametres');
        self::assertSame(401, $this->statut($client));
        foreach (['citoyen', 'agent', 'responsable'] as $role) {
            $jeton = $this->jetonDe($this->creerUtilisateur($role));
            $this->appeler($client, 'GET', '/api/admin/parametres', $jeton);
            self::assertSame(403, $this->statut($client), "$role : lecture");
            $this->modifier($client, $jeton, ['dureeCreneauDefautMinutes' => 20]);
            self::assertSame(403, $this->statut($client), "$role : écriture");
        }
        // Les jours fériés se lisent côté agent (et admin), pas côté citoyen ni sans jeton.
        $this->appeler($client, 'GET', '/api/jours-feries');
        self::assertSame(401, $this->statut($client));
        $this->appeler($client, 'GET', '/api/jours-feries', $this->jetonDe($this->creerUtilisateur('citoyen')));
        self::assertSame(403, $this->statut($client));
        $this->appeler($client, 'GET', '/api/jours-feries', $this->jetonDe($this->creerUtilisateur('agent')));
        self::assertSame(200, $this->statut($client));
    }

    public function testValeursParDefaut(): void
    {
        $client = static::createClient();
        $jeton = $this->jetonDe($this->creerUtilisateur('admin'));
        $this->appeler($client, 'GET', '/api/admin/parametres', $jeton);
        self::assertSame(200, $this->statut($client));
        $p = $this->json($client);
        self::assertSame(30, $p['dureeCreneauDefautMinutes']);
        self::assertSame([], $p['joursFeries']);
        self::assertCount(8, $p['modelesMessages']);
        foreach ($p['modelesMessages'] as $type => $modele) {
            self::assertFalse($modele['personnalise'], $type);
            self::assertSame($modele['defaut']['message'], $modele['message'], $type);
            self::assertNotEmpty($modele['variables'], $type);
        }
    }

    public function testValidation(): void
    {
        $client = static::createClient();
        $jeton = $this->jetonDe($this->creerUtilisateur('admin'));
        $mauvais = [
            [], ['inconnu' => 1],
            ['dureeCreneauDefautMinutes' => 4], ['dureeCreneauDefautMinutes' => 241], ['dureeCreneauDefautMinutes' => '30'],
            ['joursFeries' => 'noel'], ['joursFeries' => [['date' => '2026-13-45', 'libelle' => 'x']]],
            ['joursFeries' => [['date' => '2026-12-25']]], ['joursFeries' => [['date' => '2026-12-25', 'libelle' => '  ']]],
            ['joursFeries' => [['date' => '2026-12-25', 'libelle' => 'Noël'], ['date' => '2026-12-25', 'libelle' => 'Bis']]],
            ['joursFeries' => array_fill(0, 101, ['date' => '2026-12-25', 'libelle' => 'x'])],
            ['modelesMessages' => 'texte'], ['modelesMessages' => ['rdv_inconnu' => ['sujet' => 'a', 'message' => 'b']]],
            ['modelesMessages' => ['rdv_confirme' => ['sujet' => 'a']]],
            ['modelesMessages' => ['rdv_confirme' => ['sujet' => 'a', 'message' => str_repeat('x', 1001)]]],
            ['modelesMessages' => ['rdv_confirme' => ['sujet' => 'a', 'message' => 'Bonjour {prenom}']]],
            ['modelesMessages' => ['rdv_confirme' => ['sujet' => 'Pour {motif}', 'message' => 'ok']]],
            // Une erreur dans la requête n'enregistre rien du tout, même les parties valides.
            ['dureeCreneauDefautMinutes' => 20, 'joursFeries' => 'noel'],
        ];
        foreach ($mauvais as $corps) {
            $this->modifier($client, $jeton, $corps);
            self::assertSame(400, $this->statut($client), json_encode($corps));
        }
        $this->appeler($client, 'PUT', '/api/admin/parametres', $jeton, '[1,2]');
        self::assertSame(400, $this->statut($client));
        self::assertSame(0, $this->em()->getRepository(Parametre::class)->count([]));
    }

    public function testDureeEtJoursFeries(): void
    {
        $client = static::createClient();
        $jeton = $this->jetonDe($this->creerUtilisateur('admin'));
        $avant = $this->compterJournal('parametres_modifies');

        $this->modifier($client, $jeton, [
            'dureeCreneauDefautMinutes' => 45,
            'joursFeries' => [
                ['date' => '2027-12-25', 'libelle' => ' Noël '],
                ['date' => '2027-01-01', 'libelle' => 'Jour de l\'An'],
            ],
        ]);
        self::assertSame(200, $this->statut($client));
        $p = $this->json($client);
        self::assertSame(45, $p['dureeCreneauDefautMinutes']);
        self::assertSame(['2027-01-01', '2027-12-25'], array_column($p['joursFeries'], 'date'), 'triés par date');
        self::assertSame('Noël', $p['joursFeries'][1]['libelle']);
        self::assertSame($avant + 1, $this->compterJournal('parametres_modifies'));

        // Une modification partielle ne touche pas au reste ; la liste de fériés est remplacée en entier.
        $this->modifier($client, $jeton, ['joursFeries' => [['date' => '2027-05-01', 'libelle' => 'Fête du Travail']]]);
        $p = $this->json($client);
        self::assertSame(45, $p['dureeCreneauDefautMinutes']);
        self::assertSame(['2027-05-01'], array_column($p['joursFeries'], 'date'));

        // Lecture côté agent, avec filtre.
        $agent = $this->jetonDe($this->creerUtilisateur('agent'));
        $this->appeler($client, 'GET', '/api/jours-feries', $agent);
        self::assertCount(1, $this->json($client));
        $this->appeler($client, 'GET', '/api/jours-feries?du=2027-06-01', $agent);
        self::assertSame([], $this->json($client));
        $this->appeler($client, 'GET', '/api/jours-feries?du=juin', $agent);
        self::assertSame(400, $this->statut($client));
    }

    public function testDureeParDefautPourUnNouveauService(): void
    {
        $client = static::createClient();
        $jeton = $this->jetonDe($this->creerUtilisateur('admin'));
        $creer = function (array $corps) use ($client, $jeton): array {
            $corps += ['nom' => 'Rdv-test service ' . bin2hex(random_bytes(4))];
            $this->appeler($client, 'POST', '/api/services', $jeton, json_encode($corps));
            $service = $this->json($client);
            if (isset($service['id'])) {
                $this->services[] = $service['id'];
            }

            return $service;
        };

        self::assertSame(30, $creer([])['dureeCreneauMinutes']);
        $this->modifier($client, $jeton, ['dureeCreneauDefautMinutes' => 45]);
        $nouveau = $creer([]);
        self::assertSame(45, $nouveau['dureeCreneauMinutes']);
        self::assertSame(20, $creer(['dureeCreneauMinutes' => 20])['dureeCreneauMinutes']);
        $this->appeler($client, 'POST', '/api/services', $jeton, '{"nom":"x","dureeCreneauMinutes":3}');
        self::assertSame(400, $this->statut($client));
        // Les services existants gardent leur durée.
        $this->appeler($client, 'GET', '/api/services/' . $nouveau['id'], $jeton);
        self::assertSame(45, $this->json($client)['dureeCreneauMinutes']);
    }

    public function testLesJoursFeriesSontExcluDeLaGenerationDeCreneaux(): void
    {
        $client = static::createClient();
        $jetonAdmin = $this->jetonDe($this->creerUtilisateur('admin'));
        $service = $this->creerService();
        $jetonAgent = $this->jetonAgent($service);
        $mercredi = new \DateTimeImmutable('next wednesday +1 week');
        $ferie = $mercredi->format('Y-m-d');
        $corps = json_encode([
            'dateDebut' => $mercredi->modify('-1 day')->format('Y-m-d'), 'dateFin' => $mercredi->modify('+1 day')->format('Y-m-d'),
            'heureDebut' => '09:00', 'heureFin' => '10:00', 'dureeMinutes' => 30,
        ]);

        $this->appeler($client, 'POST', '/api/creneaux/generer', $jetonAgent, $corps);
        self::assertSame(201, $this->statut($client));
        self::assertSame(3, $this->json($client)['joursOuverts']);
        self::assertSame([], $this->json($client)['joursFeriesExclus']);
        $this->em()->createQuery('DELETE App\Entity\Creneau c WHERE c.service = :s')->setParameter('s', $service)->execute();

        $this->modifier($client, $jetonAdmin, ['joursFeries' => [['date' => $ferie, 'libelle' => 'Jour férié de test']]]);
        $this->appeler($client, 'POST', '/api/creneaux/generer', $jetonAgent, $corps);
        self::assertSame(201, $this->statut($client));
        $r = $this->json($client);
        self::assertSame(2, $r['joursOuverts']);
        self::assertSame([$ferie], $r['joursFeriesExclus']);
        self::assertSame(4, $r['crees']);
        $this->appeler($client, 'GET', "/api/creneaux?serviceId=$service", $jetonAgent);
        self::assertNotContains($ferie, array_column((array) $this->json($client), 'date'));
    }

    public function testModeleDeMessagePersonnalise(): void
    {
        $client = static::createClient();
        $jetonAdmin = $this->jetonDe($this->creerUtilisateur('admin'));
        $service = $this->creerService('12 rue de la Mairie, Antananarivo');
        $jetonAgent = $this->jetonAgent($service);
        $creneau1 = $this->creerCreneau($service, '+10 days', '09:00');
        $creneau2 = $this->creerCreneau($service, '+10 days', '10:00');
        [$jeton, $demande] = $this->citoyenAvecDemande($service);
        $this->em()->clear();

        $this->modifier($client, $jetonAdmin, ['modelesMessages' => ['rdv_confirme' => [
            'sujet' => 'Confirmé par {service}', 'message' => 'Bonne nouvelle ! Rendez-vous {quand}, {lieu}.{pieces}',
        ]]]);
        self::assertSame(200, $this->statut($client));
        self::assertTrue($this->json($client)['modelesMessages']['rdv_confirme']['personnalise']);
        self::assertFalse($this->json($client)['modelesMessages']['rdv_refuse']['personnalise']);

        $rdv = $this->reserver($client, $jeton, $demande, $creneau1)['id'];
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$rdv/decision", $jetonAgent, '{"decision":"confirmer"}');
        self::assertSame(200, $this->statut($client));
        $confirme = array_values(array_filter($this->notifications($client, $jeton), fn($n) => $n['type'] === 'rdv_confirme'));
        self::assertCount(1, $confirme);
        self::assertStringStartsWith('Bonne nouvelle ! Rendez-vous du ', $confirme[0]['message']);
        self::assertStringContainsString('12 rue de la Mairie', $confirme[0]['message']);
        self::assertStringNotContainsString('{', $confirme[0]['message']);

        // Les autres messages gardent leur texte par défaut.
        $enAttente = array_values(array_filter($this->notifications($client, $jeton), fn($n) => $n['type'] === 'rdv_en_attente'));
        self::assertStringContainsString('est enregistrée. Un agent doit la confirmer.', $enAttente[0]['message']);

        // null : retour au modèle par défaut.
        $this->modifier($client, $jetonAdmin, ['modelesMessages' => ['rdv_confirme' => null]]);
        self::assertFalse($this->json($client)['modelesMessages']['rdv_confirme']['personnalise']);
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$rdv/annuler", $jeton);
        $rdv2 = $this->reserver($client, $jeton, $demande, $creneau2)['id'];
        $this->appeler($client, 'PATCH', "/api/rendez-vous/$rdv2/decision", $jetonAgent, '{"decision":"confirmer"}');
        $messages = array_column(array_filter($this->notifications($client, $jeton), fn($n) => $n['type'] === 'rdv_confirme'), 'message');
        self::assertCount(2, $messages);
        self::assertStringContainsString(' est confirmé. Lieu : ', $messages[0]);
    }
}
