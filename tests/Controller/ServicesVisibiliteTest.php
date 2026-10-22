<?php

namespace App\Tests\Controller;

use App\Entity\Service;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Étape 6 : le public ne voit pas les services inactifs (l'admin voit tout) et
 * DELETE /api/services/{id} est une suppression logique (204 sans corps).
 */
class ServicesVisibiliteTest extends WebTestCase
{
    use OutilsDeTestTrait;

    /** @var list<int> */
    private array $servicesTest = [];

    protected function tearDown(): void
    {
        $this->nettoyerComptes();
        if ($this->servicesTest !== []) {
            $this->em()->createQuery('DELETE App\Entity\Service s WHERE s.id IN (:ids)')->setParameter('ids', $this->servicesTest)->execute();
        }
        $this->servicesTest = [];
        parent::tearDown();
    }

    public function testLesServicesInactifsSontMasquesAuPublicMaisVisiblesPourLAdmin(): void
    {
        $client = static::createClient();
        $actif = $this->creerService(true);
        $inactif = $this->creerService(false);
        $jetonAdmin = $this->jetonDe($this->creerUtilisateur('admin'));
        $jetonCitoyen = $this->jetonDe($this->creerUtilisateur('citoyen'));

        foreach ([null, $jetonCitoyen] as $jeton) {
            $this->appeler($client, 'GET', '/api/services', $jeton);
            $ids = array_column($this->json($client), 'id');
            self::assertContains($actif, $ids);
            self::assertNotContains($inactif, $ids);

            $this->appeler($client, 'GET', "/api/services/$inactif", $jeton);
            self::assertSame(404, $client->getResponse()->getStatusCode());
            $this->appeler($client, 'GET', "/api/services/$actif", $jeton);
            self::assertSame(200, $client->getResponse()->getStatusCode());
        }

        $this->appeler($client, 'GET', '/api/services', $jetonAdmin);
        $ids = array_column($this->json($client), 'id');
        self::assertContains($actif, $ids);
        self::assertContains($inactif, $ids);
        $this->appeler($client, 'GET', "/api/services/$inactif", $jetonAdmin);
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testSuppressionLogiqueDUnService(): void
    {
        $client = static::createClient();
        $id = $this->creerService(true);

        $this->appeler($client, 'DELETE', "/api/services/$id", $this->jetonDe($this->creerUtilisateur('citoyen')));
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $jetonAdmin = $this->jetonDe($this->creerUtilisateur('admin'));
        $this->appeler($client, 'DELETE', "/api/services/$id", $jetonAdmin);
        self::assertSame(204, $client->getResponse()->getStatusCode());
        self::assertSame('', (string) $client->getResponse()->getContent());

        // Toujours en base, désactivé, et caché du public.
        $em = $this->em();
        $em->clear();
        self::assertFalse($em->find(Service::class, $id)?->isActif());
        $this->appeler($client, 'GET', "/api/services/$id");
        self::assertSame(404, $client->getResponse()->getStatusCode());

        // Répéter la suppression ne casse rien ; un identifiant inconnu reste en 404.
        $this->appeler($client, 'DELETE', "/api/services/$id", $jetonAdmin);
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->appeler($client, 'DELETE', '/api/services/999999999', $jetonAdmin);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    private function creerService(bool $actif): int
    {
        $em = $this->em();
        $service = new Service();
        $service->setNom('Service de test ' . bin2hex(random_bytes(4)));
        $service->setActif($actif);
        $em->persist($service);
        $em->flush();
        $this->servicesTest[] = $service->getId();

        return $service->getId();
    }
}
