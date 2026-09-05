<?php
/**
 * Rasenmaeher (Robonect) - Admin-Oberflaeche
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * WICHTIG: der Seitenkopf des SDK setzt GLOBALS (u.a. $cfg aus general.json als
 * stdClass) und wuerde gleichnamige Plugin-Variablen ueberschreiben - daher
 * tragen hier ALLE Variablen ein mw_-Praefix.
 *
 * ==================================================================
 * DIE REIHENFOLGE IN DIESER DATEI IST BAUVORSCHRIFT, NICHT GESCHMACK
 * ==================================================================
 *
 *   1. Bibliothek laden
 *   2. Konfiguration lesen, Vorgaben vervollstaendigen, Token erzeugen
 *   3. WACHPOSTEN gegen fremde Formulare
 *   4. Reiterwahl
 *   5. Handler - darunter JEDER Download, der mit exit endet
 *   6. ERST JETZT den Seitenkopf des SDK ausgeben
 *   7. HTML
 *
 * GEMESSEN an 1.0.13 (26.08.2026): der Knopf "Einstellungen sichern" stand in
 * Abschnitt 6, also hinter lbheader(). Der Seitenkopf war damit schon
 * geschrieben, header() kam zu spaet, und statt einer Datei bekam der Anwender
 *
 *     Content-type: text/html
 *     Warning: Cannot modify header information - headers already sent
 *     { "mowers": [...], "aktionstoken": "..." }
 *
 * also die vollstaendige Konfiguration samt Aktionstoken als sichtbaren Text
 * in einer HTML-Seite, die der Browser in Verlauf und Zwischenspeicher legt.
 * Wer einen Download-Knopf ergaenzt - Vorlage, Sicherung, Protokollauszug -,
 * setzt ihn in Abschnitt 5. Die Pruefzeile dazu steht im Reiter Test.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

/* ================= 1. Pfade und Bibliothek ================= */

$mw_lbhome = getenv('LBHOMEDIR') ?: (function_exists('lb_wurzel_ermitteln') ? lb_wurzel_ermitteln() : '');
$mw_plugin = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
if ($mw_lbhome && is_dir($mw_lbhome . '/config/plugins/' . $mw_plugin) === false) {
    $mw_plugin = basename(dirname(__DIR__));
    if (is_dir($mw_lbhome . '/config/plugins/' . $mw_plugin) === false) {
        /* Rueckfall auf den vorgesehenen Ordnernamen nur, wenn dort noch
         * nichts liegt oder schon die EIGENE Konfigurationsdatei - ein
         * zweites Plugin darf denselben FOLDER beanspruchen. */
        $mw_ziel = $mw_lbhome . '/config/plugins/robonect';
        if (!is_dir($mw_ziel) || is_file($mw_ziel . '/mower.json')) { $mw_plugin = 'robonect'; }
    }
}

/* Die Bibliothek liegt unter webfrontend/html/, weil der Loxone-Endpunkt sie
 * ebenfalls braucht. Installiert sind html/ und htmlauth/ ZWEI GETRENNTE
 * BAEUME - ein require ueber '..' traefe nur das ausgepackte Archiv. Deshalb
 * eine Kandidatenliste, vom installierten Fall zum Archivfall. */
$mw_kandidaten = array();
if ($mw_lbhome !== '') {
    $mw_kandidaten[] = $mw_lbhome . '/webfrontend/html/plugins/' . $mw_plugin . '/mower_lib.php';
}
$mw_kandidaten[] = dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/mower_lib.php';
$mw_kandidaten[] = dirname(__DIR__) . '/html/mower_lib.php';
$mw_lib = '';
foreach ($mw_kandidaten as $mw_cand) {
    if (is_file($mw_cand)) { require_once $mw_cand; $mw_lib = $mw_cand; break; }
}
if ($mw_lib === '') {
    /* Nicht wortlos scheitern: die angemeldete Oberflaeche nennt die
     * durchsuchten Pfade. Ein leerer HTTP 500 schickt den Anwender auf eine
     * Suche, die er nicht gewinnen kann. */
    header('Content-Type: text/plain; charset=utf-8');
    echo "Die Programmbibliothek mower_lib.php wurde nicht gefunden.\n\nGesucht wurde in:\n";
    foreach ($mw_kandidaten as $mw_cand) { echo '  ' . $mw_cand . "\n"; }
    exit;
}

if ($mw_lbhome) {
    $mw_sdk = $mw_lbhome . '/libs/phplib/loxberry_system.php';
    if (file_exists($mw_sdk)) { require_once $mw_sdk; require_once $mw_lbhome . '/libs/phplib/loxberry_web.php'; }
}

$mw_p       = mo_paths();
$mw_cfgfile = $mw_p['config'];
$mw_logfile = $mw_p['log'];


/* ================= 2. Konfiguration, Vorgaben, Token ================= */

$mw_saved = false; $mw_note = '';
$mw_fehler = array();     // gesammelte Beanstandungen - nie ueberschreiben

/* mo_config() heilt selbst: fehlende, leere und beschaedigte Dateien holt es
 * aus der Zweitschrift und schreibt sie zurueck. $mw_zustand sagt, was der
 * Fall war - der Reiter Test zeigt es an. */
$mw_cfg = mo_config($mw_zustand);
if (!is_array($mw_cfg)) { $mw_cfg = array(); }

/* Vervollstaendigen, nicht ergaenzen: fehlt ein Schluessel, wird er EINMAL
 * mit seiner Vorgabe in die Datei geschrieben. Danach heisst "fehlt" nie mehr
 * "gilt als 0". Geschrieben wird nur, wenn wirklich etwas gefehlt hat. */
$mw_fehlten = mo_cfg_vervollstaendigen($mw_cfg);

/* Beim ersten Aufruf ein Token erzeugen, damit der Endpunkt fuer Loxone
 * sofort benutzbar ist. Das steht VOR dem Wachposten: ohne Aktionstoken gibt
 * es kein Formularmerkmal, und die Erstinbetriebnahme waere blockiert. */
if (trim((string) $mw_cfg['aktionstoken']) === '') {
    $mw_cfg['aktionstoken'] = mo_token_erzeugen();
    $mw_fehlten[] = 'aktionstoken';
}
if ($mw_fehlten) {
    if (!mo_config_speichern($mw_cfg)) {
        $mw_fehler[] = sprintf(mo_t('TEXT.FEHLER_SCHREIBEN'), mw_e($mw_cfgfile));
    } else {
        mo_log('Konfiguration ergaenzt: ' . implode(', ', $mw_fehlten));
    }
}

/* ================= 3. Wachposten gegen fremde Formulare ================= */
/*
 * htmlauth/ schuetzt gegen den unangemeldeten Aufruf - NICHT dagegen, dass
 * der Browser eines angemeldeten Bedieners ein Formular abschickt, das auf
 * einer fremden Seite steht. Die HTTP-Basic-Anmeldung schickt er dabei
 * automatisch mit; SameSite greift nicht.
 *
 * GEMESSEN an 1.0.13: ein POST von einer beliebigen fremden Seite mit
 * 'token_neu=1' wuerfelte das Aktionstoken neu. Danach bekamen saemtliche
 * Virtuellen Ausgaenge im Miniserver HTTP 403 - die Steuerung war tot, ohne
 * jede Rueckmeldung. Der Angreifer sieht die Antwort nicht; er braucht sie
 * auch nicht.
 *
 * EINE Pruefung, VOR allen Handlern und VOR der Reiterwahl. Einen einzelnen
 * Handler kann man beim Erweitern vergessen, einen Wachposten am Eingang
 * nicht. Und es wird GEMELDET: ein Formular, das wortlos nichts tut, schickt
 * den Anwender auf die Suche nach einem Fehler, den es nicht gibt.
 */
$mw_fmt = mo_formtoken();
$mw_ist_post = (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST');
if ($mw_ist_post) {
    $mw_mit = (isset($_POST['fmt']) && is_string($_POST['fmt'])) ? $_POST['fmt'] : '';
    $mw_csrf_ok = ($mw_fmt !== '' && hash_equals($mw_fmt, $mw_mit));
    if (!$mw_csrf_ok) {
        $mw_fehler[] = ($mw_fmt === '') ? mo_t('TEXT.CSRF_KEIN_TOKEN') : mo_t('TEXT.CSRF');
        mo_log('Ein Formular ohne gueltiges Merkmal wurde abgewiesen.');
        /* $_POST leeren, damit danach KEIN Handler mehr anlaeuft, ohne dass
         * jeder einzelne davon wissen muesste. Den aktiven Reiter behalten -
         * der Anwender soll die Meldung dort sehen, wo er war. */
        $mw_behalten = isset($_POST['activetab']) ? $_POST['activetab'] : null;
        $_POST = array();
        if ($mw_behalten !== null) { $_POST['activetab'] = $mw_behalten; }
        $mw_ist_post = false;
    }
}

/* ================= 4. Reiterwahl ================= */
/*
 * Diese Liste, die Positivliste in $mw_muster und die id der Flaechen muessen
 * deckungsgleich bleiben - alle drei. Sie stehen ausgeschrieben, weil
 * hausstandard_pruefen.py sie als LITERAL sucht: eine Schleife macht das
 * Werkzeug blind und meldet dann "0 Reiter", was beim Ueberfliegen wie ein
 * Haken aussieht. Dass sie auseinanderlaufen KOENNEN, ist der Preis; dagegen
 * steht keine Hoffnung, sondern die Pruefzeile mo_reiterprobe() im Reiter
 * Test, die alle drei Stellen gegeneinander haelt.
 */
$mw_reiter = array(
    'tab-settings' => mo_t('REITER.EINSTELLUNGEN'),
    'tab-mqtt'     => mo_t('REITER.MQTT'),
    'tab-loxone'   => mo_t('REITER.LOXONE'),
    'tab-test'     => mo_t('REITER.TEST'),
    'tab-log'      => mo_t('REITER.LOG'),
);
$mw_muster = '/^tab-(settings|mqtt|loxone|test|log)$/';
$mw_wunsch = isset($_POST['activetab']) ? (string) $_POST['activetab']
    : (isset($_GET['form']) ? 'tab-' . (string) $_GET['form'] : '');
$mw_tab = preg_match($mw_muster, $mw_wunsch) ? $mw_wunsch : 'tab-settings';

/* ================= 5. Handler ================= */

/* --- Downloads zuerst: sie enden mit exit und muessen VOR lbheader() --- */

if ($mw_ist_post && isset($_POST['vorlage_vo'])) {
    list($mw_vname, $mw_vinhalt) = mo_vo_vorlage();
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $mw_vname . '"');
    echo $mw_vinhalt;
    exit;
}
if ($mw_ist_post && isset($_POST['vorlage'])) {
    list($mw_vname, $mw_vinhalt) = mo_vorlage(isset($_POST['vorlage_dev']) ? (int) $_POST['vorlage_dev'] : 1);
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $mw_vname . '"');
    echo $mw_vinhalt;
    exit;
}

