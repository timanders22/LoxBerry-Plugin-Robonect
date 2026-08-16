<?php
/**
 * Rasenmaeher (Robonect) - minutlicher Cron-Lauf
 *
 * 1. Status aller Maeher aktualisieren (Cache-schonend).
 * 2. Ereignisse melden: Fehler, Schleifensignal verloren, Maehen beendet,
 *    Messerwechsel faellig, schwacher Akku.
 * 3. MQTT bei Aenderung, mindestens halbstuendlich.
 */

require_once __DIR__ . '/mower_lib.php';

mo_events_check();

foreach (mo_mowers() as $n => $m) {
    $st = mo_state($n);
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
echo "OK\n";
