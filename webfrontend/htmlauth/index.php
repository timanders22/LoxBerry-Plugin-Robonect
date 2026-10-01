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
 * BAEUME - ein require ueber '..' traefe nur das ausgepackte Archiv.
 *
 * Welche Lage gilt, entscheidet der eigene Ablageort, nicht die Reihenfolge
 * der Versuche (Nachlese 25.09.2026): liegt diese Datei unter
 * <Wurzel>/webfrontend/htmlauth/plugins/<ordner>, ist sie installiert, sonst
 * liegt sie in einem ausgepackten Archiv. Bis 1.1.12 wurden drei Kandidaten
 * der Reihe nach probiert - mit LBHOMEDIR zuerst die Bibliothek der ANLAGE
 * (aus einem Archiv heraus lud die Oberflaeche damit fremden Code), danach
 * <ueber dem Archiv>/html/plugins/htmlauth/mower_lib.php, also ausserhalb
 * des Archivs, aus einem Archiv unter / ab der Laufwerkswurzel (in WSL
 * gemessen, Pruefung-Robonect-1.1.12, Faelle W5, W6; Bauart Spotpreis-Tibber
 * 0.9.19). */
$mw_kandidaten = array();
if (basename(dirname(__DIR__)) === 'plugins' && basename(dirname(dirname(__DIR__))) === 'htmlauth') {
    $mw_kandidaten[] = dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/mower_lib.php';
} else {
    $mw_kandidaten[] = dirname(__DIR__) . '/html/mower_lib.php';
}
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

/* Durchgang 01.10.2026 (U2): Erfolg, Hinweis und Fehler als Listen - sie reisen
 * in der Einmalmeldung. */
$mw_ok = array(); $mw_warn = array();
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
/* U2: auch ein abgewiesener POST endet mit der Umleitung (Meldung im GET). */
$mw_war_post = $mw_ist_post;
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
 * Diese Liste und die id der Flaechen muessen deckungsgleich bleiben. Sie
 * stehen ausgeschrieben, weil hausstandard_pruefen.py sie als LITERAL sucht:
 * eine Schleife macht das Werkzeug blind und meldet dann "0 Reiter", was
 * beim Ueberfliegen wie ein Haken aussieht.
 *
 * A11 (06.09.2026, gemessen): hier stand, die Pruefzeile mo_reiterprobe()
 * halte "alle drei Stellen gegeneinander". Sie liest aber nur zwei - die
 * Leiste (data-ziel) und die Bereiche (id). Die Positivliste $mw_muster war
 * ihr unbekannt. Rueckbau an einer Kopie: nimmt man tab-log aus dem
 * Ausdruck, wird der Reiter unerreichbar (?form=log oeffnet tab-settings) -
 * und die Zeile meldet unveraendert "ja, alle 5 Reiter".
 *
 * Die dritte Stelle gibt es jetzt nicht mehr: der Ausdruck wird AUS dieser
 * Liste gebaut. Auseinanderlaufen kann damit nur noch, was die Pruefzeile
 * wirklich misst - und der Satz oben stimmt wieder.
 */
$mw_reiter = array(
    'tab-settings' => mo_t('REITER.EINSTELLUNGEN'),
    'tab-mqtt'     => mo_t('REITER.MQTT'),
    'tab-loxone'   => mo_t('REITER.LOXONE'),
    'tab-test'     => mo_t('REITER.TEST'),
    'tab-log'      => mo_t('REITER.LOG'),
);
$mw_muster = '/^(' . implode('|', array_map(
    'preg_quote', array_keys($mw_reiter))) . ')$/';
$mw_wunsch = isset($_POST['activetab']) ? (string) $_POST['activetab']
    : (isset($_GET['form']) ? 'tab-' . (string) $_GET['form'] : '');
$mw_tab = preg_match($mw_muster, $mw_wunsch) ? $mw_wunsch : 'tab-settings';

/* ================= 5. Handler ================= */
/*
 * U2 (Durchgang 01.10.2026, Regeln/04, Entscheidung Nr. 19, gemessen): bis
 * 1.1.14 rendete jeder Handler die Seite unmittelbar nach dem POST - kein
 * einziger endete mit einer Umleitung. Neuladen ohne JavaScript schickte
 * Speichern, Quittieren und "Protokoll leeren" erneut. Jetzt endet JEDER POST
 * mit 303 auf den Reiter; das Ergebnis reist als Einmalmeldung
 * (mo_flash_schreiben(), gelesen nur beim GET). Downloads (Vorlagen,
 * Sicherung) liefern weiter unmittelbar ihre Datei und enden mit exit - VOR
 * lbheader().
 *
 * U3 (Entscheidung Nr. 16, gemessen): bei einer Beanstandung wird NICHTS
 * gespeichert, auch nicht die uebrigen, richtigen Felder. Bis 1.1.14 stand
 * hier "A14: gespeichert wird auch dann, wenn etwas beanstandet wurde".
 * U4 (X-2): die eingetippten Werte des beanstandeten Formulars reisen mit
 * (nie Kennwort und Sprechtoken), das Feld ist markiert.
 */
$mw_eingaben = null;

/** X-2: die Eingaben eines Formulars fuer die Einmalmeldung - nur Zeichenketten,
 *  nie Kennwort (m_pass) und Sprechtoken (tts_alexa_token). */
function mw_eingaben_sammeln($formular, array $falsch)
{
    $namen = ($formular === 'mqtt') ? array('mqtt_enabled', 'mqtt_topic')
        : array('cache_sec', 'blade_hours', 'blade_base', 'stat_ein', 'notify_audio', 'notify_push',
                'n_fehler', 'n_fertig', 'n_messer', 'n_akku', 'tts_mode', 'tts_ip', 'tts_port', 'tts_zones',
                'tts_volume', 'tts_lang', 'tts_template', 'tts_alexa_geraet', 'tts_alexa_laut', 'tts_alexa_loeschen');
    $werte = array();
    foreach ($namen as $n) {
        if (isset($_POST[$n]) && is_string($_POST[$n])) { $werte[$n] = substr($_POST[$n], 0, 600); }
    }
    if ($formular === 'einst') {
        foreach (array('m_name', 'm_ip', 'm_user', 'm_bhours', 'm_bbase', 'm_del') as $n) {
            if (!isset($_POST[$n]) || !is_array($_POST[$n])) { continue; }
            foreach ($_POST[$n] as $i => $w) {
                if (is_int($i) && $i >= 0 && $i < mo_max_maeher() && is_string($w)) {
                    $werte[$n . '[' . $i . ']'] = substr($w, 0, 600);
                }
            }
        }
    }
    return array('formular' => $formular, 'werte' => $werte, 'falsch' => array_values(array_unique($falsch)));
}

/** Ein Feld einer Maeherzeile aus dem POST: Zeichenkette, '' wenn es fehlt,
 *  null wenn es keine Zeichenkette ist (Liste statt Wert). */
function mw_post_zelle($name, $i)
{
    if (!isset($_POST[$name]) || !is_array($_POST[$name]) || !array_key_exists($i, $_POST[$name])) { return ''; }
    return is_string($_POST[$name][$i]) ? $_POST[$name][$i] : null;
}

/* --- Downloads zuerst: sie enden mit exit und muessen VOR lbheader() ---
 * U9: beide Vorlagen je Maeher (vorlage_dev); eine unzulaessige Nummer wird
 * abgewiesen, nicht auf 1 gebogen. */
