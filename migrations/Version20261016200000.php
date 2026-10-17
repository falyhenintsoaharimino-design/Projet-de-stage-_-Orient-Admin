<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Avis : date de création + un seul avis par rendez-vous (index unique).
 * La table avis est vide à ce stade (aucun endpoint ne l'alimentait).
 */
final class Version20261016200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Avis : date_creation et unicité par rendez-vous';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE avis ADD date_creation TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('DROP INDEX IDX_8F91ABF091EF7EAA');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8F91ABF091EF7EAA ON avis (rendez_vous_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8F91ABF091EF7EAA');
        $this->addSql('CREATE INDEX IDX_8F91ABF091EF7EAA ON avis (rendez_vous_id)');
        $this->addSql('ALTER TABLE avis DROP date_creation');
    }
}
