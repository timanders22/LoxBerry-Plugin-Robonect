#!/bin/bash
ARGV1=$1; ARGV3=$3; ARGV5=$5; ARGV6=$6
PFOLDER="${ARGV3:-robonect}"; BASE="${ARGV5:-$LBHOMEDIR}"
WORK="${ARGV6:-$ARGV1}"     # sechstes Argument, siehe preupgrade.sh
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" "$BASE/data/plugins/$PFOLDER" 2>/dev/null
CF="$BASE/config/plugins/$PFOLDER/mower.json"
[ -f "$WORK/mower.json" ] && cp -p "$WORK/mower.json" "$CF" && chmod 600 "$CF" 2>/dev/null
[ -f "$WORK/mower.log" ] && cp -p "$WORK/mower.log" "$BASE/log/plugins/$PFOLDER/mower.log"

# Lebenszeichen, Fehlerhistorie und Einsatzstatistik zurueckstellen - siehe
# die Begruendung in preupgrade.sh. Nur, was wirklich gesichert wurde: ein
# fehlender Rueckstand ist der Normalfall bei einer Neuinstallation und kein
# Fehler. Eine bereits vorhandene Datei wird nicht ueberschrieben.
for F in lauf.json fehler.json statistik.json; do
    if [ -f "$WORK/data/$F" ] && [ ! -f "$BASE/data/plugins/$PFOLDER/$F" ]; then
        cp -p "$WORK/data/$F" "$BASE/data/plugins/$PFOLDER/$F" 2>/dev/null
    fi
done
BK="$BASE/config/plugins/$PFOLDER.backup.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then cp -p "$BK" "$CF"; chmod 600 "$CF" 2>/dev/null; fi
fi
exit 0
