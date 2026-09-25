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
# Nachlese 25.09.2026: die Marke aus preupgrade.sh faellt am Ende dieses
# Skripts, auch bei einem vorzeitigen Ausstieg (trap ... EXIT, Regeln/06).
# Erst dann laeuft der Minutentakt wieder - nach der Rueckholung unten.
trap 'rm -f "$MARKE"' EXIT
WORK="${ARGV6:-$ARGV1}"     # sechstes Argument, siehe preupgrade.sh
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" "$BASE/data/plugins/$PFOLDER" 2>/dev/null
CF="$BASE/config/plugins/$PFOLDER/mower.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"

# ------------------------------------------------------------------
# Nach INHALT entscheiden, nicht nach Groesse (18.09.2026, gemessen).
# ------------------------------------------------------------------
# Hier standen drei Dinge, die zusammen eine falsche Erfolgsmeldung ergaben
# (Pruefung-Robonect-1.1.11, Faelle F2-F4; Bestand-2026-09-18/klasse-C,
# Fall 9):
#   1. die Kopie von vor der Aktualisierung ging UNGEPRUEFT ueber mower.json
#      - auch eine abgeschnittene, und auch ueber den heilen Stand, den
#      postinstall.sh gerade aus der Zweitschrift geholt hatte;
#   2. "[ ! -s "$CF" ]" entschied, ob die Zweitschrift geholt wird - eine
#      abgeschnittene Datei ist nicht leer;
#   3. die Schlusszeile fragte wieder nur nach Groesse und "{}" und meldete
#      "<OK> ... Die Einstellungen sind erhalten." - auch ohne Einstellungen.
#
# mo_inhalt(), mo_beiseite() und mo_zweitschrift_holen() stehen wortgleich
# in postinstall.sh; die Begruendung steht dort.
#
# Rueckgabe: 0 = traegt Inhalt, 1 = fehlt, leer oder "{}",
#            3 = da, aber unbrauchbar, 2 = NICHT PRUEFBAR (kein php).
mo_inhalt() {   # $1 Datei, $2 Art: konf | zweit
    [ -f "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 2
    php -r '
        $roh = @file_get_contents($argv[1]);
        if ($roh === false) { exit(3); }
        $t = trim($roh);
        if ($t === "" || $t === "{}") { exit(1); }
        $d = json_decode($t, true);
        if (!is_array($d) || count($d) === 0) { exit(3); }
        if ($argv[2] === "zweit") {
            $k = isset($d["aktionstoken"]) && is_string($d["aktionstoken"])
                 && trim($d["aktionstoken"]) !== "";
            exit($k ? 0 : 3);
        }
        exit(0);
    ' -- "$1" "$2" 2>/dev/null
    mo_rc=$?
    case "$mo_rc" in 0|1|3) return "$mo_rc" ;; esac
    return 2
}

# Den verdraengten Stand als mower.json.kaputt (0600) liegen lassen - wie
# mo_config() es tut, und nur, wenn dort noch keiner liegt.
mo_beiseite() {   # $1 Quelle, $2 Konfigurationsdatei
    [ -e "$2.kaputt" ] && return 0
    ( umask 077 && cp "$1" "$2.kaputt" ) 2>/dev/null && chmod 600 "$2.kaputt" 2>/dev/null
}

# Aus der Zweitschrift zurueckspielen - nur, wenn mower.json keinen Inhalt
# traegt UND die Zweitschrift einen. Gemeldet wird, was nachgelesen wurde,
# nicht der Rueckgabewert von cp. Setzt MO_GEHOLT=1 bzw. MO_WARN=1.
mo_zweitschrift_holen() {
    [ -f "$BK" ] || return 0
    mo_inhalt "$CF" konf; mo_cf=$?
    [ "$mo_cf" = 0 ] && return 0
    if [ "$mo_cf" = 2 ]; then
        # Ohne php laeuft das Plugin ohnehin nicht. Wie bisher: nur eine
        # leere Datei wird ersetzt - aber nichts zugesichert.
        MO_WARN=1
        if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
            cp -p "$BK" "$CF" 2>/dev/null; chmod 600 "$CF" 2>/dev/null
            echo "<WARNING> php fehlt - die Zweitschrift wurde UNGEPRUEFT nach mower.json kopiert."
        else
            echo "<WARNING> php fehlt - ob mower.json oder die Zweitschrift brauchbar ist,"
            echo "<WARNING> liess sich nicht pruefen. Nichts eingespielt."
        fi
        return 0
    fi
    mo_inhalt "$BK" zweit
    if [ "$?" != 0 ]; then
        MO_WARN=1
        echo "<WARNING> Die Zweitschrift der Einstellungen ist unbrauchbar (kein gueltiges"
        echo "<WARNING> JSON mit Aktionstoken) und wurde NICHT eingespielt. Sie bleibt"
        echo "<WARNING> unangetastet liegen: $BK"
        echo "<WARNING> Sie wird beim naechsten Speichern in der Plugin-Oberflaeche neu geschrieben."
        return 0
    fi
    [ "$mo_cf" = 3 ] && mo_beiseite "$CF" "$CF"
    if cp -p "$BK" "$CF" 2>/dev/null && chmod 600 "$CF" 2>/dev/null && cmp -s "$BK" "$CF"; then
        MO_GEHOLT=1
    else
        MO_WARN=1
        echo "<WARNING> Die Zweitschrift liess sich nicht nach mower.json zurueckspielen."
        echo "<WARNING> Sie liegt unveraendert unter $BK"
    fi
}

