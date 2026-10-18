<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gestion admin des utilisateurs : un compte peut être désactivé.
 */
final class Version20261018100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'utilisateur : colonne actif (par défaut vrai)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur ADD actif BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur DROP actif');
    }
}
