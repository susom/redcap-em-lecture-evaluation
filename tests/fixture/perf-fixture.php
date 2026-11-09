<?php
namespace Stanford\LectureEvaluation\Fixture;

if (PHP_SAPI !== 'cli') { // command-line only: this directory is web-served
    http_response_code(404);
    exit;
}
/**
 * Local-only performance fixture for the Lecture Evaluations EM.
 *
 *   php perf-fixture.php create <source_pid>   clone a project's DESIGN (no records) into a new project
 *   php perf-fixture.php seed <pid> [scale]    wipe and reseed synthetic data (deterministic); scale small|prod|x2
 *   php perf-fixture.php drop <pid>            delete the fixture project's data, design and settings
 *
 * Run it inside the web container (it bootstraps REDCap through redcap_connect.php). It never reads
 * record values from the source project: `create` copies metadata, arms, events, form designations,
 * survey settings and the module's settings, and nothing else.
 *
 * `seed` writes synthetic data straight into the project's redcap_data table, sized like production
 * (pid 17231): about 0.7 M rows in the evaluations event. Every value is made up. The RNG seed is
 * fixed, so two seeds produce byte-identical data and before/after runs can be compared.
 *
 * NEVER run this against production.
 */

$mode = (string)($argv[1] ?? '');
$arg  = (int)($argv[2] ?? 0);
if (!in_array($mode, ['create', 'seed', 'drop'], true) || $arg <= 0) {
    fwrite(STDERR, "usage: php perf-fixture.php create <source_pid> | seed <pid> | drop <pid>\n");
    exit(1);
}

if ($mode !== 'create') {
    $_GET['pid'] = $arg;
}
define('NOAUTH', true);
require_once '/var/www/html/redcap_connect.php';

const FIXTURE_TITLE  = 'LE PERF FIXTURE (synthetic)';
const MODULE_PREFIX  = 'lecture_evaluation';
const RNG_SEED       = 20261106;
// Scales: `prod` matches pid 17231 (one full read of the evaluations event is ~0.74 M rows);
// `x2` doubles the evaluations; `small` is small enough for the pre-fix view/students.php, whose
// students x lectures x evaluations loop does not finish at prod scale.
const SCALES = [
    'small' => [300, 40, 1500],
    'prod'  => [2800, 230, 31000],
    'x2'    => [2800, 230, 62000],
];
$scale = (string)($argv[3] ?? 'prod');
if (!isset(SCALES[$scale])) {
    fwrite(STDERR, "unknown scale '$scale' (small|prod|x2)\n");
    exit(1);
}
define('N_LECTURES', SCALES[$scale][0]);
define('N_STUDENTS', SCALES[$scale][1]);
define('N_EVALUATIONS', SCALES[$scale][2]);
const COHORTS        = ['2019-1-1', '2021_1_1', '2026', '2027', '2028'];
const HASH_ALPHABET  = '34789ACDEFHJKLMNPRTWXY'; // generateRandomHash(8, false, true, false)

function q(string $sql)
{
    $r = db_query($sql);
    if ($r === false) {
        fwrite(STDERR, "SQL failed: " . db_error() . "\n" . substr($sql, 0, 400) . "\n");
        exit(1);
    }
    return $r;
}

function rows(string $sql): array
{
    $r = q($sql);
    $out = [];
    while ($row = db_fetch_assoc($r)) {
        $out[] = $row;
    }
    return $out;
}

function cols(string $table, array $skip): array
{
    $c = [];
    foreach (rows("SHOW COLUMNS FROM `$table`") as $row) {
        if (!in_array($row['Field'], $skip, true)) {
            $c[] = '`' . $row['Field'] . '`';
        }
    }
    return $c;
}

function moduleId(): int
{
    $r = rows("SELECT external_module_id FROM redcap_external_modules WHERE directory_prefix = '" . MODULE_PREFIX . "'");
    if (!$r) {
        fwrite(STDERR, "module " . MODULE_PREFIX . " not registered\n");
        exit(1);
    }
    return (int)$r[0]['external_module_id'];
}

