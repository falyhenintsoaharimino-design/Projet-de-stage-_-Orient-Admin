<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fuseau horaire de l'application (variable APP_TIMEZONE, Indian/Antananarivo par défaut).
 * Les créneaux sont saisis en heure locale (« 09:00 » = 9 h à Antananarivo) : pour savoir s'ils sont passés ou si le délai
 * limite est dépassé, il faut les lire dans ce fuseau, quel que soit le fuseau du serveur (souvent UTC).
 * Les dates déjà enregistrées en base ne sont pas touchées.
 */
class Fuseau
{
    private \DateTimeZone $fuseau;

    public function __construct(#[Autowire(env: 'APP_TIMEZONE')] string $nom)
    {
        try {
            $this->fuseau = new \DateTimeZone($nom);
        } catch (\Exception) {
            throw new \InvalidArgumentException(sprintf('APP_TIMEZONE invalide : « %s » (exemple : Indian/Antananarivo)', $nom));
        }
    }

    public function get(): \DateTimeZone
    {
        return $this->fuseau;
    }

    /** Aujourd'hui à 00:00, dans le fuseau de l'application. */
    public function aujourdhui(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', $this->fuseau);
    }
}
