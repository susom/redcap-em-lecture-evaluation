<?php

namespace Stanford\LectureEvaluation;

use REDCap;
use Exception;
/**
 * Class Lecture
 * @package Stanford\LectureEvaluation
 * @property  int $event
 * @property  string $name
 * @property array $record
 * @property array $evaluations
 */
class Evaluation
{
    /** What view/student, view/evaluation and view/students.php need to know about an evaluation. */
    const INDEX_FIELDS = array('id', 'evaluation_lecture_id', 'evaluation_student_id', 'evaluation_setup_complete');

    /** What redcap_survey_complete reads from the submitted evaluation. */
    const RECORD_FIELDS = array('id', 'evaluation_lecture_id', 'evaluation_student_id');

    /** What Lecture::processFeedback aggregates. */
    const FEEDBACK_FIELDS = array('id', 'evaluation_lecture_id', 'comments', 'knowledge_subject',
        'lecture_organization', 'professionalism', 'communication', 'score', 'questions_rec');

    private $event;

    private $name;

    private $record;

    /** @var array|null student id => [lecture id => evaluation]; null until something is loaded */
    private $evaluations = null;

    /** @var bool true once every student's evaluations are in $evaluations (view/students.php) */
    private $allStudentsLoaded = false;

    private $project;

    /** @var array|null record name => true for the evaluation event (null = not loaded) */
    private $data = null;

    public function __construct($eventId)
    {
        try {
            $this->setEvent($eventId);

            $this->setName('evaluation_survey');
        } catch (\LogicException $e) {
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }
    }

    /**
     * @return array
     */
    public function getEvaluations()
    {
        return $this->evaluations;
    }

    /**
     * @param array $evaluations
     */
    public function setEvaluations($evaluations)
    {
        $this->evaluations = $evaluations;
    }


    /**
     * @return array
     */
    public function getRecord()
    {
        return $this->record;
    }

