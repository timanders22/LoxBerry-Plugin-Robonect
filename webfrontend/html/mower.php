<?php
/**
 * Rasenmaeher (Robonect) - Miniserver-Endpunkt
 *
 * Abfrage (&dev=N waehlt bei mehreren Maehern das Geraet, Standard 1):
 *   (ohne Parameter) -> MOWER;OK=..;CODE=..;MODUS=..;BATT=..;MAEHT=..;LAEDT=..;FEHLER=..;
 *                       STUNDEN=..;DAUER=..;MESSER=..;MESSERWARN=..;TEMP=..;FEUCHTE=..;WLAN=..;
 *                       TIMER=..;ANN=..;AUDIO=..;PUSH=..;PTEST=..;TS=..;ZAEHLER=..;FEHLERALTER=..
 *                       (dazu EINSHEUTE, MINHEUTE, EINSWOCHE, MINWOCHE, wenn die
 *                        Einsatzstatistik eingeschaltet ist)
 *
 *                       CODE: 1=parkt 2=maeht 3=sucht Ladestation 4=laedt 5=sucht
 *                             7=Fehler 8=Schleifensignal verloren 16=abgeschaltet
 *                             17=schlaeft  -1=keine Verbindung
 *                       MODUS: 0=Automatik 1=Manuell 2=Zuhause 4=Auftrag, -1=unbekannt
 *                       MESSER = Reststunden bis zum Messerwechsel, -1 = nicht bekannt
 *                       TS/ZAEHLER = Lebenszeichen des Cron-Laufs, siehe unten
 *
 * Die Zeile wird NICHT hier gebaut, sondern von mo_zeile() - derselben
 * Funktion, aus der auch die Loxone-Vorlage und die Themenliste im Reiter
 * MQTT ihre Namen holen. Wer ein Feld ergaenzt, ergaenzt es an einer Stelle.
 *
 * ==================================================================
 * WARUM TS UND ZAEHLER DAZUGEHOEREN
 * ==================================================================
 * Ein virtueller Eingang behaelt seinen letzten Wert. Faellt der Cron-Lauf
 * aus, steht in Loxone weiter "parkt, Akku 80 %" - das ist keine fehlende
 * Auskunft, sondern eine Falschaussage, und sie sieht aus wie eine richtige.
 * OK hilft dagegen nicht: es sagt, ob der Maeher beim letzten Messen
 * erreichbar war, nicht ob ueberhaupt gemessen wurde.
 *
 *   Alter in Sekunden = (Loxone-Zeit + 1230768000) - TS
 *
 * ZAEHLER laeuft 0...999 um und beantwortet, was der Zeitstempel nicht kann:
 * ein Raspberry ohne Echtzeituhr springt beim ersten Zeitabgleich. Steht der
 * Zaehler still, laeuft der Cron nicht mehr - unabhaengig von jeder Uhr.
 *
 * Steuerung (einfache GET-Aufrufe fuer virtuelle Ausgaenge; token-pflichtig):
 *   ?cmd=auto | man | home | eod | start | stop &token=T
 *   ?cmd=blade_reset&token=T   Messerwechsel quittieren (Nullpunkt neu setzen)
 *   ?cmd=...&probe=1&token=T   TROCKENLAUF: sagt, was gesendet WUERDE
 *   Ohne passendes Token aus dem Reiter "Einbindung in Loxone" antwortet
 *   ?cmd= mit HTTP 403.
 *
 * Weitere Aufrufe: ?debug=1  ?json=1  ?refresh=1
 *   ?ptest=1&token=T   Test-Pushnachricht anstossen (tokenpflichtig)
 *   ?roh=<Befehl>&token=T   die ROHE Antwort des Moduls auf einen Lesebefehl
 *
 * Zugangsdaten stehen ausschliesslich in der Plugin-Konfiguration - diese URL
 * enthaelt KEIN Passwort und darf daher bedenkenlos in der Loxone-Projektdatei stehen.
 */

