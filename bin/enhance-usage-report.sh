#!/usr/bin/env bash
# enhance-usage-report.sh  (v3 - matches logger v4 CSV format: adds swap, IOPS,
# nproc, connections)
# Reads the CSVs written by enhance-usage-logger.sh and prints a summary
# per website: avg/peak CPU, avg/peak memory, swap, IOPS, throttle count,
# OOM-kill count, nproc, concurrent connections.
#
# Usage:
#   enhance-usage-report.sh --user <unix_user> --days 7
#   enhance-usage-report.sh --all --days 30
#
# Run this ON THE SAME SERVER where the logger has been running for that site
# (i.e. the Application server hosting it). To get a whole-cluster view, rsync
# /var/log/enhance-usage/*.csv from every Application server into one folder
# and point LOG_DIR at that folder instead.

set -uo pipefail
LOG_DIR="/var/log/enhance-usage"
DAYS=1
USER_FILTER=""
ALL=0

while [ $# -gt 0 ]; do
  case "$1" in
    --user) USER_FILTER="$2"; shift 2 ;;
    --days) DAYS="$2"; shift 2 ;;
    --all)  ALL=1; shift ;;
    *) echo "Unknown option: $1"; exit 1 ;;
  esac
done

CUTOFF=$(( $(date +%s) - DAYS*86400 ))

report_one() {
  local file="$1"
  local user
  user=$(basename "$file" .csv)
  awk -F',' -v cutoff="$CUTOFF" -v user="$user" '
    NR==1 { next }
    $2 >= cutoff {
      n++
      if (n==1) {
        first_ts=$2; first_cpu=$4; first_rb=$12; first_wb=$13
        first_thr=$5; first_oom=$9; first_rios=$14; first_wios=$15
        first_swapfail=$20
      }
      last_ts=$2; last_cpu=$4; last_rb=$12; last_wb=$13
      last_thr=$5; last_oom=$9; last_rios=$14; last_wios=$15
      last_swapfail=$20
      mem=$7+0; if (mem>peak_mem) peak_mem=mem
      mem_sum+=mem
      swap=$18+0; if (swap>peak_swap) peak_swap=swap
      swap_sum+=swap
      pids=$21+0; if (pids>peak_pids) peak_pids=pids
      pids_sum+=pids
      conn=$23+0; if (conn>peak_conn) peak_conn=conn
      conn_sum+=conn
      quota=$10; period=$11
      swap_max=$19; pids_max=$22
      riops_max=$16; wiops_max=$17
      if (n>1) {
        dt = $2 - prev_ts
        if (dt>0) {
          rate = ($4 - prev_cpu) / dt / 1000000
          if (rate > peak_cpu_rate) peak_cpu_rate = rate
        }
      }
      prev_ts=$2; prev_cpu=$4
    }
    END {
      if (n<2) { printf "\n=== %s: not enough data in this window ===\n", user; exit }
      wall = last_ts - first_ts
      if (wall<=0) wall=1
      avg_cpu_cores = (last_cpu - first_cpu) / 1000000 / wall
      throttle_delta = last_thr - first_thr
      oom_delta = last_oom - first_oom
      io_r_mb_s = (last_rb - first_rb) / wall / 1048576
      io_w_mb_s = (last_wb - first_wb) / wall / 1048576
      riops = (last_rios - first_rios) / wall
      wiops = (last_wios - first_wios) / wall
      swapfail_delta = last_swapfail - first_swapfail
      vcpu = "unlimited"
      if (quota != "max") vcpu = quota/period
      printf "\n=== %s ===\n", user
      printf "vCPU allocation : %s\n", vcpu
      printf "Avg CPU used    : %.2f cores\n", avg_cpu_cores
      printf "Peak CPU (sample): %.2f cores\n", peak_cpu_rate
      printf "Avg memory      : %.0f MB\n", mem_sum/n/1048576
      printf "Peak memory     : %.0f MB\n", peak_mem/1048576
      printf "Avg swap        : %.0f MB (limit %s)\n", swap_sum/n/1048576, (swap_max=="max"?"unlimited":sprintf("%.0f MB", swap_max/1048576))
      printf "Peak swap       : %.0f MB\n", peak_swap/1048576
      printf "Swap fails      : %d times\n", swapfail_delta
      printf "CPU throttled   : %d times\n", throttle_delta
      printf "OOM killed      : %d times\n", oom_delta
      printf "Avg IO read     : %.2f MB/s\n", io_r_mb_s
      printf "Avg IO write    : %.2f MB/s\n", io_w_mb_s
      printf "Avg read IOPS   : %.1f (limit %s)\n", riops, riops_max
      printf "Avg write IOPS  : %.1f (limit %s)\n", wiops, wiops_max
      printf "Avg nproc       : %.0f (peak %d, limit %s)\n", pids_sum/n, peak_pids, pids_max
      printf "Avg connections : %.0f (peak %d)\n", conn_sum/n, peak_conn
    }
  ' "$file"
}

if [ "$ALL" -eq 1 ]; then
  shopt -s nullglob
  for f in "$LOG_DIR"/*.csv; do
    case "$f" in
      *.old.csv) continue ;;  # skip archived pre-migration logs
    esac
    report_one "$f"
  done
elif [ -n "$USER_FILTER" ]; then
  f="$LOG_DIR/${USER_FILTER}.csv"
  if [ -f "$f" ]; then
    report_one "$f"
  else
    echo "No log file found for user: $USER_FILTER (check ls $LOG_DIR)"
  fi
else
  echo "Usage: $0 --user <unix_user> --days N   OR   $0 --all --days N"
  exit 1
fi
