<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261026120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Étape 8 : capacité et durée des créneaux, réglages du service (jours fermés), procédure d\'un rendez-vous';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creneau ADD capacite INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE creneau ADD duree_minutes INT DEFAULT 30 NOT NULL');
        $this->addSql('ALTER TABLE rendez_vous ADD procedure_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE rendez_vous ADD CONSTRAINT FK_65E8AA0A1624BCD2 FOREIGN KEY (procedure_id) REFERENCES procedure (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_65E8AA0A1624BCD2 ON rendez_vous (procedure_id)');
        $this->addSql('ALTER TABLE service ADD duree_creneau_minutes INT DEFAULT 30 NOT NULL');
        $this->addSql('ALTER TABLE service ADD capacite_creneau INT DEFAULT 1 NOT NULL');
        $this->addSql("ALTER TABLE service ADD jours_fermes JSON DEFAULT '[6,7]' NOT NULL");
        // Les valeurs par défaut ne servaient qu'aux lignes déjà présentes : le mapping Doctrine n'en déclare pas.
        $this->addSql('ALTER TABLE creneau ALTER capacite DROP DEFAULT');
        $this->addSql('ALTER TABLE creneau ALTER duree_minutes DROP DEFAULT');
        $this->addSql('ALTER TABLE service ALTER duree_creneau_minutes DROP DEFAULT');
        $this->addSql('ALTER TABLE service ALTER capacite_creneau DROP DEFAULT');
        $this->addSql('ALTER TABLE service ALTER jours_fermes DROP DEFAULT');
        // Avant : réserver un créneau le fermait (disponible = false). Désormais « disponible » = ouvert par l'agent,
        // et les places restantes se calculent avec la capacité : on rouvre les créneaux fermés par une réservation.
        $this->addSql("UPDATE creneau SET disponible = TRUE WHERE id IN (SELECT creneau_id FROM rendez_vous WHERE statut IN ('en_attente', 'confirme'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE creneau SET disponible = FALSE WHERE id IN (SELECT creneau_id FROM rendez_vous WHERE statut IN ('en_attente', 'confirme'))");
        $this->addSql('ALTER TABLE creneau DROP capacite');
        $this->addSql('ALTER TABLE creneau DROP duree_minutes');
        $this->addSql('ALTER TABLE rendez_vous DROP CONSTRAINT FK_65E8AA0A1624BCD2');
        $this->addSql('DROP INDEX IDX_65E8AA0A1624BCD2');
        $this->addSql('ALTER TABLE rendez_vous DROP procedure_id');
        $this->addSql('ALTER TABLE service DROP duree_creneau_minutes');
        $this->addSql('ALTER TABLE service DROP capacite_creneau');
        $this->addSql('ALTER TABLE service DROP jours_fermes');
    }
}
