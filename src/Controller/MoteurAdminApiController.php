<?php

namespace App\Controller;

use App\Entity\ExempleOrientation;
use App\Entity\Utilisateur;
use App\Repository\ExempleOrientationRepository;
use App\Service\JournalService;
use App\Service\MoteurClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administration du moteur d'orientation (EF-21) : état et métriques du service Python,
 * exemples d'apprentissage (consultation, suppression) et réentraînement. Réservé à l'administrateur.
 */
#[Route('/api/admin/moteur')]
#[IsGranted('ROLE_ADMIN')]
class MoteurAdminApiController extends AbstractController
{
    public function __construct(
        private MoteurClient $moteur,
        private ExempleOrientationRepository $exemples,
        private EntityManagerInterface $em,
        private JournalService $journal,
    ) {
    }

    /** État du service Python (null s'il est indisponible), dernières métriques et résumé des exemples. */
    #[Route('', name: 'api_admin_moteur_etat', methods: ['GET'])]
    public function etat(): JsonResponse
    {
        $sante = $this->moteur->sante();

        return $this->json([
            'moteur' => [
                'actif' => $this->moteur->actif(),
                'disponible' => $sante !== null,
                'sante' => $sante,
                'metriques' => $sante !== null ? $this->moteur->metriques() : null,
            ],
            'exemples' => $this->exemples->statistiques(),
        ]);
    }

    #[Route('/exemples', name: 'api_admin_moteur_exemples', methods: ['GET'])]
    public function lister(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limite = min(100, max(1, (int) $request->query->get('limite', 20)));
        $criteres = [];
        if ($origine = (string) $request->query->get('origine', '')) {
            $criteres['origine'] = $origine;
        }

        $total = $this->exemples->count($criteres);

        return $this->json([
            'page' => $page,
            'limite' => $limite,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $limite)),
            'exemples' => array_map(fn(ExempleOrientation $e) => [
                'id' => $e->getId(),
                'texte' => $e->getTexte(),
                'origine' => $e->getOrigine(),
                'service' => ['id' => $e->getService()?->getId(), 'nom' => $e->getService()?->getNom()],
                'demandeId' => $e->getDemande()?->getId(),
                'dateCreation' => $e->getDateCreation()?->format(DATE_ATOM),
            ], $this->exemples->findBy($criteres, ['id' => 'DESC'], $limite, ($page - 1) * $limite)),
        ]);
    }

    /** Retire un exemple jugé mauvais ; il ne sera plus utilisé au prochain réentraînement. */
    #[Route('/exemples/{id}', name: 'api_admin_moteur_exemple_supprimer', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function supprimer(int $id, #[CurrentUser] Utilisateur $admin): JsonResponse
    {
        $exemple = $this->exemples->find($id);
        if (!$exemple) {
            return $this->json(['error' => 'Exemple non trouvé'], 404);
        }
        $this->em->remove($exemple);
        $this->em->flush();
        $this->journal->enregistrer('exemple_supprime', $admin, sprintf('exemple=%d', $id));

        return $this->json(['message' => 'Exemple supprimé']);
    }

    /** Envoie tous les exemples au service Python, qui se réentraîne (exemples de base + ceux-ci). */
    #[Route('/reentrainer', name: 'api_admin_moteur_reentrainer', methods: ['POST'])]
    public function reentrainer(#[CurrentUser] Utilisateur $admin): JsonResponse
    {
        $resultat = $this->moteur->reentrainer($this->exemples->pourEntrainement());
        if ($resultat === null) {
            return $this->json(['error' => 'Service d\'orientation indisponible (voir MOTEUR_URL et MOTEUR_TOKEN)'], 502);
        }
        $this->journal->enregistrer('moteur_reentraine', $admin, sprintf('version=%s exemples=%s', $resultat['version'] ?? '?', $resultat['nbSupplementaires'] ?? '?'));

        return $this->json($resultat);
    }
}
