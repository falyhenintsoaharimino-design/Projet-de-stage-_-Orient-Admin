<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Journal d'audit : ajoute le détail de l'action (texte libre, facultatif)
 * et un index sur la date (consultation triée / filtrée par période).
 */
final class Version20261017200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'journal : colonne details + index sur la date';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE journal ADD details TEXT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_journal_date ON journal (date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_journal_date');
        $this->addSql('ALTER TABLE journal DROP details');
    }
}
