<?php

namespace App\Repository;

use App\Entity\RendezVous;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RendezVous>
 */
class RendezVousRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RendezVous::class);
    }

    public const STATUTS_ACTIFS = ['en_attente', 'confirme'];

    /** Nombre de rendez-vous actifs (en attente ou confirmés) d'un créneau. */
    public function compterActifs(int $creneauId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('IDENTITY(r.creneau) = :c')->andWhere('r.statut IN (:statuts)')
            ->setParameter('c', $creneauId)->setParameter('statuts', self::STATUTS_ACTIFS)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Nombre de rendez-vous actifs pour chaque créneau d'une liste, en une seule requête.
     *
     * @param list<int> $creneauIds
     *
     * @return array<int, int> créneau => nombre
     */
    public function compterActifsParCreneau(array $creneauIds): array
    {
        if ($creneauIds === []) {
            return [];
        }
        $lignes = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.creneau) AS creneau, COUNT(r.id) AS nb')
            ->where('IDENTITY(r.creneau) IN (:ids)')->andWhere('r.statut IN (:statuts)')
            ->setParameter('ids', $creneauIds)->setParameter('statuts', self::STATUTS_ACTIFS)
            ->groupBy('r.creneau')
            ->getQuery()->getArrayResult();

        $nombres = [];
        foreach ($lignes as $ligne) {
            $nombres[(int) $ligne['creneau']] = (int) $ligne['nb'];
        }

        return $nombres;
    }
}
