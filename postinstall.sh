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

MO_GEHOLT=0; MO_WARN=0
mo_zweitschrift_holen
if [ "$MO_GEHOLT" = "1" ] && [ "$AKT" = "0" ]; then
    echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
fi

# Die Schlusszeile des Aktualisierungsfalls gibt postupgrade.sh aus - erst
# dort ist die gerettete Konfiguration an ihrem Platz.
if [ "$AKT" = "0" ]; then
    echo "<OK> Installation abgeschlossen. Bitte Plugin-Oberflaeche oeffnen und Maeher-Zugang eintragen."
fi
exit 0
