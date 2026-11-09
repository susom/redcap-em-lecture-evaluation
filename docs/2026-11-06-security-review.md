# Security and PHI review (2026-11-06)

Scope: code security, PHI/HIPAA handling and dependencies, for the whole module, with the
`fix/bounded-getdata-lookups` changes. `view/student` and `view/evaluation` are no-auth pages,
reachable by anyone with a student link. Checklists: the redcap-em skill's `security.md` and
`compliance.md`.

## Fixed in this branch

| # | Severity | Where | Issue | Fix |
|---|---|---|---|---|
| 1 | High | `view/evaluation.php` | An unknown or malformed `hash`/`lid` created an orphan `E<n>` record, unauthenticated | rejected with "This evaluation link is not valid."; no read, no write |
| 2 | High | `view/evaluation.php` | `emLog($url)` logged the survey link (its `s=` opens the response) and `emLog($lecture)` logged a whole record | removed; only the lecture id and the record id are logged |
| 3 | Medium | `LectureEvaluation`, views | `FILTER_SANITIZE_STRING` (deprecated) "cleaned" `hash`/`lid` instead of validating them | `Validator`: strict patterns, rejected on mismatch |
| 4 | Medium | `Evaluation::reserveNewRecordId` | string-built SQL (`sprintf` + `db_escape`); hard-coded `redcap_data` (wrong for projects on `redcap_data2`..); unanchored `record regexp` | parameterized `ExternalModules::query`; `REDCap::getDataTable()`; exact match |
| 5 | Medium | `view/students.php` (admin) | student names, emails and URLs echoed unescaped (stored XSS through imported data) | `htmlspecialchars` |
| 6 | Low | `view/student.php` | lecture `course`/`topic`/`instructor` echoed unescaped on a no-auth page; the raw `$_GET['hash']` echoed into links | escaped; only the validated hash is echoed |
| 7 | Low | `view/student.php` | the page URL carries the student's hash, and the page loads JS/CSS from three CDNs | `<meta name="referrer" content="no-referrer">` |
| 8 | Low | classes | exception messages echoed unescaped | `htmlspecialchars` |
| 9 | Low | `Evaluation::reserveNewRecordId` | `new \Project($Proj)` (an object where an id is expected); any int project id crashed | `new \Project($project_id)`, string comparison |
| 10 | Data minimization | all lookups | whole-event, all-field reads | bounded reads; see `2026-11-06-bounded-reads.md` |
| 11 | Medium (scanner: TaintedHeader) | `redcap_survey_complete` | the student's stored `hash` went from the database into a `Location` header unvalidated | `Validator::hash()` first; redirect through the framework's `ExternalModules::redirectAfterHook()` (static: framework v1 has no `$this->redirectAfterHook()`, and calling it threw, which lost the survey submission; caught by the E2E before commit) |
| 12 | Medium (scanner: Constructor) | `LectureEvaluation` | module constructor | removed; everything is lazy |
| 13 | High | `tests/fixture/*.php`, `tests/unit/run.php` | the test scripts live in the web-served module directory and answered HTTP (200/302) | first statement: `PHP_SAPI !== 'cli'` → 404 (verified: all three return 404 with an empty body) |
| 14 | Low (log noise) | `reserveNewRecordId` | when two tabs raced for the same `E<n>`, the duplicate-key insert threw, and the framework logged the SQL and a stack trace for every collision | `INSERT IGNORE` + affected rows; no log entry |

**Module Security Scanning** (`ExternalModules/bin/scan.php`, REDCap 17.5.9) on this branch: phpcs
and Psalm taint analysis report no issues. The first run found 1 TaintedHeader (#11), the
constructor (#12) and phpcs errors in the test scripts (namespaces, a PHP 8.2-only call, echo of CLI
output); all are fixed. On a clean `git archive` of the branch (exactly what ships, no
`node_modules/`) the scanner reports: "The scan completed successfully, and no issues were
detected!" (exit 0). `npm audit` on the host: 0 vulnerabilities.

Dependencies: no Composer dependencies. `npm audit` on the dev-only Playwright package: 0
vulnerabilities. `package.json` is not loaded by REDCap; `node_modules/` is git-ignored.

## Needs a decision (not changed: each would change behaviour)

1. **No rate limit on the no-auth pages.** A hash is 8 characters from a 22-character alphabet
   (about 35.6 bits). With 230 students, one guess succeeds with probability ~4×10⁻⁹. That is enough
   against casual guessing, but there is no throttle on enumeration. The framework's `throttle()`
   writes a module-log row per request, which this hot path should avoid. Prefer a rate limit at the
   ingress/WAF for `prefix=lecture_evaluation&page=view%2F*`, or longer hashes for new students.
2. **`view/evaluation` does not check that the lecture is mapped to the student.** Any valid student
   link can open (and create) an evaluation for any lecture id, and lecture ids are guessable
   (`L1`, `L2`, ...). Fix: reject when `isStudentMappedToLecture()` is false (the same check
   `view/student` uses to build its list).
3. **`redcap_survey_complete` runs for every survey in the project**, including
   `instructor_feedback_survey`. For those it writes `lecture_complete`/`evaluation_setup_complete`
   into the evaluation event of a lecture record, and redirects the instructor to an empty student
   page. Fix: return early unless `$instrument === 'evaluation_survey'`.
4. **No `framework-version` in `config.json`** (framework v1). Moving to a current version enables
   `$module->escape()`, `$module->query()`, `$this->redirectAfterHook()` and no-auth logging
   controls, but changes framework behaviour. Do it as its own change, with these E2E scripts as the
   regression check.
5. **Third-party JS on a no-auth page**: jQuery, jQuery UI and DataTables are loaded from CDNs without
   SRI (only Bootstrap has `integrity`). A compromised CDN could read the page, including the hash in
   the URL. Vendor them into `asset/`, or add SRI hashes.
6. **`header_text`** is echoed unescaped on `view/student`. It is a design-rights setting and probably
   holds intended HTML, so it was left alone. Confirm.

## PHI checklist

- [x] No field values in emLogger or exception logs: lecture/record ids only; no hash, no survey link.
- [x] No email or SMS sent by the module.
- [x] No PHI in URLs: the student link carries an opaque hash; `lid` is a lecture id.
- [x] No outbound API calls (only browser CDN loads, item 5 above).
- [~] No-auth pages render only lecture data (course, topic, date, instructor) after a valid hash, and
      the same empty page for an unknown or malformed one. Not throttled (item 1).
- [x] No files written; no record data in module settings.
- [x] Writes go through `REDCap::saveData` (audited by REDCap).
- [x] `getData` calls are field-scoped and record-scoped (admin exceptions listed in the bounded-reads doc).

The authoritative check is **Control Center → External Modules → Module Security Scanning**. The
same scanner was run from the command line (above); run it again on the release tag. Stanford-specific policy (approved services, data classification) still
needs to be confirmed with the REDCap team lead; nothing here sends data outside REDCap.
