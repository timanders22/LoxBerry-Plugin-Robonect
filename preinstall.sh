#!/bin/bash

# Rasenmaeher (Robonect) - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Neu im Durchgang 01.10.2026 (I1, X-1, Bauart F, Entscheidung 1 vom
# 29.09.2026). Der Installer ruft dieses Skript bei JEDEM Einbau auf, nach dem
# Aufraeumen der alten Fassung und VOR dem Kopieren von Konfiguration,
# Cron-Datei und Oberflaeche (sbin/plugininstall.pl: preupgrade :846, purge
# :874, preinstall :877, Cron :990, HTML :1066 - Regeln/06).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: die Zweitschrift wird von
# postupgrade.sh gebraucht.
#
# Ohne Marke ist es eine NEUINSTALLATION. Die liegengebliebene Zweitschrift
# einer frueheren Installation (config/plugins/<ordner>.backup.json - mit
# Kennwort des Robonect-Moduls und Aktionstoken) und der MQTT-Stand
# (<ordner>.mqtt_stand.json) gehen nach <name>.alt, gemeldet mit genau einer
# <WARNING>. Bis 1.1.14 spielte postinstall.sh die Zweitschrift zurueck, und
# schon der erste Minutentakt - die Cron-Datei liegt am Geraet fast eine
# Minute vor postinstall.sh - holte Kennwort und Aktionstoken der frueheren
# Installation zurueck, im Protokoll ohne ein Wort (in WSL gemessen,
# Installer-Pruefer Faelle D, D2). Die Selbstheilung der Bibliothek liest .alt
# nie; die Deinstallation raeumt es ab.

ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
PFOLDER="${ARGV3:-robonect}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Wurzelsuche wie in preupgrade.sh, postinstall.sh und postupgrade.sh: ohne
# config/plugins, data/plugins UND config/system/general.json wird nichts
# angefasst (Regeln/06, Raumklima-Vorfall).
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv/rm daneben.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    # Aktualisierung: nichts zu tun, postupgrade.sh spielt zurueck.
    exit 0
fi

BK="$BASE/config/plugins/$PFOLDER.backup.json"
STAND="$BASE/config/plugins/$PFOLDER.mqtt_stand.json"
BEISEITE=""
FEST=""
for ZIEL in "$BK" "$STAND"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -f "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
[ -f "$BK.alt" ] && [ ! -L "$BK.alt" ] && chmod 600 "$BK.alt" 2>/dev/null

if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    MO_TEXT="<WARNING> Neuinstallation: Einstellungen einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && MO_TEXT="$MO_TEXT Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && MO_TEXT="$MO_TEXT Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$MO_TEXT"
fi
exit 0
