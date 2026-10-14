# Changelog

## 0.1.37 (Build 38) — 14.10.2026

- Filter für „⚙️ Automatismen“ (Suchtext, Raum, Funktion/Art, Zustand) und „🔎 Entdecken“ (Suchtext, Raum, Art). Wunsch Dietmar.
- Ausgefilterte Automatismen stehen in einer unsichtbaren zweiten Liste (`AutomationsHidden`), die mit „Übernehmen“ gespeichert und beim Lesen zusammengeführt wird — Symcon speichert sonst nur, was in der Liste steht, ausgefilterte wären gelöscht.

## 0.1.36 (Build 37) — 14.10.2026

- **Behoben: Konsole hing beim Speichern** (Dietmar, live). Der Bearbeiten-Dialog enthielt die Felder aller 20 Arten (rund 100 KB), und Symcon speichert jedes Dialogfeld in die Listenzeile — über 350 Werte je Zeile. Jetzt: bestehende Automatismen zeigen nur die eigene Art (Art nicht mehr umstellbar), neue über „+“ erst die Art-Auswahl (danach mit ✏️ einstellen). Felder fremder Arten werden beim Lesen entfernt; beim nächsten „Übernehmen“ wird die Liste klein gespeichert.

## 0.1.35 (Build 36) — 14.10.2026

Wunsch Dietmar („alles auf einmal“). Neu und **nur am Prüfstand getestet**:
- Fünf Automatismen: 🚪 Gehen-Check, 🛎 Türklingel (Kamerabild an Skript-Meldewege als `$_IPS['MEDIA']`), ☀️ Sommer-Lüften (Taupunkt), 🧊 Ausfall-Wächter, 📅 Zeitschaltuhr.
- 📄 Duplizieren mit Übertragen auf einen anderen Raum (Geräte gleicher Rolle werden eingesetzt).
- 🧪 Probelauf je Automatismus.
- 📅 Feiertage (DE nach Bundesland, AT, CH) für Rollläden, Aufwachlicht, Zeitschaltuhr.
- 📊 Statistik (Schaltungen/Meldungen je Automatismus, Hinweis „30 Tage nichts getan“).
- 💡 Gewohnheitsvorschläge aus dem Archiv (Licht werktags meist zur selben Zeit aus → Zeitschaltuhr „nur ausschalten“).
- Entdecken erkennt Türklingeln, Kühlschränke/Gefriertruhen mit Leistungsmessung und Raum-/Außentemperaturen.

## 0.1.34 (Build 35) — 14.10.2026

- Forum-Thread verlinkt (Hinweis „Rückmeldungen“ und „Dokumentation & Hilfe“), README-Stand aktualisiert.
- Erster Eintrag im „🆕 Neu bis Version“-Bereich (gepflegt ab der Store-Veröffentlichung).

## 0.1.33 (Build 34) — 14.10.2026

- Zustandstexte zeigen Uhrzeiten mit Stunden Abstand ohne Sekunden („Rollläden schließen um 18:41 Uhr“ statt „18:41:36 Uhr“); kurze Countdowns behalten die Sekunden.

## 0.1.32 (Build 33) — 14.10.2026

Store-Reifeprüfung (SUITE.md-Checkliste, Formular-Optik, Stolpersteine):
- Alle Lesezugriffe auf Einstellungen und Speicher typsicher (während die Instanz neu lädt, liefert Symcon dort `false` — Checkliste 9c).
- Eigene Variablen behalten ihre Position im Objektbaum; vorgegeben wird sie nur beim Anlegen (9m).
- Variable einer fremden Instanz ohne Aktion wird nicht mehr per SetValue „geschaltet“ (änderte nur die Anzeige) — stattdessen klarer Grund im Protokoll und Meldungslog.
- Meldewege ohne Visualisierung bzw. ohne Skript werden im Meldungslog vermerkt (vorher nur im Debug).
- Offenes Formular wird nachgezogen: Katalog-Zeilen „gefunden/fehlt“ nach der Suche, Knopf „Ausgeblendete Vorschläge wieder anzeigen“ nach Ausblenden/Wiederholen.
- Helligkeitswerte in den Einstellungen mit der Einheit des Sensors statt fest „lx“; Profile, die in der Darstellung stecken, werden für Einheit und Werteauswahl berücksichtigt.
- Formular-Aufbau: berechnete Spalte „Bereich“ wird nicht gespeichert. Beispieltext ohne Namen der Entwickleranlage. README-Abzeichen mit aktueller Version.

