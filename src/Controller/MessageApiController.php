<?php

namespace App\Controller;

use App\Entity\Demande;
use App\Entity\MessageAgent;
use App\Entity\Utilisateur;
use App\Repository\DemandeRepository;
use App\Repository\MessageAgentRepository;
use App\Service\JournalService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Messages d'un agent à un citoyen, à propos d'une demande (page « communication » de l'agent).
 * Envoi : l'agent du service de la demande, ou l'admin. Lecture : le citoyen auteur de la demande, l'agent du service,
 * le responsable, l'admin. Le citoyen reçoit aussi le message comme notification (application + e-mail ou SMS).
 */
#[Route('/api/demandes/{id}/messages', requirements: ['id' => '\d+'])]
class MessageApiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private DemandeRepository $demandeRepository,
        private MessageAgentRepository $messageRepository,
        private NotificationService $notifications,
        private JournalService $journal,
    ) {
    }

    #[Route('', name: 'api_messages_list', methods: ['GET'])]
    public function list(int $id, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $demande = $this->demandeRepository->find($id);
        if (!$demande) {
            return $this->json(['error' => 'Demande non trouvée'], 404);
        }
        if (!$this->peutAcceder($demande, $utilisateur, true)) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        $messages = $this->messageRepository->findBy(['demande' => $demande], ['creeLe' => 'ASC', 'id' => 'ASC']);

        return $this->json(array_map(fn(MessageAgent $m) => $this->toArray($m), $messages));
    }

    /** Corps : { "message": "Merci d'apporter un justificatif de domicile.", "sujet": "Pièce à ajouter" } (sujet facultatif). */
    #[Route('', name: 'api_messages_create', methods: ['POST'])]
    #[IsGranted('ROLE_AGENT')]
    public function create(int $id, Request $request, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $demande = $this->demandeRepository->find($id);
        if (!$demande) {
            return $this->json(['error' => 'Demande non trouvée'], 404);
        }
        if (!$this->peutAcceder($demande, $utilisateur, false)) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $texte = is_array($data) ? ($data['message'] ?? null) : null;
        $sujet = is_array($data) ? ($data['sujet'] ?? null) : null;
        if (!is_string($texte) || trim($texte) === '' || strlen($texte) > 2000) {
            return $this->json(['error' => 'message est requis (1 à 2000 caractères)'], 400);
        }
        if ($sujet !== null && (!is_string($sujet) || strlen($sujet) > 150)) {
            return $this->json(['error' => 'sujet doit être un texte de 150 caractères au plus'], 400);
        }

        $message = (new MessageAgent())->setDemande($demande)->setAuteur($utilisateur)
            ->setTexte(trim($texte))->setSujet($sujet !== null && trim($sujet) !== '' ? trim($sujet) : null)
            ->setCreeLe(new \DateTimeImmutable());
        $this->em->persist($message);
        $this->notifications->messageAgent($message);
        $this->em->flush();
        $this->journal->enregistrer('message_agent_envoye', $utilisateur, sprintf('demande=%d message=%d', $demande->getId(), $message->getId()));

        return $this->json($this->toArray($message), 201);
    }

    /** $lecture = true : le citoyen, le responsable et l'admin peuvent lire ; l'envoi reste à l'agent du service et à l'admin. */
    private function peutAcceder(Demande $demande, Utilisateur $utilisateur, bool $lecture): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        if ($this->isGranted('ROLE_AGENT')) {
            $service = $utilisateur->getService();

            return $service !== null && ($demande->getServiceRecommande() === $service || $demande->getServiceFinal() === $service);
        }
        if (!$lecture) {
            return false;
        }

        return $this->isGranted('ROLE_RESPONSABLE') || $demande->getUtilisateur() === $utilisateur;
    }

    /** @return array<string, mixed> */
    private function toArray(MessageAgent $m): array
    {
        $auteur = $m->getAuteur();

        return [
            'id' => $m->getId(),
            'demandeId' => $m->getDemande()?->getId(),
            'sujet' => $m->getSujet(),
            'message' => $m->getTexte(),
            'auteur' => $auteur ? ['id' => $auteur->getId(), 'nom' => trim($auteur->getPrenom() . ' ' . $auteur->getNom())] : null,
            'date' => $m->getCreeLe()?->format(\DATE_ATOM),
        ];
    }
}
