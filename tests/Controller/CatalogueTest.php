<?php

namespace App\Tests\Controller;

use App\Entity\Journal;
use App\Entity\Procedure;
use App\Entity\Service;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Étape 7 : catalogue et procédures (EF-07, EF-09, EF-11, EF-12, ENF-13, scénario S6).
 * Les données créées (services « Cat-test », procédures, comptes) sont supprimées à la fin de chaque test.
 */
class CatalogueTest extends WebTestCase
{
    use OutilsDeTestTrait;

    /** @var list<int> */
    private array $services = [];

    protected function tearDown(): void
    {
        $this->nettoyerComptes();
        $em = $this->em();
        $em->clear();
        if ($this->services !== []) {
            foreach ($em->getRepository(Procedure::class)->findBy(['service' => $this->services]) as $procedure) {
                $em->remove($procedure); // supprime aussi étapes et pièces ; les versions suivent (ON DELETE CASCADE)
            }
            $em->flush();
            $em->createQuery('DELETE App\Entity\Service s WHERE s.id IN (:ids)')->setParameter('ids', $this->services)->execute();
        }
        $this->services = [];
        parent::tearDown();
    }

    private function creerService(bool $actif = true, string $categorie = 'Cat-test état civil', string $nom = ''): int
    {
        $em = $this->em();
        $service = new Service();
        $service->setNom($nom !== '' ? $nom : 'Cat-test service ' . bin2hex(random_bytes(4)));
        $service->setCategorie($categorie);
        $service->setActif($actif);
        $em->persist($service);
        $em->flush();
        $this->services[] = $service->getId();

        return $service->getId();
    }

    /** Jeton d'un agent rattaché au service donné. */
    private function jetonAgent(int $serviceId): string
    {
        $agent = $this->creerUtilisateur('agent');
        $agent->setService($this->em()->find(Service::class, $serviceId));
        $this->em()->flush();

        return $this->jetonDe($agent);
    }

    private function compter(string $action): int
    {
        return (int) $this->em()->createQuery('SELECT COUNT(j.id) FROM App\Entity\Journal j WHERE j.action = :a')
            ->setParameter('a', $action)->getSingleScalarResult();
    }

    private function statut(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): int
    {
        return $client->getResponse()->getStatusCode();
    }

