# Bounded reads: student requests no longer load whole events (2026-11-06)

## Why

On prod (pid 17231, module `lecture_evaluation_v9.9.9` = master `e7184007`), every `view/student`,
`view/evaluation` and evaluation-survey submit held 330-440 MB and took about 13 s. One student
opening ~20 evaluations in parallel tabs exceeded a pod's 6 GiB limit, and the kernel OOM-killed
the Apache container 3 times in 51 minutes. The same happened on 2026-10-09 (a coordinator opening
~27 students' pages).

The cause was the 2020 "optimize queries" change (0952eedb). It replaced filtered reads with
`REDCap::getData` of the entire event, all fields, filtered in PHP:

| Request | What it read before |
|---|---|
| every page with `pid` | constructor: all students (all fields) to find one hash, all lectures to find one lid |
| `view/student` | all lectures; then, per lecture, a scan of **all evaluations, all fields** |
| `view/evaluation` | as above, plus all evaluation ids |
| survey complete | all evaluations to find one, all students to find one, then a redirect to `view/student` |
| `instructor_feedback` save | all evaluations, all fields |
| `view/students.php` (admin) | O(students x lectures x evaluations); does not finish at prod scale |

## What changed

All reads go through `Data` (`Data.php`). `Data::get()` throws on a `getData` call with no field
list, or with neither `records` nor `filterLogic` (unless the caller passes `allRecords = true`).
It also counts the records each call returns.

| Lookup | Now |
|---|---|
| Student by hash (`?hash=`) | `Validator::hash()` (`^[A-Za-z0-9]{6,40}$`), then `Data::lookup` on `hash`, 5 fields, exact match re-checked in PHP |
| Student by id (survey complete, `student` save) | `records => [id]`, same 5 fields |
| Lecture by id (`?lid=`) | `Validator::recordId()`, `records => [lid]`, every `lecture`-form field (view/evaluation copies them all into the new evaluation) |
| `view/student` lectures | only lectures sharing a `lecture_student_mapping` code with the student (the page skipped all others anyway), 6 fields |
| Student's evaluations | one lookup per request on `evaluation_student_id`, 4 fields, indexed by lecture id (last match wins, as before). Cached per student with an explicit "loaded" key, so an empty result is cached too |
| Survey complete: the evaluation | `records => [record]`, 3 fields |
| `processFeedback` | lookup on `evaluation_lecture_id`, the 9 fields it aggregates |
| Next `E<n>` id | record names only (a set from one parameterized query), same gap-filling |
| `generateHash` / `createStudentUrl` | existing hashes read once into a set (was O(students²) reads) |
| Constructor | removed; `getStudent()`, `getLecture()` and `getEvaluation()` build on first use |

**Why not `filterLogic`.** The plan was `filterLogic` with an event-prefixed `[event][field] = 'value'`.
We built and measured it, and it does not bound the read in REDCap 17.5.9. `Records::applyFilteringLogic`
first runs `getData` with `returnEmptyEvents => true` over every record in the project (about 34 k
here), for the logic's fields, and only then evaluates the logic. Measured on the fixture:
`view/student` 65 MB and `view/evaluation` 62 MB. A lookup on S10, which reads nothing else, still
cost 61 MB and about 280 ms. `Data::lookup()` therefore runs one parameterized query on the project's
data table (`REDCap::getDataTable()`, via `ExternalModules::query`, on REDCap's own connection). It
returns the matching record names, and `getData` then reads only those records and fields. MySQL's
collation ignores case, so every caller re-checks the value strictly in PHP, as the old code did.

**Project-wide reads that remain** (all have explicit fields; `allRecords = true` in code):

| Read | Where | Why |
|---|---|---|
| `students:all` (`id, first_name, last_name, email, student_url`) | `view/students.php` (admin) | lists every student |
| `students:all` (`id, hash`) | `createStudentUrl`, `generateHash` (admin import / student save) | the set of used hashes |
| `lectures:all` (6 fields), `lectures:count` (`id`) | `view/students.php` | per-student counts over all lectures |
| `evaluations:all` (4 fields) | `view/students.php` | one read for all students instead of one scan per student |
| evaluation record names (SQL, names only) | `view/evaluation` when it creates a record | picking the next free `E<n>` |

### Other fixes in this change

- `FILTER_SANITIZE_STRING` (deprecated in PHP 8.1) is gone. `hash`, `lid` and `pid` are validated by
  `Validator` and rejected if they don't match, never "cleaned".
- Logging: the survey link (`emLog($url)`, its `s=` value opens the survey) and whole lecture arrays
  are no longer logged. The student hash is never logged.
- `view/student`: lecture `course`, `topic` and `instructor` are HTML-escaped, and the hash in the
  Evaluate links is the validated one. A `no-referrer` meta keeps the hash out of Referer headers to
  the CDNs.
- `reserveNewRecordId`: parameterized queries. When REDCap's record-list cache isn't complete, it
  checks the project's own data table instead of a hard-coded `redcap_data`, with an exact match.
  `record regexp 'E3'` also matched E30-E39. A latent `new \Project($Proj)` (object instead of id)
  is fixed too.
- `view/students.php`: student names, emails and URLs are HTML-escaped.
- Survey complete: the stored hash is validated before it goes into the redirect, and the redirect
  uses the framework's `ExternalModules::redirectAfterHook()`. Same `Location` header and
  `exitAfterHook()` as before.
- `reserveNewRecordId` reserves with `INSERT IGNORE`, so two tabs racing for the same `E<n>` no
  longer write an error-log entry per collision.

### The one intentional behaviour change

`view/evaluation` with an unknown or malformed `hash` or `lid` now shows "This evaluation link is not
valid." and creates nothing. Before, it created an orphan `E<n>` record with a blank student or
lecture id, with no authentication. Under the new per-student lookup a blank id would also have
matched every orphan. Valid links behave exactly as before. `view/student` with an unknown hash
still renders the same empty page ("You have completed 0 out of 0").

## Measurements

Local REDCap 17.5.9, PHP 8.3.33, Xdebug off during measurement. Synthetic fixture sized like
pid 17231: 2,800 lectures, 230 students, 31,000 evaluations, **736 k rows in the evaluations event**
(783 k total). Per-request peak is `memory_get_peak_usage()` at shutdown, from a temporary probe
that was never committed. Container memory is `docker stats` of the web container during the run.

| | Before | After | After, 2x evaluations (1.47 M rows) |
|---|---|---|---|
| `view/student` peak / max wall (median) | 134.5 MB / 6.2 s (2.9 s) | **7.4 MB / 0.29 s (0.11 s)** | 7.5 MB / 0.40 s |
| `view/evaluation` peak / max wall (median) | 147.7 MB / 2.9 s (2.1 s) | **9.0 MB / 0.40 s (0.27 s)** | 12.4 MB / 1.6 s (1.5 s) |
| survey complete peak / wall | 123.3 MB / 0.76 s, then a heavy `view/student` | **7.9 MB / 0.07 s**, then a 7 MB `view/student` | 7.9 MB / 0.09 s |
| 20 tabs in parallel: wall / slowest tab | 9.0 s / 8.8 s | **2.0 s / 1.7 s** | 5.8 s / 5.6 s |
| web container memory during the run | 453 → **1,195 MiB** | **275-296 MiB (flat)** | 278-295 MiB |
| `processFeedback` | 118 MB / 3.6 s (the old full-event read, measured alone) | 0.2-11 MB / 0.15-0.19 s | |
| `view/students.php` (admin) | does not finish (> 10 min) | 41 MB / 1.4 s | |

Prod was heavier than the local baseline (330-440 MB, 13 s). The local fixture is synthetic and the
server is idle and faster, but the shape matches: before, the cost scales with the whole project; after,
with one student. At 2x, `view/evaluation` under 20 concurrent tabs is dominated by two indexed scans
(the student's evaluations, and the names for the next id). It is still under 2 s and memory stays flat.

Equivalence, on the same fresh seed before and after:

- All 11 saved pages (S1, S2, S3, a student with no mapping, an unknown and a malformed hash,
  before/after the tabs, after each of 3 submits) are byte-identical except for the added
  `no-referrer` meta line. The 20 Evaluate links are identical.
- The 20 records created by the tabs are field-for-field identical (except `evaluation_date`, a timestamp,
  and which tab won which id). Both runs created `E31001`-`E31020`.
- Every survey submit redirects to the same student URL.
- Admin paths (small seed, where the old `view/students.php` finishes): `processFeedback` writes the
  same `sample_size`, `avg_*`, `range` and `student_comments` for 3 lectures. Data-import and
  `student`-save hashes are generated in the right format, unique, with the right URL and `active`.
  `view/students.php` shows the same rows and counts; its HTML now escapes `&` in URLs as `&amp;`.

## How to test

Everything runs locally. Never against prod. Fixture data only.

```bash
# once: a project with this module's design (copied from a project that has it, here 177; no records)
docker exec -w /var/www/html/modules-local/lecture_evaluation_v9.9.9 redcap_2023_1_web \
  php tests/fixture/perf-fixture.php create 177          # prints {"pid": N, ...}

# before every run: wipe and reseed (deterministic). Scale: prod | x2 | small
docker exec -w /var/www/html/modules-local/lecture_evaluation_v9.9.9 redcap_2023_1_web \
  php tests/fixture/perf-fixture.php seed N prod > fixture.json

npm run e2e:install                                       # once
node e2e/student-flow.js --fixture fixture.json --label after --tabs 20 --submit 3 [--mobile] [--bad-links]
tests/fixture/dump-new-evaluations.sh N <evaluation_event_id> 31000   # records the run created

# admin paths (seed `small` first if you want the old students.php to finish)
docker exec -w /var/www/html/modules-local/lecture_evaluation_v9.9.9 redcap_2023_1_web \
  php tests/fixture/admin-paths.php N

# unit tests (REDCap stubbed; 66 checks)
docker exec -w /var/www/html/modules-local/lecture_evaluation_v9.9.9 redcap_2023_1_web php tests/unit/run.php
```

`e2e/student-flow.js` writes every page it lands on, plus `results.json`, to `e2e/out/<label>/`, so two
labels can be diffed. REDCap's own survey page raises one JS error on load ("reading
'offsetHeight'"), the same number of times before and after. The script records it but doesn't count
it as a failure. During the 20-tab test the system-enabled `redcap_notifications` module logs CSRF
mismatches for its survey-page AJAX when a tab closes mid-request. That module isn't part of this
change. `redcap_error_log` doesn't grow during a run.

To measure memory per request, add a temporary `register_shutdown_function` in the constructor that
appends `memory_get_peak_usage()`, wall time and `Data::calls()` (labels and record counts, no values,
no hashes) to a file. Never commit it.

## Not done, on purpose

**Per-project concurrency cap (`GET_LOCK`).** It was optional defence in depth. After the fix, a
request holds 7-12 MB, so 20 parallel tabs use about 200 MB against the 6 GiB pod limit. The cap
would cost more than it buys:

- `GET_LOCK` is global to the MySQL server, so 4 slots would be a cluster-wide cap on the project.
- A per-hash lock would serialize a student's tabs, and a timeout would turn them into 429s.
- It must never gate survey-complete before its `saveData`. That would leave
  `evaluation_setup_complete` unset, and the student page would say "opened but never completed".

Revisit only if prod shows concurrency problems with the bounded reads.

## Deploy

Merge, then the module reaches prod bundled into `susom/redcap-build` as `lecture_evaluation_v9.9.9`,
followed by a manual Build bump in `redcap-deploy-gcp`. Avoid Fridays 16-19 UTC (the module's peak).
After the deploy, check that `view/student` and `view/evaluation` requests for pid 17231 no longer show
the ~3.9 full-event `redcap_data` reads in Query Insights.

## Follow-ups

- `clerkship_evaluations` (pid 22663, its own repo): the same whole-event student lookup in
  `classes_Student.php` (~L55-59, ~L107-114, ~L137-159).
- Security review items that need a decision: `docs/2026-11-06-security-review.md`.
