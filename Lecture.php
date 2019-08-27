<?php

namespace Stanford\LectureEvaluation;

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
        $date = date('Y-m-d H:i:s');
        $param = array(
            'return_format' => 'array',
            'events' => $this->getEvent(),
            'filterLogic' => "[lecture_date] <= '$date'"
        );
        return \REDCap::getData($param);
    }

    private function getLectureRecord($id, $fields = array('id'))
    {
        $params = array(
            'return_format' => 'array',
            'fields' => $fields,
            'events' => $this->getEvent(),
            'filterLogic' => "[id] = '$id'"
        );
        return REDCap::getData($params);
    }

    public function getAllLecturesCount()
    {
        $param = array(
            'return_format' => 'array',
            'events' => $this->getEvent(),
        );
        return count(\REDCap::getData($param));
    }
}