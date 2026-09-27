<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute service.mots_cles : liste de mots-clés séparés par des virgules,
 * utilisée par le moteur d'orientation (App\Service\OrientationEngine) pour
 * reconnaître un service à partir du texte libre d'un citoyen.
 *
 * Stockée en base plutôt que codée en dur : un agent ou l'administrateur
 * peut l'enrichir via PUT /api/services/{id} sans toucher au code, ce qui
 * correspond à la "base de connaissances" évolutive demandée par le cahier
 * des charges (EF-11, EF-21).
 */
final class Version20260926090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute service.mots_cles pour le moteur d'orientation";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service ADD mots_cles TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service DROP mots_cles');
    }
}
