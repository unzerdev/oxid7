<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Service;

use OxidEsales\Eshop\Core\DatabaseProvider;

class PreOrderMigration
{
    public static function migrate(): void
    {
        $db = DatabaseProvider::getDb();
        if (!$db->getOne("SHOW COLUMNS FROM oxorder LIKE 'OXUNZERPAYORDERNR'")) {
            $db->execute("ALTER TABLE oxorder ADD OXUNZERPAYORDERNR VARCHAR(64) NOT NULL DEFAULT ''");
        }
        $db->execute(file_get_contents(__DIR__ . '/../../migration/preorder.sql'));
    }
}
