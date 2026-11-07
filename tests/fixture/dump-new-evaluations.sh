#!/bin/sh
# Prints the evaluation records an E2E run created on the synthetic fixture (ids above the seeded
# E<n>), keyed by lecture so two runs can be diffed regardless of which tab won which id.
# evaluation_date is a timestamp and is left out. Usage: dump-new-evaluations.sh <pid> <event_id> <seeded_count>
PID=$1; EV=$2; N=$3
DT=$(docker exec redcap_2023_1_db sh -c "mysql -uredcap -p\"\$MYSQL_PASSWORD\" redcap -N -e \"SELECT IFNULL(data_table,'redcap_data') FROM redcap_projects WHERE project_id=$PID\"" 2>/dev/null)
case "$DT" in redcap_data|redcap_data[0-9]*) ;; *) echo "no data table for pid $PID" >&2; exit 1;; esac
docker exec redcap_2023_1_db sh -c "mysql -uredcap -p\"\$MYSQL_PASSWORD\" redcap -N -e \"
SELECT CONCAT(l.value, ' | ', d.field_name, ' = ', REPLACE(d.value, '\n', ' '))
FROM $DT d JOIN $DT l ON l.project_id=d.project_id AND l.event_id=d.event_id AND l.record=d.record AND l.field_name='evaluation_lecture_id'
WHERE d.project_id=$PID AND d.event_id=$EV AND CAST(SUBSTRING(d.record,2) AS UNSIGNED) > $N AND d.field_name NOT IN ('evaluation_date')
ORDER BY l.value, d.field_name, d.value;
SELECT CONCAT('ids: ', COUNT(DISTINCT record), ' min=', MIN(CAST(SUBSTRING(record,2) AS UNSIGNED)), ' max=', MAX(CAST(SUBSTRING(record,2) AS UNSIGNED)), ' all_E_prefixed=', SUM(record NOT REGEXP '^E[0-9]+\\\$')=0)
FROM (SELECT DISTINCT record FROM $DT WHERE project_id=$PID AND event_id=$EV AND CAST(SUBSTRING(record,2) AS UNSIGNED) > $N) t;
\"" 2>&1 | grep -v Warning
