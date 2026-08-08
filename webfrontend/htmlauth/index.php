<?php
/**
 * Rasenmaeher (Robonect) - Admin-Oberflaeche
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
/* Aktiver Reiter: aus dem abgesendeten Formular (activetab) oder aus der
   Adresse (?form=...). Letzteres brauchen die Reiter, seit sie echte
   Verweise sind. Die Positivliste MUSS jeden Reiter enthalten. */
$mw_muster = '/^tab-(settings|loxone|test|log)$/';
$mw_wunsch = isset($_POST['activetab']) ? (string) $_POST['activetab']
    : (isset($_GET['form']) ? 'tab-' . (string) $_GET['form'] : '');
$mw_tab = preg_match($mw_muster, $mw_wunsch) ? $mw_wunsch : 'tab-settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    @mkdir(dirname($mw_logfile), 0775, true);
    @file_put_contents($mw_logfile, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Admin-Oberflaeche)\n");
    $mw_tab = 'tab-log';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bladereset']) && function_exists('mo_blade_reset')) {
    mo_blade_reset(1);
    $mw_note = 'Messerwechsel quittiert &mdash; die Restlaufzeit startet neu.';
}

// ---------- Neues Aktionstoken erzeugen ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token_neu'])) {
    $mw_cfg_tok = function_exists('mo_config') ? mo_config() : array();
    if (!is_array($mw_cfg_tok)) { $mw_cfg_tok = array(); }
    $mw_cfg_tok['aktionstoken'] = function_exists('mo_token_erzeugen') ? mo_token_erzeugen() : bin2hex(random_bytes(12));
    if (!is_dir($mw_cfgdir)) { @mkdir($mw_cfgdir, 0775, true); }
    $mw_json_tok = json_encode($mw_cfg_tok, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
    if ($mw_json_tok !== false && @file_put_contents($mw_cfgfile, $mw_json_tok) !== false) {
        @chmod($mw_cfgfile, 0600);
        @copy($mw_cfgfile, $mw_bkfile);
        @chmod($mw_bkfile, 0600);
        $mw_note = 'Neues Token erzeugt. <b>Die Adressen in Loxone m&uuml;ssen '
                 . 'angepasst werden</b> &ndash; die alten funktionieren nicht mehr.';
    }
    $mw_tab = 'tab-loxone';
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
        // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
        // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
        if ($mw_json !== false && @file_put_contents($mw_cfgfile, $mw_json) !== false) {
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
    'mqtt_enabled' => 0, 'mqtt_topic' => 'maeher', 'notify' => array(), 'tts' => array(), 'aktionstoken' => '');

// Beim ersten Aufruf ein Token erzeugen, damit der Endpunkt fuer Loxone sofort
// benutzbar ist (schuetzt ?cmd= im unangemeldeten mower.php).
if (empty($mw_cfg['aktionstoken'])) {
    $mw_cfg['aktionstoken'] = function_exists('mo_token_erzeugen') ? mo_token_erzeugen() : bin2hex(random_bytes(12));
    if (!is_dir($mw_cfgdir)) { @mkdir($mw_cfgdir, 0775, true); }
    $mw_json_init = json_encode($mw_cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
    if ($mw_json_init !== false && @file_put_contents($mw_cfgfile, $mw_json_init) !== false) {
        @chmod($mw_cfgfile, 0600);
        @copy($mw_cfgfile, $mw_bkfile);
        @chmod($mw_bkfile, 0600);
    }
}
$mw_notify = is_array($mw_cfg['notify']) ? $mw_cfg['notify'] : array();
$mw_notify += array('audio' => 0, 'push' => 0, 'fehler' => 1, 'fertig' => 1, 'messer' => 1, 'akku' => 0);
$mw_tts = is_array($mw_cfg['tts']) ? $mw_cfg['tts'] : array();
$mw_tts += array('mode' => 'musicserver', 'ip' => '', 'port' => 7091, 'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '');
$mw_list = function_exists('mo_mowers') ? mo_mowers() : array();
$mw_states = array();
foreach ($mw_list as $mw_k => $mw_r) { $mw_states[$mw_k] = mo_state($mw_k); }
$mw_loglines = array();
if (is_file($mw_logfile)) {
    // mo_log_tail() liest nur das Ende der Datei, nicht die ganze - siehe
    // die Begruendung mit den Messwerten in mower_lib.php.
    $mw_loglines = array_reverse(mo_log_tail($mw_logfile, 300));
}

function mw_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$mw_frame = class_exists('LBWeb', false);
if ($mw_frame) { LBWeb::lbheader('Rasenm&auml;her (Robonect)', 'https://wiki.loxberry.de/', ''); }
$mw_host = mw_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '<loxberry-ip>');
?>
<style>
.sm-wrap { max-width: 940px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=password], .sm-wrap input[type=number], .sm-wrap select, .sm-wrap textarea {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 150px; }
.sm-row > div > label:not([style]) { min-height: 2.6em; display: flex; align-items: flex-end; }
.sm-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-warn { background: #fff8e1; border: 1px solid #ffe082; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; text-shadow: none !important; text-decoration: none !important; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-log { text-shadow: none !important; background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; }
.sm-tbl th { background: #f0f0f0; }
.sm-wrap .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button { text-shadow: none !important; box-shadow: none !important; }
.sm-wrap a.sm-btn, .sm-wrap a.sm-btn:visited, .sm-wrap a.sm-btn:hover { color: #fff !important; text-decoration: none; }

/* --- Einheitliches Kachel-Raster im Reiter <?php echo mo_t('TEXT.TEST'); ?> (Standard aller Plugins) --- */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; text-shadow: none !important; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-btn.sm-b-lesen   { background: #6dac20; }
.sm-btn.sm-b-technik { background: #546e7a; }
.sm-btn.sm-b-aktion  { background: #e0620d; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
</style>
<div class="sm-wrap">

<?php if ($mw_saved) { ?><div class="sm-alert sm-ok"><b><?php echo mo_t('TEXT.KONFIGURATION_GESPEICHERT'); ?></b> <?php echo mo_t('TEXT.ZUGANGSDATEN_MIT_DATEIRECHTEN_0600'); ?></div><?php } ?>
<?php if ($mw_note !== '') { ?><div class="sm-alert sm-ok"><?= $mw_note ?></div><?php } ?>
<?php if ($mw_err !== '') { ?><div class="sm-alert sm-err"><b><?php echo mo_t('TEXT.FEHLER'); ?></b> <?= $mw_err ?></div><?php } ?>

<?php if (!$mw_list) { ?>
<div class="sm-alert sm-info"><b><?php echo mo_t('TEXT.NOCH_KEIN_MHER_EINGERICHTET'); ?></b> <?php echo mo_t('TEXT.BITTE_UNTEN_ADRESSE_BENUTZER_UND_P'); ?></div>
<?php } ?>
<?php foreach ($mw_states as $mw_k => $mw_s) { ?>
<div class="sm-alert <?= $mw_s['fehler'] ? 'sm-warn' : 'sm-info' ?>">
<b><?= mw_e($mw_s['name']) ?></b>:
<?php if ($mw_s['ok']) { ?>
<b><?= mw_e($mw_s['text']) ?></b> <?php echo mo_t('TEXT.BETRIEBSART'); ?> <?= mw_e($mw_s['modus_text']) ?> <?php echo mo_t('TEXT.AKKU'); ?> <?= (int) $mw_s['batterie'] ?> %
<?= $mw_s['fehler'] ? ' &middot; <b>Fehler ' . (int) $mw_s['fehler'] . '</b> ' . mw_e($mw_s['fehlertext']) : '' ?><br>
<?php echo mo_t('TEXT.BETRIEBSSTUNDEN_GESAMT'); ?> <?= (int) $mw_s['stunden'] ?> h<?= $mw_s['dauer'] > 0 ? ' &middot; aktuelle Laufzeit ' . (int) $mw_s['dauer'] . ' min' : '' ?><br>
<?php echo mo_t('TEXT.MESSER'); ?> <?= $mw_s['messer_rest'] >= 0 ? ($mw_s['messer_warn'] ? '<b>Wechsel f&auml;llig</b>' : 'noch <b>' . (int) $mw_s['messer_rest'] . ' h</b> bis zum Wechsel') : '&ndash;' ?>
&middot; Temperatur <?= mw_e($mw_s['temperatur']) ?> <?php echo mo_t('TEXT.C_FEUCHTE'); ?> <?= mw_e($mw_s['feuchte']) ?> <?php echo mo_t('TEXT.WLAN'); ?> <?= (int) $mw_s['wlan'] ?> dBm
<?php } else { ?>
<b><?php echo mo_t('TEXT.KEINE_VERBINDUNG'); ?></b> <?php echo mo_t('TEXT.ADRESSE_UND_ZUGANGSDATEN_PRFEN_ROB'); ?>
<?php } ?>
</div>
<?php } ?>

<?php
/*
 * Reiter als echte Verweise, sm-active vom SERVER.
 *
 * Bis 1.0.3 standen hier <div class="sm-tab"> ohne Verweis, und sm-active
 * vergab allein das JavaScript am Seitenende. Da .sm-pane auf display:none
 * steht, war die Seite ohne JavaScript vollstaendig leer - und die Reiter
 * liessen sich nicht einmal anklicken, weil ein <div> kein Verweis ist.
 *
 * Diese Liste, die Positivliste in $mw_muster und die id der Flaechen
 * muessen deckungsgleich bleiben - alle drei.
 */
$mw_reiter = array(
    'tab-settings' => mo_t('REITER.EINSTELLUNGEN'),
    'tab-loxone'   => mo_t('REITER.LOXONE'),
    'tab-test'     => mo_t('REITER.TEST'),
    'tab-log'      => mo_t('REITER.LOG'),
);
?>
<div class="sm-tabs">
<?php foreach ($mw_reiter as $mw_id => $mw_bez) { ?>
    <a class="sm-tab<?php echo $mw_tab === $mw_id ? ' sm-active' : ''; ?>" data-pane="<?php echo htmlspecialchars($mw_id, ENT_QUOTES, 'UTF-8'); ?>"
       href="index.php?form=<?php echo htmlspecialchars(substr($mw_id, 4), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $mw_bez; ?></a>
<?php } ?>
</div>

<!-- ================= <?php echo mo_t('TEXT.EINSTELLUNG'); ?>en ================= -->
<div class="sm-pane<?php echo $mw_tab === 'tab-settings' ? ' sm-active' : ''; ?>" id="tab-settings">
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo mo_t('TEXT.MHER_BIS_ZU_2'); ?></h2>
<table class="sm-tbl" style="width:100%;">
<tr><th style="width:36px;">Nr.</th><th style="width:26%;"><?php echo mo_t('TEXT.NAME_FREI'); ?></th><th><?php echo mo_t('TEXT.ADRESSE'); ?></th><th style="width:20%;"><?php echo mo_t('TEXT.BENUTZER'); ?></th><th style="width:22%;"><?php echo mo_t('TEXT.PASSWORT'); ?></th></tr>
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
<div class="sm-alert sm-ok"><?php echo mo_t('TEXT.DAS_PASSWORT_WIRD'); ?> <b><?php echo mo_t('TEXT.NIE_ANGEZEIGT'); ?></b> <?php echo mo_t('TEXT.UND_BEIM_SPEICHERN_BEHALTEN_WENN_D'); ?>
<b><?php echo mo_t('TEXT.IN_DER_LOXONE_PROJEKTDATEI_STEHT_D'); ?></b><?php echo mo_t('TEXT.GENAU_DAFR_GIBT_ES_DIESES_PLUGIN'); ?></div>

<div class="sm-row">
    <div>
        <label><?php echo mo_t('TEXT.STATUS_CACHE_SEKUNDEN'); ?></label>
        <input data-role="none" type="number" name="cache_sec" value="<?= (int) $mw_cfg['cache_sec'] ?>" min="5" max="300">
        <div class="sm-small"><?php echo mo_t('TEXT.EMPFEHLUNG_20_EINE_LOXONE_ABFRAGE_'); ?></div>
    </div>
    <div>
        <label><?php echo mo_t('TEXT.MESSERWECHSEL_INTERVALL_BETRIEBSST'); ?></label>
        <input data-role="none" type="number" name="blade_hours" value="<?= (int) $mw_cfg['blade_hours'] ?>" min="1" max="2000">
        <div class="sm-small"><?php echo mo_t('TEXT.HERSTELLERANGABE_OFT_150250_H'); ?></div>
    </div>
    <div>
        <label><?php echo mo_t('TEXT.NULLPUNKT_STUNDEN_BEIM_LETZTEN_WEC'); ?></label>
        <input data-role="none" type="number" name="blade_base" value="<?= (int) $mw_cfg['blade_base'] ?>" min="0" max="100000">
        <div class="sm-small"><?php echo mo_t('TEXT.WIRD_BEIM_QUITTIEREN_AUTOMATISCH_G'); ?> <span class="sm-mono"><?php echo mo_t('TEXT.CMD_BLADE_RESET'); ?></span>).</div>
    </div>
</div>

<h2><?php echo mo_t('TEXT.MELDUNGEN'); ?></h2>
<div style="margin-bottom:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="notify_audio" <?= !empty($mw_notify['audio']) ? 'checked' : '' ?><?php echo mo_t('TEXT.AUDIOAUSGABE_AKTIV'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_push" <?= !empty($mw_notify['push']) ? 'checked' : '' ?><?php echo mo_t('TEXT.PUSH_NACHRICHT_AKTIV'); ?>
    </label>
    <div class="sm-small"><?php echo mo_t('TEXT.DIE_ANSAGE_SPRICHT_DAS_PLUGIN_SELB'); ?> <span class="sm-mono"><?php echo mo_t('TEXT.ANN_1'); ?></span>.</div>
</div>
<div>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="n_fehler" <?= !empty($mw_notify['fehler']) ? 'checked' : '' ?><?php echo mo_t('TEXT.STRUNG_SCHLEIFENSIGNAL_VERLOREN'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="n_fertig" <?= !empty($mw_notify['fertig']) ? 'checked' : '' ?><?php echo mo_t('TEXT.MHEN_BEENDET'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="n_messer" <?= !empty($mw_notify['messer']) ? 'checked' : '' ?><?php echo mo_t('TEXT.MESSERWECHSEL_FLLIG'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="n_akku" <?= !empty($mw_notify['akku']) ? 'checked' : '' ?><?php echo mo_t('TEXT.AKKU_UNTER_20_AUERHALB_DER_STATION'); ?>
    </label>
</div>

<h2><?php echo mo_t('TEXT.SPRACHAUSGABE'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo mo_t('TEXT.AUDIO_AUSGABE'); ?></label>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="mwTtsMode()">
            <option value="musicserver"<?= $mw_tts['mode'] === 'musicserver' ? ' selected' : '' ?><?php echo mo_t('TEXT.LOXONE_MUSIC_SERVER_KLASSISCH'); ?></option>
            <option value="ms4h"<?= $mw_tts['mode'] === 'ms4h' ? ' selected' : '' ?><?php echo mo_t('TEXT.AUDIOSERVER4HOME_MUSICSERVER4HOME'); ?></option>
            <option value="audioserver"<?= $mw_tts['mode'] === 'audioserver' ? ' selected' : '' ?><?php echo mo_t('TEXT.ORIGINAL_LOXONE_AUDIOSERVER_VIA_LO'); ?></option>
            <option value="custom"<?= $mw_tts['mode'] === 'custom' ? ' selected' : '' ?><?php echo mo_t('TEXT.EIGENE_URL_VORLAGE'); ?></option>
        </select>
    </div>
    <div>
        <label><?php echo mo_t('TEXT.IP_DES_AUDIO_SERVERS'); ?></label>
        <input data-role="none" type="text" name="tts_ip" value="<?= mw_e($mw_tts['ip']) ?>" placeholder="z. B. 192.168.1.50">
    </div>
    <div>
        <label><?php echo mo_t('TEXT.PORT'); ?></label>
        <input data-role="none" type="number" name="tts_port" value="<?= (int) $mw_tts['port'] ?>" min="1" max="65535">
    </div>
</div>
<div class="sm-row">
    <div>
        <label><?php echo mo_t('TEXT.ZONEN'); ?></label>
        <input data-role="none" type="text" name="tts_zones" value="<?= mw_e($mw_tts['zones']) ?>" placeholder="z. B. 2,4,6">
        <div class="sm-small"><?php echo mo_t('TEXT.ZONENNUMMERN_MIT_KOMMA_Z_B'); ?> <span class="sm-mono">2,4,6</span><?php echo mo_t('TEXT.DIE_LAUTSTRKE_KOMMT_AUS_DEM_FELD_D'); ?> <span class="sm-mono"><?php echo mo_t('TEXT.ZONE_LAUTSTRKE'); ?></span> <?php echo mo_t('TEXT.Z_B'); ?> <span class="sm-mono">2~25,4~40</span><?php echo mo_t('TEXT.LEERZEICHEN_NACH_DEM_KOMMA_SIND_ER'); ?> <span class="sm-mono">2,4,6</span> und <span class="sm-mono">2, 4, 6</span> <?php echo mo_t('TEXT.FUNKTIONIEREN_BEIDE'); ?></div>
    </div>
    <div>
        <label><?php echo mo_t('TEXT.LAUTSTRKE'); ?></label>
        <input data-role="none" type="number" name="tts_volume" value="<?= (int) $mw_tts['volume'] ?>" min="1" max="100">
    </div>
    <div>
        <label><?php echo mo_t('TEXT.SPRACHE'); ?></label>
        <input data-role="none" type="text" name="tts_lang" value="<?= mw_e($mw_tts['lang']) ?>" maxlength="2">
    </div>
</div>
<div id="tts_template_row">
    <label><?php echo mo_t('TEXT.URL_VORLAGE_FR_AUDIOSERVER4HOME_MS'); ?></label>
    <textarea data-role="none" name="tts_template" id="tts_template" rows="2" placeholder="<?php echo mo_t('TEXT.HTTP'); ?>{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?= mw_e($mw_tts['template']) ?></textarea>
    <div class="sm-small"><?php echo mo_t('TEXT.PLATZHALTER'); ?> <span class="sm-mono"><?php echo mo_t('TEXT.IP_PORT_ZONES_VOL_LANG_TEXT'); ?></span><?php echo mo_t('TEXT.LEER_STANDARD_VORLAGE'); ?></div>
</div>
<div id="tts_audioserver_hint" class="sm-alert sm-info" style="display:none;">
    <?php echo mo_t('TEXT.DER_ORIGINALE_LOXONE_AUDIOSERVER_B'); ?> <span class="sm-mono">ANN=1</span>.
</div>

<h2><?php echo mo_t('TEXT.MQTT_OPTIONAL'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="mqtt_enabled" <?= !empty($mw_cfg['mqtt_enabled']) ? 'checked' : '' ?><?php echo mo_t('TEXT.ZUSTAND_PER_MQTT_VERFFENTLICHEN'); ?>
</label>
<div class="sm-row" style="margin-top:6px;">
    <div>
        <label><?php echo mo_t('TEXT.TOPIC_PRFIX'); ?></label>
        <input data-role="none" type="text" name="mqtt_topic" value="<?= mw_e($mw_cfg['mqtt_topic']) ?>" placeholder="maeher">
        <div class="sm-small"><?php echo mo_t('TEXT.VERFFENTLICHT_U_A'); ?> <span class="sm-mono"><?= mw_e($mw_cfg['mqtt_topic']) ?><?php echo mo_t('TEXT.CODE'); ?></span>,
        <span class="sm-mono"><?php echo mo_t('TEXT.STATUS'); ?></span>, <span class="sm-mono"><?php echo mo_t('TEXT.BATTERIE'); ?></span>, <span class="sm-mono"><?php echo mo_t('TEXT.MAEHT'); ?></span>,
        <span class="sm-mono"><?php echo mo_t('TEXT.FEHLER_2'); ?></span>, <span class="sm-mono"><?php echo mo_t('TEXT.STUNDEN'); ?></span>, <span class="sm-mono"><?php echo mo_t('TEXT.MESSER_REST'); ?></span>,
        <span class="sm-mono"><?php echo mo_t('TEXT.TEMPERATUR'); ?></span>.</div>
    </div>
</div>

<button data-role="none" class="sm-btn" type="submit"><?php echo mo_t('TEXT.SPEICHERN'); ?></button>
</form>
<form action="index.php" method="post" style="margin-top:8px;">
    <input data-role="none" type="hidden" name="bladereset" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn" type="submit" style="background:#607d8b;margin-top:0;"><?php echo mo_t('TEXT.MESSERWECHSEL_QUITTIEREN'); ?></button>
</form>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-pane<?php echo $mw_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" id="tab-loxone">
<h2><?php echo mo_t('TEXT.EINBINDUNG_IN_LOXONE_SCHRITT_FR_SC'); ?></h2>
<p><?php echo mo_t('TEXT.DER_MINISERVER_FRAGT'); ?> <b><?php echo mo_t('TEXT.EINE'); ?></b> <?php echo mo_t('TEXT.ADRESSE_OHNE_ZUGANGSDATEN_AB_UND_B'); ?></p>

<div class="sm-step"><b><?php echo mo_t('TEXT.SCHRITT_1_VIRTUELLER_HTTP_EINGANG_'); ?></b> <?php echo mo_t('TEXT.ABFRAGE_ALLE_30_S'); ?>
<table class="sm-tbl">
<tr><th><?php echo mo_t('TEXT.EIGENSCHAFT'); ?></th><th><?php echo mo_t('TEXT.WERT'); ?></th></tr>
<tr><td>URL</td><td><span class="sm-mono">http://<?= $mw_host ?><?php echo mo_t('TEXT.PLUGINS'); ?><?= mw_e($mw_plugin) ?><?php echo mo_t('TEXT.MOWER_PHP'); ?></span> <?php echo mo_t('TEXT.MHER_2'); ?> <span class="sm-mono"><?php echo mo_t('TEXT.DEV_2'); ?></span>)</td></tr>
<tr><td><?php echo mo_t('TEXT.ABFRAGEZYKLUS'); ?></td><td><?php echo mo_t('TEXT.30_SEKUNDEN'); ?></td></tr>
</table>
<span class="sm-small"><?php echo mo_t('TEXT.DER_BISHERIGE_EINGANG_MIT'); ?> <span class="sm-mono"><?php echo mo_t('TEXT.USER_PASS'); ?></span> <?php echo mo_t('TEXT.KANN_DANACH_GELSCHT_WERDEN'); ?></span>
</div>

<div class="sm-step"><b><?php echo mo_t('TEXT.SCHRITT_2_BEFEHLSERKENNUNGEN'); ?></b>
<table class="sm-tbl">
<tr><th><?php echo mo_t('TEXT.BEFEHLSERKENNUNG'); ?></th><th><?php echo mo_t('TEXT.BEDEUTUNG'); ?></th></tr>
<tr><td><span class="sm-mono"><?php echo mo_t('TEXT.ICODE_I_V'); ?></span></td><td><b><?php echo mo_t('TEXT.STATUS_2'); ?></b><?php echo mo_t('TEXT.1_PARKT_2_MHT_3_SUCHT_LADESTATION_'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mo_t('TEXT.IMODUS_I_V'); ?></span></td><td><?php echo mo_t('TEXT.BETRIEBSART_0_AUTOMATIK_1_MANUELL_'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mo_t('TEXT.IMAEHT_I_V'); ?></span> / <span class="sm-mono"><?php echo mo_t('TEXT.ILAEDT_I_V'); ?></span></td><td><?php echo mo_t('TEXT.1_MHT_GERADE_1_LDT_GERADE'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mo_t('TEXT.IBATT_I_V'); ?></span></td><td><?php echo mo_t('TEXT.AKKU_IN'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mo_t('TEXT.IFEHLER_I_V'); ?></span></td><td><?php echo mo_t('TEXT.FEHLERCODE_0_KEIN_FEHLER'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mo_t('TEXT.ISTUNDEN_I_V'); ?></span> / <span class="sm-mono"><?php echo mo_t('TEXT.IDAUER_I_V'); ?></span></td><td><?php echo mo_t('TEXT.BETRIEBSSTUNDEN_GESAMT_LAUFZEIT_DE'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mo_t('TEXT.IMESSER_I_V'); ?></span> / <span class="sm-mono"><?php echo mo_t('TEXT.IMESSERWARN_I_V'); ?></span></td><td><?php echo mo_t('TEXT.RESTSTUNDEN_BIS_ZUM_MESSERWECHSEL_'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mo_t('TEXT.ITEMP_I_V'); ?></span> / <span class="sm-mono"><?php echo mo_t('TEXT.IFEUCHTE_I_V'); ?></span> / <span class="sm-mono"><?php echo mo_t('TEXT.IWLAN_I_V'); ?></span></td><td><?php echo mo_t('TEXT.TEMPERATUR_FEUCHTE_IM_MODUL_WLAN_S'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mo_t('TEXT.IANN_I_V'); ?></span> / <span class="sm-mono"><?php echo mo_t('TEXT.IPUSH_I_V'); ?></span> / <span class="sm-mono"><?php echo mo_t('TEXT.IPTEST_I_V'); ?></span></td><td><?php echo mo_t('TEXT.MELDEFENSTER_PUSH_FREIGABE_TEST_PU'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo mo_t('TEXT.IOK_I_V'); ?></span></td><td><?php echo mo_t('TEXT.1_MHER_ERREICHBAR'); ?></td></tr>
</table>
</div>

<div class="sm-step"><b><?php echo mo_t('TEXT.SCHRITT_3_STEUERUNG_BER_EINEN_VIRT'); ?></b>
<table class="sm-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td><?php echo mo_t('TEXT.ADRESSE_VIRTUELLER_AUSGANG'); ?></td><td><span class="sm-mono">http://<?= $mw_host ?></span> <?php echo mo_t('TEXT.TEXT'); ?> <b><?php echo mo_t('TEXT.OHNE'); ?></b> <?php echo mo_t('TEXT.BENUTZER_UND_PASSWORT'); ?></td></tr>
</table>
<table class="sm-tbl">
<tr><th><?php echo mo_t('TEXT.BEFEHL_BEI_EIN'); ?></th><th><?php echo mo_t('TEXT.WIRKUNG'); ?></th></tr>
<tr><td><span class="sm-mono">/plugins/<?= mw_e($mw_plugin) ?><?php echo mo_t('TEXT.MOWER_PHP_CMD_AUTO'); ?>&amp;token=<?= mw_e($mw_cfg['aktionstoken']) ?></span></td><td><?php echo mo_t('TEXT.AUTOMATIKBETRIEB_ZEITSTEUERUNG_DES'); ?></td></tr>
<tr><td><span class="sm-mono">/plugins/<?= mw_e($mw_plugin) ?><?php echo mo_t('TEXT.MOWER_PHP_CMD_HOME'); ?>&amp;token=<?= mw_e($mw_cfg['aktionstoken']) ?></span></td><td><?php echo mo_t('TEXT.ZURCK_ZUR_LADESTATION_UND_DORT_BLE'); ?></td></tr>
<tr><td><span class="sm-mono">/plugins/<?= mw_e($mw_plugin) ?><?php echo mo_t('TEXT.MOWER_PHP_CMD_MAN'); ?>&amp;token=<?= mw_e($mw_cfg['aktionstoken']) ?></span></td><td><?php echo mo_t('TEXT.HANDBETRIEB'); ?></td></tr>
<tr><td><span class="sm-mono">/plugins/<?= mw_e($mw_plugin) ?><?php echo mo_t('TEXT.MOWER_PHP_CMD_EOD'); ?>&amp;token=<?= mw_e($mw_cfg['aktionstoken']) ?></span></td><td><?php echo mo_t('TEXT.BIS_FEIERABEND_MHEN_END_OF_DAY'); ?></td></tr>
<tr><td><span class="sm-mono">/plugins/<?= mw_e($mw_plugin) ?><?php echo mo_t('TEXT.MOWER_PHP_CMD_START'); ?>&amp;token=<?= mw_e($mw_cfg['aktionstoken']) ?></span> / <span class="sm-mono"><?php echo mo_t('TEXT.CMD_STOP'); ?>&amp;token=...</span></td><td><?php echo mo_t('TEXT.SOFORT_STARTEN_BZW_ANHALTEN'); ?></td></tr>
<tr><td><span class="sm-mono">/plugins/<?= mw_e($mw_plugin) ?><?php echo mo_t('TEXT.MOWER_PHP_CMD_BLADE_RESET'); ?>&amp;token=<?= mw_e($mw_cfg['aktionstoken']) ?></span></td><td><?php echo mo_t('TEXT.MESSERWECHSEL_QUITTIEREN_Z_B_BER_E'); ?></td></tr>
</table>
<div class="sm-alert sm-warn"><b>Token n&ouml;tig:</b> Der Endpunkt liegt unangemeldet und ist deshalb mit einem Token abgesichert &ndash; ohne passendes <span class="sm-mono">&amp;token=...</span> antwortet er mit HTTP 403 (siehe oben, aktuelles Token im n&auml;chsten Abschnitt).</div>
</div>

<div class="sm-step"><b>Aktionstoken</b>
<table class="sm-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>Aktuelles Token</td><td><span class="sm-mono"><?= mw_e($mw_cfg['aktionstoken']) ?></span></td></tr>
</table>
<div class="sm-knopfreihe sm-b-aktion">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" type="submit" name="token_neu" value="1">Neues Token erzeugen</button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> Aktion &ndash; &auml;ndert bestehende Loxone-Adressen</span>
</div>
</div>

<div class="sm-step"><b><?php echo mo_t('TEXT.SCHRITT_4_KOMPLETTE_BAUSTEIN_LISTE'); ?></b><br>
<b><?php echo mo_t('TEXT.4A_KACHELN'); ?></b>
<table class="sm-tbl">
<tr><th><?php echo mo_t('TEXT.BAUSTEIN'); ?></th><th><?php echo mo_t('TEXT.NAME'); ?></th><th>Einstellung</th><th><?php echo mo_t('TEXT.EINGNGE'); ?></th></tr>
<tr><td><?php echo mo_t('TEXT.STATUSBAUSTEIN'); ?></td><td><?php echo mo_t('TEXT.MHER_ZUSTAND'); ?></td><td><?php echo mo_t('TEXT.TEXTE_JE_WERT_1_PARKT_2_MHT_3_FHRT'); ?></td><td><?php echo mo_t('TEXT.I1_CODE'); ?></td></tr>
<tr><td><?php echo mo_t('TEXT.ANALOGANZEIGEN'); ?></td><td><?php echo mo_t('TEXT.AKKU_BETRIEBSSTUNDEN_MESSER_RESTST'); ?></td><td><?php echo mo_t('TEXT.EINHEITEN'); ?> <span class="sm-mono">&lt;v.0&gt; %</span>, <span class="sm-mono">&lt;v.0&gt; h</span></td><td><?php echo mo_t('TEXT.BATT_STUNDEN_MESSER'); ?></td></tr>
</table>
<b><?php echo mo_t('TEXT.4B_MELDUNGEN'); ?></b>
<table class="sm-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td><?php echo mo_t('TEXT.SCHWELLWERTSCHALTER_S1_S2'); ?></td><td><?php echo mo_t('TEXT.MELDEFENSTER_PUSH_FREIGEGEBEN'); ?></td><td><?php echo mo_t('TEXT.JE_EIN_0_5_AUS_0_4'); ?></td><td><?php echo mo_t('TEXT.ANN_BZW_PUSH'); ?></td></tr>
<tr><td><?php echo mo_t('TEXT.UND_U1_ODER_O1'); ?></td><td><?php echo mo_t('TEXT.MHER_MELDUNG'); ?></td><td><?php echo mo_t('TEXT.O1_IST_DIE_EINZIGE_QUELLE_DES_BENA'); ?></td><td><?php echo mo_t('TEXT.U1_S1_S2'); ?></td></tr>
<tr><td><?php echo mo_t('TEXT.BENACHRICHTIGUNGS_BAUSTEIN'); ?></td><td><?php echo mo_t('TEXT.PUSH_RASENMHER'); ?></td><td><?php echo mo_t('TEXT.TEXT_Z_B_MELDUNG_VOM_RASENMHER_DET'); ?></td><td><?php echo mo_t('TEXT.O1'); ?></td></tr>
<tr><td><?php echo mo_t('TEXT.SCHWELLWERTSCHALTER_S3'); ?></td><td><?php echo mo_t('TEXT.STRUNG'); ?></td><td><?php echo mo_t('TEXT.EIN_0_5_AN_FEHLER_EIGENE_WARNKACHE'); ?></td><td><?php echo mo_t('TEXT.FEHLER_3'); ?></td></tr>
<tr><td><?php echo mo_t('TEXT.BENACHRICHTIGUNGS_BAUSTEIN_2'); ?></td><td><?php echo mo_t('TEXT.TEST_PUSH'); ?></td><td><?php echo mo_t('TEXT.EIGENER_BAUSTEIN_NUR_FR_DEN_TEST'); ?></td><td><?php echo mo_t('TEXT.SCHWELLWERTSCHALTER_AN_PTEST'); ?></td></tr>
</table>
<b><?php echo mo_t('TEXT.4C_WETTER_UND_ZEITSPERREN_DER_EIGE'); ?></b>
<table class="sm-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td><?php echo mo_t('TEXT.UND_U2'); ?></td><td><?php echo mo_t('TEXT.MHEN_SPERREN_BEI_REGEN'); ?></td><td><?php echo mo_t('TEXT.AUF'); ?> <span class="sm-mono"><?php echo mo_t('TEXT.CMD_HOME'); ?></span><?php echo mo_t('TEXT.FREIGABE_ERST_NACH_DER_TROCKNUNGSZ'); ?> <span class="sm-mono"><?php echo mo_t('TEXT.CMD_AUTO'); ?></span></td><td><?php echo mo_t('TEXT.REGENSENSOR_CODE_2'); ?></td></tr>
<tr><td><?php echo mo_t('TEXT.UND_U3'); ?></td><td><?php echo mo_t('TEXT.RUHEZEITEN_EINHALTEN'); ?></td><td><?php echo mo_t('TEXT.TEXT_2'); ?> <span class="sm-mono">?cmd=home</span> <?php echo mo_t('TEXT.ZU_ZEITEN_IN_DENEN_NICHT_GEMHT_WER'); ?></td><td><?php echo mo_t('TEXT.ZEITSCHALTUHR_GGF_SCHULFREI_FEIERT'); ?></td></tr>
<tr><td><?php echo mo_t('TEXT.SCHWELLWERTSCHALTER_S4_TASTER'); ?></td><td>Messerwechsel quittieren</td><td><?php echo mo_t('TEXT.TASTER_IN_DER_APP_VIRTUELLER_AUSGA'); ?> <span class="sm-mono">?cmd=blade_reset</span></td><td><?php echo mo_t('TEXT.MESSERWARN_FR_DIE_WARNKACHEL'); ?></td></tr>
</table>
<b><?php echo mo_t('TEXT.PRAXIS_ERFAHRUNG'); ?></b> <?php echo mo_t('TEXT.DER_BENACHRICHTIGUNGS_BAUSTEIN_SEN'); ?>
</div>

<div class="sm-step"><b><?php echo mo_t('TEXT.SCHRITT_5_MQTT_UND_JSON'); ?></b><br>
<?php echo mo_t('TEXT.ALLE_WERTE_AUCH_PER_MQTT_REITER_EI'); ?>
<span class="sm-mono">http://<?= $mw_host ?>/plugins/<?= mw_e($mw_plugin) ?><?php echo mo_t('TEXT.MOWER_PHP_JSON_1'); ?></span>
</div>
</div>

<!-- ================= Test ================= -->
<div class="sm-pane<?php echo $mw_tab === 'tab-test' ? ' sm-active' : ''; ?>" id="tab-test">
<h2>Test</h2>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo mo_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo mo_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo mo_t('LEGENDE.AKTION'); ?></span>
</div>

<h3 class="sm-h3"><?php echo mo_t('TEXT.ANSEHEN'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-lesen"  href="/plugins/<?= mw_e($mw_plugin) ?>/mower.php" target="_blank"><?php echo mo_t('TEXT.LOXONE_ZEILE_ABRUFEN'); ?></a>
<a class="sm-btn sm-b-lesen"  href="/plugins/<?= mw_e($mw_plugin) ?>/mower.php?json=1" target="_blank"><?php echo mo_t('TEXT.JSON_ANSICHT'); ?></a>
</div>

<h3 class="sm-h3"><?php echo mo_t('TEXT.TECHNISCHE_AUSKUNFT'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-technik"  href="/plugins/<?= mw_e($mw_plugin) ?>/mower.php?debug=1&amp;refresh=1" target="_blank"><?php echo mo_t('TEXT.DEBUG'); ?></a>
</div>

<h3 class="sm-h3"><?php echo mo_t('TEXT.LST_ETWAS_AUS'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= mw_e($mw_plugin) ?>/mower.php?ptest=1" target="_blank"><?php echo mo_t('TEXT.TEST_PUSHNACHRICHT'); ?></a>
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= mw_e($mw_plugin) ?>/mower.php?cmd=auto&amp;token=<?= mw_e($mw_cfg['aktionstoken']) ?>" target="_blank"><?php echo mo_t('TEXT.AUTOMATIK'); ?></a>
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= mw_e($mw_plugin) ?>/mower.php?cmd=home&amp;token=<?= mw_e($mw_cfg['aktionstoken']) ?>" target="_blank"><?php echo mo_t('TEXT.NACH_HAUSE'); ?></a>
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= mw_e($mw_plugin) ?>/mower.php?cmd=stop&amp;token=<?= mw_e($mw_cfg['aktionstoken']) ?>" target="_blank"><?php echo mo_t('TEXT.STOPP'); ?></a>
</div>


<div class="sm-small"><?php echo mo_t('TEXT.NACH_HAUSE_IST_DER_UNGEFHRLICHSTE_'); ?></div>
<h2><?php echo mo_t('TEXT.STATUSCODES_IM_UUML_BERBLICK'); ?></h2>
<table class="sm-tbl"><tr><th><?php echo mo_t('TEXT.CODE_2'); ?></th><th>Bedeutung</th><th>Code</th><th>Bedeutung</th></tr>
<tr><td>0</td><td><?php echo mo_t('TEXT.STATUS_WIRD_ERMITTELT'); ?></td><td>7</td><td><?php echo mo_t('TEXT.FEHLER_4'); ?></td></tr>
<tr><td>1</td><td><?php echo mo_t('TEXT.PARKT'); ?></td><td>8</td><td><?php echo mo_t('TEXT.SCHLEIFENSIGNAL_VERLOREN'); ?></td></tr>
<tr><td>2</td><td><?php echo mo_t('TEXT.MHT'); ?></td><td>16</td><td><?php echo mo_t('TEXT.ABGESCHALTET'); ?></td></tr>
<tr><td>3</td><td><?php echo mo_t('TEXT.SUCHT_DIE_LADESTATION'); ?></td><td>17</td><td><?php echo mo_t('TEXT.SCHLFT'); ?></td></tr>
<tr><td>4</td><td><?php echo mo_t('TEXT.LDT'); ?></td><td>18</td><td><?php echo mo_t('TEXT.WIRD_GEWARTET'); ?></td></tr>
<tr><td>5</td><td><?php echo mo_t('TEXT.SUCHT'); ?></td><td><?php echo mo_t('TEXT.1'); ?></td><td>keine Verbindung</td></tr>
</table>
</div>

<!-- ================= <?php echo mo_t('TEXT.PROTOKOLL'); ?> ================= -->
<div class="sm-pane<?php echo $mw_tab === 'tab-log' ? ' sm-active' : ''; ?>" id="tab-log">
<h2>Protokoll</h2>
<div class="sm-small" style="margin-bottom:8px;"><?php echo mo_t('TEXT.PROTOKOLLIERT_WERDEN_STATUSNDERUNG'); ?><br><?php echo mo_t('TEXT.DATEI'); ?> <span class="sm-mono"><?= mw_e($mw_logfile) ?></span></div>
<?php if ($mw_loglines) { ?>
<div class="sm-log"><?= mw_e(implode("\n", $mw_loglines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo mo_t('TEXT.NOCH_KEINE_PROTOKOLL_EINTRGE_VORHA'); ?></div>
<?php } ?>
<form action="index.php" method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn" type="submit" style="background:#c62828;"><?php echo mo_t('TEXT.PROTOKOLL_LEEREN'); ?></button>
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
    var tabs = document.querySelectorAll('.sm-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.pane === id); });
        document.querySelectorAll('.sm-pane').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function (e) { e.preventDefault(); activate(t.dataset.pane); }); });
    activate(<?= json_encode($mw_tab) ?>);
    mwTtsMode();
})();
</script>
<?php
if ($mw_frame) { LBWeb::lbfooter(); }
