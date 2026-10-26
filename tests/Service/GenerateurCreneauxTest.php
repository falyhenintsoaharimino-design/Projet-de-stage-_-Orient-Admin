<?php

namespace App\Tests\Service;

use App\Service\GenerateurCreneaux;
use PHPUnit\Framework\TestCase;

/** EF-23 : calculs de la génération en lot des créneaux. */
class GenerateurCreneauxTest extends TestCase
{
    public function testHorairesSansPause(): void
    {
        self::assertSame(['08:00', '08:30', '09:00', '09:30'], (new GenerateurCreneaux())->horaires('08:00', '10:00', 30));
    }

    public function testLeDernierCreneauDoitFinirAvantLaFermeture(): void
    {
        // 08:00-09:00 par pas de 40 min : 08:00 (fin 08:40) seulement, 08:40 finirait à 09:20.
        self::assertSame(['08:00'], (new GenerateurCreneaux())->horaires('08:00', '09:00', 40));
    }

    public function testLaPauseNeContientAucunCreneauNiNeLeChevauche(): void
    {
        self::assertSame(
            ['08:00', '08:30', '09:30', '10:00', '10:30'],
            (new GenerateurCreneaux())->horaires('08:00', '11:00', 30, '09:00', '09:30')
        );
        // Créneaux de 45 min : 08:45 (fin 09:30) chevauche la pause 09:00-09:30 et est donc ignoré.
        self::assertSame(['08:00', '09:30'], (new GenerateurCreneaux())->horaires('08:00', '10:15', 45, '09:00', '09:30'));
    }

    public function testPlageTropCourteDonneUneListeVide(): void
    {
        self::assertSame([], (new GenerateurCreneaux())->horaires('08:00', '08:20', 30));
    }

    public function testJoursOuvertsSansWeekendNiDatesFermees(): void
    {
        // Du lundi 2 au dimanche 8 novembre 2026 ; samedi (6) et dimanche (7) fermés ; mercredi 4 fermé exceptionnellement.
        self::assertSame(
            ['2026-11-02', '2026-11-03', '2026-11-05', '2026-11-06'],
            (new GenerateurCreneaux())->jours('2026-11-02', '2026-11-08', [6, 7], ['2026-11-04'])
        );
    }

    public function testUneSeuleDate(): void
    {
        self::assertSame(['2026-11-02'], (new GenerateurCreneaux())->jours('2026-11-02', '2026-11-02', []));
        self::assertSame([], (new GenerateurCreneaux())->jours('2026-11-02', '2026-11-02', [1]));
    }
}
