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
