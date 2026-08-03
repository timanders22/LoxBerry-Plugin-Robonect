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

function mo_paths() {
    $lb = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
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

function mo_log($msg) {
    $p = mo_paths(); $f = $p['log'];
    if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
    if (is_file($f) && filesize($f) > 512000) {
        $tail = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), -200);
        @file_put_contents($f, implode("\n", $tail) . "\n");
    }
    // Sicherheitsnetz: falls je ein Passwort in eine Meldung geraet, wird es maskiert
    $cfg = mo_config();
    foreach ((array) $cfg['mowers'] as $m) {
        $pw = (string) (isset($m['pass']) ? $m['pass'] : '');
        if ($pw !== '') { $msg = str_replace($pw, '***', $msg); }
    }
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}
function mo_log_if_changed($key, $line) {
    $f = mo_tmpdir() . '/last_' . $key . '.txt';
    $prev = is_file($f) ? (string) file_get_contents($f) : '';
    if ($line !== $prev) { mo_log($key . ': ' . $line); @file_put_contents($f, $line); }
}

/* ---------------- Zugriff auf das Robonect-Modul ---------------- */

/** Ruft die JSON-Schnittstelle auf. Zugangsdaten per HTTP-Basic-Auth (nicht in der URL). */
function mo_api($cmd, $dev = 1, $extra = '', $tmo = 8) {
    $m = mo_mower($dev);
    if ($m === null) { return null; }
    $url = 'http://' . $m['ip'] . '/json?cmd=' . rawurlencode($cmd) . ($extra !== '' ? '&' . $extra : '');
    $head = "Accept: application/json\r\n";
    if ($m['user'] !== '' || $m['pass'] !== '') {
        $head .= 'Authorization: Basic ' . base64_encode($m['user'] . ':' . $m['pass']) . "\r\n";
    }
    $ctx = stream_context_create(array('http' => array('timeout' => $tmo, 'header' => $head,
        'user_agent' => 'LoxBerry Robonect', 'ignore_errors' => true)));
    $r = @file_get_contents($url, false, $ctx);
    if ($r === false) { return null; }
    $j = @json_decode($r, true);
    return is_array($j) ? $j : null;
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
    // Temperatur und Feuchte (eigener Aufruf, nicht bei jedem Modul vorhanden)
    $h = mo_api('health', $dev);
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
    file_put_contents($cache, json_encode($st));
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
    @file_put_contents($p['config'], json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @chmod($p['config'], 0600);
    @copy($p['config'], $p['backup']);
    @unlink(mo_tmpdir() . '/state_' . (int) $dev . '.json');
    mo_log('Messerwechsel quittiert - neuer Nullpunkt: ' . (int) $st['stunden'] . ' Betriebsstunden');
    return 1;
}

/* ---------------- MQTT ---------------- */

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
               'temperatur' => $st['temperatur'], 'feuchte' => $st['feuchte'], 'wlan' => $st['wlan']);
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) { return; }
    foreach ($m as $k => $v) {
        $msg = 'publish ' . $prefix . '/' . $k . ' ' . $v;
        @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $udp);
    }
    socket_close($s);
}

/* ---------------- Ansage (TTS) ---------------- */

function mo_tts_url($text) {
    $cfg = mo_config(); $tts = $cfg['tts']; $mode = $tts['mode'];
    if ($mode === 'audioserver') { return null; }
    if ((string) $tts['ip'] === '') { return ''; }
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
        @file_put_contents($f, json_encode(array('code' => $st['code'], 'ts' => time())));
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
            foreach (array('/opt/loxberry', '/home/loxberry/loxberry') as $k) {
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
