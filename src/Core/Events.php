<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Core;

use OxidEsales\Eshop\Application\Model\Country;
use OxidEsales\Eshop\Application\Model\Shop;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Core\DatabaseProvider;
use Unzer\UnzerPayment\Constants\Constants;

/**
 * Eventhandler for module activation and deactivation.
 */
class Events
{
    /**
     * Execute action on activate event.
     *
     * @return void
     */
    public static function onActivate()
    {
        self::addDatabaseStructure();
        self::addData();
        self::updateData();
        self::checkColumns();
        self::regenerateViews();
        self::clearTmp();
    }

    /**
     * Execute action on deactivate event.
     *
     * @return void
     */
    public static function onDeactivate()
    {
        self::deactivePaymethods();
        self::clearTmp();
    }

    /**
     * Regenerates database view-tables.
     *
     * @return void
     */
    public static function regenerateViews()
    {
        $oShop = oxNew(Shop::class);
        $oShop->generateViews();
    }

    /**
     * Clear tmp dir and smarty cache.
     *
     * @return void
     */
    public static function clearTmp()
    {
        $sTmpDir = getShopBasePath() . "/tmp/";
        $sTwigDir = $sTmpDir . "template_cache/";

        foreach (glob($sTmpDir . "*.txt") as $sFileName) {
            unlink($sFileName);
        }
        foreach (glob($sTwigDir . "*.php") as $sFileName) {
            unlink($sFileName);
        }
    }

    /**
     * Adding data to the database.
     *
     * @return void
     */
    public static function addData()
    {
        foreach (Constants::PAYMENT_METHODS as $sPaymentOxid => $sPaymentDataArray) {
            include( __DIR__ . '/../../translations/de/unzerpayment_lang.php');
            $sPaymentName = $aLang['UNZER_PAYMENT_METHODNAME_' . strtoupper($sPaymentOxid)];
            include( __DIR__ . '/../../translations/en/unzerpayment_lang.php');
            $sPaymentName_1 = $aLang['UNZER_PAYMENT_METHODNAME_' . strtoupper($sPaymentOxid)];
            $sPaymentOxid = Constants::PAYMENT_METHOD_PREFIX . $sPaymentOxid;
            // INSERT PAYMENT METHOD
            self::insertRowIfNotExists('oxpayments', ['OXID' => $sPaymentOxid], "INSERT INTO oxpayments (OXID, OXACTIVE, OXDESC, OXADDSUM, OXADDSUMTYPE, OXFROMBONI, OXFROMAMOUNT, OXTOAMOUNT, OXVALDESC, OXCHECKED, OXDESC_1, OXVALDESC_1, OXDESC_2, OXVALDESC_2, OXDESC_3, OXVALDESC_3, OXLONGDESC, OXLONGDESC_1, OXLONGDESC_2, OXLONGDESC_3, OXSORT) VALUES ('{$sPaymentOxid}', 0, '{$sPaymentName}', 0, 'abs', 0, 0, 999999, '', 1, '{$sPaymentName_1}', '', '', '', '', '', '', '', '', '', 0)");
            self::insertRowIfNotExists('oxobject2payment', ['OXPAYMENTID' => $sPaymentOxid, 'OXTYPE' => 'oxdelset'], "INSERT INTO oxobject2payment(OXID,OXPAYMENTID,OXOBJECTID,OXTYPE) values (MD5(CONCAT(NOW(),RAND())), '{$sPaymentOxid}', 'oxidstandard', 'oxdelset');");

            // INSERT COUNTRY RESTRICTIONS
            $paymentMethodClassName = '\Unzer\UnzerPayment\PaymentMethods\\' . $sPaymentDataArray['class_name'];
            $paymentObject = new $paymentMethodClassName();
            if (sizeof($paymentObject->getAllowedCountries()) > 0) {
                foreach ($paymentObject->getAllowedCountries() as $countryIsoCode) {
                    /** @var Country::class $oCountry */
                    $oCountry = oxNew(Country::class);
                    $oCountryId = $oCountry->getIdByCode($countryIsoCode);
                    self::insertRowIfNotExists('oxobject2payment', ['OXPAYMENTID' => $sPaymentOxid, 'OXOBJECTID' => $oCountryId, 'OXTYPE' => 'oxcountry'], "INSERT INTO oxobject2payment(OXID,OXPAYMENTID,OXOBJECTID,OXTYPE) values (MD5(CONCAT(NOW(),RAND())), '{$sPaymentOxid}', '{$oCountryId}', 'oxcountry');");
                }
            }
        }
    }

    /**
     * Add or change missing columns
     * Cumulated changes from the update-scripts from previous versions
     *
     * @return void
     */
    public static function checkColumns()
    {
    }

    /**
     * Creating database structure changes.
     *
     * @return void
     */
    public static function addDatabaseStructure()
    {
    }

    /**
     * Add a database table.
     *
     * @param string $sTableName table to add
     * @param string $sQuery     sql-query to add table
     *
     * @return boolean true or false
     */
    public static function addTableIfNotExists($sTableName, $sQuery)
    {
        $aTables = DatabaseProvider::getDb()->getAll("SHOW TABLES LIKE '{$sTableName}'");
        if (!$aTables || count($aTables) == 0) {
            DatabaseProvider::getDb()->Execute($sQuery);
            return true;
        }
        return false;
    }

    /**
     * Drop DB-table if it exists
     *
     * @param string $sTableName
     * @return void
     */
    public static function dropTable($sTableName)
    {
        DatabaseProvider::getDb()->Execute("DROP TABLE IF EXISTS `{$sTableName}`;");
    }

