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
WORK="${ARGV6:-$ARGV1}"
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/data/plugins/$PFOLDER" 2>/dev/null
CF="$BASE/config/plugins/$PFOLDER/mower.json"
[ -f "$CF" ] || echo '{}' > "$CF"
# Zugangsdaten: nur fuer den LoxBerry-Benutzer lesbar
chmod 600 "$CF" 2>/dev/null
BK="$BASE/config/plugins/$PFOLDER.backup.json"

# ------------------------------------------------------------------
# Nach INHALT entscheiden, nicht nach Groesse (18.09.2026, gemessen).
# ------------------------------------------------------------------
# Hier stand "[ ! -s "$CF" ] || [ Inhalt = "{}" ]", und die Zweitschrift
# wurde ungeprueft eingespielt. Eine abgeschnittene Datei ist weder leer
# noch "{}": eine abgeschnittene mower.json galt als vorhanden, eine
# abgeschnittene Zweitschrift wurde eingespielt und als "wiederhergestellt"
# gemeldet (Pruefung-Robonect-1.1.11, Fall F5; Bestand-2026-09-18/klasse-C,
# Fall 9).
#
# Wortgleich in postinstall.sh und postupgrade.sh - ein Hakenskript kann
# sich nichts aus dem Plugin-Ordner holen. Entschieden wird wie in
# mo_config() (mower_lib.php): gueltiges, nicht leeres JSON-Objekt. Die
# Zweitschrift muss zusaetzlich ein Aktionstoken tragen; ohne Token schreibt
# mo_zweitschrift_schreiben() gar keine.
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
# I1 (Durchgang 01.10.2026, Entscheidung 1/8): zurueckgespielt wird nur bei
# einer Aktualisierung (Merker von preupgrade.sh) oder bei liegender Marke
# (vergessene Marke gilt ohne Altersgrenze, Nr. 8). Bei einer Neuinstallation
# hat preinstall.sh eine liegengebliebene Zweitschrift schon nach .alt gelegt;
# diese Bedingung haelt postinstall.sh auch dann davon ab, wenn ein Werkzeug
# sie wieder hingelegt hat. Bis 1.1.14 lief mo_zweitschrift_holen auch bei
# AKT=0 (Installer-Pruefer Fall D: "<OK> Konfiguration aus Sicherung
# wiederhergestellt" mit Kennwort einer frueheren Anlage).
MARKE_DA=0
[ -e "$MARKE" ] && MARKE_DA=1

# Nachlese 25.09.2026: eine Neuinstallation braucht keine Upgrade-Marke. Liegt
# eine, stammt sie aus einem abgebrochenen Update und setzte den Minutentakt
# bis zu einer Stunde aus (Pruefung-Robonect-1.1.12, Fall L6). Beim Update
# bleibt sie liegen, bis postupgrade.sh zurueckgespielt hat.
if [ "$AKT" = "0" ] && [ -e "$MARKE" ]; then
    rm -f "$MARKE" && echo "<INFO> Eine liegengebliebene Upgrade-Marke wurde entfernt: $(basename "$MARKE")"
fi

MO_GEHOLT=0; MO_WARN=0
if [ "$AKT" = "1" ] || [ "$MARKE_DA" = "1" ]; then
    mo_zweitschrift_holen
fi
if [ "$MO_GEHOLT" = "1" ] && [ "$AKT" = "0" ]; then
    echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
fi

# Die Schlusszeile des Aktualisierungsfalls gibt postupgrade.sh aus - erst
# dort ist die gerettete Konfiguration an ihrem Platz.
if [ "$AKT" = "0" ]; then
    echo "<OK> Installation abgeschlossen. Bitte Plugin-Oberflaeche oeffnen und Maeher-Zugang eintragen."
fi
exit 0
