# LoxBerry-Plugin: Rasenmäher (Robonect)

Bindet einen Mähroboter mit **Robonect-Modul** (HX/Hx+) an Loxone an — und nimmt
dabei vor allem eines aus der Loxone-Projektdatei heraus: **das Passwort**.

Bisher übliche Praxis ist eine URL wie
`http://mäher/xml?user=admin&pass=geheim&cmd=status` direkt im virtuellen Eingang.
Damit steht das Klartext-Passwort in der Projektdatei, in jedem Backup und in
jeder Datei, die man zur Fehlersuche weitergibt. Dieses Plugin hält die
Zugangsdaten lokal (Dateirechte 0600, HTTP-Basic-Auth) — Loxone ruft nur noch
`http://loxberry/plugins/robonect/mower.php` auf.

Kompatibel mit LoxBerry 3.x und **LoxBerry 4** (reines PHP, PHP 7.4 und 8.x).

## Neu in 1.0.11

**Token pruefbar, ohne etwas auszuloesen.** Neuer Aufruf
`?selftest=1&token=…` — antwortet `SELFTEST;OK=1;TOKEN=OK` beziehungsweise
HTTP 403 mit `SELFTEST;OK=0;ERR=TOKEN`. Es wird dabei nichts geschaltet und
nichts angefahren. Hausstandard fuer alle Aktionsendpunkte.

## Was 1.0.4 behebt

Vier Meldungen eines Mitlesers. Alle vier treffen zu — bei der wichtigsten
stimmt allerdings weder die Zahl noch die Begründung, und das ändert die
Abhilfe.

### Zeitgrenze: richtig erkannt, falsch hergeleitet

Gemeldet: zwei offline Mäher kosten 2 × 8 s in `mo_events_check()` und
nochmals 2 × 8 s in der Cron-Schleife, zusammen 32 s. Nachgemessen gegen ein
Gegenstück, das die Verbindung annimmt und dann schweigt:

| | vorher | nachher |
|---|---|---|
| ein einzelner `mo_api()` | 8,0 s | 3,0 s |
| `mo_state()` (ein Mäher) | **16,0 s** | 3,0 s |
| `mo_events_check()`, zwei Mäher | 32,0 s | 6,0 s |
| danach die Cron-Schleife | **0,0 s** | 0,0 s |
| `mower.php` — was Loxone sieht | **16,1 s** | 3,1 s |
| derselbe Aufruf gleich danach | 16,1 s | **0,0 s** |

Zwei Korrekturen an der Herleitung:

Die 16 Sekunden je Mäher kommen **nicht** von einem doppelten Aufruf im Cron,
sondern von **zwei API-Abrufen in `mo_state()`** — `status` und `health`. Die
Cron-Schleife danach kostet gemessene 0,0 s, weil `mo_state()` das Ergebnis
zwischenspeichert, auch das gescheiterte. Die genannte Summe stimmt also
zufällig, der Weg dorthin nicht.

Der eigentliche Schaden steht in der vorletzten Zeile: **16,1 s in
`mower.php`**. Ein Loxone-Miniserver bricht einen virtuellen HTTP-Eingang
nach wenigen Sekunden ab — er bekommt gar nichts, während auf dem LoxBerry
ein Arbeiter blockiert ist.

Drei Änderungen, nicht nur eine:

1. Zeitgrenze 8 → **3 s**, wie vorgeschlagen.
2. `health` wird nur noch versucht, **wenn `status` geklappt hat**. Das halbiert
   die Wartezeit eines stummen Mähers, ohne im Normalfall etwas wegzulassen.
3. Ein **Merker „antwortet gerade nicht"** (60 s). Solange er steht, kehrt
   `mo_api()` sofort zurück. Deshalb die 0,0 s in der letzten Zeile — bei
   Loxone, das im Sekundentakt pollt, ist das der Unterschied zwischen
   „hängt" und „antwortet".

Gegengeprüft mit einem *antwortenden* Mäher: Status, Betriebsart, Batterie,
Betriebsstunden, Temperatur und Feuchte aus dem zweiten Abruf, WLAN und
Messerrestzeit kommen unverändert an.

### Race Condition beim Zwischenspeicher — zutreffend

Vier Sekunden gleichzeitiges Lesen und Schreiben:

| | vollständig | **kaputt** | leer |
|---|---|---|---|
| bisher | 26.558 | **9.039** | 320.934 |
| atomar | 56.636 | **0** | 0 |

