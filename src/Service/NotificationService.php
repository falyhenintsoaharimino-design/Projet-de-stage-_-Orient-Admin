<?php

namespace App\Service;

use App\Entity\MessageAgent;
use App\Entity\Notification;
use App\Entity\Procedure;
use App\Entity\RendezVous;
use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Notifications : une ligne en base (canal "app", lue / non lue) + un e-mail, ou un SMS simulé
 * si le destinataire a choisi le canal « sms » et renseigné son téléphone (EF-33).
 * L'envoi d'e-mail ne doit jamais faire échouer l'action métier : en cas
 * d'erreur elle est seulement écrite dans le log.
 *
 * Les lignes créées ici sont seulement persistées : l'appelant fait le flush.
 */
class NotificationService
{
    public const CANAL_APP = 'app';
    public const NON_LUE = 'non_lue';
    public const LUE = 'lue';

    public function __construct(
        private EntityManagerInterface $em,
        private MailerInterface $mailer,
        private UtilisateurRepository $utilisateurRepository,
        private LoggerInterface $logger,
        private SmsSimulateur $sms,
        private ParametreService $parametres,
        #[Autowire(env: 'MAILER_FROM')]
        private string $expediteur,
    ) {
    }

    public function notifier(Utilisateur $destinataire, string $type, string $sujet, string $message): Notification
    {
        $notification = new Notification();
        $notification->setType($type);
        $notification->setCanal(self::CANAL_APP);
        $notification->setMessage($message);
        $notification->setStatut(self::NON_LUE);
        $notification->setDateEnvoi(new \DateTimeImmutable());
        $notification->setUtilisateur($destinataire);
        $this->em->persist($notification);

        if ($this->sms->veutUnSms($destinataire)) {
            $this->sms->envoyer($destinataire, 'Orient-Admin : ' . $message);
        } else {
            $this->envoyerEmail($destinataire, $sujet, $message);
        }

        return $notification;
    }

    /** Rappel de la veille (EF-31) : envoyé par la commande app:rappels-rdv pour un rendez-vous confirmé. */
    public function rappelRendezVous(RendezVous $rdv): void
    {
        $citoyen = $rdv->getDemande()?->getUtilisateur();
        if (!$citoyen || !$rdv->getCreneau()) {
            return;
        }
        $this->notifierModele($citoyen, 'rdv_rappel', $this->variablesRdv($rdv));
    }

    /**
     * Alerte « pièces manquantes » (EF-32) : l'agent signale au citoyen ce qu'il doit encore fournir.
     *
     * @param list<string> $pieces
     */
    public function piecesManquantes(RendezVous $rdv, array $pieces, ?string $message = null): void
    {
        $citoyen = $rdv->getDemande()?->getUtilisateur();
        if (!$citoyen || !$rdv->getCreneau()) {
            return;
        }
        $this->notifierModele($citoyen, 'pieces_manquantes', [
            'liste' => implode(' ; ', $pieces),
            'message' => $message ? " $message" : '',
        ] + $this->variablesRdv($rdv));
    }

    /** Alerte « changement de procédure » (EF-32) : prévient le citoyen dont le rendez-vous concerne la procédure modifiée. */
    public function procedureModifiee(RendezVous $rdv, Procedure $procedure, string $resume): void
    {
        $citoyen = $rdv->getDemande()?->getUtilisateur();
        if (!$citoyen || !$rdv->getCreneau()) {
            return;
        }
        [$quand] = $this->descriptionRdv($rdv);
        $texte = $procedure->isActif()
            ? "La procédure « {$procedure->getNom()} » a changé ($resume). Vérifiez avant votre rendez-vous $quand."
              . $this->piecesAApporter($rdv)
            : "La procédure « {$procedure->getNom()} » n'est plus proposée. Contactez le service avant votre rendez-vous $quand.";
        $this->notifier($citoyen, 'procedure_modifiee', 'Une procédure de votre rendez-vous a changé', $texte);
    }

    /** Message d'un agent à un citoyen (page « communication »). */
    public function messageAgent(MessageAgent $message): void
    {
        $demande = $message->getDemande();
        $citoyen = $demande?->getUtilisateur();
        if (!$citoyen) {
            return;
        }
        $service = $demande->getServiceFinal() ?? $demande->getServiceRecommande();
        $origine = $service ? 'du service ' . $service->getNom() : 'd\'un agent';
        $this->notifier($citoyen, 'message_agent', $message->getSujet() ?: 'Message de votre service',
            "Message $origine : " . $message->getTexte());
    }

    /**
     * Notification au citoyen d'après le modèle de message (personnalisé par l'admin, EF-46, ou par défaut).
     *
     * @param array<string, string> $variables
     */
    private function notifierModele(Utilisateur $destinataire, string $type, array $variables): void
    {
        [$sujet, $message] = $this->parametres->rendre($type, $variables);
        $this->notifier($destinataire, $type, $sujet, $message);
    }

    /** @return array<string, string> variables {quand}, {lieu}, {service}, {pieces} d'un rendez-vous */
    private function variablesRdv(RendezVous $rdv): array
    {
        [$quand, $ou] = $this->descriptionRdv($rdv);

        return [
            'quand' => $quand,
            'lieu' => $ou,
            'service' => $rdv->getCreneau()?->getService()?->getNom() ?? 'le service',
            'pieces' => $this->piecesAApporter($rdv),
        ];
    }

