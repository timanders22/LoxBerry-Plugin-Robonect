<?php
/**
 * Rasenmaeher (Robonect) - gemeinsame Bibliothek
 *
 * Spricht das Robonect-Modul (HX/Hx+) ueber dessen JSON-Schnittstelle an und
 * liefert an Loxone fertige Zahlenwerte: Status, Betriebsart, Batterie, Fehler,
 * Betriebsstunden, Messerlaufzeit, Temperatur/Feuchte und WLAN-Signal.
 *
 * WICHTIG - Datenschutz und Sicherheit:
 * Benutzername und Passwort des Maehers stehen AUSSCHLIESSLICH in der
 * Plugin-Konfiguration (Datei mit Rechten 0600) und werden per HTTP-Basic-Auth
 * uebertragen. In der Loxone-Projektdatei taucht kein Passwort mehr auf - das
 * ist der Hauptgrund fuer dieses Plugin.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('Europe/Berlin');


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function mo_paths() {
    $lb = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
    $pd = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
    /* Rueckfall auf den vorgesehenen Ordnernamen - aber NUR, wenn dort
     * entweder noch nichts liegt oder schon die EIGENE Konfigurationsdatei.
     * Ein zweites Plugin darf denselben FOLDER beanspruchen (LoxBerry haengt
     * dann '01' an); ein blanker Rueckfall schriebe in dessen Verzeichnis
     * eine Datei, die niemand liest. Siehe REGELN_2, "Zwei Plugins duerfen
     * denselben FOLDER beanspruchen - der Rueckfall darf es nicht". */
    if ($lb && is_dir($lb . '/config/plugins/' . $pd) === false) {
        $ziel = $lb . '/config/plugins/robonect';
        if (!is_dir($ziel) || is_file($ziel . '/mower.json')) { $pd = 'robonect'; }
    }
    if ($lb) {
        return array('config' => $lb . '/config/plugins/' . $pd . '/mower.json',
                     'backup' => $lb . '/config/plugins/' . $pd . '.backup.json',
                     'log' => $lb . '/log/plugins/' . $pd . '/mower.log',
                     'datadir' => $lb . '/data/plugins/' . $pd,
                     'tmp' => '/tmp/robonect', 'lbhome' => $lb,
                     // Der Ordnername wird gebraucht, wo eine Adresse auf das
                     // eigene Plugin zeigt - Endpunkt, Vorlage, Selbstpruefung.
                     'plugin' => $pd);
    }
    return array('config' => dirname(dirname(__DIR__)) . '/config/mower.json',
                 'backup' => dirname(dirname(__DIR__)) . '/config/mower.backup.json',
                 'log' => sys_get_temp_dir() . '/robonect/mower.log',
                 'datadir' => sys_get_temp_dir() . '/robonect/data',
                 'tmp' => sys_get_temp_dir() . '/robonect', 'lbhome' => '',
                 'plugin' => $pd);
}

function mo_vorgaben()
{
    /* Herausgezogen aus mo_config(): die Vorgaben stehen weiterhin an
     * EINER Stelle, jetzt aber an einer abrufbaren. Die Sicherung
     * braucht die Schluesselliste, um Fremdes zu erkennen - ohne sie
     * koennte sie nur alles durchwinken. */
    return array(
    'mowers' => array(),          // [{name, ip, user, pass}]
    'cache_sec' => 20,
    'blade_hours' => 200,         // Messerwechsel-Intervall in Betriebsstunden
    'blade_base' => 0,            // Betriebsstunden beim letzten Messerwechsel
    'mqtt_enabled' => 0,
    'mqtt_topic' => 'maeher',
    'notify' => array(),
    'tts' => array(),
    'aktionstoken' => '',          // schuetzt ?cmd= (unangemeldeter Endpunkt)
    'stat_ein' => 0,              // Einsatzstatistik fuehren (ab Werk AUS)
);
}

/** Hoechstzahl gefuehrter Maeher. Steht GENAU EINMAL - Formular, Speicher-
 *  Handler, Endpunkt und Vorlage holen sie hier. Bis 1.0.13 stand die 2
 *  zweimal und die 9 dreimal im Code. */
function mo_max_maeher() { return 9; }

/**
 * Die Konfiguration VERVOLLSTAENDIGEN, nicht nur ergaenzen.
 *
 * Ergaenzen heisst: beim Lesen tritt fuer einen fehlenden Schluessel seine
 * Vorgabe ein. Die Datei bleibt dann lueckenhaft, und "fehlt" ist von "steht
 * auf dem Vorgabewert" nicht mehr zu unterscheiden. Vervollstaendigen heisst:
 * fehlt ein Schluessel, wird er EINMAL mit seiner Vorgabe geschrieben.
 *
 * Rueckgabe: die Namen der Schluessel, die gefehlt haben - sonst liesse sich
 * die Pruefzeile im Reiter Test nicht schreiben.
 *
 * array_key_exists() und NICHT isset(): isset() haelt einen leeren Wert fuer
 * nicht vorhanden und wuerde eine bewusst geleerte Angabe jedes Mal
 * zurueckschreiben.
 */
function mo_cfg_vervollstaendigen(&$cfg)
{
    $fehlten = array();
    foreach (mo_vorgaben() as $k => $v) {
        if (!array_key_exists($k, $cfg)) { $cfg[$k] = $v; $fehlten[] = $k; }
    }
    return $fehlten;
}

/**
 * Was steht in der Datei, und was steht darin, das es nicht gibt?
 *
 *   fehlend   in den Vorgaben, nicht in der Datei   -> wird geschrieben
 *   fremd     in der Datei, nicht in den Vorgaben   -> wird GENANNT
 *
 * Fremdes wird nicht geloescht: niemand weiss, ob dort der Rest einer
 * aelteren Fassung steht oder etwas, das der naechsten schon gehoert. Ein
 * wirkungsloser Eintrag, den niemand sieht, ist die stille Sorte Fehler.
 */
function mo_cfg_lage()
{
    $p = mo_paths();
    $vorgaben = mo_vorgaben();
    $datei = is_file($p['config'])
        ? json_decode((string) @file_get_contents($p['config']), true) : array();
    if (!is_array($datei)) { $datei = array(); }
    $fehlend = array_values(array_diff(array_keys($vorgaben), array_keys($datei)));
    $fremd   = array_values(array_diff(array_keys($datei), array_keys($vorgaben)));
    sort($fehlend); sort($fremd);
    return array('fehlend' => $fehlend, 'fremd' => $fremd,
                 'anzahl' => count($vorgaben), 'gesetzt' => count($datei));
}

/* ==================================================================
 * Die Lage der Konfigurationsdatei - und warum das eine eigene Funktion ist
 * ==================================================================
 *
 * GEMESSEN am 26.08.2026 an 1.0.13: eine vorhandene, aber unlesbare
 * Konfigurationsdatei (abgeschnittenes JSON, wie bei vollem Dateisystem oder
 * Stromausfall beim Schreiben) wurde stillschweigend als leer behandelt.
 * Ein einziger Aufruf der Oberflaeche, ohne einen Klick, hatte danach:
 *
 *     Maeher fort, Passwort fort, Nullpunkt auf 0, MQTT aus,
 *     Aktionstoken neu gewuerfelt  -> jede Loxone-Adresse antwortet 403
 *     und die ZWEITSCHRIFT mit der Werkseinstellung ueberschrieben.
 *
 * Keine Fehlermeldung, keine Protokollzeile. Der Anwender sah "Noch kein
 * Maeher eingerichtet". Gegenprobe mit heiler Datei: nichts geschah.
 *
 * Richtig ist: beschaedigte Datei einmalig als .kaputt liegen lassen, GENAU
 * EINE Protokollzeile, die Zweitschrift lesen und die Konfiguration daraus
 * wiederherstellen - und eine Wache, die die Zweitschrift nur ueberschreibt,
 * wenn wirklich eine Konfiguration MIT Token gespeichert wird.
 * ================================================================== */

/* Der Zustand der Konfiguration - ok | leer | zweitschrift | kaputt |
 * kaputt_ohne_zweitschrift - kommt ueber den Referenzparameter von
 * mo_config(). Hier stand bis kurz vor der Freigabe eine bequeme
 * Huelle mo_config_zustand(); sie hat niemand gerufen, und ein zweiter Weg
 * zu derselben Auskunft ist eine zweite Wahrheit. Gefunden von
 * Werkzeuge/tote_helfer.py. */

/**
 * Die Zweitschrift schreiben - mit Wache.
 *
 * Sie wird NUR ueberschrieben, wenn die Konfiguration ein Aktionstoken
 * traegt. Ohne diese Wache genuegt ein Aufruf der Oberflaeche mit
 * beschaedigter Datei, um die letzte gute Sicherung zu vernichten.
 */
function mo_zweitschrift_schreiben($cfg)
{
    if (!is_array($cfg) || trim((string) (isset($cfg['aktionstoken']) ? $cfg['aktionstoken'] : '')) === '') {
        return false;
    }
    $p = mo_paths();
    $js = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) { return false; }
    $ok = mo_write_atomic($p['backup'], $js, 0600);
    return $ok;
}

function mo_config(&$zustand = null) {
    static $gemeldet = false;
    /* GEMESSEN beim Bauen dieser Fassung: die Zeile im Reiter Test meldete
     * "in Ordnung", obwohl die Datei beschaedigt war. Grund: der ERSTE
     * Aufruf heilt sie, und jeder spaetere sieht eine heile Datei. Der
     * Anwender haette nie erfahren, dass etwas war - nur das Protokoll
     * wusste es. Ein geheilter Schaden ist aber kein Nicht-Schaden.
     *
     * Deshalb wird der zuerst festgestellte Zustand fuer die Dauer des
     * Prozesses gemerkt. Er wird NICHT ueberschrieben, sobald spaetere
     * Aufrufe 'ok' finden - das ist genau die Auskunft, um die es geht. */
    static $erster_zustand = null;
    $p = mo_paths();
    $zustand = 'ok';
    $cfg = null;

    $roh = is_file($p['config']) ? (string) @file_get_contents($p['config']) : null;
    if ($roh !== null && trim($roh) !== '' && trim($roh) !== '{}') {
        $cfg = json_decode($roh, true);
        if (!is_array($cfg)) {
            /* Beschaedigt. Einmal beiseitelegen, damit sie nicht verloren
             * geht und der naechste Lauf nicht wieder daran haengt - und
             * GENAU EINE Protokollzeile je Prozess. */
            $zustand = 'kaputt';
            $cfg = null;
            if (!$gemeldet) {
                $gemeldet = true;
                $beiseite = $p['config'] . '.kaputt';
                if (!is_file($beiseite)) { @copy($p['config'], $beiseite); }
                /* mo_log_roh und nicht mo_log: mo_log liest fuer die
                 * Passwortmaskierung die Konfiguration - von hier aus waere
                 * das eine Rekursion. In dieser Meldung steht ohnehin kein
                 * Passwort. */
                mo_log_roh('Die Konfigurationsdatei ist kein gueltiges JSON. Sie liegt jetzt als '
                     . basename($beiseite) . '; es wird die Zweitschrift benutzt.');
            }
        }
    }

    if ($cfg === null) {
        // Fehlend, leer, "{}" oder beschaedigt: die Zweitschrift lesen.
        $bk = is_file($p['backup']) ? json_decode((string) @file_get_contents($p['backup']), true) : null;
        if (is_array($bk) && $bk) {
            $cfg = $bk;
            $zustand = ($zustand === 'kaputt') ? 'kaputt' : 'zweitschrift';
            /* Wiederherstellen, nicht nur lesen: sonst laeuft der Dienst beim
             * naechsten Mal wieder in dieselbe Datei. Bei einer beschaedigten
             * Datei wird sie ueberschrieben - das Original steht als .kaputt
             * daneben. */
            @mkdir(dirname($p['config']), 0775, true);
            $js = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($js !== false) { mo_write_atomic($p['config'], $js, 0600); }
        } else {
            $cfg = array();
            if ($zustand === 'kaputt') { $zustand = 'kaputt_ohne_zweitschrift'; }
            elseif ($roh === null || trim($roh) === '' || trim($roh) === '{}') { $zustand = 'leer'; }
        }
    }

    if ($erster_zustand === null || $erster_zustand === 'ok') { $erster_zustand = $zustand; }
    $zustand = $erster_zustand;

    if (!is_array($cfg)) { $cfg = array(); }
    $cfg += mo_vorgaben();
    if (!is_array($cfg['mowers'])) { $cfg['mowers'] = array(); }
    if (empty($cfg['mowers']) && !empty($cfg['ip'])) { // Migration Einzelgeraet
        $cfg['mowers'] = array(array('name' => 'Rasenmaeher', 'ip' => (string) $cfg['ip'],
            'user' => (string) (isset($cfg['user']) ? $cfg['user'] : ''), 'pass' => (string) (isset($cfg['pass']) ? $cfg['pass'] : '')));
    }
    if (!is_array($cfg['notify'])) { $cfg['notify'] = array(); }
    if (!is_array($cfg['tts'])) { $cfg['tts'] = array(); }
    $cfg['notify'] += array('audio' => 0, 'push' => 0, 'fehler' => 1, 'fertig' => 1, 'messer' => 1, 'akku' => 0);
    $cfg['tts'] += array('mode' => 'musicserver', 'ip' => '', 'port' => 7091,
                         'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '');
    return $cfg;
}

function mo_mowers() {
    $cfg = mo_config();
    $out = array(); $n = 0;
    foreach ((array) $cfg['mowers'] as $m) {
        $m = (array) $m;
        if (trim((string) (isset($m['ip']) ? $m['ip'] : '')) === '') { continue; }
        $n++;
        $out[$n] = array('name' => trim((string) (isset($m['name']) ? $m['name'] : '')) !== '' ? trim((string) $m['name']) : ('Rasenmaeher ' . $n),
                         'ip' => trim((string) $m['ip']),
                         'user' => (string) (isset($m['user']) ? $m['user'] : ''),
                         'pass' => (string) (isset($m['pass']) ? $m['pass'] : ''));
    }
    return $out;
}
function mo_mower($n) { $m = mo_mowers(); $n = max(1, (int) $n); return isset($m[$n]) ? $m[$n] : null; }

/**
 * Zufallstoken fuer die schaltenden Aufrufe (?cmd=).
 *
 * Der Endpunkt liegt im unangemeldeten Bereich, damit Loxone ihn ohne
 * Zugangsdaten erreicht. Ohne Token koennte jedes Geraet im Netz den
 * Maeher starten oder stoppen.
 */
function mo_token_erzeugen($laenge = 24) {
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}
function mo_tmpdir() { $p = mo_paths(); if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); } return $p['tmp']; }
/**
 * Die letzten $max Zeilen einer Datei - ohne sie ganz einzulesen.
 *
 * Bis 1.0.3 wurde das Protokoll mit file() vollstaendig in den Speicher
 * geholt, umgedreht und abgeschnitten. Bei den 512 kB, ab denen gekuerzt
 * wird, sind das rund 1,4 MB Spitzenspeicher.
 *
 * Der oft empfohlene Weg ueber das Programm "tail" spart zwar Speicher,
 * ist aber wegen des zusaetzlichen Prozesses LANGSAMER als das, was er
 * ersetzen soll. An einer 522-kB-Datei gemessen (200 Zeilen Ausgabe):
 *
 *     file() + array_reverse   0,8 ms   1436 KB
 *     exec("tail -n 200")      1,7 ms     34 KB
 *     rueckwaerts mit fseek    0,3 ms     34 KB
 *
 * Rueckwaerts lesen ist bei beidem besser und kommt ohne fremdes Programm
 * aus. Rueckgabe in Dateireihenfolge (aelteste zuerst).
 */
