<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\Utilisateur;
use App\Repository\NotificationRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Notifications de l'utilisateur connecté (tous rôles). Chacun ne voit et
 * ne modifie que les siennes.
 */
#[Route('/api/notifications')]
class NotificationApiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationRepository $notificationRepository,
    ) {
    }

    /** GET /api/notifications?nonLues=1 (les 50 plus récentes) */
    #[Route('', name: 'api_notifications_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $nonLues = $request->query->getBoolean('nonLues');
        $notifications = $this->notificationRepository->pourUtilisateur($utilisateur, $nonLues);

        return $this->json(array_map(fn(Notification $n) => $this->toArray($n), $notifications));
    }

    /** GET /api/notifications/compteur -> { "nonLues": 3 } */
    #[Route('/compteur', name: 'api_notifications_compteur', methods: ['GET'])]
    public function compteur(#[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        return $this->json(['nonLues' => $this->notificationRepository->compterNonLues($utilisateur)]);
    }

    #[Route('/lu-tout', name: 'api_notifications_lu_tout', methods: ['PATCH'])]
    public function luTout(#[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        return $this->json(['marquees' => $this->notificationRepository->toutMarquerLu($utilisateur)]);
    }

    #[Route('/{id}/lu', name: 'api_notifications_lu', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function lu(int $id, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $notification = $this->notificationRepository->find($id);
        if (!$notification) {
            return $this->json(['error' => 'Notification non trouvée'], 404);
        }
        if ($notification->getUtilisateur() !== $utilisateur) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        $notification->setStatut(NotificationService::LUE);
        $this->em->flush();

        return $this->json($this->toArray($notification));
    }

    private function toArray(Notification $n): array
    {
        return [
            'id' => $n->getId(),
            'type' => $n->getType(),
            'message' => $n->getMessage(),
            'statut' => $n->getStatut(),
            'lue' => $n->getStatut() === NotificationService::LUE,
            'date' => $n->getDateEnvoi()?->format(DATE_ATOM),
        ];
    }
}
