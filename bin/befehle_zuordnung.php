<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Rasenmaeher (Robonect).
 *
 * Gruppe 2 / MQTT (1.1.24; Bauvorschrift wz_bild/GRUPPE2_BAUVORSCHRIFT.md, Entwurf Musterprojekt/entwurf_gruppe2_rb.txt
 * Abschnitt 3, ENTSCHEIDUNGEN_GRUPPE2_MQTT.md Punkt 2; Muster vb_g1_bruecke_bau/beispiel/bin/befehle_zuordnung.php).
 * Die Bruecke selbst (bin/befehlsbruecke.php und .sh) ist byte-gleich die Stammfassung Werkzeuge/gemeinsam/ -
 * linieneigen ist nur diese Datei.
 *
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts.
 * 'tun' ruft DIESELBE Funktion wie der HTTP-Endpunkt mower.php - mo_command() bzw. mo_blade_reset() - mit Quelle 'mqtt'
 * und gibt array(bool ok, string grund) zurueck (mo_befehl_ergebnis(), mo_befehl_messer()). Die Token-Pruefung des
 * Endpunkts ersetzen die Anmeldung am Broker und die beiden Haken.
 *
 * Sieben Pulse (nur "1"; Loxone sendet feste 1). Sechs davon fahren den Maeher und stehen hinter dem zweiten Haken
 * "Maeher ueber MQTT steuern" (befehle_mqtt_steuern, ab Werk aus, Entwurf rb E11); messer_gewechselt braucht nur den
 * ersten. Sperrzeit (Hausherr 19.10.2026, ENTSCHEIDUNGEN Punkt 2): starten und anhalten 10 s, Betriebsarten 60 s. Die
 * Sperre der Bruecke gilt fuer gleichen Namen und gleichen Wert - ein Puls hat immer den Wert 1, also je Name.
 * Maeher 2 bis 9 unter <praefix>/befehl/<n>/<name> (Brueckenbericht offene Frage 4, wie Abfuhrkalender Kalender n);
 * die Bruecke abonniert nur <praefix>/befehl/# des Grundpraefixes.
 * Ein Befehl haelt die Bruecke bis zu 5 s auf (Zeitgrenze des Abrufs). "abgelehnt: KEINE_ANTWORT" heisst: der Maeher hat
 * nicht geantwortet - der Befehl kann trotzdem gewirkt haben (Reiter "Einbindung in Loxone", Schritt 5).
 */
if (!function_exists('mo_config')) {
    $mo_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $mo_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/mower_lib.php';
    }
    $mo_kand[] = dirname(__DIR__) . '/webfrontend/html/mower_lib.php';
    foreach ($mo_kand as $mo_k) {
        if (is_file($mo_k)) { require_once $mo_k; break; }
    }
}
$mo_zehn = 'Auftraege, im Plugin ungebremst; Anhalten und wieder Starten binnen einer Minute muss gehen (Hausherr 19.10.2026)';
$mo_sechzig = 'Betriebsart: wie die Gleichwert-Sperre des Plugins (60 s); ein anderer Modus geht sofort (Hausherr 19.10.2026)';
$mo_befehl = function ($cmd, $titel, $kachel, $sperre, $grund) {
    return array('art' => 'puls', 'eingreifend' => true, 'sperre_s' => $sperre, 'begruendung' => $grund,
                 'titel' => $titel, 'kachel' => $kachel, 'quittung' => 'ausgefuehrt',
                 'tun' => function ($w, $t) use ($cmd) {
                     return mo_befehl_ergebnis(mo_command($cmd, isset($t['nr']) ? (int) $t['nr'] : 1, '', false, 'mqtt'));
                 });
};
$mo_liste = array(
    'starten'     => $mo_befehl('start', 'Rasenmäher Mähen starten (MQTT)', 'BEFEHL.K_STARTEN', 10, $mo_zehn),
    'anhalten'    => $mo_befehl('stop', 'Rasenmäher Mähen anhalten (MQTT)', 'BEFEHL.K_ANHALTEN', 10, $mo_zehn),
    'automatik'   => $mo_befehl('auto', 'Rasenmäher Automatik (MQTT)', 'BEFEHL.K_AUTOMATIK', 60, $mo_sechzig),
    'heim'        => $mo_befehl('home', 'Rasenmäher zur Ladestation (MQTT)', 'BEFEHL.K_HEIM', 60, $mo_sechzig),
    'feierabend'  => $mo_befehl('eod', 'Rasenmäher Ende des Tages (MQTT)', 'BEFEHL.K_FEIERABEND', 60, $mo_sechzig),
    'handbetrieb' => $mo_befehl('man', 'Rasenmäher Handbetrieb (MQTT)', 'BEFEHL.K_HANDBETRIEB', 60, $mo_sechzig),
    'messer_gewechselt' => array('art' => 'puls', 'eingreifend' => false, 'titel' => 'Rasenmäher Messerwechsel quittieren (MQTT)',
                                 'kachel' => 'BEFEHL.K_MESSER', 'quittung' => 'ausgefuehrt',
                                 'tun' => function ($w, $t) {
                                     return mo_befehl_messer(isset($t['nr']) ? (int) $t['nr'] : 1);
                                 }),
);
$mo_befehle = $mo_liste;
foreach ($mo_liste as $mo_n => $mo_e) {
    $mo_e['titel'] = preg_replace('/ \(MQTT\)\z/', ' Mäher n (MQTT)', $mo_e['titel']);
    $mo_befehle['{nr}/' . $mo_n] = $mo_e;
}
return array(
    'fassung' => 1,
    'linie'   => 'robonect',
    'plugin'  => 'Rasenmäher',
    'geladen' => function_exists('mo_config') && function_exists('mo_command') && function_exists('mo_befehl_ergebnis'),
    'zweiter_haken' => 'befehle_mqtt_steuern',
    'einstellungen' => function () {
        return mo_befehle_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        mo_log('Befehlsbruecke ' . ($stufe === 'INFO' ? '' : $stufe . ': ') . $text);
    },
    // Maeher 2 bis 9 (mo_max_maeher() = 9); Maeher 1 hat den Namen ohne Nummer
    'teile' => array('nr' => '[2-9]'),
    'befehle' => $mo_befehle,
);
