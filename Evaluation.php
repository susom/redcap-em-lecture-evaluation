<?php

namespace Stanford\LectureEvaluation;

use REDCap;

/**
 * Class Lecture
 * @package Stanford\LectureEvaluation
 * @property  int $event
 * @property  string $name
 * @property array $record
 */
class Evaluation
{
    private $event;

    private $name;

    private $record;

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
    private function getNextId($pid, $event_id = null, $prefix = '', $padding = false)
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
        } while (!empty($q[$id][$event_id][$id_field]));
        return $id;
    }

    // Create a new survey record for this day
    public function createSurveyRecord($data)
    {
        $data['id'] = $this->getNextId(PROJECT_ID, $this->getEvent(), 'E');
        $data['redcap_event_name'] = REDCap::getEventNames(true, false, $this->getEvent());
        $response = \REDCap::saveData('json', json_encode(array($data)));
        if (!empty($response['errors'])) {
            throw new \LogicException(implode(",", $response['errors']));
        }
        return array_pop($response['ids']);
    }

    private function getEvaluationRecord($id)
    {
        $params = array(
            'return_format' => 'array',
            'events' => $this->getEvent(),
            'filterLogic' => "[id] = '$id'"
        );
        return REDCap::getData($params);
    }

    public function isEvaluationComplete($studentId, $lectureId)
    {
        $params = array(
            'return_format' => 'array',
            'events' => $this->getEvent(),
            'filterLogic' => "[evaluation_lecture_id] = '$lectureId' AND [evaluation_student_id] = '$studentId'"
        );
        $result = REDCap::getData($params);

        if (count($result) > 0) {
            $temp = array_pop($result);
            return $temp[$this->getEvent()];
        } else {
            return false;
        }
    }
}