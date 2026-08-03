#!/bin/bash
ARGV1=$1; ARGV3=$3; ARGV5=$5
PFOLDER="${ARGV3:-robonect}"; BASE="${ARGV5:-$LBHOMEDIR}"
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" "$BASE/data/plugins/$PFOLDER" 2>/dev/null
CF="$BASE/config/plugins/$PFOLDER/mower.json"
[ -f "$ARGV1/mower.json" ] && cp -p "$ARGV1/mower.json" "$CF" && chmod 600 "$CF" 2>/dev/null
[ -f "$ARGV1/mower.log" ] && cp -p "$ARGV1/mower.log" "$BASE/log/plugins/$PFOLDER/mower.log"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then cp -p "$BK" "$CF"; chmod 600 "$CF" 2>/dev/null; fi
fi
exit 0
