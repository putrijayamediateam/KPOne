#!/bin/sh
# Samples every 5 s: PostgreSQL connections, ungranted locks, and the CPU and memory of the app and database containers.
# usage: monitor.sh <seconds> <out.csv> <pg container> <db name>
END=$(( $(date +%s) + $1 ))
echo "ts,connections,active,ungranted_locks,xact_commit,pg_cpu,pg_mem_mb,app_cpu,app_mem_mb" > "$2"
while [ "$(date +%s)" -lt "$END" ]; do
  P=$(docker exec "$3" psql -U postgres -d "$4" -Atc "select count(*), count(*) filter (where state='active'), (select count(*) from pg_locks where not granted), (select xact_commit from pg_stat_database where datname='$4') from pg_stat_activity where datname='$4'" | tr '|' ',')
  S=$(docker stats --no-stream --format '{{.Name}},{{.CPUPerc}},{{.MemUsage}}' 2>/dev/null)
  PG=$(echo "$S" | grep "^$3" | cut -d, -f2,3 | sed 's/%//; s/MiB.*//; s/ //g')
  APP=$(echo "$S" | grep -v "^$3" | head -1 | cut -d, -f2,3 | sed 's/%//; s/MiB.*//; s/ //g')
  echo "$(date +%s),$P,$PG,$APP" >> "$2"
  sleep 5
done
