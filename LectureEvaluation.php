<?php

namespace Stanford\LectureEvaluation;

use REDCap;

include_once("Student.php");
include_once("Lecture.php");
include_once("Evaluation.php");
require_once "emLoggerTrait.php";


define('ACTIVE', 1);
define('INACTIVE', 0);

define('INCOMPLETE', 0);
define('UNVERIFIED', 1);
define('COMPLETE', 2);


define('INSTRUCTOR_ACTIONS_POST_CHECKBOX_1', '__chk__instructor_actions_RC_1');
define('INSTRUCTOR_ACTIONS___1', 'instructor_actions___1');
define('INSTRUCTOR_ACTIONS_POST_CHECKBOX_2', '__chk__instructor_actions_RC_2');
define('INSTRUCTOR_ACTIONS___2', 'instructor_actions___2');

/**
 * Class LectureEvaluation
 * @package Stanford\LectureEvaluation
 * @property \Stanford\LectureEvaluation\Student $student
 * @property \Stanford\LectureEvaluation\Lecture $lecture
 * @property \Stanford\LectureEvaluation\Evaluation $evaluation
 */
class LectureEvaluation extends \ExternalModules\AbstractExternalModule
{


    use emLoggerTrait;

    private $student;

    private $lecture;

    private $evaluation;

    public function __construct()
    {
        try {
            parent::__construct();

            if ($_GET && $_GET['pid'] != null) {
                //TODO main initiation

                /**
                 * init student with its event id
                 */
                $this->setStudent(new Student($this->getProjectSetting("students")));

                /**
                 * load the actual student information
                 */
                if (isset($_GET['hash'])) {
                    $this->getStudent()->setRecord(filter_var($_GET['hash'], FILTER_SANITIZE_STRING));
                }

                /**
                 * init lecture with its event id
                 */
                $this->setLecture(new Lecture($this->getProjectSetting("lectures")));

                if (isset($_GET['lid'])) {
                    $this->getLecture()->setRecord(filter_var($_GET['lid'], FILTER_SANITIZE_STRING));
                }

                /**
                 * init evaluation with its event id
                 */
                $this->setEvaluation(new Evaluation($this->getProjectSetting("evaluations")));
            }

        } catch (\LogicException $e) {

        }
    }

    /**
     * @return Evaluation
     */
    public function getEvaluation()
    {
        return $this->evaluation;
    }

    /**
     * @param Evaluation $evaluation
     */
    public function setEvaluation($evaluation)
    {
        $this->evaluation = $evaluation;
    }

    /**
     * @return Lecture
     */
    public function getLecture()
    {
        return $this->lecture;
    }

    /**
     * @param Lecture $lecture
     */
    public function setLecture($lecture)
    {
        $this->lecture = $lecture;
    }

    /**
     * @return Student
     */
    public function getStudent()
    {
        return $this->student;
    }

    /**
     * @param Student $student
     */
    public function setStudent($student)
    {
        $this->student = $student;
    }


    public function redcap_every_page_before_render()
    {
        if ($_POST && isset($_POST['updaterecs']) && $_POST['updaterecs'] == "Import Data") {
            register_shutdown_function(array($this, 'createStudentUrl'));
        }
    }

    public function createStudentUrl()
    {
        try {
            $students = $this->getStudent()->getAllStudent();
            foreach ($students as $id => $student) {
                if ($student[$this->getStudent()->getEvent()]['hash'] == '') {
                    $data['id'] = $id;
                    $data['active'] = ACTIVE;
                    $data['hash'] = $this->getStudent()->generateHash();
                    $data['student_url'] = $this->generateURL($data['hash']);
                    $data['redcap_event_name'] = REDCap::getEventNames(true, false, $this->getStudent()->getEvent());
                    $response = \REDCap::saveData('json', json_encode(array($data)));
                    if (!empty($response['errors'])) {
                        throw new \LogicException(implode(",", $response['errors']));
                    }
                }
            }
        } catch (\LogicException $e) {
            echo $e->getMessage();
        }
    }

    private function generateURL($hash)
    {
        return $this->getUrl('view/student.php', true, true) . '&hash=' . $hash;
    }

    public function redirect($url)
    {
        ob_start();
        header('Location: ' . $url);
        ob_end_flush();
    }

    public function redcap_survey_complete($project_id, $record)
    {
        if (!is_null($record)) {
            $this->getEvaluation()->setRecord($record);
            $eval = $this->getEvaluation()->getRecord();
            $sid = $eval[$record][$this->getEvaluation()->getEvent()]['evaluation_student_id'];

            $data['id'] = $record;
            $data['lecture_complete'] = COMPLETE;
            $data['evaluation_setup_complete'] = COMPLETE;
            $data['redcap_event_name'] = REDCap::getEventNames(true, false, $this->getEvaluation()->getEvent());
            $response = \REDCap::saveData('json', json_encode(array($data)));
            if (!empty($response['errors'])) {
                throw new \LogicException(implode(",", $response['errors']));
            }

            $this->getStudent()->setRecord($sid, 'id');
            $student = $this->getStudent()->getRecord();

            $this->redirect($this->generateURL($student[$sid][$this->getStudent()->getEvent()]['hash']));
            $this->exitAfterHook();
        }
    }

    public function redcap_save_record($project_id, $record, $instrument, $event_id)
    {
        if ($instrument == "student") {
            $this->getStudent()->setRecord($record, 'id');
            $student = array_pop($this->getStudent()->getRecord());
            if ($student[$this->getStudent()->getEvent()]['hash'] == '') {
                $data['hash'] = $this->getStudent()->generateHash();
            } else {
                $data['hash'] = $student[$this->getStudent()->getEvent()]['hash'];
            }

            $data['id'] = $record;
            $data['student_url'] = $this->generateURL($data['hash']);
            $data['redcap_event_name'] = REDCap::getEventNames(true, false, $this->getStudent()->getEvent());
            $response = \REDCap::saveData('json', json_encode(array($data)));
            if (!empty($response['errors'])) {
                throw new \LogicException(implode(",", $response['errors']));
            }
        }
        if ($instrument == "instructor_feedback") {

            if ($_POST[INSTRUCTOR_ACTIONS_POST_CHECKBOX_2] == "2") {
                $this->getLecture()->processFeedback($record, $this->getEvaluation(), $this->getProjectId());
            }

        }
    }

    public function isStudentMappedToLecture($student, $lecture)
    {
        //make sure student is mapped to this lecture
        $studentMap = $student[$this->getStudent()->getEvent()]['lecture_student_mapping'];
        $lectureMap = $lecture[$this->getLecture()->getEvent()]['lecture_student_mapping'];
        foreach ($studentMap as $value => $row) {
            if ($row == "0") {
                continue;
            } else {
                if ($lectureMap[$value] == "1") {
                    return true;
                }
            }
        }
        return false;
    }
}