## 0.1.31 (Build 32) — 13.10.2026

Sieben neue Automatismen (Wunsch Dietmar: „alles zusammen einbauen“). **Nur am Prüfstand getestet — an echter Hardware ungeprüft** (Dietmar hat die Geräte nicht):
- 🚨 **Wasser-/Rauchmelder-Reaktion**: Licht voll an, Rollläden auf, Wasserventil/Geräte aus, dringende Meldung (auch in der Ruhezeit und bei Pause), Erinnerung bis Entwarnung; schaltet danach bewusst nichts zurück.
- 🪟 **Rollladen nach Zeit/Sonne**: öffnen zur Uhrzeit, bei Sonnenaufgang ± Versatz oder bei „Tag“; werktags/Wochenende frühestens; schließen zur Uhrzeit, bei Sonnenuntergang, bei Dunkelheit (nur nachmittags) oder bei „Nacht“, spätestens; Aussperrschutz (Tür offen → bleibt oben, schließt, sobald zu); fährt beim Einrichten nicht sofort.
- 🔌 **Standby-Abschaltung**: unter X W für Y min → Steckdose aus.
- 💨 **Lüftungserinnerung**: Feuchte oder CO₂ zu hoch (mit Wartezeit) → „Bitte lüften“, Erinnerung; Fenster offen und Luft gut → „kann wieder zu“.
- 🌡 **Heizung nach Hausmodus**: bei Abwesend/Urlaub/(Nacht) absenken, alten Sollwert merken und zurückstellen; von Hand geänderte Sollwerte bleiben.
- 🔘 **Taster-Zuordnung**: kurz/doppelt/lang (Werte wie „single“/„double“/„hold“ oder 1/2/3) → umschalten, an, aus, Wert oder Hausmodus.
- 🌅 **Aufwach-/Einschlaflicht**: Rampe bis zur Weckzeit (Uhrzeit oder Weckzeit-Variable) bzw. herunter bis aus (auch bei „Nacht“); Handeingriff beendet sie.
- Entdecken erkennt dafür Wasser-/Rauchmelder, Rollläden, Raumluft (Feuchte/CO₂, ohne Boden-/Außen-/Prognosewerte) und Taster und schlägt Alarm, Rollläden, Heizung, Lüften je Raum und Taster vor.
- Alarm, Heizung und Szene zeigen im Dialog keine allgemeinen Modus-Häkchen (Sicherheit bzw. der Modus ist der Auslöser). Kachel: Alarm/„Bitte lüften“ gelten als auffällig, Rampe/Absenkung/Standby als laufend.

## 0.1.30 (Build 31) — 13.10.2026

- **Kachel neu gestaltet** (Rückmeldung Dietmar: zu viel Platz, keine Sortierung, Filter oder Suche):
  - Kompakt und mehrspaltig: Gruppen stehen als Kästen nebeneinander, ein Raum mit einem Automatismus belegt keine ganze Zeile mehr; auf dem Handy einspaltig.
  - Suche über Name, Raum, Art und Zustand; der Fokus bleibt beim sekündlichen Mitlaufen der Restzeiten erhalten (nur die Restzeiten werden nachgeführt).
  - Filter mit Anzahl: Alle · Läuft · Auffällig · Gesperrt/inaktiv. Farbiger Rand: blau = läuft, orange = auffällig.
  - Gruppieren nach Raum, nach Funktion oder ohne Gruppen; sortieren nach Name, Zustand oder Art; Gruppen per Klick zuklappen. Die Wahl merkt sich der Browser.
  - Kacheldaten: flache Liste mit Raum, Funktion und Zustand je Automatismus.