    public function testScenarioS6UnAgentModifieUneProcedureEtLeCitoyenVoitLaNouvelleVersion(): void
    {
        $client = static::createClient();
        $service = $this->creerService();
        $jetonAgent = $this->jetonAgent($service);
        $avant = $this->compter('procedure_creee');
        $this->em()->clear();

        $this->appeler($client, 'POST', '/api/procedures', $jetonAgent, json_encode([
            'serviceId' => $service,
            'nom' => 'Cat-test acte de naissance',
            'delaiEstime' => 3,
            'frais' => '2 000 Ar',
            'source' => 'Mairie d\'Antananarivo',
            'etapes' => ['Retirer le formulaire', ['description' => 'Déposer le dossier']],
            'documentsRequis' => [
                ['libelle' => 'Livret de famille', 'exemplaires' => 2, 'ancienneteMaxMois' => 3, 'ouObtenir' => 'Fokontany'],
                ['libelle' => 'Photo', 'obligatoire' => false],
            ],
        ]));
        self::assertSame(201, $this->statut($client));
        $creee = $this->json($client);
        self::assertSame(1, $creee['numeroVersion']);
        self::assertSame('Mairie d\'Antananarivo', $creee['source']);
        self::assertNotEmpty($creee['misAJourLe']);
        self::assertStringContainsString('indicative', $creee['avertissement']);
        self::assertSame([1, 2], array_column($creee['etapes'], 'ordre'));
        self::assertSame('Déposer le dossier', $creee['etapes'][1]['description']);
        self::assertSame(2, $creee['documentsRequis'][0]['exemplaires']);
        self::assertSame(3, $creee['documentsRequis'][0]['ancienneteMaxMois']);
        self::assertSame('Fokontany', $creee['documentsRequis'][0]['ouObtenir']);
        self::assertFalse($creee['documentsRequis'][1]['obligatoire']);
        self::assertSame(1, $creee['documentsRequis'][1]['exemplaires']);
        $id = $creee['id'];

        // Le citoyen (sans jeton) voit la fiche, avec étapes et pièces.
        $this->appeler($client, 'GET', "/api/procedures/$id");
        self::assertSame(200, $this->statut($client));
        self::assertCount(2, $this->json($client)['etapes']);

        // L'agent modifie les frais et une étape : nouvelle version, date de mise à jour renouvelée.
        $ancienneDate = $creee['misAJourLe'];
        sleep(1);
        $this->appeler($client, 'PUT', "/api/procedures/$id", $jetonAgent, json_encode([
            'frais' => 'Gratuit',
            'etapes' => ['Retirer le formulaire', 'Déposer le dossier', 'Récupérer l\'acte'],
        ]));
        self::assertSame(200, $this->statut($client));
        $modifiee = $this->json($client);
        self::assertSame(2, $modifiee['numeroVersion']);
        self::assertGreaterThan($ancienneDate, $modifiee['misAJourLe']);

        $this->appeler($client, 'GET', "/api/procedures/$id");
        $public = $this->json($client);
        self::assertSame('Gratuit', $public['frais']);
        self::assertCount(3, $public['etapes']);
        self::assertSame($modifiee['misAJourLe'], $public['misAJourLe']);

        // Même contenu renvoyé : aucune nouvelle version, date inchangée.
        $this->appeler($client, 'PUT', "/api/procedures/$id", $jetonAgent, json_encode(['frais' => 'Gratuit']));
        self::assertSame(2, $this->json($client)['numeroVersion']);
        self::assertSame($modifiee['misAJourLe'], $this->json($client)['misAJourLe']);

        // Historique : 2 versions, la plus récente d'abord, avec résumé et auteur.
        $this->appeler($client, 'GET', "/api/procedures/$id/versions", $jetonAgent);
        $versions = $this->json($client);
        self::assertSame([2, 1], array_column($versions, 'numero'));
        self::assertSame('Création', $versions[1]['resume']);
        self::assertStringContainsString('frais', $versions[0]['resume']);
        self::assertStringContainsString('étapes', $versions[0]['resume']);
        self::assertSame('2 000 Ar', $versions[1]['contenu']['frais']);
        self::assertSame('Gratuit', $versions[0]['contenu']['frais']);
        self::assertNotNull($versions[0]['auteur']['id']);

        // « Information vérifiée » : date de vérification, pas de nouvelle version.
        self::assertNull($public['verifieLe']);
        $this->appeler($client, 'POST', "/api/procedures/$id/verification", $jetonAgent);
        self::assertSame(200, $this->statut($client));
        self::assertNotNull($this->json($client)['verifieLe']);
        self::assertSame(2, $this->json($client)['numeroVersion']);

        self::assertSame($avant + 1, $this->compter('procedure_creee'));
    }

    public function testUneProcedureAnterieureALHistoriqueGardeSonEtatInitial(): void
    {
        $client = static::createClient();
        $service = $this->creerService();
        $jetonAgent = $this->jetonAgent($service);

        // Procédure « ancienne » : créée directement, sans passer par l'API (donc sans version enregistrée).
        $em = $this->em();
        $ancienne = new Procedure();
        $ancienne->setNom('Cat-test ancienne')->setFrais('500 Ar')->setService($em->find(Service::class, $service));
        $em->persist($ancienne);
        $em->flush();
        $id = $ancienne->getId();
        $em->clear();

        $this->appeler($client, 'PUT', "/api/procedures/$id", $jetonAgent, json_encode(['frais' => '800 Ar']));
        self::assertSame(200, $this->statut($client));
        self::assertSame(2, $this->json($client)['numeroVersion']);

        $this->appeler($client, 'GET', "/api/procedures/$id/versions", $jetonAgent);
        $versions = $this->json($client);
        self::assertSame([2, 1], array_column($versions, 'numero'));
        self::assertSame('État initial', $versions[1]['resume']);
        self::assertSame('500 Ar', $versions[1]['contenu']['frais']);
        self::assertSame('800 Ar', $versions[0]['contenu']['frais']);
    }