/* Einstellungen sichern.
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin kaeme
 * trotzdem nicht an die Anlage; die Datei waere wertlos. Damit traegt sie ein
 * Geheimnis, und der Warnkasten am Knopf sagt das. */
if ($mw_ist_post && isset($_POST['mo_sichern'])) {
    $mw_js = mo_sicherung_erzeugen();
    if ($mw_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . mo_sicherung_name() . '"');
        echo $mw_js;
        exit;
    }
    $mw_fehler[] = mo_t('TEXT.SICH_SCHREIBFEHLER');
}

/* --- Einstellungen zurueckspielen ---
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze - eine Sicherung dieses
 * Plugins ist wenige Kilobyte gross; alles darueber wird gar nicht erst
 * gelesen. Und dann die Alles-oder-nichts-Pruefung: eine halb gueltige Datei
 * ueberschreibt GAR NICHTS, und ALLE Beanstandungen werden gemeinsam
 * gemeldet, nicht die erste. */
if ($mw_ist_post && isset($_POST['mo_zurueck'])) {
    if (!isset($_FILES['mo_sicherung']) || !is_array($_FILES['mo_sicherung'])
        || !isset($_FILES['mo_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['mo_sicherung']['tmp_name'])) {
        $mw_fehler[] = mo_t('TEXT.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['mo_sicherung']['size'] > 262144) {
        $mw_fehler[] = mo_t('TEXT.SICH_ZU_GROSS');
    } else {
        list($mw_neu, $mw_mangel, $mw_n) = mo_sicherung_lesen(
            (string) @file_get_contents($_FILES['mo_sicherung']['tmp_name']));
        if ($mw_neu === null) {
            $mw_fehler[] = mo_t('TEXT.SICH_ABGELEHNT');
            foreach ($mw_mangel as $mw_m) { $mw_fehler[] = $mw_m; }
        } else {
            /* Das Aktionstoken der Datei gilt - sonst waere die Sicherung
             * wertlos. Ist in der Datei keines, bleibt das bisherige stehen:
             * ein leeres Feld darf die Loxone-Adressen nicht abschneiden. */
            if (trim((string) $mw_neu['aktionstoken']) === '') {
                $mw_neu['aktionstoken'] = (string) $mw_cfg['aktionstoken'];
            }
            if (mo_config_speichern($mw_neu)) {
                $mw_cfg = mo_config();
                $mw_fmt = mo_formtoken();   // das Merkmal haengt am Token
                $mw_note = sprintf(mo_t('TEXT.SICH_UEBERNOMMEN'), $mw_n);
                mo_log('Einstellungen aus einer Sicherung zurueckgespielt: ' . $mw_n . ' Werte.');
            } else {
                $mw_fehler[] = mo_t('TEXT.SICH_SCHREIBFEHLER');
            }
        }
    }
    $mw_tab = 'tab-settings';
}

if ($mw_ist_post && isset($_POST['clearlog'])) {
    if (!is_dir(dirname($mw_logfile))) { @mkdir(dirname($mw_logfile), 0775, true); }   // A18
    @file_put_contents($mw_logfile, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Admin-Oberflaeche)\n");
    $mw_tab = 'tab-log';
}

if ($mw_ist_post && isset($_POST['bladereset'])) {
    $mw_dev = max(1, min(mo_max_maeher(), (int) (isset($_POST['bladereset']) ? $_POST['bladereset'] : 1)));
    if (mo_blade_reset($mw_dev)) {
        $mw_note = mo_t('TEXT.MESSER_QUITTIERT');
        $mw_cfg = mo_config();
    } else {
        $mw_fehler[] = mo_t('TEXT.SICH_SCHREIBFEHLER');
    }
    $mw_tab = 'tab-settings';
}

/* --- Neues Aktionstoken --- */
if ($mw_ist_post && isset($_POST['token_neu'])) {
    $mw_cfg['aktionstoken'] = mo_token_erzeugen();
    if (mo_config_speichern($mw_cfg)) {
        $mw_fmt = mo_formtoken();   // das Merkmal wechselt mit
        $mw_note = mo_t('TEXT.TOKEN_NEU_OK');
        mo_log('Ein neues Aktionstoken wurde erzeugt.');
    } else {
        $mw_fehler[] = sprintf(mo_t('TEXT.FEHLER_SCHREIBEN'), mw_e($mw_cfgfile));
    }
    $mw_tab = 'tab-loxone';
}

/* --- MQTT speichern (eigener Reiter, Hausstandard) --- */
if ($mw_ist_post && isset($_POST['mqtt_save'])) {
    $mw_neu = $mw_cfg;
    $mw_neu['mqtt_enabled'] = isset($_POST['mqtt_enabled']) ? 1 : 0;
    /* Gegen DIESELBE Positivliste wie die Sicherung - eine zweite Wahrheit
     * ueber zulaessige Werte gibt es nicht. */
    list($mw_wert, $mw_m) = mo_wert_pruefen('mqtt_topic',
        isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : 'maeher');
    if ($mw_m !== '') { $mw_fehler[] = $mw_m; } else { $mw_neu['mqtt_topic'] = $mw_wert; }
    if (!$mw_fehler) {
        if (mo_config_speichern($mw_neu)) { $mw_cfg = mo_config(); $mw_saved = true; }
        else { $mw_fehler[] = sprintf(mo_t('TEXT.FEHLER_SCHREIBEN'), mw_e($mw_cfgfile)); }
    }
    $mw_tab = 'tab-mqtt';
}

/* --- Einstellungen speichern --- */
if ($mw_ist_post && isset($_POST['save'])) {
    /* Aus dem Bestand uebernehmen, was dieses Formular nicht mitschickt.
     * BIS 1.0.8 FEHLTE DAS FUER aktionstoken: jedes Speichern warf das Token
     * still weg, der naechste Seitenaufruf erzeugte ein NEUES - und alle
     * Loxone-Adressen liefen auf 403. Deshalb wird hier NICHT von Grund auf
     * neu gebaut, sondern der Bestand fortgeschrieben. */
    $mw_neu = $mw_cfg;

    /* --- Maeher. Der Index steht AUSGESCHRIEBEN im Feldnamen (m_ip[0] statt
     * m_ip[]): eine nicht angehakte Loeschbox sendet gar nichts, und mit
     * fortlaufenden Klammern rutschten danach alle folgenden Zeilen um eine
     * Position - jeder Maeher bekaeme die Zugangsdaten seines Nachbarn.
     * Geloescht wird ueber den Haken, NIE durch Leeren eines Feldes. */
    $mw_ips  = isset($_POST['m_ip'])   && is_array($_POST['m_ip'])   ? $_POST['m_ip']   : array();
    $mw_nam  = isset($_POST['m_name']) && is_array($_POST['m_name']) ? $_POST['m_name'] : array();
    $mw_usr  = isset($_POST['m_user']) && is_array($_POST['m_user']) ? $_POST['m_user'] : array();
    $mw_pwd  = isset($_POST['m_pass']) && is_array($_POST['m_pass']) ? $_POST['m_pass'] : array();
    $mw_del  = isset($_POST['m_del'])  && is_array($_POST['m_del'])  ? $_POST['m_del']  : array();
    /* Messerwechsel je Maeher (1.1.4). Ein LEERES Feld heisst "die Vorgabe
     * gilt" und wird nicht als 0 uebernommen - sonst waere aus "erbt den
     * Nullpunkt" stillschweigend "Nullpunkt 0" geworden. */
    $mw_bhs  = isset($_POST['m_bhours']) && is_array($_POST['m_bhours']) ? $_POST['m_bhours'] : array();
    $mw_bbs  = isset($_POST['m_bbase'])  && is_array($_POST['m_bbase'])  ? $_POST['m_bbase']  : array();
    $mw_alt  = is_array($mw_cfg['mowers']) ? array_values($mw_cfg['mowers']) : array();
    $mw_liste = array();
    for ($mw_i = 0; $mw_i < mo_max_maeher(); $mw_i++) {
        if (!empty($mw_del[$mw_i])) { continue; }
        $mw_ip = trim((string) (isset($mw_ips[$mw_i]) ? $mw_ips[$mw_i] : ''));
        if ($mw_ip === '') {
            /* A19 (04.09.2026): der Hilfetext unter der Tabelle sagt
             * woertlich "Geloescht wird ueber den Haken, nie durch Leeren
             * eines Feldes". Fuer den Namen stimmte das, fuer die ADRESSE
             * nicht: ein versehentlich geleertes Adressfeld warf die Zeile
             * samt Benutzer und Kennwort weg, und die Seite meldete
             * "Konfiguration gespeichert". Eine Zeile, die es vorher gab,
             * wird jetzt beanstandet; eine leer gebliebene Zeile bleibt
             * stillschweigend weg - die gab es ja nie. */
            if (isset($mw_alt[$mw_i]) && is_array($mw_alt[$mw_i])) {
                $mw_fehler[] = sprintf(mo_t('TEXT.MAEHER_ADRESSE_LEER'), $mw_i + 1);
            }
            continue;
        }
        $mw_pw = (string) (isset($mw_pwd[$mw_i]) ? $mw_pwd[$mw_i] : '');
        // Leeres Passwortfeld = bisheriges Passwort behalten (es wird nie angezeigt)
        if ($mw_pw === '' && isset($mw_alt[$mw_i]['pass'])) { $mw_pw = (string) $mw_alt[$mw_i]['pass']; }
        $mw_zeile = array(
            'name' => (string) (isset($mw_nam[$mw_i]) ? $mw_nam[$mw_i] : ''),
            'ip'   => $mw_ip,
            'user' => (string) (isset($mw_usr[$mw_i]) ? $mw_usr[$mw_i] : ''),
            'pass' => $mw_pw);
        $mw_bh = trim((string) (isset($mw_bhs[$mw_i]) ? $mw_bhs[$mw_i] : ''));
        $mw_bb = trim((string) (isset($mw_bbs[$mw_i]) ? $mw_bbs[$mw_i] : ''));
        if ($mw_bh !== '') { $mw_zeile['blade_hours'] = $mw_bh; }
        if ($mw_bb !== '') { $mw_zeile['blade_base'] = $mw_bb; }
        $mw_liste[] = $mw_zeile;
    }
    /* Alle Beanstandungen sammeln, nicht die erste melden - der Benutzer
     * korrigiert sonst einen Fehler nach dem anderen. */
    list($mw_wert, $mw_m) = mo_wert_pruefen('mowers', $mw_liste);
    if ($mw_m !== '') { $mw_fehler[] = $mw_m; } else { $mw_neu['mowers'] = $mw_wert; }

    foreach (array('cache_sec', 'blade_hours', 'blade_base') as $mw_k) {
        list($mw_wert, $mw_m) = mo_wert_pruefen($mw_k, isset($_POST[$mw_k]) ? $_POST[$mw_k] : '');
        if ($mw_m !== '') { $mw_fehler[] = $mw_m; } else { $mw_neu[$mw_k] = $mw_wert; }
    }

    $mw_neu['stat_ein'] = isset($_POST['stat_ein']) ? 1 : 0;
    $mw_neu['notify'] = array(
        'audio'  => isset($_POST['notify_audio']) ? 1 : 0,
        'push'   => isset($_POST['notify_push']) ? 1 : 0,
        'fehler' => isset($_POST['n_fehler']) ? 1 : 0,
        'fertig' => isset($_POST['n_fertig']) ? 1 : 0,
        'messer' => isset($_POST['n_messer']) ? 1 : 0,
        'akku'   => isset($_POST['n_akku']) ? 1 : 0,
    );
    list($mw_wert, $mw_m) = mo_wert_pruefen('tts', array(
        'mode'     => (string) (isset($_POST['tts_mode']) ? $_POST['tts_mode'] : 'musicserver'),
        'ip'       => (string) (isset($_POST['tts_ip']) ? $_POST['tts_ip'] : ''),
        'port'     => (string) (isset($_POST['tts_port']) ? $_POST['tts_port'] : '7091'),
        'zones'    => (string) (isset($_POST['tts_zones']) ? $_POST['tts_zones'] : '1'),
        'volume'   => (string) (isset($_POST['tts_volume']) ? $_POST['tts_volume'] : '8'),
        'lang'     => (string) (isset($_POST['tts_lang']) ? $_POST['tts_lang'] : 'de'),
        'template' => (string) (isset($_POST['tts_template']) ? $_POST['tts_template'] : ''),
    ));
    if ($mw_m !== '') { $mw_fehler[] = $mw_m; } else { $mw_neu['tts'] = $mw_wert; }

    if (!$mw_fehler) {
        if (mo_config_speichern($mw_neu)) {
            $mw_cfg = mo_config();
            $mw_saved = true;
        } else {
            $mw_fehler[] = sprintf(mo_t('TEXT.FEHLER_SCHREIBEN'), mw_e($mw_cfgfile));
        }
    }
    $mw_tab = 'tab-settings';
}

/* ================= Anzeigedaten ================= */

$mw_notify = is_array($mw_cfg['notify']) ? $mw_cfg['notify'] : array();
$mw_notify += array('audio' => 0, 'push' => 0, 'fehler' => 1, 'fertig' => 1, 'messer' => 1, 'akku' => 0);
$mw_tts = is_array($mw_cfg['tts']) ? $mw_cfg['tts'] : array();
$mw_tts += array('mode' => 'musicserver', 'ip' => '', 'port' => 7091, 'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '');
$mw_list = mo_mowers();
$mw_states = array();
foreach ($mw_list as $mw_k => $mw_r) { $mw_states[$mw_k] = mo_state($mw_k); }
$mw_loglines = array();
if (is_file($mw_logfile)) {
    // mo_log_tail() liest nur das Ende der Datei, nicht die ganze - siehe
    // die Begruendung mit den Messwerten in mower_lib.php.
    $mw_loglines = array_reverse(mo_log_tail($mw_logfile, 300));
}
$mw_lauf   = mo_lauf_lesen();
$mw_stat   = mo_stat_lesen();
$mw_fehlerliste = mo_fehler_liste();
$mw_felder = mo_felder();
$mw_themen = mo_themen();

/* ================= 6. Seitenkopf ================= */

$mw_frame = class_exists('LBWeb', false);
if ($mw_frame) { LBWeb::lbheader('Rasenm&auml;her (Robonect)', 'https://wiki.loxberry.de/', 'help.html'); }
$mw_host = mw_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '<loxberry-ip>');

/* ================= 7. HTML ================= */
?>
<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3, .sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=password], .sm-wrap input[type=number], .sm-wrap select, .sm-wrap textarea {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 150px; }
.sm-row > div > label:not([style]) { min-height: 2.6em; display: flex; align-items: flex-end; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-warn { background: #fff8e1; border: 1px solid #ffe082; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
/* Hinweis und Warnung. Beide gehoeren dazu, und sie heissen SO.
   In 1.0.13 fehlte .sm-warnung als EINZIGE Klasse - ausgerechnet an dem
   Satz, dass die Sicherungsdatei ein Geheimnis traegt. Er stand als nackter
   Fliesstext da und war die unauffaelligste Zeile der Seite. */
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; text-decoration: none !important; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
/* Jede Tabelle mit mehr als sechs Spalten oder mit Eingabefeldern kommt in
   einen Rollbehaelter. Ohne ihn steht die letzte Spalte auf einem schmalen
   Bildschirm ausserhalb und ist UNERREICHBAR, nicht bloss unbequem. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. Die
   Hover-Farben unten sind kein Feinschliff, sondern Pflicht: fehlen sie,
   kommt der Hover-Zustand vom Rahmen und ist unlesbar. In 1.0.13 fehlten
   beide. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. Die Raute im SVG wird
   als %23 geschrieben: eine rohe Raute beendet in einer CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
.sm-pruef td:first-child { width: 42px; text-align: center; font-size: 1.1em; }
</style>
<div class="sm-wrap">

<?php if ($mw_saved) { ?><div class="sm-alert sm-ok"><b><?php echo mw_e(mo_t('TEXT.KONFIGURATION_GESPEICHERT')); ?></b> <?php echo mw_e(mo_t('TEXT.ZUGANGSDATEN_MIT_DATEIRECHTEN_0600')); ?></div><?php } ?>
<?php if ($mw_note !== '') { ?><div class="sm-alert sm-ok"><?php echo $mw_note; ?></div><?php } ?>
<?php if ($mw_fehler) { ?>
<div class="sm-alert sm-err"><b><?php echo mw_e(mo_t('TEXT.FEHLER_4')); ?></b>
<ul style="margin:6px 0 0 18px;padding:0;">
<?php foreach ($mw_fehler as $mw_f) { ?><li><?php echo $mw_f; ?></li><?php } ?>
</ul></div>
<?php } ?>

<?php if (!$mw_list) { ?>
<div class="sm-alert sm-info"><b><?php echo mw_e(mo_t('TEXT.NOCH_KEIN_MHER_EINGERICHTET')); ?></b> <?php echo mw_e(mo_t('TEXT.BITTE_UNTEN_ADRESSE_BENUTZER_UND_P')); ?></div>
<?php } ?>

<?php /* Statuskacheln statt Fliesstext - die Werte liegen alle schon vor. */ ?>
<?php foreach ($mw_states as $mw_k => $mw_s) { ?>
<h3 class="sm-h3"><?php echo mw_e($mw_s['name']); ?></h3>
<?php if ($mw_s['ok']) { ?>
<div class="sm-kacheln">
  <div class="sm-kachel"><b><?php echo mw_e($mw_s['text']); ?></b><?php echo mw_e(mo_t('TEXT.K_ZUSTAND')); ?></div>
  <div class="sm-kachel"><b><?php echo (int) $mw_s['batterie']; ?>&nbsp;%</b><?php echo mw_e(mo_t('TEXT.K_AKKU')); ?></div>
  <div class="sm-kachel"><b><?php echo mw_e($mw_s['modus_text']); ?></b><?php echo mw_e(mo_t('TEXT.K_MODUS')); ?></div>
  <div class="sm-kachel"><b><?php echo (int) $mw_s['stunden']; ?>&nbsp;h</b><?php echo mw_e(mo_t('TEXT.K_STUNDEN')); ?></div>
  <div class="sm-kachel"><b><?php echo $mw_s['messer_rest'] >= 0 ? (int) $mw_s['messer_rest'] . '&nbsp;h' : '&ndash;'; ?></b><?php echo mw_e(mo_t('TEXT.K_MESSER')); ?></div>
  <div class="sm-kachel"><b><?php echo mw_e($mw_s['temperatur']); ?>&nbsp;&deg;C</b><?php echo mw_e(mo_t('TEXT.K_TEMP')); ?></div>
  <div class="sm-kachel"><b><?php echo (int) $mw_s['wlan']; ?>&nbsp;dBm</b><?php echo mw_e(mo_t('TEXT.K_WLAN')); ?></div>
</div>
<?php if ($mw_s['fehler']) { ?>
<div class="sm-warnung"><b><?php echo sprintf(mw_e(mo_t('TEXT.K_FEHLER')), (int) $mw_s['fehler']); ?></b> <?php echo mw_e($mw_s['fehlertext']); ?></div>
<?php } ?>
<?php if ($mw_s['messer_warn']) { ?>
<div class="sm-warnung"><?php echo mw_e(mo_t('TEXT.K_MESSER_FAELLIG')); ?></div>
<?php } ?>
<?php } else { ?>
<div class="sm-warnung"><b><?php echo mw_e(mo_t('TEXT.KEINE_VERBINDUNG')); ?></b>
<?php echo mw_e($mw_s['grundtext'] !== '' ? $mw_s['grundtext'] : mo_t('TEXT.ADRESSE_UND_ZUGANGSDATEN_PRFEN_ROB')); ?></div>
<?php } ?>
<?php } ?>

<div class="sm-tabs">
	<a class="sm-tab<?php echo $mw_tab === 'tab-settings' ? ' sm-active' : ''; ?>" data-ziel="tab-settings"
	   href="index.php?form=settings"><?php echo mw_e($mw_reiter['tab-settings']); ?></a>
	<a class="sm-tab<?php echo $mw_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>" data-ziel="tab-mqtt"
	   href="index.php?form=mqtt"><?php echo mw_e($mw_reiter['tab-mqtt']); ?></a>
	<a class="sm-tab<?php echo $mw_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" data-ziel="tab-loxone"
	   href="index.php?form=loxone"><?php echo mw_e($mw_reiter['tab-loxone']); ?></a>
	<a class="sm-tab<?php echo $mw_tab === 'tab-test' ? ' sm-active' : ''; ?>" data-ziel="tab-test"
	   href="index.php?form=test"><?php echo mw_e($mw_reiter['tab-test']); ?></a>
	<a class="sm-tab<?php echo $mw_tab === 'tab-log' ? ' sm-active' : ''; ?>" data-ziel="tab-log"
	   href="index.php?form=log"><?php echo mw_e($mw_reiter['tab-log']); ?></a>
</div>

<!-- ================= Einstellungen ================= -->
<div class="sm-seite<?php echo $mw_tab === 'tab-settings' ? ' sm-active' : ''; ?>" id="tab-settings">
<form action="index.php" method="post" autocomplete="off">
<?php echo mo_fmt_feld(); ?>
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo sprintf(mw_e(mo_t('TEXT.H_MAEHER')), mo_max_maeher()); ?></h2>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:36px;">Nr.</th><th style="width:24%;"><?php echo mw_e(mo_t('TEXT.NAME_FREI')); ?></th><th><?php echo mw_e(mo_t('TEXT.ADRESSE')); ?></th><th style="width:18%;"><?php echo mw_e(mo_t('TEXT.BENUTZER')); ?></th><th style="width:20%;"><?php echo mw_e(mo_t('TEXT.PASSWORT')); ?></th><th style="width:110px;"><?php echo mw_e(mo_t('TEXT.MESSER_IV_KURZ')); ?></th><th style="width:110px;"><?php echo mw_e(mo_t('TEXT.MESSER_NP_KURZ')); ?></th><th style="width:70px;"><?php echo mw_e(mo_t('TEXT.LOESCHEN')); ?></th></tr>
<?php
/* Vorhandene Zeilen plus EINE leere zum Anlegen - hoechstens mo_max_maeher().
   Der Index steht ausgeschrieben, siehe die Begruendung am Speicher-Handler. */
$mw_zeilen = is_array($mw_cfg['mowers']) ? array_values($mw_cfg['mowers']) : array();
$mw_anz = min(mo_max_maeher(), count($mw_zeilen) + 1);
for ($mw_i = 0; $mw_i < $mw_anz; $mw_i++) {
    $mw_r = isset($mw_zeilen[$mw_i]) ? (array) $mw_zeilen[$mw_i] : array();
    $mw_r += array('name' => '', 'ip' => '', 'user' => '', 'pass' => '',
                   'blade_hours' => '', 'blade_base' => '');
    $mw_leer = ($mw_r['ip'] === '');
?>
<tr>
<td><?php echo $mw_i + 1; ?></td>
<td><input data-role="none" type="text" name="m_name[<?php echo (int) $mw_i; ?>]" value="<?php echo mw_e($mw_r['name']); ?>" placeholder="<?php echo $mw_leer ? mw_e(mo_t('TEXT.PH_NAME')) : ''; ?>"></td>
<td><input data-role="none" type="text" name="m_ip[<?php echo (int) $mw_i; ?>]" value="<?php echo mw_e($mw_r['ip']); ?>" placeholder="<?php echo $mw_leer ? mw_e(mo_t('TEXT.PH_IP')) : ''; ?>"></td>
<td><input data-role="none" type="text" name="m_user[<?php echo (int) $mw_i; ?>]" value="<?php echo mw_e($mw_r['user']); ?>" placeholder="admin"></td>
<td><input data-role="none" type="password" name="m_pass[<?php echo (int) $mw_i; ?>]" value="" placeholder="<?php echo $mw_r['pass'] !== '' ? mw_e(mo_t('TEXT.PH_GESPEICHERT')) : ''; ?>" autocomplete="new-password"></td>
<td><input data-role="none" type="number" name="m_bhours[<?php echo (int) $mw_i; ?>]" value="<?php echo mw_e((string) $mw_r['blade_hours']); ?>" min="1" max="2000" placeholder="<?php echo (int) $mw_cfg['blade_hours']; ?>"></td>
<td><input data-role="none" type="number" name="m_bbase[<?php echo (int) $mw_i; ?>]" value="<?php echo mw_e((string) $mw_r['blade_base']); ?>" min="0" max="100000" placeholder="<?php echo (int) $mw_cfg['blade_base']; ?>"></td>
<td style="text-align:center;"><?php if (!$mw_leer) { ?><input data-role="none" type="checkbox" name="m_del[<?php echo (int) $mw_i; ?>]" value="1" title="<?php echo mw_e(mo_t('TEXT.LOESCHEN_HILFE')); ?>"><?php } ?></td>
</tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?php echo mo_t('TEXT.LOESCHEN_HILFE'); ?></div>
<div class="sm-hilfe"><?php echo mo_t('TEXT.MESSER_JE_MAEHER'); ?></div>
<div class="sm-hinweis"><?php echo mo_t('TEXT.PASSWORT_HINWEIS'); ?></div>

<div class="sm-row">
    <div>
        <label><?php echo mw_e(mo_t('TEXT.STATUS_CACHE_SEKUNDEN')); ?></label>
        <input data-role="none" type="number" name="cache_sec" value="<?php echo (int) $mw_cfg['cache_sec']; ?>" min="5" max="300">
        <div class="sm-small"><?php echo mw_e(mo_t('TEXT.EMPFEHLUNG_20_EINE_LOXONE_ABFRAGE_')); ?></div>
    </div>
    <div>
        <label><?php echo mw_e(mo_t('TEXT.MESSERWECHSEL_INTERVALL_BETRIEBSST')); ?></label>
        <input data-role="none" type="number" name="blade_hours" value="<?php echo (int) $mw_cfg['blade_hours']; ?>" min="1" max="2000">
        <div class="sm-small"><?php echo mw_e(mo_t('TEXT.HERSTELLERANGABE_OFT_150250_H')); ?>
             &mdash; <?php echo mw_e(mo_t('TEXT.MESSER_VORGABE')); ?></div>
    </div>
    <div>
        <label><?php echo mw_e(mo_t('TEXT.NULLPUNKT_STUNDEN_BEIM_LETZTEN_WEC')); ?></label>
        <input data-role="none" type="number" name="blade_base" value="<?php echo (int) $mw_cfg['blade_base']; ?>" min="0" max="100000">
        <div class="sm-small"><?php echo mo_t('TEXT.WIRD_BEIM_QUITTIEREN_AUTOMATISCH_G'); ?> <span class="sm-mono">?cmd=blade_reset</span>).
             &mdash; <?php echo mw_e(mo_t('TEXT.MESSER_VORGABE')); ?></div>
    </div>
</div>

<h2><?php echo mw_e(mo_t('TEXT.MELDUNGEN')); ?></h2>
<div style="margin-bottom:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="notify_audio"<?php echo !empty($mw_notify['audio']) ? ' checked' : ''; ?>> <?php echo mw_e(mo_t('TEXT.AUDIOAUSGABE_AKTIV')); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_push"<?php echo !empty($mw_notify['push']) ? ' checked' : ''; ?>> <?php echo mw_e(mo_t('TEXT.PUSH_NACHRICHT_AKTIV')); ?>
    </label>
    <div class="sm-small"><?php echo mo_t('TEXT.DIE_ANSAGE_SPRICHT_DAS_PLUGIN_SELB'); ?> <span class="sm-mono">ANN=1</span>.</div>
</div>
<div>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="n_fehler"<?php echo !empty($mw_notify['fehler']) ? ' checked' : ''; ?>> <?php echo mw_e(mo_t('TEXT.STRUNG_SCHLEIFENSIGNAL_VERLOREN')); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="n_fertig"<?php echo !empty($mw_notify['fertig']) ? ' checked' : ''; ?>> <?php echo mw_e(mo_t('TEXT.MHEN_BEENDET')); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="n_messer"<?php echo !empty($mw_notify['messer']) ? ' checked' : ''; ?>> <?php echo mw_e(mo_t('TEXT.MESSERWECHSEL_FLLIG')); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="n_akku"<?php echo !empty($mw_notify['akku']) ? ' checked' : ''; ?>> <?php echo mw_e(mo_t('TEXT.AKKU_UNTER_20_AUERHALB_DER_STATION')); ?>
    </label>
</div>

<h2><?php echo mw_e(mo_t('TEXT.H_STATISTIK')); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="stat_ein"<?php echo !empty($mw_cfg['stat_ein']) ? ' checked' : ''; ?>> <?php echo mw_e(mo_t('TEXT.STAT_EIN')); ?>
</label>
<div class="sm-hilfe"><?php echo mo_t('TEXT.STAT_HILFE'); ?></div>
<?php if (!empty($mw_cfg['stat_ein'])) { ?>
<div class="sm-kacheln">
  <div class="sm-kachel"><b><?php echo (int) $mw_stat['eins_heute']; ?></b><?php echo mw_e(mo_t('TEXT.K_EINS_HEUTE')); ?></div>
  <div class="sm-kachel"><b><?php echo (int) $mw_stat['min_heute']; ?>&nbsp;min</b><?php echo mw_e(mo_t('TEXT.K_MIN_HEUTE')); ?></div>
  <div class="sm-kachel"><b><?php echo (int) $mw_stat['eins_woche']; ?></b><?php echo mw_e(mo_t('TEXT.K_EINS_WOCHE')); ?></div>
  <div class="sm-kachel"><b><?php echo (int) $mw_stat['min_woche']; ?>&nbsp;min</b><?php echo mw_e(mo_t('TEXT.K_MIN_WOCHE')); ?></div>
</div>
<?php } ?>

<h2><?php echo mw_e(mo_t('TEXT.SPRACHAUSGABE')); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo mw_e(mo_t('TEXT.AUDIO_AUSGABE')); ?></label>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="mwTtsMode()">
            <option value="musicserver"<?php echo $mw_tts['mode'] === 'musicserver' ? ' selected' : ''; ?>><?php echo mw_e(mo_t('TEXT.LOXONE_MUSIC_SERVER_KLASSISCH')); ?></option>
            <option value="ms4h"<?php echo $mw_tts['mode'] === 'ms4h' ? ' selected' : ''; ?>><?php echo mw_e(mo_t('TEXT.AUDIOSERVER4HOME_MUSICSERVER4HOME')); ?></option>
            <option value="audioserver"<?php echo $mw_tts['mode'] === 'audioserver' ? ' selected' : ''; ?>><?php echo mw_e(mo_t('TEXT.ORIGINAL_LOXONE_AUDIOSERVER_VIA_LO')); ?></option>
            <option value="custom"<?php echo $mw_tts['mode'] === 'custom' ? ' selected' : ''; ?>><?php echo mw_e(mo_t('TEXT.EIGENE_URL_VORLAGE')); ?></option>
        </select>
    </div>
    <div>
        <label><?php echo mw_e(mo_t('TEXT.IP_DES_AUDIO_SERVERS')); ?></label>
        <input data-role="none" type="text" name="tts_ip" value="<?php echo mw_e($mw_tts['ip']); ?>" placeholder="<?php echo mw_e(mo_t('TEXT.PH_TTS_IP')); ?>">
    </div>
    <div>
        <label><?php echo mw_e(mo_t('TEXT.PORT')); ?></label>
        <input data-role="none" type="number" name="tts_port" value="<?php echo (int) $mw_tts['port']; ?>" min="1" max="65535">
    </div>
</div>
<div class="sm-row">
    <div>
        <label><?php echo mw_e(mo_t('TEXT.ZONEN')); ?></label>
        <input data-role="none" type="text" name="tts_zones" value="<?php echo mw_e($mw_tts['zones']); ?>" placeholder="2,4,6">
        <div class="sm-small"><?php echo mo_t('TEXT.ZONEN_HILFE'); ?></div>
    </div>
    <div>
        <label><?php echo mw_e(mo_t('TEXT.LAUTSTRKE')); ?></label>
        <input data-role="none" type="number" name="tts_volume" value="<?php echo (int) $mw_tts['volume']; ?>" min="1" max="100">
    </div>
    <div>
        <label><?php echo mw_e(mo_t('TEXT.SPRACHE')); ?></label>
        <input data-role="none" type="text" name="tts_lang" value="<?php echo mw_e($mw_tts['lang']); ?>" maxlength="2">
    </div>
</div>
<div id="tts_template_row">
    <label><?php echo mw_e(mo_t('TEXT.URL_VORLAGE_FR_AUDIOSERVER4HOME_MS')); ?></label>
    <textarea data-role="none" name="tts_template" id="tts_template" rows="2" placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?php echo mw_e($mw_tts['template']); ?></textarea>
    <div class="sm-small"><?php echo mw_e(mo_t('TEXT.PLATZHALTER')); ?> <span class="sm-mono"><?php echo mw_e(mo_t('TEXT.IP_PORT_ZONES_VOL_LANG_TEXT')); ?></span><?php echo mw_e(mo_t('TEXT.LEER_STANDARD_VORLAGE')); ?></div>
</div>
<div id="tts_audioserver_hint" class="sm-alert sm-info" style="display:none;">
    <?php echo mo_t('TEXT.DER_ORIGINALE_LOXONE_AUDIOSERVER_B'); ?> <span class="sm-mono">ANN=1</span>.
</div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo mw_e(mo_t('LEGENDE.LESEN')); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo mw_e(mo_t('LEGENDE.AKTION')); ?></span>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo mw_e(mo_t('TEXT.SPEICHERN')); ?></button>
</div>
</form>

<div class="sm-knopfreihe">
<form action="index.php" method="post">
    <?php echo mo_fmt_feld(); ?>
    <?php $mw_mlist = mo_mowers(); if (count($mw_mlist) > 1) { ?>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:10px;">
        <?php echo mw_e(mo_t('TEXT.QUITTIEREN_FUER')); ?>
        <select data-role="none" name="bladereset">
        <?php foreach ($mw_mlist as $mw_mn => $mw_mm) { ?>
            <option value="<?php echo (int) $mw_mn; ?>"><?php echo mw_e($mw_mm['name']); ?></option>
        <?php } ?>
        </select>
    </label>
    <?php } else { ?>
    <input data-role="none" type="hidden" name="bladereset" value="1">
    <?php } ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo mw_e(mo_t('TEXT.MESSERWECHSEL_QUITTIEREN')); ?></button>
</form>
</div>

<h2><?php echo mw_e(mo_t('TEXT.H_SICHERUNG')); ?></h2>
<div class="sm-hinweis"><?php echo mo_t('TEXT.SICH_ERKLAERUNG'); ?></div>
<div class="sm-warnung"><?php echo mo_t('TEXT.SICH_WARNUNG'); ?></div>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt.
       accept=".json" ist ein Hinweis fuer den Dateidialog und KEINE Pruefung -
       der Browser haelt sich nicht immer daran, und ein Upload kommt ohnehin
       auch ohne Browser. Geprueft wird serverseitig. -->
  <form action="index.php" method="post">
    <?php echo mo_fmt_feld(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="mo_sichern" value="1"><?php echo mw_e(mo_t('TEXT.K_SICHERN')); ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <?php echo mo_fmt_feld(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="mo_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="mo_zurueck" value="1"><?php echo mw_e(mo_t('TEXT.K_ZURUECK')); ?></button>
  </form>
</div>
</div>

<!-- ================= MQTT ================= -->
<div class="sm-seite<?php echo $mw_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>" id="tab-mqtt">
<form action="index.php" method="post">
<?php echo mo_fmt_feld(); ?>
<input data-role="none" type="hidden" name="mqtt_save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<h2><?php echo mw_e(mo_t('TEXT.MQTT_OPTIONAL')); ?></h2>
<?php if (mo_mqtt_gateway_autostart() === false) { ?>
<div class="sm-warnung"><b>MQTT:</b> <?php echo mo_t('TEXT.W_AUTOSTART'); ?></div>
<?php } ?>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="mqtt_enabled"<?php echo !empty($mw_cfg['mqtt_enabled']) ? ' checked' : ''; ?>> <?php echo mw_e(mo_t('TEXT.ZUSTAND_PER_MQTT_VERFFENTLICHEN')); ?>
</label>
<div class="sm-feld" style="margin-top:6px;max-width:520px;">
    <label><?php echo mw_e(mo_t('TEXT.TOPIC_PRFIX')); ?></label>
    <input data-role="none" type="text" name="mqtt_topic" value="<?php echo mw_e($mw_cfg['mqtt_topic']); ?>" placeholder="maeher">
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo mw_e(mo_t('LEGENDE.AKTION')); ?></span>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo mw_e(mo_t('TEXT.SPEICHERN')); ?></button>
</div>
</form>

<?php
/* Das Abo - und WAS hier steht, haengt an der Fassung des Gateways.
 * Bis 1.0.13 fehlte dieser Schritt ganz: weder im Reiter MQTT noch im Reiter
 * Loxone stand, was einzutragen ist. Unter Gateway V1 kam damit am Miniserver
 * nichts an, und die Oberflaeche gab keinen Hinweis darauf, woran es liegt.
 * Ein pauschaler Satz waere fuer eine der beiden Fassungen falsch - deshalb
 * mo_abo_text(). */
$mw_gw = mo_mqtt_gateway_info();
$mw_gwf = ($mw_gw === null) ? 0 : (int) $mw_gw['fassung'];
$mw_praefix = mo_mqtt_praefix($mw_cfg['mqtt_topic']);
?>
<h2><?php echo mw_e(mo_t('TEXT.H_ABO')); ?></h2>
<div class="<?php echo $mw_gwf >= 2 ? 'sm-hinweis' : 'sm-warnung'; ?>">
<b><?php echo mw_e(mo_t('TEXT.ABO_TITEL')); ?></b><br>
<span class="sm-mono"><?php echo mw_e($mw_praefix); ?>/#</span><br>
<?php echo mo_abo_text(); ?>
</div>

<h2><?php echo mw_e(mo_t('TEXT.H_THEMEN')); ?></h2>
<div class="sm-hilfe"><?php echo mo_t('TEXT.THEMEN_HILFE'); ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:38%;"><?php echo mw_e(mo_t('TEXT.THEMA')); ?></th><th><?php echo mw_e(mo_t('TEXT.BEDEUTUNG')); ?></th></tr>
<?php
/* Die Tabelle entsteht aus DERSELBEN Quelle wie der Sendecode - die
 * Pruefzeile im Reiter Test haelt beide gegeneinander. */
foreach ($mw_themen['maeher'] as $mw_th) {
    /* A9 (04.09.2026, gemessen): hier stand strtoupper($mw_th). Das trifft
     * bei vier Themen daneben - 'batterie' ergibt BATTERIE, das Feld heisst
     * BATT; ebenso messer_rest, messer_warn, temperatur. Vier von 55 Zeilen
     * standen ohne Bedeutung da, und zwar stumm: die Zeile erschien, nur die
     * Spalte war leer. Die Zuordnung steht jetzt ausgeschrieben in
     * mo_thema_feld(), und ein unbekanntes Thema wird SICHTBAR gemeldet
     * statt zu einer leeren Zelle zu werden. */
    $mw_karte = mo_thema_feld();
    $mw_gross = isset($mw_karte[$mw_th]) ? $mw_karte[$mw_th] : strtoupper($mw_th);
    if ($mw_th === 'status') {
        $mw_bed = mo_t('TEXT.TH_STATUS');
    } elseif ($mw_gross !== '' && isset($mw_felder[$mw_gross])) {
        $mw_bed = mo_feld_text($mw_gross);
    } else {
        $mw_bed = sprintf(mo_t('TEXT.TH_OHNE'), $mw_th);
    }
?>
<tr><td><span class="sm-mono"><?php echo mw_e($mw_praefix); ?>/<?php echo mw_e($mw_th); ?></span></td><td><?php echo mw_e($mw_bed); ?></td></tr>
<?php } ?>
<?php foreach ($mw_themen['anlage'] as $mw_th) { ?>
<tr><td><span class="sm-mono"><?php echo mw_e($mw_praefix); ?>/<?php echo mw_e($mw_th); ?></span></td><td><?php echo mw_e(mo_t('TEXT.TH_' . strtoupper(str_replace('status/', '', $mw_th)))); ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?php echo sprintf(mo_t('TEXT.THEMEN_MEHRERE'), mo_max_maeher()); ?></div>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-seite<?php echo $mw_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" id="tab-loxone">
<h2><?php echo mw_e(mo_t('TEXT.EINBINDUNG_IN_LOXONE_SCHRITT_FR_SC')); ?></h2>
<p><?php echo mo_t('TEXT.DER_MINISERVER_FRAGT'); ?> <b><?php echo mw_e(mo_t('TEXT.EINE')); ?></b> <?php echo mo_t('TEXT.ADRESSE_OHNE_ZUGANGSDATEN_AB_UND_B'); ?></p>

<div class="sm-step"><b><?php echo mw_e(mo_t('TEXT.SCHRITT_1_VIRTUELLER_HTTP_EINGANG_')); ?></b> <?php echo mw_e(mo_t('TEXT.ABFRAGE_ALLE_30_S')); ?>
<table class="sm-tbl">
<tr><th><?php echo mw_e(mo_t('TEXT.EIGENSCHAFT')); ?></th><th><?php echo mw_e(mo_t('TEXT.WERT')); ?></th></tr>
<tr><td>URL</td><td><span class="sm-mono">http://<?php echo $mw_host; ?>/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php</span> <?php echo mw_e(mo_t('TEXT.MHER_2')); ?> <span class="sm-mono">?dev=2</span>)</td></tr>
<tr><td><?php echo mw_e(mo_t('TEXT.ABFRAGEZYKLUS')); ?></td><td><?php echo mw_e(mo_t('TEXT.30_SEKUNDEN')); ?></td></tr>
</table>
<span class="sm-small"><?php echo mo_t('TEXT.DER_BISHERIGE_EINGANG_MIT'); ?> <span class="sm-mono">?user=...&amp;pass=...</span> <?php echo mw_e(mo_t('TEXT.KANN_DANACH_GELSCHT_WERDEN')); ?></span>
</div>

<div class="sm-step"><b><?php echo mw_e(mo_t('TEXT.SCHRITT_2_ABO')); ?></b><br>
<?php echo mo_t('TEXT.SCHRITT_2_ABO_TEXT'); ?>
<div class="<?php echo $mw_gwf >= 2 ? 'sm-hinweis' : 'sm-warnung'; ?>">
<span class="sm-mono"><?php echo mw_e($mw_praefix); ?>/#</span><br>
<?php echo mo_abo_text(); ?>
</div>
</div>

<div class="sm-step"><b><?php echo mw_e(mo_t('TEXT.SCHRITT_3_BEFEHLSERKENNUNGEN')); ?></b>
<div class="sm-hilfe"><?php echo mo_t('TEXT.CHECK_HILFE'); ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:34%;"><?php echo mw_e(mo_t('TEXT.BEFEHLSERKENNUNG')); ?></th><th><?php echo mw_e(mo_t('TEXT.BEDEUTUNG')); ?></th></tr>
<?php
/* Die Suchtexte kommen aus mo_check() - DERSELBEN Funktion, aus der auch die
 * Loxone-Vorlage sie bildet. Bis 1.0.13 standen sie zusaetzlich in beiden
 * Sprachdateien ausgeschrieben: dieselbe Angabe aus zwei Quellen, und wer die
 * Tabelle abtippte statt die Vorlage zu importieren, bekam die aeltere. */
foreach ($mw_felder as $mw_name => $mw_f) { ?>
<tr><td><span class="sm-mono"><?php echo mw_e(mo_check($mw_name)); ?></span></td><td><?php echo mw_e(mo_feld_text($mw_name)); ?></td></tr>
<?php } ?>
</table>
</div>
</div>

<div class="sm-step"><b><?php echo mw_e(mo_t('TEXT.SCHRITT_4_STEUERUNG')); ?></b>
<table class="sm-tbl">
<tr><th><?php echo mw_e(mo_t('TEXT.EIGENSCHAFT')); ?></th><th><?php echo mw_e(mo_t('TEXT.WERT')); ?></th></tr>
<tr><td><?php echo mw_e(mo_t('TEXT.ADRESSE_VIRTUELLER_AUSGANG')); ?></td><td><span class="sm-mono">http://<?php echo $mw_host; ?></span> &mdash; <b><?php echo mw_e(mo_t('TEXT.OHNE')); ?></b> <?php echo mw_e(mo_t('TEXT.BENUTZER_UND_PASSWORT')); ?></td></tr>
</table>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:52%;"><?php echo mw_e(mo_t('TEXT.BEFEHL_BEI_EIN')); ?></th><th><?php echo mw_e(mo_t('TEXT.WIRKUNG')); ?></th></tr>
<?php
/* Die Adressen werden aus EINEM Bauteil gebildet - demselben, das die
 * Vorlage der Steuerbefehle benutzt. Zwei Stellen, die dasselbe
 * zusammensetzen, laufen auseinander. Und sie tragen das Token: eine
 * angezeigte Adresse zum Abschreiben ist vollstaendig, sonst weist das
 * Plugin die eigene Anleitung ab. */
$mw_befehle = array(
    'auto'        => mo_t('TEXT.B_AUTO'),
    'home'        => mo_t('TEXT.B_HOME'),
    'man'         => mo_t('TEXT.B_MAN'),
    'eod'         => mo_t('TEXT.B_EOD'),
    'start'       => mo_t('TEXT.B_START'),
    'stop'        => mo_t('TEXT.B_STOP'),
    'blade_reset' => mo_t('TEXT.B_BLADE'),
);
foreach ($mw_befehle as $mw_b => $mw_bt) { ?>
<tr><td><span class="sm-mono">/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?cmd=<?php echo mw_e($mw_b); ?>&amp;token=<?php echo mw_e($mw_cfg['aktionstoken']); ?></span></td><td><?php echo mw_e($mw_bt); ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-warnung"><?php echo mo_t('TEXT.TOKEN_NOETIG'); ?></div>
</div>

<div class="sm-step"><b><?php echo mw_e(mo_t('TEXT.H_TOKEN')); ?></b>
<table class="sm-tbl">
<tr><th><?php echo mw_e(mo_t('TEXT.EIGENSCHAFT')); ?></th><th><?php echo mw_e(mo_t('TEXT.WERT')); ?></th></tr>
<tr><td><?php echo mw_e(mo_t('TEXT.AKTUELLES_TOKEN')); ?></td><td><span class="sm-mono"><?php echo mw_e($mw_cfg['aktionstoken']); ?></span></td></tr>
</table>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo mw_e(mo_t('LEGENDE.AKTION')); ?></span>
</div>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <?php echo mo_fmt_feld(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?php echo mw_e(mo_t('TEXT.K_TOKEN_NEU')); ?></button>
  </form>
</div>
</div>

<div class="sm-step"><b><?php echo mw_e(mo_t('TEXT.SCHRITT_5_AUSFALL')); ?></b><br>
<?php echo mo_t('TEXT.AUSFALL_TEXT'); ?>
</div>

<h2><?php echo mw_e(mo_t('TEXT.H_VORLAGE')); ?></h2>
<div class="sm-hinweis"><?php echo mo_t('TEXT.H_VORLAGE_TEXT'); ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?php echo mw_e(mo_t('LEGENDE.TECHNIK')); ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <?php echo mo_fmt_feld(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage" value="1">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?php echo mw_e(mo_t('TEXT.K_VORLAGE')); ?></button>
</form>
<form action="index.php" method="post">
  <?php echo mo_fmt_feld(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage_vo" value="1">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?php echo mw_e(mo_t('TEXT.K_VORLAGE_VO')); ?></button>
</form>
</div>

<div class="sm-step"><b><?php echo mw_e(mo_t('TEXT.SCHRITT_6_BAUSTEINE')); ?></b><br>
<b><?php echo mw_e(mo_t('TEXT.4A_KACHELN')); ?></b>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?php echo mw_e(mo_t('TEXT.BAUSTEIN')); ?></th><th><?php echo mw_e(mo_t('TEXT.NAME')); ?></th><th><?php echo mw_e(mo_t('TEXT.EINSTELLUNG')); ?></th><th><?php echo mw_e(mo_t('TEXT.EINGNGE')); ?></th></tr>
<tr><td>1</td><td><?php echo mw_e(mo_t('TEXT.STATUSBAUSTEIN')); ?></td><td><?php echo mw_e(mo_t('TEXT.MHER_ZUSTAND')); ?></td><td><?php echo mw_e(mo_t('TEXT.TEXTE_JE_WERT_1_PARKT_2_MHT_3_FHRT')); ?></td><td><?php echo mw_e(mo_t('TEXT.I1_CODE')); ?></td></tr>
<tr><td>2</td><td><?php echo mw_e(mo_t('TEXT.ANALOGANZEIGEN')); ?></td><td><?php echo mw_e(mo_t('TEXT.AKKU_BETRIEBSSTUNDEN_MESSER_RESTST')); ?></td><td><?php echo mo_t('TEXT.EINHEITEN'); ?> <span class="sm-mono">&lt;v.0&gt; %</span>, <span class="sm-mono">&lt;v.0&gt; h</span></td><td><?php echo mw_e(mo_t('TEXT.BATT_STUNDEN_MESSER')); ?></td></tr>
</table>
</div>
<b><?php echo mw_e(mo_t('TEXT.4B_MELDUNGEN')); ?></b>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?php echo mw_e(mo_t('TEXT.BAUSTEIN')); ?></th><th><?php echo mw_e(mo_t('TEXT.NAME')); ?></th><th><?php echo mw_e(mo_t('TEXT.EINSTELLUNG')); ?></th><th><?php echo mw_e(mo_t('TEXT.EINGNGE')); ?></th></tr>
<tr><td>3</td><td><?php echo mw_e(mo_t('TEXT.SCHWELLWERTSCHALTER_S1_S2')); ?></td><td><?php echo mw_e(mo_t('TEXT.MELDEFENSTER_PUSH_FREIGEGEBEN')); ?></td><td><?php echo mw_e(mo_t('TEXT.JE_EIN_0_5_AUS_0_4')); ?></td><td><?php echo mw_e(mo_t('TEXT.ANN_BZW_PUSH')); ?></td></tr>
<tr><td>4</td><td><?php echo mw_e(mo_t('TEXT.UND_U1_ODER_O1')); ?></td><td><?php echo mw_e(mo_t('TEXT.MHER_MELDUNG')); ?></td><td><?php echo mw_e(mo_t('TEXT.O1_IST_DIE_EINZIGE_QUELLE_DES_BENA')); ?></td><td><?php echo mw_e(mo_t('TEXT.U1_S1_S2')); ?></td></tr>
<tr><td>5</td><td><?php echo mw_e(mo_t('TEXT.BENACHRICHTIGUNGS_BAUSTEIN')); ?></td><td><?php echo mw_e(mo_t('TEXT.PUSH_RASENMHER')); ?></td><td><?php echo mw_e(mo_t('TEXT.TEXT_Z_B_MELDUNG_VOM_RASENMHER_DET')); ?></td><td><?php echo mw_e(mo_t('TEXT.O1')); ?></td></tr>
<tr><td>6</td><td><?php echo mw_e(mo_t('TEXT.SCHWELLWERTSCHALTER_S3')); ?></td><td><?php echo mw_e(mo_t('TEXT.STRUNG')); ?></td><td><?php echo mw_e(mo_t('TEXT.EIN_0_5_AN_FEHLER_EIGENE_WARNKACHE')); ?></td><td><?php echo mw_e(mo_t('TEXT.FEHLER_3')); ?></td></tr>
<tr><td>7</td><td><?php echo mw_e(mo_t('TEXT.BENACHRICHTIGUNGS_BAUSTEIN_2')); ?></td><td><?php echo mw_e(mo_t('TEXT.TEST_PUSH')); ?></td><td><?php echo mw_e(mo_t('TEXT.EIGENER_BAUSTEIN_NUR_FR_DEN_TEST')); ?></td><td><?php echo mw_e(mo_t('TEXT.SCHWELLWERTSCHALTER_AN_PTEST')); ?></td></tr>
<tr><td>8</td><td><?php echo mw_e(mo_t('TEXT.STATUSBAUSTEIN')); ?></td><td><?php echo mw_e(mo_t('TEXT.B8_NAME')); ?></td><td><?php echo mw_e(mo_t('TEXT.B8_EINST')); ?></td><td><?php echo mw_e(mo_t('TEXT.B8_EING')); ?></td></tr>
</table>
</div>
<b><?php echo mw_e(mo_t('TEXT.4C_WETTER_UND_ZEITSPERREN_DER_EIGE')); ?></b>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?php echo mw_e(mo_t('TEXT.BAUSTEIN')); ?></th><th><?php echo mw_e(mo_t('TEXT.NAME')); ?></th><th><?php echo mw_e(mo_t('TEXT.EINSTELLUNG')); ?></th><th><?php echo mw_e(mo_t('TEXT.EINGNGE')); ?></th></tr>
<tr><td>9</td><td><?php echo mw_e(mo_t('TEXT.UND_U2')); ?></td><td><?php echo mw_e(mo_t('TEXT.MHEN_SPERREN_BEI_REGEN')); ?></td><td><?php echo mo_t('TEXT.AUF'); ?> <span class="sm-mono">?cmd=home</span><?php echo mo_t('TEXT.FREIGABE_ERST_NACH_DER_TROCKNUNGSZ'); ?> <span class="sm-mono">?cmd=auto</span></td><td><?php echo mo_t('TEXT.REGENSENSOR_CODE_2'); ?></td></tr>
<tr><td>10</td><td><?php echo mw_e(mo_t('TEXT.UND_U3')); ?></td><td><?php echo mw_e(mo_t('TEXT.RUHEZEITEN_EINHALTEN')); ?></td><td><?php echo mo_t('TEXT.TEXT_2'); ?> <span class="sm-mono">?cmd=home</span> <?php echo mw_e(mo_t('TEXT.ZU_ZEITEN_IN_DENEN_NICHT_GEMHT_WER')); ?></td><td><?php echo mo_t('TEXT.ZEITSCHALTUHR_GGF_SCHULFREI_FEIERT'); ?></td></tr>
<tr><td>11</td><td><?php echo mw_e(mo_t('TEXT.SCHWELLWERTSCHALTER_S4_TASTER')); ?></td><td><?php echo mw_e(mo_t('TEXT.MESSERWECHSEL_QUITTIEREN')); ?></td><td><?php echo mo_t('TEXT.TASTER_IN_DER_APP_VIRTUELLER_AUSGA'); ?> <span class="sm-mono">?cmd=blade_reset</span></td><td><?php echo mw_e(mo_t('TEXT.MESSERWARN_FR_DIE_WARNKACHEL')); ?></td></tr>
</table>
</div>
<div class="sm-hilfe"><b><?php echo mw_e(mo_t('TEXT.PRAXIS_ERFAHRUNG')); ?></b> <?php echo mo_t('TEXT.DER_BENACHRICHTIGUNGS_BAUSTEIN_SEN'); ?></div>
<div class="sm-hilfe"><b><?php echo mw_e(mo_t('TEXT.ZU_8')); ?></b> <?php echo mo_t('TEXT.ZU_8_TEXT'); ?></div>
</div>

<div class="sm-step"><b><?php echo mw_e(mo_t('TEXT.SCHRITT_7_GEGENPROBE')); ?></b><br>
<?php echo mo_t('TEXT.GEGENPROBE_TEXT'); ?>
<span class="sm-mono">http://<?php echo $mw_host; ?>/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?json=1</span>
</div>
</div>

<!-- ================= Test ================= -->
<div class="sm-seite<?php echo $mw_tab === 'tab-test' ? ' sm-active' : ''; ?>" id="tab-test">
<h2><?php echo mw_e(mo_t('TEXT.H_SELBSTPRUEFUNG')); ?></h2>
<div class="sm-hilfe"><?php echo mo_t('TEXT.SELBST_HILFE'); ?></div>
<table class="sm-tbl sm-pruef">
<?php
/* Die Selbstpruefung bekommt die Reiterliste als ARGUMENT, nicht aus einem
 * zweiten preg_match: sie steht zur Laufzeit ohnehin da, und sie ein zweites
 * Mal aus dem Quelltext zu lesen waere eine zweite Wahrheit. */
foreach (mo_selbsttest(__FILE__, $mw_reiter) as $mw_z) {
    list($mw_schl, $mw_ok, $mw_txt) = $mw_z;
    $mw_zeichen = ($mw_ok === 1) ? '&#10004;' : (($mw_ok === 2) ? '&ndash;' : '&#10008;');
    $mw_farbe = ($mw_ok === 1) ? 'sm-an' : (($mw_ok === 2) ? '' : 'sm-aus');
?>
<tr><td class="<?php echo $mw_farbe; ?>"><?php echo $mw_zeichen; ?></td><td style="width:34%;"><?php echo mw_e(mo_t($mw_schl)); ?></td><td><?php echo $mw_txt; ?></td></tr>
<?php } ?>
</table>
<div class="sm-hilfe"><?php echo mo_t('TEXT.SELBST_STRICH'); ?></div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo mw_e(mo_t('LEGENDE.LESEN')); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo mw_e(mo_t('LEGENDE.TECHNIK')); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo mw_e(mo_t('LEGENDE.AKTION')); ?></span>
</div>

<h3 class="sm-h3"><?php echo mw_e(mo_t('TEXT.ANSEHEN')); ?></h3>
<div class="sm-knopfreihe">
<a data-role="none" class="sm-btn sm-b-lesen" href="/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php" target="_blank"><?php echo mw_e(mo_t('TEXT.LOXONE_ZEILE_ABRUFEN')); ?></a>
<a data-role="none" class="sm-btn sm-b-lesen" href="/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?json=1" target="_blank"><?php echo mw_e(mo_t('TEXT.JSON_ANSICHT')); ?></a>
</div>

<h3 class="sm-h3"><?php echo mw_e(mo_t('TEXT.TECHNISCHE_AUSKUNFT')); ?></h3>
<div class="sm-knopfreihe">
<a data-role="none" class="sm-btn sm-b-technik" href="/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?debug=1&amp;refresh=1" target="_blank"><?php echo mw_e(mo_t('TEXT.DEBUG')); ?></a>
<a data-role="none" class="sm-btn sm-b-technik" href="/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?selftest=1&amp;token=<?php echo mw_e($mw_cfg['aktionstoken']); ?>" target="_blank"><?php echo mw_e(mo_t('TEXT.K_SELFTEST')); ?></a>
</div>

<?php
/* C7 - Der rohe Befehl.
 *
 * Welche Befehle die JSON-Schnittstelle des Robonect-Moduls ausser status,
 * health, mode, start und stop noch kennt, ist NICHT gemessen - im
 * Arbeitsordner steht es nirgends, und ein Modul zum Messen war nicht
 * greifbar. Statt zu raten beantwortet die Anlage die Frage selbst: dieser
 * Knopf zeigt die ROHE Antwort auf einen eingegebenen Befehl. Er liest nur,
 * deshalb grau und nicht orange - was er ausloest, entscheidet der Befehl,
 * und darauf weist der Text daneben hin. */
?>
<h3 class="sm-h3"><?php echo mw_e(mo_t('TEXT.H_ROHBEFEHL')); ?></h3>
<div class="sm-hilfe"><?php echo mo_t('TEXT.ROHBEFEHL_HILFE'); ?></div>
<div class="sm-knopfreihe">
<a data-role="none" class="sm-btn sm-b-technik" href="/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?roh=version&amp;token=<?php echo mw_e($mw_cfg['aktionstoken']); ?>" target="_blank"><?php echo mw_e(mo_t('TEXT.K_ROH_VERSION')); ?></a>
<a data-role="none" class="sm-btn sm-b-technik" href="/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?roh=status&amp;token=<?php echo mw_e($mw_cfg['aktionstoken']); ?>" target="_blank"><?php echo mw_e(mo_t('TEXT.K_ROH_STATUS')); ?></a>
<a data-role="none" class="sm-btn sm-b-technik" href="/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?roh=health&amp;token=<?php echo mw_e($mw_cfg['aktionstoken']); ?>" target="_blank"><?php echo mw_e(mo_t('TEXT.K_ROH_HEALTH')); ?></a>
</div>

<h3 class="sm-h3"><?php echo mw_e(mo_t('TEXT.LST_ETWAS_AUS')); ?></h3>
<div class="sm-hilfe"><?php echo mo_t('TEXT.SCHALTEN_HINWEIS'); ?></div>
<div class="sm-knopfreihe">
<a data-role="none" class="sm-btn sm-b-aktion" href="/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?ptest=1&amp;token=<?php echo mw_e($mw_cfg['aktionstoken']); ?>" target="_blank"><?php echo mw_e(mo_t('TEXT.TEST_PUSHNACHRICHT')); ?></a>
<a data-role="none" class="sm-btn sm-b-aktion" href="/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?cmd=auto&amp;probe=1&amp;token=<?php echo mw_e($mw_cfg['aktionstoken']); ?>" target="_blank"><?php echo mw_e(mo_t('TEXT.K_TROCKEN')); ?></a>
<a data-role="none" class="sm-btn sm-b-aktion" href="/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?cmd=auto&amp;token=<?php echo mw_e($mw_cfg['aktionstoken']); ?>" target="_blank"><?php echo mw_e(mo_t('TEXT.AUTOMATIK')); ?></a>
<a data-role="none" class="sm-btn sm-b-aktion" href="/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?cmd=home&amp;token=<?php echo mw_e($mw_cfg['aktionstoken']); ?>" target="_blank"><?php echo mw_e(mo_t('TEXT.NACH_HAUSE')); ?></a>
<a data-role="none" class="sm-btn sm-b-aktion" href="/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?cmd=stop&amp;token=<?php echo mw_e($mw_cfg['aktionstoken']); ?>" target="_blank"><?php echo mw_e(mo_t('TEXT.STOPP')); ?></a>
</div>
<div class="sm-small"><?php echo mo_t('TEXT.NACH_HAUSE_IST_DER_UNGEFHRLICHSTE_'); ?></div>

<?php if ($mw_fehlerliste) { ?>
<h3 class="sm-h3"><?php echo mw_e(mo_t('TEXT.H_FEHLERHISTORIE')); ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:150px;"><?php echo mw_e(mo_t('TEXT.ZEITPUNKT')); ?></th><th style="width:24%;"><?php echo mw_e(mo_t('TEXT.NAME')); ?></th><th style="width:70px;">Code</th><th><?php echo mw_e(mo_t('TEXT.BEDEUTUNG')); ?></th></tr>
<?php foreach (array_reverse($mw_fehlerliste) as $mw_fe) { ?>
<tr><td><?php echo mw_e(date('d.m.Y H:i', (int) $mw_fe['ts'])); ?></td><td><?php echo mw_e($mw_fe['name']); ?></td><td><?php echo (int) $mw_fe['code']; ?></td><td><?php echo mw_e($mw_fe['text']); ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>

<h2><?php echo mw_e(mo_t('TEXT.STATUSCODES_IM_UUML_BERBLICK')); ?></h2>
<table class="sm-tbl"><tr><th><?php echo mw_e(mo_t('TEXT.CODE_2')); ?></th><th><?php echo mw_e(mo_t('TEXT.BEDEUTUNG')); ?></th><th><?php echo mw_e(mo_t('TEXT.CODE_2')); ?></th><th><?php echo mw_e(mo_t('TEXT.BEDEUTUNG')); ?></th></tr>
<tr><td>0</td><td><?php echo mw_e(mo_t('TEXT.STATUS_WIRD_ERMITTELT')); ?></td><td>7</td><td><?php echo mw_e(mo_t('TEXT.FEHLER_4')); ?></td></tr>
<tr><td>1</td><td><?php echo mw_e(mo_t('TEXT.PARKT')); ?></td><td>8</td><td><?php echo mw_e(mo_t('TEXT.SCHLEIFENSIGNAL_VERLOREN')); ?></td></tr>
<tr><td>2</td><td><?php echo mw_e(mo_t('TEXT.MHT')); ?></td><td>16</td><td><?php echo mw_e(mo_t('TEXT.ABGESCHALTET')); ?></td></tr>
<tr><td>3</td><td><?php echo mw_e(mo_t('TEXT.SUCHT_DIE_LADESTATION')); ?></td><td>17</td><td><?php echo mw_e(mo_t('TEXT.SCHLFT')); ?></td></tr>
<tr><td>4</td><td><?php echo mw_e(mo_t('TEXT.LDT')); ?></td><td>18</td><td><?php echo mw_e(mo_t('TEXT.WIRD_GEWARTET')); ?></td></tr>
<tr><td>5</td><td><?php echo mw_e(mo_t('TEXT.SUCHT')); ?></td><td>&minus;1</td><td><?php echo mw_e(mo_t('TEXT.KEINE_VERBINDUNG')); ?></td></tr>
</table>
</div>

<!-- ================= Logdateien ================= -->
<div class="sm-seite<?php echo $mw_tab === 'tab-log' ? ' sm-active' : ''; ?>" id="tab-log">
<h2><?php echo mw_e($mw_reiter['tab-log']); ?></h2>
<div class="sm-small" style="margin-bottom:8px;"><?php echo mo_t('TEXT.PROTOKOLLIERT_WERDEN_STATUSNDERUNG'); ?><br><?php echo mw_e(mo_t('TEXT.DATEI')); ?> <span class="sm-mono"><?php echo mw_e($mw_logfile); ?></span></div>
<?php if ($mw_loglines) { ?>
<div class="sm-log"><?php echo mw_e(implode("\n", $mw_loglines)); ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo mw_e(mo_t('TEXT.NOCH_KEINE_PROTOKOLL_EINTRGE_VORHA')); ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo mw_e(mo_t('LEGENDE.AKTION')); ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
    <?php echo mo_fmt_feld(); ?>
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo mw_e(mo_t('TEXT.PROTOKOLL_LEEREN')); ?></button>
</form>
</div>
</div>

</div>
<script>
function mwTtsMode() {
    var m = document.getElementById('tts_mode');
    if (!m) { return; }
    m = m.value;
    var h = document.getElementById('tts_audioserver_hint');
    var t = document.getElementById('tts_template_row');
    if (h) { h.style.display = (m === 'audioserver') ? 'block' : 'none'; }
    if (t) { t.style.display = (m === 'ms4h' || m === 'custom') ? 'block' : 'none'; }
    var port = document.getElementsByName('tts_port')[0];
    /* A23 (05.09.2026): bis 1.1.3 stand hier zusaetzlich port.value === '80'.
       Die Funktion laeuft beim Seitenaufbau; ein bewusst gespeicherter Port 80
       wurde damit ohne Zutun auf 7091 gestellt, und ein anschliessendes,
       sonst unveraendertes Speichern schrieb ihn in die Datei - gemeldet als
       "Konfiguration gespeichert". Vorbelegt wird nur noch ein LEERES Feld. */
    if (port && m === 'musicserver' && !port.value) { port.value = 7091; }
}
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.ziel === id); });
        document.querySelectorAll('.sm-seite').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
        document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
        if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
    }
    tabs.forEach(function (t) { t.addEventListener('click', function (e) { e.preventDefault(); activate(t.dataset.ziel); }); });
    // Der Server hat sm-active bereits gesetzt; dieser Aufruf richtet nur die
    // versteckten activetab-Felder aus und ist ansonsten wirkungslos.
    activate(<?php echo json_encode($mw_tab); ?>);
    mwTtsMode();
})();
</script>
<?php
if ($mw_frame) { LBWeb::lbfooter(); }
