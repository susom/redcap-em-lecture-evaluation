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
        $data['last_update'] = date('Y-m-d H:i:s');
        $avgScore = 'N/A';
        $lecOrg = 'N/A';
        $knowledge = 'N/A';
        $prof = 'N/A';
        $commSkills = 'N/A';
        $questionRec = 'N/A';
        foreach ($evaluations as $evaluation) {
            if ($evaluation['comments'] != '') {
                $data['student_comments'] .= "* " . $evaluation['comments'] . "\n" . "-------------------------------------------------" . "\n";
            }
            if ($evaluation['knowledge_subject']) {
                $knowledge += $evaluation['knowledge_subject'];
            }
            if ($evaluation['lecture_organization']) {
                $lecOrg += $evaluation['lecture_organization'];
            }

            if ($evaluation['professionalism']) {
                $prof += $evaluation['professionalism'];
            }

            if ($evaluation['communication']) {
                $commSkills += $evaluation['communication'];
            }

            if ($evaluation['score']) {
                $avgScore += $evaluation['score'];
            }

            if ($evaluation['questions_rec']) {
                $questionRec += $evaluation['questions_rec'];
            }
        }

        if ($avgScore != 'N/A') {
            $data['avg_score'] = number_format($avgScore / $data['sample_size'], 2);
        } else {
            $data['avg_score'] = 'N/A';
        }

        if ($lecOrg != 'N/A') {
            $data['avg_lecture_organization'] = number_format($lecOrg / $data['sample_size'], 2);
        } else {
            $data['avg_lecture_organization'] = 'N/A';
        }

        if ($knowledge != 'N/A') {
            $data['avg_knowledge_subject'] = number_format($knowledge / $data['sample_size'], 2);
        } else {
            $data['avg_knowledge_subject'] = 'N/A';
        }

        if ($prof != 'N/A') {
            $data['avg_professionalism'] = number_format($prof / $data['sample_size'], 2);
        } else {
            $data['avg_professionalism'] = 'N/A';
        }

        if ($commSkills != 'N/A') {
            $data['avg_communication'] = number_format($commSkills / $data['sample_size'], 2);
        } else {
            $data['avg_communication'] = 'N/A';
        }

        if ($questionRec != 'N/A') {
            $data['avg_questions_rec'] = number_format($questionRec / $data['sample_size'], 2);
        } else {
            $data['avg_questions_rec'] = 'N/A';
        }

        $this->updateFeedbackForm($data, $projectId, $lectureId);
    }
}