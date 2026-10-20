<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Garde la documentation `docs/openapi.yaml` synchronisée avec les routes réelles :
 * toute route /api ajoutée, retirée ou renommée doit apparaître (ou disparaître) de la doc.
 */
class OpenApiTest extends KernelTestCase
{
    public function testToutesLesRoutesApiSontDocumentees(): void
    {
        self::bootKernel();

        $codes = [];
        foreach (static::getContainer()->get('router')->getRouteCollection() as $route) {
            if (!str_starts_with($route->getPath(), '/api')) {
                continue;
            }
            // /api/login_check est déclarée sans méthode dans routes.yaml : c'est un POST.
            foreach ($route->getMethods() ?: ['POST'] as $methode) {
                $codes[] = $methode . ' ' . $route->getPath();
            }
        }

        $doc = Yaml::parseFile(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        $documentees = [];
        foreach ($doc['paths'] as $chemin => $operations) {
            foreach (array_keys($operations) as $methode) {
                $documentees[] = strtoupper($methode) . ' ' . $chemin;
            }
        }
        sort($codes);
        sort($documentees);

        self::assertSame([], array_values(array_diff($codes, $documentees)), 'Routes du code absentes de docs/openapi.yaml');
        self::assertSame([], array_values(array_diff($documentees, $codes)), 'Routes de docs/openapi.yaml qui n\'existent plus dans le code');
    }
}
