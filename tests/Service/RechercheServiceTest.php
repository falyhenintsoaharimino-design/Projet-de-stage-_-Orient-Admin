<?php

namespace App\Tests\Service;

use App\Service\RechercheService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** EF-12 : recherche par mots-clés tolérante aux fautes de frappe, accents et casse ignorés. */
class RechercheServiceTest extends TestCase
{
    private const CATALOGUE = [
        'Acte de naissance' => 'etat civil',
        'Carte nationale d\'identité' => 'identité',
        'Passeport biométrique' => 'voyage',
        'Permis de conduire' => 'transport',
    ];

    /** @return list<string> noms trouvés, du plus pertinent au moins pertinent */
    private function chercher(string $requete): array
    {
        return (new RechercheService())->filtrer(
            array_keys(self::CATALOGUE),
            $requete,
            fn(string $nom) => [[$nom, 4], [self::CATALOGUE[$nom], 2]]
        );
    }

    public static function requetes(): \Generator
    {
        yield 'exacte' => ['passeport', 'Passeport biométrique'];
        yield 'faute de frappe (7 lettres, 2 écarts)' => ['pasport', 'Passeport biométrique'];
        yield 'lettre manquante' => ['naisance', 'Acte de naissance'];
        yield 'lettres inversées' => ['pemris', 'Permis de conduire'];
        yield 'accents et casse ignorés' => ['IDENTITE', 'Carte nationale d\'identité'];
        yield 'début de mot' => ['condu', 'Permis de conduire'];
        yield 'mot du champ rubrique' => ['voyage', 'Passeport biométrique'];
        yield 'phrase avec mots inutiles' => ['je veux un acte de naissance', 'Acte de naissance'];
    }

    #[DataProvider('requetes')]
    public function testTrouveLeBonElementEnPremier(string $requete, string $attendu): void
    {
        self::assertSame($attendu, $this->chercher($requete)[0] ?? null, "Requête : $requete");
    }

    public function testRienDePertinentDonneUneListeVide(): void
    {
        self::assertSame([], $this->chercher('zzzzqqqq'));
    }

    public function testUneRequeteVideRenvoieTout(): void
    {
        self::assertSame(array_keys(self::CATALOGUE), $this->chercher('   '));
    }

    public function testLesMotsTropCourtsSontIgnores(): void
    {
        // « de » et « la » ne doivent pas faire remonter tout le catalogue.
        self::assertSame(['Passeport biométrique'], $this->chercher('de la passeport'));
    }

    public function testNormaliser(): void
    {
        self::assertSame('etat civil a l ecole', (new RechercheService())->normaliser('  État-Civil à l\'école '));
    }
}
