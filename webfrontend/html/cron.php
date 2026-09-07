<?php
/**
 * Rasenmaeher (Robonect) - minutlicher Cron-Lauf
 *
 * 1. Konfiguration vervollstaendigen (der Dienst ist die zweite Stelle,
 *    an der ein fehlender Schluessel einmal geschrieben wird).
 * 2. Status aller Maeher aktualisieren (Cache-schonend).
 * 3. Ereignisse melden: Fehler, Schleifensignal verloren, Maehen beendet,
 *    Messerwechsel faellig, schwacher Akku.
 * 4. Das Lebenszeichen fortschreiben.
 * 5. MQTT bei Aenderung, mindestens halbstuendlich, danach das Lebenszeichen.
 *
 * Die Reihenfolge 4 vor 5 ist seit 1.1.6 wichtig (A6): mo_mqtt_publish()
 * las lauf.json, bevor mo_lauf_vermerken() es geschrieben hatte.
 */

require_once __DIR__ . '/mower_lib.php';

/* ==================================================================
 * A1 (04.09.2026): DIESER LAUF GEHOERT NICHT INS NETZ
 * ==================================================================
 *
 * Die Datei liegt unter webfrontend/html/ und wird damit unter
 * /plugins/<ordner>/cron.php OHNE Anmeldung ausgeliefert. Gemessen am
 * Pruefstand: ein Aufruf von aussen antwortete HTTP 200 mit
 * "OK;GEMESSEN=1;MAEHER=1;ZAEHLER=1", schrieb lauf.json und trieb den
 * Laufzaehler ueber fuenf weitere Aufrufe von 1 auf 6.
 *
 * Das ist genau die Auskunft, die der Miniserver braucht, um einen
 * STEHENDEN Cron zu erkennen (siehe mower.php, Abschnitt "Warum TS und
 * ZAEHLER dazugehoeren"). Von aussen vorwaerts getrieben ist sie wertlos:
 * ein toter Cron sieht dann aus wie ein lebender. Derselbe Aufruf loeste
 * ausserdem den MQTT-Versand und - bei gesetztem Haken - eine Ansage aus.
 *
 * Der Riegel steht VOR jeder Wirkung. Der Cron ruft "php .../cron.php"
 * und laeuft damit unter der Kommandozeile; er bleibt unberuehrt.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CRON;OK=0;ERR=NUR_CLI\n";
    exit(1);
}

/* ==================================================================
 * EINE SPERRE, WEIL DIESER LAUF INS NETZ GEHT
 * ==================================================================
 *
 * Der Lauf startet jede Minute. Ein Maeher, der Pakete verwirft, kostet je
 * Abruf bis zur Zeitgrenze - bei mehreren Maehern kann ein Durchgang damit
 * ueber die Minute hinauskommen und auf den naechsten treffen. Zwei Laeufe
 * schreiben dann dieselben Dateien unter /tmp/robonect/.
 *
 * Die Sperrdatei traegt die Prozessnummer, damit im Protokoll steht, WER
 * noch laeuft. Rechte VOR dem Inhalt.
 *
 * Eine verwaiste Sperre (Rechner abgestuerzt) wuerde den Dienst dauerhaft
 * stilllegen - deshalb die Altersgrenze: nach 15 Minuten gilt sie als tot.
 * Der Wert liegt deutlich ueber dem laengsten denkbaren Durchgang.
 */
$mo_sperre = mo_tmpdir() . '/cron.lock';
$mo_fh = @fopen($mo_sperre, 'c');
if ($mo_fh === false) {
    mo_log('Der Cron-Lauf konnte seine Sperrdatei nicht anlegen: ' . $mo_sperre);
    echo "FEHLER;GRUND=SPERRE\n";
    exit(1);
}
@chmod($mo_sperre, 0644);