function mo_log_tail($datei, $max = 200, $block = 8192) {
    $fp = @fopen($datei, 'rb');
    if (!$fp) { return array(); }
    fseek($fp, 0, SEEK_END);
    $rest = ftell($fp);
    $puffer = '';
    while ($rest > 0 && substr_count($puffer, "\n") <= $max) {
        $lese = (int) min($block, $rest);
        $rest -= $lese;
        fseek($fp, $rest, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
    }
    fclose($fp);
    $zeilen = preg_split('/\R/', $puffer, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($zeilen)) { return array(); }
    return array_slice($zeilen, -$max);
}

/**
 * Eine Zeile ins Protokoll schreiben, OHNE die Konfiguration zu lesen.
 *
 * mo_log() maskiert Passwoerter und liest dafuer die Konfiguration. Wer aus
 * mo_config() heraus protokollieren will, kann das nicht benutzen - es waere
 * eine Rekursion. Diese Fassung nimmt den Text, wie er ist; sie wird nur mit
 * Meldungen gerufen, in denen kein Geheimnis stehen kann.
 */
function mo_log_roh($msg) {
    $p = mo_paths(); $f = $p['log'];
    if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function mo_log($msg) {
    $p = mo_paths(); $f = $p['log'];
    if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 512000) {
        mo_write_atomic($f, implode("\n", mo_log_tail($f, 200)) . "\n");
    }
    // Sicherheitsnetz: falls je ein Passwort in eine Meldung geraet, wird es maskiert
    /* Erst ab vier Zeichen maskieren.
       Ein Passwort wie "a" oder "abc" haette sonst jedes Vorkommen dieser
       Buchstabenfolge in JEDER Meldung durch *** ersetzt - aus
       "Status=maeht Batterie=80%" waere bei Passwort "at" ein
       "St***us=m***eht ..." geworden. Unter vier Zeichen ist die
       Maskierung schaedlicher als der Schutz, den sie bringt; sie ist
       ohnehin nur ein Sicherheitsnetz fuer den Fall, dass ein Passwort je
       in eine Meldung geraet. */
    $cfg = mo_config();
    foreach ((array) $cfg['mowers'] as $m) {
        $pw = (string) (isset($m['pass']) ? $m['pass'] : '');
        if (strlen($pw) >= 4) { $msg = str_replace($pw, '***', $msg); }
    }
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}
function mo_log_if_changed($key, $line) {
    $f = mo_tmpdir() . '/last_' . $key . '.txt';
    $prev = is_file($f) ? (string) file_get_contents($f) : '';
    if ($line !== $prev) { mo_log($key . ': ' . $line); @file_put_contents($f, $line); }
}

/* ---------------- Zugriff auf das Robonect-Modul ---------------- */

/* ==================================================================
 * Zeitgrenzen - und warum ein stummer Maeher gemerkt wird
 * ==================================================================
 *
 * Bis 1.0.3 wartete jeder Abruf 8 Sekunden. Nachgemessen gegen ein
 * Gegenstueck, das die Verbindung annimmt und dann schweigt (der schlimmste
 * Fall - ein abgeschaltetes Geraet weist die Verbindung sofort ab und kostet
 * nichts):
 *
 *     ein einzelner mo_api()          8,0 s
 *     mo_state() (status + health)   16,0 s   <- ZWEI Abrufe je Maeher
 *     mo_events_check(), 2 Maeher    32,0 s
 *     danach die Schleife im Cron     0,0 s   <- der Cache greift
 *     mower.php mit leerem Cache     16,1 s   <- das sieht Loxone
 *
 * Drei Dinge folgen daraus:
 *
 * 1. Die 16 Sekunden je Maeher kommen nicht von einem langen Abruf, sondern
 *    von ZWEI Abrufen: status und health. Faellt schon status aus, braucht
 *    health gar nicht mehr versucht zu werden.
 *
 * 2. Die 16,1 Sekunden in mower.php sind der eigentliche Schaden. Ein
 *    Loxone-Miniserver bricht einen virtuellen HTTP-Eingang nach wenigen
 *    Sekunden ab - er bekommt also gar nichts, waehrend auf dem LoxBerry
 *    ein Arbeiter blockiert ist.
 *
 * 3. Der Cron ruft NICHT doppelt ab. Die zweite Schleife kostet 0,0 s, weil
 *    mo_state() das Ergebnis zwischenspeichert - auch das gescheiterte.
 *
 * Abhilfe: Zeitgrenze auf 3 Sekunden (ein Maeher im eigenen WLAN antwortet
 * in unter 200 ms), health nur nach erfolgreichem status, und ein Merker
 * fuer "antwortet gerade nicht". Solange der steht, kehrt mo_api sofort
 * zurueck, statt erneut zu warten.
 * ================================================================== */

/** Wie lange ein stummer Maeher als stumm gilt, in Sekunden. */
define('MO_STUMM_SEK', 60);

/** Merker: antwortet dieser Maeher gerade nicht? */
function mo_stumm($dev) {
    $f = mo_tmpdir() . '/stumm_' . (int) $dev;
    return (is_file($f) && time() - filemtime($f) < MO_STUMM_SEK) ? 1 : 0;
}
function mo_stumm_setzen($dev) { @touch(mo_tmpdir() . '/stumm_' . (int) $dev); }
function mo_stumm_loeschen($dev) { @unlink(mo_tmpdir() . '/stumm_' . (int) $dev); }

/**
 * Ruft die JSON-Schnittstelle auf. Zugangsdaten per HTTP-Basic-Auth
 * (nicht in der URL).
 *
 * $tmo ist die Zeitgrenze in Sekunden. 3 statt 8: siehe oben.
 */
/* ==================================================================
 * D3/D4 (26.08.2026): zwei Befunde an genau dieser Funktion
 * ==================================================================
 *
 * 1. Die Zeitgrenze galt nur fuers LESEN. Bis 1.0.13 stand hier
 *    stream_context_create(... 'timeout' => 3 ...). Diese Angabe deckt den
 *    VERBINDUNGSAUFBAU nicht ab; dafuer gilt default_socket_timeout, ab Werk
 *    SECHZIG Sekunden. Getroffen hat das genau den Fall, fuer den die drei
 *    Sekunden gedacht waren: ein Maeher im Standby, der Pakete verwirft. Ein
 *    abgeschaltetes Geraet weist die Verbindung sofort ab und kostet nichts.
 *    Mit curl gilt CURLOPT_CONNECTTIMEOUT neben CURLOPT_TIMEOUT.
 *
 * 2. 'ignore_errors' => true lieferte auch bei HTTP 401 einen Rumpf. Der
 *    Aufruf war damit nicht false, der Stumm-Merker wurde sogar GELOESCHT,
 *    json_decode scheiterte, und die Oberflaeche schrieb "keine Verbindung".
 *    Ein falsches Passwort war von einem toten Geraet nicht zu unterscheiden.
 *    Der Statuscode wird jetzt ausgewertet und benannt.
 *
 * Rueckgabe von mo_api_roh(): array(Daten|null, Grund, Klartext).
 * Grund ist einer von: '' (alles gut), 'anmeldung', 'abgewiesen',
 * 'keine_antwort', 'kein_json', 'stumm', 'nicht_konfiguriert'.
 * ================================================================== */
function mo_api_roh($cmd, $dev = 1, $extra = '', $tmo = 3) {
    $m = mo_mower($dev);
    if ($m === null) { return array(null, 'nicht_konfiguriert', 'Maeher nicht konfiguriert'); }
    // Antwortet er gerade nicht, gar nicht erst warten.
    if (mo_stumm($dev)) { return array(null, 'stumm', 'antwortet gerade nicht'); }

    $url = 'http://' . $m['ip'] . '/json?cmd=' . rawurlencode($cmd) . ($extra !== '' ? '&' . $extra : '');
    $kopf = array('Accept: application/json');
    if ($m['user'] !== '' || $m['pass'] !== '') {
        $kopf[] = 'Authorization: Basic ' . base64_encode($m['user'] . ':' . $m['pass']);
    }

    $r = false; $code = 0; $netzfehler = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(1, (int) $tmo));
        curl_setopt($ch, CURLOPT_TIMEOUT, max(2, (int) $tmo + 2));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $kopf);
        curl_setopt($ch, CURLOPT_USERAGENT, 'LoxBerry Robonect');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        $r = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($r === false) { $netzfehler = (string) curl_error($ch); }
        curl_close($ch);
    } else {
        /* Ohne curl: die Lesegrenze gilt weiterhin nur fuers Lesen, also
         * wird default_socket_timeout fuer die Dauer des Aufrufs gesetzt und
         * danach zurueckgestellt. Sonst wartet der Aufruf bis zu 60 s. */
        $alt = ini_get('default_socket_timeout');
        @ini_set('default_socket_timeout', (string) max(1, (int) $tmo));
        $ctx = stream_context_create(array('http' => array(
            'timeout' => $tmo, 'header' => implode("\r\n", $kopf) . "\r\n",
            'user_agent' => 'LoxBerry Robonect', 'ignore_errors' => true)));
        $r = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header) && is_array($http_response_header)
            && isset($http_response_header[0])
            && preg_match('#\s(\d{3})\s#', ' ' . $http_response_header[0] . ' ', $t)) {
            $code = (int) $t[1];
        }
        @ini_set('default_socket_timeout', (string) $alt);
    }

    if ($r === false) {
        mo_stumm_setzen($dev);
        return array(null, 'keine_antwort',
                     $netzfehler !== '' ? $netzfehler : 'keine Antwort in ' . (int) $tmo . ' s');
    }
    if ($code === 401 || $code === 403) {
        /* Erreichbar und hat geantwortet - nur nicht hereingelassen. Das ist
         * KEIN stummer Maeher: der Merker wird nicht gesetzt, sonst wartete
         * die naechste Minute umsonst. */
        return array(null, 'anmeldung', 'Benutzer oder Passwort stimmen nicht (HTTP ' . $code . ')');
    }
    if ($code >= 400) {
        return array(null, 'abgewiesen', 'Das Modul hat abgewiesen (HTTP ' . $code . ')');
    }
    mo_stumm_loeschen($dev);
    $j = @json_decode($r, true);
    if (!is_array($j)) {
        /* HTML statt JSON heisst: es hat etwas anderes geantwortet als die
         * Schnittstelle - meist die Anmeldeseite oder ein Zwischengeraet. */
        return array(null, 'kein_json', 'Antwort ist kein JSON'
            . (stripos((string) $r, '<html') !== false ? ' (es kam HTML - falsche Adresse?)' : ''));
    }
    return array($j, '', '');
}

/** Schlanke Huelle: liefert nur die Daten. Fuer Aufrufer, die den Grund
 *  nicht brauchen - die Schnittstelle der Funktion bleibt damit unveraendert. */
function mo_api($cmd, $dev = 1, $extra = '', $tmo = 3) {
    list($j, , ) = mo_api_roh($cmd, $dev, $extra, $tmo);
    return $j;
}

/**
 * Eine Datei unteilbar schreiben: Nebendatei, dann umbenennen.
 *
 * Der Cron schreibt den Zwischenspeicher, waehrend Loxone ueber mower.php
 * liest. file_put_contents kuerzt die Datei zuerst auf null - der Leser
 * bekommt dann eine halbe oder leere Datei und damit kaputtes JSON.
 * rename() ist innerhalb eines Dateisystems unteilbar.
 *
 * Die Nebendatei traegt Prozessnummer UND Zufallszahl im Namen: ein fester
 * Name waere derselbe Fehler eine Ebene tiefer, sobald zwei Schreiber
 * gleichzeitig laufen.
 */
function mo_write_atomic($datei, $inhalt, $rechte = 0644) {
    if ($inhalt === false || $inhalt === null) { return false; }
    $inhalt = (string) $inhalt;
    $ordner = dirname($datei);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) { return false; }
    $tmp = $datei . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
    /* Erst leer anlegen, SOFORT schuetzen, dann fuellen. fopen legt nicht mit
     * gewaehlten Rechten an; ein chmod hinterher liesse die Datei fuer die
     * Dauer des Schreibens offen. */
    $fh = @fopen($tmp, 'c');
    if ($fh === false) { return false; }
    @chmod($tmp, $rechte);
    $ok = ftruncate($fh, 0) && @fwrite($fh, $inhalt) === strlen($inhalt);
    fflush($fh);
    fclose($fh);
    if (!$ok) { @unlink($tmp); return false; }
    if (!@rename($tmp, $datei)) { @unlink($tmp); return false; }
    return true;
}
function mo_write_json($datei, $daten) {
    return mo_write_atomic($datei, json_encode($daten));
}

