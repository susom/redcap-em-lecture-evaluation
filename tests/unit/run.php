<?php
/**
 * Unit tests for the module's lookup code, with REDCap stubbed out. No Composer, no REDCap:
 *
 *   php tests/unit/run.php
 *
 * The stubs record every REDCap::getData call and every SQL query, so the tests can assert not only
 * what a lookup returns but how much it reads (the point of the 2026-11-06 change).
 */

namespace {
    define('PROJECT_ID', 354);
    define('COMPLETE', 2);

    class REDCap
    {
        /** @var array[] every getData params array, in order */
        public static $getDataCalls = array();
        /** @var callable params => array */
        public static $getDataHandler;

        public static function getData($params)
        {
            self::$getDataCalls[] = $params;
            return call_user_func(self::$getDataHandler, $params);
        }

        public static function getDataTable($pid = null)
        {
            return 'redcap_data2';
        }

        public static function getRecordIdField()
        {
            return 'id';
        }

        public static function getEventNames($unique = false, $label = false, $eventId = null)
        {
            return 'event_' . $eventId;
        }

        public static function getFieldNames($instrument = null)
        {
            return array('id', 'lecture_date', 'instructor', 'topic', 'course', 'lecture_complete');
        }
    }

    $GLOBALS['__hashes'] = array();
    function generateRandomHash($length = 6, $a = false, $b = false, $c = false)
    {
        return array_shift($GLOBALS['__hashes']);
    }
}

namespace ExternalModules {
    class FakeResult
    {
        private $rows;

        public function __construct(array $rows)
        {
            $this->rows = $rows;
        }

        public function fetch_assoc()
        {
            return array_shift($this->rows);
        }
    }

    class ExternalModules
    {
        /** @var array[] [sql, params] for every query */
        public static $queries = array();
        /** @var callable (sql, params) => list of record names */
        public static $queryHandler;

        public static function query($sql, $params = null)
        {
            self::$queries[] = array($sql, $params);
            $rows = array();
            foreach (call_user_func(self::$queryHandler, $sql, $params) as $record) {
                $rows[] = array('record' => $record);
            }
            return new FakeResult($rows);
        }
    }
}

namespace Stanford\LectureEvaluation\Tests {

    use ExternalModules\ExternalModules;
    use REDCap;
    use Stanford\LectureEvaluation\Data;
    use Stanford\LectureEvaluation\Evaluation;
    use Stanford\LectureEvaluation\Lecture;
    use Stanford\LectureEvaluation\Student;
    use Stanford\LectureEvaluation\Validator;

    $root = dirname(__DIR__, 2);
    require_once "$root/Validator.php";
    require_once "$root/Data.php";
    require_once "$root/Student.php";
    require_once "$root/Lecture.php";
    require_once "$root/Evaluation.php";

    $failures = 0;
    $passes = 0;

    function check($cond, $name)
    {
        global $failures, $passes;
        if ($cond) {
            $passes++;
        } else {
            $failures++;
            echo "FAIL  $name\n";
        }
    }

    function reset_stubs($sqlRecords = array(), $getData = null)
    {
        REDCap::$getDataCalls = array();
        ExternalModules::$queries = array();
        ExternalModules::$queryHandler = function () use ($sqlRecords) {
            return $sqlRecords;
        };
        REDCap::$getDataHandler = $getData ?: function () {
            return array();
        };
    }

    function expect_exception(callable $fn, $name)
    {
        try {
            $fn();
            check(false, $name);
        } catch (\Exception $e) {
            check(true, $name);
        }
    }

    // --- Validator -------------------------------------------------------------------------------
    foreach (array('8RDYCD8X', 'YDRX8CER', 'abcdef', str_repeat('A', 40)) as $ok) {
        check(Validator::hash($ok) === $ok, "hash accepts $ok");
    }
    foreach (array("x' or '1'='1", '', 'abc12', str_repeat('A', 41), 'ABC DEF1', "ABCD]123", "ABCD'123", null, array('8RDYCD8X'), 12345678) as $bad) {
        check(Validator::hash($bad) === null, 'hash rejects ' . json_encode($bad));
    }
    foreach (array('L1', 'E31001', 'S_1', '2019-1-1', 'a.b', 42) as $ok) {
        check(Validator::recordId($ok) === (string)$ok, "recordId accepts $ok");
    }
    foreach (array("L1' or 1=1", '', 'a]b', 'a b', str_repeat('x', 101), null, array('L1')) as $bad) {
        check(Validator::recordId($bad) === null, 'recordId rejects ' . json_encode($bad));
    }
    check(Validator::pid('354') === 354 && Validator::pid(354) === 354, 'pid accepts 354');
    foreach (array('0', '-1', '35a', '', ' 354', null, array(1), 0) as $bad) {
        check(Validator::pid($bad) === null, 'pid rejects ' . json_encode($bad));
    }