    /** @return array{0: string, 1: string} « du 12/11/2026 à 09:00 » et « Service, adresse » */
    private function descriptionRdv(RendezVous $rdv): array
    {
        $creneau = $rdv->getCreneau();
        $service = $creneau?->getService();
        $lieu = $service?->getNom() ?? 'le service';
        $adresse = $service?->getAdresse();

        return [
            sprintf('du %s à %s', $creneau?->getDate()?->format('d/m/Y'), $creneau?->getHeure()),
            $adresse ? "$lieu, $adresse" : $lieu,
        ];
    }

    /**
     * Événements d'un rendez-vous : reserve, confirme, refuse, annule, termine, modifie, reporte.
     * $par = l'utilisateur qui a agi (sert à savoir qui prévenir en cas d'annulation).
     * $contexte : pour « reporte », 'ancien' (ancienne date) et 'motif' facultatif.
     *
     * @param array<string, mixed> $contexte
     */
    public function notifierRendezVous(RendezVous $rdv, string $evenement, ?Utilisateur $par = null, array $contexte = []): void
    {
        $citoyen = $rdv->getDemande()?->getUtilisateur();
        $creneau = $rdv->getCreneau();
        $service = $creneau?->getService();
        if (!$citoyen || !$creneau) {
            return;
        }

        $quand = sprintf('du %s à %s', $creneau->getDate()?->format('d/m/Y'), $creneau->getHeure());
        $lieu = $service?->getNom() ?? 'le service';
        $adresse = $service?->getAdresse();
        $ou = $adresse ? "$lieu, $adresse" : $lieu;
        $agents = $service ? $this->utilisateurRepository->findAgentsDuService($service) : [];
        $nomCitoyen = trim($citoyen->getPrenom() . ' ' . $citoyen->getNom());
        $variables = ['quand' => $quand, 'lieu' => $ou, 'service' => $lieu, 'pieces' => $this->piecesAApporter($rdv)];

        switch ($evenement) {
            case 'reserve':
                $this->notifierModele($citoyen, 'rdv_en_attente', $variables);
                foreach ($agents as $agent) {
                    $this->notifier($agent, 'rdv_a_valider', 'Nouveau rendez-vous à valider',
                        "$nomCitoyen demande un rendez-vous $quand. À confirmer ou refuser.");
                }
                break;

            case 'confirme':
                $this->notifierModele($citoyen, 'rdv_confirme', $variables);
                break;

            case 'modifie':
                $this->notifierModele($citoyen, 'rdv_modifie', $variables);
                foreach ($agents as $agent) {
                    $this->notifier($agent, 'rdv_a_valider', 'Rendez-vous modifié à valider',
                        "$nomCitoyen a déplacé son rendez-vous $quand. À confirmer ou refuser.");
                }
                break;

            case 'reporte':
                $this->notifierModele($citoyen, 'rdv_reporte', [
                    'ancien' => (string) ($contexte['ancien'] ?? ''),
                    'motif' => !empty($contexte['motif']) ? ' Motif : ' . $contexte['motif'] . '.' : '',
                ] + $variables);
                break;

            case 'refuse':
                $this->notifierModele($citoyen, 'rdv_refuse', $variables);
                break;

            case 'termine':
                $this->notifierModele($citoyen, 'rdv_termine', $variables);
                break;

            case 'annule':
                if ($par !== null && $par->getId() === $citoyen->getId()) {
                    // Le citoyen annule : on prévient les agents du service.
                    foreach ($agents as $agent) {
                        $this->notifier($agent, 'rdv_annule', 'Rendez-vous annulé',
                            "$nomCitoyen a annulé son rendez-vous $quand.");
                    }
                } else {
                    // L'agent (ou l'admin) annule : on prévient le citoyen.
                    $this->notifier($citoyen, 'rdv_annule', 'Rendez-vous annulé',
                        "Votre rendez-vous $quand ($lieu) a été annulé par le service. Vous pouvez choisir un autre créneau.");
                }
                break;
        }
    }

    /** « Pièces à apporter : ... » d'après la procédure choisie pour le rendez-vous (vide sinon). */
    private function piecesAApporter(RendezVous $rdv): string
    {
        $procedure = $rdv->getProcedure();
        if (!$procedure || $procedure->getDocumentRequis()->isEmpty()) {
            return '';
        }
        $lignes = [];
        foreach ($procedure->getDocumentRequis() as $d) {
            $details = [];
            if ($d->getExemplaires() > 1) {
                $details[] = $d->getExemplaires() . ' exemplaires';
            }
            if ($d->getAncienneteMaxMois() !== null) {
                $details[] = 'de moins de ' . $d->getAncienneteMaxMois() . ' mois';
            }
            if (!$d->isObligatoire()) {
                $details[] = 'facultatif';
            }
            $lignes[] = $d->getLibelle() . ($details ? ' (' . implode(', ', $details) . ')' : '');
        }

        return ' Pièces à apporter : ' . implode(' ; ', $lignes) . '.';
    }

    /** E-mail seul, sans ligne de notification en base (ne lève jamais d'exception). */
    public function envoyerEmail(Utilisateur $destinataire, string $sujet, string $message): void
    {
        if (!$destinataire->getEmail()) {
            return;
        }
        try {
            $this->mailer->send(
                (new Email())
                    ->from($this->expediteur)
                    ->to($destinataire->getEmail())
                    ->subject('[Orient-Admin] ' . $sujet)
                    ->text($message)
            );
        } catch (\Throwable $e) {
            $this->logger->error('Envoi e-mail impossible : ' . $e->getMessage(), ['destinataire' => $destinataire->getEmail()]);
        }
    }
}