    /**
     * @param array $record
     */
    public function setRecord($id)
    {
        $this->record = $this->getEvaluationRecord($id);
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * @return int
     */
    public function getEvent()
    {
        return $this->event;
    }

    /**
     * @param int $event
     */
    public function setEvent($event)
    {
        $this->event = $event;
    }


    public function getProject()
    {
        if (!$this->project) {
            global $Proj;
            $this->project = $Proj;
        }
        return $this->project;
    }

    public function getData($pid, $event_id)
    {
        if ($this->data === null) {
            // The names of the records already in the evaluation event (name => true), for picking
            // the next free E<n>. One read per request, names only.
            $this->data = Data::recordNames($event_id, \REDCap::getRecordIdField(), 'evaluations:ids');
        }
        return $this->data;
    }
    /**
     * @param $pid
     * @param int $event_id : Pass NULL or '' if CLASSICAL
     * @param string $prefix
     * @param bool $padding
     * @return bool|int|string
     * @throws
     */
    private function getNextId($pid, $event_id = null, $prefix = '', $padding = false, $records = array())
    {
        //Get Project
        $thisProj = $this->getProject();

        $id_field = $thisProj->table_pk;
        //If Classical no event or null is passed
        if (($event_id == '') OR ($event_id == null)) {
            throw new \LogicException("no event found");
        }
        $q = $this->getData($pid, $event_id);
        //$this->emLog($q, "Found records in project $pid using $id_field");
        $i = 1;
        do {
            // Make a padded number
            if ($padding) {
                // make sure we haven't exceeded padding, pad of 2 means
                //$max = 10^$padding;
                $max = 10 ** $padding;
                if ($i >= $max) {
                    return false;
                }
                $id = str_pad($i, $padding, "0", STR_PAD_LEFT);
                //$this->emLog("Padded to $padding for $i is $id");
            } else {
                $id = $i;
            }
            // Add the prefix
            $id = $prefix . $id;
            //$this->emLog("Prefixed id for $i is $id for event_id $event_id and idfield $id_field");
            $i++;

            $skip = false;
            // if there are any ids in skip then skip make sure the next id we do not want it to be
            if (!empty($records) && in_array($id, $records)) {
                $con = true;
            } else {
                $con = isset($q[(string)$id]);
            }
        } while ($con);
        return $id;
    }


    // Create a new survey record for this day
    public function createSurveyRecord($data, $skip = array())
    {
        $recordId = $this->getNextId(PROJECT_ID, $this->getEvent(), 'E', false, $skip);

        // create array to know if record ids is wacked
        while (!self::reserveNewRecordId(PROJECT_ID, $recordId, $this->getEvent())) {
            $skip[] = $recordId;
            $recordId = $this->getNextId(PROJECT_ID, $this->getEvent(), 'E', false, $skip);
        }
        $data['id'] = $recordId;
        $data['redcap_event_name'] = REDCap::getEventNames(true, false, $this->getEvent());
        $response = \REDCap::saveData('json', json_encode(array($data)));
        if (!empty($response['errors'])) {
            throw new \LogicException($response['errors']);
        }
        return array_pop($response['ids']);
    }

    /**
     * This is a function to reserve a record and ensure it is unique.
     * If it returns true, it will 'hold' the reserved new record for 1 hour to be saved to the REDCap data
     * table.  If it is 'false' you must try a different record ID.
     * @param      $project_id
     * @param      $record
     * @param null $event_id
     * @param null $arm_id
     * @return bool
     * @throws \Exception
     */
    public static function reserveNewRecordId($project_id, $record, $event_id = null, $arm_id = null)
    {
        // SET $P AS CURRENT PROJECT
        global $Proj;
        /** @var \Project $P */
        $P = (empty($Proj) || (string)$Proj->project_id !== (string)$project_id) ? new \Project($project_id) : $Proj;
        // GET ARM_ID AND EVENT_ID IF NOT SUPPLIED
        if (empty($arm_id)) {
            if (empty($event_id)) {
                // Missing both event_id and arm_id -- assume first arm_id
                $arm_id = $P->firstArmId;
                $event_id = $P->firstEventId;
            } else {
                // We have an event_id, but not the arm.  Let's retrieve it
                foreach ($P->eventInfo as $p_event_id => $p_armDetail) {
                    if ($p_event_id == $event_id) {
                        $arm_id = $p_armDetail['arm_id'];
                        break;
                    }
                }
            }
        } else {
            // We have the arm
            if (empty($event_id)) {
                // Get event from arm
                $event_id = $P->getFirstEventIdArmId($arm_id);
            }
        }
        if (empty($event_id) || empty($arm_id) || empty($record)) {
            throw new \Exception ("Missing required inputs for reserveRecord");
        }
        // All three steps are parameterized queries on REDCap's connection.
        // STEP 1: CHECK THE NEW_RECORD_CACHE FIRST FOR HIGH-HIT SCENARIOS
        // Is the record in the redcap_new_record_cache
        $q = \ExternalModules\ExternalModules::query(
            "select 1 from redcap_new_record_cache where project_id = ? and record = ?",
            array((int)$project_id, (string)$record));
        if ($q->num_rows > 0) {
            // Already used
            return false;
        }
        // STEP 2: SINCE THE NEW_RECORD_CACHE DOESNT INCLUDE OLDER RECORDS, LETS CHECK THERE TOO:
        // Is the record used in the record list or the project's data table
        $recordListCacheStatus = \Records::getRecordListCacheStatus($project_id);
        ## USE RECORD LIST CACHE (if completed) (requires ARM)
        if ($recordListCacheStatus == 'COMPLETE') {
            $q = \ExternalModules\ExternalModules::query(
                "select 1 from redcap_record_list where project_id = ? and record = ? limit 1",
                array((int)$project_id, (string)$record));
        } ## USE DATA TABLE (the project's own: redcap_data, redcap_data2, ...)
        else {
            $table = REDCap::getDataTable($project_id);
            if (!is_string($table) || !preg_match('/^redcap_data[0-9]*$/', $table)) {
                throw new \Exception("Unexpected data table for project $project_id");
            }
            // exact match: the former "record regexp" also matched E30..E39 when asked about E3
            $q = \ExternalModules\ExternalModules::query(
                "select 1 from `$table` where project_id = ? and field_name = ? and record = ? limit 1",
                array((int)$project_id, (string)$P->table_pk, (string)$record));
        }
        if ($q->num_rows > 0) {
            // Record is used
            return false;
        }
        // STEP 3: LETS TRY TO ADD IT TO THE NEW RECORD CACHE TO ENSURE IT IS STILL UNIQUE
        try {
            \ExternalModules\ExternalModules::query(
                "insert into redcap_new_record_cache (project_id, record, creation_time) values (?, ?, ?)",
                array((int)$project_id, (string)$record, NOW));
            return true;
        } catch (\Exception $e) {
            // Duplicate (another request reserved it first) or other error: try a different id
            return false;
        }
    }

    private function getEvaluationRecord($id)
    {
        $id = Validator::recordId($id);
        if ($id === null) {
            return false;
        }
        $records = Data::get(array(
            'records' => array($id),
            'events' => array($this->getEvent()),
            'fields' => self::RECORD_FIELDS,
        ), 'evaluation:id');
        foreach ($records as $key => $record) {
            if ((string)$key === $id) {
                return array($key => $record);
            }
        }

        return false;
    }

    /**
     * Add one evaluation to the index. When a student has more than one evaluation for a lecture
     * the last one returned wins, as it always has.
     */
    private function addToIndex(array $row)
    {
        $sid = (string)$row['evaluation_student_id'];
        $lid = (string)$row['evaluation_lecture_id'];
        $this->evaluations[$sid][$lid] = $row;
    }

    /**
     * Every evaluation of one student, read once per request and indexed by lecture id.
     *
     * @param string $studentId
     * @return array lecture id => evaluation fields (INDEX_FIELDS)
     */
    private function getStudentIndex($studentId)
    {
        $sid = (string)$studentId;
        if ($this->evaluations === null) {
            $this->evaluations = array();
        }
        if (!array_key_exists($sid, $this->evaluations)) {
            $this->evaluations[$sid] = array();
            // A student that has no valid id (e.g. an unknown link) has no evaluations.
            if (!$this->allStudentsLoaded && Validator::recordId($sid) !== null) {
                $records = Data::lookup($this->getEvent(), 'evaluation_student_id', array($sid),
                    self::INDEX_FIELDS, 'evaluations:student');
                foreach ($records as $record) {
                    $row = $record[$this->getEvent()];
                    if ((string)$row['evaluation_student_id'] === $sid) {
                        $this->addToIndex($row);
                    }
                }
            }
        }
        return $this->evaluations[$sid];
    }

    /**
     * Read every student's evaluations in one query (INDEX_FIELDS only). For view/students.php,
     * which shows every student's counts; per-student reads there would scan the event 230 times.
     */
    public function loadAllStudents()
    {
        if ($this->allStudentsLoaded) {
            return;
        }
        $this->evaluations = array();
        $records = Data::get(array(
            'events' => array($this->getEvent()),
            'fields' => self::INDEX_FIELDS,
        ), 'evaluations:all', true);
        foreach ($records as $record) {
            $this->addToIndex($record[$this->getEvent()]);
        }
        $this->allStudentsLoaded = true;
    }

    /**
     * @return array|false the student's evaluation of the lecture (INDEX_FIELDS), or false
     */
    public function isEvaluationComplete($studentId, $lectureId)
    {
        $index = $this->getStudentIndex($studentId);
        $lid = (string)$lectureId;
        return isset($index[$lid]) ? $index[$lid] : false;
    }

    public function getStudentStates($studentId, $lectures, $lectureEventId)
    {
        $completed = 0;
        $TBA = 0;
        foreach ($lectures as $id => $lecture) {
            if ($eval = $this->isEvaluationComplete($studentId, $id)) {
                if ($eval['evaluation_setup_complete'] == COMPLETE) {
                    $completed++;
                }
            }
            if ($lecture[$lectureEventId]['lecture_date'] == '') {
                $TBA++;
            }
        }
        return array($completed, count($lectures) - $completed - $TBA);
    }

    /**
     * Every evaluation of one lecture, with the fields processFeedback aggregates.
     *
     * @param int|string $lectureId
     * @return array list of evaluation fields (FEEDBACK_FIELDS)
     */
    public function getLectureEvaluations($lectureId)
    {
        $lid = Validator::recordId($lectureId);
        if ($lid === null) {
            return array();
        }
        $records = Data::lookup($this->getEvent(), 'evaluation_lecture_id', array($lid), self::FEEDBACK_FIELDS,
            'evaluations:lecture');
        $result = array();
        foreach ($records as $evaluation) {
            if ((string)$evaluation[$this->getEvent()]['evaluation_lecture_id'] === $lid) {
                $result[] = $evaluation[$this->getEvent()];
            }
        }
        return $result;
    }
}