if ($mw_ist_post && (isset($_POST['vorlage']) || isset($_POST['vorlage_vo']))) {
    $mw_vd = (isset($_POST['vorlage_dev']) && is_string($_POST['vorlage_dev'])) ? trim($_POST['vorlage_dev']) : '1';
    if (preg_match('/^[1-9]$/', $mw_vd) === 1 && (int) $mw_vd <= max(1, count(mo_mowers()))) {
        list($mw_vname, $mw_vinhalt) = isset($_POST['vorlage_vo']) ? mo_vo_vorlage((int) $mw_vd) : mo_vorlage((int) $mw_vd);
        header('Content-Type: application/x-download');
        header('Content-Disposition: attachment; filename="' . $mw_vname . '"');
        echo $mw_vinhalt;
        exit;
    }
    $mw_fehler[] = sprintf(mo_t('TEXT.VORLAGE_DEV_FALSCH'), htmlspecialchars(mo_kuerzen($mw_vd, 10), ENT_QUOTES, 'UTF-8'));
    $mw_tab = 'tab-loxone';
}

/* Einstellungen sichern.
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken, ohne das
 * Sprechtoken fuer Alexa-NG. Ohne das Aktionstoken stuenden nach dem
 * Zurueckspielen alle Felder richtig, und das Plugin kaeme trotzdem nicht an
 * die Anlage; die Datei waere wertlos. C7 (X-3): wuerde das Zurueckspielen
 * die Datei abweisen, traegt ihr Kopf _warnung (nur Namen), und der Knopf
 * zeigt die Warnung schon vorher. */
if ($mw_ist_post && isset($_POST['mo_sichern'])) {
    $mw_js = mo_sicherung_erzeugen();
    if ($mw_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . mo_sicherung_name() . '"');
        echo $mw_js;
        exit;
    }
    $mw_fehler[] = mo_t('TEXT.SICH_SCHREIBFEHLER');
    $mw_tab = 'tab-settings';
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
        || !isset($_FILES['mo_sicherung']['tmp_name']) || !is_string($_FILES['mo_sicherung']['tmp_name'])
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
             * ein leeres Feld darf die Loxone-Adressen nicht abschneiden.
             * C8 (Durchgang 01.10.2026, Nr. 19): die Meldung sagt es jetzt -
             * bis 1.1.14 stand nur "10 Werte uebernommen". */
            $mw_token_blieb = (trim((string) $mw_neu['aktionstoken']) === '');
            if ($mw_token_blieb) {
                $mw_neu['aktionstoken'] = (string) $mw_cfg['aktionstoken'];
            }
            /* C9: das Sprechtoken reist nie in einer Datei - das geltende bleibt. */
            if (is_array($mw_neu['tts'])) {
                $mw_neu['tts']['alexa_token'] = isset($mw_cfg['tts']['alexa_token'])
                    ? (string) $mw_cfg['tts']['alexa_token'] : '';
            }
            $mw_alt_cfg = $mw_cfg;
            if (mo_config_speichern($mw_neu)) {
                $mw_cfg = mo_config();
                $mw_fmt = mo_formtoken();   // das Merkmal haengt am Token
                $mw_ok[] = sprintf(mo_t('TEXT.SICH_UEBERNOMMEN'), $mw_n);
                if ($mw_token_blieb) { $mw_ok[] = mo_t('TEXT.SICH_TOKEN_BLIEB'); }
                mo_log('Einstellungen aus einer Sicherung zurueckgespielt: ' . $mw_n . ' Werte'
                    . ($mw_token_blieb ? ' (die Datei trug kein Aktionstoken - das bisherige bleibt).' : '.'));
                // M2/M3: Praefix oder Schalter koennen sich geaendert haben.
                $mw_r = mo_mqtt_nach_aenderung($mw_alt_cfg, $mw_cfg);
                foreach ($mw_r['ok'] as $mw_m) { $mw_ok[] = $mw_m; }
                foreach ($mw_r['fehler'] as $mw_m) { $mw_fehler[] = $mw_m; }
            } else {
                $mw_fehler[] = mo_t('TEXT.SICH_SCHREIBFEHLER');
            }
        }
    }
    $mw_tab = 'tab-settings';
}

if ($mw_ist_post && isset($_POST['clearlog'])) {
    if (!is_dir(dirname($mw_logfile))) { @mkdir(dirname($mw_logfile), 0775, true); }   // A18
    if (@file_put_contents($mw_logfile, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Admin-Oberflaeche)\n") === false) {
        $mw_fehler[] = sprintf(mo_t('TEXT.LOG_NICHT_GELEERT'), mw_e($mw_logfile));
    } else {
        $mw_ok[] = mo_t('TEXT.LOG_GELEERT');
    }
    $mw_tab = 'tab-log';
}

/* C5 (Durchgang 01.10.2026, gemessen): bis 1.1.14 hiess jeder Fehlschlag "Die
 * Einstellungen liessen sich nicht schreiben" - auch bei einem schweigenden
 * Maeher, bei 401 und bei einem nicht eingerichteten. Jetzt nennt die Meldung
 * den Schritt. Eine unzulaessige Nummer wird abgewiesen, nicht geklemmt. */
if ($mw_ist_post && isset($_POST['bladereset'])) {
    $mw_br = is_string($_POST['bladereset']) ? trim($_POST['bladereset']) : '';
    if (preg_match('/^[1-9]$/', $mw_br) !== 1) {
        $mw_fehler[] = sprintf(mo_t('TEXT.MESSER_NICHT_EINGERICHTET'), htmlspecialchars(mo_kuerzen($mw_br, 10), ENT_QUOTES, 'UTF-8'));
    } else {
        $mw_bgrund = '';
        $mw_btext = '';
        if (mo_blade_reset((int) $mw_br, $mw_bgrund, $mw_btext)) {
            $mw_ok[] = mo_t('TEXT.MESSER_QUITTIERT');
            $mw_cfg = mo_config();
        } elseif ($mw_bgrund === 'nicht_erreichbar') {
            $mw_fehler[] = sprintf(mo_t('TEXT.MESSER_NICHT_ERREICHBAR'), mw_e($mw_btext));
        } elseif ($mw_bgrund === 'nicht_eingerichtet') {
            $mw_fehler[] = sprintf(mo_t('TEXT.MESSER_NICHT_EINGERICHTET'), (int) $mw_br);
        } else {
            $mw_fehler[] = mo_t('TEXT.SICH_SCHREIBFEHLER');
        }
    }
    $mw_tab = 'tab-settings';
}

/* --- Neues Aktionstoken --- */
if ($mw_ist_post && isset($_POST['token_neu'])) {
    $mw_cfg['aktionstoken'] = mo_token_erzeugen();
    if (mo_config_speichern($mw_cfg)) {
        $mw_fmt = mo_formtoken();   // das Merkmal wechselt mit
        $mw_ok[] = mo_t('TEXT.TOKEN_NEU_OK');
        mo_log('Ein neues Aktionstoken wurde erzeugt.');
    } else {
        $mw_fehler[] = sprintf(mo_t('TEXT.FEHLER_SCHREIBEN'), mw_e($mw_cfgfile));
    }
    $mw_tab = 'tab-loxone';
}

/* --- C9 (Ansage-2): Testansage mit den GESPEICHERTEN Einstellungen --- */
if ($mw_ist_post && isset($_POST['tts_test'])) {
    list($mw_tok, $mw_tgrund) = mo_say(mo_t('TEXT.TESTANSAGE_TEXT'));
    if ($mw_tok) {
        $mw_ok[] = sprintf(mo_t('TEXT.TESTANSAGE_OK'), mw_e((string) $mw_cfg['tts']['mode']));
    } else {
        $mw_fehler[] = sprintf(mo_t('TEXT.TESTANSAGE_FEHL'), mw_e($mw_tgrund));
    }
    $mw_tab = 'tab-test';
}