    public function testChaqueAgentNeGereQueSonService(): void
    {
        $client = static::createClient();
        $serviceA = $this->creerService();
        $serviceB = $this->creerService();
        $jetonA = $this->jetonAgent($serviceA);
        $jetonB = $this->jetonAgent($serviceB);
        $jetonAdmin = $this->jetonDe($this->creerUtilisateur('admin'));
        $this->em()->clear();

        $this->appeler($client, 'POST', '/api/procedures', $jetonA, json_encode(['serviceId' => $serviceB, 'nom' => 'Cat-test x']));
        self::assertSame(403, $this->statut($client));

        $this->appeler($client, 'POST', '/api/procedures', $jetonA, json_encode(['serviceId' => $serviceA, 'nom' => 'Cat-test a']));
        self::assertSame(201, $this->statut($client));
        $id = $this->json($client)['id'];

        foreach (['PUT' => '{"frais":"1"}', 'DELETE' => '', 'GET' => ''] as $methode => $corps) {
            $url = $methode === 'GET' ? "/api/procedures/$id/versions" : "/api/procedures/$id";
            $this->appeler($client, $methode, $url, $jetonB, $corps);
            self::assertSame(403, $this->statut($client), "$methode par l'agent d'un autre service");
        }
        $this->appeler($client, 'POST', "/api/procedures/$id/verification", $jetonB);
        self::assertSame(403, $this->statut($client));

        // Seul l'admin déplace une procédure vers un autre service.
        $this->appeler($client, 'PUT', "/api/procedures/$id", $jetonA, json_encode(['serviceId' => $serviceB]));
        self::assertSame(403, $this->statut($client));
        $this->appeler($client, 'PUT', "/api/procedures/$id", $jetonAdmin, json_encode(['serviceId' => $serviceB]));
        self::assertSame(200, $this->statut($client));
        self::assertSame($serviceB, $this->json($client)['service']['id']);

        // Citoyen et responsable : refusés.
        foreach (['citoyen', 'responsable'] as $role) {
            $this->appeler($client, 'POST', '/api/procedures', $this->jetonDe($this->creerUtilisateur($role)), json_encode(['serviceId' => $serviceA, 'nom' => 'Cat-test y']));
            self::assertSame(403, $this->statut($client), $role);
        }
    }

    public function testFicheDuServiceModifiableParSonAgent(): void
    {
        $client = static::createClient();
        $serviceA = $this->creerService();
        $serviceB = $this->creerService();
        $jetonA = $this->jetonAgent($serviceA);
        $this->em()->clear();

        $this->appeler($client, 'PUT', "/api/services/$serviceA", $jetonA, json_encode(['horaires' => 'Lun-Ven 8h-15h', 'adresse' => 'Analakely', 'motsCles' => 'acte, naissance']));
        self::assertSame(200, $this->statut($client));
        self::assertSame('Lun-Ven 8h-15h', $this->json($client)['horaires']);

        // null vide un champ facultatif.
        $this->appeler($client, 'PUT', "/api/services/$serviceA", $jetonA, json_encode(['adresse' => null]));
        self::assertNull($this->json($client)['adresse']);

        // Réservés à l'admin : nom, catégorie, statut. Et pas la fiche d'un autre service.
        foreach (['nom' => 'Autre', 'categorie' => 'Autre', 'actif' => false] as $cle => $valeur) {
            $this->appeler($client, 'PUT', "/api/services/$serviceA", $jetonA, json_encode([$cle => $valeur]));
            self::assertSame(403, $this->statut($client), $cle);
        }
        $this->appeler($client, 'PUT', "/api/services/$serviceB", $jetonA, json_encode(['horaires' => 'x']));
        self::assertSame(403, $this->statut($client));

        // L'admin peut tout modifier.
        $jetonAdmin = $this->jetonDe($this->creerUtilisateur('admin'));
        $this->appeler($client, 'PUT', "/api/services/$serviceA", $jetonAdmin, json_encode(['categorie' => 'Cat-test autre']));
        self::assertSame('Cat-test autre', $this->json($client)['categorie']);
        $this->appeler($client, 'PUT', "/api/services/$serviceA", $jetonAdmin, json_encode(['nom' => '']));
        self::assertSame(400, $this->statut($client));
    }

