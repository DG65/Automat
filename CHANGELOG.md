# Changelog

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
