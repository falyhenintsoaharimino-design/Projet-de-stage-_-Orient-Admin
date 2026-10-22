<?php

namespace App\Tests\Controller;

use App\Entity\Avis;
use App\Entity\Creneau;
use App\Entity\Demande;
use App\Entity\ExempleOrientation;
use App\Entity\HistoriqueStatut;
use App\Entity\Journal;
use App\Entity\Notification;
use App\Entity\RendezVous;
use App\Entity\Service;
use App\Entity\Utilisateur;
use App\Service\JournalService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Étape 6 : limitation des tentatives de connexion, inscription avec consentement,
 * profil (PATCH /api/me), export des données et suppression du compte.
 */
class ComptesTest extends WebTestCase
{
    use OutilsDeTestTrait;

    /** @var list<int> services créés par donneesCompletes(), supprimés en fin de test */
    private array $servicesTest = [];

    protected function tearDown(): void
    {
        $this->nettoyerComptes();
        $em = $this->em();
        foreach ($this->servicesTest as $id) {
            foreach (['ExempleOrientation e WHERE e.service', 'Creneau c WHERE c.service', 'Service s WHERE s.id'] as $dql) {
                $em->createQuery('DELETE App\Entity\\' . $dql . ' = :id')->setParameter('id', $id)->execute();
            }
        }
        $this->servicesTest = [];
        parent::tearDown();
    }

    public function testLaSixiemeTentativeDeConnexionEstBloquee(): void
    {
        $client = static::createClient();
        $corps = json_encode(['email' => 'inconnu-' . bin2hex(random_bytes(4)) . '@orient-admin.test', 'password' => 'mauvais']);

        for ($i = 1; $i <= 5; ++$i) {
            $this->appeler($client, 'POST', '/api/login_check', null, $corps);
            self::assertSame(401, $client->getResponse()->getStatusCode(), "tentative $i");
        }

        $this->appeler($client, 'POST', '/api/login_check', null, $corps);
        $reponse = $client->getResponse();
        self::assertSame(429, $reponse->getStatusCode());
        self::assertGreaterThan(0, (int) $reponse->headers->get('Retry-After'));
        self::assertArrayHasKey('error', $this->json($client));
    }

    public function testInscriptionSansConsentementRefusee(): void
    {
        $client = static::createClient();
        foreach ([[], ['consentement' => false], ['consentement' => 'oui']] as $extra) {
            $corps = json_encode($extra + ['nom' => 'Test', 'email' => 'ctest-refus-' . bin2hex(random_bytes(4)) . '@orient-admin.test', 'password' => 'motdepasse-test-1']);
            $this->appeler($client, 'POST', '/api/register', null, $corps);
            self::assertSame(400, $client->getResponse()->getStatusCode());
        }
    }

