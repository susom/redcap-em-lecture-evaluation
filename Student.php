<?php

namespace Stanford\LectureEvaluation;

use REDCap;

/**
 * Class Student
 * @package Stanford\LectureEvaluation
 * @property int $event
 * @property array $record
 */
class Student
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
    public function setRecord($identifier, $field = 'hash')
    {
        $this->record = $this->getStudentViaHash($identifier, $field,
            array());;
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


    public function getAllStudent()
    {
        $param = array(
            'return_format' => 'array',
            'events' => $this->getEvent()
        );
        return REDCap::getData($param);
    }

    public function generateHash()
    {
        //$url_field   = $this->getProjectSetting('personal-url-fields');  // won't work with sub_settings

        $i = 0;
        do {
            $new_hash = generateRandomHash(8, false, true, false);

            $q = $this->getStudentViaHash($new_hash);
            $i++;
        } while (count($q) > 0 AND $i < 10); //keep generating until nothing returns from get

        return $new_hash;
    }

    public function getURL($hash)
    {
        $record = $this->getStudentViaHash($hash, 'hash', array('student_url'));
        return $record[0][$this->getEvent()];
    }

    private function getStudentViaHash($identifier, $field = 'hash', $fields = array('hash'))
    {
        # check if fields are requested or not
        if (!empty($fields)) {
            $params = array(
                'return_format' => 'array',
                'fields' => $fields,
                'events' => $this->getEvent(),
            );
        } else {
            $params = array(
                'return_format' => 'array',
                'events' => $this->getEvent(),
            );
        }
        $records = REDCap::getData($params);;
        foreach ($records as $key => $record) {
            if ($record[$this->getEvent()][$field] == $identifier) {
                return array($key => $records[$key]);
            }
        }
        return false;
    }
}