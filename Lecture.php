<?php

namespace Stanford\LectureEvaluation;

use Kigkonsult\Icalcreator\Standard;
use REDCap;

/**
 * Class Lecture
 * @package Stanford\LectureEvaluation
 * @property  int $event
 * @property  array $record
 */
class Lecture
{
    private $event;

    private $record;

    public function __construct($eventId)
    {
        try {
            $this->setEvent($eventId);
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
        $this->record = $this->getLectureRecord($id, array('id', 'lecture_date', 'instructor', 'topic', 'course'));
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
     * @return mixed
     */
    public function getCompletedLectures()
    {
        # do filter logic on the foreach loop instead of getData
        $date = date('Y-m-d H:i:s');
        $param = array(
            'return_format' => 'array',
            'events' => $this->getEvent()
        );
        $result = array();
        $records = \REDCap::getData($param);
        foreach ($records as $key => $record) {
            if ($record[$this->getEvent()]['lecture_date'] <= $date) {
                $result[$key] = $records[$key];
            }
        }
        return $result;
    }

    private function getLectureRecord($id, $fields = array('id'))
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

    public function getAllLecturesCount()
    {
        $param = array(
            'return_format' => 'array',
            'events' => $this->getEvent(),
        );
        return count(\REDCap::getData($param));
    }

    /**
     * @param array $data
     * @param int $projectId
     * @return bool
     */
    public function updateFeedbackForm($data, $projectId, $record)
    {

        $data[INSTRUCTOR_ACTIONS___2] = "0";
        $data[REDCap::getRecordIdField()] = $record;
        $data['redcap_event_name'] = REDCap::getEventNames(true, false, $this->getEvent());

        $response = \REDCap::saveData($projectId, 'json', json_encode(array($data)));

        if (!empty($response['errors'])) {
            throw new \LogicException(implode(",", $response['errors']));
        }
        return false;
    }


    /**
     * @param int $lectureId
     * @param Evaluation $evaluation
     * @param int $projectId
     */
    public function processFeedback($lectureId, $evaluation, $projectId)
    {

        $evaluations = $evaluation->getLectureEvaluations($lectureId);
        $data = array();
        $data['number_of_evaluations'] = count($evaluations);
        $data['last_update'] = date('Y-m-d H:i:s');
        foreach ($evaluations as $evaluation) {
            if ($evaluation['comments'] != '') {
                $data['students_comments'] .= "* " . $evaluation['comments'] . "\n" . "-------------------------------------------------" . "\n";
                $data['number_of_comment']++;
            }
        }
        $this->updateFeedbackForm($data, $projectId, $lectureId);
    }
}