require_once __DIR__ . '/mower_lib.php';

/** A6: der unangemeldete Endpunkt LIEST nur - er legt nichts an.
 *
 * Gemessen am 04.09.2026: eine tokenlose, korrekt mit HTTP 403 abgewiesene
 * Anfrage legte config/plugins/<ordner>/mower.json an, 262 Byte, mit
 * Kennwort und Aktionstoken - aus der Zweitschrift geheilt. Das ist der
 * Zustand nach jedem Upgrade, nicht ein Sonderfall.
 */
function mo_cfg_ro() { $mo_z = null; return mo_config($mo_z, false); }

/* A15: eine unzulaessige Geraetenummer wird abgewiesen, nicht zurechtgebogen.
 *
 * Bis 1.1.3 stand hier max(1, min(...)). Gemessen: ?dev=99999, ?dev=-5 und
 * ?dev=../../etc/passwd lieferten allesamt unauffaellig die Statuszeile von
 * Maeher 1, mit HTTP 200 und ohne Meldung. Ein Tippfehler in der
 * Loxone-Adresse holte damit stillschweigend die Werte eines ANDEREN
 * Geraets - eine Falschaussage, die richtig aussieht. */
$dev = 1;
if (isset($_GET['dev'])) {
    $mo_dev_roh = is_string($_GET['dev']) ? $_GET['dev'] : '';
    if (preg_match('/^[0-9]{1,2}$/', $mo_dev_roh) !== 1
        || (int) $mo_dev_roh < 1 || (int) $mo_dev_roh > mo_max_maeher()) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'MOWER;OK=0;ERR=DEV;ERLAUBT=1-' . mo_max_maeher() . "\n";
        exit;
    }
    $dev = (int) $mo_dev_roh;
}

/** Ist ein gueltiges Aktionstoken mitgeschickt worden?
 *
 * Ohne eingerichtetes Token ist die Antwort NEIN - ein leeres Soll darf
 * nicht auf ein leeres Ist passen, sonst schuetzt die Pruefung genau die
 * Anlage nicht, bei der noch nie jemand ein Token gesetzt hat.
 *
 * is_string() zuerst: ?token[]=x macht aus $_GET['token'] ein Feld, und
 * (string) auf ein Feld ist unter PHP 8 eine Warnung, die VOR
 * http_response_code() hinausgeht - der Statuscode fehlte dann.
 */
function mo_token_ok() {
    $cfg = mo_cfg_ro();
    $soll = isset($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    if ($soll === '') { return false; }
    $ist = (isset($_GET['token']) && is_string($_GET['token'])) ? $_GET['token'] : '';
    return hash_equals($soll, $ist);
}

/** Kein Token eingerichtet? Dann sagt die Antwort das - und nicht "falsch". */
function mo_token_eingerichtet() {
    $cfg = mo_cfg_ro();
    return trim((string) (isset($cfg['aktionstoken']) ? $cfg['aktionstoken'] : '')) !== '';
}

/* A7 (04.09.2026, gemessen): der json-Zweig stand VOR allen Aktionszweigen
 * und beendete die Anfrage mit exit. "?cmd=stop&json=1" lieferte deshalb
 * HTTP 200 und den Zustand als JSON - der Befehl wurde nie ausgefuehrt.
 * Dasselbe fuer selftest, ptest und roh. Ein Virtueller Ausgang liest die
 * Antwort nicht: der Befehl sah erfolgreich aus und geschah nie.
 *
 * Abgewiesen statt still entschieden - welche der beiden Absichten gemeint
 * war, weiss nur der Aufrufer. */
$mo_mehrdeutig = array();
foreach (array('cmd', 'ptest', 'roh', 'selftest') as $mo_p) {
    if (isset($_GET['json']) && isset($_GET[$mo_p])) { $mo_mehrdeutig[] = $mo_p; }
}
if ($mo_mehrdeutig) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'MOWER;OK=0;ERR=MEHRDEUTIG;MIT=json,' . implode(',', $mo_mehrdeutig) . "\n";
    exit;
}

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    $st = mo_state($dev, isset($_GET['refresh']));
    $st['ann'] = mo_ann_active($dev);
    $st['ptest'] = mo_ptest_active();
    $st['werte'] = mo_werte($dev, $st);
    echo json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