/* A13 (04.09.2026): flock statt Vorpruefung.
 *
 * Bis 1.1.3 stand hier "is_file() und filemtime() < 900, sonst schreiben".
 * Zwischen der Pruefung und dem Schreiben lag ein Fenster, in dem ein
 * zweiter Lauf dieselbe Pruefung bestand - beide liefen dann parallel und
 * schrieben dieselben Dateien. Die Abschlussfunktion loeschte die Sperre
 * ausserdem nach PFAD: der zweite Lauf raeumte beim Beenden die Sperre des
 * ersten weg, der noch lief.
 *
 * flock() entscheidet ohne dieses Fenster. Die Altersgrenze von 900 s
 * entfaellt damit ersatzlos, und zwar zum Besseren: eine verwaiste Sperre
 * kann es nicht mehr geben. Stirbt der Prozess, gibt das Betriebssystem
 * die Sperre sofort frei - kein Warten auf eine Frist, egal ob der Rechner
 * abgestuerzt ist oder jemand den Lauf abgebrochen hat.
 *
 * Die Datei bleibt liegen: sie unter einer gehaltenen Sperre zu loeschen,
 * ist die naechste Wettlaufstelle. Sie steht auf der Ramdisk und ist nach
 * einem Neustart ohnehin fort.
 */
if (!flock($mo_fh, LOCK_EX | LOCK_NB)) {
    $mo_wer = trim((string) @file_get_contents($mo_sperre));
    $mo_seit = time() - (int) @filemtime($mo_sperre);
    fclose($mo_fh);
    echo 'SKIP;GRUND=LAEUFT_NOCH;SEIT=' . $mo_seit . 's;PID=' . $mo_wer . "\n";
    exit(0);   // Ein uebersprungener Lauf ist KEIN Fehler.
}
ftruncate($mo_fh, 0);
fwrite($mo_fh, (string) getmypid());
fflush($mo_fh);
/* Das Handle bleibt bis zum Ende des Laufs offen - es IST die Sperre.
 * Freigegeben wird das eigene Handle, nicht ein Pfad. */
register_shutdown_function(function () use ($mo_fh) {
    if (is_resource($mo_fh)) { @flock($mo_fh, LOCK_UN); @fclose($mo_fh); }
});

/* Vervollstaendigen: fehlt ein Schluessel, wird er EINMAL geschrieben. Der
 * Dienst muss das koennen, weil er auf einer Anlage laufen kann, deren
 * Oberflaeche seit dem Update niemand geoeffnet hat. */
$mo_cfg = mo_config();
$mo_fehlten = mo_cfg_vervollstaendigen($mo_cfg);
if ($mo_fehlten) {
    if (mo_config_speichern($mo_cfg)) {
        mo_log('Konfiguration ergaenzt (Cron): ' . implode(', ', $mo_fehlten));
    }
}

mo_events_check();

/* Hat dieser Lauf wirklich GEMESSEN? Ein Lauf, bei dem alle Maeher
 * schweigen, ist ein Lauf - aber keine Messung, und beides gehoert
 * unterschieden. Ohne eingerichteten Maeher gibt es nichts zu messen. */
$mo_gemessen = 0;
$mo_anzahl = 0;
$mo_stand = array();

/* ERST MESSEN. Der zweite Durchgang unten kostet nichts: mo_state()
 * speichert das Ergebnis zwischen - auch das gescheiterte. */
foreach (mo_mowers() as $n => $m) {
    $mo_anzahl++;
    $mo_stand[$n] = mo_state($n);
    if (!empty($mo_stand[$n]['ok'])) { $mo_gemessen = 1; }
}

/* DANN das Lebenszeichen fortschreiben - und ZWAR VOR dem Senden.
 *
 * A6 (06.09.2026, gemessen): bis 1.1.5 stand diese Zeile UNTER der Schleife.
 * mo_mqtt_publish() las lauf.json also noch im alten Stand, und in einem
 * einzigen Lauf gingen erst der alte, dann der neue Zaehler hinaus:
 *
 *     publish maeher/status/ts       0            <- alt
 *     publish maeher/status/zaehler  0
 *     publish maeher/status/ts       1788652847   <- neu
 *     publish maeher/status/zaehler  1
 *
 * Es wird IMMER fortgeschrieben, auch wenn kein Maeher geantwortet hat -
 * genau dann ist es am wichtigsten: es unterscheidet "der Cron laeuft, der
 * Maeher schweigt" von "der Cron laeuft gar nicht mehr". */
