<?php

namespace Stanford\LectureEvaluation;

/** @var \Stanford\LectureEvaluation\LectureEvaluation $module */

use \REDCap;

try {
    $lectureId = filter_var($_GET['lid'], FILTER_SANITIZE_STRING);
    $hash = filter_var($_GET['hash'], FILTER_SANITIZE_STRING);

    $lecture = $module->getLecture()->getRecord();
    $data = $lecture[$lectureId][$module->getLecture()->getEvent()];
    $x = array_pop($module->getStudent()->getRecord());;
    $student = array_pop($module->getStudent()->getRecord());
    $data['evaluation_lecture_id'] = $data['id'];
    $data['evaluation_student_id'] = $student[$module->getStudent()->getEvent()]['id'];
    $data['evaluation_date'] = date('Y-m-d H:i:s');

    //if student hit the survey before then just load the URL
    $record = $module->getEvaluation()->isEvaluationComplete($data['evaluation_student_id'],
        $data['evaluation_lecture_id']);
    if ($record == false) {
        $record = $module->getEvaluation()->createSurveyRecord($data);
    } else {
        $record = $record['id'];
    }


    $url = REDCap::getSurveyLink($record, $module->getEvaluation()->getName(), $module->getEvaluation()->getEvent());
    $module->redirect($url);
} catch (\LogicException $e) {
    echo $e->getMessage();
}
?>