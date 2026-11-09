<?php

namespace Stanford\LectureEvaluation;

use REDCap;

/**
 * The only place this module calls REDCap::getData.
 *
 * In array format getData fills in every requested field for every record-event it returns, so a
 * read is bounded only when it limits BOTH the records (`records` or `filterLogic`) and the
 * fields. From 2020 (0952eedb) until this change the module read whole events with every field on each student request
 * (330-440 MB, ~13 s per request on prod; see docs/2026-11-06-bounded-reads.md).
 *
 * get() refuses a read without an explicit field list, and refuses a read without a record
 * restriction unless the caller passes $allRecords = true. Only admin pages and the record-id lookup
 * for new E<n> ids do that, and the docs list each one.
 *
 * lookup() is how a request finds records by value (a student by hash, a student's evaluations, a
 * lecture's evaluations, the lectures of a cohort). It does NOT use getData's filterLogic: in
 * REDCap 17.5 the filter step itself calls getData with returnEmptyEvents for every record in the
 * project (all ~34k lectures, students and evaluations) before the logic is applied, even when the
 * logic is event-prefixed. On the prod-sized fixture that cost 55 MB and ~300 ms per lookup.
 * Instead, one parameterized query on the project's data table returns the matching record names,
 * and getData reads just those records and fields.
 */
final class Data
{
    /** @var array[] label, records returned, milliseconds - per call, for diagnostics */
    private static $calls = array();

    /**
     * @param array $params REDCap::getData named parameters (return_format is forced to 'array')
     * @param string $label short name for diagnostics
     * @param bool $allRecords true only for an intentionally project-wide read (admin pages)
     * @return array
     */
    public static function get(array $params, $label, $allRecords = false)
    {
        if (empty($params['fields']) || !is_array($params['fields'])) {
            throw new \LogicException("unbounded getData ($label): no field list");
        }
        if (!$allRecords && empty($params['records']) && empty($params['filterLogic'])) {
            throw new \LogicException("unbounded getData ($label): no records or filterLogic");
        }
        $params['return_format'] = 'array';
        $start = microtime(true);
        $result = REDCap::getData($params);
        $result = is_array($result) ? $result : array();
        self::$calls[] = array($label, count($result), (int)round((microtime(true) - $start) * 1000));
        return $result;
    }

    /**
     * Records with $field equal to one of $values in one event, read with $fields only.
     *
     * The SQL match is a superset (MySQL's collation ignores case); callers re-check the value in
     * PHP with a strict comparison, as the module always has.
     *
     * @param int $eventId
     * @param string $field
     * @param string[] $values validated by the caller
     * @param string[] $fields
     * @param string $label short name for diagnostics
     * @return array getData array format
     */
    public static function lookup($eventId, $field, array $values, array $fields, $label)
    {
        $values = array_values(array_unique(array_map('strval', $values)));
        if (empty($values) || !defined('PROJECT_ID')) {
            return array();
        }
        $table = self::dataTable($label);
        $start = microtime(true);
        $sql = "SELECT DISTINCT record FROM `$table` WHERE project_id = ? AND event_id = ? AND field_name = ? AND value IN ("
            . implode(',', array_fill(0, count($values), '?')) . ")";
        $result = \ExternalModules\ExternalModules::query($sql,
            array_merge(array((int)PROJECT_ID, (int)$eventId, (string)$field), $values));
        $records = array();
        while ($row = $result->fetch_assoc()) {
            $records[] = (string)$row['record'];
        }
        self::$calls[] = array($label . ':sql', count($records), (int)round((microtime(true) - $start) * 1000));
        if (empty($records)) {
            // never fall through to getData: an empty `records` list means every record
            return array();
        }
        return self::get(array(
            'records' => $records,
            'events' => array((int)$eventId),
            'fields' => $fields,
        ), $label);
    }

    /**
     * Names of the records that have a value for $field in one event, as a set (name => true).
     * Used to pick the next free E<n> id: a set of ~31k short strings instead of getData's nested
     * arrays for every evaluation (~30 MB on the prod-sized fixture).
     *
     * @param int $eventId
     * @param string $field
     * @param string $label short name for diagnostics
     * @return array record name => true
     */
    public static function recordNames($eventId, $field, $label)
    {
        if (!defined('PROJECT_ID')) {
            return array();
        }
        $table = self::dataTable($label);
        $start = microtime(true);
        $result = \ExternalModules\ExternalModules::query(
            "SELECT DISTINCT record FROM `$table` WHERE project_id = ? AND event_id = ? AND field_name = ?",
            array((int)PROJECT_ID, (int)$eventId, (string)$field));
        $names = array();
        while ($row = $result->fetch_assoc()) {
            $names[(string)$row['record']] = true;
        }
        self::$calls[] = array($label . ':sql', count($names), (int)round((microtime(true) - $start) * 1000));
        return $names;
    }

    private static function dataTable($label)
    {
        $table = REDCap::getDataTable(PROJECT_ID);
        if (!is_string($table) || !preg_match('/^redcap_data[0-9]*$/', $table)) {
            throw new \LogicException("unexpected data table ($label)");
        }
        return $table;
    }

    /**
     * @return array[] one [label, records returned, ms] entry per getData call in this request
     */
    public static function calls()
    {
        return self::$calls;
    }
}