$mo_lauf = mo_lauf_vermerken($mo_gemessen);

/* DANN senden. */
foreach ($mo_stand as $n => $st) {
    /* A5 (06.09.2026, gemessen): bis 1.1.5 stand hier eine von Hand
     * geschriebene Liste aus sieben Angaben. temperatur, feuchte, wlan,
     * dauer, timer und messer_rest fehlten darin. Gemessen: Temperatur von
     * 21,5 auf 33,3, Feuchte 48 auf 11, WLAN -55 auf -88 - ueber MQTT
     * gingen DREI Datagramme hinaus (nur das Lebenszeichen), ueber HTTP im
     * selben Augenblick TEMP=33.3;FEUCHTE=11.0;WLAN=-88. Wer auf MQTT
     * umstellte, bekam eine Temperaturkurve mit halbstuendlichen Stufen.
     *
     * Die Signatur entsteht jetzt aus dem VOLLEN Wertsatz, also aus
     * derselben Quelle wie die Antwortzeile - sie steht damit auch bei
     * einem kuenftigen Feld von selbst richtig.
     *
     * TS und ZAEHLER bleiben ausdruecklich draussen: sie aendern sich bei
     * JEDEM Durchgang, und mit ihnen in der Signatur ginge der ganze
     * Wertsatz jede Minute hinaus. Sie gehen ueber mo_mqtt_lebenszeichen(),
     * und zwar ebenfalls jede Minute.
     *
     * Die Meldeflags (ann, audio, push, ptest) stecken in mo_werte() und
     * bleiben damit in der Signatur - ann und ptest aendern sich ohne
     * Zustandswechsel, allein durch Zeitablauf, und das Fenster von ptest
     * ist nur fuenf Minuten breit. */
    /* 1.1.8 (07.09.2026, am Geraet gemessen): bis 1.1.7 entstand die
     * Signatur hier aus mo_werte() allein - also ohne den Statustext,
     * der als Thema <praefix>/status hinausgeht. Sechs Minuten lang
     * wechselte der Text, ohne dass etwas gesendet wurde. Die Quelle
     * steht jetzt an EINER Stelle in der Bibliothek, und der Text geht
     * auf seine Klasse zurueckgefuehrt mit ein. */
    $sig = mo_mqtt_signatur($n, $st);
    $sigf = mo_tmpdir() . '/mqtt_sig_' . $n . '.txt';
    $beat = mo_tmpdir() . '/mqtt_beat_' . $n;
    $old = is_file($sigf) ? (string) file_get_contents($sigf) : '';
    if ($sig !== $old || !is_file($beat) || time() - filemtime($beat) > 1800) {
        mo_mqtt_publish($st, $n);
        @file_put_contents($sigf, $sig);
        @touch($beat);
    }
}

/* ZULETZT das Lebenszeichen senden, ohne auf einen Zustandswechsel zu
 * warten: der Zeitstempel aendert sich bei JEDEM Durchgang, und ueber MQTT
 * gibt es kein Alter - nur einen Zeitstempel, der frisch sein muss. Der
 * Doppelt-senden-Filter oben wird dafuer uebergangen. Seit 1.1.6 gehen
 * hier auch <praefix>/ts und <praefix>/zaehler je Maeher mit (A6). */
mo_mqtt_lebenszeichen();

echo 'OK;GEMESSEN=' . $mo_gemessen . ';MAEHER=' . $mo_anzahl
   . ';ZAEHLER=' . (int) $mo_lauf['zaehler'] . "\n";
