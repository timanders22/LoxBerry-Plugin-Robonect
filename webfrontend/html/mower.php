<?php
/**
 * Rasenmaeher (Robonect) - Miniserver-Endpunkt
 *
 * Abfrage (&dev=N waehlt bei mehreren Maehern das Geraet, Standard 1):
 *   (ohne Parameter) -> MOWER;OK=..;CODE=..;MODUS=..;BATT=..;MAEHT=..;LAEDT=..;FEHLER=..;
 *                       STUNDEN=..;DAUER=..;MESSER=..;MESSERWARN=..;TEMP=..;FEUCHTE=..;WLAN=..;
 *                       TIMER=..;ANN=..;AUDIO=..;PUSH=..;PTEST=..
 *                       CODE: 1=parkt 2=maeht 3=sucht Ladestation 4=laedt 5=sucht
 *                             7=Fehler 8=Schleifensignal verloren 16=abgeschaltet 17=schlaeft
 *                       MODUS: 0=Automatik 1=Manuell 2=Zuhause 4=Auftrag
 *                       MESSER = Reststunden bis zum Messerwechsel
 *
 * Steuerung (einfache GET-Aufrufe fuer virtuelle Ausgaenge):
 *   ?cmd=auto | man | home | eod | start | stop
 *   ?cmd=blade_reset   Messerwechsel quittieren (Nullpunkt neu setzen)
 *
 * Weitere Aufrufe: ?debug=1  ?json=1  ?refresh=1  ?ptest=1
 *
 * Zugangsdaten stehen ausschliesslich in der Plugin-Konfiguration - diese URL
 * enthaelt KEIN Passwort und darf daher bedenkenlos in der Loxone-Projektdatei stehen.
 */

require_once __DIR__ . '/mower_lib.php';
$dev = isset($_GET['dev']) ? max(1, min(9, (int) $_GET['dev'])) : 1;

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    $st = mo_state($dev, isset($_GET['refresh']));
    $st['ann'] = mo_ann_active($dev);
    $st['ptest'] = mo_ptest_active();
    echo json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

if (isset($_GET['cmd'])) {
    if ($_GET['cmd'] === 'blade_reset') {
        $ok = mo_blade_reset($dev);
        echo 'CMD;OK=' . $ok . ";BEFEHL=blade_reset\n";
        exit;
    }
    list($ok, $info) = mo_command($_GET['cmd'], $dev, isset($_GET['p']) ? $_GET['p'] : '');
    echo 'CMD;OK=' . $ok . ';BEFEHL=' . htmlspecialchars((string) $_GET['cmd'], ENT_QUOTES) . ';INFO=' . $info . "\n";
    exit;
}

if (isset($_GET['ptest'])) {
    @file_put_contents(mo_tmpdir() . '/ptest', '1');
    mo_log('Test-Pushnachricht angefordert (PTEST=1 fuer 5 Minuten)');
    echo "PTEST;OK=1;DAUER=300\n";
    exit;
}

$st = mo_state($dev, isset($_GET['refresh']));
$cfg = mo_config();

if (isset($_GET['debug'])) {
    $m = mo_mower($dev);
    echo 'DEBUG  Maeher ' . $dev . ': ' . ($m ? $m['name'] . ' (' . $m['ip'] . ')' : 'nicht konfiguriert') . "\n";
    echo 'Status: ' . $st['text'] . ' (Code ' . $st['code'] . ')  Betriebsart: ' . $st['modus_text']
       . '  Batterie: ' . $st['batterie'] . "%\n";
    if ($st['fehler']) { echo 'FEHLER ' . $st['fehler'] . ': ' . $st['fehlertext'] . "\n"; }
    echo 'Betriebsstunden: ' . $st['stunden'] . ' h  aktuelle Laufzeit: ' . $st['dauer'] . " min\n";
    echo 'Messer: noch ' . $st['messer_rest'] . ' h bis zum Wechsel (Intervall ' . (int) $cfg['blade_hours']
       . ' h, Nullpunkt bei ' . (int) $cfg['blade_base'] . " h)\n";
    echo 'Temperatur: ' . $st['temperatur'] . ' C  Feuchte: ' . $st['feuchte'] . ' %  WLAN: ' . $st['wlan'] . " dBm\n\n";
}

printf("MOWER;OK=%d;CODE=%d;MODUS=%d;BATT=%d;MAEHT=%d;LAEDT=%d;FEHLER=%d;STUNDEN=%d;DAUER=%d;MESSER=%d;MESSERWARN=%d;TEMP=%.1f;FEUCHTE=%.1f;WLAN=%d;TIMER=%d;ANN=%d;AUDIO=%d;PUSH=%d;PTEST=%d\n",
    $st['ok'], $st['code'], $st['modus'], $st['batterie'], $st['maeht'], $st['laedt'], $st['fehler'],
    $st['stunden'], $st['dauer'], $st['messer_rest'], $st['messer_warn'],
    $st['temperatur'], $st['feuchte'], $st['wlan'], $st['timer'],
    mo_ann_active($dev),
    empty($cfg['notify']['audio']) ? 0 : 1,
    empty($cfg['notify']['push']) ? 0 : 1,
    mo_ptest_active());