    public function testInscriptionAvecConsentementEnregistreLaDate(): void
    {
        $client = static::createClient();
        $email = 'ctest-' . bin2hex(random_bytes(6)) . '@orient-admin.test';
        $avant = new \DateTimeImmutable('-1 minute');

        $this->appeler($client, 'POST', '/api/register', null, json_encode([
            'nom' => 'Test', 'prenom' => 'Consent', 'email' => $email, 'password' => 'motdepasse-test-1',
            'consentement' => true, 'telephone' => '+261 34 12 345 67',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());

        $utilisateur = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        self::assertNotNull($utilisateur);
        $this->comptesCrees[] = $utilisateur->getId();
        self::assertGreaterThan($avant, $utilisateur->getConsentementLe());
        self::assertSame('+261341234567', $utilisateur->getTelephone());
        self::assertSame('fr', $utilisateur->getLangue());
        self::assertSame('email', $utilisateur->getCanalNotification());
    }

    public function testInscriptionRefuseUnEmailInvalide(): void
    {
        $client = static::createClient();
        $this->appeler($client, 'POST', '/api/register', null, json_encode([
            'nom' => 'Test', 'email' => 'pas-un-email', 'password' => 'motdepasse-test-1', 'consentement' => true,
        ]));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testModificationDuProfil(): void
    {
        $client = static::createClient();
        $utilisateur = $this->creerUtilisateur('citoyen');
        $jeton = $this->jetonDe($utilisateur);

        // role, email et service sont ignorés : seuls les champs du profil sont modifiables.
        $this->appeler($client, 'PATCH', '/api/me', $jeton, json_encode([
            'telephone' => '034 12 345 67', 'langue' => 'mg', 'canalNotification' => 'sms',
            'role' => 'admin', 'email' => 'pirate@orient-admin.test',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $this->appeler($client, 'GET', '/api/me', $jeton);
        $moi = $this->json($client);
        self::assertSame('0341234567', $moi['telephone']);
        self::assertSame('mg', $moi['langue']);
        self::assertSame('sms', $moi['canalNotification']);
        self::assertSame($utilisateur->getEmail(), $moi['email']);
        self::assertNotContains('ROLE_ADMIN', $moi['roles']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('profilsInvalides')]
    public function testProfilInvalideRefuse(array $corps): void
    {
        $client = static::createClient();
        $jeton = $this->jetonDe($this->creerUtilisateur('citoyen'));

        $this->appeler($client, 'PATCH', '/api/me', $jeton, json_encode($corps));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function profilsInvalides(): array
    {
        return [
            'corps vide' => [[]],
            'champ inconnu seul' => [['role' => 'admin']],
            'langue inconnue' => [['langue' => 'xx']],
            'canal inconnu' => [['canalNotification' => 'pigeon']],
            'sms sans téléphone' => [['canalNotification' => 'sms']],
            'téléphone invalide' => [['telephone' => 'abc']],
            'nom vide' => [['nom' => '  ']],
        ];
    }

    public function testExportDesDonneesNeContientQueLeCompte(): void
    {
        $client = static::createClient();
        $moi = $this->creerUtilisateur('citoyen');
        $autre = $this->creerUtilisateur('citoyen');
        $this->donneesCompletes($moi);
        $this->donneesCompletes($autre);

        $this->appeler($client, 'GET', '/api/me/donnees', $this->jetonDe($moi));

        $reponse = $client->getResponse();
        self::assertSame(200, $reponse->getStatusCode());
        self::assertStringContainsString('attachment', (string) $reponse->headers->get('Content-Disposition'));
        $export = $this->json($client);
        self::assertSame($moi->getEmail(), $export['profil']['email']);
        self::assertCount(1, $export['demandes']);
        self::assertCount(1, $export['demandes'][0]['historique']);
        self::assertCount(1, $export['rendezVous']);
        self::assertCount(1, $export['avis']);
        self::assertCount(1, $export['notifications']);
        self::assertStringNotContainsString($autre->getEmail(), (string) $reponse->getContent());
        self::assertStringNotContainsString('"password"', (string) $reponse->getContent());
    }

    public function testSuppressionDuCompteEfface_lesDonneesEtGardeLesTraces(): void
    {
        $client = static::createClient();
        $utilisateur = $this->creerUtilisateur('citoyen', 'motdepasse-test-1');
        $id = $utilisateur->getId();
        $jeton = $this->jetonDe($utilisateur);
        $ids = $this->donneesCompletes($utilisateur);

        // Confirmation obligatoire, et bon mot de passe.
        $this->appeler($client, 'DELETE', '/api/me', $jeton, '{}');
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $this->appeler($client, 'DELETE', '/api/me', $jeton, json_encode(['motDePasse' => 'mauvais']));
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->em()->find(Utilisateur::class, $id));

        $this->appeler($client, 'DELETE', '/api/me', $jeton, json_encode(['motDePasse' => 'motdepasse-test-1']));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        self::assertSame('', (string) $client->getResponse()->getContent());

        $em = $this->em();
        $em->clear();
        self::assertNull($em->find(Utilisateur::class, $id));
        foreach ([Demande::class => 'demande', RendezVous::class => 'rdv', Avis::class => 'avis', Notification::class => 'notification'] as $classe => $cle) {
            self::assertNull($em->find($classe, $ids[$cle]), $classe . ' doit être supprimé');
        }
        self::assertSame(0, $em->getRepository(HistoriqueStatut::class)->count(['id' => $ids['historique']]));
        // L'exemple d'entraînement (anonymisé) est gardé, détaché de la demande supprimée.
        $exemple = $em->find(ExempleOrientation::class, $ids['exemple']);
        self::assertNotNull($exemple);
        self::assertNull($exemple->getDemande());
        // Le journal garde ses lignes, sans lien avec le compte supprimé.
        self::assertSame(0, $em->getRepository(Journal::class)->count(['utilisateur' => $id]));
        self::assertGreaterThan(0, $em->getRepository(Journal::class)->count(['action' => 'compte_supprime']));

        // Le jeton déjà émis ne mène plus nulle part.
        $this->appeler($client, 'GET', '/api/me', $jeton);
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testLePersonnelNeSupprimePasSonCompteParCetteRoute(): void
    {
        $client = static::createClient();
        $agent = $this->creerUtilisateur('agent', 'motdepasse-test-1');

        $this->appeler($client, 'DELETE', '/api/me', $this->jetonDe($agent), json_encode(['motDePasse' => 'motdepasse-test-1']));

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->em()->find(Utilisateur::class, $agent->getId()));
    }

    /** Une demande avec historique, un rendez-vous, un avis, une notification, un exemple et une ligne de journal. */
    /** @return array<string, int> identifiants des objets créés */
    private function donneesCompletes(Utilisateur $utilisateur): array
    {
        $em = $this->em();
        $utilisateur = $em->find(Utilisateur::class, $utilisateur->getId());

        $service = new Service();
        $service->setNom('Service de test ' . bin2hex(random_bytes(4)));
        $service->setActif(true);
        $em->persist($service);

        $creneau = new Creneau();
        $creneau->setDate(new \DateTime('+30 days'));
        $creneau->setHeure('09:00');
        $creneau->setDisponible(false);
        $creneau->setService($service);
        $em->persist($creneau);

        $demande = new Demande();
        $demande->setTextDemande('Je veux un acte de naissance');
        $demande->setStatus('en_cours');
        $demande->setDateCreation(new \DateTimeImmutable());
        $demande->setUtilisateur($utilisateur);
        $em->persist($demande);

        $historique = new HistoriqueStatut();
        $historique->setStatut('en_cours');
        $historique->setDateChangement(new \DateTimeImmutable());
        $historique->setDemande($demande);
        $em->persist($historique);

        $rdv = new RendezVous();
        $rdv->setStatut('termine');
        $rdv->setDemande($demande);
        $rdv->setCreneau($creneau);
        $em->persist($rdv);

        $avis = new Avis();
        $avis->setNote(5);
        $avis->setCommentaire('Très bien');
        $avis->setRendezVous($rdv);
        $avis->setDateCreation(new \DateTimeImmutable());
        $em->persist($avis);

        $notification = new Notification();
        $notification->setType('rdv_confirme');
        $notification->setCanal('email');
        $notification->setMessage('Votre rendez-vous est confirmé');
        $notification->setStatut('envoyee');
        $notification->setDateEnvoi(new \DateTimeImmutable());
        $notification->setUtilisateur($utilisateur);
        $em->persist($notification);

        $exemple = new ExempleOrientation();
        $exemple->setTexte('acte de naissance');
        $exemple->setOrigine('citoyen');
        $exemple->setDateCreation(new \DateTimeImmutable());
        $exemple->setService($service);
        $exemple->setDemande($demande);
        $em->persist($exemple);

        $em->flush();
        $this->servicesTest[] = $service->getId();
        static::getContainer()->get(JournalService::class)->enregistrer('test_action', $utilisateur);

        $ids = [
            'demande' => $demande->getId(), 'historique' => $historique->getId(), 'rdv' => $rdv->getId(),
            'avis' => $avis->getId(), 'notification' => $notification->getId(), 'exemple' => $exemple->getId(),
        ];
        // Vide le gestionnaire : la requête suivante relit tout depuis la base (sinon la collection
        // « historique » de la demande, créée vide en mémoire, ne serait jamais rechargée).
        $em->clear();

        return $ids;
    }
}