# Was diese Datei ueber den Stand weiss, fuer die Schlusszeile - gelesen,
# nicht angenommen.
mo_beschreibung() {   # $1 Datei
    php -r '
        $d = json_decode((string) @file_get_contents($argv[1]), true);
        $n = (is_array($d) && isset($d["mowers"]) && is_array($d["mowers"])) ? count($d["mowers"]) : 0;
        $t = is_array($d) && isset($d["aktionstoken"]) && is_string($d["aktionstoken"])
             && trim($d["aktionstoken"]) !== "";
        echo $n . " Maeher eingetragen, Aktionstoken "
             . ($t ? "vorhanden" : "fehlt (entsteht beim naechsten Oeffnen der Oberflaeche)");
    ' -- "$1" 2>/dev/null
}

MO_GEHOLT=0; MO_WARN=0; MO_VORAB=""

# Die Kopie von vor der Aktualisierung (preupgrade.sh) - nur mit Inhalt.
WK="$WORK/mower.json"
if [ -f "$WK" ]; then
    mo_inhalt "$WK" konf
    case "$?" in
    0)
        if cp -p "$WK" "$CF" 2>/dev/null && chmod 600 "$CF" 2>/dev/null && cmp -s "$WK" "$CF"; then
            MO_VORAB=uebernommen
        else
            MO_WARN=1
            echo "<WARNING> Die Einstellungen von vor der Aktualisierung liessen sich nicht"
            echo "<WARNING> nach mower.json kopieren."
        fi
        ;;
    1)
        : # vor der Aktualisierung gab es keine Einstellungen
        ;;
    3)
        MO_WARN=1; MO_VORAB=beschaedigt
        mo_beiseite "$WK" "$CF"
        echo "<WARNING> mower.json war schon VOR der Aktualisierung beschaedigt (kein gueltiges"
        echo "<WARNING> JSON) und wurde nicht uebernommen."
        if cmp -s "$WK" "$CF.kaputt"; then
            echo "<WARNING> Der beschaedigte Stand liegt als $CF.kaputt daneben."
        fi
        ;;
    *)
        # Ohne php laeuft das Plugin ohnehin nicht. Wie bisher uebernehmen,
        # aber nichts zusichern.
        MO_WARN=1
        cp -p "$WK" "$CF" 2>/dev/null && chmod 600 "$CF" 2>/dev/null
        echo "<WARNING> php fehlt - mower.json wurde UNGEPRUEFT aus der Kopie von vor der"
        echo "<WARNING> Aktualisierung uebernommen."
        ;;
    esac
fi

# A27: das Protokoll wird nicht mehr zurueckgespielt - es war nie fort.
# Begruendung in preupgrade.sh.

