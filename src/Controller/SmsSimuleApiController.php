<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Service\SmsSimulateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Boîte d'envoi de la passerelle SMS simulée (EF-33) : réservée à l'administrateur. */
#[Route('/api/sms-simules')]
#[IsGranted('ROLE_ADMIN')]
class SmsSimuleApiController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** GET /api/sms-simules?limite=50 (200 au plus) : SMS « envoyés », du plus récent au plus ancien. */
    #[Route('', name: 'api_sms_simules', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limite = max(1, min(200, $request->query->getInt('limite', 50)));
        $sms = $this->em->getRepository(Notification::class)->findBy(
            ['canal' => SmsSimulateur::CANAL_SMS],
            ['dateEnvoi' => 'DESC', 'id' => 'DESC'],
            $limite
        );

        return $this->json(array_map(fn(Notification $n) => [
            'id' => $n->getId(),
            'telephone' => $n->getUtilisateur()?->getTelephone(),
            'destinataire' => $n->getUtilisateur() ? [
                'id' => $n->getUtilisateur()->getId(),
                'nom' => trim($n->getUtilisateur()->getPrenom() . ' ' . $n->getUtilisateur()->getNom()),
            ] : null,
            'message' => $n->getMessage(),
            'statut' => $n->getStatut(),
            'date' => $n->getDateEnvoi()?->format(\DATE_ATOM),
        ], $sms));
    }
}
