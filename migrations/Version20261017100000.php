<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Renomme l'index de rendez_vous.creneau_id : il portait encore le nom issu
 * de l'ancienne colonne « creaneau_id » (faute de frappe corrigée depuis).
 * Aucune donnée touchée ; permet à doctrine:schema:validate de passer.
 */
final class Version20261017100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'rendez_vous : renomme l\'index de creneau_id (reste de creaneau_id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER INDEX idx_65e8aa0ab8bbcb9e RENAME TO IDX_65E8AA0A7D0729A9');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX IDX_65E8AA0A7D0729A9 RENAME TO idx_65e8aa0ab8bbcb9e');
    }
}
