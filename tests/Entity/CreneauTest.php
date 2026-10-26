<?php

namespace App\Tests\Entity;

use App\Entity\Creneau;
use App\Service\Fuseau;
use PHPUnit\Framework\TestCase;

/** Les horaires d'un créneau sont des heures locales (Antananarivo, UTC+3), quel que soit le fuseau du serveur. */
class CreneauTest extends TestCase
{
    private function creneau(): Creneau
    {
        return (new Creneau())->setDate(new \DateTime('2028-09-04'))->setHeure('08:30')->setDureeMinutes(45);
    }

    public function testLHeureEstLueDansLeFuseauDeLApplication(): void
    {
        $debut = $this->creneau()->getDebut((new Fuseau('Indian/Antananarivo'))->get());
        self::assertSame('2028-09-04T08:30:00+03:00', $debut->format(\DATE_ATOM));
        self::assertSame('2028-09-04 05:30', $debut->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));
        self::assertSame('09:15', $this->creneau()->getFin((new Fuseau('Indian/Antananarivo'))->get())->format('H:i'));
    }

    public function testUneHeureIllisibleDonneNull(): void
    {
        self::assertNull((new Creneau())->setDate(new \DateTime('2028-09-04'))->setHeure('9h')->getDebut());
        self::assertNull((new Creneau())->getDebut());
    }

    public function testUnFuseauInvalideEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Fuseau('Mars/Olympus');
    }
}