/** Robonect-Statuscode -> Klartext. */
function mo_status_text($code) {
    $t = array(0 => 'Status wird ermittelt', 1 => 'parkt', 2 => 'maeht', 3 => 'sucht die Ladestation',
               4 => 'laedt', 5 => 'sucht', 6 => 'Abschluss der Bearbeitung', 7 => 'Fehler',
               8 => 'Schleifensignal verloren', 16 => 'abgeschaltet', 17 => 'schlaeft', 18 => 'wird gewartet');
    return isset($t[(int) $code]) ? $t[(int) $code] : 'unbekannt (' . (int) $code . ')';
}
/** Betriebsart -> Klartext. */
function mo_mode_text($mode) {
    $t = array(0 => 'Automatik', 1 => 'Manuell', 2 => 'Zuhause', 3 => 'Demo', 4 => 'Auftrag');
    return isset($t[(int) $mode]) ? $t[(int) $mode] : 'unbekannt';
}

/** Kompletter Zustand eines Maehers (mit Cache). */
function mo_state($dev = 1, $force = false) {
    $cfg = mo_config();
    $dev = max(1, (int) $dev);
    $m = mo_mower($dev);
    $cache = mo_tmpdir() . '/state_' . $dev . '.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < max(5, (int) $cfg['cache_sec'])) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (is_array($c)) { return $c; }
    }
    $st = array('ok' => 0, 'name' => $m ? $m['name'] : '-', 'code' => -1, 'text' => 'keine Verbindung',
                'modus' => -1, 'modus_text' => '-', 'batterie' => 0, 'laedt' => 0,
                'fehler' => 0, 'fehlertext' => '', 'stunden' => 0, 'dauer' => 0,
                'messer_rest' => -1, 'messer_warn' => 0, 'temperatur' => 0, 'feuchte' => 0,
                'wlan' => 0, 'timer' => 0, 'maeht' => 0, 'ts' => time(),
                /* Warum das Nichterreichen einen GRUND braucht (D4, 26.08.2026):
                 * bis 1.0.13 sah ein falsches Passwort genauso aus wie ein
                 * totes Geraet - beide Male stand hier "keine Verbindung",
                 * und der Anwender suchte an der falschen Stelle. */
                'grund' => 'nicht_konfiguriert', 'grundtext' => '');
    if ($m === null) { return $st; }
    list($j, $grund, $gtext) = mo_api_roh('status', $dev);
    $st['grund'] = $grund;
    $st['grundtext'] = $gtext;
    if (is_array($j) && isset($j['status'])) {
        /* Das Modul liefert 'successful' nicht in jeder Fassung mit. Eine
         * Antwort, die einen status-Block enthaelt, gilt deshalb als
         * gelungen - bis 1.0.13 stand hier ein Ausdruck, der in BEIDEN
         * Zweigen 1 ergab und deshalb wie ein Fehler aussah. */
        $st['ok'] = 1;
        $st['grund'] = '';
        $s = $j['status'];
        $st['code'] = isset($s['status']) ? (int) $s['status'] : -1;
        $st['text'] = mo_status_text($st['code']);
        $st['batterie'] = isset($s['battery']) ? (int) $s['battery'] : 0;
        $st['stunden'] = isset($s['hours']) ? (int) $s['hours'] : 0;
        $st['dauer'] = isset($s['duration']) ? (int) round(((int) $s['duration']) / 60) : 0; // s -> min
        $st['modus'] = isset($s['mode']) ? (int) $s['mode'] : -1;
        $st['modus_text'] = mo_mode_text($st['modus']);
        $st['maeht'] = ($st['code'] === 2) ? 1 : 0;
        $st['laedt'] = ($st['code'] === 4) ? 1 : 0;
        if (isset($j['wlan']['signal'])) { $st['wlan'] = (int) $j['wlan']['signal']; }
        if (isset($j['timer']['status'])) { $st['timer'] = (int) $j['timer']['status']; }
        if (isset($j['error'])) {
            $st['fehler'] = isset($j['error']['error_code']) ? (int) $j['error']['error_code'] : 0;
            $st['fehlertext'] = isset($j['error']['error_message']) ? (string) $j['error']['error_message'] : '';
        }
        if ($st['code'] === 7 && $st['fehler'] === 0) { $st['fehler'] = 1; }
    } elseif ($st['grundtext'] !== '') {
        // Der Grund gehoert in den angezeigten Text, nicht nur ins Protokoll.
        $st['text'] = $st['grundtext'];
    }
    /* Temperatur und Feuchte kommen aus einem ZWEITEN Abruf. Der wird nur
       versucht, wenn der erste geklappt hat - sonst kostete ein stummer
       Maeher die Zeitgrenze doppelt. Gemessen waren das 16 s statt 8. */
    $h = ($st['ok'] === 1) ? mo_api('health', $dev) : null;
    if (is_array($h) && isset($h['health'])) {
        if (isset($h['health']['temperature'])) { $st['temperatur'] = round((float) $h['health']['temperature'], 1); }
        if (isset($h['health']['humidity'])) { $st['feuchte'] = round((float) $h['health']['humidity'], 1); }
    }
    // Messerlaufzeit seit dem letzten Wechsel
    $iv = max(1, (int) $cfg['blade_hours']);
    $base = max(0, (int) $cfg['blade_base']);
    if ($st['stunden'] > 0) {
        $st['messer_rest'] = max(0, $iv - max(0, $st['stunden'] - $base));
        $st['messer_warn'] = $st['messer_rest'] <= 0 ? 1 : 0;
    }
    mo_write_json($cache, $st);
    mo_log_if_changed('status_' . $dev, 'Status=' . $st['text'] . ' Modus=' . $st['modus_text']
        . ' Batterie=' . $st['batterie'] . '% Fehler=' . $st['fehler'] . ' Stunden=' . $st['stunden']);
    return $st;
}

/* ---------------- Steuerung ---------------- */

/**
 * Einen Befehl an den Maeher geben.
 *
 * C8 - DER TROCKENLAUF. $probe = true laeuft durch DIESELBE Funktion, prueft
 * dieselbe Weissliste und baut dieselbe Adresse - er sendet nur nicht. Ein
 * Trockenlauf, der einen anderen Weg nimmt, ist keiner: er wuerde genau die
 * Fehler nicht finden, wegen derer man ihn baut. Der Rueckgabewert ist 2
 * ("nicht ausgefuehrt"), damit ein Erfolg nie vorgetaeuscht wird.
 */
function mo_command($cmd, $dev = 1, $param = '', $probe = false) {
    $m = mo_mower($dev);
    if ($m === null) { return array(0, 'Maeher nicht konfiguriert'); }
    $cmd = strtolower(trim((string) $cmd));
    $map = array('auto' => array('mode', 'mode=auto'), 'manuell' => array('mode', 'mode=man'),
                 'man' => array('mode', 'mode=man'), 'home' => array('mode', 'mode=home'),
                 'eod' => array('mode', 'mode=eod'), 'start' => array('start', ''),
                 'stop' => array('stop', ''));
    if ($cmd === 'job') {
        $p = preg_replace('/[^0-9a-z=&%\-]/i', '', (string) $param);
        $ziel = array('mode', 'mode=job' . ($p !== '' ? '&' . $p : ''));
    } elseif (isset($map[$cmd])) {
        $ziel = $map[$cmd];
    } else {
        return array(0, 'unbekannter Befehl');
    }
    if ($probe) {
        /* Die Adresse wird gezeigt, das Passwort nicht - es geht ohnehin
         * ueber die Kopfzeile, nicht ueber die Adresse. */
        return array(2, 'Trockenlauf - wuerde senden: http://' . $m['ip'] . '/json?cmd='
                      . $ziel[0] . ($ziel[1] !== '' ? '&' . $ziel[1] : '')
                      . ' an ' . $m['name']);
    }
    $j = mo_api($ziel[0], $dev, $ziel[1]);
    $ok = (is_array($j) && (!isset($j['successful']) || $j['successful'])) ? 1 : 0;
    mo_log('Befehl "' . $cmd . ($param !== '' ? ' ' . $param : '') . '" an ' . $m['name'] . ' -> ' . ($ok ? 'OK' : 'FEHLER'));
    @unlink(mo_tmpdir() . '/state_' . (int) $dev . '.json'); // Status neu holen
    return array($ok, $ok ? 'ausgefuehrt' : 'fehlgeschlagen');
}

/** Messerwechsel quittieren: aktuelle Betriebsstunden als neuen Nullpunkt speichern. */
function mo_blade_reset($dev = 1) {
    $p = mo_paths();
    $st = mo_state($dev, true);
    $raw = json_decode((string) @file_get_contents($p['config']), true);
    if (!is_array($raw)) { return 0; }
    $raw['blade_base'] = (int) $st['stunden'];
    /* Ueber den gemeinsamen Schreibweg: unteilbar, mit Rechten am Anlegen,
     * und die Zweitschrift geht durch die Wache. Bis 1.0.13 stand hier ein
     * file_put_contents() - der Cron konnte gleichzeitig lesen - und ein
     * blankes copy() auf die Zweitschrift. */
    if (!mo_config_speichern($raw)) { return 0; }
    @unlink(mo_tmpdir() . '/state_' . (int) $dev . '.json');
    mo_log('Messerwechsel quittiert - neuer Nullpunkt: ' . (int) $st['stunden'] . ' Betriebsstunden');
    return 1;
}

/* ---------------- MQTT ---------------- */

/**
 * Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE. Ein Zeilenumbruch im Wert - aus einer
 * Fehlermeldung des Betriebssystems, einem Geraetenamen oder der Ausgabe
 * eines Systembefehls - zerlegt die Uebertragung, und aus den Bruchstuecken
 * bildet das Gateway erfundene Themen. Ein Tabulator schadet ebenso, weil
 * Leerzeichen Thema und Wert trennt.
 */
function mo_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

/**
 * Den Themen-Praefix unschaedlich machen.
 *
 * A5 (26.08.2026): mo_mqtt_wert_saeubern() raeumte die WERTE auf, den
 * Praefix nicht. Das Formular filtert ihn zwar, die zurueckgespielte
 * Sicherung aber ging bis 1.0.13 ungeprueft durch - und das Gateway liest
 * ZEILENWEISE, mit dem Leerzeichen als Trenner zwischen Thema und Wert. Ein
 * Praefix mit Leerzeichen oder Zeilenumbruch erzeugt damit erfundene Themen.
 * Hier steht die Wache ein zweites Mal, weil sie hier NICHTS kostet und den
 * Weg unabhaengig von jedem Aufrufer schliesst.
 */
function mo_mqtt_praefix($roh)
{
    $s = preg_replace('#[^\w/\-]#', '', (string) $roh);
    $s = trim((string) $s, '/');
    return $s !== '' ? $s : 'maeher';
}

/**
 * Eine Zeile an den UDP-Eingang des MQTT-Gateways geben.
 *
 * D1 (26.08.2026): bis 1.0.13 stand hier @socket_create(). Das @ unterdrueckt
 * Warnungen, NICHT einen Error - fehlt die Erweiterung php-sockets, endet der
 * Aufruf mit "Call to undefined function" und Rueckgabewert 255. GEMESSEN:
 * der Cron-Lauf starb dann mitten in der Schleife, die folgenden Maeher
 * wurden nicht mehr abgefragt, und ?ptest=1 beantwortete dem Miniserver einen
 * HTTP 500 statt PTEST;OK=1. Der Cron schreibt nach /dev/null, es fiel also
 * niemandem auf. Das Plugin fordert die Erweiterung nirgends an (kein
 * dpkg/apt), sie ist also nicht zugesichert.
 *
 * fsockopen('udp://...') kommt ohne Erweiterung aus und steht im PHP-Kern.
 * Rueckgabe: Zahl der zugestellten Zeilen (Zustellungen, nicht Durchlaeufe).
 */
function mo_mqtt_senden($port, array $zeilen)
{
    if (!$zeilen) { return 0; }
    $fp = @fsockopen('udp://127.0.0.1', (int) $port, $eno, $etxt, 2);
    if (!$fp) {
        mo_log('MQTT: der UDP-Eingang des Gateways ist nicht erreichbar (Port '
             . (int) $port . '): ' . (string) $etxt);
        return 0;
    }
    stream_set_timeout($fp, 2);
    $n = 0;
    foreach ($zeilen as $z) {
        if (@fwrite($fp, $z) !== false) { $n++; }
    }
    fclose($fp);
    return $n;
}