## 0.1.29 (Build 30) — 13.10.2026

- Der Schalter je Automatismus heißt jetzt „Aktiv“ statt „Eingeschaltet“ (Dialog und Listenspalte, vorher „An“); Zustand „⚫ Nicht aktiv“, Zusammenfassung „… aktiv“. „Eingeschaltet“ war mit dem Schalten der Geräte zu verwechseln. Rückmeldung Dietmar.

## 0.1.28 (Build 29) — 13.10.2026

- Neuer Automatismus **🌙 Zentral-Aus / Szene nach Hausmodus** (Wunsch Dietmar): Beim Wechsel des Hausmodus auf angekreuzte Modi (Standard: Nacht, Abwesend, Urlaub) bis zu 16 Geräte ausschalten, einschalten (Dimmer mit Helligkeit) oder auf einen Wert setzen (z. B. 17 °C). Wahlweise verzögert (wechselt der Modus vorher weiter, entfällt es). Was schon richtig steht, wird nicht angefasst. Nur beim Wechsel — danach darf jeder wieder schalten. Optional Meldung, was geschaltet wurde (über die gewählten Meldewege).
- Steuert ein anderer Automatismus dasselbe Licht (z. B. Bewegungslicht), gilt das Ausschalten durch die Szene als „zentral“ statt als Handbedienung: keine Handpause, die nächste Bewegung schaltet wieder ein; das Protokoll nennt die Szene. Doppelregelung wird hier nur als ℹ️ (gewollt) angezeigt.
- Entdecken schlägt „Zentral-Aus nach Hausmodus“ mit den gefundenen Lichtern vor. Im Dialog der Szene sind die allgemeinen Modus-Häkchen ausgeblendet (der Modus ist hier der Auslöser).

## 0.1.27 (Build 28) — 13.10.2026

- **Meldewege je Meldung** (Wunsch Dietmar): Unter „🔔 Meldungen“ legst du beliebig viele benannte Meldewege an — Push an alle oder eine bestimmte Visualisierung (Kachel/WebFront), E-Mail über eine SMTP-Instanz an bestimmte Empfänger, oder ein Skript (bekommt $_IPS['TITLE'] und $_IPS['TEXT'], z. B. für Telegram, Pushover, Sprachausgabe). Je Weg: „Standard“ und „Ruhezeit beachten“.
- Im Dialog jedes meldenden Automatismus (Wächter, Gerät fertig, Anwesenheit) hakst du an, über welche Wege er meldet; ohne Haken gelten die Standard-Wege. Die Übersicht im Panel zeigt je Automatismus seine Wege.
- Ruhezeit je Weg: Wege ohne „Ruhezeit beachten“ melden sofort, die anderen holen danach nach — jeweils nur über ihren eigenen Weg.
- Testmeldung über alle Wege mit Ergebnis je Weg. Störungen gehen über die Standard-Wege.
- Übernahme: Die bisherigen Einstellungen (Push an alle/gewählte Visualisierungen, E-Mail) erscheinen als erste Meldewege und gelten weiter, bis du die Liste mit „Übernehmen“ speicherst.

## 0.1.26 (Build 27) — 13.10.2026

- „🪟 Formular: Aufbau“ jetzt mit Kontrollkästchen statt Auswahlliste, genau wie der Batteriewächter 0.16.5 (Anregung Dietmar): Spalten Aufgeklappt | Zugeklappt | 🙈 Ausgeblendet | Position. Kein Haken = Standard; bei mehreren Haken gilt Ausgeblendet vor Zugeklappt vor Aufgeklappt, die Liste zeigt dann nur den geltenden. Gespeicherte Einstellungen aus 0.1.24 werden weiter gelesen. Zurücksetzen leert alle Haken.

## 0.1.25 (Build 26) — 13.10.2026