/* ---------- Selbsttest: Token pruefen, ohne etwas auszuloesen ----------
 * Hausregel: jeder Aktionsendpunkt beantwortet ?selftest=1&token=... , ohne
 * dass etwas passiert. Sonst laesst sich nicht feststellen, ob die Adresse im
 * Miniserver noch stimmt, ohne wirklich zu schalten.
 */
if (isset($_GET['selftest'])) {
    if (!mo_token_eingerichtet()) {
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
        exit;
    }
    if (!mo_token_ok()) {
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=TOKEN\n";
        exit;
    }
    /* A8: die Fassung stand hier fest im Quelltext und meldete in 1.1.1 bis
     * 1.1.3 unveraendert "1.1.0". Jetzt aus der Quelle, die auch LoxBerry
     * benutzt; ist sie nicht feststellbar, bleibt das Feld leer statt zu
     * raten. */
    $mo_fv = mo_fassung();
    echo 'SELFTEST;OK=1;TOKEN=OK;DEV=' . $dev
       . ';FASSUNG=' . ($mo_fv !== '' ? $mo_fv : 'unbekannt') . "\n";
    exit;
}

/* ---------- Die rohe Antwort des Moduls ----------
 *
 * Welche Befehle die JSON-Schnittstelle des Robonect-Moduls ausser status,
 * health, mode, start und stop noch kennt, ist NICHT gemessen. Statt zu
 * raten beantwortet die Anlage die Frage selbst.
 *
 * Nur LESEN: die Weissliste laesst ausschliesslich Befehle zu, von denen
 * belegt ist, dass sie nichts schalten. Ein freier Durchgriff auf ?cmd= des
 * Moduls waere eine Hintertuer - der Endpunkt liegt im unangemeldeten
 * Bereich. Tokenpflichtig ist er trotzdem: er gibt Geraeteinnenwerte preis.
 */
