#!/usr/bin/env bash
# enhance-usage-logger.sh  (v4 - adds swap, IOPS, nproc, concurrent connections)
# Discovers cgroups via /sys/fs/cgroup/websites/<uuid>, mapped to unix users
# through cgroup.procs -> process owner (not by name-guessing).
# Logs per-website CPU / Memory / Swap / IO / IOPS / nproc / connections /
# throttle / OOM-kill counters from cgroup v2.
#
# Run this on EVERY Application server (where website containers actually execute) -
# not the master/panel-only node, unless that node also hosts websites.
#
# First run manually with: bash -x enhance-usage-logger.sh
# to confirm the cgroup path discovery finds your local sites correctly.

set -uo pipefail

LOG_DIR="/var/log/enhance-usage"
STATE_DIR="/var/lib/enhance-usage"
CACHE_FILE="${STATE_DIR}/cgroup-map.tsv"
WEBSITES_ROOT="/sys/fs/cgroup/websites"
mkdir -p "$LOG_DIR" "$STATE_DIR"

TS_EPOCH=$(date +%s)
TS_HUMAN=$(date -Iseconds)

if [ ! -d "$WEBSITES_ROOT" ]; then
  echo "ERROR: ${WEBSITES_ROOT} not found - is this an Enhance Application server?" >&2
  exit 1
fi