    // --- Data guards ------------------------------------------------------------------------------
    reset_stubs();
    expect_exception(function () {
        Data::get(array('events' => array(1), 'records' => array('E1')), 't');
    }, 'Data::get refuses a read without fields');
    expect_exception(function () {
        Data::get(array('events' => array(1), 'fields' => array('id')), 't');
    }, 'Data::get refuses a read without records or filterLogic');
    Data::get(array('events' => array(1), 'fields' => array('id')), 't', true);
    check(count(REDCap::$getDataCalls) === 1 && REDCap::$getDataCalls[0]['return_format'] === 'array',
        'Data::get allows an explicit all-records read, in array format');

    reset_stubs(array());
    check(Data::lookup(1, 'hash', array('8RDYCD8X'), array('id'), 't') === array(), 'lookup with no SQL match returns []');
    check(count(REDCap::$getDataCalls) === 0, 'lookup with no SQL match never calls getData (empty records = all)');
    check(strpos(ExternalModules::$queries[0][0], 'redcap_data2') !== false && ExternalModules::$queries[0][1] === array(354, 1, 'hash', '8RDYCD8X'),
        'lookup is a parameterized query on the project data table');

    // --- Student ----------------------------------------------------------------------------------
    $ev = 606;
    reset_stubs(array('S1', 'S2'), function ($p) use ($ev) {
        return array(
            'S1' => array($ev => array('id' => 'S1', 'hash' => 'abcd1234')),     // case-insensitive SQL superset
            'S2' => array($ev => array('id' => 'S2', 'hash' => 'ABCD1234')),
        );
    });
    $s = new Student($ev);
    $s->setRecord('ABCD1234');
    check(array_keys($s->getRecord()) === array('S2'), 'student by hash matches the hash exactly');
    $call = REDCap::$getDataCalls[0];
    check($call['records'] === array('S1', 'S2') && $call['fields'] === Student::FIELDS && $call['events'] === array($ev),
        'student by hash reads only the matched records and the student fields');

    reset_stubs(array('S1'));
    $s->setRecord("x' or '1'='1");
    check($s->getRecord() === array() && count(ExternalModules::$queries) === 0 && count(REDCap::$getDataCalls) === 0,
        'malformed hash reads nothing');

    reset_stubs(array(), function ($p) use ($ev) {
        return array('S7' => array($ev => array('id' => 'S7', 'hash' => 'H')));
    });
    $s->setRecord('S7', 'id');
    check(array_keys($s->getRecord()) === array('S7') && REDCap::$getDataCalls[0]['records'] === array('S7'),
        'student by id reads one record');

    // generateHash: one read of all hashes, then set lookups; a used hash is skipped
    reset_stubs(array(), function ($p) use ($ev) {
        return array('S1' => array($ev => array('id' => 'S1', 'hash' => 'AAAA1111')));
    });
    $GLOBALS['__hashes'] = array('AAAA1111', 'BBBB2222', 'BBBB2222', 'CCCC3333');
    $s2 = new Student($ev);
    $h1 = $s2->generateHash();
    $h2 = $s2->generateHash();
    check($h1 === 'BBBB2222' && $h2 === 'CCCC3333', 'generateHash skips used hashes, including ones it just made');
    check(count(REDCap::$getDataCalls) === 1 && REDCap::$getDataCalls[0]['fields'] === array('id', 'hash'),
        'generateHash reads the hashes once');

    // --- Evaluation -------------------------------------------------------------------------------
    $evE = 607;
    $rows = array(
        'E1' => array($evE => array('id' => 'E1', 'evaluation_lecture_id' => 'L1', 'evaluation_student_id' => 'S1', 'evaluation_setup_complete' => '0')),
        'E2' => array($evE => array('id' => 'E2', 'evaluation_lecture_id' => 'L2', 'evaluation_student_id' => 'S1', 'evaluation_setup_complete' => '2')),
        'E3' => array($evE => array('id' => 'E3', 'evaluation_lecture_id' => 'L1', 'evaluation_student_id' => 'S1', 'evaluation_setup_complete' => '2')),
        'E4' => array($evE => array('id' => 'E4', 'evaluation_lecture_id' => 'L1', 'evaluation_student_id' => 's1', 'evaluation_setup_complete' => '2')),
    );
    reset_stubs(array_keys($rows), function ($p) use ($rows) {
        return array_intersect_key($rows, array_flip($p['records']));
    });
    $e = new Evaluation($evE);
    $a = $e->isEvaluationComplete('S1', 'L1');
    $b = $e->isEvaluationComplete('S1', 'L2');
    $c = $e->isEvaluationComplete('S1', 'L3');
    check($a['id'] === 'E3', 'isEvaluationComplete: last match wins');
    check($b['id'] === 'E2' && $c === false, 'isEvaluationComplete: per lecture, false when none');
    check(count(REDCap::$getDataCalls) === 1 && count(ExternalModules::$queries) === 1,
        'one lookup per student per request, however many lectures');
    check(REDCap::$getDataCalls[0]['fields'] === Evaluation::INDEX_FIELDS, 'evaluation index reads INDEX_FIELDS only');