- Wächter: Der Vergleichswert lässt sich aus den möglichen Werten der Variable auswählen („… oder aus den möglichen Werten der Variable wählen“) — aus der Darstellung (z. B. Matter-Kontakt „Offen“/„Geschlossen“), aus den Profil-Zuordnungen oder bei Schaltern „an / wahr“ / „aus / falsch“. Die Auswahl schreibt den echten Wert ins Feld (wichtig, wo „wahr“ „Geschlossen“ bedeutet) und stellt „ist an/aus“ auf „ist gleich“ um. Wechselt die Variable, folgt die Auswahl sofort; bei Messwerten ohne feste Werte bleibt nur das Eintippen. Meldungen zeigen die Beschriftung („ist gleich Offen … (aktuell Offen)“) statt „false“. Wunsch Dietmar.

## 0.1.24 (Build 25) — 13.10.2026

- **Meldungen: was und wie entscheidet der Nutzer** (Aufbau wie beim Batteriewächter). Neuer Bereich „🔔 Meldungen“:
  - Was: je Automatismus „Melden“ im Dialog (Wächter, Gerät fertig — und neu „Anwesenheit zusammenfassen“: „Alle weg“ / „Heimgekommen“, Standard aus); Übersicht, welche Automatismen melden; wahlweise auch Störungen (Gerät ließ sich nicht schalten).
  - Wie: Push an alle oder nur ausgewählte Visualisierungen (Kachel und WebFront), E-Mail über eine SMTP-Instanz an einen oder mehrere Empfänger (Fehlergrund verständlich, z. B. „Anmeldung abgelehnt“), Ruhezeit (z. B. 22–7 Uhr) mit Nachholen danach.
  - Testmeldung zeigt das Ergebnis je Weg.
  - Bisher ging Push fest an alle Visualisierungen; das bleibt der Standard, bis du etwas anderes wählst.
- **🪟 Formular: Aufbau** (wie Batteriewächter, zusätzlich „🙈 Ausgeblendet“): je Bereich beim Öffnen auf-/zugeklappt, Position, oder ganz ausblenden — für Bereiche, die man selten oder nur einmal braucht (Katalog, Entdecken, Dokumentation). Ausgeblendete Bereiche arbeiten weiter; die Kopfzeile nennt, wie viele ausgeblendet sind, Zurücksetzen holt alle zurück.
- „Meldungen, Protokoll und Kachel“ aufgeteilt in „🔔 Meldungen“ und „📜 Protokoll und Kachel“.
- Wunsch Dietmar.

## 0.1.23 (Build 24) — 13.10.2026

- Hausmodus/Helligkeitssensor: Die Sensor-Vorschläge („💡 Passende Sensoren gefunden …“) erscheinen jetzt sofort beim Umstellen auf „wenn es hell/dunkel wird“, nicht erst nach dem Übernehmen; Einstrahlung von Wetterstationen steht vor Lux-Sensoren in Räumen. Hinweis, dass manche Wetterstationen W/m² mit „lx“ beschriften. Rückmeldung Dietmar.

## 0.1.22 (Build 23) — 13.10.2026

- Hausmodus: Alle Zeitpunkte der Location Control stehen zur Auswahl, jeweils mit der heutigen Uhrzeit — Tag ab Sonnenaufgang oder Beginn der bürgerlichen/nautischen/astronomischen Dämmerung, Abend ab Sonnenuntergang oder Ende einer Dämmerung, „Ist es Tag“. Neu auch „Nacht beginnt“ wahlweise zur Uhrzeit oder am Ende einer Dämmerung. Jeweils mit Versatz. Wunsch Dietmar.
- Hausmodus nach **Helligkeitssensor** (z. B. Einstrahlung einer Wetterstation in W/m² oder Helligkeit in lx): „Tag beginnt, wenn es hell wird“ / „Abend beginnt, wenn es dunkel wird“ — mit zwei Schwellen (hell ab / dunkel unter) und Haltezeit (Standard 10 min), damit eine Wolke nicht umschaltet. Das Formular schlägt passende Sensoren vor, zeigt die Einheit an den Schwellen und den aktuellen Wert mit Zustand. Fehlt der Sensor, gelten die Uhrzeiten. Wunsch Dietmar.

