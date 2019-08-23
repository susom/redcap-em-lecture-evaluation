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
        <h3>Students List</h3>
    </div>
    <div class="row p-1">
        <table id="student-table" class="display table table-striped table-bordered">
            <thead>
            <th>First Name</th>
            <th>Last Name</th>
            <th>Email</th>
            <th>Number of Completed Evaluations</th>
            <th>Number of Pending Evaluations</th>
            <th>Number of Future Evaluations</th>
            <th>URL</th>
            </thead>
            <tbody>
            <?php
            $students = $module->getStudent()->getAllStudent();
            $lectures = $module->getLecture()->getCompletedLectures();
            $count = $module->getLecture()->getAllLecturesCount();
            if (!empty($students)) {
                foreach ($students as $id => $student) {
                    list($completed, $todo) = $module->getEvaluation()->getStudentStates($id, $lectures,
                        $module->getLecture()->getEvent());
                    ?>
                    <tr>
                        <td><?php echo $student[$module->getStudent()->getEvent()]['first_name'] ?></td>
                        <td><?php echo $student[$module->getStudent()->getEvent()]['last_name'] ?></td>
                        <td>
                            <a href="mailto:<?php echo $student[$module->getStudent()->getEvent()]['email'] ?>"><?php echo $student[$module->getStudent()->getEvent()]['email'] ?></a>
                        </td>
                        <td><?php echo $completed ?></td>
                        <td><?php echo $todo ?></td>
                        <td><?php echo $count - ($completed + $todo) ?></td>
                        <td><a href="<?php echo $student[$module->getStudent()->getEvent()]['student_url'] ?>"
                               target="_blank"><?php echo $student[$module->getStudent()->getEvent()]['student_url'] ?></a>
                        </td>
                    </tr>
                    <?php
                }
            }
            ?>
            </tbody>
        </table>
    </div>
</div>
<script src="<?php echo $module->getUrl('asset/js/student.js') ?>"></script>
</body>
</html>