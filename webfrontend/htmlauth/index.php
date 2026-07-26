<?php
/**
 * Rasenmaeher (Robonect) - Admin-Oberflaeche (v1.0.0)
 * Reiter: Einstellungen | Einbindung in Loxone | Test | Protokoll
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-GLOBALS (u.a. $cfg aus general.json als
 * stdClass) und wuerde gleichnamige Plugin-Variablen ueberschreiben - daher
 * tragen hier ALLE Variablen ein mw_-Praefix.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

$mw_lbhome = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
$mw_plugin = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
if ($mw_lbhome && is_dir($mw_lbhome . '/config/plugins/' . $mw_plugin) === false) {
    $mw_plugin = basename(dirname(__DIR__));
    if (is_dir($mw_lbhome . '/config/plugins/' . $mw_plugin) === false) { $mw_plugin = 'robonect'; }
}
if ($mw_lbhome) {
    $mw_sdk = $mw_lbhome . '/libs/phplib/loxberry_system.php';
    if (file_exists($mw_sdk)) { require_once $mw_sdk; require_once $mw_lbhome . '/libs/phplib/loxberry_web.php'; }
    $mw_cfgdir = $mw_lbhome . '/config/plugins/' . $mw_plugin;
    $mw_bkfile = $mw_lbhome . '/config/plugins/' . $mw_plugin . '.backup.json';
    $mw_logfile = $mw_lbhome . '/log/plugins/' . $mw_plugin . '/mower.log';
} else {
    $mw_cfgdir = dirname(dirname(__DIR__)) . '/config';
    $mw_bkfile = $mw_cfgdir . '/mower.backup.json';
    $mw_logfile = sys_get_temp_dir() . '/robonect/mower.log';
}
$mw_cfgfile = $mw_cfgdir . '/mower.json';

foreach (array(dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . $mw_plugin . '/mower_lib.php',
               dirname(__DIR__) . '/html/mower_lib.php') as $mw_cand) {
    if (is_file($mw_cand)) { require_once $mw_cand; break; }
}

if ((!is_file($mw_cfgfile) || trim((string) @file_get_contents($mw_cfgfile)) === '' || trim((string) @file_get_contents($mw_cfgfile)) === '{}') && is_file($mw_bkfile)) {
    @mkdir($mw_cfgdir, 0775, true);
    @copy($mw_bkfile, $mw_cfgfile);
    @chmod($mw_cfgfile, 0600);
}

$mw_saved = false; $mw_err = ''; $mw_note = '';
$mw_tab = preg_match('/^tab-(settings|loxone|test|log)$/', (string) (isset($_POST['activetab']) ? $_POST['activetab'] : '')) ? $_POST['activetab'] : 'tab-settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    @mkdir(dirname($mw_logfile), 0775, true);
    @file_put_contents($mw_logfile, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Admin-Oberflaeche)\n");
    $mw_tab = 'tab-log';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bladereset']) && function_exists('mo_blade_reset')) {
    mo_blade_reset(1);
    $mw_note = 'Messerwechsel quittiert &mdash; die Restlaufzeit startet neu.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $mw_old = function_exists('mo_config') ? mo_config() : array('mowers' => array());
    $mw_new = array();
    $mw_new['mowers'] = array();
    $mw_n = isset($_POST['m_name']) ? (array) $_POST['m_name'] : array();
    $mw_i2 = isset($_POST['m_ip']) ? (array) $_POST['m_ip'] : array();
    $mw_u2 = isset($_POST['m_user']) ? (array) $_POST['m_user'] : array();
    $mw_p2 = isset($_POST['m_pass']) ? (array) $_POST['m_pass'] : array();
    for ($mw_i = 0; $mw_i < 2; $mw_i++) {
        $ip = trim((string) (isset($mw_i2[$mw_i]) ? $mw_i2[$mw_i] : ''));
        if ($ip === '') { continue; }
        if (!preg_match('/^[\w\.\-]+$/', $ip)) { $mw_err = 'M&auml;her ' . ($mw_i + 1) . ': ung&uuml;ltige Adresse.'; continue; }
        $pw = (string) (isset($mw_p2[$mw_i]) ? $mw_p2[$mw_i] : '');
        // Leeres Passwortfeld = bisheriges Passwort behalten (es wird nie angezeigt)
        if ($pw === '' && isset($mw_old['mowers'][$mw_i]['pass'])) { $pw = (string) $mw_old['mowers'][$mw_i]['pass']; }
        $mw_new['mowers'][] = array('name' => trim((string) (isset($mw_n[$mw_i]) ? $mw_n[$mw_i] : '')),
            'ip' => $ip, 'user' => trim((string) (isset($mw_u2[$mw_i]) ? $mw_u2[$mw_i] : '')), 'pass' => $pw);
    }
    $mw_new['cache_sec'] = max(5, min(300, (int) (isset($_POST['cache_sec']) ? $_POST['cache_sec'] : 20)));
    $mw_new['blade_hours'] = max(1, min(2000, (int) (isset($_POST['blade_hours']) ? $_POST['blade_hours'] : 200)));
    $mw_new['blade_base'] = max(0, min(100000, (int) (isset($_POST['blade_base']) ? $_POST['blade_base'] : 0)));
    $mw_new['mqtt_enabled'] = isset($_POST['mqtt_enabled']) ? 1 : 0;
    $mw_new['mqtt_topic'] = preg_replace('#[^\w/\-]#', '', (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : 'maeher')) ?: 'maeher';
    $mw_new['notify'] = array(
        'audio' => isset($_POST['notify_audio']) ? 1 : 0,
        'push' => isset($_POST['notify_push']) ? 1 : 0,
        'fehler' => isset($_POST['n_fehler']) ? 1 : 0,
        'fertig' => isset($_POST['n_fertig']) ? 1 : 0,
        'messer' => isset($_POST['n_messer']) ? 1 : 0,
        'akku' => isset($_POST['n_akku']) ? 1 : 0,
    );
    $mw_mode = (string) (isset($_POST['tts_mode']) ? $_POST['tts_mode'] : 'musicserver');
    $mw_new['tts'] = array(
        'mode' => in_array($mw_mode, array('musicserver', 'ms4h', 'audioserver', 'custom'), true) ? $mw_mode : 'musicserver',
        'ip' => trim((string) (isset($_POST['tts_ip']) ? $_POST['tts_ip'] : '')),
        'port' => max(1, min(65535, (int) (isset($_POST['tts_port']) ? $_POST['tts_port'] : 7091))),
        'zones' => trim((string) (isset($_POST['tts_zones']) ? $_POST['tts_zones'] : '1')),
        'volume' => max(1, min(100, (int) (isset($_POST['tts_volume']) ? $_POST['tts_volume'] : 8))),
        'lang' => preg_replace('/[^a-z]/', '', strtolower((string) (isset($_POST['tts_lang']) ? $_POST['tts_lang'] : 'de'))) ?: 'de',
        'template' => trim((string) (isset($_POST['tts_template']) ? $_POST['tts_template'] : '')),
    );
    if ($mw_err === '') {
        if (!is_dir($mw_cfgdir)) { @mkdir($mw_cfgdir, 0775, true); }
        $mw_json = json_encode($mw_new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($mw_cfgfile, $mw_json) !== false) {
            @chmod($mw_cfgfile, 0600);   // Zugangsdaten nur fuer den LoxBerry-Benutzer lesbar
            $mw_saved = true;
            @copy($mw_cfgfile, $mw_bkfile);
            @chmod($mw_bkfile, 0600);
            foreach (glob('/tmp/robonect/state_*.json') ?: array() as $g) { @unlink($g); }
        } else {
            $mw_err = 'Konfiguration konnte nicht gespeichert werden: ' . $mw_cfgfile;
        }
    }
}

$mw_cfg = function_exists('mo_config') ? mo_config() : array();
if (!is_array($mw_cfg)) { $mw_cfg = array(); }
$mw_cfg += array('mowers' => array(), 'cache_sec' => 20, 'blade_hours' => 200, 'blade_base' => 0,
    'mqtt_enabled' => 0, 'mqtt_topic' => 'maeher', 'notify' => array(), 'tts' => array());
$mw_notify = is_array($mw_cfg['notify']) ? $mw_cfg['notify'] : array();
$mw_notify += array('audio' => 0, 'push' => 0, 'fehler' => 1, 'fertig' => 1, 'messer' => 1, 'akku' => 0);
$mw_tts = is_array($mw_cfg['tts']) ? $mw_cfg['tts'] : array();
$mw_tts += array('mode' => 'musicserver', 'ip' => '', 'port' => 7091, 'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '');
$mw_list = function_exists('mo_mowers') ? mo_mowers() : array();
$mw_states = array();
foreach ($mw_list as $mw_k => $mw_r) { $mw_states[$mw_k] = mo_state($mw_k); }
$mw_loglines = array();
if (is_file($mw_logfile)) {
    $mw_loglines = array_slice(array_reverse(file($mw_logfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()), 0, 300);
}

function mw_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$mw_frame = class_exists('LBWeb', false);
if ($mw_frame) { LBWeb::lbheader('Rasenm&auml;her (Robonect)', 'https://wiki.loxberry.de/', ''); }
$mw_host = mw_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '<loxberry-ip>');
?>
<style>
.mw-wrap { max-width: 940px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.mw-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.mw-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.mw-wrap input[type=text], .mw-wrap input[type=password], .mw-wrap input[type=number], .mw-wrap select, .mw-wrap textarea {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.mw-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.mw-row { display: flex; gap: 12px; flex-wrap: wrap; }
.mw-row > div { flex: 1; min-width: 150px; }
.mw-row > div > label:not([style]) { min-height: 2.6em; display: flex; align-items: flex-end; }
.mw-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.mw-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.mw-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.mw-err { background: #ffebee; border: 1px solid #ef9a9a; }
.mw-warn { background: #fff8e1; border: 1px solid #ffe082; }
.mw-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.mw-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.mw-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.mw-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.mw-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; text-shadow: none !important; }
.mw-tab.mw-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.mw-pane { display: none; padding-top: 4px; }
.mw-pane.mw-active { display: block; }
.mw-log { text-shadow: none !important; background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.mw-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.mw-tbl { border-collapse: collapse; margin: 8px 0; }
.mw-tbl th, .mw-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; }
.mw-tbl th { background: #f0f0f0; }
.mw-wrap .mw-btn, .mw-wrap a.mw-btn, .mw-wrap button { text-shadow: none !important; box-shadow: none !important; }
.mw-wrap a.mw-btn, .mw-wrap a.mw-btn:visited, .mw-wrap a.mw-btn:hover { color: #fff !important; text-decoration: none; }

/* --- Einheitliches Kachel-Raster im Reiter Test (Standard aller Plugins) --- */
.mw-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; text-shadow: none !important; }
.mw-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.mw-knopfreihe form { margin: 0; display: flex; }
.mw-knopfreihe .mw-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; }
.mw-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.mw-legende span { display: inline-flex; align-items: center; gap: 6px; }
.mw-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.mw-btn.mw-b-lesen   { background: #6dac20; }
.mw-btn.mw-b-technik { background: #546e7a; }
.mw-btn.mw-b-aktion  { background: #e0620d; }
.mw-punkt.mw-b-lesen   { background: #6dac20; }
.mw-punkt.mw-b-technik { background: #546e7a; }
.mw-punkt.mw-b-aktion  { background: #e0620d; }
</style>
<div class="mw-wrap">

<?php if ($mw_saved) { ?><div class="mw-alert mw-ok"><b>Konfiguration gespeichert</b> (Zugangsdaten mit Dateirechten 0600, inkl. Sicherungskopie f&uuml;r Updates).</div><?php } ?>
<?php if ($mw_note !== '') { ?><div class="mw-alert mw-ok"><?= $mw_note ?></div><?php } ?>
<?php if ($mw_err !== '') { ?><div class="mw-alert mw-err"><b>Fehler:</b> <?= $mw_err ?></div><?php } ?>

<?php if (!$mw_list) { ?>
<div class="mw-alert mw-info"><b>Noch kein M&auml;her eingerichtet.</b> Bitte unten Adresse, Benutzer und Passwort des Robonect-Moduls eintragen.</div>
<?php } ?>
<?php foreach ($mw_states as $mw_k => $mw_s) { ?>
<div class="mw-alert <?= $mw_s['fehler'] ? 'mw-warn' : 'mw-info' ?>">
<b><?= mw_e($mw_s['name']) ?></b>:
<?php if ($mw_s['ok']) { ?>
<b><?= mw_e($mw_s['text']) ?></b> &middot; Betriebsart <?= mw_e($mw_s['modus_text']) ?> &middot; Akku <?= (int) $mw_s['batterie'] ?> %
<?= $mw_s['fehler'] ? ' &middot; <b>Fehler ' . (int) $mw_s['fehler'] . '</b> ' . mw_e($mw_s['fehlertext']) : '' ?><br>
Betriebsstunden gesamt: <?= (int) $mw_s['stunden'] ?> h<?= $mw_s['dauer'] > 0 ? ' &middot; aktuelle Laufzeit ' . (int) $mw_s['dauer'] . ' min' : '' ?><br>
Messer: <?= $mw_s['messer_rest'] >= 0 ? ($mw_s['messer_warn'] ? '<b>Wechsel f&auml;llig</b>' : 'noch <b>' . (int) $mw_s['messer_rest'] . ' h</b> bis zum Wechsel') : '&ndash;' ?>
&middot; Temperatur <?= mw_e($mw_s['temperatur']) ?> &deg;C &middot; Feuchte <?= mw_e($mw_s['feuchte']) ?> % &middot; WLAN <?= (int) $mw_s['wlan'] ?> dBm
<?php } else { ?>
<b>keine Verbindung</b> &mdash; Adresse und Zugangsdaten pr&uuml;fen (Robonect-Oberfl&auml;che im Browser erreichbar?).
<?php } ?>
</div>
<?php } ?>

<div class="mw-tabs">
    <div class="mw-tab" data-pane="tab-settings">Einstellungen</div>
    <div class="mw-tab" data-pane="tab-loxone">Einbindung in Loxone</div>
    <div class="mw-tab" data-pane="tab-test">Test</div>
    <div class="mw-tab" data-pane="tab-log">Protokoll</div>
</div>

<!-- ================= Einstellungen ================= -->
<div class="mw-pane" id="tab-settings">
<form method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2>M&auml;her (bis zu 2)</h2>
<table class="mw-tbl" style="width:100%;">
<tr><th style="width:36px;">Nr.</th><th style="width:26%;">Name (frei)</th><th>Adresse</th><th style="width:20%;">Benutzer</th><th style="width:22%;">Passwort</th></tr>
<?php for ($mw_i = 0; $mw_i < 2; $mw_i++) {
    $mw_r = isset($mw_cfg['mowers'][$mw_i]) ? (array) $mw_cfg['mowers'][$mw_i] : array();
    $mw_r += array('name' => '', 'ip' => '', 'user' => '', 'pass' => ''); ?>
<tr>
<td><?= $mw_i + 1 ?></td>
<td><input data-role="none" type="text" name="m_name[]" value="<?= mw_e($mw_r['name']) ?>" placeholder="<?= $mw_i === 0 ? 'z. B. Rasenmäher' : 'leer = ungenutzt' ?>"></td>
<td><input data-role="none" type="text" name="m_ip[]" value="<?= mw_e($mw_r['ip']) ?>" placeholder="<?= $mw_i === 0 ? 'z. B. 192.168.1.34' : '' ?>"></td>
<td><input data-role="none" type="text" name="m_user[]" value="<?= mw_e($mw_r['user']) ?>" placeholder="admin"></td>
<td><input data-role="none" type="password" name="m_pass[]" value="" placeholder="<?= $mw_r['pass'] !== '' ? '(gespeichert)' : '' ?>" autocomplete="new-password"></td>
</tr>
<?php } ?>
</table>
<div class="mw-alert mw-ok">Das Passwort wird <b>nie angezeigt</b> und beim Speichern behalten, wenn das Feld leer bleibt.
Es liegt ausschlie&szlig;lich in der Plugin-Konfiguration (Dateirechte 0600) und wird per HTTP-Basic-Auth &uuml;bertragen &mdash;
<b>in der Loxone-Projektdatei steht damit kein Passwort mehr</b>. Genau daf&uuml;r gibt es dieses Plugin.</div>

<div class="mw-row">
    <div>
        <label>Status-Cache (Sekunden)</label>
        <input data-role="none" type="number" name="cache_sec" value="<?= (int) $mw_cfg['cache_sec'] ?>" min="5" max="300">
        <div class="mw-small">Empfehlung 20 &mdash; eine Loxone-Abfrage alle 30 s reicht v&ouml;llig.</div>
    </div>
    <div>
        <label>Messerwechsel-Intervall (Betriebsstunden)</label>
        <input data-role="none" type="number" name="blade_hours" value="<?= (int) $mw_cfg['blade_hours'] ?>" min="1" max="2000">
        <div class="mw-small">Herstellerangabe, oft 150&ndash;250 h.</div>
    </div>
    <div>
        <label>Nullpunkt: Stunden beim letzten Wechsel</label>
        <input data-role="none" type="number" name="blade_base" value="<?= (int) $mw_cfg['blade_base'] ?>" min="0" max="100000">
        <div class="mw-small">Wird beim Quittieren automatisch gesetzt (Knopf unten oder <span class="mw-mono">?cmd=blade_reset</span>).</div>
    </div>
</div>

<h2>Meldungen</h2>
<div style="margin-bottom:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="notify_audio" <?= !empty($mw_notify['audio']) ? 'checked' : '' ?>> Audioausgabe aktiv
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_push" <?= !empty($mw_notify['push']) ? 'checked' : '' ?>> Push-Nachricht aktiv
    </label>
    <div class="mw-small">Die Ansage spricht das Plugin selbst; den Push verschickt der Miniserver &uuml;ber <span class="mw-mono">ANN=1</span>.</div>
</div>
<div>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="n_fehler" <?= !empty($mw_notify['fehler']) ? 'checked' : '' ?>> St&ouml;rung / Schleifensignal verloren
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="n_fertig" <?= !empty($mw_notify['fertig']) ? 'checked' : '' ?>> M&auml;hen beendet
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="n_messer" <?= !empty($mw_notify['messer']) ? 'checked' : '' ?>> Messerwechsel f&auml;llig
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="n_akku" <?= !empty($mw_notify['akku']) ? 'checked' : '' ?>> Akku unter 20 % au&szlig;erhalb der Station
    </label>
</div>

<h2>Sprachausgabe</h2>
<div class="mw-row">
    <div>
        <label>Audio-Ausgabe</label>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="mwTtsMode()">
            <option value="musicserver"<?= $mw_tts['mode'] === 'musicserver' ? ' selected' : '' ?>>Loxone Music Server (klassisch)</option>
            <option value="ms4h"<?= $mw_tts['mode'] === 'ms4h' ? ' selected' : '' ?>>Audioserver4Home / MusicServer4Home</option>
            <option value="audioserver"<?= $mw_tts['mode'] === 'audioserver' ? ' selected' : '' ?>>Original Loxone Audioserver (via Loxone Config)</option>
            <option value="custom"<?= $mw_tts['mode'] === 'custom' ? ' selected' : '' ?>>Eigene URL-Vorlage</option>
        </select>
    </div>
    <div>
        <label>IP des Audio-Servers</label>
        <input data-role="none" type="text" name="tts_ip" value="<?= mw_e($mw_tts['ip']) ?>" placeholder="z. B. 192.168.1.50">
    </div>
    <div>
        <label>Port</label>
        <input data-role="none" type="number" name="tts_port" value="<?= (int) $mw_tts['port'] ?>" min="1" max="65535">
    </div>
</div>
<div class="mw-row">
    <div>
        <label>Zonen</label>
        <input data-role="none" type="text" name="tts_zones" value="<?= mw_e($mw_tts['zones']) ?>" placeholder="z. B. 2,4,6">
        <div class="mw-small">Zonennummern mit Komma (z.&nbsp;B. <span class="mw-mono">2,4,6</span>) &mdash; die Lautst&auml;rke kommt aus dem Feld daneben. Optional je Zone eigene Lautst&auml;rke: <span class="mw-mono">Zone~Lautst&auml;rke</span> (z.&nbsp;B. <span class="mw-mono">2~25,4~40</span>). Leerzeichen nach dem Komma sind erlaubt &mdash; <span class="mw-mono">2,4,6</span> und <span class="mw-mono">2, 4, 6</span> funktionieren beide.</div>
    </div>
    <div>
        <label>Lautst&auml;rke (%)</label>
        <input data-role="none" type="number" name="tts_volume" value="<?= (int) $mw_tts['volume'] ?>" min="1" max="100">
    </div>
    <div>
        <label>Sprache</label>
        <input data-role="none" type="text" name="tts_lang" value="<?= mw_e($mw_tts['lang']) ?>" maxlength="2">
    </div>
</div>
<div id="tts_template_row">
    <label>URL-Vorlage (f&uuml;r Audioserver4Home/MS4H bzw. eigene Ausgabe)</label>
    <textarea data-role="none" name="tts_template" id="tts_template" rows="2" placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?= mw_e($mw_tts['template']) ?></textarea>
    <div class="mw-small">Platzhalter: <span class="mw-mono">{ip} {port} {zones} {vol} {lang} {text}</span>. Leer = Standard-Vorlage.</div>
</div>
<div id="tts_audioserver_hint" class="mw-alert mw-info" style="display:none;">
    Der originale Loxone Audioserver bietet keine HTTP-TTS-Schnittstelle. In diesem Modus spricht das Plugin nicht selbst;
    die Ausgabe baut man in Loxone Config &uuml;ber Textgenerator und <span class="mw-mono">ANN=1</span>.
</div>

<h2>MQTT (optional)</h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="mqtt_enabled" <?= !empty($mw_cfg['mqtt_enabled']) ? 'checked' : '' ?>> Zustand per MQTT ver&ouml;ffentlichen
</label>
<div class="mw-row" style="margin-top:6px;">
    <div>
        <label>Topic-Pr&auml;fix</label>
        <input data-role="none" type="text" name="mqtt_topic" value="<?= mw_e($mw_cfg['mqtt_topic']) ?>" placeholder="maeher">
        <div class="mw-small">Ver&ouml;ffentlicht u.&nbsp;a. <span class="mw-mono"><?= mw_e($mw_cfg['mqtt_topic']) ?>/code</span>,
        <span class="mw-mono">/status</span>, <span class="mw-mono">/batterie</span>, <span class="mw-mono">/maeht</span>,
        <span class="mw-mono">/fehler</span>, <span class="mw-mono">/stunden</span>, <span class="mw-mono">/messer_rest</span>,
        <span class="mw-mono">/temperatur</span>.</div>
    </div>
</div>

<button data-role="none" class="mw-btn" type="submit">Speichern</button>
</form>
<form method="post" style="margin-top:8px;">
    <input data-role="none" type="hidden" name="bladereset" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="mw-btn" type="submit" style="background:#607d8b;margin-top:0;">Messerwechsel quittieren</button>
</form>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="mw-pane" id="tab-loxone">
<h2>Einbindung in Loxone &mdash; Schritt f&uuml;r Schritt</h2>
<p>Der Miniserver fragt <b>eine</b> Adresse ohne Zugangsdaten ab und bekommt fertige Zahlenwerte.
Die Steuerung l&auml;uft &uuml;ber ebenso einfache Adressen &mdash; das Passwort bleibt im LoxBerry.</p>

<div class="mw-step"><b>Schritt 1: Virtueller HTTP-Eingang &bdquo;Rasenm&auml;her&ldquo;</b> (Abfrage alle 30 s)
<table class="mw-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>URL</td><td><span class="mw-mono">http://<?= $mw_host ?>/plugins/<?= mw_e($mw_plugin) ?>/mower.php</span> (M&auml;her 2: <span class="mw-mono">?dev=2</span>)</td></tr>
<tr><td>Abfragezyklus</td><td>30 Sekunden</td></tr>
</table>
<span class="mw-small">Der bisherige Eingang mit <span class="mw-mono">?user=...&amp;pass=...</span> kann danach gel&ouml;scht werden.</span>
</div>

<div class="mw-step"><b>Schritt 2: Befehlserkennungen</b>
<table class="mw-tbl">
<tr><th>Befehlserkennung</th><th>Bedeutung</th></tr>
<tr><td><span class="mw-mono">\iCODE=\i\v</span></td><td><b>Status</b>: 1 = parkt, 2 = m&auml;ht, 3 = sucht Ladestation, 4 = l&auml;dt, 5 = sucht, 7 = Fehler, 8 = Schleifensignal verloren, 16 = abgeschaltet, 17 = schl&auml;ft</td></tr>
<tr><td><span class="mw-mono">\iMODUS=\i\v</span></td><td>Betriebsart: 0 = Automatik, 1 = Manuell, 2 = Zuhause, 4 = Auftrag</td></tr>
<tr><td><span class="mw-mono">\iMAEHT=\i\v</span> / <span class="mw-mono">\iLAEDT=\i\v</span></td><td>1 = m&auml;ht gerade / 1 = l&auml;dt gerade</td></tr>
<tr><td><span class="mw-mono">\iBATT=\i\v</span></td><td>Akku in %</td></tr>
<tr><td><span class="mw-mono">\iFEHLER=\i\v</span></td><td>Fehlercode (0 = kein Fehler)</td></tr>
<tr><td><span class="mw-mono">\iSTUNDEN=\i\v</span> / <span class="mw-mono">\iDAUER=\i\v</span></td><td>Betriebsstunden gesamt / Laufzeit des aktuellen Einsatzes in Minuten</td></tr>
<tr><td><span class="mw-mono">\iMESSER=\i\v</span> / <span class="mw-mono">\iMESSERWARN=\i\v</span></td><td>Reststunden bis zum Messerwechsel / 1 = Wechsel f&auml;llig</td></tr>
<tr><td><span class="mw-mono">\iTEMP=\i\v</span> / <span class="mw-mono">\iFEUCHTE=\i\v</span> / <span class="mw-mono">\iWLAN=\i\v</span></td><td>Temperatur, Feuchte im Modul, WLAN-Signal</td></tr>
<tr><td><span class="mw-mono">\iANN=\i\v</span> / <span class="mw-mono">\iPUSH=\i\v</span> / <span class="mw-mono">\iPTEST=\i\v</span></td><td>Meldefenster / Push-Freigabe / Test-Push</td></tr>
<tr><td><span class="mw-mono">\iOK=\i\v</span></td><td>1 = M&auml;her erreichbar</td></tr>
</table>
</div>

<div class="mw-step"><b>Schritt 3: Steuerung &uuml;ber einen Virtuellen Ausgang</b>
<table class="mw-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>Adresse (Virtueller Ausgang)</td><td><span class="mw-mono">http://<?= $mw_host ?></span> &mdash; <b>ohne</b> Benutzer und Passwort!</td></tr>
</table>
<table class="mw-tbl">
<tr><th>Befehl bei EIN</th><th>Wirkung</th></tr>
<tr><td><span class="mw-mono">/plugins/<?= mw_e($mw_plugin) ?>/mower.php?cmd=auto</span></td><td>Automatikbetrieb (Zeitsteuerung des M&auml;hers)</td></tr>
<tr><td><span class="mw-mono">/plugins/<?= mw_e($mw_plugin) ?>/mower.php?cmd=home</span></td><td>zur&uuml;ck zur Ladestation und dort bleiben</td></tr>
<tr><td><span class="mw-mono">/plugins/<?= mw_e($mw_plugin) ?>/mower.php?cmd=man</span></td><td>Handbetrieb</td></tr>
<tr><td><span class="mw-mono">/plugins/<?= mw_e($mw_plugin) ?>/mower.php?cmd=eod</span></td><td>bis Feierabend m&auml;hen (End of Day)</td></tr>
<tr><td><span class="mw-mono">/plugins/<?= mw_e($mw_plugin) ?>/mower.php?cmd=start</span> / <span class="mw-mono">?cmd=stop</span></td><td>sofort starten bzw. anhalten</td></tr>
<tr><td><span class="mw-mono">/plugins/<?= mw_e($mw_plugin) ?>/mower.php?cmd=blade_reset</span></td><td>Messerwechsel quittieren (z. B. &uuml;ber einen Taster in der App)</td></tr>
</table>
</div>

<div class="mw-step"><b>Schritt 4: Komplette Baustein-Liste zum 1:1-Nachbauen</b><br>
<b>4a) Kacheln</b>
<table class="mw-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Statusbaustein</td><td>M&auml;her-Zustand</td><td>Texte je Wert: 1 &bdquo;parkt&ldquo;, 2 &bdquo;m&auml;ht&ldquo;, 3 &bdquo;f&auml;hrt zur Station&ldquo;, 4 &bdquo;l&auml;dt&ldquo;, 7 &bdquo;St&ouml;rung&ldquo;, 8 &bdquo;Schleifensignal verloren&ldquo;</td><td>I1 &larr; CODE</td></tr>
<tr><td>Analoganzeigen</td><td>Akku / Betriebsstunden / Messer-Reststunden</td><td>Einheiten <span class="mw-mono">&lt;v.0&gt; %</span>, <span class="mw-mono">&lt;v.0&gt; h</span></td><td>&larr; BATT, STUNDEN, MESSER</td></tr>
</table>
<b>4b) Meldungen</b>
<table class="mw-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Schwellwertschalter S1 / S2</td><td>Meldefenster / Push freigegeben</td><td>je Ein 0,5 / Aus 0,4</td><td>&larr; ANN bzw. PUSH</td></tr>
<tr><td>UND U1 + ODER O1</td><td>M&auml;her-Meldung</td><td>O1 ist die einzige Quelle des Benachrichtigungs-Bausteins</td><td>U1: S1 &amp; S2</td></tr>
<tr><td>Benachrichtigungs-Baustein</td><td>Push &bdquo;Rasenm&auml;her&ldquo;</td><td>Text z. B. &bdquo;Meldung vom Rasenm&auml;her &mdash; Details in der App&ldquo;</td><td>&larr; O1</td></tr>
<tr><td>Schwellwertschalter S3</td><td>St&ouml;rung</td><td>Ein 0,5 an FEHLER &rarr; eigene Warnkachel</td><td>&larr; FEHLER</td></tr>
<tr><td>Benachrichtigungs-Baustein 2</td><td>Test-Push</td><td>eigener Baustein NUR f&uuml;r den Test</td><td>&larr; Schwellwertschalter an PTEST</td></tr>
</table>
<b>4c) Wetter- und Zeitsperren (der eigentliche Mehrwert der Automatisierung)</b>
<table class="mw-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>UND U2</td><td>M&auml;hen sperren bei Regen</td><td>&rarr; auf <span class="mw-mono">?cmd=home</span>; Freigabe erst nach der Trocknungszeit wieder auf <span class="mw-mono">?cmd=auto</span></td><td>Regensensor &amp; (CODE = 2)</td></tr>
<tr><td>UND U3</td><td>Ruhezeiten einhalten</td><td>&rarr; <span class="mw-mono">?cmd=home</span> zu Zeiten, in denen nicht gem&auml;ht werden soll (Sonn- und Feiertage!)</td><td>Zeitschaltuhr &amp; ggf. SCHULFREI/FEIERTAG aus dem Ferien-Plugin</td></tr>
<tr><td>Schwellwertschalter S4 + Taster</td><td>Messerwechsel quittieren</td><td>Taster in der App &rarr; Virtueller Ausgang <span class="mw-mono">?cmd=blade_reset</span></td><td>&larr; MESSERWARN f&uuml;r die Warnkachel</td></tr>
</table>
<b>Praxis-Erfahrung:</b> Der Benachrichtigungs-Baustein sendet nur bei einer 0&rarr;1-Flanke &mdash; niemals mehrere
Quellen direkt an den Eingang legen, immer erst im ODER sammeln. F&uuml;r den Test einen eigenen Baustein verwenden.
</div>

<div class="mw-step"><b>Schritt 5: MQTT und JSON</b><br>
Alle Werte auch per MQTT (Reiter Einstellungen) und als JSON:
<span class="mw-mono">http://<?= $mw_host ?>/plugins/<?= mw_e($mw_plugin) ?>/mower.php?json=1</span>
</div>
</div>

<!-- ================= Test ================= -->
<div class="mw-pane" id="tab-test">
<h2>Test</h2>
<div class="mw-legende">
<span><i class="mw-punkt mw-b-lesen"></i> Ansehen &mdash; fragt nur ab, ver&auml;ndert nichts</span>
<span><i class="mw-punkt mw-b-technik"></i> Technische Auskunft &mdash; f&uuml;r die Fehlersuche</span>
<span><i class="mw-punkt mw-b-aktion"></i> L&ouml;st etwas aus &mdash; sendet oder ver&auml;ndert</span>
</div>

<h3 class="mw-h3">Ansehen</h3>
<div class="mw-knopfreihe">
<a class="mw-btn mw-b-lesen"  href="/plugins/<?= mw_e($mw_plugin) ?>/mower.php" target="_blank">Loxone-Zeile abrufen</a>
<a class="mw-btn mw-b-lesen"  href="/plugins/<?= mw_e($mw_plugin) ?>/mower.php?json=1" target="_blank">JSON-Ansicht</a>
</div>

<h3 class="mw-h3">Technische Auskunft</h3>
<div class="mw-knopfreihe">
<a class="mw-btn mw-b-technik"  href="/plugins/<?= mw_e($mw_plugin) ?>/mower.php?debug=1&amp;refresh=1" target="_blank">Debug</a>
</div>

<h3 class="mw-h3">L&ouml;st etwas aus</h3>
<div class="mw-knopfreihe">
<a class="mw-btn mw-b-aktion"  href="/plugins/<?= mw_e($mw_plugin) ?>/mower.php?ptest=1" target="_blank">Test-Pushnachricht</a>
<a class="mw-btn mw-b-aktion"  href="/plugins/<?= mw_e($mw_plugin) ?>/mower.php?cmd=auto" target="_blank">Automatik</a>
<a class="mw-btn mw-b-aktion"  href="/plugins/<?= mw_e($mw_plugin) ?>/mower.php?cmd=home" target="_blank">Nach Hause</a>
<a class="mw-btn mw-b-aktion"  href="/plugins/<?= mw_e($mw_plugin) ?>/mower.php?cmd=stop" target="_blank">Stopp</a>
</div>


<div class="mw-small">&bdquo;Nach Hause&ldquo; ist der ungef&auml;hrlichste Test: Der M&auml;her f&auml;hrt zur Ladestation und bleibt dort,
bis wieder auf Automatik gestellt wird.</div>
<h2>Statuscodes im &Uuml;berblick</h2>
<table class="mw-tbl"><tr><th>Code</th><th>Bedeutung</th><th>Code</th><th>Bedeutung</th></tr>
<tr><td>0</td><td>Status wird ermittelt</td><td>7</td><td>Fehler</td></tr>
<tr><td>1</td><td>parkt</td><td>8</td><td>Schleifensignal verloren</td></tr>
<tr><td>2</td><td>m&auml;ht</td><td>16</td><td>abgeschaltet</td></tr>
<tr><td>3</td><td>sucht die Ladestation</td><td>17</td><td>schl&auml;ft</td></tr>
<tr><td>4</td><td>l&auml;dt</td><td>18</td><td>wird gewartet</td></tr>
<tr><td>5</td><td>sucht</td><td>&minus;1</td><td>keine Verbindung</td></tr>
</table>
</div>

<!-- ================= Protokoll ================= -->
<div class="mw-pane" id="tab-log">
<h2>Protokoll</h2>
<div class="mw-small" style="margin-bottom:8px;">Protokolliert werden Status&auml;nderungen, Meldungen und Steuerbefehle. Passw&ouml;rter werden vor dem Schreiben maskiert. Neueste Eintr&auml;ge oben (max. 300).<br>Datei: <span class="mw-mono"><?= mw_e($mw_logfile) ?></span></div>
<?php if ($mw_loglines) { ?>
<div class="mw-log"><?= mw_e(implode("\n", $mw_loglines)) ?></div>
<?php } else { ?>
<div class="mw-alert mw-info">Noch keine Protokoll-Eintr&auml;ge vorhanden.</div>
<?php } ?>
<form method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="mw-btn" type="submit" style="background:#c62828;">Protokoll leeren</button>
</form>
</div>

</div>
<script>
function mwTtsMode() {
    var m = document.getElementById('tts_mode').value;
    document.getElementById('tts_audioserver_hint').style.display = (m === 'audioserver') ? 'block' : 'none';
    document.getElementById('tts_template_row').style.display = (m === 'ms4h' || m === 'custom') ? 'block' : 'none';
    var port = document.getElementsByName('tts_port')[0];
    if (m === 'musicserver' && (!port.value || port.value === '80')) { port.value = 7091; }
}
(function () {
    var tabs = document.querySelectorAll('.mw-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('mw-active', t.dataset.pane === id); });
        document.querySelectorAll('.mw-pane').forEach(function (p) { p.classList.toggle('mw-active', p.id === id); });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { activate(t.dataset.pane); }); });
    activate(<?= json_encode($mw_tab) ?>);
    mwTtsMode();
})();
</script>
<?php
if ($mw_frame) { LBWeb::lbfooter(); }