## 0.1.21 (Build 22) — 13.10.2026

- Hausmodus: Die grüne Zeile „🔗 … aus Symcons Location Control“ klang, als sei schon alles verbunden. Ersetzt durch eine Infozeile, die nur nennt, was die Auswahl wirklich nutzt, mit den heutigen Werten (z. B. „Sonnenaufgang heute 07:45 Uhr · „Ist es Tag“ gerade: ja“); bei „zur Uhrzeit“ ausgeblendet, ohne Location Control ⚠️ mit Rückfall auf die Uhrzeiten. Folgt dem Umstellen sofort. Rückmeldung Dietmar.

## 0.1.20 (Build 21) — 13.10.2026

- Hausmodus: Es sind nur noch die Felder sichtbar, die zur Auswahl passen — „zur Uhrzeit“ zeigt die Uhrzeit, Sonnenaufgang/-untergang den Versatz, „Ist es Tag“ keins von beiden. Wechselt sofort beim Umstellen. Rückmeldung Dietmar.

## 0.1.19 (Build 20) — 13.10.2026

- Hausmodus/Anwesenheit: Gibt es einen Automatismus „Anwesenheit zusammenfassen“, wird das Feld für eine eigene Anwesenheits-Variable ausgeblendet — dessen Ergebnis nutzt der Hausmodus ohnehin. Ist dort die Ergebnisvariable selbst eingetragen, gilt sie als automatische Quelle (mit Hinweis, dass der Eintrag nicht nötig ist). Steht dort eine fremde Variable, bleibt das Feld sichtbar und hat Vorrang. Rückmeldung Dietmar.

## 0.1.18 (Build 19) — 13.10.2026

- „Anwesenheit zusammenfassen“ nimmt jetzt bis zu 8 Signale auf (vorher 4) — genug für größere Haushalte oder mehrere Signale je Person (z. B. Geofency und WLAN). Entdecken übernimmt entsprechend bis zu 8. Die Platzzahl ist jetzt je Feld festgelegt; alle anderen Mehrfachfelder bleiben bei 4. Wunsch Dietmar.

## 0.1.17 (Build 18) — 13.10.2026

- Neuer Automatismus **🏠 Anwesenheit zusammenfassen**: bis zu 4 Anwesenheitssignale (z. B. Geofency je Person, „Anwesenheit“-Variablen) ergeben eine Variable „Jemand zu Hause“. Sobald einer da ist: sofort „jemand da“; sind alle weg, erst nach einer Wartezeit (Standard 10 min) „niemand da“ — kurzes GPS-Springen schaltet nichts um. Status nennt, wer zu Hause ist; Kommen/Gehen im Protokoll. Entdecken schlägt ihn vor; Fahrzeuge („Zu Hause“ eines Autos), Kalender-Planwerte und Präsenzmelder im Raum zählen nicht. Wunsch Dietmar.
- Hausmodus nutzt das Ergebnis automatisch für „Abwesend“, wenn keine eigene Anwesenheits-Variable eingetragen ist (eine eingetragene hat Vorrang); das Panel zeigt, woher die Anwesenheit kommt.
- Hausmodus: „Tag beginnt“ wahlweise zur Uhrzeit, bei Sonnenaufgang (± Versatz) oder wenn „Ist es Tag“ der Location Control wahr wird; „Abend beginnt“ bei Sonnenuntergang (± Versatz), zur Uhrzeit oder wenn „Ist es Tag“ falsch wird. „Nacht ab“ nach Mitternacht (z. B. 00:30) wird richtig behandelt. Wunsch Dietmar.

## 0.1.16 (Build 17) — 13.10.2026

- Hausmodus-Schalter neu formuliert: „automatisch nach Tageszeit umschalten … (wann genau, legst du darunter fest — z. B. Abend ab Sonnenuntergang)“ — er schaltet nicht zwingend zu festen Zeiten. Rückmeldung Dietmar.

## 0.1.15 (Build 16) — 13.10.2026

