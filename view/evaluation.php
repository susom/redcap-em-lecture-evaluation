<?php

namespace Stanford\LectureEvaluation;

/** @var \Stanford\LectureEvaluation\LectureEvaluation $module */

use \REDCap;

try {
    $lectureId = filter_var($_GET['lid'], FILTER_SANITIZE_STRING);
    $hash = filter_var($_GET['hash'], FILTER_SANITIZE_STRING);
    $module->emLog("Lecture ID" . $lectureId);
    $lecture = $module->getLecture()->getRecord();
    $lecture = $lecture[$lectureId][$module->getLecture()->getEvent()];
    //keep only lecture fields in $data
    $lectureFields = \REDCap::getFieldNames('lecture');
    foreach ($lectureFields as $field) {
        $data[$field] = $lecture[$field];
    }
    $module->emLog("Lecture");
    $module->emLog($lecture);

    $student = array_pop($module->getStudent()->getRecord());

    $module->emLog("student");
    $module->emLog($student);
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

    $module->emLog("record");
    $module->emLog($record);

    $module->emLog("instrument evaluation");
    $module->emLog($module->getEvaluation()->getName());

    $module->emLog("event evaluation");
    $module->emLog($module->getEvaluation()->getEvent());

    $array = \Survey::getFollowupSurveyParticipantIdHash($module->project->forms[$module->getEvaluation()->getName()]['survey_id'],
        $record, $module->getEvaluation()->getEvent(), false, 1);
    $module->emLog($array);
    $url = REDCap::getSurveyLink($record, $module->getEvaluation()->getName(), $module->getEvaluation()->getEvent());
    $module->emLog("url");
    $module->emLog($url);
    $module->redirect($url);
} catch (\LogicException $e) {
    echo $e->getMessage();
}
?>