<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Étape 6 : profil (téléphone, langue, canal de notification) et consentement
 * à l'inscription (date enregistrée) sur la table utilisateur.
 */
final class Version20261021120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'utilisateur : telephone, langue, canal_notification, consentement_le';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur ADD telephone VARCHAR(30) DEFAULT NULL');
        $this->addSql("ALTER TABLE utilisateur ADD langue VARCHAR(5) DEFAULT 'fr' NOT NULL");
        $this->addSql("ALTER TABLE utilisateur ADD canal_notification VARCHAR(20) DEFAULT 'email' NOT NULL");
        $this->addSql('ALTER TABLE utilisateur ADD consentement_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur DROP telephone');
        $this->addSql('ALTER TABLE utilisateur DROP langue');
        $this->addSql('ALTER TABLE utilisateur DROP canal_notification');
        $this->addSql('ALTER TABLE utilisateur DROP consentement_le');
    }
}
