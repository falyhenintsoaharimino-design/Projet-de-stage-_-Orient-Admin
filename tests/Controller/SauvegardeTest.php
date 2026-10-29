<?php

namespace App\Tests\Controller;

use App\Service\SauvegardeService;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Étape 10a : sauvegarde et restauration de la base (EF-47, ENF-15). Les tests qui lancent pg_dump / pg_restore sont
 * sautés si ces programmes ne sont pas disponibles (renseigner PG_DUMP_BIN et PG_RESTORE_BIN dans .env.test.local).
 * Les sauvegardes de test vont dans var/sauvegardes_test (SAUVEGARDE_DIR de .env.test) et sont supprimées à la fin.
 * La restauration est essayée dans une base à part, créée puis supprimée par le test : la base de test n'est pas touchée.
 */
class SauvegardeTest extends WebTestCase
{
    use OutilsDeTestTrait;

    private const BASE_VERIF = 'orient_admin_phpunit_verif';

    protected function tearDown(): void
    {
        $this->nettoyerComptes();
        $sauvegardes = static::getContainer()->get(SauvegardeService::class);
        foreach ($sauvegardes->lister() as $s) {
            @unlink($sauvegardes->chemin($s['nom']));
        }
        parent::tearDown();
    }

    private function exigerOutils(): void
    {
        if (!static::getContainer()->get(SauvegardeService::class)->outilsDisponibles()) {
            self::markTestSkipped('pg_dump / pg_restore introuvables (PG_DUMP_BIN, PG_RESTORE_BIN).');
        }
    }

    /** @return array{0: int, 1: string} */
    private function commande($client, string $nom, array $options = []): array
    {
        $testeur = new CommandTester((new Application($client->getKernel()))->find($nom));
        $code = $testeur->execute($options, ['interactive' => false]);

        return [$code, $testeur->getDisplay()];
    }

    public function testAccesReserveALAdministrateur(): void
    {
        $client = static::createClient();
        foreach ([['GET', '/api/admin/sauvegardes'], ['POST', '/api/admin/sauvegardes'],
                     ['GET', '/api/admin/sauvegardes/orient-admin-20260101-000000.dump']] as [$methode, $url]) {
            $this->appeler($client, $methode, $url);
            self::assertSame(401, $client->getResponse()->getStatusCode(), "$methode $url sans jeton");
            foreach (['citoyen', 'agent', 'responsable'] as $role) {
                $this->appeler($client, $methode, $url, $this->jetonDe($this->creerUtilisateur($role)));
                self::assertSame(403, $client->getResponse()->getStatusCode(), "$methode $url ($role)");
            }
        }
    }

    public function testCreationListeEtTelechargementParAPI(): void
    {
        $client = static::createClient();
        $this->exigerOutils();
        $jeton = $this->jetonDe($this->creerUtilisateur('admin'));

        $this->appeler($client, 'POST', '/api/admin/sauvegardes', $jeton);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $cree = $this->json($client);
        self::assertMatchesRegularExpression(SauvegardeService::MOTIF_NOM, $cree['nom']);
        self::assertGreaterThan(1000, $cree['taille']);

        $this->appeler($client, 'GET', '/api/admin/sauvegardes', $jeton);
        self::assertSame([$cree['nom']], array_column($this->json($client), 'nom'));

        $client->request('GET', '/api/admin/sauvegardes/' . $cree['nom'], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $jeton]);
        $reponse = $client->getResponse();
        self::assertSame(200, $reponse->getStatusCode());
        self::assertInstanceOf(BinaryFileResponse::class, $reponse);
        self::assertStringContainsString('attachment', (string) $reponse->headers->get('Content-Disposition'));
        self::assertSame('PGDMP', file_get_contents($reponse->getFile()->getPathname(), false, null, 0, 5), 'format pg_dump');

        // Nom inconnu ou tentative de sortir du dossier : jamais de fichier servi.
        foreach (['orient-admin-20200101-000000.dump', '..%2F..%2F.env', 'passwd', 'orient-admin-1.dump'] as $nom) {
            $this->appeler($client, 'GET', "/api/admin/sauvegardes/$nom", $jeton);
            self::assertSame(404, $client->getResponse()->getStatusCode(), $nom);
        }
        self::assertGreaterThanOrEqual(1, $this->em()->createQuery("SELECT COUNT(j.id) FROM App\Entity\Journal j WHERE j.action IN ('sauvegarde_creee','sauvegarde_telechargee')")->getSingleScalarResult());
    }

    public function testCommandeSauvegardeGardeLesPlusRecentes(): void
    {
        $client = static::createClient();
        $this->exigerOutils();

        self::assertSame(2, $this->commande($client, 'app:sauvegarde', ['--garder' => '0'])[0]);
        self::assertSame(2, $this->commande($client, 'app:sauvegarde', ['--garder' => 'abc'])[0]);
        foreach ([1, 2, 3] as $i) {
            [$code, $texte] = $this->commande($client, 'app:sauvegarde', ['--garder' => '2']);
            self::assertSame(0, $code, $texte);
        }
        $sauvegardes = static::getContainer()->get(SauvegardeService::class)->lister();
        self::assertCount(2, $sauvegardes);
        self::assertGreaterThan($sauvegardes[1]['nom'], $sauvegardes[0]['nom'], 'la plus récente d\'abord');
    }

    public function testRestaurationDansUneAutreBase(): void
    {
        $client = static::createClient();
        $this->exigerOutils();
        $connexion = $this->em()->getConnection();
        $connexion->executeStatement('DROP DATABASE IF EXISTS ' . self::BASE_VERIF);
        $avant = (int) $connexion->fetchOne('SELECT COUNT(*) FROM service');
        $migrations = (int) $connexion->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions');

        $nom = static::getContainer()->get(SauvegardeService::class)->creer()['nom'];

        // Sans --base ni --oui, en mode non interactif : refus, rien n'est modifié.
        [$code, $texte] = $this->commande($client, 'app:restauration', ['fichier' => $nom]);
        self::assertSame(1, $code);
        self::assertStringContainsString('annulée', $texte);
        // Fichier ou nom de base invalides.
        self::assertSame(1, $this->commande($client, 'app:restauration', ['fichier' => 'orient-admin-20200101-000000.dump', '--base' => self::BASE_VERIF])[0]);
        self::assertSame(1, $this->commande($client, 'app:restauration', ['fichier' => $nom, '--base' => 'a; DROP DATABASE x'])[0]);

        try {
            [$code, $texte] = $this->commande($client, 'app:restauration', ['fichier' => $nom, '--base' => self::BASE_VERIF]);
            self::assertSame(0, $code, $texte);

            $params = $connexion->getParams();
            $verif = \Doctrine\DBAL\DriverManager::getConnection(array_merge($params, ['dbname' => self::BASE_VERIF]));
            self::assertSame($avant, (int) $verif->fetchOne('SELECT COUNT(*) FROM service'));
            self::assertSame($migrations, (int) $verif->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));
            self::assertSame(1, (int) $verif->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'parametre'"));
            $verif->close();
        } finally {
            $connexion->executeStatement('DROP DATABASE IF EXISTS ' . self::BASE_VERIF);
        }
        self::assertSame($avant, (int) $connexion->fetchOne('SELECT COUNT(*) FROM service'), 'la base courante n\'a pas bougé');
    }
}
