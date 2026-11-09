<?php
namespace Stanford\LectureEvaluation\Fixture;

if (PHP_SAPI !== 'cli') { // command-line only: this directory is web-served
    http_response_code(404);
    exit;
}
/**
 * Exercises the module's admin-side paths on the synthetic perf fixture and prints what they
 * produce, so a run before a change can be diffed against a run after it:
 *
 *   - Lecture::processFeedback (the instructor_feedback "Aggregate scores" save): the aggregate
 *     fields it writes back to the lecture,
 *   - createStudentUrl (the Data Import Tool shutdown hook) for hashless students,
 *   - redcap_save_record on the `student` instrument for a hashless student,
 *   - the authenticated view/students.php page (rendered into a buffer, hashed and summarised).
 *
 *   php admin-paths.php <fixture_pid> [lecture ids for processFeedback ...]
 *
 * Random hashes are reported by format and uniqueness only. Fixture data only, never prod.
 */

$pid = (int)($argv[1] ?? 0);
$_GET['pid'] = $pid;
define('NOAUTH', true);
require_once '/var/www/html/redcap_connect.php';
while (ob_get_level() > 0) {
    ob_end_flush();
}

$title = db_result(db_query("SELECT app_title FROM redcap_projects WHERE project_id = $pid"), 0);
if ($title !== 'LE PERF FIXTURE (synthetic)') {
    fwrite(STDERR, "pid $pid is not the perf fixture; refusing\n");
    exit(1);
}

/** @var \Stanford\LectureEvaluation\LectureEvaluation $module */
$module = \ExternalModules\ExternalModules::getModuleInstance('lecture_evaluation');
$evL = (int)$module->getProjectSetting('lectures');
$evS = (int)$module->getProjectSetting('students');
$evE = (int)$module->getProjectSetting('evaluations');

function measure(string $label, callable $fn)
{
    gc_collect_cycles();
    if (function_exists('memory_reset_peak_usage')) {
        call_user_func('memory_reset_peak_usage'); // PHP 8.2+
    }
    $base = memory_get_usage();
    $t = microtime(true);
    $r = $fn();
    fprintf(STDOUT, "PERF %-28s peak_delta_mb=%7.1f wall_s=%6.3f\n", $label, (memory_get_peak_usage() - $base) / 1048576, microtime(true) - $t);
    return $r;
}

function fieldValues(int $pid, int $event, string $record, array $fields): array
{
    $out = [];
    $d = \REDCap::getData(['project_id' => $pid, 'return_format' => 'array', 'records' => [$record], 'events' => [$event], 'fields' => $fields]);
    foreach ($fields as $f) {
        $out[$f] = $d[$record][$event][$f] ?? null;
    }
    return $out;
}

// 1. processFeedback -----------------------------------------------------------------------------
$fb = ['sample_size', 'avg_score', 'avg_lecture_organization', 'avg_knowledge_subject', 'avg_professionalism',
    'avg_communication', 'avg_questions_rec', 'range', 'student_comments'];
foreach (array_slice($argv, 2) ?: ['L181', 'L2', 'L299'] as $lid) {
    measure("processFeedback($lid)", function () use ($module, $lid, $pid) {
        $module->getLecture()->processFeedback($lid, $module->getEvaluation(), $pid);
    });
    $v = fieldValues($pid, $evL, $lid, $fb);
    $v['student_comments'] = 'sha1:' . sha1((string)$v['student_comments']) . ' len=' . strlen((string)$v['student_comments']);
    fwrite(STDOUT, "FEEDBACK $lid " . json_encode($v) . "\n");
}

// 2. createStudentUrl for hashless students (as after a Data Import) ----------------------------
$evName = \REDCap::getEventNames(true, false, $evS);
$new = [];
foreach (['S9001', 'S9002', 'S9003'] as $sid) {
    $new[] = ['id' => $sid, 'redcap_event_name' => $evName, 'first_name' => 'Imported', 'last_name' => $sid, 'email' => "$sid@example.org"];
}
$r = \REDCap::saveData($pid, 'json', json_encode($new));
if (!empty($r['errors'])) {
    fwrite(STDERR, "seed import failed: " . print_r($r['errors'], true));
    exit(1);
}
measure('createStudentUrl', function () use ($module) {
    $module->createStudentUrl();
});

// 3. redcap_save_record on the student instrument -------------------------------------------------
\REDCap::saveData($pid, 'json', json_encode([['id' => 'S9004', 'redcap_event_name' => $evName, 'first_name' => 'Saved']]));
measure('redcap_save_record(student)', function () use ($module, $pid, $evS) {
    $module->redcap_save_record($pid, 'S9004', 'student', $evS);
});

$all = \REDCap::getData(['project_id' => $pid, 'return_format' => 'array', 'events' => [$evS], 'fields' => ['id', 'hash']]);
$hashes = array_map(function ($r) use ($evS) { return $r[$evS]['hash']; }, $all);
foreach (['S9001', 'S9002', 'S9003', 'S9004'] as $sid) {
    $v = fieldValues($pid, $evS, $sid, ['hash', 'student_url', 'active']);
    $fmt = (bool)preg_match('/^[34789ACDEFHJKLMNPRTWXY]{8}$/', (string)$v['hash']);
    $urlOk = $v['student_url'] === $module->getUrl('view/student.php', true, true) . '&hash=' . $v['hash'];
    fwrite(STDOUT, "NEWSTUDENT $sid hash_format_ok=" . json_encode($fmt) . " url_ok=" . json_encode($urlOk) . " active=" . json_encode($v['active']) . "\n");
}
fwrite(STDOUT, "HASHES unique=" . json_encode(count($hashes) === count(array_unique($hashes))) . " count=" . count($hashes) . "\n");

// 4. view/students.php ------------------------------------------------------------------------------
$html = measure('view/students.php', function () use ($module) {
    ob_start();
    include dirname(__DIR__, 2) . '/view/students.php';
    return ob_get_clean();
});
$html = preg_replace('/S900[1-4].*?<\/tr>/s', '', $html);   // rows of the students this script just added
$html = preg_replace('/hash=[34789ACDEFHJKLMNPRTWXY]{8}/', 'hash=<h>', $html);
preg_match_all('/<tr>\s*<td>(.*?)<\/td>.*?<td>(\d+)<\/td>\s*<td>(\d+)<\/td>\s*<td>(-?\d+)<\/td>/s', $html, $m, PREG_SET_ORDER);
fwrite(STDOUT, "STUDENTS_PAGE rows=" . count($m) . " sha1=" . sha1($html) . "\n");
foreach (array_slice($m, 0, 5) as $row) {
    fwrite(STDOUT, "  {$row[1]} completed={$row[2]} pending={$row[3]} future={$row[4]}\n");
}