    reset_stubs(array());
    $e2 = new Evaluation($evE);
    $e2->isEvaluationComplete('S9', 'L1');
    $e2->isEvaluationComplete('S9', 'L2');
    check(count(ExternalModules::$queries) === 1, 'a student with no evaluations is cached too (no re-query)');
    $e2->isEvaluationComplete('', 'L1');
    check(count(ExternalModules::$queries) === 1, 'a blank student id reads nothing');

    list($completed, $todo) = $e->getStudentStates('S1', array(
        'L1' => array(605 => array('lecture_date' => '2026-01-01')),
        'L2' => array(605 => array('lecture_date' => '2026-01-01')),
        'L3' => array(605 => array('lecture_date' => '')),
    ), 605);
    check($completed === 2 && $todo === 0, 'getStudentStates counts completed and TBA as before');

    reset_stubs(array('E1', 'E3'), function ($p) use ($rows, $evE) {
        $out = array();
        foreach ($p['records'] as $r) {
            $out[$r] = array($evE => array_merge($rows[$r][$evE], array('score' => '4', 'comments' => "c$r")));
        }
        return $out;
    });
    $list = $e->getLectureEvaluations('L1');
    check(count($list) === 2 && REDCap::$getDataCalls[0]['fields'] === Evaluation::FEEDBACK_FIELDS,
        'getLectureEvaluations returns the lecture\'s evaluations with the feedback fields only');
    reset_stubs(array('E1'));
    check($e->getLectureEvaluations("L1' or 1=1") === array() && count(ExternalModules::$queries) === 0,
        'getLectureEvaluations rejects a malformed lecture id');

    // next E<n>: gap-filling as before, from a set of names
    reset_stubs(array('E1', 'E2', 'E4'));
    $GLOBALS['Proj'] = (object)array('table_pk' => 'id');
    $e3 = new Evaluation($evE);
    $m = new \ReflectionMethod($e3, 'getNextId');
    $m->setAccessible(true);
    check($m->invoke($e3, 354, $evE, 'E', false, array()) === 'E3', 'next id fills the first gap (E3)');
    check($m->invoke($e3, 354, $evE, 'E', false, array('E3')) === 'E5', 'next id honours the skip list (E5)');
    check(count(REDCap::$getDataCalls) === 0 && count(ExternalModules::$queries) === 1, 'record names are read once, without getData');

    // --- Lecture ----------------------------------------------------------------------------------
    reset_stubs(array('L1'));
    $l = new Lecture(605);
    check($l->getCompletedLectures(array()) === array() && count(ExternalModules::$queries) === 0,
        'a student with no mapping codes reads no lectures');
    reset_stubs(array('L1', 'L2'), function ($p) {
        return array(
            'L1' => array(605 => array('id' => 'L1', 'lecture_date' => '2020-01-01')),
            'L2' => array(605 => array('id' => 'L2', 'lecture_date' => '2999-01-01')),
        );
    });
    $done = $l->getCompletedLectures(array('2019-1-1', "bad'code", '2026'));
    check(array_keys($done) === array('L1'), 'getCompletedLectures keeps past (and blank) dates only');
    check(ExternalModules::$queries[0][1] === array(354, 605, 'lecture_student_mapping', '2019-1-1', '2026'),
        'mapping codes are validated and passed as parameters');
    check(REDCap::$getDataCalls[0]['fields'] === Lecture::LIST_FIELDS, 'lectures are read with the list fields only');

    reset_stubs(array(), function ($p) {
        return array('L5' => array(605 => array('id' => 'L5')));
    });
    $l->setRecord('L5');
    check(array_keys($l->getRecord()) === array('L5') && REDCap::$getDataCalls[0]['records'] === array('L5')
        && in_array('lecture_complete', REDCap::$getDataCalls[0]['fields'], true),
        'lecture by id reads one record with every lecture-form field');
    reset_stubs();
    $l->setRecord(null);
    check($l->getRecord() === array() && count(REDCap::$getDataCalls) === 0, 'a missing lid reads nothing');

    echo "\n$passes passed, $failures failed\n";
    exit($failures ? 1 : 0);
}
