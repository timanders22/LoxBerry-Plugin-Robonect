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

## Neu in 1.1.4

Diese Fassung behebt neunzehn gemessene Befunde aus einer vollständigen
Durchsicht. Die Messungen stehen in der Befundliste zur Vorfassung.

**Umstiegsfolgen — bitte vor dem Einspielen lesen:**

- **Bei nicht erreichbarem Mäher senden `BATT`, `FEUCHTE` jetzt `-1` und
  `TEMP`, `WLAN` jetzt `-999`** statt einer 0. Eine 0 ist bei diesen vier
  Feldern ein gültiger Messwert: `BATT=0` heißt „Akku leer“, `WLAN=0` bei
  MaxVal 0 heißt „bestes Signal“. Eine Loxone-Regel „Warnung, wenn `BATT`
  unter 20“ sprach deshalb bei jedem Verbindungsabriss an. **Wer solche
  Regeln hat, prüft sie** — und importiert die Vorlage neu, weil `MinVal`
  mitgezogen wurde.
- **`?debug=1` verlangt jetzt ein Token.** Der Aufruf nennt Name und Adresse
  des Mähers, WLAN-Pegel und den Nullpunkt des Messerwechsels; der Endpunkt
  liegt im unangemeldeten Bereich.
- **`?cmd=…&json=1`** wird abgewiesen statt still zu JSON zu werden.
- **Fehlgeschlagene Befehle antworten nicht mehr mit HTTP 200**, sondern mit
  400 (Anfrage taugt nicht), 409 (nicht eingerichtet) oder 502 (Gerät hat
  nicht geliefert).
- **Der `Comment` der Importvorlage ist kürzer.** Loxone Config macht daraus
  den Anzeigenamen der Kachel; sechs davon waren ganze Sätze, der längste 95
  Zeichen. Ein erneuter Import legt **neue** Bausteine an und überschreibt
  nichts — wer die Namen übernehmen will, löscht die alten selbst.

**Neu:**

- **Messerwechsel je Mäher.** Intervall und Nullpunkt stehen jetzt in der
  Mäher-Tabelle, zwei Felder je Zeile. Bleibt ein Feld leer, gilt die
  Vorgabe unter der Tabelle — **bestehende Anlagen ändern sich damit
  nicht**: jeder Mäher erbt den bisherigen globalen Wert, bis Sie etwas
  eintragen. Bis 1.1.3 galt ein einziger Nullpunkt für alle, während Kachel
  und Warnung je Mäher angezeigt wurden; ein Quittieren für Mäher 2
  verschob den Nullpunkt aller anderen mit. Der Knopf „Messerwechsel
  quittieren“ hat ab zwei Mähern eine Auswahl, und
  `?cmd=blade_reset&dev=2&token=…` quittiert gezielt den zweiten.

**Behoben:**

- **`cron.php` war über HTTP ohne Token erreichbar.** Gemessen: ein Aufruf
  aus dem Netz antwortete HTTP 200 und trieb den Laufzähler über fünf
  Aufrufe von 1 auf 6. Damit ließ sich genau die Auskunft fälschen, an der
  der Miniserver einen stehenden Cron erkennt. Der Lauf läuft jetzt nur noch
  auf der Kommandozeile.
- **Ohne `php-curl` folgte das Plugin einer Weiterleitung** und schickte die
  Zugangsdaten des Mähers ein zweites Mal an das Umleitungsziel. Der
  curl-Zweig verbot das seit jeher; der Ersatzweg tut es jetzt auch.
- **`?cmd=blade_reset` setzte den Nullpunkt auf 0, wenn der Mäher schwieg** —
  und meldete `OK=1`. Der echte Nullpunkt war damit fort. Jetzt bleibt er
  stehen, und die Antwort sagt, warum.
- **Die Konfiguration wurde im Aktualisierungsfall nie vervollständigt.** Der
  Reiter Test meldete auf jeder bestehenden Anlage dauerhaft „es fehlen 7 von
  10“, ohne dass die Oberfläche es je behob.
- **Die eigene Sicherung wurde abgelehnt**, sobald die Anlage von der
  Einzelgeräte-Fassung stammte: die Altschlüssel `ip`, `user`, `pass`
  wanderten mit und galten beim Zurückspielen als „unbekannt“.
- **Lebenszeichen, Fehlerhistorie und Einsatzstatistik überstehen jetzt ein
  Update.** Bis 1.1.3 räumte der Installer `data/plugins/<ordner>/` bei jedem
  Upgrade ab, und `preupgrade.sh` sicherte nur `mower.json` und `mower.log`.
  Der Laufzähler fing danach bei 0 an — am Miniserver nicht von einem
  stehengebliebenen Cron zu unterscheiden.
