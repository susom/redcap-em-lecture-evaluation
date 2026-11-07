<?php

namespace Stanford\LectureEvaluation;

/** @var \Stanford\LectureEvaluation\LectureEvaluation $module */

use \REDCap;

try {
    // The lecture and the student come from the link (lid, hash), validated and read once by the module.
    $lecture = $module->getLecture()->getRecord();
    $lectureId = $lecture ? (string)key($lecture) : null;
    $records = $module->getStudent()->getRecord();
    $student = array_pop($records);
    if ($lectureId === null || empty($student)) {
        // Unknown or malformed link: never create an evaluation record for it.
        throw new \LogicException("This evaluation link is not valid.");
    }
    $module->emLog("Lecture ID " . $lectureId);
    $lecture = $lecture[$lectureId][$module->getLecture()->getEvent()];
    //keep only lecture fields in $data
    $data = array();
    $lectureFields = \REDCap::getFieldNames('lecture');
    foreach ($lectureFields as $field) {
        $data[$field] = $lecture[$field];
    }

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
    // never log $url: its s= value opens the survey
    if ($url == '' || is_null($url)) {
        $module->emError("could not generate survey URL for record " . $record);
        throw new \LogicException("cant not generate survey URL for record $record");
    }
    $module->redirect($url);
} catch (\LogicException $e) {
    ?>
<!doctype html>
<html lang="en">
<head>
    <title>Lecture Evaluation</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { margin: 0; padding: 24px 16px; font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #212529; background: #fff; }
        p { max-width: 40rem; margin: 0 auto; }
    </style>
</head>
<body><p><?php echo htmlspecialchars($e->getMessage(), ENT_QUOTES) ?></p></body>
</html>
<?php
}
?>