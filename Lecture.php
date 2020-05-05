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
            throw new \LogicException($response['errors']);
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
        $data['sample_size'] = count($evaluations);
        $criteria = array(
            'knowledge_subject',
            'lecture_organization',
            'professionalism',
            'communication',
            'score',
            'questions_rec'
        );
        $counts = array();
        $totals = array();
        $data['last_update'] = date('Y-m-d H:i:s');
        $maxScore = 'N/A';
        $minScore = 'N/A';
        foreach ($evaluations as $evaluation) {
            if ($evaluation['comments'] != '') {
                $data['student_comments'] .= "* " . $evaluation['comments'] . "\n" . "-------------------------------------------------" . "\n";
            }

            foreach ($criteria as $item) {
                if ($evaluation[$item]) {

                    // special case to get max and min scores
                    if ($item == 'score') {
                        if (!is_numeric($maxScore) || $evaluation[$item] > $maxScore) {
                            $maxScore = $evaluation[$item];
                        }

                        if (!is_numeric($minScore) || $evaluation[$item] < $minScore) {
                            $minScore = $evaluation[$item];
                        }
                    }

                    $totals[$item] += number_format($evaluation[$item], 2);
                    $counts[$item]++;
                }
            }
        }

        foreach ($criteria as $item) {
            if ($totals[$item]) {
                $data['avg_' . $item] = number_format((float)$totals[$item] / $counts[$item], 2);
            } else {
                $data['avg_' . $item] = 'N/A';
            }
        }

        $data['range'] = $minScore . '–' . $maxScore;

        $this->updateFeedbackForm($data, $projectId, $lectureId);
    }
}