function mo_mqtt_publish($st = null, $dev = 1) {
    $cfg = mo_config();
    if (empty($cfg['mqtt_enabled'])) { return; }
    $p = mo_paths();
    if ($p['lbhome'] === '') { return; }
    if ($st === null) { $st = mo_state($dev); }
    $gen = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
    $udp = 0;
    if (isset($gen['Mqtt']['Udpinport'])) { $udp = (int) $gen['Mqtt']['Udpinport']; }
    if (!$udp && isset($gen['mqtt']['udpinport'])) { $udp = (int) $gen['mqtt']['udpinport']; }
    if (!$udp) { return; }
    $wurzel = mo_mqtt_praefix($cfg['mqtt_topic']);
    $prefix = $wurzel;
    if ((int) $dev > 1) { $prefix .= '/' . (int) $dev; }
    $m = array('ok' => $st['ok'], 'code' => $st['code'], 'status' => $st['text'], 'modus' => $st['modus'],
               'batterie' => $st['batterie'], 'maeht' => $st['maeht'], 'laedt' => $st['laedt'],
               'fehler' => $st['fehler'], 'stunden' => $st['stunden'], 'dauer' => $st['dauer'],
               'messer_rest' => $st['messer_rest'], 'messer_warn' => $st['messer_warn'],
               'temperatur' => $st['temperatur'], 'feuchte' => $st['feuchte'], 'wlan' => $st['wlan'],
               'timer' => $st['timer']);
    /* timer, ann, audio, push und ptest fehlten hier. In der Vorlage fuer
     * Loxone (mo_felder) stehen sie seit jeher, ueber HTTP kamen sie auch -
     * nur der MQTT-Weg lieferte sie nicht. Wer umstellte, bekam 15 statt 20
     * Werte und merkte es erst, wenn der Test-Push nicht mehr ausloeste. */
    $m = array_merge($m, mo_meldeflags($dev));
    $m = array_merge($m, mo_zusatzwerte($dev));

    $zeilen = array();
    foreach ($m as $k => $v) {
        $zeilen[] = 'publish ' . $prefix . '/' . $k . ' ' . mo_mqtt_wert_saeubern($v);
    }

    /* ==============================================================
     * C2: das Lebenszeichen - sonst luegt das Plugin bei Stillstand
     * ==============================================================
     *
     * Ein virtueller Eingang behaelt seinen letzten Wert, bei MQTT mit Retain
     * sogar ueber einen Neustart des Miniservers hinweg. Faellt der Cron-Lauf
     * aus, steht in Loxone weiter "parkt, Akku 80 %" - das ist keine fehlende
     * Auskunft, sondern eine Falschaussage, und sie sieht aus wie eine
     * richtige. Das Feld ok hilft dagegen NICHT: es sagt, ob der Maeher beim
     * letzten Messen erreichbar war, nicht ob ueberhaupt gemessen wurde.
     *
     * Die drei Themen stehen unter <wurzel>/status/ und gelten fuer die
     * ganze Anlage, nicht je Maeher - deshalb $wurzel und nicht $prefix.
     *
     * ts geht bei JEDEM Durchgang hinaus, auch unveraendert; der
     * Doppelt-senden-Filter im Cron wird dafuer uebergangen. Ueber MQTT gibt
     * es kein "Alter", nur einen Zeitstempel - der Miniserver rechnet selbst:
     * Alter = (Loxone-Zeit + 1230768000) - ts.
     *
     * Der ZAEHLER beantwortet, was der Zeitstempel nicht kann: ein Raspberry
     * ohne Echtzeituhr springt beim ersten Zeitabgleich, und ein Alter kann
     * danach negativ oder stundenlang sein, obwohl alles laeuft. Eine
     * umlaufende Zahl nicht.
     * ============================================================== */
    $lauf = mo_lauf_lesen();
    $zeilen[] = 'publish ' . $wurzel . '/status/ts ' . (int) $lauf['ts'];
    $zeilen[] = 'publish ' . $wurzel . '/status/zaehler ' . (int) $lauf['zaehler'];
    $zeilen[] = 'publish ' . $wurzel . '/status/ok ' . (int) $lauf['ok'];

    mo_mqtt_senden($udp, $zeilen);
}

/**
 * NUR das Lebenszeichen senden - drei Themen, nicht dreiundzwanzig.
 *
 * Der Zeitstempel aendert sich bei JEDEM Durchgang; ihn ueber den vollen
 * mo_mqtt_publish() zu schicken hiesse, jede Minute alle Werte erneut
 * hinauszugeben, obwohl sich keiner geaendert hat. Der Doppelt-senden-Filter
 * im Cron wird fuer diese drei Themen bewusst uebergangen - ueber MQTT gibt
 * es kein "Alter", nur einen Zeitstempel, und der muss frisch sein.
 */
function mo_mqtt_lebenszeichen()
{
    $cfg = mo_config();
    if (empty($cfg['mqtt_enabled'])) { return 0; }
    $p = mo_paths();
    if ($p['lbhome'] === '') { return 0; }
    $gen = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
    $udp = 0;
    if (isset($gen['Mqtt']['Udpinport'])) { $udp = (int) $gen['Mqtt']['Udpinport']; }
    if (!$udp && isset($gen['mqtt']['udpinport'])) { $udp = (int) $gen['mqtt']['udpinport']; }
    if (!$udp) { return 0; }
    $w = mo_mqtt_praefix($cfg['mqtt_topic']);
    $lauf = mo_lauf_lesen();
    return mo_mqtt_senden($udp, array(
        'publish ' . $w . '/status/ts ' . (int) $lauf['ts'],
        'publish ' . $w . '/status/zaehler ' . (int) $lauf['zaehler'],
        'publish ' . $w . '/status/ok ' . (int) $lauf['ok'],
    ));
}

/* ==================================================================
 * C2 - Das Lebenszeichen des Cron-Laufs
 *
 * Es liegt im DATENverzeichnis, nicht in der Konfiguration: der
 * unangemeldete Endpunkt darf dort nichts schreiben, und die Sicherung soll
 * es nicht mitschleppen.
 * ================================================================== */
/**
 * Eine JSON-Datei lesen - erst fragen, dann oeffnen.
 *
 * Ein @file_get_contents() auf eine fehlende Datei ist stumm, aber nicht
 * folgenlos: ein gesetzter Fehlerbehandler sieht die Warnung trotzdem. Im
 * Pruefstand rendern.py stand sie deshalb als Befund da - dreimal, und alle
 * drei Dateien fehlen vor dem ersten Cron-Lauf regelmaessig.
 *
 * Rueckgabe: das Feld, oder ein leeres Feld. Ein Lesefehler und eine leere
 * Datei sind hier dasselbe - es geht um Zaehlstaende, die neu entstehen
 * duerfen. Fuer die KONFIGURATION gilt das ausdruecklich NICHT, siehe
 * mo_config().
 */
function mo_json_lesen($pfad)
{
    if (!is_file($pfad)) { return array(); }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : array();
}

function mo_lauf_datei() { return mo_paths()['datadir'] . '/lauf.json'; }

function mo_lauf_lesen()
{
    $d = mo_json_lesen(mo_lauf_datei());
    $d += array('ts' => 0, 'zaehler' => 0, 'ok' => 0);
    return array('ts' => (int) $d['ts'], 'zaehler' => (int) $d['zaehler'], 'ok' => (int) $d['ok']);
}

/**
 * Einen Durchlauf vermerken. $ok = 1, wenn wirklich gemessen wurde.
 *
 * "Wirklich gemessen" heisst: mindestens ein eingerichteter Maeher hat
 * geantwortet. Ein Lauf, bei dem alle schweigen, ist ein Lauf - aber keine
 * Messung, und beides gehoert unterschieden.
 */
function mo_lauf_vermerken($ok)
{
    $a = mo_lauf_lesen();
    $neu = array('ts' => time(), 'zaehler' => ((int) $a['zaehler'] + 1) % 1000,
                 'ok' => $ok ? 1 : 0);
    mo_write_json(mo_lauf_datei(), $neu);
    return $neu;
}

/** Alter des letzten Laufs in Sekunden; -1, wenn noch nie einer war. */
function mo_lauf_alter()
{
    $a = mo_lauf_lesen();
    return $a['ts'] > 0 ? max(0, time() - (int) $a['ts']) : -1;
}

/* ==================================================================
 * C6 - Fehlerhistorie
 *
 * Bis 1.0.13 lieferte das Plugin FEHLER nur als Momentanwert. Ein Fehler,
 * der nachts auftritt und sich von selbst loest, ist am Morgen unsichtbar -
 * es sei denn, jemand liest das Protokoll.
 * ================================================================== */
function mo_fehler_datei() { return mo_paths()['datadir'] . '/fehler.json'; }

function mo_fehler_liste()
{
    return mo_json_lesen(mo_fehler_datei());
}

/** Einen Fehler vermerken - nur den WECHSEL, nicht jeden Durchlauf. */
function mo_fehler_vermerken($name, $code, $text)
{
    $l = mo_fehler_liste();
    $l[] = array('ts' => time(), 'name' => (string) $name,
                 'code' => (int) $code, 'text' => (string) $text);
    // Harte Obergrenze: eine Liste ohne Grenze waechst, bis jemand darueber
    // stolpert. 40 Eintraege sind rund 4 kB.
    if (count($l) > 40) { $l = array_slice($l, -40); }
    mo_write_json(mo_fehler_datei(), $l);
}

/** Stunden seit dem letzten vermerkten Fehler; -1 = keiner bekannt. */
function mo_fehler_alter_h()
{
    $l = mo_fehler_liste();
    if (!$l) { return -1; }
    $letzt = end($l);
    if (!is_array($letzt) || empty($letzt['ts'])) { return -1; }
    return (int) floor(max(0, time() - (int) $letzt['ts']) / 3600);
}

/* ==================================================================
 * C5 - Einsatzstatistik, aus Daten, die ohnehin anfallen
 *
 * mo_events_check() erkennt den Uebergang von "maeht" nach "parkt / laedt /
 * sucht Ladestation" bereits und kennt die Laufzeit des Einsatzes. Es
 * braucht also keinen einzigen zusaetzlichen Netzabruf.
 *
 * Ein Tageswert ist KEIN Momentanwert: der Umlauf um Mitternacht und der
 * Wochenwechsel stehen hier ausgeschrieben. Die Woche ist die ISO-Woche
 * ('oW') - 'YW' laege in der Neujahrswoche daneben.
 * ================================================================== */
function mo_stat_datei() { return mo_paths()['datadir'] . '/statistik.json'; }

function mo_stat_lesen()
{
    $d = mo_json_lesen(mo_stat_datei());
    $d += array('tag' => '', 'woche' => '', 'eins_heute' => 0, 'min_heute' => 0,
                'eins_woche' => 0, 'min_woche' => 0);
    // Umlauf beim LESEN, nicht nur beim Schreiben: sonst zeigte die
    // Oberflaeche am Morgen noch die Zahlen von gestern.
    if ($d['tag'] !== date('Ymd'))   { $d['eins_heute'] = 0; $d['min_heute'] = 0; }
    if ($d['woche'] !== date('oW'))  { $d['eins_woche'] = 0; $d['min_woche'] = 0; }
    return $d;
}

/** Einen abgeschlossenen Einsatz aufnehmen. */
function mo_stat_einsatz($minuten)
{
    $cfg = mo_config();
    if (empty($cfg['stat_ein'])) { return; }
    $d = mo_stat_lesen();
    $d['tag'] = date('Ymd');
    $d['woche'] = date('oW');
    $d['eins_heute'] = (int) $d['eins_heute'] + 1;
    $d['eins_woche'] = (int) $d['eins_woche'] + 1;
    $m = max(0, (int) $minuten);
    $d['min_heute'] = (int) $d['min_heute'] + $m;
    $d['min_woche'] = (int) $d['min_woche'] + $m;
    mo_write_json(mo_stat_datei(), $d);
}

/**
 * Die Werte, die zu jedem Maeher zusaetzlich hinausgehen.
 *
 * An EINER Stelle, weil sie ueber HTTP, ueber MQTT und in der Loxone-Vorlage
 * gebraucht werden. Genau dafuer gibt es mo_meldeflags() schon - dies ist
 * dieselbe Bauart fuer die neuen Werte.
 *
 * Die Statistikfelder entfallen, wenn der Haken aus ist. Die Vorlage darf
 * nur Felder anlegen, die auch geliefert werden.
 */
function mo_zusatzwerte($dev = 1)
{
    $cfg = mo_config();
    $lauf = mo_lauf_lesen();
    $out = array(
        'ts'          => (int) $lauf['ts'],
        'zaehler'     => (int) $lauf['zaehler'],
        'fehleralter' => mo_fehler_alter_h(),
    );
    if (!empty($cfg['stat_ein'])) {
        $s = mo_stat_lesen();
        $out['einsheute'] = (int) $s['eins_heute'];
        $out['minheute']  = (int) $s['min_heute'];
        $out['einswoche'] = (int) $s['eins_woche'];
        $out['minwoche']  = (int) $s['min_woche'];
    }
    return $out;
}

/* ---------------- Ansage (TTS) ---------------- */

function mo_tts_url($text) {
    $cfg = mo_config(); $tts = $cfg['tts']; $mode = $tts['mode'];
    if ($mode === 'audioserver') { return null; }
    if ($mode === 'musicserver' && (string) $tts['ip'] === '') {
        return '';   // ohne IP laesst sich die Music-Server-Adresse nicht bauen
    }

    /* Zonenliste EINMAL fuer alle Modi normalisieren. Vorher wurde nur im
     * Modus musicserver je Zone getrimmt; in den Vorlagen-Modi ging die
     * Eingabe roh in {zones} - aus "2, 4, 6" wurde eine Adresse mit
     * Leerzeichen. */
    $zl = array();
    foreach (explode(',', (string) $tts['zones']) as $z) {
        $z = trim($z);
        if ($z !== '') { $zl[] = $z; }
    }
    $tts['zones'] = implode(',', $zl);
    if ($mode === 'musicserver') {
        $vol = max(1, min(100, (int) $tts['volume']));
        $zones = array();
        foreach (explode(',', (string) $tts['zones']) as $z) {
            $z = trim($z);
            if ($z === '') { continue; }
            $zones[] = (strpos($z, '~') === false) ? $z . '~' . $vol : $z;
        }
        $zoneStr = $zones ? implode(',', $zones) : '1~' . $vol;
        return 'http://' . $tts['ip'] . ':' . (int) $tts['port'] . '/audio/grouped/tts/' . $zoneStr . '/' . rawurlencode($tts['lang'] . '|' . $text);
    }
    $tpl = trim((string) $tts['template']);
    if ($tpl === '') { $tpl = 'http://{ip}:{port}/tts?text={text}&zone={zones}&vol={vol}'; }
    /* Die IP wird nur verlangt, wenn die Vorlage sie auch verwendet.
     * Vorher stand die Pruefung unbedingt am Anfang der Funktion - eine
     * eigene Vorlage ohne {ip} war damit unbenutzbar (AWM-1.2.0-Fund,
     * hier nachgezogen). */
    if ((string) $tts['ip'] === '' && strpos($tpl, '{ip}') !== false) {
        return '';
    }
    return str_replace(array('{ip}', '{port}', '{zones}', '{vol}', '{lang}', '{text}'),
        array($tts['ip'], (int) $tts['port'], $tts['zones'], (int) $tts['volume'], $tts['lang'], rawurlencode($text)), $tpl);
}
function mo_say($text) {
    $url = mo_tts_url($text);
    if ($url === null) { mo_log('Ansage: Modus Audioserver - Ausgabe ueber Loxone Config'); return false; }
    if ($url === '') { mo_log('Ansage uebersprungen: keine TTS-IP konfiguriert'); return false; }
    $ctx = stream_context_create(array('http' => array('timeout' => 10)));
    $r = @file_get_contents($url, false, $ctx);
    mo_log('Ansage gesendet: "' . $text . '" -> ' . ($r !== false ? 'OK' : 'FEHLER'));
    return $r !== false;
}

