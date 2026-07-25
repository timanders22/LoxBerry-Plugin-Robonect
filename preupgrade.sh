#!/bin/bash
ARGV1=$1; ARGV3=$3; ARGV5=$5
PFOLDER="${ARGV3:-robonect}"; BASE="${ARGV5:-$LBHOMEDIR}"
mkdir -p "$ARGV1" 2>/dev/null
cp -p "$BASE/config/plugins/$PFOLDER/mower.json" "$ARGV1/mower.json" 2>/dev/null
cp -p "$BASE/log/plugins/$PFOLDER/mower.log" "$ARGV1/mower.log" 2>/dev/null
exit 0
