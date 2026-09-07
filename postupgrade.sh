#!/bin/bash
ARGV1=$1; ARGV3=$3; ARGV5=$5; ARGV6=$6
PFOLDER="${ARGV3:-robonect}"; BASE="${ARGV5:-$LBHOMEDIR}"
WORK="${ARGV6:-$ARGV1}"     # sechstes Argument, siehe preupgrade.sh
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" "$BASE/data/plugins/$PFOLDER" 2>/dev/null
CF="$BASE/config/plugins/$PFOLDER/mower.json"
[ -f "$WORK/mower.json" ] && cp -p "$WORK/mower.json" "$CF" && chmod 600 "$CF" 2>/dev/null

# A27: das Protokoll wird nicht mehr zurueckgespielt - es war nie fort.
# Begruendung in preupgrade.sh.

# Lebenszeichen, Fehlerhistorie und Einsatzstatistik zurueckstellen - siehe
# die Begruendung in preupgrade.sh. Nur, was wirklich gesichert wurde: ein
# fehlender Rueckstand ist der Normalfall bei einer Neuinstallation und kein
# Fehler. Eine bereits vorhandene Datei wird nicht ueberschrieben.
# A26: der Ordner heisst seit 1.1.6 "rettung"; "data" fasste der Installer
# selbst an, und diese Schleife war dadurch toter Code.
for F in lauf.json fehler.json statistik.json; do
    if [ -f "$WORK/rettung/$F" ] && [ ! -f "$BASE/data/plugins/$PFOLDER/$F" ]; then
        cp -p "$WORK/rettung/$F" "$BASE/data/plugins/$PFOLDER/$F" 2>/dev/null
    fi
done
BK="$BASE/config/plugins/$PFOLDER.backup.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then cp -p "$BK" "$CF"; chmod 600 "$CF" 2>/dev/null; fi
fi

# A15: die Schlusszeile des Aktualisierungsfalls steht HIER, nicht in
# postinstall.sh - erst jetzt ist die gerettete Konfiguration wirklich an
# ihrem Platz. postinstall.sh schweigt dafuer, wenn der Merker
# "$WORK/.aktualisierung" liegt.
if [ -s "$CF" ] && [ "$(cat "$CF" 2>/dev/null)" != "{}" ]; then
    echo "<OK> Aktualisierung abgeschlossen. Die Einstellungen sind erhalten."
else
    echo "<INFO> Aktualisierung abgeschlossen. Es lag keine Konfiguration vor -"
    echo "<INFO> bitte die Plugin-Oberflaeche oeffnen und den Maeher-Zugang eintragen."
fi
exit 0
