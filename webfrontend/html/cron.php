<?php
/**
 * Rasenmaeher (Robonect) - minutlicher Cron-Lauf
 *
 * 1. Konfiguration vervollstaendigen (der Dienst ist die zweite Stelle,
 *    an der ein fehlender Schluessel einmal geschrieben wird).
 * 2. Status aller Maeher aktualisieren (Cache-schonend).
 * 3. Ereignisse melden: Fehler, Schleifensignal verloren, Maehen beendet,
 *    Messerwechsel faellig, schwacher Akku.
 * 4. MQTT bei Aenderung, mindestens halbstuendlich.
 * 5. Das Lebenszeichen fortschreiben.
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

foreach (mo_mowers() as $n => $m) {
    $mo_anzahl++;
    $st = mo_state($n);
    if (!empty($st['ok'])) { $mo_gemessen = 1; }
    /* Die Meldeflags gehoeren in die Signatur, sonst waeren sie zwar in der
     * Nachricht - aber die Nachricht ginge nicht raus. ann und ptest aendern
     * sich naemlich OHNE Zustandswechsel, allein durch Zeitablauf. Ohne sie
     * in der Signatur bliebe ein ptest bis zum naechsten Zustandswechsel
     * oder bis zum halbstuendlichen Lebenszeichen liegen - sein Fenster ist
     * aber nur fuenf Minuten breit. */
    $sig = json_encode(array($st['code'], $st['modus'], $st['batterie'], $st['fehler'],
                             $st['stunden'], $st['messer_warn'], mo_meldeflags($n)));
    if ($sig === false) { $sig = 'unlesbar'; }
    $sigf = mo_tmpdir() . '/mqtt_sig_' . $n . '.txt';
    $beat = mo_tmpdir() . '/mqtt_beat_' . $n;
    $old = is_file($sigf) ? (string) file_get_contents($sigf) : '';
    if ($sig !== $old || !is_file($beat) || time() - filemtime($beat) > 1800) {
        mo_mqtt_publish($st, $n);
        @file_put_contents($sigf, $sig);
        @touch($beat);
    }
}

/* Das Lebenszeichen ZULETZT und IMMER - auch wenn kein Maeher geantwortet
 * hat. Genau dann ist es am wichtigsten: es unterscheidet "der Cron laeuft,
 * der Maeher schweigt" von "der Cron laeuft gar nicht mehr". */
$mo_lauf = mo_lauf_vermerken($mo_gemessen);

/* Und einmal senden, ohne auf einen Zustandswechsel zu warten: der
 * Zeitstempel aendert sich bei JEDEM Durchgang, und ueber MQTT gibt es kein
 * Alter - nur einen Zeitstempel, der frisch sein muss. Der
 * Doppelt-senden-Filter oben wird dafuer uebergangen. Es sind drei Themen,
 * nicht der ganze Wertesatz. */
mo_mqtt_lebenszeichen();

echo 'OK;GEMESSEN=' . $mo_gemessen . ';MAEHER=' . $mo_anzahl
   . ';ZAEHLER=' . (int) $mo_lauf['zaehler'] . "\n";