# Lebenszeichen, Fehlerhistorie und Einsatzstatistik zurueckstellen - siehe
# die Begruendung in preupgrade.sh. Ein fehlender Rueckstand ist bei einer
# Neuinstallation der Normalfall und kein Fehler.
# A26: der Ordner heisst seit 1.1.6 "rettung"; "data" fasste der Installer
# selbst an, und diese Schleife war dadurch toter Code.
#
# Nachlese 25.09.2026: nach INHALT, nicht nach Dasein. Bis 1.1.12 galt "eine
# bereits vorhandene Datei wird nicht ueberschrieben": hatte der Minutentakt
# in der Luecke eine frische lauf.json geschrieben (Zaehler 1), blieb die
# gerettete (Zaehler 500) liegen; und eine abgeschnittene Rettung wurde
# ungeprueft kopiert (in WSL gemessen, Pruefung-Robonect-1.1.12, Faelle L8,
# L9). Die Rettung stammt aus DIESEM Update (preupgrade.sh eben) und ist der
# vollstaendige Stand: traegt sie gueltiges JSON, ersetzt sie, was in der
# Luecke entstand, und das Protokoll sagt es. Ungueltig: nicht einspielen,
# melden. Die Marke (preupgrade.sh) haelt den Takt aus der Luecke heraus;
# dieser Weg traegt auch, wenn sie fehlt.
mo_json_inhalt() {   # $1 Datei -> 0 gueltiges JSON, 1 leer, 3 ungueltig, 2 kein php
    [ -s "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 2
    php -r '
        $t = trim((string) @file_get_contents($argv[1]));
        if ($t === "") { exit(1); }
        $d = json_decode($t, true);
        exit(is_array($d) ? 0 : 3);
    ' -- "$1" 2>/dev/null
    mo_rc=$?
    case "$mo_rc" in 0|1|3) return "$mo_rc" ;; esac
    return 2
}
MO_GEHOLT_D=""; MO_ERSETZT_D=""
for F in lauf.json fehler.json statistik.json; do
    Q="$WORK/rettung/$F"; ZIEL="$BASE/data/plugins/$PFOLDER/$F"
    [ -f "$Q" ] || continue
    mo_json_inhalt "$Q"
    case "$?" in
    0)
        VORHER=0
        if [ -e "$ZIEL" ] && ! cmp -s "$Q" "$ZIEL"; then VORHER=1; fi
        if cp -p "$Q" "$ZIEL" 2>/dev/null && cmp -s "$Q" "$ZIEL"; then
            MO_GEHOLT_D="$MO_GEHOLT_D $F"
            [ "$VORHER" = 1 ] && MO_ERSETZT_D="$MO_ERSETZT_D $F"
        else
            MO_WARN=1
            echo "<WARNING> $F liess sich nicht zurueckspielen; die Rettung liegt unter $Q"
        fi
        ;;
    1)
        : # leer gerettet - nichts zurueckzuspielen
        ;;
    3)
        MO_WARN=1
        echo "<WARNING> Die gerettete $F ist kein gueltiges JSON und wurde nicht zurueckgespielt."
        ;;
    *)
        # Ohne php laeuft das Plugin ohnehin nicht: wie bisher nur, wenn am
        # Ziel nichts liegt - und nichts zusichern.
        [ -f "$ZIEL" ] || cp -p "$Q" "$ZIEL" 2>/dev/null
        ;;
    esac
done
[ -n "$MO_GEHOLT_D" ] && echo "<INFO> Zurueckgespielt aus der Sicherung von eben:$MO_GEHOLT_D"
[ -n "$MO_ERSETZT_D" ] && echo "<INFO> Dabei ersetzt, was der Minutentakt waehrend der Installation neu angelegt hatte:$MO_ERSETZT_D"

mo_zweitschrift_holen

# A15: die Schlusszeile des Aktualisierungsfalls steht HIER, nicht in
# postinstall.sh - erst jetzt ist die gerettete Konfiguration wirklich an
# ihrem Platz. postinstall.sh schweigt dafuer, wenn der Merker
# "$WORK/.aktualisierung" liegt.
#
# Sie sagt, was jetzt in mower.json steht - nachgelesen, nicht angenommen.
mo_inhalt "$CF" konf
case "$?" in
0)
    echo "<OK> Aktualisierung abgeschlossen. mower.json ist lesbar: $(mo_beschreibung "$CF")."
    if [ "$MO_GEHOLT" = "1" ] || [ "$MO_VORAB" = "beschaedigt" ]; then
        echo "<INFO> Eingespielt ist der Stand der Zweitschrift."
    fi
    ;;
1)
    if [ "$MO_WARN" = "1" ]; then
        echo "<WARNING> Aktualisierung abgeschlossen, aber OHNE Einstellungen: mower.json ist leer."
        echo "<WARNING> Bitte die Plugin-Oberflaeche oeffnen und den Maeher-Zugang neu eintragen."
    else
        echo "<INFO> Aktualisierung abgeschlossen. Es lag keine Konfiguration vor -"
        echo "<INFO> bitte die Plugin-Oberflaeche oeffnen und den Maeher-Zugang eintragen."
    fi
    ;;
3)
    echo "<WARNING> Aktualisierung abgeschlossen, aber mower.json ist NICHT lesbar (kein gueltiges"
    echo "<WARNING> JSON). Die Einstellungen sind nicht erhalten; bitte die Plugin-Oberflaeche"
    echo "<WARNING> oeffnen und den Maeher-Zugang neu eintragen."
    ;;
*)
    echo "<WARNING> Aktualisierung abgeschlossen. Ob die Einstellungen erhalten sind, liess sich"
    echo "<WARNING> nicht pruefen (php fehlt)."
    ;;
esac
exit 0
