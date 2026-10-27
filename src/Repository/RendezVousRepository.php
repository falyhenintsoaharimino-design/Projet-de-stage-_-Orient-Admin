<?php

namespace App\Repository;

use App\Entity\Procedure;
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
     * Rendez-vous actifs, à partir d'aujourd'hui (AAAA-MM-JJ), qui concernent une procédure.
     *
     * @return list<RendezVous>
     */
    public function aVenirDeLaProcedure(Procedure $procedure, string $aujourdhui): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.creneau', 'c')->addSelect('c')
            ->where('r.procedure = :p')->andWhere('r.statut IN (:statuts)')->andWhere('c.date >= :d')
            ->setParameter('p', $procedure)->setParameter('statuts', self::STATUTS_ACTIFS)->setParameter('d', $aujourdhui)
            ->orderBy('c.date')->addOrderBy('c.heure')
            ->getQuery()->getResult();
    }

    /**
     * Rendez-vous confirmés d'un jour donné (AAAA-MM-JJ), sans rappel déjà envoyé.
     *
     * @return list<RendezVous>
     */
    public function aRappeler(string $jour): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.creneau', 'c')->addSelect('c')
            ->where('r.statut = :statut')->andWhere('c.date = :jour')->andWhere('r.rappelEnvoyeLe IS NULL')
            ->setParameter('statut', 'confirme')->setParameter('jour', $jour)
            ->orderBy('c.heure')->addOrderBy('r.id')
            ->getQuery()->getResult();
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
