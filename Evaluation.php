<?php

namespace Stanford\LectureEvaluation;

use REDCap;

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
    private $event;

    private $name;

    private $record;

    private $evaluations;

    public function __construct($eventId)
    {
        try {
            $this->setEvent($eventId);

            $this->setName('evaluation_survey');
        } catch (\LogicException $e) {
            echo $e->getMessage();
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
        global $Proj;
        if (empty($Proj) || $Proj->project_id !== $pid) {
            $thisProj = new \Project($pid);
        } else {
            $thisProj = $Proj;
        }

        $id_field = $thisProj->table_pk;
        //If Classical no event or null is passed
        if (($event_id == '') OR ($event_id == null)) {
            throw new \LogicException("no event found");
        }
        $q = \REDCap::getData($pid, 'array', null, array($id_field), $event_id);
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
                $con = !empty($q[$id][$event_id][$id_field]);
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
        $P = (empty($Proj) || $Proj->project_id !== $project_id) ? new \Project($Proj) : $Proj;
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
        // STEP 1: CHECK THE NEW_RECORD_CACHE FIRST FOR HIGH-HIT SCENARIOS
        // Is the record in the redcap_new_record_cache
        $sql = sprintf("select 1 from redcap_new_record_cache
            where project_id = %d and arm_id = %d and record = '%s'",
            intval($project_id),
            intval($arm_id),
            db_escape($record)
        );
        $q = db_query($sql);
        if (!$q) {
            throw new Exception("Unable to query redcap_new_record_cache - check your database connectivity");
        }
        if (db_num_rows($q) > 0) {
            // Already used
            return false;
        }
        // STEP 2: SINCE THE NEW_RECORD_CACHE DOESNT INCLUDE OLDER RECORDS, LETS CHECK THERE TOO:
        // Is the record used in the record list or redcap_data
        $recordListCacheStatus = \Records::getRecordListCacheStatus($project_id);
        ## USE RECORD LIST CACHE (if completed) (requires ARM)
        if ($recordListCacheStatus == 'COMPLETE') {
            $sql = sprintf("select 1 from redcap_record_list
                where project_id = %d and record = '%s' limit 1",
                intval($project_id),
                db_escape($record)
            );
        } ## USE DATA TABLE
        else {
            $sql = sprintf("select 1 from redcap_data
                where project_id = %d and field_name = '%s'
                and record regexp '%s' limit 1",
                intval($project_id),
                db_escape($P->table_pk),
                db_escape($record)
            );
        }
        $q = db_query($sql);
        if (!$q) {
            throw new \Exception("Unable to query redcap_data for $record in project $project_id - check your database connectivity and system logs");
        }
        if (db_num_rows($q) > 0) {
            // Record is used
            return false;
        }
        // STEP 3: LETS TRY TO ADD IT TO THE NEW RECORD CACHE TO ENSURE IT IS STILL UNIQUE
        $sql = sprintf("insert into redcap_new_record_cache 
            (project_id, event_id, arm_id, record, creation_time)
            values (%d, %d, %d, '%s', '%s')",
            intval($project_id),
            intval($event_id),
            intval($arm_id),
            db_escape($record),
            db_escape(NOW)
        );
        if (db_query($sql)) {
            // Success
            return true;
        } else {
            // Duplicate or other error
            // TODO: look at error code to differentiate from a lock error vs. another db error
            return false;
        }
    }

    private function getEvaluationRecord($id)
    {
        $param = array(
            'return_format' => 'array',
            'events' => $this->getEvent(),
        );

        $records = \REDCap::getData($param);
        foreach ($records as $key => $record) {
            if ($key == $id) {
                return array($key => $record);
            }
        }


        return false;
    }

    public function isEvaluationComplete($studentId, $lectureId)
    {
        $result = array();
        if (!$this->getEvaluations()) {
            $params = array(
                'return_format' => 'array',
                'events' => $this->getEvent(),
            );
            $records = REDCap::getData($params);

            # cache records to be used later through the loop.
            $this->setEvaluations($records);
        } else {
            $records = $this->getEvaluations();
        }

        foreach ($records as $key => $record) {
            if ($record[$this->getEvent()]['evaluation_lecture_id'] == $lectureId && $record[$this->getEvent()]['evaluation_student_id'] == $studentId) {
                $result = array($key => $records[$key]);
            }
        }


        if (count($result) > 0) {
            $temp = array_pop($result);
            return $temp[$this->getEvent()];
        } else {
            return false;
        }
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
     * @return array
     */
    public function getAllEvaluations()
    {
        if (!$this->getEvaluations()) {
            $params = array(
                'return_format' => 'array',
                'events' => $this->getEvent(),
            );
            $records = REDCap::getData($params);
            $this->setEvaluations($records);
        } else {
            $records = $this->getEvaluations();
        }
        return $records;
    }

    /**
     * @param int $lectureId
     * @return array
     */
    public function getLectureEvaluations($lectureId)
    {
        $evaluations = $this->getAllEvaluations();
        $result = array();
        foreach ($evaluations as $evaluation) {
            if ($lectureId == $evaluation[$this->getEvent()]['evaluation_lecture_id']) {
                $result[] = $evaluation[$this->getEvent()];
            }
        }
        return $result;
    }
}