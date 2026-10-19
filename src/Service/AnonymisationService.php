<?php

namespace App\Service;

use App\Entity\Utilisateur;

/**
 * Anonymisation des textes de demandes avant de les garder comme exemples
 * d'apprentissage (EF-22) : on retire ce qui permet d'identifier une personne.
 *
 * Masqués : adresses e-mail, numéros (téléphone, CIN, passeport... : 6 chiffres ou plus,
 * éventuellement séparés par des espaces, points ou tirets), et le nom / prénom du citoyen
 * s'ils apparaissent dans son texte. Les petits nombres (« bulletin n°3 », « 18 ans ») restent.
 * Limite connue : le nom d'un tiers écrit dans le texte ne peut pas être deviné.
 */
class AnonymisationService
{
    public const TEXTE_MAX = 1000;
    private const LETTRES_MIN = 3;

    /** @return ?string le texte anonymisé, ou null s'il ne reste rien d'exploitable */
    public function anonymiser(string $texte, ?Utilisateur $citoyen = null): ?string
    {
        $texte = preg_replace('/[\w.+\-]+@[\w\-]+(?:\.[\w\-]+)+/u', '[email]', $texte) ?? $texte;
        $texte = preg_replace('/(?<![\w])\+?\d(?:[\d\s.\-]{4,}\d)(?![\w])/u', '[numéro]', $texte) ?? $texte;

        if ($citoyen) {
            foreach ([$citoyen->getNom(), $citoyen->getPrenom()] as $mot) {
                $mot = trim((string) $mot);
                if (preg_match_all('/\p{L}/u', $mot) >= self::LETTRES_MIN) {
                    $texte = preg_replace('/(?<!\p{L})' . preg_quote($mot, '/') . '(?!\p{L})/iu', '[nom]', $texte) ?? $texte;
                }
            }
        }

        $texte = trim(preg_replace('/\s+/u', ' ', $texte) ?? $texte);
        // Coupe à TEXTE_MAX caractères (sans mbstring, qui n'est pas listé dans composer.json).
        if (preg_match('/^.{0,' . self::TEXTE_MAX . '}/us', $texte, $m)) {
            $texte = rtrim($m[0]);
        }

        // Il doit rester assez de vrais mots une fois les marqueurs retirés.
        $reste = preg_replace('/\[(?:email|numéro|nom)\]/u', '', $texte) ?? $texte;
        if (preg_match_all('/\p{L}/u', $reste) < self::LETTRES_MIN) {
            return null;
        }

        return $texte;
    }
}
