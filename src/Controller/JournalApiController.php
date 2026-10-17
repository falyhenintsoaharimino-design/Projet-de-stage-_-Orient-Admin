<?php

namespace App\Controller;

use App\Entity\Journal;
use App\Repository\JournalRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Consultation du journal d'audit, réservée à l'administrateur.
 * Filtres : ?action=, ?utilisateurId=, ?du=AAAA-MM-JJ, ?au=AAAA-MM-JJ
 * Pagination : ?page= (défaut 1), ?limite= (défaut 20, maximum 100).
 */
#[Route('/api/journal')]
#[IsGranted('ROLE_ADMIN')]
class JournalApiController extends AbstractController
{
    private const LIMITE_DEFAUT = 20;
    private const LIMITE_MAX = 100;

    public function __construct(private JournalRepository $journalRepository)
    {
    }

    #[Route('', name: 'api_journal_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = $this->entierPositif($request->query->get('page'), 1);
        $limite = $this->entierPositif($request->query->get('limite'), self::LIMITE_DEFAUT);
        $utilisateurId = $request->query->get('utilisateurId');
        if ($page === null || $limite === null || $limite > self::LIMITE_MAX) {
            return $this->json(['error' => sprintf('page et limite doivent être des entiers positifs (limite maximum %d)', self::LIMITE_MAX)], 400);
        }
        if ($utilisateurId !== null && $utilisateurId !== '' && $this->entierPositif($utilisateurId, 0) === null) {
            return $this->json(['error' => 'utilisateurId invalide'], 400);
        }

        $du = $this->jour($request->query->get('du'));
        $au = $this->jour($request->query->get('au'));
        if ($du === false || $au === false) {
            return $this->json(['error' => 'du / au : format AAAA-MM-JJ attendu'], 400);
        }

        $action = $request->query->get('action');
        $resultat = $this->journalRepository->rechercher([
            'action' => is_string($action) && $action !== '' ? $action : null,
            'utilisateurId' => ($utilisateurId === null || $utilisateurId === '') ? null : (int) $utilisateurId,
            'du' => $du,
            'au' => $au,
        ], $page, $limite);

        return $this->json([
            'items' => array_map(fn(Journal $j) => $this->toArray($j), $resultat['items']),
            'page' => $page,
            'limite' => $limite,
            'total' => $resultat['total'],
            'pages' => max(1, (int) ceil($resultat['total'] / $limite)),
        ]);
    }

    /** Liste des actions déjà enregistrées (pour alimenter un filtre). */
    #[Route('/actions', name: 'api_journal_actions', methods: ['GET'])]
    public function actions(): JsonResponse
    {
        return $this->json($this->journalRepository->actionsConnues());
    }

    /** Valeur absente -> $defaut ; entier > 0 -> l'entier ; sinon null (invalide). */
    private function entierPositif(mixed $valeur, int $defaut): ?int
    {
        if ($valeur === null || $valeur === '') {
            return $defaut;
        }
        if (!ctype_digit((string) $valeur) || (int) $valeur < 1) {
            return null;
        }

        return (int) $valeur;
    }

    /** null : absent ; DateTimeImmutable : valide ; false : invalide. */
    private function jour(mixed $valeur): \DateTimeImmutable|false|null
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }
        if (!is_string($valeur)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);

        return ($date && $date->format('Y-m-d') === $valeur) ? $date : false;
    }

    private function toArray(Journal $j): array
    {
        $u = $j->getUtilisateur();

        return [
            'id' => $j->getId(),
            'action' => $j->getAction(),
            'date' => $j->getDate()?->format(DATE_ATOM),
            'adresseIp' => $j->getAdresseIp(),
            'details' => $j->getDetails(),
            'utilisateur' => $u === null ? null : [
                'id' => $u->getId(),
                'nom' => $u->getNom(),
                'prenom' => $u->getPrenom(),
                'email' => $u->getEmail(),
            ],
        ];
    }
}