- **Eine tokenlose, abgewiesene Anfrage legte die Konfigurationsdatei an**,
  wenn eine Zweitschrift daneben lag — der Zustand nach jedem Upgrade. Der
  unangemeldete Endpunkt liest jetzt nur.
- **Vier Zeilen der Themen-Tabelle standen ohne Bedeutung da** (`batterie`,
  `messer_rest`, `messer_warn`, `temperatur`): der Feldname wurde aus dem
  Themennamen gerechnet statt zugeordnet.
- **Die Cron-Sperre war eine Vorprüfung**, kein `flock`; die Abschlussfunktion
  löschte die Sperre nach Pfad und traf damit die des anderen Laufs.
- **`cron.01min` warf Ausgabe und Rückgabewert weg.** Ein Fehlschlag geht
  jetzt ins Protokoll des Plugins.
- **Der Selbsttest meldete `FASSUNG=1.1.0`**, seit drei Fassungen. Die Nummer
  kommt jetzt aus der Plugin-Datenbank von LoxBerry.
- **`?dev=` wurde still zurechtgebogen**: `?dev=99999` lieferte unauffällig
  die Werte von Mäher 1.
- Ein **geleertes Adressfeld** verwarf die Mäher-Zeile samt Kennwort, obwohl
  der Hilfetext daneben zusagte, gelöscht werde nur über den Haken.
- `?roh=` und `?ptest=` unterscheiden jetzt „kein Token eingerichtet“ von
  „falsches Token“.
- Der Auftragsparameter `?p=` wird gegen eine Positivliste geprüft und
  abgewiesen statt gefiltert; `&` kam bisher durch.
- `mo_say()` hält eine Verbindungs-Zeitgrenze ein — ohne sie konnte eine
  Ansage an einen stummen Music-Server den Cron über die Minute ziehen.
- MQTT-Zeilen tragen ein Zeilenende; ein kurzer Schreibvorgang gilt nicht
  mehr als Erfolg.
- Der Arbeitsordner heißt jetzt `/tmp/<ordner>` statt fest `/tmp/robonect` —
  bei einer Zweitinstallation teilten sich beide Sperrdatei und Zustand.
- Kleinere Berichtigungen: `mo_kuerzen()` schnitt eine abgeschnittene
  UTF-8-Folge nicht ab, `json_encode()`-Fehlschläge blieben stumm, eine
  `mkdir`-Warnung stand in jedem Prüflauf, die Prüzeile „Themenliste gegen
  den Sendecode“ verglich nichts, tote Reste entfernt.

## Neu in 1.1.1 bis 1.1.3

Zu diesen drei Schritten stand hier nichts. Nachgetragen, gemessen am
Dateibefund — nicht an einer Absicht:

- **1.1.3** ändert genau einen Sprachwert je Sprache: die Überschrift des
  Abo-Kastens heißt „Das Abo im MQTT-Gateway“ statt „Dieses Abo im
  MQTT-Gateway eintragen“. Unter Gateway V2 gibt es nichts einzutragen.
- **1.1.1 und 1.1.2** lassen sich hier nicht mehr belegen: weder die Ordner
  noch die Archive liegen im Arbeitsordner. Was sie geändert haben, steht auf
  ihren Release-Seiten.

## Neu in 1.1.0

Diese Fassung behebt drei Dinge, die **gemessen** wurden, und ergänzt vier
Funktionen. Die vollständige Liste mit den Messwerten steht in der
Vorschlagsdatei zu 1.0.13.

**Der Knopf „Einstellungen sichern" lieferte keine Datei.** Sein Zweig stand
hinter `LBWeb::lbheader()`; der Seitenkopf war damit schon geschrieben, und
`header()` kam zu spät. Statt eines Downloads bekam der Anwender zwei Warnungen
und die vollständige Konfiguration **samt Aktionstoken als sichtbaren Text** in
einer HTML-Seite. Die Reihenfolge in `index.php` ist jetzt Bauvorschrift, und
eine Zeile im Reiter Test prüft sie nach.