- Hausmodus-Automatik ist für neue Instanzen standardmäßig an (bestimmt nur, welcher Modus gilt; schaltet selbst nichts). Bestehende Instanzen behalten ihre Einstellung.
- Verständlicher Schalter: „Hausmodus automatisch umschalten: morgens auf „Tag“, abends auf „Abend“, nachts auf „Nacht“ (Zeiten darunter)“, darunter eine Zustandszeile (folgt dem Schalter sofort); Panel-Überschrift und Zusammenfassung über der Liste zeigen „automatisch“ bzw. „Automatik aus — nur von Hand“. Rückmeldung Dietmar.

## 0.1.14 (Build 15) — 13.10.2026

- Gedächtnis der Automatismen (gemerkte Heizungs-Sollwerte, gelernter Nachlauf, Melderzustände) liegt jetzt in einer versteckten Variable „Gedächtnis (intern)“ statt in einem Attribut — es übersteht damit ein Neueinbinden des Moduls in der Modulverwaltung. Der bisherige Stand wird beim ersten „Übernehmen“ übernommen.
- Sicherheitsnetz beim Start: Steht ein Thermostat noch abgesenkt, obwohl alle Fenster des Automatismus zu sind (z. B. Fenster während eines Updates geschlossen), stellt Automat den gemerkten Sollwert zurück und vermerkt „Beim Start nachgeholt“ im Protokoll. Wunsch Dietmar.

## 0.1.13 (Build 14) — 13.10.2026

- Bewegungslicht lernt aus Fehlabschaltungen: Geht das Licht innerhalb von 3 Minuten nach dem automatischen Ausschalten wieder an (Bewegung oder von Hand), verlängert sich der Nachlauf um die Hälfte (bis zum einstellbaren Höchstwert, Standard 30 min); ohne Fehlabschaltung geht er je Tag um 10 % Richtung Grundwert zurück. Kurz nach dem eigenen Aus zählt der Helligkeitssensor nicht (Restlicht). Anzeige in „Einstellungen“ („🕒 Nachlauf gelernt 4:30 min · 3× zu früh aus (7 Tage)“), im Dialog am Nachlauf-Feld und im Protokoll; abschaltbar. Standardmäßig an — auch für bestehende Bewegungslichter.
- Weitere Anzeichen für Anwesenheit (bis zu 4 Variablen, z. B. Herd-/Dunstabzug-Leistung, Fernseher, Musik): Solange eines aktiv ist (Schalter an bzw. Zahl über der Schwelle), beginnt kein Nachlauf und eine Vorwarnung wird zurückgenommen; sie schalten nie selbst ein.
- Anlass: Dietmars Archiv — Esszimmer 7, Küche 6 Fehlabschaltungen in 3 Tagen.

## 0.1.12 (Build 13) — 13.10.2026

- Impuls-Melder werden erkannt: Sendet ein Melder mindestens 10× „Bewegung“ und nie „keine Bewegung“, steht in „Einstellungen“ „ℹ️ Impuls-Melder erkannt“ und im Dialog direkt am Schalter „Nachlauf bei jeder Bewegungsmeldung“ ein Hinweis mit dem Meldernamen.
- Funkqualität: Jeder Eingriff des Melder-Rückfalls wird je Melder gezählt — „📶 3× Meldung verloren (7 Tage)“ in „Einstellungen“, im Dialog direkt unter dem betroffenen Melder mit Tipp (Empfang, Batterie, Standort), auch in der Übersicht.
- README: Abschnitt „Melder unterschiedlicher Systeme“.

## 0.1.11 (Build 12) — 13.10.2026

- Bewegungslicht: Melder-Rückfall für verlorene „keine Bewegung“-Meldungen (z. B. Z-Wave). Automat lernt je Melder, wie lange „Bewegung“ üblicherweise ansteht — zum Start aus dem Archiv der letzten 3 Tage, danach laufend — und wertet einen Melder nach dem Dreifachen des 90-%-Werts (30 s bis 1 h, ohne Daten 10 min) ohne neue Meldung als still. Steht im Protokoll; eine neue Bewegungsmeldung setzt die Zeit zurück. Fester Wert im Dialog möglich (0 = automatisch), gelernter Wert in der Spalte „Einstellungen“. Anlass: Dietmars Z-Wave-Melder (üblich 10 s, einzelne Fälle 8–15 min).

