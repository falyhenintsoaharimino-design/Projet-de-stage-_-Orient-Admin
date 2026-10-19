<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Repository\ServiceRepository;
use App\Service\JournalService;
use App\Service\StatistiquesService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Statistiques du responsable (EF-39 à EF-43), réservées à ROLE_RESPONSABLE
 * (l'administrateur y a accès par la hiérarchie des rôles).
 *
 * Filtres communs (facultatifs) : ?du=AAAA-MM-JJ, ?au=AAAA-MM-JJ, ?serviceId=,
 * ?pas=jour|mois (courbes d'évolution, défaut mois).
 * Les définitions des indicateurs sont décrites dans App\Service\StatistiquesService.
 */
#[Route('/api/statistiques')]
#[IsGranted('ROLE_RESPONSABLE')]
class StatistiquesApiController extends AbstractController
{
    public function __construct(
        private StatistiquesService $statistiques,
        private ServiceRepository $serviceRepository,
        private JournalService $journal,
    ) {
    }

    #[Route('/tableau-de-bord', name: 'api_stats_tableau', methods: ['GET'])]
    public function tableauDeBord(Request $request): JsonResponse
    {
        return $this->reponse($request, fn(array $f) => $this->statistiques->tableauDeBord($f));
    }

    #[Route('/demandes', name: 'api_stats_demandes', methods: ['GET'])]
    public function demandes(Request $request): JsonResponse
    {
        return $this->reponse($request, fn(array $f) => $this->statistiques->demandes($f));
    }

    #[Route('/rendez-vous', name: 'api_stats_rdv', methods: ['GET'])]
    public function rendezVous(Request $request): JsonResponse
    {
        return $this->reponse($request, fn(array $f) => $this->statistiques->rendezVous($f));
    }

    #[Route('/affluence', name: 'api_stats_affluence', methods: ['GET'])]
    public function affluence(Request $request): JsonResponse
    {
        return $this->reponse($request, fn(array $f) => $this->statistiques->affluence($f));
    }

    #[Route('/satisfaction', name: 'api_stats_satisfaction', methods: ['GET'])]
    public function satisfaction(Request $request): JsonResponse
    {
        return $this->reponse($request, fn(array $f) => $this->statistiques->satisfaction($f));
    }

    #[Route('/orientation', name: 'api_stats_orientation', methods: ['GET'])]
    public function orientation(Request $request): JsonResponse
    {
        return $this->reponse($request, fn(array $f) => $this->statistiques->orientation($f));
    }

    /**
     * Export CSV : ?type=synthese|demandes|rendez-vous|avis (obligatoire) + filtres communs.
     * Format lisible directement par Excel en français : séparateur « ; », UTF-8 avec BOM.
     * Les exports de demandes ne contiennent ni le texte ni l'identité du citoyen.
     */
    #[Route('/export', name: 'api_stats_export', methods: ['GET'])]
    public function export(Request $request, #[CurrentUser] Utilisateur $utilisateur): Response
    {
        $type = (string) $request->query->get('type', '');
        if (!in_array($type, StatistiquesService::TYPES_EXPORT, true)) {
            return $this->json(['error' => 'type invalide. Valeurs possibles : ' . implode(', ', StatistiquesService::TYPES_EXPORT)], 400);
        }
        $format = (string) $request->query->get('format', 'csv');
        if ($format !== 'csv') {
            return $this->json(['error' => 'format invalide. Seul csv est disponible'], 400);
        }

        try {
            $f = $this->filtres($request);
            [$entetes, $lignes] = $this->statistiques->exporter($type, $f);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 400);
        }

        $this->journal->enregistrer('export_rapport', $utilisateur, sprintf(
            'type=%s du=%s au=%s service=%s lignes=%d',
            $type,
            $f['du']?->format('Y-m-d') ?? '-',
            $f['au']?->format('Y-m-d') ?? '-',
            $f['serviceId'] ?? '-',
            count($lignes)
        ));

        $nom = sprintf('rapport-%s-%s.csv', $type, (new \DateTimeImmutable())->format('Ymd-His'));

        return new Response($this->csv($entetes, $lignes), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $nom),
        ]);
    }

    private function reponse(Request $request, callable $calcul): JsonResponse
    {
        try {
            $f = $this->filtres($request);

            return $this->json(['filtres' => $this->filtresEnTableau($f)] + $calcul($f));
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 400);
        }
    }

    /**
     * @return array{du: ?\DateTimeImmutable, au: ?\DateTimeImmutable, serviceId: ?int, pas: string}
     * @throws \DomainException 400 (paramètre invalide) ou 404 (service inconnu)
     */
    private function filtres(Request $request): array
    {
        $du = $this->jour($request->query->get('du'), 'du');
        $au = $this->jour($request->query->get('au'), 'au');
        if ($du && $au && $du > $au) {
            throw new \DomainException('du doit être antérieur ou égal à au', 400);
        }

        $serviceId = $request->query->get('serviceId');
        if ($serviceId === null || $serviceId === '') {
            $serviceId = null;
        } else {
            if (!ctype_digit((string) $serviceId) || (int) $serviceId < 1) {
                throw new \DomainException('serviceId invalide', 400);
            }
            $serviceId = (int) $serviceId;
            if (!$this->serviceRepository->find($serviceId)) {
                throw new \DomainException('Service introuvable', 404);
            }
        }

        $pas = (string) $request->query->get('pas', 'mois');
        if (!in_array($pas, ['jour', 'mois'], true)) {
            throw new \DomainException('pas invalide. Valeurs possibles : jour, mois', 400);
        }

        return ['du' => $du, 'au' => $au, 'serviceId' => $serviceId, 'pas' => $pas];
    }

    private function filtresEnTableau(array $f): array
    {
        return [
            'du' => $f['du']?->format('Y-m-d'),
            'au' => $f['au']?->format('Y-m-d'),
            'serviceId' => $f['serviceId'],
            'pas' => $f['pas'],
        ];
    }

    private function jour(mixed $valeur, string $nom): ?\DateTimeImmutable
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }
        $date = is_string($valeur) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur) : false;
        if (!$date || $date->format('Y-m-d') !== $valeur) {
            throw new \DomainException(sprintf('%s : format AAAA-MM-JJ attendu', $nom), 400);
        }

        return $date;
    }

    /** CSV pour Excel (français) : « ; », UTF-8 avec BOM, décimales à la virgule. */
    private function csv(array $entetes, array $lignes): string
    {
        $flux = fopen('php://temp', 'r+');
        fwrite($flux, "\xEF\xBB\xBF");
        fputcsv($flux, $entetes, ';', '"', '', "\r\n");
        foreach ($lignes as $ligne) {
            fputcsv($flux, array_map($this->cellule(...), $ligne), ';', '"', '', "\r\n");
        }
        rewind($flux);
        $contenu = stream_get_contents($flux);
        fclose($flux);

        return $contenu;
    }

    private function cellule(mixed $valeur): string
    {
        if ($valeur === null) {
            return '';
        }
        if (is_float($valeur)) {
            return str_replace('.', ',', (string) $valeur);
        }
        $texte = (string) $valeur;
        // Un texte saisi par un utilisateur (ex. commentaire d'avis) ne doit pas être
        // interprété comme une formule par Excel (injection de formule).
        if ($texte !== '' && str_contains("=+-@\t\r", $texte[0])) {
            $texte = "'" . $texte;
        }

        return $texte;
    }
}
