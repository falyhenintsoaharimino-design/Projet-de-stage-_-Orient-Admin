<?php

namespace App\Tests\Controller;

use App\Service\OrientationEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Étape 10b : le texte d'une demande est validé (type et longueur) par l'assistant public comme par le dépôt d'une demande :
 * une entrée mal formée donne un 400, jamais un 500.
 */
class EntreesInvalidesTest extends WebTestCase
{
    use OutilsDeTestTrait;

    protected function tearDown(): void
    {
        $this->nettoyerComptes();
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function corpsInvalides(): iterable
    {
        yield 'texte tableau' => ['{"texte":[1,2]}'];
        yield 'texte objet' => ['{"texte":{"a":1}}'];
        yield 'texte nombre' => ['{"texte":123}'];
        yield 'texte nul' => ['{"texte":null}'];
        yield 'texte vide' => ['{"texte":"   "}'];
        yield 'sans texte' => ['{}'];
        yield 'JSON invalide' => ['{pas du json'];
        yield 'JSON qui est une chaîne' => ['"texte"'];
        yield 'JSON qui est un nombre' => ['42'];
        yield 'texte trop long' => ['{"texte":"' . str_repeat('a', OrientationEngine::TEXTE_MAX + 1) . '"}'];
        yield 'texte trop long (accents)' => ['{"texte":"' . str_repeat('é', OrientationEngine::TEXTE_MAX + 1) . '"}'];
    }

    #[DataProvider('corpsInvalides')]
    public function testAssistantPublicRefuseLesEntreesInvalides(string $corps): void
    {
        $client = static::createClient();
        $this->appeler($client, 'POST', '/api/orientation/tester', null, $corps);
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    #[DataProvider('corpsInvalides')]
    public function testDepotDeDemandeRefuseLesEntreesInvalides(string $corps): void
    {
        $client = static::createClient();
        $jeton = $this->jetonDe($this->creerUtilisateur('citoyen'));
        $this->appeler($client, 'POST', '/api/demandes', $jeton, $corps);
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testLaLongueurMaximaleExacteEstAcceptee(): void
    {
        $client = static::createClient();
        $texte = str_repeat('é', OrientationEngine::TEXTE_MAX);
        $this->appeler($client, 'POST', '/api/orientation/tester', null, json_encode(['texte' => $texte]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }
}