/* --- MQTT speichern (eigener Reiter, Hausstandard) --- */
if ($mw_ist_post && isset($_POST['mqtt_save'])) {
    mo_nennform('formular');
    $mw_neu = $mw_cfg;
    $mw_neu['mqtt_enabled'] = isset($_POST['mqtt_enabled']) ? 1 : 0;
    /* Gegen DIESELBE Positivliste wie die Sicherung. Ein leeres oder fehlendes
     * Thema wird beanstandet, nicht still zu 'maeher' (C6, Nr. 19). */
    list($mw_wert, $mw_l) = mo_wert_pruefen('mqtt_topic', isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : '');
    if ($mw_l) {
        $mw_fehler[] = mo_t('TEXT.NICHTS_GESPEICHERT');
        $mw_falsch = array();
        foreach ($mw_l as $mw_e) { $mw_fehler[] = $mw_e['text']; $mw_falsch[] = $mw_e['feld']; }
        $mw_eingaben = mw_eingaben_sammeln('mqtt', $mw_falsch);
        mo_log('MQTT-Einstellungen beanstandet - es wurde nichts gespeichert.');
    } else {
        $mw_neu['mqtt_topic'] = $mw_wert;
        $mw_alt_cfg = $mw_cfg;
        if (mo_config_speichern($mw_neu)) {
            $mw_cfg = mo_config();
            $mw_ok[] = mo_t('TEXT.KONFIGURATION_GESPEICHERT');
            $mw_r = mo_mqtt_nach_aenderung($mw_alt_cfg, $mw_cfg);
            foreach ($mw_r['ok'] as $mw_m) { $mw_ok[] = $mw_m; }
            foreach ($mw_r['fehler'] as $mw_m) { $mw_fehler[] = $mw_m; }
        } else {
            $mw_fehler[] = sprintf(mo_t('TEXT.FEHLER_SCHREIBEN'), mw_e($mw_cfgfile));
        }
    }
    $mw_tab = 'tab-mqtt';
}

