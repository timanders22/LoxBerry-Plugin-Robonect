#!/bin/bash
ARGV1=$1; ARGV3=$3; ARGV5=$5; ARGV6=$6
PFOLDER="${ARGV3:-robonect}"
# ------------------------------------------------------------------
# Die Wurzel: GELESEN, nicht geraten (Nachlese 25.09.2026).
# ------------------------------------------------------------------
# Bis 1.1.12 stand hier nur BASE="${5:-$LBHOMEDIR}", ohne Pruefung. Fehlten
# beide, arbeitete das Skript gegen /config/plugins/... und /data/plugins/...
# ab der Laufwerkswurzel (in WSL gemessen, Pruefung-Robonect-1.1.12, Fall W7:
# mkdir, cp und chmod auf Pfade ab /). Eine LoxBerry-Wurzel traegt
# config/plugins, data/plugins und config/system/general.json (Regeln/06).
# Ohne Wurzel: <WARNING>, nichts anlegen, nichts kopieren, Rueckgabe 1.
# Wortgleich in preupgrade.sh, postinstall.sh und postupgrade.sh; Bauart
# sk_wurzel_suchen() (Skoda-Connect-NG 0.9.24).
mo_wurzel_suchen() {
    mo_v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd -P)
    mo_i=0
    while [ -n "$mo_v" ] && [ "$mo_v" != "/" ] && [ "$mo_i" -lt 8 ]; do
        if [ -d "$mo_v/config/plugins" ] && [ -d "$mo_v/data/plugins" ] \
           && [ -f "$mo_v/config/system/general.json" ]; then
            echo "$mo_v"
            return 0
        fi
        mo_v=$(dirname "$mo_v")
        mo_i=$((mo_i + 1))
    done
    return 1
}
BASE="${ARGV5:-}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    if [ -n "${LBHOMEDIR:-}" ] && [ -d "$LBHOMEDIR/config/plugins" ] \
       && [ -d "$LBHOMEDIR/data/plugins" ]; then
        BASE="$LBHOMEDIR"
    else
        BASE=$(mo_wurzel_suchen) || BASE=""
    fi
fi
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    echo "<WARNING> Das Wurzelverzeichnis des LoxBerry liess sich nicht bestimmen: weder"
    echo "<WARNING> das fuenfte Argument noch \$LBHOMEDIR noch der eigene Ablageort fuehrten"
    echo "<WARNING> auf einen Ordner mit config/plugins und data/plugins."
    echo "<WARNING> Es wurde nichts angelegt, gesichert oder zurueckgespielt."
    exit 1
fi
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"

# ------------------------------------------------------------------
# Die Upgrade-Marke, als ERSTES nach der Wurzel (Nachlese 25.09.2026).
# ------------------------------------------------------------------
# Zwischen purge_installation und postupgrade.sh laeuft der Minutentakt
# schon mit den neuen Dateien (Regeln/06, am Geraet gemessen: fast eine
# Minute). Er schrieb lauf.json, fehler.json und statistik.json neu in den
# gerade geleerten Datenordner, und die Rettung weiter unten kam danach nicht
# mehr zurueck (in WSL gemessen, Pruefung-Robonect-1.1.12, Fall L1: Zaehler
# 500 -> 1, fuenf Fehler -> 1, drei Einsaetze -> 1). cron.php setzt aus,
# solange diese Marke gilt (mo_upgrade_marke_gilt(): Unixzeit, hoechstens
# 3600 s alt, bis 300 s aus der Zukunft). Sie liegt NEBEN dem Datenordner,
# sonst loescht purge_installation sie mit; postupgrade.sh raeumt sie weg,
# uninstall ebenso.
date +%s > "$MARKE" 2>/dev/null
case "$(cat "$MARKE" 2>/dev/null)" in
    ''|*[!0-9]*)
        echo "<WARNING> Die Upgrade-Marke liess sich nicht mit der Uhrzeit anlegen ($MARKE)."
        echo "<WARNING> Laeuft der Minutentakt waehrend der Installation, holt postupgrade.sh"
        echo "<WARNING> Laufzaehler, Fehlerhistorie und Statistik trotzdem nach Inhalt zurueck."
        ;;
    *)
        # <INFO>, nicht <OK>: das Protokoll traegt sein <OK> erst in der
        # Schlusszeile von postupgrade.sh, wenn nachgelesen ist, was in
        # mower.json steht (Pruefung-Robonect-1.1.11, Faelle F4, F9).
        echo "<INFO> Minutentakt bis zum Ende der Installation ausgesetzt."
        ;;
esac

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