    public function testSuppressionLogiqueEtVisibiliteDesProceduresInactives(): void
    {
        $client = static::createClient();
        $service = $this->creerService();
        $jetonAgent = $this->jetonAgent($service);
        $jetonAdmin = $this->jetonDe($this->creerUtilisateur('admin'));
        $jetonCitoyen = $this->jetonDe($this->creerUtilisateur('citoyen'));
        $this->em()->clear();

        $this->appeler($client, 'POST', '/api/procedures', $jetonAgent, json_encode(['serviceId' => $service, 'nom' => 'Cat-test à retirer']));
        $id = $this->json($client)['id'];
        $avant = $this->compter('procedure_desactivee');

        $this->appeler($client, 'DELETE', "/api/procedures/$id", $jetonAgent);
        self::assertSame(204, $this->statut($client));
        self::assertSame('', (string) $client->getResponse()->getContent());
        self::assertSame($avant + 1, $this->compter('procedure_desactivee'));

        // Public et citoyen : introuvable, absente des listes (même avec inclureInactifs).
        foreach ([null, $jetonCitoyen] as $jeton) {
            $this->appeler($client, 'GET', "/api/procedures/$id", $jeton);
            self::assertSame(404, $this->statut($client));
            $this->appeler($client, 'GET', "/api/procedures?serviceId=$service&inclureInactifs=1", $jeton);
            self::assertSame([], $this->json($client));
        }
        // Agent du service et admin : la voient.
        foreach ([$jetonAgent, $jetonAdmin] as $jeton) {
            $this->appeler($client, 'GET', "/api/procedures/$id", $jeton);
            self::assertSame(200, $this->statut($client));
            self::assertFalse($this->json($client)['actif']);
            $this->appeler($client, 'GET', "/api/procedures?serviceId=$service&inclureInactifs=1", $jeton);
            self::assertCount(1, $this->json($client));
            $this->appeler($client, 'GET', "/api/procedures?serviceId=$service", $jeton);
            self::assertSame([], $this->json($client), 'sans inclureInactifs, la liste reste publique');
        }

        // Répéter la suppression est sans effet ; réactivation par PUT.
        $this->appeler($client, 'DELETE', "/api/procedures/$id", $jetonAgent);
        self::assertSame(204, $this->statut($client));
        $this->appeler($client, 'GET', "/api/procedures/$id/versions", $jetonAgent);
        self::assertSame(['Désactivation', 'Création'], array_column($this->json($client), 'resume'));
        $this->appeler($client, 'PUT', "/api/procedures/$id", $jetonAgent, json_encode(['actif' => true]));
        self::assertTrue($this->json($client)['actif']);
        $this->appeler($client, 'GET', "/api/procedures/$id");
        self::assertSame(200, $this->statut($client));

        $this->appeler($client, 'DELETE', '/api/procedures/999999999', $jetonAgent);
        self::assertSame(404, $this->statut($client));
    }

    public function testLesProceduresDUnServiceInactifSontMasqueesAuPublic(): void
    {
        $client = static::createClient();
        $service = $this->creerService();
        $jetonAdmin = $this->jetonDe($this->creerUtilisateur('admin'));
        $this->em()->clear();

        $this->appeler($client, 'POST', '/api/procedures', $jetonAdmin, json_encode(['serviceId' => $service, 'nom' => 'Cat-test visible']));
        $id = $this->json($client)['id'];
        $this->appeler($client, 'DELETE', "/api/services/$service", $jetonAdmin);
        self::assertSame(204, $this->statut($client));

        $this->appeler($client, 'GET', "/api/procedures/$id");
        self::assertSame(404, $this->statut($client));
        $this->appeler($client, 'GET', "/api/procedures?serviceId=$service");
        self::assertSame([], $this->json($client));
        $this->appeler($client, 'GET', "/api/procedures/$id", $jetonAdmin);
        self::assertSame(200, $this->statut($client));

        $this->appeler($client, 'PUT', "/api/services/$service", $jetonAdmin, json_encode(['actif' => true]));
        $this->appeler($client, 'GET', "/api/procedures/$id");
        self::assertSame(200, $this->statut($client));
    }

