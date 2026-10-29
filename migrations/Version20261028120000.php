<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Étape 10a : table des paramètres de l'application (EF-46).
 */
final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Étape 10a : paramètres de l\'application (modèles de messages, jours fériés, durée par défaut)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE parametre (cle VARCHAR(60) NOT NULL, valeur JSON NOT NULL, modifie_le TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, modifie_par_id INT DEFAULT NULL, PRIMARY KEY (cle))');
        $this->addSql('CREATE INDEX IDX_ACC79041553B2554 ON parametre (modifie_par_id)');
        $this->addSql('ALTER TABLE parametre ADD CONSTRAINT FK_ACC79041553B2554 FOREIGN KEY (modifie_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parametre DROP CONSTRAINT FK_ACC79041553B2554');
        $this->addSql('DROP TABLE parametre');
    }
}
