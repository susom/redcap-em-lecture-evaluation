<?php

namespace Stanford\LectureEvaluation;

/** @var \Stanford\LectureEvaluation\LectureEvaluation $module */

use \REDCap;

?>
<!doctype html>
<html lang="en">
<head>
    <title>Lectures Evaluation list</title>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css"
          integrity="sha384-Gn5384xqQ1aoWXA+058RXPxPg6fy4IWvTNh0E263XmFcJlSAwiGgFAW/dAiS6JXm" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.19/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" type="text/css"
          href="https://cdn.datatables.net/1.10.15/css/jquery.dataTables.min.css"></link>

    <script src="https://code.jquery.com/jquery-3.3.1.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>

    <!-- DataTable Implementation -->
    <script src="https://cdn.datatables.net/1.10.19/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/1.5.6/js/dataTables.buttons.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"
            integrity="sha384-JZR6Spejh4U02d8jOt6vLEHfe/JQGiRRSQQxSfFWpi1MquVdAyjUar5+76PVCmYl"
            crossorigin="anonymous"></script>
    <style>
        body {
            word-wrap: break-word;
        }
    </style>
</head>
<body>

<div id="app" class="container">
    <div class="row p-1">
        <h3><?php echo $module->getProjectSetting("header_text") ?></h3>
    </div>
    <div class="row p-1">
        <table id="student-table" class="display table table-striped table-bordered">
            <thead>
            <th>Course</th>
            <th>Topic</th>
            <th>Date</th>
            <th>Instructor</th>
            <th>notes</th>
            <th>Evaluate</th>
            </thead>
            <tbody>
            <?php
            $lectures = $module->getLecture()->getCompletedLectures();
            $completed = 0;
            $TBA = 0;
            $student = array_pop($module->getStudent()->getRecord());
            $studentId = $student[$module->getStudent()->getEvent()]['id'];
            if (!empty($lectures)) {
                foreach ($lectures as $id => $lecture) {
                    $notes = '';
                    if ($lecture[$module->getLecture()->getEvent()]['lecture_date'] == '') {
                        $TBA++;
                        continue;
                    }

                    if ($eval = $module->getEvaluation()->isEvaluationComplete($studentId, $id)) {
                        if ($eval['evaluation_setup_complete'] == COMPLETE) {
                            $completed++;
                            continue;
                        }
                        $notes = "You opened this evaluation but never completed it";
                    }
                    ?>
                    <tr>
                        <td><?php echo $lecture[$module->getLecture()->getEvent()]['course'] ?></td>
                        <td><?php echo $lecture[$module->getLecture()->getEvent()]['topic'] ?></td>
                        <td><?php echo date('m/d/Y',
                                strtotime($lecture[$module->getLecture()->getEvent()]['lecture_date'])) ?></td>
                        <td><?php echo $lecture[$module->getLecture()->getEvent()]['instructor'] ?></td>
                        <td><?php echo $notes ?></td>
                        <td>
                            <a href="<?php echo $module->getUrl('view/evaluation.php', true,
                                    false) . '&hash=' . filter_var($_GET['hash'],
                                    FILTER_SANITIZE_STRING) . '&lid=' . $id; ?>" class="btn btn-primary"
                               id="correlated-report-submit">Evaluate
                            </a>
                        </td>
                    </tr>
                    <?php
                }
            }
            ?>
            </tbody>
        </table>
    </div>
    <div class="row p-1">
        <h4>You have completed <?php echo $completed ?> out of <?php echo count($lectures) - $TBA ?></h4>
    </div>
</div>
<script src="<?php echo $module->getUrl('asset/js/student.js') ?>"></script>
</body>
</html>