## 0.1.10 (Build 11) — 13.10.2026

- Wird im Bearbeiten-Dialog ein Variablenfeld geleert, setzt Automat es sofort auf 0 (Symcon lieferte dafür teils 1) — gespeichert wird so eine saubere 0. Wunsch Dietmar.

## 0.1.9 (Build 10) — 13.10.2026

- Leere Variablenfelder: Symcons Auswahlfeld speichert beim Leeren teils den Wert 1 statt 0 — Werte unter 10000 (echte Objekt-IDs sind fünfstellig) gelten jetzt als „nicht gewählt“. Die falsche Meldung „Variable #1 existiert nicht mehr“ verschwindet.
- Bearbeiten-Dialog markiert Problemfelder: Unter jedem Variablenfeld, dessen Variable nicht mehr existiert oder das als Pflichtfeld leer ist, steht ein roter Hinweis; der Abschnitt trägt ⛔, oben steht eine Zusammenfassung. Wählt man eine gültige Variable, verschwindet der Hinweis sofort. Wunsch Dietmar.

## 0.1.8 (Build 9) — 13.10.2026

- Räume: Kurz- und Langform gelten als derselbe Raum — Essen/Esszimmer, Wohnen/Wohnzimmer, Schlafen/Schlafzimmer, Kind/Kinderzimmer, Bad/Badezimmer, Gäste/Gästezimmer, Arbeiten/Arbeitszimmer. Nummern bleiben unterschieden („Kind II“ → „Kinderzimmer II“). Der Raumname folgt Raumstruktur, falls vorhanden. Hinweis Dietmar.

## 0.1.7 (Build 8) — 13.10.2026

- Entdecken: Vorschläge direkt in der Liste anhaken (Spalte „✔“) und mit „Angehakte in die Liste übernehmen“ alle auf einmal anlegen bzw. mit „Angehakte ausblenden“ wegräumen; das separate Auswahlfeld „Vorschlag“ entfällt.
- Räume: Geräte ohne Raum in Raumstruktur, deren Name einen bekannten Raum nennt („Thermostat Kind II“), kommen in genau diesen Raum; Schreibweise aus Raumstruktur („HWR“ statt „Hwr“); „Kind I“ und „Kind II“ werden nicht mehr zusammengelegt.
- Gerät fertig: ein Vorschlag je Gerät, auch wenn es mehrere Leistungsvariablen hat (bevorzugt die ohne „Original“).
- Doppelsteuerung: Die Anwesenheitssimulation gilt nicht mehr als zweiter Regler (ℹ️ mit Tipp, den Automatismus im Hausmodus „Abwesend“ abzuwählen). Rückmeldungen Dietmar.

## 0.1.6 (Build 7) — 13.10.2026

- Entdecken: Vorschläge als sortierbare Liste (Raum, Art, Vorschlag, Warum, Hinweise) statt Textblock; „Ordnen nach“ Raum oder Funktion, Spaltenköpfe Raum/Art gruppieren wie in der Hauptliste; Vorschläge mit Doppelsteuerung gelb.
- Auswahl „Vorschlag“ folgt derselben Ordnung und nennt Raum und Art („Flur · 🚶 Bewegungslicht: …“). Wunsch Dietmar.

## 0.1.5 (Build 6) — 13.10.2026

