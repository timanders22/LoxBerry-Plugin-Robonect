#!/bin/bash
ARGV1=$1; ARGV3=$3; ARGV5=$5; ARGV6=$6
PFOLDER="${ARGV3:-robonect}"; BASE="${ARGV5:-$LBHOMEDIR}"

# Der Arbeitsordner des Installers steht im SECHSTEN Argument. $1 ist eine
# zehnstellige Zufallskennung, kein Pfad; dass "mkdir -p $1" bisher aufging,
# lag allein daran, dass der Installer die Hakenskripte mit cd "$tempfolder"
# startet. Ausgeschrieben ist besser als geerbt - der Rueckfall auf $1 haelt
# den bisherigen Weg offen.
WORK="${ARGV6:-$ARGV1}"
mkdir -p "$WORK" 2>/dev/null

cp -p "$BASE/config/plugins/$PFOLDER/mower.json" "$WORK/mower.json" 2>/dev/null
cp -p "$BASE/log/plugins/$PFOLDER/mower.log" "$WORK/mower.log" 2>/dev/null

# ------------------------------------------------------------------
# Der Datenordner ueberlebt ein Upgrade NICHT.
# ------------------------------------------------------------------
# purge_installation raeumt data/plugins/<ordner>/ ab, bevor postinstall
# laeuft; preupgrade ist das einzige Rettungsfenster. Dort liegen:
#
#   lauf.json       Lebenszeichen - Zeitstempel UND Laufzaehler
#   fehler.json     Fehlerhistorie, bis 40 Eintraege
#   statistik.json  Einsaetze und Maehdauer je Tag und Woche
#
# Bis 1.1.3 gingen alle drei bei jedem Update verloren, ohne dass es
# irgendwo stand. Beim Laufzaehler ist das mehr als ein Schoenheitsfehler:
# er springt dann auf 0, und ein Zaehler, der auf 0 springt, ist am
# Miniserver von einem stehengebliebenen Cron nicht zu unterscheiden -
# genau die Unterscheidung, fuer die es ihn gibt.
#
# endpunkt.json wird bewusst NICHT gesichert: ein Zwischenspeicher mit
# fuenf Minuten Lebensdauer, der sich von selbst neu bildet.
mkdir -p "$WORK/data" 2>/dev/null
for F in lauf.json fehler.json statistik.json; do
    cp -p "$BASE/data/plugins/$PFOLDER/$F" "$WORK/data/$F" 2>/dev/null
done
exit 0
