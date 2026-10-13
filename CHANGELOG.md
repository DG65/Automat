# Changelog

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