/* --- Einstellungen speichern --- */
if ($mw_ist_post && isset($_POST['save'])) {
    mo_nennform('formular');
    /* Aus dem Bestand uebernehmen, was dieses Formular nicht mitschickt.
     * BIS 1.0.8 FEHLTE DAS FUER aktionstoken: jedes Speichern warf das Token
     * still weg, der naechste Seitenaufruf erzeugte ein NEUES - und alle
     * Loxone-Adressen liefen auf 403. Deshalb wird hier NICHT von Grund auf
     * neu gebaut, sondern der Bestand fortgeschrieben. */
    $mw_neu = $mw_cfg;
    $mw_mangel = array();

    /* --- Maeher. Der Index steht AUSGESCHRIEBEN im Feldnamen (m_ip[0] statt
     * m_ip[]): eine nicht angehakte Loeschbox sendet gar nichts, und mit
     * fortlaufenden Klammern rutschten danach alle folgenden Zeilen um eine
     * Position - jeder Maeher bekaeme die Zugangsdaten seines Nachbarn.
     * Geloescht wird ueber den Haken, NIE durch Leeren eines Feldes. */
    $mw_alt  = is_array($mw_cfg['mowers']) ? array_values($mw_cfg['mowers']) : array();
    $mw_liste = array();
    for ($mw_i = 0; $mw_i < mo_max_maeher(); $mw_i++) {
        if (mw_post_zelle('m_del', $mw_i) !== '' && isset($mw_alt[$mw_i])) { continue; }
        $mw_z = array();
        foreach (array('m_name' => 'name', 'm_ip' => 'ip', 'm_user' => 'user', 'm_pass' => 'pass',
                       'm_bhours' => 'blade_hours', 'm_bbase' => 'blade_base') as $mw_f => $mw_k) {
            $mw_v = mw_post_zelle($mw_f, $mw_i);
            $mw_z[$mw_k] = ($mw_v === null) ? array() : $mw_v;     // Liste -> von der Pruefung abgewiesen
        }
        $mw_ip = is_string($mw_z['ip']) ? trim($mw_z['ip']) : 'x';
        if ($mw_ip === '') {
            if (isset($mw_alt[$mw_i]) && is_array($mw_alt[$mw_i])) {
                /* A19 (04.09.2026): ein versehentlich geleertes Adressfeld warf
                 * die Zeile samt Zugangsdaten weg. Beanstandet; seit dem
                 * Durchgang 01.10.2026 (Nr. 16) wird dann gar nichts gespeichert. */
                $mw_mangel[] = mo_mangel('m_ip[' . $mw_i . ']', 'mowers.' . ($mw_i + 1) . '.ip',
                                         sprintf(mo_t('TEXT.MAEHER_ADRESSE_LEER'), $mw_i + 1));
            } else {
                /* Nr. 19: eine neue Zeile mit Angaben, aber ohne Adresse wurde bis
                 * 1.1.14 still verworfen - jetzt beanstandet. */
                foreach (array('name', 'user', 'pass', 'blade_hours', 'blade_base') as $mw_k) {
                    if (!is_string($mw_z[$mw_k]) || trim($mw_z[$mw_k]) !== '') {
                        $mw_mangel[] = mo_mangel('m_ip[' . $mw_i . ']', 'mowers.' . ($mw_i + 1) . '.ip',
                                                 sprintf(mo_t('TEXT.MAEHER_ADRESSE_FEHLT'), $mw_i + 1));
                        break;
                    }
                }
            }
            continue;
        }
        // Leeres Passwortfeld = bisheriges Passwort behalten (es wird nie angezeigt)
        if ($mw_z['pass'] === '' && isset($mw_alt[$mw_i]['pass'])) { $mw_z['pass'] = (string) $mw_alt[$mw_i]['pass']; }
        /* Messerwechsel je Maeher (1.1.4): ein LEERES Feld heisst "die Vorgabe
         * gilt" und wird nicht als 0 uebernommen. */
        foreach (array('blade_hours', 'blade_base') as $mw_k) {
            if (is_string($mw_z[$mw_k]) && trim($mw_z[$mw_k]) === '') { unset($mw_z[$mw_k]); }
        }
        $mw_z['_zeile'] = $mw_i;
        $mw_liste[] = $mw_z;
    }
    /* U5: alle Beanstandungen sammeln, nicht die erste melden. */
    list($mw_wert, $mw_l) = mo_wert_pruefen('mowers', $mw_liste);
    if ($mw_l) { $mw_mangel = array_merge($mw_mangel, $mw_l); } else { $mw_neu['mowers'] = $mw_wert; }

    foreach (array('cache_sec', 'blade_hours', 'blade_base') as $mw_k) {
        list($mw_wert, $mw_l) = mo_wert_pruefen($mw_k, isset($_POST[$mw_k]) ? $_POST[$mw_k] : '');
        if ($mw_l) { $mw_mangel = array_merge($mw_mangel, $mw_l); } else { $mw_neu[$mw_k] = $mw_wert; }
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
    /* C9 (Ansage-2): das Sprechtoken - leer lassen behaelt es, der Haken
     * loescht es; beides zugleich ist ein Widerspruch und wird beanstandet. */
    $mw_atok_alt = isset($mw_cfg['tts']['alexa_token']) ? (string) $mw_cfg['tts']['alexa_token'] : '';
    $mw_atok = isset($_POST['tts_alexa_token']) ? $_POST['tts_alexa_token'] : '';
    $mw_aweg = isset($_POST['tts_alexa_loeschen']);
    if (is_string($mw_atok) && trim($mw_atok) === '') { $mw_atok = $mw_aweg ? '' : $mw_atok_alt; }
    elseif ($mw_aweg) {
        $mw_mangel[] = mo_mangel('tts_alexa_token', 'tts.alexa_token', mo_t('TEXT.ALEXA_TOKEN_WIDERSPRUCH'));
    }
    if (is_string($mw_atok)) { $mw_atok = trim($mw_atok); }
    $mw_tts_ein = array();
    foreach (array('mode' => 'tts_mode', 'ip' => 'tts_ip', 'port' => 'tts_port', 'zones' => 'tts_zones',
                   'volume' => 'tts_volume', 'lang' => 'tts_lang', 'template' => 'tts_template',
                   'alexa_geraet' => 'tts_alexa_geraet', 'alexa_laut' => 'tts_alexa_laut') as $mw_k => $mw_f) {
        $mw_tts_ein[$mw_k] = isset($_POST[$mw_f]) ? $_POST[$mw_f] : '';
    }
    $mw_tts_ein['alexa_token'] = $mw_atok;
    list($mw_wert, $mw_l) = mo_wert_pruefen('tts', $mw_tts_ein);
    if ($mw_l) {
        $mw_mangel = array_merge($mw_mangel, $mw_l);
    } elseif ($mw_wert['mode'] === 'alexang' && $mw_wert['alexa_token'] === '') {
        $mw_mangel[] = mo_mangel('tts_alexa_token', 'tts.alexa_token', mo_t('TEXT.ALEXA_OHNE_TOKEN'));
    } else {
        $mw_neu['tts'] = $mw_wert;
    }

    if ($mw_mangel) {
        /* U3 (Nr. 16): nichts speichern; U4 (X-2): die Eingaben reisen mit. */
        $mw_fehler[] = mo_t('TEXT.NICHTS_GESPEICHERT');
        $mw_falsch = array();
        foreach ($mw_mangel as $mw_e) { $mw_fehler[] = $mw_e['text']; $mw_falsch[] = $mw_e['feld']; }
        $mw_eingaben = mw_eingaben_sammeln('einst', $mw_falsch);
        mo_log('Einstellungen beanstandet (' . count($mw_mangel) . ') - es wurde nichts gespeichert.');
    } elseif (mo_config_speichern($mw_neu)) {
        $mw_cfg = mo_config();
        $mw_ok[] = '<b>' . mw_e(mo_t('TEXT.KONFIGURATION_GESPEICHERT')) . '</b> '
                 . mw_e(mo_t('TEXT.ZUGANGSDATEN_MIT_DATEIRECHTEN_0600'));
    } else {
        $mw_fehler[] = sprintf(mo_t('TEXT.FEHLER_SCHREIBEN'), mw_e($mw_cfgfile));
    }
    $mw_tab = 'tab-settings';
}
mo_nennform('datei');

/* --- U2: jeder POST endet hier mit 303 und der Einmalmeldung; ein GET liest sie. */
if ($mw_war_post) {
    if (mo_flash_schreiben(array('tab' => $mw_tab, 'ok' => $mw_ok, 'warn' => $mw_warn,
                                 'fehler' => $mw_fehler, 'eingaben' => $mw_eingaben))) {
        header('Location: index.php?form=' . substr($mw_tab, 4), true, 303);
        exit;
    }
    // Laesst sie sich nicht schreiben, zeigt die Seite das Ergebnis sofort.
    mo_log('Die Einmalmeldung liess sich nicht schreiben (' . $mw_p['flash'] . ') - Ergebnis ohne Umleitung gezeigt.');
} else {
    $mw_flash = mo_flash_lesen();
    if (is_array($mw_flash)) {
        foreach (array('ok' => 'mw_ok', 'warn' => 'mw_warn', 'fehler' => 'mw_fehler') as $mw_k => $mw_v) {
            if (isset($mw_flash[$mw_k]) && is_array($mw_flash[$mw_k])) {
                foreach ($mw_flash[$mw_k] as $mw_m) { if (is_string($mw_m)) { ${$mw_v}[] = $mw_m; } }
            }
        }
        if (isset($mw_flash['eingaben']['formular'], $mw_flash['eingaben']['werte'], $mw_flash['eingaben']['falsch'])
            && is_array($mw_flash['eingaben']['werte']) && is_array($mw_flash['eingaben']['falsch'])) {
            $mw_eingaben = $mw_flash['eingaben'];
        }
        if (!isset($_GET['form']) && isset($mw_flash['tab']) && is_string($mw_flash['tab'])
            && preg_match($mw_muster, $mw_flash['tab'])) {
            $mw_tab = $mw_flash['tab'];
        }
    }
}

/* X-2: Anzeige aus den Eingaben statt aus der Konfiguration - nur fuer das
 * beanstandete Formular und nur im GET direkt nach der Beanstandung. */
function mw_x2($formular)
{
    global $mw_eingaben;
    return is_array($mw_eingaben) && isset($mw_eingaben['formular']) && $mw_eingaben['formular'] === $formular;
}
function mw_wert($formular, $feld, $gespeichert)
{
    global $mw_eingaben;
    if (mw_x2($formular)) {
        return isset($mw_eingaben['werte'][$feld]) && is_string($mw_eingaben['werte'][$feld])
            ? $mw_eingaben['werte'][$feld] : '';
    }
    return (string) $gespeichert;
}
function mw_haken($formular, $feld, $gespeichert)
{
    global $mw_eingaben;
    if (mw_x2($formular)) { return isset($mw_eingaben['werte'][$feld]); }
    return !empty($gespeichert);
}
/** Das Merkmal am beanstandeten Feld: rot umrandet und fuer Vorleseprogramme markiert. */
function mw_falsch($feld)
{
    global $mw_eingaben;
    return (is_array($mw_eingaben) && isset($mw_eingaben['falsch']) && is_array($mw_eingaben['falsch'])
            && in_array($feld, $mw_eingaben['falsch'], true)) ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}

/* ================= Anzeigedaten ================= */

$mw_notify = is_array($mw_cfg['notify']) ? $mw_cfg['notify'] : array();
$mw_notify += array('audio' => 0, 'push' => 0, 'fehler' => 1, 'fertig' => 1, 'messer' => 1, 'akku' => 0);
$mw_tts = is_array($mw_cfg['tts']) ? $mw_cfg['tts'] : array();
$mw_tts += array('mode' => 'musicserver', 'ip' => '', 'port' => 7091, 'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '',
                 'alexa_geraet' => '', 'alexa_token' => '', 'alexa_laut' => -1);
/* C7 (X-3): wuerde die eigene Sicherung abgewiesen? Nur Namen. */
$mw_sich_mangel = mo_sicherung_mangel();
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
/* A30d (06.09.2026): hier stand .sm-pre - im gerenderten HTML nirgends
   benutzt, in beide Richtungen gezaehlt. Entfernt. .sm-warn eine Zeile
   hoeher war ebenfalls tot und wird seit A14 fuer die Meldung "nicht alles
   uebernommen" gebraucht; sie bleibt. */
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
/* X-2 (Durchgang 01.10.2026): das beanstandete Feld. */
.sm-wrap .sm-beanstandet { border: 2px solid #c62828 !important; background: #fff5f5 !important; }
</style>
<div class="sm-wrap">

<?php /* U2/U3 (Durchgang 01.10.2026): die Meldungen kommen aus der Einmalmeldung.
       * "Gespeichert, aber nicht alles uebernommen" gibt es nicht mehr (Nr. 16). */ ?>
<?php foreach ($mw_ok as $mw_m) { ?><div class="sm-alert sm-ok"><?php echo $mw_m; ?></div><?php } ?>
<?php foreach ($mw_warn as $mw_m) { ?><div class="sm-alert sm-warn"><?php echo $mw_m; ?></div><?php } ?>
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
<tr><th style="width:36px;"><?php echo mw_e(mo_t('TEXT.NR')); ?></th><th style="width:20%;"><?php echo mw_e(mo_t('TEXT.NAME_FREI')); ?></th><th style="width:20%;"><?php echo mw_e(mo_t('TEXT.ADRESSE')); ?></th><th style="width:13%;"><?php echo mw_e(mo_t('TEXT.BENUTZER')); ?></th><th style="width:13%;"><?php echo mw_e(mo_t('TEXT.PASSWORT')); ?></th><th style="width:110px;"><?php echo mw_e(mo_t('TEXT.MESSER_IV_KURZ')); ?></th><th style="width:110px;"><?php echo mw_e(mo_t('TEXT.MESSER_NP_KURZ')); ?></th><th style="width:70px;"><?php echo mw_e(mo_t('TEXT.LOESCHEN')); ?></th></tr>
<?php
/* Vorhandene Zeilen plus EINE leere zum Anlegen - hoechstens mo_max_maeher().
   Der Index steht ausgeschrieben, siehe die Begruendung am Speicher-Handler. */
$mw_zeilen = is_array($mw_cfg['mowers']) ? array_values($mw_cfg['mowers']) : array();
$mw_anz = min(mo_max_maeher(), count($mw_zeilen) + 1);
/* X-2: nach einer Beanstandung so viele Zeilen, wie eingetippt wurden. */
if (mw_x2('einst')) {
    foreach (array_keys($mw_eingaben['werte']) as $mw_ek) {
        if (preg_match('/^m_[a-z]+\[([0-9])\]$/', (string) $mw_ek, $mw_em)) {
            $mw_anz = max($mw_anz, min(mo_max_maeher(), (int) $mw_em[1] + 1));
        }
    }
}
for ($mw_i = 0; $mw_i < $mw_anz; $mw_i++) {
    $mw_r = isset($mw_zeilen[$mw_i]) ? (array) $mw_zeilen[$mw_i] : array();
    $mw_r += array('name' => '', 'ip' => '', 'user' => '', 'pass' => '',
                   'blade_hours' => '', 'blade_base' => '');
    $mw_leer = ($mw_r['ip'] === '');
?>
<tr>
<td><?php echo $mw_i + 1; ?></td>
<td><input data-role="none" type="text" name="m_name[<?php echo (int) $mw_i; ?>]"<?php echo mw_falsch('m_name[' . $mw_i . ']'); ?> value="<?php echo mw_e(mw_wert('einst', 'm_name[' . $mw_i . ']', $mw_r['name'])); ?>" placeholder="<?php echo $mw_leer ? mw_e(mo_t('TEXT.PH_NAME')) : ''; ?>"></td>
<td><input data-role="none" type="text" name="m_ip[<?php echo (int) $mw_i; ?>]"<?php echo mw_falsch('m_ip[' . $mw_i . ']'); ?> value="<?php echo mw_e(mw_wert('einst', 'm_ip[' . $mw_i . ']', $mw_r['ip'])); ?>" placeholder="<?php echo $mw_leer ? mw_e(mo_t('TEXT.PH_IP')) : ''; ?>"></td>
<td><input data-role="none" type="text" name="m_user[<?php echo (int) $mw_i; ?>]"<?php echo mw_falsch('m_user[' . $mw_i . ']'); ?> value="<?php echo mw_e(mw_wert('einst', 'm_user[' . $mw_i . ']', $mw_r['user'])); ?>" placeholder="admin"></td>
<td><input data-role="none" type="password" name="m_pass[<?php echo (int) $mw_i; ?>]"<?php echo mw_falsch('m_pass[' . $mw_i . ']'); ?> value="" placeholder="<?php echo $mw_r['pass'] !== '' ? mw_e(mo_t('TEXT.PH_GESPEICHERT')) : ''; ?>" autocomplete="new-password"></td>
<td><input data-role="none" type="number" name="m_bhours[<?php echo (int) $mw_i; ?>]"<?php echo mw_falsch('m_bhours[' . $mw_i . ']'); ?> value="<?php echo mw_e(mw_wert('einst', 'm_bhours[' . $mw_i . ']', (string) $mw_r['blade_hours'])); ?>" min="1" max="2000" placeholder="<?php echo (int) $mw_cfg['blade_hours']; ?>"></td>
<td><input data-role="none" type="number" name="m_bbase[<?php echo (int) $mw_i; ?>]"<?php echo mw_falsch('m_bbase[' . $mw_i . ']'); ?> value="<?php echo mw_e(mw_wert('einst', 'm_bbase[' . $mw_i . ']', (string) $mw_r['blade_base'])); ?>" min="0" max="100000" placeholder="<?php echo (int) $mw_cfg['blade_base']; ?>"></td>
<td style="text-align:center;"><?php if (!$mw_leer) { ?><input data-role="none" type="checkbox" name="m_del[<?php echo (int) $mw_i; ?>]" value="1"<?php echo mw_haken('einst', 'm_del[' . $mw_i . ']', false) ? ' checked' : ''; ?> title="<?php echo mw_e(mo_t('TEXT.LOESCHEN_HILFE')); ?>"><?php } ?></td>
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
        <input data-role="none" type="number" name="cache_sec"<?php echo mw_falsch('cache_sec'); ?> value="<?php echo mw_e(mw_wert('einst', 'cache_sec', (int) $mw_cfg['cache_sec'])); ?>" min="5" max="300">
        <div class="sm-small"><?php echo mw_e(sprintf(mo_t('TEXT.EMPFEHLUNG_20_EINE_LOXONE_ABFRAGE_'), mo_polling())); ?></div>
    </div>
    <div>
        <label><?php echo mw_e(mo_t('TEXT.MESSERWECHSEL_INTERVALL_BETRIEBSST')); ?></label>
        <input data-role="none" type="number" name="blade_hours"<?php echo mw_falsch('blade_hours'); ?> value="<?php echo mw_e(mw_wert('einst', 'blade_hours', (int) $mw_cfg['blade_hours'])); ?>" min="1" max="2000">
        <div class="sm-small"><?php echo mw_e(mo_t('TEXT.HERSTELLERANGABE_OFT_150250_H')); ?>
             &mdash; <?php echo mw_e(mo_t('TEXT.MESSER_VORGABE')); ?></div>
    </div>
    <div>
        <label><?php echo mw_e(mo_t('TEXT.NULLPUNKT_STUNDEN_BEIM_LETZTEN_WEC')); ?></label>
        <input data-role="none" type="number" name="blade_base"<?php echo mw_falsch('blade_base'); ?> value="<?php echo mw_e(mw_wert('einst', 'blade_base', (int) $mw_cfg['blade_base'])); ?>" min="0" max="100000">
        <div class="sm-small"><?php echo mo_t('TEXT.WIRD_BEIM_QUITTIEREN_AUTOMATISCH_G'); ?> <span class="sm-mono">?cmd=blade_reset</span>).
             &mdash; <?php echo mw_e(mo_t('TEXT.MESSER_VORGABE')); ?></div>
    </div>
</div>

<h2><?php echo mw_e(mo_t('TEXT.MELDUNGEN')); ?></h2>
<div style="margin-bottom:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="notify_audio"<?php echo mw_haken('einst', 'notify_audio', $mw_notify['audio']) ? ' checked' : ''; ?>> <?php echo mw_e(mo_t('TEXT.AUDIOAUSGABE_AKTIV')); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_push"<?php echo mw_haken('einst', 'notify_push', $mw_notify['push']) ? ' checked' : ''; ?>> <?php echo mw_e(mo_t('TEXT.PUSH_NACHRICHT_AKTIV')); ?>
    </label>
    <div class="sm-small"><?php echo mo_t('TEXT.DIE_ANSAGE_SPRICHT_DAS_PLUGIN_SELB'); ?> <span class="sm-mono">ANN=1</span>.</div>
</div>
<div>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="n_fehler"<?php echo mw_haken('einst', 'n_fehler', $mw_notify['fehler']) ? ' checked' : ''; ?>> <?php echo mw_e(mo_t('TEXT.STRUNG_SCHLEIFENSIGNAL_VERLOREN')); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="n_fertig"<?php echo mw_haken('einst', 'n_fertig', $mw_notify['fertig']) ? ' checked' : ''; ?>> <?php echo mw_e(mo_t('TEXT.MHEN_BEENDET')); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="n_messer"<?php echo mw_haken('einst', 'n_messer', $mw_notify['messer']) ? ' checked' : ''; ?>> <?php echo mw_e(mo_t('TEXT.MESSERWECHSEL_FLLIG')); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="n_akku"<?php echo mw_haken('einst', 'n_akku', $mw_notify['akku']) ? ' checked' : ''; ?>> <?php echo mw_e(mo_t('TEXT.AKKU_UNTER_20_AUERHALB_DER_STATION')); ?>
    </label>
</div>

<h2><?php echo mw_e(mo_t('TEXT.H_STATISTIK')); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="stat_ein"<?php echo mw_haken('einst', 'stat_ein', $mw_cfg['stat_ein']) ? ' checked' : ''; ?>> <?php echo mw_e(mo_t('TEXT.STAT_EIN')); ?>
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
        <?php $mw_modus = mw_wert('einst', 'tts_mode', $mw_tts['mode']); ?>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="mwTtsMode()"<?php echo mw_falsch('tts_mode'); ?>>
            <option value="musicserver"<?php echo $mw_modus === 'musicserver' ? ' selected' : ''; ?>><?php echo mw_e(mo_t('TEXT.LOXONE_MUSIC_SERVER_KLASSISCH')); ?></option>
            <option value="ms4h"<?php echo $mw_modus === 'ms4h' ? ' selected' : ''; ?>><?php echo mw_e(mo_t('TEXT.AUDIOSERVER4HOME_MUSICSERVER4HOME')); ?></option>
            <option value="audioserver"<?php echo $mw_modus === 'audioserver' ? ' selected' : ''; ?>><?php echo mw_e(mo_t('TEXT.ORIGINAL_LOXONE_AUDIOSERVER_VIA_LO')); ?></option>
            <option value="custom"<?php echo $mw_modus === 'custom' ? ' selected' : ''; ?>><?php echo mw_e(mo_t('TEXT.EIGENE_URL_VORLAGE')); ?></option>
            <option value="alexang"<?php echo $mw_modus === 'alexang' ? ' selected' : ''; ?>><?php echo mw_e(mo_t('TEXT.ALEXA_NG_AUSGABE')); ?></option>
        </select>
    </div>
    <div>
        <label><?php echo mw_e(mo_t('TEXT.IP_DES_AUDIO_SERVERS')); ?></label>
        <input data-role="none" type="text" name="tts_ip"<?php echo mw_falsch('tts_ip'); ?> value="<?php echo mw_e(mw_wert('einst', 'tts_ip', $mw_tts['ip'])); ?>" placeholder="<?php echo mw_e(mo_t('TEXT.PH_TTS_IP')); ?>">
    </div>
    <div>
        <label><?php echo mw_e(mo_t('TEXT.PORT')); ?></label>
        <input data-role="none" type="number" name="tts_port"<?php echo mw_falsch('tts_port'); ?> value="<?php echo mw_e(mw_wert('einst', 'tts_port', (int) $mw_tts['port'])); ?>" min="1" max="65535">
    </div>
</div>
<div class="sm-row">
    <div>
        <label><?php echo mw_e(mo_t('TEXT.ZONEN')); ?></label>
        <input data-role="none" type="text" name="tts_zones"<?php echo mw_falsch('tts_zones'); ?> value="<?php echo mw_e(mw_wert('einst', 'tts_zones', $mw_tts['zones'])); ?>" placeholder="2,4,6">
        <div class="sm-small"><?php echo mo_t('TEXT.ZONEN_HILFE'); ?></div>
    </div>
    <div>
        <label><?php echo mw_e(mo_t('TEXT.LAUTSTRKE')); ?></label>
        <input data-role="none" type="number" name="tts_volume"<?php echo mw_falsch('tts_volume'); ?> value="<?php echo mw_e(mw_wert('einst', 'tts_volume', (int) $mw_tts['volume'])); ?>" min="1" max="100">
    </div>
    <div>
        <label><?php echo mw_e(mo_t('TEXT.SPRACHE')); ?></label>
        <input data-role="none" type="text" name="tts_lang"<?php echo mw_falsch('tts_lang'); ?> value="<?php echo mw_e(mw_wert('einst', 'tts_lang', $mw_tts['lang'])); ?>" maxlength="5">
    </div>
</div>
<div id="tts_template_row">
    <label><?php echo mw_e(mo_t('TEXT.URL_VORLAGE_FR_AUDIOSERVER4HOME_MS')); ?></label>
    <textarea data-role="none" name="tts_template" id="tts_template" rows="2"<?php echo mw_falsch('tts_template'); ?> placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?php echo mw_e(mw_wert('einst', 'tts_template', $mw_tts['template'])); ?></textarea>
    <div class="sm-small"><?php echo mw_e(mo_t('TEXT.PLATZHALTER')); ?> <span class="sm-mono"><?php echo mw_e(mo_t('TEXT.IP_PORT_ZONES_VOL_LANG_TEXT')); ?></span><?php echo mw_e(mo_t('TEXT.LEER_STANDARD_VORLAGE')); ?></div>
</div>
<?php /* C9 (Ansage-2, Durchgang 01.10.2026): die Felder fuer Alexa-NG. Das
       * Sprechtoken ist ein Kennwortfeld: nie angezeigt, leer lassen behaelt
       * es, der Haken loescht es; es reist nie in die Einmalmeldung. */
    $mw_atl = (string) mw_wert('einst', 'tts_alexa_laut', (int) $mw_tts['alexa_laut'] >= 0 ? (int) $mw_tts['alexa_laut'] : ''); ?>
<div id="tts_alexa_row" style="<?php echo $mw_modus === 'alexang' ? '' : 'display:none;'; ?>">
<div class="sm-hinweis"><?php echo mo_t('TEXT.ALEXA_HINWEIS'); ?></div>
<div class="sm-row">
    <div>
        <label><?php echo mw_e(mo_t('TEXT.ALEXA_GERAET')); ?></label>
        <input data-role="none" type="text" name="tts_alexa_geraet"<?php echo mw_falsch('tts_alexa_geraet'); ?> value="<?php echo mw_e(mw_wert('einst', 'tts_alexa_geraet', $mw_tts['alexa_geraet'])); ?>" placeholder="<?php echo mw_e(mo_t('TEXT.ALEXA_GERAET_PH')); ?>">
    </div>
    <div>
        <label><?php echo mw_e(mo_t('TEXT.ALEXA_LAUT')); ?></label>
        <input data-role="none" type="number" name="tts_alexa_laut"<?php echo mw_falsch('tts_alexa_laut'); ?> value="<?php echo mw_e($mw_atl); ?>" min="0" max="100" placeholder="<?php echo mw_e(mo_t('TEXT.ALEXA_LAUT_PH')); ?>">
    </div>
    <div>
        <label><?php echo mw_e(mo_t('TEXT.ALEXA_TOKEN')); ?></label>
        <input data-role="none" type="password" name="tts_alexa_token"<?php echo mw_falsch('tts_alexa_token'); ?> value="" autocomplete="new-password" placeholder="<?php echo (string) $mw_tts['alexa_token'] !== '' ? mw_e(sprintf(mo_t('TEXT.ALEXA_TOKEN_GESPEICHERT'), strlen((string) $mw_tts['alexa_token']))) : ''; ?>">
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;"><input data-role="none" type="checkbox" name="tts_alexa_loeschen" value="1"<?php echo mw_haken('einst', 'tts_alexa_loeschen', false) ? ' checked' : ''; ?>> <?php echo mw_e(mo_t('TEXT.ALEXA_TOKEN_LOESCHEN')); ?></label>
    </div>
</div>
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
<?php if ($mw_sich_mangel) { ?>
<div class="sm-alert sm-warn"><?php echo sprintf(mo_t('TEXT.SICH_X3_WARNUNG'), mw_e(implode(', ', $mw_sich_mangel))); ?></div>
<?php } ?>
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
<?php /* U6 (Durchgang 01.10.2026): "Es wird gesendet" nur, wenn dieses Plugin sendet. */
if (!empty($mw_cfg['mqtt_enabled']) && mo_mqtt_gateway_autostart() === false) { ?>
<div class="sm-warnung"><b>MQTT:</b> <?php echo mo_t('TEXT.W_AUTOSTART'); ?></div>
<?php } ?>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="mqtt_enabled"<?php echo mw_haken('mqtt', 'mqtt_enabled', $mw_cfg['mqtt_enabled']) ? ' checked' : ''; ?>> <?php echo mw_e(mo_t('TEXT.ZUSTAND_PER_MQTT_VERFFENTLICHEN')); ?>
</label>
<div class="sm-feld" style="margin-top:6px;max-width:520px;">
    <label><?php echo mw_e(mo_t('TEXT.TOPIC_PRFIX')); ?></label>
    <input data-role="none" type="text" name="mqtt_topic"<?php echo mw_falsch('mqtt_topic'); ?> value="<?php echo mw_e(mw_wert('mqtt', 'mqtt_topic', $mw_cfg['mqtt_topic'])); ?>" placeholder="maeher">
</div>
<div class="sm-hilfe"><?php echo mo_t('TEXT.MQTT_WECHSEL_HILFE'); ?></div>
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
/* M7 (Durchgang 01.10.2026): traegt die Abodatei des Plugins das Abo, ist
 * unter Gateway V1 nichts von Hand einzutragen. */
list(, $mw_abo_da) = mo_abo_datei($mw_praefix);
$mw_abo_klasse = ($mw_gwf >= 2 || $mw_abo_da) ? 'sm-hinweis' : 'sm-warnung';
$mw_abo_html = ($mw_abo_da && $mw_gwf < 2) ? mo_t('TEXT.ABO_DATEI') : mo_abo_text();
?>
<h2><?php echo mw_e(mo_t('TEXT.H_ABO')); ?></h2>
<div class="<?php echo $mw_abo_klasse; ?>">
<b><?php echo mw_e(mo_t('TEXT.ABO_TITEL')); ?></b><br>
<span class="sm-mono"><?php echo mw_e($mw_praefix); ?>/#</span><br>
<?php echo $mw_abo_html; ?>
</div>

<h2><?php echo mw_e(mo_t('TEXT.H_THEMEN')); ?></h2>
<div class="sm-hilfe"><?php echo mo_t('TEXT.THEMEN_HILFE'); ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<?php /* U7 (Durchgang 01.10.2026, Entscheidung Nr. 3): die Spalte "retained" kommt
       * aus mo_mqtt_retain() - derselben Quelle wie der Sendecode. */ ?>
<tr><th style="width:38%;"><?php echo mw_e(mo_t('TEXT.THEMA')); ?></th><th style="width:90px;"><?php echo mw_e(mo_t('TEXT.RETAINED')); ?></th><th><?php echo mw_e(mo_t('TEXT.BEDEUTUNG')); ?></th></tr>
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
    } elseif ($mw_th === 'maeherstatus') {
        $mw_bed = mo_t('TEXT.TH_MAEHERSTATUS');
    } elseif ($mw_gross !== '' && isset($mw_felder[$mw_gross])) {
        $mw_bed = mo_feld_text($mw_gross);
    } else {
        $mw_bed = sprintf(mo_t('TEXT.TH_OHNE'), $mw_th);
    }
?>
<tr><td><span class="sm-mono"><?php echo mw_e($mw_praefix); ?>/<?php echo mw_e($mw_th); ?></span></td><td><?php echo mw_e(mo_t(mo_mqtt_retain($mw_th) ? 'TEXT.JA' : 'TEXT.NEIN')); ?></td><td><?php echo mw_e($mw_bed); ?></td></tr>
<?php } ?>
<?php foreach ($mw_themen['anlage'] as $mw_th) { ?>
<tr><td><span class="sm-mono"><?php echo mw_e($mw_praefix); ?>/<?php echo mw_e($mw_th); ?></span></td><td><?php echo mw_e(mo_t(mo_mqtt_retain($mw_th) ? 'TEXT.JA' : 'TEXT.NEIN')); ?></td><td><?php echo mw_e(mo_t('TEXT.TH_' . strtoupper(str_replace('status/', '', $mw_th)))); ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?php echo sprintf(mo_t('TEXT.THEMEN_MEHRERE'), mo_max_maeher()); ?></div>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-seite<?php echo $mw_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" id="tab-loxone">
<h2><?php echo mw_e(mo_t('TEXT.EINBINDUNG_IN_LOXONE_SCHRITT_FR_SC')); ?></h2>
<?php /* A30b (06.09.2026): hier standen ZWEI Legenden im selben Reiter, jede
       * unvollstaendig - eine beim Token (nur Aktion), eine bei der Vorlage
       * (nur Technik). Die uebrigen vier Reiter fuehren je eine. Jetzt eine
       * gesammelte, oben, mit beiden Punkten. */ ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?php echo mw_e(mo_t('LEGENDE.TECHNIK')); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo mw_e(mo_t('LEGENDE.AKTION')); ?></span>
</div>
<p><?php echo mo_t('TEXT.DER_MINISERVER_FRAGT'); ?> <b><?php echo mw_e(mo_t('TEXT.EINE')); ?></b> <?php echo mo_t('TEXT.ADRESSE_OHNE_ZUGANGSDATEN_AB_UND_B'); ?></p>

<div class="sm-step"><b><?php echo mw_e(mo_t('TEXT.SCHRITT_1_VIRTUELLER_HTTP_EINGANG_')); ?></b> <?php echo mw_e(sprintf(mo_t('TEXT.ABFRAGE_ALLE_S'), mo_polling())); ?>
<table class="sm-tbl">
<tr><th><?php echo mw_e(mo_t('TEXT.EIGENSCHAFT')); ?></th><th><?php echo mw_e(mo_t('TEXT.WERT')); ?></th></tr>
<?php /* U9 (Durchgang 01.10.2026): je eingerichtetem Maeher eine Adresse. */
$mw_ml = mo_mowers();
if (!$mw_ml) { ?>
<tr><td>URL</td><td><span class="sm-mono">http://<?php echo $mw_host; ?>/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php</span></td></tr>
<?php }
foreach ($mw_ml as $mw_mn => $mw_mm) { ?>
<tr><td>URL <?php echo mw_e($mw_mm['name']); ?></td><td><span class="sm-mono">http://<?php echo $mw_host; ?>/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php<?php echo $mw_mn > 1 ? '?dev=' . (int) $mw_mn : ''; ?></span></td></tr>
<?php } ?>
<tr><td><?php echo mw_e(mo_t('TEXT.ABFRAGEZYKLUS')); ?></td><td><?php echo mw_e(sprintf(mo_t('TEXT.SEKUNDEN_N'), mo_polling())); ?></td></tr>
</table>
<span class="sm-small"><?php echo mo_t('TEXT.DER_BISHERIGE_EINGANG_MIT'); ?> <span class="sm-mono">?user=...&amp;pass=...</span> <?php echo mw_e(mo_t('TEXT.KANN_DANACH_GELSCHT_WERDEN')); ?></span>
</div>

<div class="sm-step"><b><?php echo mw_e(mo_t('TEXT.SCHRITT_2_ABO')); ?></b><br>
<?php echo mo_t('TEXT.SCHRITT_2_ABO_TEXT'); ?>
<div class="<?php echo $mw_abo_klasse; ?>">
<span class="sm-mono"><?php echo mw_e($mw_praefix); ?>/#</span><br>
<?php echo $mw_abo_html; ?>
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
<?php
/* U9 (Durchgang 01.10.2026, gemessen): bis 1.1.14 standen hier nur die
 * Adressen fuer Maeher 1. Jetzt je eingerichtetem Maeher eine Tabelle; ab
 * Maeher 2 mit &dev=<n>. */
$mw_bml = mo_mowers();
if (!$mw_bml) { $mw_bml = array(1 => array('name' => '')); }
foreach ($mw_bml as $mw_bn => $mw_bm) { ?>
<?php if (count($mw_bml) > 1) { ?><div class="sm-h3"><?php echo mw_e(sprintf(mo_t('TEXT.BEFEHLE_FUER'), (int) $mw_bn, $mw_bm['name'])); ?></div><?php } ?>
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
<tr><td><span class="sm-mono">/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?cmd=<?php echo mw_e($mw_b); ?><?php echo $mw_bn > 1 ? '&amp;dev=' . (int) $mw_bn : ''; ?>&amp;token=<?php echo mw_e($mw_cfg['aktionstoken']); ?></span></td><td><?php echo mw_e($mw_bt); ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<div class="sm-hilfe"><?php echo mo_t('TEXT.GLEICHWERT_HILFE'); ?></div>
<div class="sm-warnung"><?php echo mo_t('TEXT.TOKEN_NOETIG'); ?></div>
</div>

<div class="sm-step"><b><?php echo mw_e(mo_t('TEXT.H_TOKEN')); ?></b>
<table class="sm-tbl">
<tr><th><?php echo mw_e(mo_t('TEXT.EIGENSCHAFT')); ?></th><th><?php echo mw_e(mo_t('TEXT.WERT')); ?></th></tr>
<tr><td><?php echo mw_e(mo_t('TEXT.AKTUELLES_TOKEN')); ?></td><td><span class="sm-mono"><?php echo mw_e($mw_cfg['aktionstoken']); ?></span></td></tr>
</table>
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
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <?php echo mo_fmt_feld(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <?php $mw_vml = mo_mowers(); if (count($mw_vml) > 1) { ?>
  <label style="display:inline-flex;align-items:center;gap:6px;margin-right:10px;">
      <?php echo mw_e(mo_t('TEXT.VORLAGE_FUER')); ?>
      <select data-role="none" name="vorlage_dev">
      <?php foreach ($mw_vml as $mw_vn => $mw_vm) { ?>
          <option value="<?php echo (int) $mw_vn; ?>"><?php echo (int) $mw_vn; ?>: <?php echo mw_e($mw_vm['name']); ?></option>
      <?php } ?>
      </select>
  </label>
  <?php } else { ?>
  <input data-role="none" type="hidden" name="vorlage_dev" value="1">
  <?php } ?>
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="1"><?php echo mw_e(mo_t('TEXT.K_VORLAGE')); ?></button>
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage_vo" value="1"><?php echo mw_e(mo_t('TEXT.K_VORLAGE_VO')); ?></button>
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
<?php echo mo_t('TEXT.GEGENPROBE_TEXT'); ?><br>
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
foreach (mo_selbsttest(__FILE__, $mw_reiter, $mw_tab === 'tab-test') as $mw_z) {
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
<?php /* A31 (06.09.2026, gemessen): dieser Knopf verlinkte ohne Token.
       * ?debug=1 ist seit 1.1.4 tokenpflichtig, ?refresh=1 seit 1.1.6 - der
       * Knopf lieferte also DEBUG;OK=0;ERR=TOKEN mit HTTP 403, waehrend
       * jeder andere Verweis im Reiter das Token traegt. */ ?>
<a data-role="none" class="sm-btn sm-b-technik" href="/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?debug=1&amp;refresh=1&amp;token=<?php echo mw_e($mw_cfg['aktionstoken']); ?>" target="_blank"><?php echo mw_e(mo_t('TEXT.DEBUG')); ?></a>
<a data-role="none" class="sm-btn sm-b-technik" href="/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?selftest=1&amp;token=<?php echo mw_e($mw_cfg['aktionstoken']); ?>" target="_blank"><?php echo mw_e(mo_t('TEXT.K_SELFTEST')); ?></a>
<?php /* U8 (Durchgang 01.10.2026, Regeln/04): der Trockenlauf sendet nichts - grau und
       * hier, nicht orange einen Fingerbreit neben dem echten "Automatik". */ ?>
<a data-role="none" class="sm-btn sm-b-technik" href="/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?cmd=auto&amp;probe=1&amp;token=<?php echo mw_e($mw_cfg['aktionstoken']); ?>" target="_blank"><?php echo mw_e(mo_t('TEXT.K_TROCKEN')); ?></a>
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
<a data-role="none" class="sm-btn sm-b-aktion" href="/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?cmd=auto&amp;token=<?php echo mw_e($mw_cfg['aktionstoken']); ?>" target="_blank"><?php echo mw_e(mo_t('TEXT.AUTOMATIK')); ?></a>
<a data-role="none" class="sm-btn sm-b-aktion" href="/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?cmd=home&amp;token=<?php echo mw_e($mw_cfg['aktionstoken']); ?>" target="_blank"><?php echo mw_e(mo_t('TEXT.NACH_HAUSE')); ?></a>
<a data-role="none" class="sm-btn sm-b-aktion" href="/plugins/<?php echo mw_e($mw_plugin); ?>/mower.php?cmd=stop&amp;token=<?php echo mw_e($mw_cfg['aktionstoken']); ?>" target="_blank"><?php echo mw_e(mo_t('TEXT.STOPP')); ?></a>
<form action="index.php" method="post">
  <?php echo mo_fmt_feld(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="tts_test" value="1"><?php echo mw_e(mo_t('TEXT.K_TESTANSAGE')); ?></button>
</form>
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
<div class="sm-small" style="margin-bottom:8px;"><?php echo mo_t('TEXT.PROTOKOLLIERT_WERDEN_STATUSNDERUNG'); ?><br><?php echo mo_t('TEXT.LOG_RAMDISK'); ?><br><?php echo mw_e(mo_t('TEXT.DATEI')); ?> <span class="sm-mono"><?php echo mw_e($mw_logfile); ?></span></div>
<?php if ($mw_loglines) { ?>
<div class="sm-log"><?php echo mw_e(implode("\n", $mw_loglines)); ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo mw_e(mo_t('TEXT.NOCH_KEINE_PROTOKOLL_EINTRGE_VORHA')); ?>
<?php /* U10 (Durchgang 01.10.2026, Regeln/04): protokolliert wird nur bei Wechseln,
       * und die Protokollwartung von LoxBerry raeumt die Datei ab - ein leerer
       * Reiter ist kein Fehler. Der letzte Lauf steht in data/.../lauf.json. */ ?>
<br><?php echo mw_e($mw_lauf['ts'] > 0
    ? sprintf(mo_t('TEXT.LOG_LEER_LAUF'), date('d.m.Y H:i:s', (int) $mw_lauf['ts']), (int) $mw_lauf['zaehler'])
    : mo_t('TEXT.PRUEF_CRON_NIE')); ?>
<br><?php echo mw_e(mo_t('TEXT.LOG_LEER_WARTUNG')); ?></div>
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
    var a = document.getElementById('tts_alexa_row');
    if (a) { a.style.display = (m === 'alexang') ? 'block' : 'none'; }
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
