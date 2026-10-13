# Changelog

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
