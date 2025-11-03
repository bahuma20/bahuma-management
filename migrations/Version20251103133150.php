<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251103133150 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE contract_item_pricing (id INT AUTO_INCREMENT NOT NULL, contract_item_id INT NOT NULL, start DATE NOT NULL, end DATE DEFAULT NULL, period VARCHAR(255) NOT NULL, price DOUBLE PRECISION NOT NULL, INDEX IDX_B3B9FB31A3E2F96 (contract_item_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE contract_item_pricing ADD CONSTRAINT FK_B3B9FB31A3E2F96 FOREIGN KEY (contract_item_id) REFERENCES contract_item (id)');
        $this->addSql('ALTER TABLE contract_item DROP start, DROP end, DROP price, DROP period');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contract_item_pricing DROP FOREIGN KEY FK_B3B9FB31A3E2F96');
        $this->addSql('DROP TABLE contract_item_pricing');
        $this->addSql('ALTER TABLE contract_item ADD start DATE NOT NULL, ADD end DATE DEFAULT NULL, ADD price DOUBLE PRECISION NOT NULL, ADD period VARCHAR(255) NOT NULL');
    }
}