Die leeren sind der häufigere Fall: `file_put_contents` kürzt die Datei
zuerst auf null. Jetzt Nebendatei plus `rename()`.

### Protokoll: Speicher ja, `tail` nein

Der Hinweis auf den RAM stimmt. Der empfohlene Weg über das Programm `tail`
ist aber die **langsamere** Lösung — an einer 522-kB-Datei gemessen, 200
Zeilen Ausgabe:

| Verfahren | Zeit | Speicher |
|---|---|---|
| `file()` + `array_reverse` (bisher) | 0,8 ms | 1436 KB |
| `exec("tail -n 200")` (empfohlen) | 1,7 ms | 34 KB |
| rückwärts mit `fseek` (jetzt) | **0,3 ms** | **34 KB** |

Rückwärts lesen ist bei beidem besser und kommt ohne fremden Prozess aus.

### Passwortmaskierung — zutreffend

`str_replace($pw, '***', $msg)` mit einem kurzen Passwort macht die Meldungen
unlesbar: Bei Passwort `at` wird aus „Status=mäht Batterie=80 %" ein
„St\*\*\*us=m\*\*\*eht …". Maskiert wird jetzt erst ab vier Zeichen. Die
Maskierung ist ohnehin nur ein Sicherheitsnetz — in die Meldungen gerät
regulär kein Passwort.

### Hausstandard

Die Reiter waren `<div>` ohne Verweis, `sm-active` vergab allein das
JavaScript — ohne JavaScript war die Seite leer und die Reiter nicht einmal
anklickbar. Jetzt echte Verweise mit serverseitigem `sm-active`, alle vier
über `?form=…` geprüft. Dazu `uninstall` und `prerelease.cfg` ergänzt
(`PRERELEASECFG` war leer bei eingeschaltetem Auto-Update), fünf tote
Sprachschlüssel entfernt — 238 Schlüssel, deutsch und englisch deckungsgleich
— und das Symbol auf das neue Hausmuster gebracht. Beide PHP-Fassungen
liefern in beiden Sprachen zeichengleiche Ausgabe ohne eine Meldung.

## Funktionen

- Status als **Zahl** (parkt, mäht, sucht Ladestation, lädt, Fehler,
  Schleifensignal verloren, abgeschaltet, schläft) plus Klartext
- Betriebsart, Akku, Fehlercode und Fehlertext, Betriebsstunden, Laufzeit des
  aktuellen Einsatzes, Temperatur und Feuchte im Modul, WLAN-Signal
- **Messerwechsel-Überwachung**: Intervall in Betriebsstunden, Restlaufzeit,
  Warnung — Quittieren per Knopf oder `?cmd=blade_reset`
- **Steuerung** per einfachem GET: `auto`, `man`, `home`, `eod`, `start`, `stop`
- **Meldungen** als Ansage (TTS) und/oder Push: Störung, Schleifensignal
  verloren, Mähen beendet, Messerwechsel fällig, Akku unter 20 %
- Bis zu **2 Mäher**, MQTT, JSON, Protokoll (Passwörter werden maskiert)
- Reiter: Einstellungen, Einbindung in Loxone (mit kompletter Baustein-Liste
  inkl. Regen- und Ruhezeitensperre), Test, Protokoll

## Endpunkte

| Aufruf | Zweck |
|---|---|
| `/plugins/robonect/mower.php` | Loxone-Zeile `MOWER;OK=..;CODE=..;BATT=..;STUNDEN=..;MESSER=..;…` |
| `/plugins/robonect/mower.php?debug=1` | Klartext-Übersicht |
| `/plugins/robonect/mower.php?json=1` | kompletter Zustand als JSON |
| `/plugins/robonect/mower.php?cmd=auto` | Automatik (auch `man`, `home`, `eod`, `start`, `stop`) |
| `/plugins/robonect/mower.php?cmd=blade_reset` | Messerwechsel quittieren |

## Sicherheit

- Zugangsdaten ausschließlich in `config/plugins/robonect/mower.json`
  (Dateirechte 0600), Übertragung per HTTP-Basic-Auth statt in der URL
- Das Passwortfeld zeigt den gespeicherten Wert nie an; leer lassen behält ihn
- Vor dem Schreiben ins Protokoll werden Passwörter maskiert
- **Keine personenbezogenen Daten** im Plugin selbst

## Lizenz

MIT — siehe [LICENSE](LICENSE).