    public function testDonneesInvalidesRefusees(): void
    {
        $client = static::createClient();
        $service = $this->creerService();
        $jetonAgent = $this->jetonAgent($service);
        $this->em()->clear();

        $cas = [
            'nom manquant' => ['serviceId' => $service],
            'nom vide' => ['serviceId' => $service, 'nom' => '  '],
            'nom trop long' => ['serviceId' => $service, 'nom' => str_repeat('a', 151)],
            'délai négatif' => ['serviceId' => $service, 'nom' => 'x', 'delaiEstime' => -1],
            'actif non booléen' => ['serviceId' => $service, 'nom' => 'x', 'actif' => 'oui'],
            'étape vide' => ['serviceId' => $service, 'nom' => 'x', 'etapes' => ['ok', ' ']],
            'étapes pas une liste' => ['serviceId' => $service, 'nom' => 'x', 'etapes' => 'texte'],
            'pièce sans libellé' => ['serviceId' => $service, 'nom' => 'x', 'documentsRequis' => [['obligatoire' => true]]],
            'exemplaires à 0' => ['serviceId' => $service, 'nom' => 'x', 'documentsRequis' => [['libelle' => 'CIN', 'exemplaires' => 0]]],
            'ancienneté texte' => ['serviceId' => $service, 'nom' => 'x', 'documentsRequis' => [['libelle' => 'CIN', 'ancienneteMaxMois' => 'trois']]],
        ];
        foreach ($cas as $nom => $corps) {
            $this->appeler($client, 'POST', '/api/procedures', $jetonAgent, json_encode($corps));
            self::assertSame(400, $this->statut($client), $nom);
        }
        $this->appeler($client, 'POST', '/api/procedures', $jetonAgent, '[1,2]');
        self::assertSame(400, $this->statut($client));
        $this->appeler($client, 'POST', '/api/procedures', $jetonAgent, json_encode(['serviceId' => 999999999, 'nom' => 'x']));
        self::assertSame(404, $this->statut($client));
        $this->appeler($client, 'GET', "/api/procedures?serviceId=$service");
        self::assertSame([], $this->json($client), 'aucune procédure ne doit avoir été créée');
    }

    public function testRechercheTolerantePuisFiltreParRubrique(): void
    {
        $client = static::createClient();
        $rubrique = 'Cat-test Rubrique ' . bin2hex(random_bytes(3));
        $naissance = $this->creerService(true, $rubrique, 'Cat-test Service des actes de naissance');
        $passeport = $this->creerService(true, $rubrique . ' bis', 'Cat-test Bureau des passeports');
        $inactif = $this->creerService(false, $rubrique, 'Cat-test Ancien bureau des actes');
        $jetonAdmin = $this->jetonDe($this->creerUtilisateur('admin'));
        $this->em()->clear();

        $this->appeler($client, 'POST', '/api/procedures', $jetonAdmin, json_encode(['serviceId' => $naissance, 'nom' => 'Cat-test Copie d\'acte de naissance']));
        $this->appeler($client, 'POST', '/api/procedures', $jetonAdmin, json_encode(['serviceId' => $passeport, 'nom' => 'Cat-test Renouvellement de passeport']));

        // Services : faute de frappe, accents ignorés.
        $this->appeler($client, 'GET', '/api/services?q=' . urlencode('naisance'));
        self::assertSame([$naissance], array_column($this->json($client), 'id'));
        $this->appeler($client, 'GET', '/api/services?q=' . urlencode('PASSEPORTS'));
        self::assertSame([$passeport], array_column($this->json($client), 'id'));

        // Rubrique : insensible à la casse et aux accents ; le service inactif reste caché au public.
        $this->appeler($client, 'GET', '/api/services?rubrique=' . urlencode(strtoupper($rubrique)));
        self::assertSame([$naissance], array_column($this->json($client), 'id'));
        $this->appeler($client, 'GET', '/api/services?rubrique=' . urlencode($rubrique), $jetonAdmin);
        self::assertEqualsCanonicalizing([$naissance, $inactif], array_column($this->json($client), 'id'));

        // Procédures : recherche tolérante, filtre par rubrique du service, combinaison des deux.
        $this->appeler($client, 'GET', '/api/procedures?q=' . urlencode('pasport'));
        self::assertSame(['Cat-test Renouvellement de passeport'], array_column($this->json($client), 'nom'));
        $this->appeler($client, 'GET', '/api/procedures?rubrique=' . urlencode($rubrique));
        self::assertSame(['Cat-test Copie d\'acte de naissance'], array_column($this->json($client), 'nom'));
        $this->appeler($client, 'GET', '/api/procedures?rubrique=' . urlencode($rubrique) . '&q=passeport');
        self::assertSame([], $this->json($client));

        // Liste des rubriques : seulement les services actifs, avec leur nombre.
        $this->appeler($client, 'GET', '/api/services/rubriques');
        self::assertSame(200, $this->statut($client));
        $rubriques = array_column($this->json($client), 'nombreServices', 'nom');
        self::assertSame(1, $rubriques[$rubrique]);
        self::assertSame(1, $rubriques[$rubrique . ' bis']);
    }
}
