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
    /** Every student field a request reads: view/student, view/evaluation and the two hooks. */
    const FIELDS = array('id', 'hash', 'active', 'student_url', 'lecture_student_mapping');

    /** What view/students.php lists. */
    const LIST_FIELDS = array('id', 'first_name', 'last_name', 'email', 'student_url');

    private $event;

    private $record = array();

    /** @var array|null hash => true for every student, loaded once (null = not loaded yet) */
    private $existingHashes = null;

    public function __construct($eventId)
    {
        try {
            $this->setEvent($eventId);
        } catch (\LogicException $e) {
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }
    }

    /**
     * @return array [record_id => [event_id => fields]], or [] when no student matched
     */
    public function getRecord()
    {
        return $this->record;
    }

    /**
     * Load one student, by hash (the student's link) or by record id.
     *
     * A hash that is not in the format generateHash() produces is treated exactly like a hash that
     * matches nobody: no query runs and the record is empty.
     *
     * @param string $identifier
     * @param string $field 'hash' or 'id'
     */
    public function setRecord($identifier, $field = 'hash')
    {
        $this->record = ($field === 'id')
            ? $this->getStudentById($identifier)
            : $this->getStudentViaHash($identifier);
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
     * Every student, with the given fields only. Admin paths only (view/students.php and the
     * post-import hash generation): this is the one project-wide student read.
     *
     * @param string[] $fields
     * @return array
     */
    public function getAllStudent($fields = self::LIST_FIELDS)
    {
        return Data::get(array(
            'events' => array($this->getEvent()),
            'fields' => $fields,
        ), 'students:all', true);
    }

    public function generateHash()
    {
        $existing = $this->getExistingHashes();
        $i = 0;
        do {
            $new_hash = generateRandomHash(8, false, true, false);
            $i++;
        } while (isset($existing[$new_hash]) && $i < 10); //keep generating until the hash is unused

        $this->existingHashes[$new_hash] = true;
        return $new_hash;
    }

    /**
     * @return array hash => true, for every student that has one. Loaded once per request.
     */
    private function getExistingHashes()
    {
        if ($this->existingHashes === null) {
            $this->existingHashes = array();
            foreach ($this->getAllStudent(array('id', 'hash')) as $record) {
                $hash = isset($record[$this->getEvent()]['hash']) ? (string)$record[$this->getEvent()]['hash'] : '';
                if ($hash !== '') {
                    $this->existingHashes[$hash] = true;
                }
            }
        }
        return $this->existingHashes;
    }

    /**
     * @param array $students getAllStudent() output; lets a caller that already read every student
     *                        seed the hash set instead of reading the students again
     */
    public function primeExistingHashes(array $students)
    {
        $this->existingHashes = array();
        foreach ($students as $record) {
            $hash = isset($record[$this->getEvent()]['hash']) ? (string)$record[$this->getEvent()]['hash'] : '';
            if ($hash !== '') {
                $this->existingHashes[$hash] = true;
            }
        }
    }

    public function getURL($hash)
    {
        $record = $this->getStudentViaHash($hash);
        $record = array_pop($record);
        return $record ? $record[$this->getEvent()]['student_url'] : null;
    }

    private function getStudentViaHash($identifier)
    {
        $hash = Validator::hash($identifier);
        if ($hash === null) {
            return array();
        }
        $records = Data::lookup($this->getEvent(), 'hash', array($hash), self::FIELDS, 'student:hash');
        // the SQL match ignores case; confirm the match exactly
        foreach ($records as $key => $record) {
            if (isset($record[$this->getEvent()]['hash']) && (string)$record[$this->getEvent()]['hash'] === $hash) {
                return array($key => $record);
            }
        }
        return array();
    }

    private function getStudentById($identifier)
    {
        $id = Validator::recordId($identifier);
        if ($id === null) {
            return array();
        }
        $records = Data::get(array(
            'records' => array($id),
            'events' => array($this->getEvent()),
            'fields' => self::FIELDS,
        ), 'student:id');
        foreach ($records as $key => $record) {
            if ((string)$key === $id) {
                return array($key => $record);
            }
        }
        return array();
    }
}