# 1. Build a fresh uuid -> unix_user map for this run by inspecting which
#    process(es) live in each website cgroup right now. Also remember every
#    PID in each cgroup so we can tally concurrent connections per site.
declare -A PID_OWNER
: > "${CACHE_FILE}.tmp"
for CGPATH in "$WEBSITES_ROOT"/*/; do
  CGPATH="${CGPATH%/}"
  UUID=$(basename "$CGPATH")
  [ -f "$CGPATH/cgroup.procs" ] || continue

  UNIX_USER=""
  while read -r PID; do
    [ -z "$PID" ] && continue
    U=$(ps -o user= -p "$PID" 2>/dev/null | tr -d '[:space:]')
    if [ -n "$U" ] && [ "$U" != "root" ]; then
      UNIX_USER="$U"
    fi
    [ -n "$UNIX_USER" ] && PID_OWNER["$PID"]="$UNIX_USER"
  done < "$CGPATH/cgroup.procs"

  if [ -z "$UNIX_USER" ]; then
    echo "WARN: could not determine owner for cgroup ${UUID} (no running procs?) - skipping" >&2
    continue
  fi

  echo -e "${UNIX_USER}\t${CGPATH}\t${UUID}" >> "${CACHE_FILE}.tmp"
done
mv "${CACHE_FILE}.tmp" "$CACHE_FILE"

if [ ! -s "$CACHE_FILE" ]; then
  echo "ERROR: no website cgroups resolved to an owner - nothing to log" >&2
  exit 1
fi

# 2. Tally current ESTABLISHED TCP connections per unix user, in one pass
#    (avoids running ss once per site).
declare -A CONN_COUNT
while read -r PID; do
  [ -z "$PID" ] && continue
  OWNER="${PID_OWNER[$PID]:-}"
  [ -z "$OWNER" ] && continue
  CONN_COUNT["$OWNER"]=$(( ${CONN_COUNT["$OWNER"]:-0} + 1 ))
done < <(ss -tnp state established 2>/dev/null | grep -oP 'pid=\K[0-9]+')

# 3. For each resolved website, read its cgroup stats and append a CSV row.
while IFS=$'\t' read -r UNIX_USER CGPATH UUID; do
  [ -z "$UNIX_USER" ] && continue
  [ -f "$CGPATH/cpu.stat" ] || continue

  USAGE_USEC=$(awk '/^usage_usec/{print $2}' "$CGPATH/cpu.stat")
  NR_THROTTLED=$(awk '/^nr_throttled/{print $2}' "$CGPATH/cpu.stat")
  THROTTLED_USEC=$(awk '/^throttled_usec/{print $2}' "$CGPATH/cpu.stat")

  MEM_CURRENT=$(cat "$CGPATH/memory.current" 2>/dev/null || echo 0)
  MEM_MAX=$(cat "$CGPATH/memory.max" 2>/dev/null || echo 0)
  OOM_KILL=$(awk '/^oom_kill /{print $2}' "$CGPATH/memory.events" 2>/dev/null || echo 0)

  CPU_MAX_LINE=$(cat "$CGPATH/cpu.max" 2>/dev/null || echo "max 100000")
  QUOTA=$(echo "$CPU_MAX_LINE" | awk '{print $1}')
  PERIOD=$(echo "$CPU_MAX_LINE" | awk '{print $2}')

  RBYTES=0; WBYTES=0; RIOS=0; WIOS=0
  if [ -f "$CGPATH/io.stat" ]; then
    RBYTES=$(grep -oP 'rbytes=\K[0-9]+' "$CGPATH/io.stat" 2>/dev/null | awk '{s+=$1} END{print s+0}')
    WBYTES=$(grep -oP 'wbytes=\K[0-9]+' "$CGPATH/io.stat" 2>/dev/null | awk '{s+=$1} END{print s+0}')
    RIOS=$(grep -oP 'rios=\K[0-9]+' "$CGPATH/io.stat" 2>/dev/null | awk '{s+=$1} END{print s+0}')
    WIOS=$(grep -oP 'wios=\K[0-9]+' "$CGPATH/io.stat" 2>/dev/null | awk '{s+=$1} END{print s+0}')
  fi

  RIOPS_MAX="max"; WIOPS_MAX="max"
  if [ -f "$CGPATH/io.max" ]; then
    R=$(grep -oP 'riops=\K\S+' "$CGPATH/io.max" 2>/dev/null | head -n1)
    W=$(grep -oP 'wiops=\K\S+' "$CGPATH/io.max" 2>/dev/null | head -n1)
    [ -n "$R" ] && RIOPS_MAX="$R"
    [ -n "$W" ] && WIOPS_MAX="$W"
  fi

  SWAP_CURRENT=$(cat "$CGPATH/memory.swap.current" 2>/dev/null || echo 0)
  SWAP_MAX=$(cat "$CGPATH/memory.swap.max" 2>/dev/null || echo max)
  SWAP_FAIL=$(awk '/^fail/{print $2}' "$CGPATH/memory.swap.events" 2>/dev/null || echo 0)

  PIDS_CURRENT=$(cat "$CGPATH/pids.current" 2>/dev/null || echo 0)
  PIDS_MAX=$(cat "$CGPATH/pids.max" 2>/dev/null || echo max)

  CONNECTIONS="${CONN_COUNT[$UNIX_USER]:-0}"

  LOGFILE="${LOG_DIR}/${UNIX_USER}.csv"
  HEADER="timestamp,ts_epoch,unix_user,usage_usec,nr_throttled,throttled_usec,mem_current_bytes,mem_max_bytes,oom_kill,cpu_quota,cpu_period,io_rbytes,io_wbytes,io_rios,io_wios,io_riops_max,io_wiops_max,swap_current_bytes,swap_max_bytes,swap_fail,pids_current,pids_max,connections"
  if [ ! -f "$LOGFILE" ]; then
    echo "$HEADER" > "$LOGFILE"
  elif [ "$(head -n1 "$LOGFILE")" != "$HEADER" ]; then
    # Older log format (v3 or earlier) - archive it and start fresh so
    # columns don't get misaligned between old and new rows.
    mv "$LOGFILE" "${LOGFILE%.csv}.$(date +%Y%m%d%H%M%S).old.csv"
    echo "$HEADER" > "$LOGFILE"
  fi
  echo "${TS_HUMAN},${TS_EPOCH},${UNIX_USER},${USAGE_USEC:-0},${NR_THROTTLED:-0},${THROTTLED_USEC:-0},${MEM_CURRENT:-0},${MEM_MAX:-0},${OOM_KILL:-0},${QUOTA:-max},${PERIOD:-100000},${RBYTES:-0},${WBYTES:-0},${RIOS:-0},${WIOS:-0},${RIOPS_MAX},${WIOPS_MAX},${SWAP_CURRENT:-0},${SWAP_MAX},${SWAP_FAIL:-0},${PIDS_CURRENT:-0},${PIDS_MAX},${CONNECTIONS}" >> "$LOGFILE"
done < "$CACHE_FILE"

# 4. Retention: keep only the last 35 days per website.
CUTOFF=$(( TS_EPOCH - 35*86400 ))
for f in "$LOG_DIR"/*.csv; do
  [ -f "$f" ] || continue
  awk -F',' -v cutoff="$CUTOFF" 'NR==1{print;next} $2>=cutoff{print}' "$f" > "${f}.tmp" && mv "${f}.tmp" "$f"
done
