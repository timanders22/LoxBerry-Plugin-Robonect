#!/bin/bash
ARGV1=$1; ARGV3=$3; ARGV5=$5; ARGV6=$6
PFOLDER="${ARGV3:-robonect}"; BASE="${ARGV5:-$LBHOMEDIR}"
WORK="${ARGV6:-$ARGV1}"
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/data/plugins/$PFOLDER" 2>/dev/null
CF="$BASE/config/plugins/$PFOLDER/mower.json"
[ -f "$CF" ] || echo '{}' > "$CF"
# Zugangsdaten: nur fuer den LoxBerry-Benutzer lesbar
chmod 600 "$CF" 2>/dev/null
BK="$BASE/config/plugins/$PFOLDER.backup.json"

# ------------------------------------------------------------------
# A15 (06.09.2026, gemessen): Neuinstallation und Aktualisierung sagen
# Verschiedenes.
# ------------------------------------------------------------------
# Beim Upgrade ist die Lage IMMER so: purge_installation hat den
# Konfigordner geraeumt, die Zeile darueber legt "{}" an, die Zweitschrift
# daneben hat ueberlebt. Die Bedingung unten war damit jedes Mal wahr, und
# im Installationsprotokoll stand "Konfiguration aus Sicherung
# wiederhergestellt" - ein Zwischenzustand, der Sekunden dauert und wie ein
# ueberstandener Schaden aussieht. Der Satz "Bitte Maeher-Zugang eintragen"
# war im Aktualisierungsfall schlicht falsch: der Zugang steht schon.
#
# Den Merker legt preupgrade.sh, und das laeuft nur bei einer
# Aktualisierung (plugininstall.pl:845).
AKT=0
[ -f "$WORK/.aktualisierung" ] && AKT=1

if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$BK" "$CF"
        chmod 600 "$CF" 2>/dev/null
        [ "$AKT" = "0" ] && echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi

# Die Schlusszeile des Aktualisierungsfalls gibt postupgrade.sh aus - erst
# dort ist die gerettete Konfiguration an ihrem Platz.
if [ "$AKT" = "0" ]; then
    echo "<OK> Installation abgeschlossen. Bitte Plugin-Oberflaeche oeffnen und Maeher-Zugang eintragen."
fi
exit 0
