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
    /** What view/student renders and filters on, plus the mapping used to pick lectures. */
    const LIST_FIELDS = array('id', 'course', 'topic', 'lecture_date', 'instructor', 'lecture_student_mapping');

    private $event;

    private $record = array();

    public function __construct($eventId)
    {
        try {
            $this->setEvent($eventId);
        } catch (\LogicException $e) {
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES);
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
     * Load one lecture by record id, with every field on the `lecture` form: view/evaluation copies
     * all of them into the new evaluation record.
     *
     * @param string $id
     */
    public function setRecord($id)
    {
        $fields = array('id', 'lecture_date', 'instructor', 'topic', 'course');
        $formFields = REDCap::getFieldNames('lecture');
        if (is_array($formFields)) {
            $fields = array_values(array_unique(array_merge($fields, $formFields)));
        }
        $this->record = $this->getLectureRecord($id, $fields);
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
     * Lectures whose date is today or earlier (or blank, which the pages count as TBA), with only
     * the fields the pages render.
     *
     * @param string[]|null $mappingCodes the student's checked lecture_student_mapping codes: only
     *        lectures sharing one of them are read (view/student skips every other lecture anyway).
     *        null reads every lecture, for the admin view/students.php.
     * @return array
     */
    public function getCompletedLectures($mappingCodes = null)
    {
        if ($mappingCodes === null) {
            $records = Data::get(array(
                'events' => array($this->getEvent()),
                'fields' => self::LIST_FIELDS,
            ), 'lectures:all', true);
        } else {
            // a checked checkbox is stored as one row per code, value = the code
            $codes = array_values(array_filter(array_map('strval', $mappingCodes), function ($code) {
                return (bool)preg_match(Validator::CHECKBOX_CODE_PATTERN, $code);
            }));
            $records = Data::lookup($this->getEvent(), 'lecture_student_mapping', $codes, self::LIST_FIELDS,
                'lectures:mapped');
        }

        # the date test stays in PHP, exactly as before
        $date = date('Y-m-d H:i:s');
        $result = array();
        foreach ($records as $key => $record) {
            if ($record[$this->getEvent()]['lecture_date'] <= $date) {
                $result[$key] = $records[$key];
            }
        }
        return $result;
    }

    private function getLectureRecord($id, $fields = array('id'))
    {
        $id = Validator::recordId($id);
        if ($id === null) {
            return array();
        }
        $records = Data::get(array(
            'records' => array($id),
            'events' => array($this->getEvent()),
            'fields' => $fields,
        ), 'lecture:id');
        foreach ($records as $key => $record) {
            if ((string)$key === $id) {
                return array($key => $record);
            }
        }

        return array();
    }

    /**
     * Admin view/students.php only.
     */
    public function getAllLecturesCount()
    {
        return count(Data::get(array(
            'events' => array($this->getEvent()),
            'fields' => array(REDCap::getRecordIdField()),
        ), 'lectures:count', true));
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