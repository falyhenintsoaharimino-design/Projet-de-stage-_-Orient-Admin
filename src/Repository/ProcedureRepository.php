<?php

namespace App\Repository;

use App\Entity\Procedure;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Procedure>
 */
class ProcedureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Procedure::class);
    }

    /**
     * Procédures listées pour un visiteur : actives, d'un service actif. Avec $inclureInactifs, un admin
     * ($admin) voit tout et un agent ($monServiceId) voit en plus les procédures de son propre service.
     *
     * @return list<Procedure>
     */
    public function visibles(bool $inclureInactifs, bool $admin, ?int $monServiceId, ?int $serviceId): array
    {
        $qb = $this->createQueryBuilder('p')->join('p.service', 's')->addSelect('s')->orderBy('p.id', 'ASC');

        if (!($inclureInactifs && $admin)) {
            $visible = 'p.actif = true AND s.actif = true';
            if ($inclureInactifs && $monServiceId !== null) {
                $visible = "($visible) OR s.id = :monService";
                $qb->setParameter('monService', $monServiceId);
            }
            $qb->andWhere($visible);
        }
        if ($serviceId !== null) {
            $qb->andWhere('s.id = :serviceId')->setParameter('serviceId', $serviceId);
        }

        return $qb->getQuery()->getResult();
    }
}