- Prüfung auf doppelte Steuerung im Bearbeiten-Dialog: jede Fundstelle mit „Öffnen“-Knopf (springt ins Objekt), in eigenem Abschnitt (aufgeklappt, wenn etwas dasselbe schaltet).
- Ereignisse in Klartext statt Symcons automatischem Namen: wo sie hängen (z. B. „unter Skript „Auslösung Alarmsensor Kochen“ (…\\Alarmanlage\\…)“), worauf sie reagieren und was sie tun (schaltet Variable X / startet das Skript); ⚠️ nur, wenn sie nachweislich dieselbe Variable schalten, sonst ℹ️.
- Instanzen mit Modulname und Ort; Module, die Variablen nur weitergeben oder anzeigen (z. B. HomeKit Bridge, Sprachassistenten, Visualisierungen), gelten nicht mehr als zweiter Regler (ℹ️ statt ⚠️). Rückmeldung Dietmar.

## 0.1.4 (Build 5) — 13.10.2026

- Liste der Automatismen: mindestens 12 Zeilen hoch (wächst bis 25), neue Spalte „Einstellungen“ mit den wichtigsten Werten (z. B. „Nachlauf 2:00 min · unter 30 lx (jetzt 12 lx) · 100 %, Nacht 20 %“).
- Gruppieren: Spaltenkopf „Raum“ ordnet nach Raum und darin nach Art, „Art“ nach Funktion und darin nach Raum; „Ordnen nach“ merkt sich die Wahl (Wunsch Dietmar).

## 0.1.3 (Build 4) — 13.10.2026

- Neue Variable „… · Restzeit“ je Automatismus: zeigt, wie lange es noch dauert, bis das Licht ausgeht, die Heizung abgesenkt, eine Meldung gesendet oder die nächste Dämmerungsschaltung fällig ist; wird alle 5 s nachgeführt, solange der Countdown unter einer Stunde liegt, sonst minütlich; leer, wenn nichts ansteht (Wunsch Dietmar).
- Kachel zählt jetzt bis zum eigentlichen Ereignis herunter (vorher bei Vorwarnung bis zum Warnzeitpunkt).

## 0.1.2 (Build 3) — 13.10.2026

- Bewegungslicht: verständlicherer Text für den Schalter „Melder setzt die Variable nie (oder erst sehr spät) auf „aus“ zurück“ und ein Hilfe-Knopf „Wann brauche ich diesen Schalter?“ direkt darunter (Rückmeldung Dietmar aus dem ersten Live-Test).

## 0.1.1 (Build 2) — 13.10.2026

- Räume kommen aus dem umbenannten Toolkit-Modul **Raumstruktur** (vormals Katasteramt): Automat ruft `RAUM_GetStructure` auf, `STRUKT_GetStructure` bleibt als Rückfall, bis alle umgestellt haben.
- Texte nennen die neuen Toolkit-Namen (Raumstruktur, Anwesenheitssimulation, Messwertrechner).

## 0.1.0 (Build 1) — 13.10.2026

Erste Version, nur Prüfstand (noch nicht an einer echten Symcon-Installation geprüft).

- Sechs Automatismen in einer Instanz: Zeitlicht (Treppenlicht), Bewegungslicht, Dämmerungs- und
  Zeitschaltung, Fenster offen → Heizung runter, Wächter „bleibt zu lange so", Gerät fertig.
- Eine Liste mit Bearbeiten-Dialog je Zeile (Art im Dialog wählbar, nur die passenden Felder sichtbar),
  Zustandsspalte, unvollständige Zeilen rot.
- Entdecken: Geräte-Rollen aus Profilen, Darstellungen und Namen, Räume aus Katasteramt oder aus
  Gerätenamen, Vorschläge mit Warnung bei doppelter Steuerung, ausblendbar.
- Katalog mit Hinweis, ob passende Geräte vorhanden sind.
- Querschnitt: Sperre und Dauerlicht als Variablen, globale Pause, Hausmodus (manuell, nach Uhrzeit/
  Sonnenuntergang, nach Anwesenheit), Symcon-Bedingungen, Erkennung von Handbedienung (Echo eigener
  Befehle zählt nicht), Protokoll mit Entscheidungsgründen.
- Meldungen an alle WebFront-/Kachel-Visualisierungen und in die Variable „Letzte Meldung".
- Kachel: Übersicht je Raum mit laufenden Restzeiten, Sperren, Dauerlicht, Hausmodus, Pause, Protokoll.
