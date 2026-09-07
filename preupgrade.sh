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

# ------------------------------------------------------------------
# A15 (06.09.2026): der Merker, an dem postinstall.sh den Fall erkennt.
# ------------------------------------------------------------------
# plugininstall.pl ruft preupgrade NUR bei einer Aktualisierung (:845,
# "if ($isupgrade)"), postinstall dagegen IMMER (:1305). Ohne diesen Merker
# meldete postinstall bei jeder Aktualisierung "Konfiguration aus Sicherung
# wiederhergestellt" und "Bitte Maeher-Zugang eintragen" - beides beschreibt
# einen Zustand, den es nur fuer Sekunden gibt, und liest sich im
# Installationsprotokoll wie ein ueberstandener Schaden.
: > "$WORK/.aktualisierung" 2>/dev/null

cp -p "$BASE/config/plugins/$PFOLDER/mower.json" "$WORK/mower.json" 2>/dev/null

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
#
# A26 (06.09.2026, gemessen): bis 1.1.5 hiess dieser Ordner "$WORK/data".
# Das ist $tempfolder/data - und plugininstall.pl:1010-1013 kopiert
# $tempfolder/data/* SELBST nach data/plugins/<ordner>/, sobald der Ordner
# nicht leer ist. Die Dateien kamen also zurueck, aber ueber einen Weg, den
# dieses Skript weder nennt noch in der Hand hat; die Rueckstellschleife in
# postupgrade.sh war dadurch toter Code. "rettung" fasst der Installer
# nicht an.
mkdir -p "$WORK/rettung" 2>/dev/null
for F in lauf.json fehler.json statistik.json; do
    cp -p "$BASE/data/plugins/$PFOLDER/$F" "$WORK/rettung/$F" 2>/dev/null
done

# A27 (06.09.2026, gemessen): das Protokoll wird NICHT mehr gesichert.
# plugininstall.pl:1642 loescht log/plugins/<ordner>/ nur unter
# "if ($option eq 'all')", also nur bei der Deinstallation - beim Upgrade
# bleibt es liegen. Die Sicherung war ueberfluessig, und das unbedingte
# Zurueckspielen in postupgrade.sh warf alles weg, was zwischen den beiden
# Skripten hineingeschrieben wurde.
exit 0
