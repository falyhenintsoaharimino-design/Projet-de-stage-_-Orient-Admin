<?php

namespace App\Repository;

use App\Entity\ExempleOrientation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExempleOrientation>
 */
class ExempleOrientationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExempleOrientation::class);
    }

    /**
     * Exemples envoyés au moteur pour le réentraînement.
     *
     * @return array<int, array{texte: string, service: string}>
     */
    public function pourEntrainement(): array
    {
        return array_map(
            fn(array $r) => ['texte' => $r['texte'], 'service' => $r['service']],
            $this->getEntityManager()->getConnection()->fetchAllAssociative(
                'SELECT e.texte AS texte, s.nom AS service FROM exemple_orientation e JOIN service s ON s.id = e.service_id ORDER BY e.id'
            )
        );
    }

    /**
     * @return array{total: int, parOrigine: array<int, array{origine: string, total: int}>, parService: array<int, array{service: string, total: int}>}
     */
    public function statistiques(): array
    {
        $connexion = $this->getEntityManager()->getConnection();
        $parOrigine = array_map(
            fn(array $r) => ['origine' => $r['origine'], 'total' => (int) $r['nb']],
            $connexion->fetchAllAssociative('SELECT origine, COUNT(*) AS nb FROM exemple_orientation GROUP BY origine ORDER BY nb DESC, origine')
        );
        $parService = array_map(
            fn(array $r) => ['service' => $r['nom'], 'total' => (int) $r['nb']],
            $connexion->fetchAllAssociative(
                'SELECT s.nom AS nom, COUNT(e.id) AS nb FROM exemple_orientation e JOIN service s ON s.id = e.service_id GROUP BY s.nom ORDER BY nb DESC, s.nom'
            )
        );

        return ['total' => array_sum(array_column($parOrigine, 'total')), 'parOrigine' => $parOrigine, 'parService' => $parService];
    }
}
