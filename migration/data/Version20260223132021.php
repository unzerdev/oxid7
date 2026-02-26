<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\LoggerInterface;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260223132021 extends AbstractMigration
{
    /** @throws Exception */
    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        parent::__construct($connection, $logger);

        $this->platform->registerDoctrineTypeMapping('enum', 'string');
    }

    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->updateOxOrderTable($schema);
    }

    public function down(Schema $schema): void
    {
    }

    protected function updateOxOrderTable(Schema $schema): void
    {
        $oxorder = $schema->getTable('oxorder');
        if (!$oxorder->hasColumn('OXUNZERPAYORDERNR')) {
            $oxorder->addColumn('OXUNZERPAYORDERNR', Types::INTEGER, ['columnDefinition' => 'varchar(50)', 'default' => 0, 'comment' => 'Unzer OrderNr']);
        }
    }
}
