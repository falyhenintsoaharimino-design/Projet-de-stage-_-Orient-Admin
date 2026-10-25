<?php

namespace App\Service;

/**
 * Recherche par mots-clés tolérante aux fautes de frappe (EF-12), sans extension de base de données :
 * le catalogue est petit, le tri se fait en PHP.
 *
 * Un mot de la requête correspond à un mot d'un champ s'il est identique, s'il en est le début, s'il y est
 * contenu (4 lettres ou plus) ou s'il en diffère de 1 lettre (mots de 4 à 6 lettres) ou de 2 lettres
 * (7 lettres ou plus), ou si deux lettres voisines sont inversées : « pasport » trouve « passeport », « naisance » trouve « naissance ».
 * Les accents et la casse sont ignorés ; les mots de moins de 3 lettres (« de », « la »...) sont ignorés.
 */
class RechercheService
{
    public function normaliser(string $texte): string
    {
        // Même principe que OrientationEngine : pas de dépendance à intl.
        $recherche = ['à','â','ä','é','è','ê','ë','î','ï','ô','ö','ù','û','ü','ç','œ',
                      'À','Â','Ä','É','È','Ê','Ë','Î','Ï','Ô','Ö','Ù','Û','Ü','Ç','Œ'];
        $remplace = ['a','a','a','e','e','e','e','i','i','o','o','u','u','u','c','oe',
                     'a','a','a','e','e','e','e','i','i','o','o','u','u','u','c','oe'];
        $texte = strtolower(str_replace($recherche, $remplace, trim($texte)));
        $texte = preg_replace('/[^a-z0-9\s]/', ' ', $texte) ?? $texte;

        return trim(preg_replace('/\s+/', ' ', $texte) ?? $texte);
    }

    /**
     * Garde les éléments qui correspondent à la requête, du plus pertinent au moins pertinent.
     * Une requête vide renvoie les éléments tels quels.
     *
     * @template T
     *
     * @param iterable<T>                                $elements
     * @param callable(T): list<array{0: ?string, 1: int}> $champsDe  champs de l'élément : [texte, poids]
     *
     * @return list<T>
     */
    public function filtrer(iterable $elements, string $requete, callable $champsDe): array
    {
        $liste = is_array($elements) ? array_values($elements) : iterator_to_array($elements, false);
        $mots = $this->mots($requete);
        if ($mots === []) {
            return $liste;
        }

        $resultats = [];
        foreach ($liste as $rang => $element) {
            $score = $this->score($mots, $champsDe($element));
            if ($score > 0) {
                $resultats[] = [$score, $rang, $element];
            }
        }
        // Meilleur score d'abord ; à score égal, l'ordre d'origine est conservé.
        usort($resultats, fn(array $a, array $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return array_map(fn(array $r) => $r[2], $resultats);
    }

    /** @return list<string> */
    private function mots(string $requete): array
    {
        $tous = array_values(array_unique(array_filter(explode(' ', $this->normaliser($requete)))));
        $longs = array_values(array_filter($tous, fn(string $m) => strlen($m) >= 3));

        return $longs !== [] ? $longs : $tous;
    }

    /**
     * @param list<string>                      $mots
     * @param list<array{0: ?string, 1: int}> $champs
     */
    private function score(array $mots, array $champs): int
    {
        $motsDesChamps = [];
        foreach ($champs as [$texte, $poids]) {
            if ($texte !== null && trim($texte) !== '') {
                $motsDesChamps[] = [explode(' ', $this->normaliser($texte)), $poids];
            }
        }

        $total = 0;
        $trouves = 0;
        foreach ($mots as $mot) {
            $meilleur = 0;
            foreach ($motsDesChamps as [$motsDuChamp, $poids]) {
                foreach ($motsDuChamp as $candidat) {
                    $meilleur = max($meilleur, $this->correspondance($mot, $candidat) * $poids);
                }
            }
            if ($meilleur > 0) {
                ++$trouves;
                $total += $meilleur;
            }
        }

        // Au moins la moitié des mots de la requête doivent être trouvés (« je veux un passeport » trouve « passeport »).
        return $trouves >= (int) ceil(count($mots) / 2) ? $total : 0;
    }

    private function correspondance(string $mot, string $candidat): int
    {
        if ($candidat === '') {
            return 0;
        }
        if ($mot === $candidat) {
            return 4;
        }
        if (strlen($mot) >= 3 && str_starts_with($candidat, $mot)) {
            return 3;
        }
        if (strlen($mot) >= 4 && str_contains($candidat, $mot)) {
            return 2;
        }
        $tolerance = match (true) {
            strlen($mot) >= 7 => 2,
            strlen($mot) >= 4 => 1,
            default => 0,
        };
        if ($tolerance > 0 && abs(strlen($mot) - strlen($candidat)) <= $tolerance
            && (levenshtein($mot, $candidat) <= $tolerance || $this->deuxLettresInversees($mot, $candidat))) {
            return 1;
        }

        return 0;
    }

    /** « pemris » / « permis » : deux lettres voisines inversées comptent pour une seule faute. */
    private function deuxLettresInversees(string $a, string $b): bool
    {
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        for ($i = 0; $i < strlen($a) - 1; ++$i) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] === $b[$i + 1] && $a[$i + 1] === $b[$i] && substr($a, $i + 2) === substr($b, $i + 2);
            }
        }

        return false;
    }
}
