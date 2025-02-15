<?php

/**
 * UuidRegistry class
 *
 *    Generic support for UUID creation and use. Goal is to support:
 *     1. uuid for fhir resources
 *     2. uuid for future offsite support (using Timestamp-first COMB Codec for uuid,
 *        so can use for primary keys)
 *     3. uuid for couchdb docid
 *     4. other future use cases.
 *    The construct accepts an associative array in order to allow easy addition of
 *    fields and new sql columns in the uuid_registry table.
 *
 *    When add support for a new table uuid, need to add it to the populateAllMissingUuids function.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Gerhard Brink <gjdbrink@gmail.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019 Gerhard Brink <gjdbrink@gmail.com>
 * @copyright Copyright (c) 2020 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Common\Uuid;

use Exception;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use Ramsey\Uuid\Codec\TimestampFirstCombCodec;
use Ramsey\Uuid\Generator\CombGenerator;
use Ramsey\Uuid\UuidFactory;
use Ramsey\Uuid\Uuid;

class UuidRegistry
{

    const UUID_MAX_BATCH_COUNT = 1000;

    private $table_name;      // table to check if uuid has already been used in
    private $table_id;        // the label of the column in above table that is used for id (defaults to 'id')
    private $table_vertical;  // false or array. if table is vertical, will store the critical columns (uuid set for matching columns)
    private $disable_tracker; // disable check and storage of uuid in the main uuid_registry table
    private $couchdb;         // blank or string (documents or ccda label for now, which represents the tables that hold the doc ids).
    private $document_drive;  // set to true if this is for labeling a document saved to drive
    private $mapped;          // set to true if this was mapped in uuid_mapping table

    public function __construct($associations = [])
    {
        $this->table_name = $associations['table_name'] ?? '';
        if (!empty($this->table_name)) {
            $this->table_id = $associations['table_id'] ?? 'id';
        } else {
            $this->table_id = '';
        }
        $this->table_vertical = $associations['table_vertical'] ?? false;
        $this->disable_tracker = $associations['disable_tracker'] ?? false;
        $this->couchdb = $associations['couchdb'] ?? '';
        if (!empty($associations['document_drive']) && $associations['document_drive'] === true) {
            $this->document_drive = 1;
        } else {
            $this->document_drive = 0;
        }
        if (!empty($associations['mapped']) && $associations['mapped'] === true) {
            $this->mapped = 1;
        } else {
            $this->mapped = 0;
        }
    }

    /**
     * @return string
     */
    public function createUuid()
    {
        // Create uuid using the Timestamp-first COMB Codec, so can use for primary keys
        //  (since first part is timestamp, it is naturally ordered; the rest is from uuid4, so is random)
        //  reference:
        //    https://uuid.ramsey.dev/en/latest/customize/timestamp-first-comb-codec.html#customize-timestamp-first-comb-codec
        $uuid = $this->getUnusedUuidBatch(1)[0];

        // Insert the uuid into uuid_registry (unless $this->disable_tracker is set to true)
        if (!$this->disable_tracker) {
            $this->insertUuidsIntoRegistry([$uuid]);
        }

        // Return the uuid
        return $uuid;
    }

    // Generic function to update all missing uuids (to be primarily used in service that is run intermittently; should not use anywhere else)
    // When add support for a new table uuid, need to add it here
    //  Will log by default
    public static function populateAllMissingUuids($log = true)
    {
        $logEntryComment = '';

        // Update for tables (alphabetically ordered):
        //  ccda
        //  drugs (with custom id drug_id)
        //  facility
        //  facility_user_ids (vertical table)
        //  form_encounter
        //  immunizations
        //  insurance_companies
        //  insurance_data
        //  lists
        //  patient_data
        //  prescriptions
        //  procedure_order (with custom id procedure_order_id)
        //  procedure_result (with custom id procedure_result_id)
        //  users
        self::appendPopulateLog('ccda', (new UuidRegistry(['table_name' => 'ccda']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('documents', (new UuidRegistry(['table_name' => 'documents']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('drugs', (new UuidRegistry(['table_name' => 'drugs', 'table_id' => 'drug_id']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('facility', (new UuidRegistry(['table_name' => 'facility']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('facility_user_ids', (new UuidRegistry(['table_name' => 'facility_user_ids', 'table_vertical' => ['uid', 'facility_id']]))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('form_clinical_notes', (new UuidRegistry(['table_name' => 'form_clinical_notes']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('form_encounter', (new UuidRegistry(['table_name' => 'form_encounter']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('form_vitals', (new UuidRegistry(['table_name' => 'form_vitals']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('history_data', (new UuidRegistry(['table_name' => 'history_data']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('immunizations', (new UuidRegistry(['table_name' => 'immunizations']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('insurance_companies', (new UuidRegistry(['table_name' => 'insurance_companies']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('insurance_data', (new UuidRegistry(['table_name' => 'insurance_data']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('lists', (new UuidRegistry(['table_name' => 'lists']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('patient_data', (new UuidRegistry(['table_name' => 'patient_data']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('prescriptions', (new UuidRegistry(['table_name' => 'prescriptions']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('procedure_order', (new UuidRegistry(['table_name' => 'procedure_order', 'table_id' => 'procedure_order_id']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('procedure_result', (new UuidRegistry(['table_name' => 'procedure_result', 'table_id' => 'procedure_result_id']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('users', (new UuidRegistry(['table_name' => 'users']))->createMissingUuids(), $logEntryComment);

        // log it
        if ($log && !empty($logEntryComment)) {
            $logEntryComment = rtrim($logEntryComment, ', ');
            EventAuditLogger::instance()->newEvent('uuid', '', '', 1, 'Automatic uuid service creation: ' . $logEntryComment);
        }
    }

    // Helper function for above populateAllMissingUuids function
    private static function appendPopulateLog($table, $count, &$logEntry)
    {
        if ($count > 0) {
            $logEntry .= 'added ' . $count . ' uuids to ' . $table . ', ';
        }
    }

    public function createMissingUuids()
    {
        try {
            sqlBeginTrans();
            $counter = 0;

            // we split the loop so we aren't doing a condition inside each one.
            if ($this->table_vertical) {
                do {
                    $count = $this->createMissingUuidsForVerticalTable();
                    $counter += $count;
                } while ($count > 0);
                do {
                    $count = $this->completePartialMissingUuidsForVerticalTable();
                    $counter += $count;
                } while ($count > 0);
            } else {
                do {
                    $count = $this->createMissingUuidsForTableWithId();
                    $counter += $count;
                } while ($count > 0);
            }
            sqlCommitTrans();
            return $counter;
        } catch (Exception $exception) {
            sqlRollbackTrans();
            throw $exception;
        }
    }


    // Generic function to see if there are missing uuids in a sql table (table needs an `id` column to work)
    public function tableNeedsUuidCreation()
    {
        // Empty should be NULL, but to be safe also checking for empty and null bytes
        $resultSet = sqlQueryNoLog("SELECT count(`" . $this->table_id . "`) as `total` FROM `" . $this->table_name . "` WHERE `uuid` IS NULL OR `uuid` = '' OR `uuid` = '\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0'");
        if ($resultSet['total'] > 0) {
            return true;
        }
        return false;
    }

    /**
     * Converts a UUID byte value to a string representation
     * @return the UUID string value
     */
    public static function uuidToString($uuidBytes)
    {
        return Uuid::fromBytes($uuidBytes)->toString();
    }

    /**
     * Converts a UUID string to a bytes representation
     * @return the UUID bytes value
     */
    public static function uuidToBytes($uuidString)
    {
        return Uuid::fromString($uuidString)->getBytes();
    }

    /**
     * Check if UUID String is Valid
     * @return boolean
     */
    public static function isValidStringUUID($uuidString)
    {
        return (Uuid::isValid($uuidString));
    }

    /**
     * Check if UUID Brinary is Empty
     * @return boolean
     */
    public static function isEmptyBinaryUUID($uuidString)
    {
        return (empty($uuidString) || ($uuidString == '\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0'));
    }

    /**
     * Returns a batch of generated uuids that do NOT exist in the system.  The number of records generated is determined
     * by the limit.
     * @param int $limit
     * @return array
     */
    private function getUnusedUuidBatch($limit = 10)
    {
        if ($limit <= 0) {
            return [];
        }
        $uuids = $this->getUUIDBatch($limit);
        $dbUUIDs = [];

        if (!$this->disable_tracker) {
            $sqlColumns = array_map(function ($u) {
                return '`uuid` = ?';
            }, $uuids);
            $sqlWhere = implode(" OR ", $sqlColumns);
            $dbUUIDs = QueryUtils::fetchRecordsNoLog("SELECT `uuid` FROM `uuid_registry` WHERE " . $sqlWhere, $uuids);
        }
        if (empty($dbUUIDs)) {
            if (!empty($this->table_name)) {
                $sqlColumns = array_map(function ($u) {
                    return '`uuid` = ?';
                }, $uuids);
                $sqlWhere = implode(" OR ", $sqlColumns);
                // If using $this->table_name, then ensure uuid is unique in that table
                $dbUUIDs =  QueryUtils::fetchRecordsNoLog("SELECT `uuid` FROM `" . $this->table_name . "` WHERE " . $sqlWhere, $uuids);
            } elseif ($this->document_drive === 1) {
                $sqlColumns = array_map(function ($u) {
                    return '`drive_uuid` = ?';
                }, $uuids);
                $sqlWhere = implode(" OR ", $sqlColumns);
                // If using for document labeling on drive, then ensure drive_uuid is unique in documents table
                $dbUUIDs = QueryUtils::fetchRecordsNoLog("SELECT `drive_uuid` as `uuid` FROM `documents` WHERE " . $sqlWhere, $uuids);
            }
        }

        $count = count($dbUUIDs);

        if ($count <= 0) {
            return $uuids;
        }

        // generate some new uuids since we had duplicates... which should never happen... but we have this here in
        // case we do
        error_log("OpenEMR Warning: There was a collision when creating a unique UUID. Will try again.");
        return $this->getUnusedUuidBatch($limit);
    }

    private function createMissingUuidsForTableWithId()
    {
        $counter = 0;
        $count = $this->getTableCountWithMissingUuids();
        if ($count > 0) {
            // loop through in batches of 1000
            // generate min(1000, $count)
            // generate bulk insert statement
            $gen_count = min($count, self::UUID_MAX_BATCH_COUNT);
            $batchUUids = $this->getUnusedUuidBatch($gen_count);
            $ids = QueryUtils::fetchRecords("SELECT " . $this->table_id . " FROM `" . $this->table_name . "` WHERE `uuid` IS NULL OR `uuid` = '' OR `uuid` = '\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0' LIMIT " . $gen_count);
            $this->insertUuidsIntoRegistry($batchUUids);
            for ($i = 0; $i < $gen_count; $i++) {
                // do single updates
                sqlStatementNoLog("UPDATE `" . $this->table_name . "` SET `uuid` = ? WHERE `" . $this->table_id . "` = ?", [$batchUUids[$i], $ids[$i][$this->table_id]], true);
                $counter++;
            }
        }
        return $counter;
    }

    private function getTableCountWithMissingUuids()
    {
        $result = QueryUtils::fetchRecordsNoLog("SELECT count(*) AS cnt FROM `" . $this->table_name . "` WHERE `uuid` IS NULL OR `uuid` = '' OR `uuid` = '\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0'", []);
        // loop through batches of 1000
        $count = $result[0]['cnt'];
        return $count;
    }

    /**
     * Participates in populating missing uuids for a group in vertical table. It will use a
     *  key or composite key to represent the group of entries. Note there are 2 scenarios
     *  (this function deals with scenario 1):
     *   1. The entire group is missing a uuid (this function)
     *   2. The group has a uuid, however, one or more items in the group have not been
     *      assigned this group uuid. (see completePartialMissingUuidsForVerticalTable() function)
     * @return int
     */
    private function createMissingUuidsForVerticalTable()
    {
        $counter = 0;

        // Collect groups that are missing a uuid
        $columns = array_map(function ($col) {
            return "`$col`";
        }, $this->table_vertical);
        $columnsQtwo = array_map(function ($col) {
            return "`q2`.`$col`";
        }, $this->table_vertical);
        $columnsOn = array_map(function ($col) {
            return "`q1`.`$col` = `q2`.`$col`";
        }, $this->table_vertical);
        $columnsWhere = array_map(function ($col) {
            return "(`q1`.`$col` IS NULL OR `q1`.`$col` = '')";
        }, $this->table_vertical);
        $query = "SELECT " . implode(",", $columnsQtwo) . "
        FROM
          (SELECT " . implode(",", $columns) . "
          FROM `" . $this->table_name . "`
          WHERE `uuid` IS NOT NULL AND `uuid` != '' AND `uuid` != '\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0'
          GROUP BY " . implode(",", $columns) . ") AS `q1`
        RIGHT OUTER JOIN `" . $this->table_name . "` as `q2`
        ON " . implode(" AND ", $columnsOn) . "
        WHERE " . implode(" AND ", $columnsWhere) . "
        GROUP BY " . implode(",", $columnsQtwo) . "
        LIMIT " . self::UUID_MAX_BATCH_COUNT;
        $groupsWithoutUuid = sqlStatementNoLog($query, false, true);
        $number = sqlNumRows($groupsWithoutUuid);

        // create uuids and populate the groups with them
        if ($number > 0) {
            $batchUUids = $this->getUnusedUuidBatch($number);
            $this->insertUuidsIntoRegistry($batchUUids);
            $sqlUpdate = "UPDATE `" . $this->table_name . "` SET `uuid` = ? WHERE " .
                implode(" AND ", array_map(function ($col) {
                    return "`$col` = ? ";
                }, $this->table_vertical));
            while ($row = sqlFetchArray($groupsWithoutUuid)) {
                $mappedValues = array_map(function ($col) use ($row) {
                    return $row[$col];
                }, $this->table_vertical);
                $bindValues = array_merge([$batchUUids[$counter]], $mappedValues);
                sqlStatementNoLog($sqlUpdate, $bindValues, true);
                $counter++;
            }
        }
        return $counter;
    }

    /**
     * Participates in populating missing uuids for a group in vertical table. It will use a
     *  key or composite key to represent the group of entries. Note there are 2 scenarios
     *  (this function deals with scenario 2):
     *   1. The entire group is missing a uuid (see createMissingUuidsForVerticalTable() function)
     *   2. The group has a uuid, however, one or more items in the group have not been
     *      assigned this group uuid. (this function)
     * @return int
     */
    private function completePartialMissingUuidsForVerticalTable()
    {
        $counter = 0;

        // Collect groups that are missing a uuid
        $columns = array_map(function ($col) {
            return "`$col`";
        }, $this->table_vertical);
        $columnsQtwo = array_map(function ($col) {
            return "`q2`.`$col`";
        }, array_merge(['uuid'], $this->table_vertical));
        $columnsOn = array_map(function ($col) {
            return "`q1`.`$col` = `q2`.`$col`";
        }, $this->table_vertical);
        $query = "SELECT " . implode(",", $columnsQtwo) . "
        FROM
          (SELECT " . implode(",", $columns) . "
          FROM `" . $this->table_name . "`
          WHERE `uuid` IS NULL OR `uuid` = '' OR `uuid` = '\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0'
          GROUP BY " . implode(",", $columns) . ") AS `q1`
        INNER JOIN `" . $this->table_name . "` as `q2`
        ON " . implode(" AND ", $columnsOn) . "
        WHERE `q2`.`uuid` IS NOT NULL AND `q2`.`uuid` != '' AND `q2`.`uuid` != '\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0'
        GROUP BY " . implode(",", $columnsQtwo) . "
        LIMIT " . self::UUID_MAX_BATCH_COUNT;
        $groupsWithoutUuid = sqlStatementNoLog($query, false, true);
        $number = sqlNumRows($groupsWithoutUuid);

        // populate the groups with the already existent uuids
        if ($number > 0) {
            $sqlUpdate = "UPDATE `" . $this->table_name . "` SET `uuid` = ? WHERE " .
                implode(" AND ", array_map(function ($col) {
                    return "`$col` = ? ";
                }, $this->table_vertical));
            while ($row = sqlFetchArray($groupsWithoutUuid)) {
                $mappedValues = array_map(function ($col) use ($row) {
                    return $row[$col];
                }, array_merge(['uuid'], $this->table_vertical));
                sqlStatementNoLog($sqlUpdate, $mappedValues, true);
                $counter++;
            }
        }
        return $counter;
    }

    /**
     * Given a batch of UUIDs it inserts them into the uuid registry.
     * @param $batchUuids
     */
    private function insertUuidsIntoRegistry($batchUuids)
    {
        $count = count($batchUuids);
        $sql = "INSERT INTO `uuid_registry` (`uuid`, `table_name`, `table_id`, `table_vertical`, `couchdb`, `document_drive`, `mapped`, `created`) VALUES ";
        $columns = [];
        $bind = [];
        $json_vertical = !empty($this->table_vertical) ? json_encode($this->table_vertical) : "";
        for ($i = 0; $i < $count; $i++) {
            $columns[] = "(?, ?, ?, ?, ?, ?, ?, NOW())";
            $bind[] = $batchUuids[$i];
            $bind[] = $this->table_name;
            $bind[] = $this->table_id;
            $bind[] = $json_vertical;
            $bind[] = $this->couchdb;
            $bind[] = $this->document_drive;
            $bind[] = $this->mapped;
        }
        $sql .= implode(",", $columns);
        QueryUtils::sqlStatementThrowException($sql, $bind);
    }


    /**
     * Returns an array of generated unique universal identifiers up to the passed in limit.
     * @param int $limit
     * @return array
     */
    private function getUUIDBatch($limit = 10)
    {
        $uuids = [];
        // Create uuid using the Timestamp-first COMB Codec, so can use for primary keys
        //  (since first part is timestamp, it is naturally ordered; the rest is from uuid4, so is random)
        //  reference:
        //    https://uuid.ramsey.dev/en/latest/customize/timestamp-first-comb-codec.html#customize-timestamp-first-comb-codec
        $factory = new UuidFactory();
        $codec = new TimestampFirstCombCodec($factory->getUuidBuilder());
        $factory->setCodec($codec);
        $factory->setRandomGenerator(new CombGenerator(
            $factory->getRandomGenerator(),
            $factory->getNumberConverter()
        ));
        for ($i = 0; $i < $limit; $i++) {
            $timestampFirstComb = $factory->uuid4();
            $uuids[] = $timestampFirstComb->getBytes();
        }
        return $uuids;
    }
}
