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
    if ($lb && is_dir($lb . '/config/plugins/' . $pd) === false) { $pd = 'robonect'; }
    if ($lb) {
        return array('config' => $lb . '/config/plugins/' . $pd . '/mower.json',
                     'backup' => $lb . '/config/plugins/' . $pd . '.backup.json',
                     'log' => $lb . '/log/plugins/' . $pd . '/mower.log',
                     'data' => $lb . '/data/plugins/' . $pd,
                     'tmp' => '/tmp/robonect', 'lbhome' => $lb);
    }
    return array('config' => dirname(dirname(__DIR__)) . '/config/mower.json',
                 'backup' => dirname(dirname(__DIR__)) . '/config/mower.backup.json',
                 'log' => sys_get_temp_dir() . '/robonect/mower.log',
                 'data' => sys_get_temp_dir() . '/robonect/data',
                 'tmp' => sys_get_temp_dir() . '/robonect', 'lbhome' => '');
}

function mo_config() {
    $p = mo_paths();
    if ((!is_file($p['config']) || trim((string) @file_get_contents($p['config'])) === '' || trim((string) @file_get_contents($p['config'])) === '{}') && is_file($p['backup'])) {
        @mkdir(dirname($p['config']), 0775, true);
        @copy($p['backup'], $p['config']);
        @chmod($p['config'], 0600);
    }
    $cfg = is_file($p['config']) ? (json_decode((string) file_get_contents($p['config']), true) ?: array()) : array();
    if (!is_array($cfg)) { $cfg = array(); }
    $cfg += array(
        'mowers' => array(),          // [{name, ip, user, pass}]
        'cache_sec' => 20,
        'blade_hours' => 200,         // Messerwechsel-Intervall in Betriebsstunden
        'blade_base' => 0,            // Betriebsstunden beim letzten Messerwechsel
        'mqtt_enabled' => 0,
        'mqtt_topic' => 'maeher',
        'notify' => array(),
        'tts' => array(),
        'aktionstoken' => '',          // schuetzt ?cmd= (unangemeldeter Endpunkt)
    );
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
function mo_datadir() { $p = mo_paths(); if (!is_dir($p['data'])) { @mkdir($p['data'], 0775, true); } return $p['data']; }

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
function mo_api($cmd, $dev = 1, $extra = '', $tmo = 3) {
    $m = mo_mower($dev);
    if ($m === null) { return null; }
    // Antwortet er gerade nicht, gar nicht erst warten.
    if (mo_stumm($dev)) { return null; }
    $url = 'http://' . $m['ip'] . '/json?cmd=' . rawurlencode($cmd) . ($extra !== '' ? '&' . $extra : '');
    $head = "Accept: application/json\r\n";
    if ($m['user'] !== '' || $m['pass'] !== '') {
        $head .= 'Authorization: Basic ' . base64_encode($m['user'] . ':' . $m['pass']) . "\r\n";
    }
    $ctx = stream_context_create(array('http' => array('timeout' => $tmo, 'header' => $head,
        'user_agent' => 'LoxBerry Robonect', 'ignore_errors' => true)));
    $r = @file_get_contents($url, false, $ctx);
    if ($r === false) {
        mo_stumm_setzen($dev);
        return null;
    }
    mo_stumm_loeschen($dev);
    $j = @json_decode($r, true);
    return is_array($j) ? $j : null;
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
function mo_write_atomic($datei, $inhalt) {
    if ($inhalt === false || $inhalt === null) { return false; }
    $inhalt = (string) $inhalt;
    $ordner = dirname($datei);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) { return false; }
    $tmp = $datei . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
    if (@file_put_contents($tmp, $inhalt) !== strlen($inhalt)) { @unlink($tmp); return false; }
    @chmod($tmp, 0644);
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
                'wlan' => 0, 'timer' => 0, 'maeht' => 0, 'ts' => time());
    if ($m === null) { return $st; }
    $j = mo_api('status', $dev);
    if (is_array($j) && isset($j['status'])) {
        $st['ok'] = !empty($j['successful']) ? 1 : 1;
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

function mo_command($cmd, $dev = 1, $param = '') {
    $m = mo_mower($dev);
    if ($m === null) { return array(0, 'Maeher nicht konfiguriert'); }
    $cmd = strtolower(trim((string) $cmd));
    $map = array('auto' => array('mode', 'mode=auto'), 'manuell' => array('mode', 'mode=man'),
                 'man' => array('mode', 'mode=man'), 'home' => array('mode', 'mode=home'),
                 'eod' => array('mode', 'mode=eod'), 'start' => array('start', ''),
                 'stop' => array('stop', ''));
    if ($cmd === 'job') {
        $p = preg_replace('/[^0-9a-z=&%\-]/i', '', (string) $param);
        $j = mo_api('mode', $dev, 'mode=job' . ($p !== '' ? '&' . $p : ''));
    } elseif (isset($map[$cmd])) {
        $j = mo_api($map[$cmd][0], $dev, $map[$cmd][1]);
    } else {
        return array(0, 'unbekannter Befehl');
    }
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
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
    $json_neu = json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json_neu !== false) { @file_put_contents($p['config'], $json_neu); }
    @chmod($p['config'], 0600);
    @copy($p['config'], $p['backup']);
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
    $prefix = trim((string) $cfg['mqtt_topic']) !== '' ? trim((string) $cfg['mqtt_topic']) : 'maeher';
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
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) { return; }
    foreach ($m as $k => $v) {
        $msg = 'publish ' . $prefix . '/' . $k . ' ' . mo_mqtt_wert_saeubern($v);
        @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $udp);
    }
    socket_close($s);
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
        $meldung = '';
        // Fehler (Statuswechsel nach 7 oder Schleifensignal verloren)
        if (!empty($cfg['notify']['fehler']) && in_array($st['code'], array(7, 8), true) && $pcode !== $st['code']) {
            $meldung = 'Achtung: ' . $st['name'] . ' meldet '
                     . ($st['code'] === 8 ? 'Schleifensignal verloren' : 'einen Fehler')
                     . ($st['fehlertext'] !== '' ? ': ' . $st['fehlertext'] : '.');
        }
        // Maehen beendet (von "maeht" zurueck in die Station)
        if (!empty($cfg['notify']['fertig']) && $pcode === 2 && in_array($st['code'], array(1, 3, 4), true)) {
            $meldung = $st['name'] . ' ist mit dem Maehen fertig.'
                     . ($st['dauer'] > 0 ? ' Laufzeit ' . (int) $st['dauer'] . ' Minuten.' : '');
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
        mo_write_json($f, array('code' => $st['code'], 'ts' => time()));
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

/* ---------------- Loxone-Vorlage (Hausstandard "Alles auf einmal anlegen") ---------------- */
/** name => array(analog, min, max, einheit, kommentar) */
function mo_felder() {
    return array(
        'OK'         => array(0, 0, 1,    '',    '1 = Maeher erreichbar'),
        'CODE'       => array(1, 0, 99,   '',    'Statuszahl des Maehers (Robonect-Status)'),
        'MODUS'      => array(1, 0, 99,   '',    'Betriebsmodus (Auto, Manuell, Zuhause, ...)'),
        'BATT'       => array(1, 0, 100,  '%',   'Batterie in Prozent'),
        'MAEHT'      => array(0, 0, 1,    '',    '1 = maeht gerade'),
        'LAEDT'      => array(0, 0, 1,    '',    '1 = laedt gerade'),
        'FEHLER'     => array(1, 0, 10000,'',    'Fehlercode (0 = kein Fehler)'),
        'STUNDEN'    => array(1, 0, 100000,'h',  'Maehstunden gesamt'),
        'DAUER'      => array(1, 0, 10000,'min', 'Dauer des laufenden Einsatzes'),
        'MESSER'     => array(1, 0, 10000,'h',   'Messer: Stunden seit Wechsel'),
        'MESSERWARN' => array(0, 0, 1,    '',    '1 = Messerwechsel faellig'),
        'TEMP'       => array(1, -30, 80, 'GradC','Temperatur am Maeher'),
        'FEUCHTE'    => array(1, 0, 100,  '%',   'Luftfeuchte am Maeher'),
        'WLAN'       => array(1, -100, 0, 'dBm', 'WLAN-Signalstaerke'),
        'TIMER'      => array(0, 0, 1,    '',    '1 = Timer aktiv'),
        'ANN'        => array(0, 0, 1,    '',    'Meldefenster aktiv'),
        'AUDIO'      => array(0, 0, 1,    '',    'Ansage freigegeben'),
        'PUSH'       => array(0, 0, 1,    '',    'Push freigegeben'),
        'PTEST'      => array(0, 0, 1,    '',    'Test-Push ausloesen'),
    );
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

/** Hausstandard: Gateway-Autostart aus general.json (PLUGIN_HAUSREGELN Abschnitt 3). */
function mo_mqtt_gateway_autostart() {
    $p = mo_paths();
    $home = isset($p['lbhome']) && $p['lbhome'] !== '' ? $p['lbhome'] : (getenv('LBHOMEDIR') ?: '/opt/loxberry');
    $gj = $home . '/config/system/general.json';
    if (!is_file($gj)) { return null; }
    $d = json_decode((string) @file_get_contents($gj), true);
    if (!is_array($d) || !isset($d['Mqtt'])) { return null; }
    return !empty($d['Mqtt']['Gatewayautostart']);
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
            'check' => '\i' . $name . '=\i\v',
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
