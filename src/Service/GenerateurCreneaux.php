<?php

namespace App\Service;

/**
 * Calculs purs de la génération en lot des créneaux (EF-23) : horaires d'une journée et jours ouverts d'une période.
 */
class GenerateurCreneaux
{
    /**
     * Heures de début des créneaux d'une journée : de $debut à $fin, un créneau toutes les $dureeMinutes ;
     * un créneau qui dépasserait $fin ou chevaucherait la pause est ignoré.
     *
     * @return list<string> heures « HH:MM »
     */
    public function horaires(string $debut, string $fin, int $dureeMinutes, ?string $pauseDebut = null, ?string $pauseFin = null): array
    {
        $d = $this->minutes($debut);
        $f = $this->minutes($fin);
        $pd = $pauseDebut !== null ? $this->minutes($pauseDebut) : null;
        $pf = $pauseFin !== null ? $this->minutes($pauseFin) : null;

        $heures = [];
        for ($t = $d; $t + $dureeMinutes <= $f; $t += $dureeMinutes) {
            if ($pd !== null && $pf !== null && $t < $pf && $t + $dureeMinutes > $pd) {
                continue;
            }
            $heures[] = sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
        }

        return $heures;
    }

    /**
     * Dates (AAAA-MM-JJ) de la période dont le jour de semaine (1 = lundi ... 7 = dimanche) n'est pas fermé
     * et qui ne figurent pas dans $datesFermees.
     *
     * @param list<int>    $joursFermes
     * @param list<string> $datesFermees
     *
     * @return list<string>
     */
    public function jours(string $dateDebut, string $dateFin, array $joursFermes, array $datesFermees = []): array
    {
        $jours = [];
        $jour = new \DateTimeImmutable($dateDebut);
        $fin = new \DateTimeImmutable($dateFin);
        while ($jour <= $fin) {
            if (!in_array((int) $jour->format('N'), $joursFermes, true) && !in_array($jour->format('Y-m-d'), $datesFermees, true)) {
                $jours[] = $jour->format('Y-m-d');
            }
            $jour = $jour->modify('+1 day');
        }

        return $jours;
    }

    private function minutes(string $heure): int
    {
        [$h, $m] = array_map('intval', explode(':', $heure));

        return $h * 60 + $m;
    }
}
