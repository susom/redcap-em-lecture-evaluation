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

//    $array = \Survey::getFollowupSurveyParticipantIdHash($module->project->forms[$module->getEvaluation()->getName()]['survey_id'],
//        $record, $module->getEvaluation()->getEvent(), false, 1);
//    $module->emLog($array);
//
//    $array = \Survey::getFollowupSurveyParticipantIdHash($module->project->forms[$module->getEvaluation()->getName()]['survey_id'],
//        $record, $module->getEvaluation()->getEvent(), false, 1);
//
//    if (!isset($array[1])) {
//        throw  new \LogicException("could not generate evaluation hash please try again later.");
//    };
//    // Return full survey URL
//    $url = APP_PATH_SURVEY_FULL . '?s=' . $array[1];


    $url = REDCap::getSurveyLink($record, $module->getEvaluation()->getName(), $module->getEvaluation()->getEvent());
    $module->emLog("url");
    $module->emLog($url);
    if ($url == '' || is_null($url)) {
        $module->emError($record);
        throw new \LogicException("cant not generate survey URL for record $record");
    }
    $module->redirect($url);
} catch (\LogicException $e) {
    echo $e->getMessage();
}
?>