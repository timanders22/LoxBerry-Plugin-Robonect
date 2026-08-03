#!/bin/bash
ARGV3=$3; ARGV5=$5
PFOLDER="${ARGV3:-robonect}"; BASE="${ARGV5:-$LBHOMEDIR}"
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/data/plugins/$PFOLDER" 2>/dev/null
CF="$BASE/config/plugins/$PFOLDER/mower.json"
[ -f "$CF" ] || echo '{}' > "$CF"
# Zugangsdaten: nur fuer den LoxBerry-Benutzer lesbar
chmod 600 "$CF" 2>/dev/null
BK="$BASE/config/plugins/$PFOLDER.backup.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then cp -p "$BK" "$CF"; chmod 600 "$CF" 2>/dev/null; echo "<OK> Konfiguration aus Sicherung wiederhergestellt."; fi
fi
echo "<OK> Installation abgeschlossen. Bitte Plugin-Oberflaeche oeffnen und Maeher-Zugang eintragen."
exit 0