**Eine beschädigte Konfigurationsdatei riss die Zweitschrift mit.** Ungültiges
JSON galt stillschweigend als leer; ein einziger Aufruf der Oberfläche genügte,
um Mäher, Passwort, Nullpunkt und MQTT-Einstellung zu verlieren, ein neues
Aktionstoken zu würfeln — womit jede Loxone-Adresse auf 403 lief — und die
Zweitschrift mit der Werkseinstellung zu überschreiben. Ohne eine einzige
Meldung. Jetzt bleibt die beschädigte Datei als `mower.json.kaputt` liegen, es
gibt genau eine Protokollzeile, die Konfiguration wird aus der Zweitschrift
wiederhergestellt, und die Zweitschrift wird nur noch überschrieben, wenn
wirklich eine Konfiguration **mit** Token gespeichert wird.

**Ein Formular von einer fremden Seite konnte das Aktionstoken neu würfeln.**
`htmlauth/` schützt gegen den unangemeldeten Aufruf, nicht dagegen, dass der
Browser eines angemeldeten Bedieners ein untergeschobenes Formular abschickt.
Danach hätten sämtliche Virtuellen Ausgänge HTTP 403 bekommen — still, denn ein
Virtueller Ausgang wertet die Antwort nicht aus. Jedes der Formulare trägt
jetzt ein aus dem Aktionstoken abgeleitetes Merkmal, geprüft von **einem**
Wachposten vor allen Handlern.

Dazu:

- **Ein Lebenszeichen.** Ein virtueller Eingang behält seinen letzten Wert;
  fällt der Cron-Lauf aus, steht in Loxone weiter „parkt, Akku 80 %". Neu sind
  `TS` (Zeitstempel), `ZAEHLER` (läuft 0…999 um) und `FEHLERALTER`, über MQTT
  zusätzlich `<präfix>/status/ts`, `/status/zaehler` und `/status/ok`.
  Baustein 8 der Liste im Reiter „Einbindung in Loxone" wertet das aus.
- **Der MQTT-Gateway wird nach seiner Fassung gefragt.** `Gatewayversion` aus
  `general.json` entscheidet, ob das Abo von Hand einzutragen ist (V1) oder
  nicht (V2). Ist die Fassung nicht lesbar, stehen beide Sätze da. Der Schritt
  „Abo eintragen" fehlte bisher ganz.
- **Bis zu neun Mäher** statt zwei, mit ausgeschriebenem Index und Löschhaken.
- **Einsatzstatistik** (ab Werk aus), **Fehlerhistorie**, **Trockenlauf** für
  die Steuerbefehle und ein Knopf für die **rohe Antwort** des Moduls.
- Der Reiter Test hat eine **Selbstprüfung** mit zwölf Zeilen; bisher standen
  dort nur Knöpfe.
- Der Netzabruf läuft über `curl`, wenn `php-curl` vorhanden ist, sonst über
  `file_get_contents` — beide mit eigener Verbindungs-Zeitgrenze —
  `file_get_contents` deckt mit `timeout` nur das Lesen ab, für den
  Verbindungsaufbau galt `default_socket_timeout` mit 60 Sekunden. Ein falsches
  Passwort wird jetzt als solches gemeldet und nicht mehr als „keine
  Verbindung".
- Der MQTT-Versand kommt ohne `php-sockets` aus. `@socket_create()` fängt einen
  fehlenden Funktionsnamen nicht ab — der Cron-Lauf starb dann mitten in der
  Schleife, und `?ptest=1` antwortete dem Miniserver mit HTTP 500.

**Nicht am Gerät geprüft:** Diese Fassung ist gegen einen Prüfstand gemessen,
nicht gegen ein Robonect-Modul. Die Knöpfe „Rohe Antwort" im Reiter Test sind
genau dafür da, die offenen Fragen an der eigenen Anlage zu beantworten.

## Neu in 1.0.12

**Der MQTT-Weg liefert jetzt alles, was der HTTP-Weg liefert.** Bisher
veröffentlichte das Plugin über MQTT 15 Werte, über HTTP aber 20: es fehlten
`timer` und die vier Melde-Merker `ann` (Meldefenster), `audio` und `push`
(Freigaben aus der Konfiguration) sowie `ptest` (Test-Push). Wer auf MQTT
umstellte, verlor damit genau die Werte, mit denen sich Ansage und
Pushnachricht im Miniserver steuern und **prüfen** lassen — der Test-Push
löste über MQTT schlicht nicht mehr aus.

Drei Änderungen, damit das wirklich wirkt:

- Die vier Merker kommen aus **einer** Funktion (`mo_meldeflags()`), die
  beide Wege benutzen. HTTP und MQTT können nicht mehr auseinanderlaufen.