function eventSettings(int $pid): array
{
    $mid = moduleId();
    $out = [];
    foreach (rows("SELECT `key`, value FROM redcap_external_module_settings WHERE external_module_id = $mid AND project_id = $pid") as $r) {
        $out[$r['key']] = $r['value'];
    }
    foreach (['students', 'lectures', 'evaluations'] as $k) {
        if (empty($out[$k])) {
            fwrite(STDERR, "project $pid has no '$k' event setting for the module\n");
            exit(1);
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------
if ($mode === 'create') {
    $src = $arg;
    $exists = rows("SELECT project_id FROM redcap_projects WHERE app_title = '" . db_escape(FIXTURE_TITLE) . "'");
    if ($exists) {
        fwrite(STDERR, "fixture project already exists: pid " . $exists[0]['project_id'] . " (drop it first)\n");
        exit(1);
    }

    $pc = cols('redcap_projects', ['project_id']);
    $sel = array_map(function ($c) {
        switch ($c) {
            case '`project_name`':       return "CONCAT('le_perf_fixture_', UNIX_TIMESTAMP())";
            case '`app_title`':          return "'" . db_escape(FIXTURE_TITLE) . "'";
            case '`creation_time`':      return 'NOW()';
            case '`status`':             return '0';
            case '`twilio_from_number`': return 'NULL';
            case '`last_logged_event`':  return 'NULL';
            default:                     return $c;
        }
    }, $pc);
    q("INSERT INTO redcap_projects (" . implode(',', $pc) . ") SELECT " . implode(',', $sel) . " FROM redcap_projects WHERE project_id = $src");
    $pid = (int)db_insert_id();

    $mc = cols('redcap_metadata', ['project_id']);
    q("INSERT INTO redcap_metadata (project_id," . implode(',', $mc) . ") SELECT $pid," . implode(',', $mc) . " FROM redcap_metadata WHERE project_id = $src");

    $eventMap = [];
    foreach (rows("SELECT * FROM redcap_events_arms WHERE project_id = $src ORDER BY arm_num") as $arm) {
        q(sprintf("INSERT INTO redcap_events_arms (project_id, arm_num, arm_name) VALUES (%d, %d, '%s')",
            $pid, $arm['arm_num'], db_escape($arm['arm_name'])));
        $newArm = (int)db_insert_id();
        foreach (rows("SELECT * FROM redcap_events_metadata WHERE arm_id = " . (int)$arm['arm_id'] . " ORDER BY event_id") as $ev) {
            q(sprintf("INSERT INTO redcap_events_metadata (arm_id, day_offset, offset_min, offset_max, descrip, custom_event_label)
                       VALUES (%d, %F, %F, %F, '%s', %s)", $newArm, $ev['day_offset'], $ev['offset_min'], $ev['offset_max'],
                db_escape($ev['descrip']), $ev['custom_event_label'] === null ? 'NULL' : "'" . db_escape($ev['custom_event_label']) . "'"));
            $eventMap[(int)$ev['event_id']] = (int)db_insert_id();
        }
    }
    foreach ($eventMap as $old => $new) {
        q("INSERT INTO redcap_events_forms (event_id, form_name) SELECT $new, form_name FROM redcap_events_forms WHERE event_id = $old");
    }

    $sc = cols('redcap_surveys', ['survey_id', 'project_id', 'logo']);
    q("INSERT INTO redcap_surveys (project_id, logo," . implode(',', $sc) . ") SELECT $pid, NULL," . implode(',', $sc) . " FROM redcap_surveys WHERE project_id = $src");

    $mid = moduleId();
    foreach (rows("SELECT `key`, type, value FROM redcap_external_module_settings WHERE external_module_id = $mid AND project_id = $src") as $s) {
        $value = $s['value'];
        if (in_array($s['key'], ['students', 'lectures', 'evaluations'], true)) {
            $value = (string)$eventMap[(int)$value];
        }
        q(sprintf("INSERT INTO redcap_external_module_settings (external_module_id, project_id, `key`, type, value) VALUES (%d, %d, '%s', '%s', '%s')",
            $mid, $pid, db_escape($s['key']), db_escape($s['type']), db_escape($value)));
    }

    fwrite(STDOUT, json_encode(['pid' => $pid, 'events' => $eventMap]) . "\n");
    exit(0);
}

// ---------------------------------------------------------------------------------------------
$pid = $arg;
$proj = rows("SELECT app_title, data_table FROM redcap_projects WHERE project_id = $pid");
if (!$proj || $proj[0]['app_title'] !== FIXTURE_TITLE) {
    fwrite(STDERR, "pid $pid is not the fixture project ('" . FIXTURE_TITLE . "'); refusing\n");
    exit(1);
}
$dataTable = $proj[0]['data_table'] ?: 'redcap_data';
$surveyIds = array_column(rows("SELECT survey_id FROM redcap_surveys WHERE project_id = $pid"), 'survey_id');
$surveyList = $surveyIds ? implode(',', array_map('intval', $surveyIds)) : '0';

function wipe(int $pid, string $dataTable, string $surveyList): void
{
    q("DELETE r FROM redcap_surveys_response r JOIN redcap_surveys_participants p USING (participant_id) WHERE p.survey_id IN ($surveyList)");
    q("DELETE FROM redcap_surveys_participants WHERE survey_id IN ($surveyList)");
    q("DELETE FROM `$dataTable` WHERE project_id = $pid");
    q("DELETE FROM redcap_new_record_cache WHERE project_id = $pid");
    q("DELETE FROM redcap_record_list WHERE project_id = $pid");
    \Records::resetRecordCountAndListCache($pid);
}

if ($mode === 'drop') {
    wipe($pid, $dataTable, $surveyList);
    $events = array_column(rows("SELECT e.event_id FROM redcap_events_metadata e JOIN redcap_events_arms a USING (arm_id) WHERE a.project_id = $pid"), 'event_id');
    if ($events) {
        q("DELETE FROM redcap_events_forms WHERE event_id IN (" . implode(',', array_map('intval', $events)) . ")");
        q("DELETE e FROM redcap_events_metadata e JOIN redcap_events_arms a USING (arm_id) WHERE a.project_id = $pid");
    }
    q("DELETE FROM redcap_events_arms WHERE project_id = $pid");
    q("DELETE FROM redcap_surveys WHERE project_id = $pid");
    q("DELETE FROM redcap_metadata WHERE project_id = $pid");
    q("DELETE FROM redcap_external_module_settings WHERE project_id = $pid");
    q("DELETE FROM redcap_user_rights WHERE project_id = $pid");
    q("DELETE FROM redcap_projects WHERE project_id = $pid");
    fwrite(STDOUT, "dropped $pid\n");
    exit(0);
}

// seed
$settings = eventSettings($pid);
$evL = (int)$settings['lectures'];
$evS = (int)$settings['students'];
$evE = (int)$settings['evaluations'];

wipe($pid, $dataTable, $surveyList);
mt_srand(RNG_SEED);

$buffer = [];
$total = 0;
$perEvent = [$evL => 0, $evS => 0, $evE => 0];
function put(int $event, string $record, string $field, $value): void
{
    global $buffer, $perEvent;
    if ($value === '' || $value === null) {
        return;
    }
    $buffer[] = [$event, $record, $field, (string)$value];
    $perEvent[$event]++;
    if (count($buffer) >= 2000) {
        flush_rows();
    }
}
function flush_rows(): void
{
    global $buffer, $pid, $dataTable, $total;
    if (!$buffer) {
        return;
    }
    $vals = [];
    foreach ($buffer as [$e, $r, $f, $v]) {
        $vals[] = sprintf("(%d,%d,'%s','%s','%s',NULL)", $pid, $e, db_escape($r), db_escape($f), db_escape($v));
    }
    q("INSERT INTO `$dataTable` (project_id, event_id, record, field_name, value, instance) VALUES " . implode(',', $vals));
    $total += count($buffer);
    $buffer = [];
}
function words(int $n): string
{
    static $w = ['clear', 'engaging', 'pace', 'slides', 'examples', 'cases', 'helpful', 'review', 'concepts',
        'questions', 'structure', 'board', 'reading', 'timing', 'detail', 'summary', 'practice', 'volume'];
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = $w[mt_rand(0, count($w) - 1)];
    }
    return ucfirst(implode(' ', $out)) . '.';
}

// Lectures ------------------------------------------------------------------------------------
$today = strtotime('2026-11-06');
$lectures = [];          // id => ['date' => ..., 'cohorts' => [...]]
$lectureForm = [];       // id => lecture-form values (copied into evaluations, like view/evaluation does)
for ($i = 1; $i <= N_LECTURES; $i++) {
    $id = 'L' . $i;
    $r = mt_rand(1, 100);
    if ($r <= 5) {
        $date = '';                                                     // TBA
    } elseif ($r <= 12) {
        $date = date('Y-m-d', $today + mt_rand(1, 200) * 86400);         // future
    } else {
        $date = date('Y-m-d', $today - mt_rand(1, 900) * 86400);         // past
    }
    $cohorts = [COHORTS[($i - 1) % count(COHORTS)]];
    if (mt_rand(1, 10) === 1) {
        $cohorts[] = COHORTS[mt_rand(0, count(COHORTS) - 1)];
    }
    $cohorts = array_values(array_unique($cohorts));
    $lectures[$id] = ['date' => $date, 'cohorts' => $cohorts];
    $form = [
        'id' => $id,
        'lecture_date' => $date,
        'instructor' => 'Instructor ' . (($i % 140) + 1),
        'pom_faculty' => (string)mt_rand(0, 1),
        'instructor_preferred_name' => 'Dr. Synthetic ' . (($i % 140) + 1),
        'instructor_email' => 'instructor' . (($i % 140) + 1) . '@example.org',
        'topic' => 'Topic ' . $i . ' - ' . words(3),
        'lecture_hours' => (string)mt_rand(1, 3),
        'course' => 'COURSE ' . (200 + ($i % 30)),
        'course_director' => 'Director ' . (($i % 30) + 1),
        'cd_email' => 'director' . (($i % 30) + 1) . '@example.org',
        'lecture_complete' => '2',
    ];
    $lectureForm[$id] = $form;
    foreach ($form as $f => $v) {
        put($evL, $id, $f, $v);
    }
    foreach ($cohorts as $c) {
        put($evL, $id, 'lecture_student_mapping', $c);
    }
    put($evL, $id, 'lecture_student_map_complete', '2');
    if ($date !== '' && strtotime($date) < $today && mt_rand(1, 2) === 1) {
        put($evL, $id, 'instructor_actions', '1');
        put($evL, $id, 'lecture_text_feedback', words(20));
        put($evL, $id, 'student_comments', str_repeat(words(12) . "\n", 4));
        put($evL, $id, 'last_update', date('Y-m-d H:i:s', $today - 86400));
        put($evL, $id, 'instructor_feedback_complete', '2');
    }
}

// Students ------------------------------------------------------------------------------------
$module = \ExternalModules\ExternalModules::getModuleInstance(MODULE_PREFIX);
$base = $module->getUrl('view/student.php', true, true);
$students = [];
$usedHashes = [];
for ($i = 1; $i <= N_STUDENTS; $i++) {
    $id = 'S' . $i;
    do {
        $h = '';
        for ($k = 0; $k < 8; $k++) {
            $h .= HASH_ALPHABET[mt_rand(0, strlen(HASH_ALPHABET) - 1)];
        }
    } while (isset($usedHashes[$h]));
    $usedHashes[$h] = true;
    $cohort = COHORTS[($i - 1) % count(COHORTS)];
    $students[$id] = ['hash' => $h, 'cohort' => $cohort];
    foreach ([
                 'id' => $id, 'first_name' => 'First' . $i, 'last_name' => 'Last' . $i,
                 'email' => 'student' . $i . '@example.org', 'hash' => $h, 'student_url' => $base . '&hash=' . $h,
                 'active' => '1', 'student_complete' => '2',
             ] as $f => $v) {
        put($evS, $id, $f, $v);
    }
    if ($i % 10 !== 0) {                     // ~10% of students have no mapping, as on prod
        put($evS, $id, 'lecture_student_mapping', $cohort);
    }
    put($evS, $id, 'lecture_student_map_complete', '2');
}

// Evaluations -----------------------------------------------------------------------------------
$pastByCohort = [];
foreach ($lectures as $id => $l) {
    if ($l['date'] !== '' && strtotime($l['date']) < $today) {
        foreach ($l['cohorts'] as $c) {
            $pastByCohort[$c][] = $id;
        }
    }
}
$mapped = array_values(array_filter(array_keys($students), function ($s) {
    return ((int)substr($s, 1)) % 10 !== 0;
}));
$done = [];   // "student|lecture" pairs already evaluated
$e = 0;
$guard = 0;
while ($e < N_EVALUATIONS && $guard++ < N_EVALUATIONS * 5) {
    $sid = $mapped[mt_rand(0, count($mapped) - 1)];
    $pool = $pastByCohort[$students[$sid]['cohort']];
    $lid = $pool[mt_rand(0, count($pool) - 1)];
    if (isset($done["$sid|$lid"])) {
        continue;
    }
    $done["$sid|$lid"] = true;
    $e++;
    $rid = 'E' . $e;
    $complete = mt_rand(1, 100) <= 93;
    foreach ($lectureForm[$lid] as $f => $v) {
        put($evE, $rid, $f, $f === 'id' ? $rid : ($f === 'lecture_complete' ? ($complete ? '2' : '0') : $v));
    }
    put($evE, $rid, 'evaluation_lecture_id', $lid);
    put($evE, $rid, 'evaluation_student_id', $sid);
    put($evE, $rid, 'evaluation_date', date('Y-m-d H:i:s', $today - mt_rand(1, 800) * 86400));
    put($evE, $rid, 'evaluation_setup_complete', $complete ? '2' : '0');
    if ($complete) {
        put($evE, $rid, 'did_you_attend_this_lectur', '1');
        foreach (['score', 'knowledge_subject', 'lecture_organization', 'professionalism', 'communication', 'questions_rec'] as $f) {
            put($evE, $rid, $f, (string)mt_rand(1, 5));
        }
        if (mt_rand(1, 3) === 1) {
            put($evE, $rid, 'comments', words(mt_rand(8, 40)));
        }
        put($evE, $rid, 'evaluation_survey_complete', '2');
    }
}
flush_rows();
\Records::resetRecordCountAndListCache($pid);

// Hand the E2E scripts a few known students: hyphenated, underscored and plain cohort codes, and one
// student with no mapping at all.
$pick = ['S1', 'S2', 'S3', 'S10'];
$fixture = ['pid' => $pid, 'scale' => $scale, 'rows' => $total, 'rows_per_event' => $perEvent, 'evaluations' => $e, 'students' => []];
foreach ($pick as $sid) {
    $fixture['students'][$sid] = [
        'hash' => $students[$sid]['hash'],
        'cohort' => ((int)substr($sid, 1)) % 10 === 0 ? null : $students[$sid]['cohort'],
        'url' => $base . '&hash=' . $students[$sid]['hash'],
    ];
}
fwrite(STDOUT, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
