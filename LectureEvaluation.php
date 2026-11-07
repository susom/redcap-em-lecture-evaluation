<?php

namespace Stanford\LectureEvaluation;

use REDCap;

include_once("Validator.php");
include_once("Data.php");
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

    public $project;
    public function __construct()
    {
        try {
            parent::__construct();

            // Nothing is read here. The student, lecture and evaluation objects are built on first use
            // (getStudent(), getLecture(), getEvaluation()), so a request only reads what it renders.
            if (isset($_GET['pid']) && Validator::pid($_GET['pid']) !== null) {
                global $Proj;
                $this->project = $Proj;
            }
        } catch (\LogicException $e) {

        }
    }

    /**
     * @return Evaluation
     */
    public function getEvaluation()
    {
        if ($this->evaluation === null) {
            $this->evaluation = new Evaluation($this->getProjectSetting("evaluations"));
        }
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
        if ($this->lecture === null) {
            $this->lecture = new Lecture($this->getProjectSetting("lectures"));
            // the lecture named in the link (view/evaluation)
            if (isset($_GET['lid'])) {
                $this->lecture->setRecord(Validator::recordId($_GET['lid']));
            }
        }
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
        if ($this->student === null) {
            $this->student = new Student($this->getProjectSetting("students"));
            // the student named in the link (view/student, view/evaluation); a malformed hash is
            // treated like an unknown one and reads nothing
            if (isset($_GET['hash'])) {
                $this->student->setRecord(Validator::hash($_GET['hash']));
            }
        }
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
            // one read of every student's id and hash; it also seeds generateHash()'s set of used hashes
            $students = $this->getStudent()->getAllStudent(array('id', 'hash'));
            $this->getStudent()->primeExistingHashes($students);
            foreach ($students as $id => $student) {
                $data = array();
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
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES);
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

            $data = array();
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

            $hash = isset($student[$sid]) ? $student[$sid][$this->getStudent()->getEvent()]['hash'] : null;
            $this->redirect($this->generateURL($hash));
            $this->exitAfterHook();
        }
    }

    public function redcap_save_record($project_id, $record, $instrument, $event_id)
    {
        if ($instrument == "student") {
            $this->getStudent()->setRecord($record, 'id');
            $records = $this->getStudent()->getRecord();
            $student = array_pop($records);
            $data = array();
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

    /**
     * @param array|null $student one student's [event_id => fields]
     * @return string[] the lecture_student_mapping codes the student has checked
     */
    public function getStudentMappingCodes($student)
    {
        $codes = array();
        $map = isset($student[$this->getStudent()->getEvent()]['lecture_student_mapping'])
            ? $student[$this->getStudent()->getEvent()]['lecture_student_mapping'] : array();
        if (is_array($map)) {
            foreach ($map as $code => $checked) {
                if ($checked != "0") {
                    $codes[] = (string)$code;
                }
            }
        }
        return $codes;
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