    /**
     * Check database if column exists
     *
     * @param string $sTableName
     * @param string $sColumnName
     * @return bool
     */
    public static function checkIfColumnExists($sTableName, $sColumnName)
    {
        $aColumns = DatabaseProvider::getDb()->getAll("SHOW COLUMNS FROM {$sTableName} LIKE '{$sColumnName}'");
        if (!$aColumns || count($aColumns) == 0) {
            return false;
        }
        return true;
    }

    /**
     * Add a column to a database table.
     *
     * @param string $sTableName  table name
     * @param string $sColumnName column name
     * @param string $sQuery      sql-query to add column to table
     *
     * @return boolean true or false
     */
    public static function addColumnIfNotExists($sTableName, $sColumnName, $sQuery)
    {
        if (self::checkIfColumnExists($sTableName, $sColumnName) === false) {
            try {
                DatabaseProvider::getDb()->Execute($sQuery);
            } catch (\Exception $e) {
            }
            return true;
        }
        return false;
    }

    /**
     * Change a column of a database table.
     *
     * @param string $sTableName  table name
     * @param string $sColumnName column name
     * @param string $sQuery      sql-query to change column
     *
     * @return boolean true or false
     */
    public static function changeColumnIfExists($sTableName, $sColumnName, $sQuery)
    {
        if (self::checkIfColumnExists($sTableName, $sColumnName) === true) {
            try {
                DatabaseProvider::getDb()->Execute($sQuery);
            } catch (\Exception $e) {
            }
            return true;
        }
        return false;
    }

    /**
     * Drop column if exists
     *
     * @param string $sTableName
     * @param string $sColumnName
     * @return bool
     */
    public static function dropColumnIfExists($sTableName, $sColumnName)
    {
        if (self::checkIfColumnExists($sTableName, $sColumnName) === true) {
            try {
                DatabaseProvider::getDb()->Execute("ALTER TABLE `{$sTableName}` DROP `{$sColumnName}`;");
            } catch (\Exception $e) {
            }
            return true;
        }
        return false;
    }

    /**
     * Check charset of a given column and change it if needed
     *
     * @param string $sTableName
     * @param string $sColumnName
     * @param string $sNeededCharset
     * @param string $sQuery
     * @return void
     */
    public static function changeCharsetIfNeeded($sTableName, $sColumnName, $sNeededCharset, $sQuery)
    {
        $sCheckQuery = 'SELECT character_set_name FROM information_schema.`COLUMNS` 
                        WHERE table_schema = "' . Registry::getConfig()->getConfigParam('dbName') . '"
                          AND table_name = "' . $sTableName . '"
                          AND column_name = "' . $sColumnName . '";';
        $sCurrentCharset = DatabaseProvider::getDb()->getOne($sCheckQuery);
        if ($sCurrentCharset != $sNeededCharset) {
            DatabaseProvider::getDb()->Execute($sQuery);
        }
    }

    /**
     * Insert a database row to an existing table.
     *
     * @param string $sTableName database table name
     * @param array  $aKeyValue  keys of rows to add for existance check
     * @param string $sQuery     sql-query to insert data
     *
     * @return boolean true or false
     */
    public static function insertRowIfNotExists($sTableName, $aKeyValue, $sQuery)
    {
        $oDb = DatabaseProvider::getDb();

        $sWhere = '';
        foreach ($aKeyValue as $key => $value) {
            $sWhere .= " AND $key = '$value'";
        }

        $sCheckQuery = "SELECT * FROM {$sTableName} WHERE 1" . $sWhere;
        $mResult = $oDb->getOne($sCheckQuery);

        if ($mResult !== false) return false;
        $oDb->execute($sQuery);

        return true;
    }

    /**
     * Deactivates payone paymethods on module deactivation.
     *
     * @return void
     */
    public static function deactivePaymethods()
    {
        $toDeactivateMethods = array_keys(Constants::PAYMENT_METHODS);
        foreach ($toDeactivateMethods as &$sMethod) {
            $sMethod = Constants::PAYMENT_METHOD_PREFIX . $sMethod;
        }
        $sPaymenthodIds = "'" . implode("','", $toDeactivateMethods) . "'";
        $sQ = "update oxpayments set oxactive = 0 where oxid in ($sPaymenthodIds)";
        DatabaseProvider::getDB()->Execute($sQ);
    }

    /**
     * Update data
     */
    public static function updateData()
    {
    }

    /**
     * Insert a database row to an existing table.
     *
     * @param string $sTableName  database table name
     * @param array  $aKeyValue   keys of rows to change
     * @param string $sColumnName the column name to change, used also to existence check
     * @param string $sValue
     *
     * @param        $aCriteria
     * @return bool
     */
    public static function updateDataIfExists($sTableName, $aKeyValue, $sColumnName, $sValue, $aCriteria)
    {
        if (!self::checkIfColumnExists($sTableName, $sColumnName)) {
            return false;
        }

        $sWhere = '';
        foreach ($aKeyValue as $key => $value) {
            $sWhere .= " AND $key = '$value'";
        }
        foreach ($aCriteria as $key => $value) {
            $sWhere .= " AND $key = '$value'";
        }
        $sQ = "UPDATE {$sTableName} SET {$sColumnName} = '{$sValue}' WHERE 1" . $sWhere;
        try {
            DatabaseProvider::getDB()->Execute($sQ);
        } catch (\Exception $oEx) {
            return false;
        }

        return true;
    }
}