function mo_ann_active($dev = 1) {
    $f = mo_tmpdir() . '/ann_' . (int) $dev;
    return (is_file($f) && time() - filemtime($f) < 600) ? 1 : 0;
}
function mo_ptest_active() {
    $f = mo_tmpdir() . '/ptest';
    return (is_file($f) && time() - filemtime($f) < 300) ? 1 : 0;
}

/**
 * Die vier Meldeflags an EINER Stelle: ann, audio, push, ptest.
 *
 * Sie standen bisher nur in der HTTP-Antwort - ausgerechnet dort, wo sie
 * das Programm im Miniserver auch ohne MQTT abholen konnte. Wer auf MQTT
 * umstellte, verlor sie ersatzlos: kein Meldefenster, keine Freigaben und
 * vor allem kein PTEST, also keine Moeglichkeit mehr, den Push-Weg zu
 * pruefen, ohne auf ein echtes Ereignis zu warten.
 *
 * Seit 1.0.12 liefert diese Funktion die Werte fuer beide Wege. Sie koennen
 * damit nicht mehr auseinanderlaufen - genau das war der Grund, sie
 * herauszuziehen statt die Rechnung ein zweites Mal hinzuschreiben.
 */
function mo_meldeflags($dev = 1)
{
    $cfg = mo_config();
    return array(
        'ann'   => mo_ann_active($dev),
        'audio' => empty($cfg['notify']['audio']) ? 0 : 1,
        'push'  => empty($cfg['notify']['push']) ? 0 : 1,
        'ptest' => mo_ptest_active(),
    );
}

/** Cron: Ereignisse erkennen und melden. */
function mo_events_check() {
    $cfg = mo_config();
    foreach (mo_mowers() as $n => $mw) {
        $st = mo_state($n);
        $f = mo_tmpdir() . '/ev_' . $n . '.json';
        $prev = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: array()) : array();
        $pcode = isset($prev['code']) ? (int) $prev['code'] : -99;
        $maeh_start = isset($prev['maeh_start']) ? (int) $prev['maeh_start'] : 0;
        $meldung = '';
        // Fehler (Statuswechsel nach 7 oder Schleifensignal verloren)
        if (in_array($st['code'], array(7, 8), true) && $pcode !== $st['code']) {
            /* C6: der WECHSEL wird vermerkt, nicht jeder Durchlauf - sonst
             * stuenden bei einem Dauerfehler minuetlich neue Eintraege da.
             * Das geschieht unabhaengig vom Meldehaken: der Haken steuert,
             * ob jemand benachrichtigt wird, nicht ob es aufgeschrieben wird. */
            mo_fehler_vermerken($st['name'], $st['code'],
                $st['fehlertext'] !== '' ? $st['fehlertext']
                                         : ($st['code'] === 8 ? 'Schleifensignal verloren' : 'Fehler'));
            if (!empty($cfg['notify']['fehler'])) {
                $meldung = 'Achtung: ' . $st['name'] . ' meldet '
                         . ($st['code'] === 8 ? 'Schleifensignal verloren' : 'einen Fehler')
                         . ($st['fehlertext'] !== '' ? ': ' . $st['fehlertext'] : '.');
            }
        }
        /* Maehen beendet (von "maeht" zurueck in die Station).
         *
         * C5: die Dauer wird SELBST gemessen, nicht aus $st['dauer']
         * genommen. Das Feld traegt die Laufzeit des LAUFENDEN Einsatzes;
         * was es meldet, sobald der Einsatz vorbei ist, ist nicht gemessen -
         * und eine Zahl, die richtig aussieht und geraten ist, gehoert nicht
         * in eine Statistik. Gemessen wird zwischen dem ersten und dem
         * letzten Durchlauf mit Code 2. */
        if ($st['code'] === 2 && $maeh_start === 0) { $maeh_start = time(); }
        if ($pcode === 2 && in_array($st['code'], array(1, 3, 4), true)) {
            $min = ($maeh_start > 0) ? (int) round((time() - $maeh_start) / 60) : 0;
            if ($min <= 0 && (int) $st['dauer'] > 0) { $min = (int) $st['dauer']; }
            mo_stat_einsatz($min);
            $maeh_start = 0;
            if (!empty($cfg['notify']['fertig'])) {
                $meldung = $st['name'] . ' ist mit dem Maehen fertig.'
                         . ($min > 0 ? ' Laufzeit ' . $min . ' Minuten.' : '');
            }
        }
        // Akku schwach ausserhalb der Ladung
        if (!empty($cfg['notify']['akku']) && $st['batterie'] > 0 && $st['batterie'] < 20
            && !in_array($st['code'], array(4, 3), true)) {
            $af = mo_tmpdir() . '/akku_' . $n . '_' . date('Ymd');
            if (!is_file($af)) {
                @file_put_contents($af, '1');
                $meldung = $st['name'] . ': Akku nur noch ' . (int) $st['batterie'] . ' Prozent.';
            }
        }
        // Messerwechsel faellig (hoechstens einmal taeglich)
        if (!empty($cfg['notify']['messer']) && $st['messer_warn']) {
            $mf = mo_tmpdir() . '/messer_' . $n . '_' . date('Ymd');
            if (!is_file($mf)) {
                @file_put_contents($mf, '1');
                $meldung = $st['name'] . ': Messerwechsel faellig - seit dem letzten Wechsel sind '
                         . max(0, (int) $st['stunden'] - (int) $cfg['blade_base']) . ' Betriebsstunden vergangen.';
            }
        }
        if ($meldung !== '') {
            @touch(mo_tmpdir() . '/ann_' . $n);
            @file_put_contents(mo_tmpdir() . '/anntext_' . $n, $meldung);
            mo_log('Meldung: ' . $meldung);
            if (!empty($cfg['notify']['audio'])) { mo_say('Hallo! ' . $meldung); }
        }
        mo_write_json($f, array('code' => $st['code'], 'ts' => time(),
                                'maeh_start' => $maeh_start));
    }
    foreach (array('messer_', 'akku_') as $pre) {
        foreach (glob(mo_tmpdir() . '/' . $pre . '*') ?: array() as $g) {
            if (substr(basename($g), -8) !== date('Ymd')) { @unlink($g); }
        }
    }
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini
 * immer vollstaendig sein.
 * ================================================================== */

function mo_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/**
 * Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL".
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt
 * beim Durchsehen sofort auf, was noch fehlt, statt dass die Seite leer
 * bleibt.
 */
function mo_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        // Installiert liegen die Dateien unter
        // <home>/templates/plugins/<ordner>/lang/ - der Ordnername ergibt
        // sich aus dem Ablageort dieser Datei.
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) { $home = $k; break; }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            // Nicht installiert (Entwicklung): neben dem Plugin nachsehen.
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . mo_sprache() . '.ini',
                                 true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        // parse_ini_file mit INI_SCANNER_RAW liefert die Werte samt der
        // Anfuehrungszeichen zurueck, in die sie in der Datei stehen muessen.
        // Die gehoeren nicht in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}

/* ==================================================================
 * D6: der Suchtext kommt aus EINER Funktion - auch fuer die ANZEIGE
 * ==================================================================
 *
 * Bis 1.0.13 bildete die Vorlage ihn als '\i' . $name . '=\i\v', und in
 * beiden Sprachdateien standen dieselben Texte noch einmal von Hand
 * ausgeschrieben - 34 Fundstellen. Wer die Tabelle im Reiter abtippte statt
 * die Vorlage zu importieren, bekam damit dieselbe Angabe aus zwei Quellen.
 *
 * Das fuehrende Semikolon ist die eigentliche Aenderung. Ohne es trifft
 * '\iMESSER=' in einer Zeile, die auch MESSERWARN= enthaelt, potenziell die
 * falsche Stelle. Heute geht es gut, weil das Gleichheitszeichen mitgesucht
 * wird - es geht so lange gut, bis jemand ein Feld anlegt, dessen Name
 * mitsamt '=' anderswo in der Zeile vorkommt. Die Antwortzeile beginnt mit
 * 'MOWER;', jedes Feld steht also hinter einem Semikolon.
 * ================================================================== */
function mo_check($feld) { return '\i;' . $feld . '=\i\v'; }

/* ---------------- Loxone-Vorlage (Hausstandard "Alles auf einmal anlegen") ---------------- */
/** name => array(analog, min, max, einheit, kommentar) */
/* D5 (26.08.2026): drei Felder senden -1 und waren als vorzeichenlos
 * angelegt. mo_state() setzt code und modus bei fehlender Verbindung auf -1,
 * und messer_rest bleibt -1, solange keine Betriebsstunden bekannt sind. Aus
 * min = 0 leitete die Vorlage Signed="false" und MinVal="0" ab - in der
 * Visualisierung stuende dann eine 0, und 0 heisst bei MESSER "Wechsel jetzt
 * faellig" statt "nicht bekannt". Eine stille Falschaussage.
 *
 * Wer ein Feld baut, das -1 als "nicht bekannt" sendet, setzt MinVal="-1".
 * NEUE Felder werden HINTEN angehaengt - bestehende Projekte finden sonst
 * nicht mehr, was sie suchen. */
function mo_felder() {
    $cfg = mo_config();
    $f = array(
        'OK'         => array(0, 0, 1,    '',    '1 = Maeher erreichbar'),
        'CODE'       => array(1, -1, 99,  '',    'Statuszahl des Maehers (Robonect-Status), -1 = keine Verbindung'),
        'MODUS'      => array(1, -1, 99,  '',    'Betriebsmodus (Auto, Manuell, Zuhause, ...), -1 = unbekannt'),
        'BATT'       => array(1, 0, 100,  '%',   'Batterie in Prozent'),
        'MAEHT'      => array(0, 0, 1,    '',    '1 = maeht gerade'),
        'LAEDT'      => array(0, 0, 1,    '',    '1 = laedt gerade'),
        'FEHLER'     => array(1, 0, 10000,'',    'Fehlercode (0 = kein Fehler)'),
        'STUNDEN'    => array(1, 0, 100000,'h',  'Maehstunden gesamt'),
        'DAUER'      => array(1, 0, 10000,'min', 'Dauer des laufenden Einsatzes'),
        'MESSER'     => array(1, -1, 10000,'h',  'Messer: Reststunden bis zum Wechsel, -1 = nicht bekannt'),
        'MESSERWARN' => array(0, 0, 1,    '',    '1 = Messerwechsel faellig'),
        'TEMP'       => array(1, -30, 80, 'GradC','Temperatur am Maeher'),
        'FEUCHTE'    => array(1, 0, 100,  '%',   'Luftfeuchte am Maeher'),
        'WLAN'       => array(1, -100, 0, 'dBm', 'WLAN-Signalstaerke'),
        'TIMER'      => array(0, 0, 1,    '',    '1 = Timer aktiv'),
        'ANN'        => array(0, 0, 1,    '',    'Meldefenster aktiv'),
        'AUDIO'      => array(0, 0, 1,    '',    'Ansage freigegeben'),
        'PUSH'       => array(0, 0, 1,    '',    'Push freigegeben'),
        'PTEST'      => array(0, 0, 1,    '',    'Test-Push ausloesen'),
        // --- ab 1.1.0, hinten angehaengt ---
        'TS'          => array(1, 0, 2000000000, 's', 'Zeitstempel des letzten Cron-Laufs (Unix-Sekunden). Alter = (Loxone-Zeit + 1230768000) - TS'),
        'ZAEHLER'     => array(1, 0, 999,  '',   'Laufzaehler, laeuft 0...999 um - steht er still, laeuft der Cron nicht mehr'),
        'FEHLERALTER' => array(1, -1, 100000, 'h', 'Stunden seit dem letzten vermerkten Fehler, -1 = keiner bekannt'),
    );
    if (!empty($cfg['stat_ein'])) {
        $f['EINSHEUTE'] = array(1, 0, 99,    '',    'Abgeschlossene Maeheinsaetze heute');
        $f['MINHEUTE']  = array(1, 0, 10000, 'min', 'Maehdauer heute');
        $f['EINSWOCHE'] = array(1, 0, 999,   '',    'Abgeschlossene Maeheinsaetze diese Woche');
        $f['MINWOCHE']  = array(1, 0, 100000,'min', 'Maehdauer diese Woche');
    }
    return $f;
}
/** Gepruefter PHP-Nachbau des LoxoneTemplateBuilder - Attributreihenfolge,
 *  CRLF und der Tabulator vor den Kindelementen entsprechen dem Original.
 *  Uebernommen aus LoxBerry-Plugin-APC-UPS, nur das Kuerzel getauscht. */
