<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015230500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Optional persistent Unzer preliminary orders and completion/mail markers';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('unzer_preorders')) {
            $this->addSql(file_get_contents(__DIR__ . '/../preorder.sql'));
        }
        if (!$schema->getTable('oxorder')->hasColumn('OXUNZERPAYORDERNR')) {
            $this->addSql("ALTER TABLE oxorder ADD OXUNZERPAYORDERNR VARCHAR(64) NOT NULL DEFAULT ''");
        }
    }

    public function down(Schema $schema): void
    {
        // Intentionally retain payment links and idempotency records, even after rollback.
    }
}