- Sie stehen jetzt auch in der **Signatur** des Cron-Laufs. Ohne das wären
  sie zwar in der Nachricht gewesen, die Nachricht aber nicht verschickt
  worden: `ann` und `ptest` ändern sich allein durch Zeitablauf, nicht durch
  einen Zustandswechsel — ein gesetzter `ptest` wäre bis zum halbstündlichen
  Lebenszeichen liegengeblieben, sein Fenster ist aber nur fünf Minuten breit.
- `?ptest=1` veröffentlicht **sofort**, statt bis zu eine Minute auf den
  nächsten Cron-Lauf zu warten. Ein Test, der erst eine Minute später wirkt,
  sieht aus wie ein Test, der nicht wirkt.

**`?ptest=1` verlangt jetzt ein Token.** Bisher konnte jedes Gerät im Heimnetz
dem Anwender eine Pushnachricht aufs Telefon schicken — und seit dieser Fassung
hätte es zusätzlich eine MQTT-Meldung ausgelöst. Der Saugroboter verlangt seit
1.0.4 ein Token, hier fehlte es. Die Adresse steht in der Oberfläche mit Token
dabei; wer sie nirgends verdrahtet hatte, merkt nichts.

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
- Bis zu **neun Mäher**, MQTT, JSON, Protokoll (Passwörter werden maskiert)
- Reiter: Einstellungen, Einbindung in Loxone (mit kompletter Baustein-Liste
  inkl. Regen- und Ruhezeitensperre), Test, Protokoll

## Endpunkte

| Aufruf | Zweck |
|---|---|
| `/plugins/robonect/mower.php` | Loxone-Zeile `MOWER;OK=..;CODE=..;BATT=..;STUNDEN=..;MESSER=..;…` |
| `/plugins/robonect/mower.php?json=1` | kompletter Zustand als JSON |
| `/plugins/robonect/mower.php?refresh=1` | Zwischenspeicher übergehen und frisch messen |
| `/plugins/robonect/mower.php?dev=2` | zweiter Mäher (1 bis 9; unzulässige Nummern werden mit HTTP 400 abgewiesen) |
| `/plugins/robonect/mower.php?debug=1&token=…` | Klartext-Übersicht **(Token nötig ab 1.1.4)** |
| `/plugins/robonect/mower.php?selftest=1&token=…` | prüft nur das Token, löst nichts aus |
| `/plugins/robonect/mower.php?roh=status&token=…` | rohe Antwort des Moduls (Weißliste, nur lesende Befehle) |
| `/plugins/robonect/mower.php?cmd=auto&token=…` | Automatik (auch `man`, `home`, `eod`, `start`, `stop`, `job`) |
| `/plugins/robonect/mower.php?cmd=blade_reset&token=…` | Messerwechsel quittieren |
| `/plugins/robonect/mower.php?cmd=start&probe=1&token=…` | Trockenlauf: sagt, was gesendet würde |
| `/plugins/robonect/mower.php?ptest=1&token=…` | Test-Pushnachricht auslösen **(Token nötig ab 1.0.12)** |

**Jeder auslösende Aufruf verlangt `&token=…`** — den Wert zeigt der Reiter
*Einbindung in Loxone*. Ohne ihn antwortet der Endpunkt mit HTTP 403, und ein
Virtueller Ausgang meldet das nicht: die Adresse sieht dann aus, als hätte sie
gewirkt. Bis 1.1.3 standen die Befehlszeilen in dieser Tabelle ohne Token — wer
sie abschrieb, bekam 403.

`?json=1` lässt sich **nicht** mit einem Befehl verbinden: `?cmd=stop&json=1`
wird seit 1.1.4 mit HTTP 400 und `ERR=MEHRDEUTIG` abgewiesen. Bis 1.1.3 gewann
stillschweigend das JSON, und der Befehl geschah nie.

`cron.php` im selben Verzeichnis ist **kein** Endpunkt, sondern der minutliche
Lauf. Er antwortet seit 1.1.4 nur noch auf der Kommandozeile; ein Aufruf über
HTTP wird mit HTTP 403 abgewiesen (siehe *Neu in 1.1.4*).

## Sicherheit

- Zugangsdaten ausschließlich in `config/plugins/robonect/mower.json`
  (Dateirechte 0600), Übertragung per HTTP-Basic-Auth statt in der URL
- Das Passwortfeld zeigt den gespeicherten Wert nie an; leer lassen behält ihn
- Vor dem Schreiben ins Protokoll werden Passwörter maskiert
- **Keine personenbezogenen Daten** im Plugin selbst

## Lizenz

MIT — siehe [LICENSE](LICENSE).