if (isset($_GET['roh'])) {
    /* B10: dieselbe Unterscheidung wie bei selftest und cmd. Abgewiesen
     * wurde vorher richtig, nur die Begruendung war falsch - auf einer
     * frisch eingerichteten Anlage suchte der Anwender einen Tippfehler in
     * einem Token, das es noch gar nicht gab. */
    if (!mo_token_eingerichtet()) {
        http_response_code(403);
        echo "ROH;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
        exit;
    }
    if (!mo_token_ok()) {
        http_response_code(403);
        echo "ROH;OK=0;ERR=TOKEN\n";
        exit;
    }
    $befehl = (isset($_GET['roh']) && is_string($_GET['roh'])) ? $_GET['roh'] : '';
    $erlaubt = array('status', 'health', 'version', 'timer', 'error', 'battery', 'motor', 'wlan', 'hour', 'weather');
    if (!in_array($befehl, $erlaubt, true)) {
        http_response_code(400);
        echo "ROH;OK=0;ERR=BEFEHL_UNBEKANNT;ERLAUBT=" . implode(',', $erlaubt) . "\n";
        exit;
    }
    list($j, $grund, $gtext) = mo_api_roh($befehl, $dev, '', 5);
    if ($j === null) {
        /* A16: der Statuscode taugt sonst nicht als Ueberwachungsmerkmal -
         * ein gescheiterter Geraetekontakt sah aus wie ein Erfolg. 502:
         * die Anfrage war richtig, die Gegenstelle hat nicht geliefert. */
        http_response_code($grund === 'nicht_konfiguriert' ? 409 : 502);
        echo 'ROH;OK=0;BEFEHL=' . $befehl . ';GRUND=' . $grund . ';INFO=' . $gtext . "\n";
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if (isset($_GET['cmd'])) {
    if (!mo_token_eingerichtet()) {
        http_response_code(403);
        echo "CMD;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
        exit;
    }
    if (!mo_token_ok()) {
        http_response_code(403);
        echo "CMD;OK=0;ERR=TOKEN\n";
        exit;
    }
    $cmd = (isset($_GET['cmd']) && is_string($_GET['cmd'])) ? $_GET['cmd'] : '';

    /* Trockenlauf: dieselbe Funktion, nur eine andere Auskunft. Ein
     * Trockenlauf, der einen anderen Weg nimmt, ist keiner - deshalb wird
     * mo_command() mit $probe = true gerufen und nicht etwas nachgebaut. */
    $probe = isset($_GET['probe']);

    if ($cmd === 'blade_reset') {
        if ($probe) {
            echo "CMD;OK=2;BEFEHL=blade_reset;PROBE=1;INFO=wuerde den Nullpunkt neu setzen\n";
            exit;
        }
        $ok = mo_blade_reset($dev);
        /* A3/A16: schlaegt es fehl, ist der Maeher nicht erreichbar - der
         * Nullpunkt bleibt dann ausdruecklich stehen. Der Grund gehoert in
         * die Antwort, sonst sucht der Anwender einen Schreibfehler. */
        if ($ok === 0) {
            http_response_code(502);
            echo "CMD;OK=0;BEFEHL=blade_reset;INFO=Maeher antwortet nicht - Nullpunkt unveraendert\n";
            exit;
        }
        echo 'CMD;OK=' . $ok . ";BEFEHL=blade_reset\n";
        exit;
    }
    list($ok, $info) = mo_command($cmd, $dev, isset($_GET['p']) && is_string($_GET['p']) ? $_GET['p'] : '', $probe);
    /* A16: bis 1.1.3 ging in diesem Zweig alles mit HTTP 200 hinaus - der
     * unbekannte Befehl, der nicht eingerichtete Maeher und der
     * fehlgeschlagene Geraetekontakt. Der ?roh=-Zweig nebenan setzte laengst
     * 400. Jetzt einheitlich: 400 = die Anfrage taugt nicht, 409 = die
     * Anlage ist nicht eingerichtet, 502 = das Geraet hat nicht geliefert.
     * OK=2 ist der Trockenlauf und bleibt 200. */
    if ($ok === 0) {
        if (strpos($info, 'unbekannt') !== false || strpos($info, 'Unzulaessig') !== false) {
            http_response_code(400);
        } elseif (strpos($info, 'nicht konfiguriert') !== false) {
            http_response_code(409);
        } else {
            http_response_code(502);
        }
    }
    echo 'CMD;OK=' . $ok . ';BEFEHL=' . preg_replace('/[^A-Za-z0-9_\-]/', '', $cmd)
       . ($probe ? ';PROBE=1' : '') . ';INFO=' . mo_mqtt_wert_saeubern($info) . "\n";
    exit;
}

if (isset($_GET['ptest'])) {
    /* Tokenpflichtig wie ?cmd= - Hausstandard fuer alle Aktionsendpunkte.
     * Der Aufruf setzt PTEST=1 fuer fuenf Minuten; das Loxone-Programm
     * schickt daraufhin eine echte Pushnachricht, und zusaetzlich geht
     * sofort eine MQTT-Meldung heraus. Ohne Token konnte jedes Geraet im
     * Netz dem Anwender Meldungen aufs Telefon schicken. */
    if (!mo_token_eingerichtet()) {
        http_response_code(403);
        echo "PTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
        exit;
    }
    if (!mo_token_ok()) {
        http_response_code(403);
        echo "PTEST;OK=0;ERR=TOKEN\n";
        exit;
    }
    @file_put_contents(mo_tmpdir() . '/ptest', '1');
    mo_log('Test-Pushnachricht angefordert (PTEST=1 fuer 5 Minuten)');
    /* Sofort melden, statt bis zu einer Minute auf den Cron zu warten.
     * Ueber HTTP holt sich der Miniserver den Merker beim naechsten Abruf;
     * ueber MQTT muss ihn das Plugin schicken - und ein Test, der erst eine
     * Minute spaeter wirkt, sieht aus wie ein Test, der nicht wirkt.
     * Ueber alle Maeher, weil der Merker fuer alle gilt. */
    foreach (array_keys(mo_mowers()) as $mo_n) {
        mo_mqtt_publish(null, $mo_n);
    }
    echo "PTEST;OK=1;DAUER=300\n";
    exit;
}

$st = mo_state($dev, isset($_GET['refresh']));
$cfg = mo_cfg_ro();

/* ?debug=1 nennt Name und ADRESSE des Maehers, WLAN-Pegel, Fehlertext und
 * den Nullpunkt des Messerwechsels. Das sind Innenwerte des Heimnetzes, und
 * der Endpunkt liegt im unangemeldeten Bereich - dieselbe Ueberlegung, aus
 * der ?roh= seit jeher tokenpflichtig ist. Die Statuszeile darunter bleibt
 * offen: sie ist die Auskunft, die Loxone braucht.
 * UMSTIEGSFOLGE: wer ?debug=1 von Hand aufruft, haengt jetzt &token=... an. */
if (isset($_GET['debug']) && !mo_token_ok()) {
    http_response_code(403);
    echo "DEBUG;OK=0;ERR=" . (mo_token_eingerichtet() ? 'TOKEN' : 'KEIN_TOKEN_EINGERICHTET') . "\n";
    exit;
}

if (isset($_GET['debug'])) {
    $m = mo_mower($dev);
    $lauf = mo_lauf_lesen();
    echo 'DEBUG  Maeher ' . $dev . ': ' . ($m ? $m['name'] . ' (' . $m['ip'] . ')' : 'nicht konfiguriert') . "\n";
    echo 'Status: ' . $st['text'] . ' (Code ' . $st['code'] . ')  Betriebsart: ' . $st['modus_text']
       . '  Batterie: ' . $st['batterie'] . "%\n";
    if ($st['grund'] !== '') { echo 'Grund: ' . $st['grund'] . ' - ' . $st['grundtext'] . "\n"; }
    if ($st['fehler']) { echo 'FEHLER ' . $st['fehler'] . ': ' . $st['fehlertext'] . "\n"; }
    echo 'Betriebsstunden: ' . $st['stunden'] . ' h  aktuelle Laufzeit: ' . $st['dauer'] . " min\n";
    /* Intervall und Nullpunkt gelten seit 1.1.4 je Maeher; hier stand bis
     * dahin der globale Wert und damit fuer jeden Maeher ausser dem zuletzt
     * quittierten eine Zahl, die nicht galt. */
    echo 'Messer: noch ' . $st['messer_rest'] . ' h bis zum Wechsel (Intervall '
       . ($m ? (int) $m['blade_hours'] : (int) $cfg['blade_hours'])
       . ' h, Nullpunkt bei ' . ($m ? (int) $m['blade_base'] : (int) $cfg['blade_base'])
       . ' h' . ($m && empty($m['blade_eigen']) ? ', geerbt aus der Vorgabe' : '') . ")\n";
    echo 'Temperatur: ' . $st['temperatur'] . ' C  Feuchte: ' . $st['feuchte'] . ' %  WLAN: ' . $st['wlan'] . " dBm\n";
    echo 'Letzter Cron-Lauf: ' . ($lauf['ts'] > 0 ? date('d.m.Y H:i:s', (int) $lauf['ts'])
        . ' (vor ' . mo_lauf_alter() . ' s), Zaehler ' . (int) $lauf['zaehler']
        . ', gemessen=' . (int) $lauf['ok'] : 'noch keiner') . "\n";
    echo 'Letzter Fehler: ' . (mo_fehler_alter_h() >= 0 ? 'vor ' . mo_fehler_alter_h() . ' h' : 'keiner bekannt') . "\n\n";
}

echo mo_zeile($dev, $st) . "\n";