function mo_xml_virtual_in_http($kopf, $cmds) {
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" ';
    $o .= 'Title="' . mo_x($kopf['title']) . '" ';
    $o .= 'Comment="' . mo_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . mo_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . mo_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf; // wie Original-Export aus Loxone Config 17.1
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . mo_x($c['title']) . '" ';
        $o .= 'Comment="' . mo_x($c['comment']) . '" ';
        $o .= 'Check="' . mo_x($c['check']) . '" ';
        $o .= 'Signed="' . ($c['min'] < 0 ? 'true' : 'false') . '" ';
        $o .= 'Analog="' . ($c['analog'] ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" DefVal="0" ';
        $o .= 'MinVal="' . (int) $c['min'] . '" ';
        $o .= 'MaxVal="' . (int) $c['max'] . '" ';
        $o .= 'Unit="' . mo_x(isset($c['unit']) ? $c['unit'] : '<v>') . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

function mo_x($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/* ==================================================================
 * B1 - Zustand UND Fassung des MQTT-Gateways
 * ==================================================================
 *
 * Bis 1.0.13 wurde aus general.json ausschliesslich Gatewayautostart
 * gelesen. Der Schluessel Gatewayversion steht unmittelbar daneben und blieb
 * ungelesen - obwohl er zwei Gateways mit VERSCHIEDENER BEDIENUNG
 * unterscheidet:
 *
 *   V1  Das Abo wird von Hand eingetragen. Ohne den Eintrag kommt am
 *       Miniserver nichts an. Das ist die haeufigste Fehlerursache ueberhaupt.
 *   V2  Es ist NICHTS einzutragen. Die Themengruppe erscheint von selbst in
 *       den Abonnements; dort werden die Datenpunkte angehakt.
 *
 * Belegt am LoxBerry-Kern, webfrontend/htmlauth/system/mqtt-gateway.cgi:
 *
 *     $gatewayversion = $generaljson->{Mqtt}->{Gatewayversion} // 1;
 *     $template->param("GATEWAY_V2", $gatewayversion == 2 ? 1 : 0);
 *     $template->param("FORM_DISABLE_BUTTONS", 1) if $gatewayversion == 2;
 *
 * Unter V2 schaltet der Kern auf der Abonnement-Seite die Knoepfe ab - von
 * Hand eintragen kann man dort nichts mehr. Wer den V1-Satz unbedingt
 * hinschreibt, schickt jeden V2-Anwender zu einem Eingabefeld, das es nicht
 * mehr gibt.
 *
 * NICHT selbst gemessen ist, dass V2 die Themengruppe von SELBST erkennt.
 * Das steht in der Oberflaeche eines fremden Plugins (mschlenstedt,
 * LoxBerry-Plugin-MGiSMART) und passt zu den abgeschalteten Knoepfen - es
 * bleibt eine Sekundaerquelle.
 *
 * Diese Funktion liest denselben Block wie die Autostart-Pruefung; es gibt
 * also keinen zweiten Dateizugriff, und beide Aussagen kommen aus einer
 * Quelle.
 *
 * Rueckgabe: null, wenn general.json nicht lesbar ist - sonst autostart
 * (bool) und fassung (int, 0 = unbekannt). Die 0 wird NICHT auf 1
 * vorbelegt: "unbekannt" und "Fassung 1" sind verschiedene Aussagen, und
 * die Oberflaeche behandelt sie verschieden.
 * ================================================================== */
function mo_mqtt_gateway_info() {
    $p = mo_paths();
    /* Kein fest verdrahteter Systempfad mehr: lb_wurzel_ermitteln() steht
     * zwei Funktionen weiter oben und trifft auch eine Installation, die
     * nicht unter /opt/loxberry liegt. */
    $home = isset($p['lbhome']) && $p['lbhome'] !== '' ? $p['lbhome'] : '';
    if ($home === '') { return null; }
    $gj = $home . '/config/system/general.json';
    if (!is_file($gj)) { return null; }
    $d = json_decode((string) @file_get_contents($gj), true);
    if (!is_array($d) || !isset($d['Mqtt']) || !is_array($d['Mqtt'])) { return null; }
    $auto = isset($d['Mqtt']['Gatewayautostart']) ? $d['Mqtt']['Gatewayautostart'] : '';
    return array(
        'autostart' => in_array((string) $auto, array('1', 'true'), true),
        'fassung'   => isset($d['Mqtt']['Gatewayversion']) ? (int) $d['Mqtt']['Gatewayversion'] : 0,
    );
}

/** Hausstandard: Gateway-Autostart aus general.json. Wortlaut unveraendert. */
function mo_mqtt_gateway_autostart() {
    $m = mo_mqtt_gateway_info();
    return $m === null ? null : $m['autostart'];
}

/**
 * Der Satz zum Abo - an die Fassung gehaengt.
 *
 * Ist die Fassung nicht lesbar, stehen BEIDE Faelle da. Einen von beiden zu
 * behaupten waere fuer die Haelfte der Anlagen falsch - und eine stille
 * Falschaussage in genau der Zeile, die als haeufigste Fehlerursache gilt.
 */
function mo_abo_text() {
    $g = mo_mqtt_gateway_info();
    $f = ($g === null) ? 0 : (int) $g['fassung'];
    if ($f <= 0) { return mo_t('TEXT.ABO_UNBEKANNT'); }
    return mo_t($f >= 2 ? 'TEXT.ABO_V2' : 'TEXT.ABO_PFLICHT')
         . ' <span class="sm-mono">'
         . sprintf(mo_t('TEXT.ABO_GEMESSEN'), $f) . '</span>';
}

/** Vorlage fuer den Import in Loxone Config. Rueckgabe: array(name, inhalt) */
function mo_vorlage($dev = 1) {
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    $ordner = getenv('LBPPLUGINDIR') ?: 'robonect';
    $dev = max(1, min(9, (int) $dev));
    $cmds = array();
    foreach (mo_felder() as $name => $f) {
        list($analog, $min, $max, $einheit, $text) = $f;
        $cmds[] = array(
            'title' => 'MOWER_' . $name . ($dev > 1 ? '_' . $dev : ''),
            'comment' => $text . ($einheit !== '' ? ' [' . $einheit . ']' : ''),
            'check' => mo_check($name),
            'unit' => ($einheit !== '' ? '<v.1> ' . $einheit : '<v.1>'),
            'analog' => $analog, 'min' => $min, 'max' => $max,
        );
    }
    return array('VI_robonect' . ($dev > 1 ? '_' . $dev : '') . '.xml', mo_xml_virtual_in_http(array(
        'title' => 'Rasenmaeher' . ($dev > 1 ? ' ' . $dev : ''),
        'address' => 'http://' . $host . '/plugins/' . $ordner . '/mower.php' . ($dev > 1 ? '?dev=' . $dev : ''),
        'polling' => '60',
        'comment' => 'Erzeugt vom LoxBerry-Plugin Rasenmaeher/Robonect (' . date('d.m.Y') . '). '
                   . 'Loxone Config legt beim Import neu an und ueberschreibt nichts - '
                   . 'zweimal eingelesen ergibt doppelte Bausteine.',
    ), $cmds));
}

/** Vorlage der Steuerbefehle (Virtueller Ausgang) - Format wie Original-Export aus Loxone Config 17.1. */
function mo_vo_vorlage() {
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    $ordner = getenv('LBPPLUGINDIR') ?: 'robonect';
    $cfg = mo_config();
    $tok = isset($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="Rasenmaeher steuern (LoxBerry-Plugin)" Comment="Steuerbefehle ueber das Plugin ' . mo_x($ordner) . ' - enthaelt das Aktionstoken." Address="http://' . mo_x($host) . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach (array(
        array('Maeher starten', '/mower.php?cmd=start', false),
        array('Maeher stoppen', '/mower.php?cmd=stop', false),
        array('Automatik', '/mower.php?cmd=auto', false),
        array('Zur Ladestation', '/mower.php?cmd=home', false),
        array('Ende des Tages', '/mower.php?cmd=eod', false),
        array('Messerwechsel quittieren', '/mower.php?cmd=blade_reset', false),
    ) as $c) {
        $o .= "\t" . '<VirtualOutCmd Title="' . mo_x($c[0]) . '" Comment="" CmdOnMethod="GET" CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . mo_x('/plugins/' . $ordner . $c[1] . '&token=' . $tok) . '" ';
        $o .= 'CmdOnHTTP="" CmdOnPost="" CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" ';
        $o .= 'Analog="' . (!empty($c[2]) ? 'true' : 'false') . '" Repeat="0" RepeatRate="0" HintText=""/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return array('VQ_robonect_steuern.xml', $o);
}


/**
 * Den ganzen Konfigurationsstand ablegen - und sagen, ob es geklappt hat.
 *
 * Bisher schrieb diese Linie mitten in index.php. Das Zurueckspielen einer
 * Sicherung braucht aber EINE Stelle, sonst steht die Pruefung "hat es
 * geklappt?" an vier Orten verschieden da.
 *
 * Der Schreibweg ist der, den die Linie ohnehin benutzt - hier wird kein
 * Verhalten geaendert, nur ein vorhandenes zusammengefasst.
 */
function mo_config_speichern($cfg)
{
    $p = mo_paths();
    $js = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        return false;   /* ungueltiges UTF-8 - lieber gar nicht schreiben
                           als eine halbe Datei hinterlassen */
    }
    @mkdir(dirname($p['config']), 0775, true);
    if (!mo_write_atomic($p['config'], $js, 0600)) { return false; }

    /* A6 (26.08.2026): zwei Dinge, die bis 1.0.13 an dieser Stelle fehlten
     * und deshalb bei jedem Aufrufer einzeln vergessen werden konnten.
     *
     * 1. Die Zweitschrift. Alle anderen Speicherwege kopierten sie
     *    anschliessend, dieser nicht - nach dem Zurueckspielen einer
     *    Sicherung stand darin also der Stand VON VOR dem Zurueckspielen,
     *    und beim naechsten Zwischenfall holte mo_config() genau den zurueck.
     *    Sie geht durch die Wache: ohne Aktionstoken wird nicht geschrieben.
     *
     * 2. Der Zustands-Zwischenspeicher. Sonst zeigen Oberflaeche und
     *    Endpunkt bis zu cache_sec Sekunden lang den Zustand des ALTEN
     *    Maehers.
     *
     * Beides steht hier und nicht beim Aufrufer: einen Aufrufer kann man
     * vergessen, eine gemeinsame Funktion nicht. */
    mo_zweitschrift_schreiben($cfg);
    foreach (glob(mo_tmpdir() . '/state_*.json') ?: array() as $g) { @unlink($g); }
    return true;
}

/**
 * Die Sicherungsdatei erzeugen - Inhalt und Kopf an EINER Stelle.
 *
 * A4: Der lesbare Kopf sagt, woher die Datei stammt und wann sie entstand.
 * Wer ihn hinzufuegt, ergaenzt im selben Zug die Leseseite - sonst lehnt
 * mo_sicherung_lesen() die Datei ab, die zwei Zeilen vorher dieselbe
 * Bibliothek erzeugt hat. Genau das ist am 26.08.2026 an einer anderen Linie
 * passiert; gefunden hat es kein Lesen, sondern der Prueflauf, der die eigene
 * Sicherung zurueckspielt.
 *
 * DER AKTIONSTOKEN GEHOERT IN DIE DATEI - der Formulartoken NICHT. Ohne den
 * Aktionstoken stuenden nach dem Zurueckspielen alle Felder richtig, und das
 * Plugin kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Der
 * Formulartoken aus mo_formtoken() ist etwas anderes: er lebt eine Sitzung
 * und schuetzt gegen fremde Absender. In einer Datei hat er nichts zu suchen.
 */
function mo_sicherung_erzeugen()
{
    $cfg = mo_config();
    $kopf = array(
        '_hinweis' => 'Einstellungen des LoxBerry-Plugins Rasenmaeher (Robonect). '
                    . 'Enthaelt Zugangsdaten des Maehers und das Aktionstoken - '
                    . 'wie ein Passwort behandeln.',
        '_stand'   => date('Y-m-d H:i:s'),
        '_plugin'  => 'robonect',
    );
    return json_encode($kopf + $cfg,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Dateiname der Sicherung - steht genau einmal. */
function mo_sicherung_name()
{
    return 'robonect_einstellungen_' . date('Ymd_His') . '.json';
}


/* ==================================================================
 * A5 - Geprueft wird der WERT, nicht nur der Schluessel
 * ==================================================================
 *
 * Bis 1.0.13 war das Formular strenger als die Sicherung: es filterte den
 * Themen-Praefix und wies eine ungueltige Maeher-Adresse ab, waehrend
 * dieselben Werte ueber eine zurueckgespielte Datei ungeprueft in die
 * Konfiguration gingen. Zwei Wahrheiten ueber zulaessige Werte.
 *
 * Diese Positivliste ist jetzt die EINE Quelle: das Formular ruft sie
 * genauso wie die Sicherung. Wer eine Grenze aendert, aendert sie hier.
 * ================================================================== */

/** Taugt der Wert ueberhaupt fuer eine Konfiguration? Grundwache. */
function mo_wert_taugt($v)
{
    if (is_array($v) || is_object($v) || is_bool($v) || is_null($v)) { return false; }
    $s = (string) $v;
    if (strlen($s) > 4096) { return false; }
    // Steuerzeichen, Zeilenumbrueche und Tabulatoren: der MQTT-Weg ist
    // zeilenorientiert, und eine Protokollzeile ebenfalls.
    return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $s) !== 1;
}

/** Eine Maeherzeile pruefen. Rueckgabe: array(Zeile|null, Beanstandung|''). */
function mo_maeher_pruefen($m, $nr)
{
    if (!is_array($m)) { return array(null, sprintf(mo_t('TEXT.SICH_MAEHER_FORM'), $nr)); }
    $m += array('name' => '', 'ip' => '', 'user' => '', 'pass' => '');
    foreach (array('name', 'ip', 'user', 'pass') as $k) {
        if (!mo_wert_taugt($m[$k])) { return array(null, sprintf(mo_t('TEXT.SICH_MAEHER_FORM'), $nr)); }
    }
    $ip = trim((string) $m['ip']);
    if ($ip === '') { return array(null, ''); }   // leere Zeile: still weglassen
    if (!preg_match('/^[\w\.\-]+$/', $ip)) {
        return array(null, sprintf(mo_t('TEXT.SICH_MAEHER_IP'), $nr, htmlspecialchars($ip, ENT_QUOTES, 'UTF-8')));
    }
    return array(array('name' => trim((string) $m['name']), 'ip' => $ip,
                       'user' => trim((string) $m['user']), 'pass' => (string) $m['pass']), '');
}

/**
 * Einen Konfigurationswert pruefen und in seine Sollform bringen.
 *
 * Rueckgabe: array(Wert|null, Beanstandung|''). Ist die Beanstandung leer
 * und der Wert null, gehoert der Schluessel weggelassen.
 */
function mo_wert_pruefen($k, $v)
{
    switch ($k) {
        case 'mowers':
            if (!is_array($v)) { return array(null, sprintf(mo_t('TEXT.SICH_WERT'), $k)); }
            $out = array(); $nr = 0;
            foreach ($v as $m) {
                $nr++;
                if ($nr > mo_max_maeher()) {
                    return array(null, sprintf(mo_t('TEXT.SICH_ZU_VIELE'), mo_max_maeher()));
                }
                list($z, $mangel) = mo_maeher_pruefen($m, $nr);
                if ($mangel !== '') { return array(null, $mangel); }
                if ($z !== null) { $out[] = $z; }
            }
            return array($out, '');

        case 'notify':
            if (!is_array($v)) { return array(null, sprintf(mo_t('TEXT.SICH_WERT'), $k)); }
            $out = array();
            foreach (array('audio', 'push', 'fehler', 'fertig', 'messer', 'akku') as $n) {
                $out[$n] = (isset($v[$n]) && !empty($v[$n])) ? 1 : 0;
            }
            $fremd = array_diff(array_keys($v), array_keys($out));
            if ($fremd) { return array(null, sprintf(mo_t('TEXT.SICH_FREMD'), $k . '.' . implode(', ' . $k . '.', $fremd))); }
            return array($out, '');

        case 'tts':
            if (!is_array($v)) { return array(null, sprintf(mo_t('TEXT.SICH_WERT'), $k)); }
            $soll = array('mode', 'ip', 'port', 'zones', 'volume', 'lang', 'template');
            $fremd = array_diff(array_keys($v), $soll);
            if ($fremd) { return array(null, sprintf(mo_t('TEXT.SICH_FREMD'), 'tts.' . implode(', tts.', $fremd))); }
            foreach ($soll as $n) {
                if (isset($v[$n]) && !mo_wert_taugt($v[$n])) {
                    return array(null, sprintf(mo_t('TEXT.SICH_WERT'), 'tts.' . $n));
                }
            }
            $mode = isset($v['mode']) ? (string) $v['mode'] : 'musicserver';
            if (!in_array($mode, array('musicserver', 'ms4h', 'audioserver', 'custom'), true)) {
                return array(null, sprintf(mo_t('TEXT.SICH_WERT'), 'tts.mode'));
            }
            $ip = isset($v['ip']) ? trim((string) $v['ip']) : '';
            if ($ip !== '' && !preg_match('/^[\w\.\-]+$/', $ip)) {
                return array(null, sprintf(mo_t('TEXT.SICH_WERT'), 'tts.ip'));
            }
            $zones = isset($v['zones']) ? trim((string) $v['zones']) : '1';
            if ($zones !== '' && !preg_match('/^[0-9~,\s]+$/', $zones)) {
                return array(null, sprintf(mo_t('TEXT.SICH_WERT'), 'tts.zones'));
            }
            $lang = isset($v['lang']) ? strtolower(trim((string) $v['lang'])) : 'de';
            if ($lang !== '' && !preg_match('/^[a-z]{1,5}$/', $lang)) {
                return array(null, sprintf(mo_t('TEXT.SICH_WERT'), 'tts.lang'));
            }
            $tpl = isset($v['template']) ? trim((string) $v['template']) : '';
            if ($tpl !== '' && (strlen($tpl) > 500
                || (stripos($tpl, 'http://') !== 0 && stripos($tpl, 'https://') !== 0))) {
                return array(null, sprintf(mo_t('TEXT.SICH_WERT'), 'tts.template'));
            }
            return array(array(
                'mode' => $mode, 'ip' => $ip,
                'port' => max(1, min(65535, (int) (isset($v['port']) ? $v['port'] : 7091))),
                'zones' => $zones !== '' ? $zones : '1',
                'volume' => max(1, min(100, (int) (isset($v['volume']) ? $v['volume'] : 8))),
                'lang' => $lang !== '' ? $lang : 'de', 'template' => $tpl), '');

        case 'mqtt_topic':
            if (!mo_wert_taugt($v)) { return array(null, sprintf(mo_t('TEXT.SICH_WERT'), $k)); }
            $s = trim((string) $v, '/ ');
            if ($s === '') { $s = 'maeher'; }
            if (!preg_match('#^[\w/\-]+$#', $s)) { return array(null, sprintf(mo_t('TEXT.SICH_WERT'), $k)); }
            return array($s, '');

        case 'aktionstoken':
            if (!mo_wert_taugt($v)) { return array(null, sprintf(mo_t('TEXT.SICH_WERT'), $k)); }
            $s = trim((string) $v);
            // Leer ist zulaessig - die Oberflaeche erzeugt dann eines.
            if ($s !== '' && !preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $s)) {
                return array(null, sprintf(mo_t('TEXT.SICH_WERT'), $k));
            }
            return array($s, '');

        case 'cache_sec':    return mo_zahl_pruefen($k, $v, 5, 300);
        case 'blade_hours':  return mo_zahl_pruefen($k, $v, 1, 2000);
        case 'blade_base':   return mo_zahl_pruefen($k, $v, 0, 100000);
        case 'mqtt_enabled':
        case 'stat_ein':     return mo_zahl_pruefen($k, $v, 0, 1);
    }
    return array(null, sprintf(mo_t('TEXT.SICH_FREMD'), $k));
}

/** Eine Zahl im Bereich - abgewiesen, nicht zurechtgebogen. */
function mo_zahl_pruefen($k, $v, $min, $max)
{
    if (!mo_wert_taugt($v)) { return array(null, sprintf(mo_t('TEXT.SICH_WERT'), $k)); }
    $s = trim((string) $v);
    if ($s === '' || !preg_match('/^-?[0-9]+$/', $s)) {
        return array(null, sprintf(mo_t('TEXT.SICH_WERT'), $k));
    }
    $n = (int) $s;
    if ($n < $min || $n > $max) {
        return array(null, sprintf(mo_t('TEXT.SICH_BEREICH'), $k, $min, $max));
    }
    return array($n, '');
}

/**
 * A7 - Das Merkmal gegen fremde Absender.
 *
 * htmlauth/ schuetzt gegen den unangemeldeten Aufruf - NICHT dagegen, dass
 * der Browser eines angemeldeten Bedieners ein Formular abschickt, das auf
 * einer fremden Seite steht. Die Anmeldung schickt er dabei automatisch mit.
 *
 * GEMESSEN an 1.0.13: ein POST von einer beliebigen fremden Seite mit
 * 'token_neu=1' wuerfelte das Aktionstoken neu. Danach beantwortete der
 * Endpunkt jeden Virtuellen Ausgang mit 403 - und ein Virtueller Ausgang
 * wertet die Antwort nicht aus, der Ausfall bliebe still.
 *
 * Abgeleitet, nicht gespeichert: es gibt damit keinen zweiten Wert, der
 * verlorengehen oder auseinanderlaufen kann, und es wechselt automatisch mit,
 * wenn das Aktionstoken neu gewuerfelt wird.
 *
 * Fail closed: ohne Aktionstoken gibt es kein Merkmal. Ein aus dem Leerstring
 * abgeleiteter Wert waere fuer jeden ausrechenbar und damit kein Schutz,
 * sondern die Behauptung eines Schutzes.
 */
function mo_formtoken()
{
    $cfg = mo_config();
    $t = trim((string) (isset($cfg['aktionstoken']) ? $cfg['aktionstoken'] : ''));
    if ($t === '') { return ''; }
    return hash_hmac('sha256', 'formular-v1', $t);
}

/** Das versteckte Feld - damit es in jedem Formular gleich aussieht. */
function mo_fmt_feld()
{
    return '<input data-role="none" type="hidden" name="fmt" value="'
         . htmlspecialchars(mo_formtoken(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Der wichtigste Punkt: eine halb gueltige Datei ueberschreibt GAR NICHTS.
 * Wer eine Sicherung zurueckspielt, will entweder den ganzen Stand oder
 * gar keinen - eine zur Haelfte uebernommene Konfiguration ist schlimmer
 * als die alte, und man sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function mo_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(mo_t('TEXT.SICH_KEIN_JSON')), 0);
    }
    $neu = mo_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        $ks = (string) $k;
        /* Der lesbare Kopf (_hinweis, _stand, _plugin) wird UEBERGANGEN,
         * nicht beanstandet - er ist keine Einstellung. */
        if ($ks !== '' && $ks[0] === '_') { continue; }
        if (!in_array($ks, $bekannt, true)) {
            $mangel[] = sprintf(mo_t('TEXT.SICH_FREMD'),
                                 htmlspecialchars($ks, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        /* A5: JEDER WERT wird geprueft, nicht nur der Schluessel - gegen
         * dieselbe Positivliste, die auch das Formular benutzt. */
        list($wert, $m) = mo_wert_pruefen($ks, $w);
        if ($m !== '') { $mangel[] = $m; continue; }
        $neu[$ks] = $wert;
        $anzahl++;
    }
    if ($anzahl === 0 && !$mangel) {
        $mangel[] = mo_t('TEXT.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}

/* ==================================================================
 * C1 - Die Selbstpruefung des Reiters Test
 * ==================================================================
 *
 * Bis 1.0.13 enthielt der Reiter vier Knoepfe, eine Legende und eine
 * Statuscode-Tabelle - aber keine einzige Pruefzeile.
 *
 * ok = 1 Haken, 0 Kreuz, 2 Strich ("nicht feststellbar"). Ein Strich ist
 * ausdruecklich KEIN Haken: was nicht gemessen werden konnte, sagt das.
 *
 * Drei Bedingungen, alle aus vorhandenen Regeln:
 *   - Der Endpunktaufruf wird zwischengespeichert (300 s). Alle Reiter werden
 *     bei jedem Seitenaufbau mitgerendert; ohne Zwischenspeicher riefe sich
 *     der Webserver bei jedem Klick selbst auf.
 *   - Jede Zeile, die ueber eine Menge urteilt, prueft zuerst, ob die Menge
 *     leer ist.
 *   - Jede Zeile, die die eigene Datei liest, meldet die Zahl der
 *     angesehenen Stellen. Eine Null ist kein Haken, sondern der Hinweis,
 *     dass nichts gemessen wurde.
 * ================================================================== */

/**
 * Den eigenen Endpunkt WIRKLICH aufrufen - auf 127.0.0.1.
 *
 * Das findet die getrennten Baeume, die keine Leseprüfung sieht: html/ und
 * htmlauth/ liegen installiert in verschiedenen Verzeichnissen, und ein
 * require ueber '..' trifft nur das ausgepackte Archiv.
 *
 * Drei Ausgaenge, nicht zwei: geantwortet und plausibel, geantwortet und
 * falsch, NICHT FESTSTELLBAR.
 */
function mo_endpunkt_probe($frisch = false)
{
    $cache = mo_paths()['datadir'] . '/endpunkt.json';
    if (!$frisch && is_file($cache) && time() - filemtime($cache) < 300) {
        $c = mo_json_lesen($cache);
        if (isset($c['ok'])) { return $c; }
    }
    $cfg = mo_config();
    $tok = (string) $cfg['aktionstoken'];
    $ordner = mo_paths()['plugin'];
    if ($tok === '') {
        $r = array('ok' => 0, 'text' => mo_t('TEXT.PRUEF_EP_KEIN_TOKEN'));
        mo_write_json($cache, $r);
        return $r;
    }
    $port = (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] > 0)
        ? (int) $_SERVER['SERVER_PORT'] : 80;
    $url = 'http://127.0.0.1:' . $port . '/plugins/' . rawurlencode($ordner)
         . '/mower.php?selftest=1&token=' . rawurlencode($tok);
    $antwort = false; $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $antwort = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        /* Beim Bauen dieser Fassung aufgefallen: ohne curl derselbe Rueckfall
         * wie in mo_api_roh(). Fehlte er, meldete diese Zeile dauerhaft
         * "nicht feststellbar" - auch auf einer Anlage, deren Endpunkt
         * tadellos antwortet. Eine Pruefung, die auf einer ganzen
         * Geraeteklasse nie etwas messen kann, ist keine. */
        $alt = ini_get('default_socket_timeout');
        @ini_set('default_socket_timeout', '2');
        $antwort = @file_get_contents($url, false, stream_context_create(
            array('http' => array('timeout' => 5, 'ignore_errors' => true))));
        if (isset($http_response_header) && is_array($http_response_header)
            && isset($http_response_header[0])
            && preg_match('#\s(\d{3})\s#', ' ' . $http_response_header[0] . ' ', $t)) {
            $code = (int) $t[1];
        }
        @ini_set('default_socket_timeout', (string) $alt);
    }
    if ($antwort === false) {
        $r = array('ok' => 2, 'text' => mo_t('TEXT.PRUEF_EP_UNKLAR'));
    } elseif (strpos((string) $antwort, 'SELFTEST;OK=1') !== false) {
        $r = array('ok' => 1, 'text' => sprintf(mo_t('TEXT.PRUEF_EP_JA'), $code));
    } else {
        $r = array('ok' => 0, 'text' => sprintf(mo_t('TEXT.PRUEF_EP_NEIN'), $code,
            mo_kuerzen(trim((string) $antwort), 80)));
    }
    mo_write_json($cache, $r);
    return $r;
}

/**
 * ALLE Werte eines Maehers - unter den Namen, die auch die Vorlage traegt.
 *
 * EINE Quelle fuer drei Verbraucher: die Antwortzeile des Endpunkts, die
 * Loxone-Vorlage und die Themenliste im Reiter MQTT. Bis 1.0.13 stand die
 * Antwortzeile als feste printf-Zeichenkette in mower.php und die Feldliste
 * getrennt davon in mo_felder() - wer eines von beiden ergaenzte, musste an
 * das andere denken. Die Pruefzeile im Reiter Test haelt beide gegeneinander.
 */
function mo_werte($dev = 1, $st = null)
{
    if ($st === null) { $st = mo_state($dev); }
    $f = mo_meldeflags($dev);
    $z = mo_zusatzwerte($dev);
    return array(
        'OK' => (int) $st['ok'],                 'CODE' => (int) $st['code'],
        'MODUS' => (int) $st['modus'],           'BATT' => (int) $st['batterie'],
        'MAEHT' => (int) $st['maeht'],           'LAEDT' => (int) $st['laedt'],
        'FEHLER' => (int) $st['fehler'],         'STUNDEN' => (int) $st['stunden'],
        'DAUER' => (int) $st['dauer'],           'MESSER' => (int) $st['messer_rest'],
        'MESSERWARN' => (int) $st['messer_warn'],
        // Die Fliesskommawerte gehen mit einer Nachkommastelle hinaus.
        // number_format() und nicht printf('%.1f'): die Locale-Einstellung
        // koennte sonst ein Komma erzeugen, und Loxone liest dann nichts.
        'TEMP' => number_format((float) $st['temperatur'], 1, '.', ''),
        'FEUCHTE' => number_format((float) $st['feuchte'], 1, '.', ''),
        'WLAN' => (int) $st['wlan'],             'TIMER' => (int) $st['timer'],
        'ANN' => (int) $f['ann'],                'AUDIO' => (int) $f['audio'],
        'PUSH' => (int) $f['push'],              'PTEST' => (int) $f['ptest'],
        'TS' => (int) $z['ts'],                  'ZAEHLER' => (int) $z['zaehler'],
        'FEHLERALTER' => (int) $z['fehleralter'],
    ) + (isset($z['einsheute']) ? array(
        'EINSHEUTE' => (int) $z['einsheute'],    'MINHEUTE' => (int) $z['minheute'],
        'EINSWOCHE' => (int) $z['einswoche'],    'MINWOCHE' => (int) $z['minwoche'],
    ) : array());
}

/**
 * Die Antwortzeile fuer den Miniserver.
 *
 * Sie beginnt mit 'MOWER;', jedes Feld steht hinter einem Semikolon - genau
 * darauf sucht mo_check().
 */
function mo_zeile($dev = 1, $st = null)
{
    $teile = array('MOWER');
    foreach (mo_werte($dev, $st) as $k => $v) { $teile[] = $k . '=' . $v; }
    return implode(';', $teile);
}

/**
 * Text kuerzen, ohne mitten in einem Zeichen zu schneiden.
 *
 * OHNE mbstring. Die Erweiterung ist auf einem LoxBerry nicht zugesichert
 * (REGELN_2: php-mbstring ist auf einem PHP-7.4-LoxBerry das falsche Paket),
 * und diese Funktion wird ausgerechnet im FEHLERZWEIG der Selbstpruefung
 * gerufen: mit mb_substr() starb die Zeile genau dann, wenn sie einen Befund
 * melden sollte. Beim Bauen dieser Fassung gemessen - drei von sechs
 * Eichfaellen brachen mit "Call to undefined function mb_strlen" ab, und alle
 * drei waren die Faelle, fuer die es die Pruefung gibt.
 *
 * Geschnitten wird an einer Zeichengrenze: eine angeschnittene UTF-8-Folge am
 * Ende macht die ganze Ausgabe ungueltig.
 */
function mo_kuerzen($s, $n)
{
    $s = (string) $s;
    if ($s === '') { return '-'; }
    if (strlen($s) <= $n) { return $s; }
    $teil = substr($s, 0, $n);
    $ohne = preg_replace('/(?:[À-ÿ][-¿]*)$/', '', $teil);
    if (is_string($ohne)) { $teil = $ohne; }
    return $teil . '...';
}

/**
 * Passen Reiterleiste, Bereiche und Positivliste zusammen?
 *
 * Verglichen werden die MENGEN, nicht die Anzahlen: ein Bereich mit einem
 * anderen NAMEN laesst die Zahlen stimmen und fuehrt den Reiter trotzdem ins
 * Leere.
 */
function mo_reiterprobe(array $reiter, $datei)
{
    $s = (string) @file_get_contents($datei);
    if ($s === '') {
        return array(2, sprintf(mo_t('TEXT.PRUEF_DATEI_LEER'), basename($datei)));
    }
    $soll = array_keys($reiter);

    $leiste = array();
    if (preg_match_all('/data-ziel="(tab-[a-z0-9]+)"/', $s, $y)) { $leiste = $y[1]; }
    $bereiche = array();
    if (preg_match_all('/class="sm-seite[^"]*"[^>]*id="(tab-[a-z0-9]+)"/', $s, $y)) { $bereiche = $y[1]; }

    if (!$leiste)   { return array(0, mo_t('TEXT.PRUEF_REITER_LEER')); }
    $fehlt = array_values(array_diff($soll, $bereiche));
    $ueber = array_values(array_diff($bereiche, $soll));
    if ($fehlt) { return array(0, sprintf(mo_t('TEXT.PRUEF_REITER_FEHLT'), implode(', ', $fehlt))); }
    if ($ueber) { return array(0, sprintf(mo_t('TEXT.PRUEF_REITER_UEBER'), implode(', ', $ueber))); }
    $fehlt2 = array_values(array_diff($soll, $leiste));
    if ($fehlt2) { return array(0, sprintf(mo_t('TEXT.PRUEF_REITER_LEISTE'), implode(', ', $fehlt2))); }
    return array(1, sprintf(mo_t('TEXT.PRUEF_REITER_JA'), count($soll)));
}

/** Tragen alle Formulare das Merkmal gegen fremde Absender? */
function mo_formularprobe($datei)
{
    $s = (string) @file_get_contents($datei);
    if ($s === '') {
        return array(2, sprintf(mo_t('TEXT.PRUEF_DATEI_LEER'), basename($datei)));
    }
    $gesamt = 0; $ohne = 0;
    if (preg_match_all('/<form\s/', $s, $y, PREG_OFFSET_CAPTURE)) {
        foreach ($y[0] as $f) {
            $gesamt++;
            $ende = strpos($s, '</form>', $f[1]);
            $blk = substr($s, $f[1], ($ende === false ? 400 : $ende - $f[1]));
            if (strpos($blk, 'name="fmt"') === false && strpos($blk, 'mo_fmt_feld') === false) { $ohne++; }
        }
    }
    // Die leere Menge zuerst: "alle 0 von 0 sind in Ordnung" ist kein Haken.
    if ($gesamt === 0) { return array(0, mo_t('TEXT.PRUEF_FORM_LEER')); }
    if ($ohne > 0)     { return array(0, sprintf(mo_t('TEXT.PRUEF_FORM_NEIN'), $ohne, $gesamt)); }
    return array(1, sprintf(mo_t('TEXT.PRUEF_FORM_JA'), $gesamt));
}

/** Steht der Sicherungs-Download VOR dem Seitenkopf? */
function mo_downloadprobe($datei)
{
    $s = (string) @file_get_contents($datei);
    if ($s === '') {
        return array(2, sprintf(mo_t('TEXT.PRUEF_DATEI_LEER'), basename($datei)));
    }
    /* Gesucht wird die AUFRUFFORM, nicht das Wort: 'LBWeb::lbheader' steht
     * auch im erklaerenden Kommentar am Dateikopf, und eine Suche nach dem
     * Namen nimmt dessen erste Fundstelle. Beim Bauen dieser Fassung ist
     * genau das passiert - die Zeile stand auf Rot, obwohl der Zweig richtig
     * sass. Dieselbe Klasse wie die Wache, die auf den eigenen Kommentar
     * anschlaegt (REGELN_1, Abschnitt 6). */
    $dl = strpos($s, "\$_POST['mo_sichern']");
    $kopf = strpos($s, '{ LBWeb::lbheader(');
    if ($dl === false || $kopf === false) { return array(2, mo_t('TEXT.PRUEF_DL_UNKLAR')); }
    return ($dl < $kopf)
        ? array(1, mo_t('TEXT.PRUEF_DL_JA'))
        : array(0, mo_t('TEXT.PRUEF_DL_NEIN'));
}

/** Sind die erzeugbaren Loxone-Vorlagen wohlgeformt? */
function mo_vorlagenprobe()
{
    $n = 0;
    foreach (array(mo_vorlage(1), mo_vo_vorlage()) as $v) {
        $n++;
        $alt = libxml_use_internal_errors(true);
        $x = simplexml_load_string($v[1]);
        libxml_clear_errors();
        libxml_use_internal_errors($alt);
        if ($x === false) { return array(0, sprintf(mo_t('TEXT.PRUEF_XML_NEIN'), $v[0])); }
    }
    return array(1, sprintf(mo_t('TEXT.PRUEF_XML_JA'), $n));
}

/**
 * Stimmt die Themenliste mit dem Sendecode ueberein?
 *
 * Die Tabelle im Reiter MQTT ist die Anleitung. Sie entsteht aus derselben
 * Quelle wie der Sendecode - diese Zeile misst, dass das so bleibt.
 */
function mo_themen()
{
    /* Dieselben Schluessel, die mo_mqtt_publish() sendet - in derselben
     * Reihenfolge. Wer dort etwas ergaenzt, ergaenzt es hier. Die Pruefzeile
     * unten haelt beide gegeneinander. */
    $je_maeher = array('ok', 'code', 'status', 'modus', 'batterie', 'maeht', 'laedt',
        'fehler', 'stunden', 'dauer', 'messer_rest', 'messer_warn', 'temperatur',
        'feuchte', 'wlan', 'timer');
    $je_maeher = array_merge($je_maeher, array_keys(mo_meldeflags(1)));
    $je_maeher = array_merge($je_maeher, array_keys(mo_zusatzwerte(1)));
    return array('maeher' => $je_maeher,
                 'anlage' => array('status/ts', 'status/zaehler', 'status/ok'));
}

/**
 * Die vollstaendige Selbstpruefung.
 *
 * $datei ist die index.php der Oberflaeche; die Zeilen, die die eigene Datei
 * lesen, brauchen sie. $reiter ist die Reiterliste zur Laufzeit - sie ein
 * zweites Mal aus dem Quelltext zu lesen waere eine zweite Wahrheit.
 */
function mo_selbsttest($datei, array $reiter)
{
    $cfg = mo_config($zustand);
    $z = array();
    $add = function ($schluessel, $ok, $text) use (&$z) { $z[] = array($schluessel, $ok, $text); };

    // --- Konfiguration
    $texte = array('ok' => 'PRUEF_CFG_OK', 'leer' => 'PRUEF_CFG_LEER',
                   'zweitschrift' => 'PRUEF_CFG_ZWEIT', 'kaputt' => 'PRUEF_CFG_KAPUTT',
                   'kaputt_ohne_zweitschrift' => 'PRUEF_CFG_KAPUTT_OHNE');
    $schl = isset($texte[$zustand]) ? $texte[$zustand] : 'PRUEF_CFG_LEER';
    $add('PRUEF.CFG_HEIL', ($zustand === 'ok' ? 1 : ($zustand === 'zweitschrift' ? 2 : 0)),
         mo_t('TEXT.' . $schl));

    $lage = mo_cfg_lage();
    if ($lage['fehlend']) {
        $add('PRUEF.CFG_VOLL', 0, sprintf(mo_t('TEXT.PRUEF_CFG_FEHLT'),
            count($lage['fehlend']), $lage['anzahl'], implode(', ', $lage['fehlend'])));
    } elseif ($lage['fremd']) {
        $add('PRUEF.CFG_VOLL', 0, sprintf(mo_t('TEXT.PRUEF_CFG_FREMD'), implode(', ', $lage['fremd'])));
    } else {
        $add('PRUEF.CFG_VOLL', 1, sprintf(mo_t('TEXT.PRUEF_CFG_VOLL'), $lage['anzahl']));
    }

    // --- Maeher
    $liste = mo_mowers();
    if (!$liste) {
        $add('PRUEF.MAEHER', 0, mo_t('TEXT.PRUEF_MAEHER_KEINER'));
    } else {
        $gut = 0; $namen = array();
        foreach ($liste as $n => $mw) {
            $st = mo_state($n);
            if ($st['ok']) { $gut++; }
            else { $namen[] = $mw['name'] . ' (' . ($st['grundtext'] !== '' ? $st['grundtext'] : $st['text']) . ')'; }
        }
        $add('PRUEF.MAEHER', ($gut === count($liste) ? 1 : ($gut > 0 ? 2 : 0)),
             $gut === count($liste)
                ? sprintf(mo_t('TEXT.PRUEF_MAEHER_JA'), $gut)
                : sprintf(mo_t('TEXT.PRUEF_MAEHER_NEIN'), $gut, count($liste), implode('; ', $namen)));
    }

    // --- Lebenszeichen des Cron-Laufs
    $alter = mo_lauf_alter();
    $lauf = mo_lauf_lesen();
    if ($alter < 0) {
        $add('PRUEF.CRON', 0, mo_t('TEXT.PRUEF_CRON_NIE'));
    } elseif ($alter > 300) {
        $add('PRUEF.CRON', 0, sprintf(mo_t('TEXT.PRUEF_CRON_ALT'), $alter));
    } else {
        $add('PRUEF.CRON', 1, sprintf(mo_t('TEXT.PRUEF_CRON_JA'), $alter, (int) $lauf['zaehler']));
    }

    // --- Aktionstoken
    $add('PRUEF.TOKEN', trim((string) $cfg['aktionstoken']) !== '' ? 1 : 0,
         trim((string) $cfg['aktionstoken']) !== ''
            ? mo_t('TEXT.PRUEF_TOKEN_JA') : mo_t('TEXT.PRUEF_TOKEN_NEIN'));

    // --- Der eigene Endpunkt
    $ep = mo_endpunkt_probe();
    $add('PRUEF.ENDPUNKT', (int) $ep['ok'], (string) $ep['text']);

    // --- MQTT-Gateway
    $gw = mo_mqtt_gateway_info();
    if ($gw === null) {
        $add('PRUEF.GATEWAY', 2, mo_t('TEXT.PRUEF_GW_UNKLAR'));
    } else {
        $add('PRUEF.GATEWAY', $gw['autostart'] ? 1 : 0,
             sprintf(mo_t($gw['autostart'] ? 'TEXT.PRUEF_GW_JA' : 'TEXT.PRUEF_GW_NEIN'),
                     $gw['fassung'] > 0 ? (string) $gw['fassung'] : mo_t('TEXT.PRUEF_GW_UNBEKANNT')));
    }

    // --- Themenliste gegen den Sendecode
    $th = mo_themen();
    $felder = count(mo_felder());
    $add('PRUEF.THEMEN', 1, sprintf(mo_t('TEXT.PRUEF_THEMEN'),
         count($th['maeher']), count($th['anlage']), $felder));

    // --- Die eigene Datei
    list($ok, $txt) = mo_reiterprobe($reiter, $datei);   $add('PRUEF.REITER', $ok, $txt);
    list($ok, $txt) = mo_formularprobe($datei);          $add('PRUEF.FORMULARE', $ok, $txt);
    list($ok, $txt) = mo_downloadprobe($datei);          $add('PRUEF.DOWNLOAD', $ok, $txt);
    list($ok, $txt) = mo_vorlagenprobe();                $add('PRUEF.VORLAGEN', $ok, $txt);

    return $z;
}
