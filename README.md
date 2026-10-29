# Automat

[![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Modul Version](https://img.shields.io/badge/Modul%20Version-0.1.55-blue.svg)](https://github.com/DG65/Automat)
[![Symcon Version](https://img.shields.io/badge/Symcon%20Version-9.0%2B-blue.svg)](https://www.symcon.de/de/service/dokumentation/installation/)
[![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-green.svg)](LICENSE)
[![Check Style](https://github.com/DG65/Automat/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/Automat/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C.svg)](https://paypal.me/DietmarGureth)

Teil des **DG65-Toolkit** — Werkzeuge ohne Energiebezug, für jede Symcon-Installation.

Automat bündelt die kleinen Standard-Automatismen eines Hauses in **einer Instanz**: Treppenlicht,
Bewegungslicht, Dämmerungsschaltung, Fenster offen → Heizung runter, „Tür zu lange offen"-Wächter und
„Waschmaschine fertig". Statt dutzender Einzel-Instanzen und Ereignisse gibt es eine Liste mit Zustand
und Grund je Automatismus, ein Protokoll, eine Kachel — und **Entdecken** schlägt aus den vorhandenen
Geräten vor, was sich lohnt.

> **Stand 0.1.55 (Beta im Module Store, 700 Prüfungen):** Bewegungslicht, Fenster offen, Wächter, Anwesenheit, Zentral-Aus,
> Rollläden und Hausmodus laufen an meiner Anlage; die übrigen Automatismen sind am Prüfstand (rund 500
> Prüfungen) getestet. Rückmeldungen gerne im [Forum-Thread](https://community.symcon.de/t/beta-modul-dg65-toolkit-automat-alle-standard-automatismen-in-einer-instanz/144647).

## Automatismen

| | Art | Was es tut | Besonderheiten |
|---|---|---|---|
| ⏲ | Zeitlicht (Treppenlicht) | Licht geht nach einer Leuchtdauer von selbst aus | per Taster oder bei jedem Einschalten; Nachtriggern; Vorwarnung (Dimmer: halbe Helligkeit, Schalter: kurz aus); Dauerlicht |
| 🚶 | Bewegungslicht | an bei Bewegung, aus nach Nachlauf | bis 4 Melder (ODER); bleibt an, solange Bewegung gemeldet wird; Helligkeitsschwelle nur beim Einschalten (das eigene Licht schaltet sich nicht wieder aus); übernimmt kein Licht, das schon an war; Pause nach Ausschalten von Hand; **lernender Nachlauf**: geht das Licht kurz nach dem Ausschalten wieder an, verlängert er sich; **weitere Anzeichen für Anwesenheit** (z. B. Herd-Leistung, Fernseher) halten das Licht an; **Melder-Rückfall**: geht die Meldung „keine Bewegung“ unterwegs verloren (Funk/Z-Wave), gilt ein Melder nach dem Dreifachen seiner gelernten üblichen Meldedauer als still |
| 🌆 | Dämmerungs- und Zeitschaltung | an/aus nach Sonnenstand, Uhrzeit oder Helligkeit | Versatz zu Sonnenauf-/-untergang, Zufallsversatz je Tag, über Mitternacht; von Hand aus = bleibt bis zum nächsten Schaltfenster aus |
| 🪟 | Fenster offen → Heizung runter | senkt Sollwerte ab, stellt beim Schließen wieder her | Verzögerung (kurz lüften zählt nicht); ändert ein Wochenplan den Sollwert bei offenem Fenster, wird der neue Wert gemerkt; invertierte Kontakte (Matter/Zigbee) |
| ⏱ | Wächter „bleibt zu lange so" | meldet, wenn ein Zustand zu lange anhält | gleich/ungleich/größer/kleiner/an/aus; Wiederholung; Entwarnung |
| 🧺 | Gerät fertig | meldet das Ende eines Laufs an der Leistung | Mindestlaufzeit gegen Spitzen, Pausen/Schleudern überbrückt |
| 🏠 | Anwesenheit zusammenfassen | bis 8 Anwesenheitssignale → „Jemand zu Hause“ | sofort „da“, „niemand da“ erst nach Wartezeit; nennt, wer da ist; speist den Hausmodus „Abwesend“ |
| 🌙 | Zentral-Aus / Szene nach Hausmodus | beim Wechsel auf Nacht/Abwesend/… Geräte aus, ein oder auf Wert | bis 16 Geräte, Verzögerung, nur was anders steht; andere Automatismen sehen es als „zentral“, nicht als Handbedienung |
| 🚪 | Gehen-Check | beim Wechsel auf Abwesend/Urlaub (wahlweise Nacht) EINE Meldung, was offen oder an ist | Fenster/Türen/Tore und Geräte (Schalter oder Leistung), wahlweise gleich ausschalten |
| 🛎 | Türklingel | melden, abends Licht an, Musik leiser — danach wie vorher | Meldung „niemand zu Hause“ bei Abwesenheit; Kamerabild an Skript-Meldewege; Handänderungen bleiben |
| ☀️ | Sommer-Lüften | „Fenster schließen“, wenn draußen wärmer, „jetzt lüften“, wenn kühler | Mindest-Innentemperatur, Hysterese, Taupunkt mit Luftfeuchte innen/außen (Keller) |
| 🧊 | Ausfall-Wächter | zu lange ohne Strom (Kühlschrank) oder zu lange am Stück (Pumpe) | merkt sich die bisher längste Pause bzw. den längsten Lauf als „normal“ |
| 📅 | Zeitschaltuhr | ein/aus nach Wochentag und Uhrzeit | nur ein / nur aus, über Mitternacht, Feiertage, Zufallsversatz; Handbedienung bleibt bis zur nächsten Schaltzeit |
| 🚨 | Wasser-/Rauchmelder-Reaktion | Alarm: Licht an, Rollläden auf, Ventil/Geräte aus, melden | wirkt auch bei Pause und in der Ruhezeit; Erinnerung bis Entwarnung; schaltet danach nichts zurück |
| 🪟 | Rollladen nach Zeit/Sonne | morgens auf, abends zu | Uhrzeit, Sonnenauf-/-untergang ± Versatz, Helligkeit oder Hausmodus; frühestens (werktags/Wochenende), spätestens; Aussperrschutz bei offener Tür |
| 🔌 | Standby-Abschaltung | Steckdose aus, wenn das Gerät lange im Standby ist | Schwelle in W, Dauer; zwischendurch Betrieb setzt die Zeit zurück |
| 💨 | Lüftungserinnerung | „Bitte lüften“ bei zu viel Feuchte/CO₂, „kann wieder zu“ | Wartezeit gegen kurze Spitzen (Dusche), Erinnerung, Fensterkontakt optional |
| 🌡 | Heizung nach Hausmodus | bei Abwesend/Urlaub (Nacht wahlweise) absenken, danach zurück | merkt den alten Sollwert; Handänderungen bleiben |
| 🔘 | Taster-Zuordnung | kurz/doppelt/lang drücken → Aktion | für Taster, die den Druck als Wert melden („single“, „double“, „hold“, 1/2/3); umschalten, an, aus, Wert, Hausmodus |
| 🌅 | Aufwach-/Einschlaflicht | Licht langsam hoch bis zur Weckzeit bzw. runter bis aus | Uhrzeit, Weckzeit-Variable oder Hausmodus „Nacht“; Handeingriff beendet die Rampe |

## Für alle Automatismen gleich

- **Restzeit** je Automatismus als Variable „… · Restzeit“ (z. B. „1:35 min“ bis das Licht ausgeht, bis zur Absenkung oder Meldung), sonst leer.
- **Sperre** je Automatismus als Variable „… · gesperrt" — sichtbar, aus WebFront, Kachel und Skript schaltbar
  (Putzen, Party, Handwerker). Gesperrt heißt: tut gar nichts.
- **Pause** für alle auf einmal (Variable „Alle Automatismen pausieren").
- **Hausmodus** Tag/Abend/Nacht/Abwesend/Urlaub als Variable dieser Instanz — von Hand, per Skript oder
  automatisch nach Uhrzeit, Sonnenstand/Dämmerung oder „Ist es Tag“ (Location Control), Helligkeitssensor (z. B. Wetterstation) und Anwesenheit. Jeder Automatismus lässt sich auf Modi
  beschränken, Licht nutzt bei „Nacht" einen eigenen Helligkeitswert.
- **Meldungen — was und wie entscheidest du**: beliebig viele benannte Meldewege (Push an alle oder eine
  bestimmte Visualisierung, E-Mail über SMTP an bestimmte Empfänger, Skript z. B. für Telegram), je Weg
  „Standard“ und „Ruhezeit beachten“. Je Automatismus „Melden“ und die Wahl seiner Wege (ohne Wahl: Standard);
  dazu wahlweise Störungen. Ruhezeit mit Nachholen, Testmeldung mit Ergebnis je Weg.
- **Filter** über der Liste (Suchtext, Raum, Funktion/Art, Zustand) und beim Entdecken (Suchtext, Raum, Art);
  ausgefilterte Automatismen bleiben beim Speichern erhalten.
- **Duplizieren**: Automatismen anhaken und in einen anderen Raum kopieren — Melder, Licht, Kontakte gleicher Art
  werden dort eingesetzt; was nicht passt, bleibt leer und die Kopie bis zur Auswahl inaktiv.
- **Von Hand auslösen**: angehakte Automatismen „▶ Jetzt ausführen“ (verschlafene Schaltung nachholen, Szene, Zeitschaltuhr, Treppenlicht, Gehen-Check, Heizung) bzw. Rollläden „⬇ schließen“ / „⬆ öffnen“ — auch in der Kachel. `AUTOMAT_RunNow($id, $uid, '' | 'down' | 'up')` für Skripte.
- **Probelauf** je Automatismus: nichts schalten, nichts melden, nur protokollieren, was passiert wäre.
- **Feiertage** nach Bundesland (DE), Österreich oder Schweiz für Rollläden, Aufwachlicht und Zeitschaltuhr.
- **Statistik** je Automatismus (Schaltungen, Meldungen) und Hinweis auf solche, die 30 Tage nichts getan haben.
- **Gewohnheiten**: Entdecken wertet das Archiv der Lichter aus und schlägt z. B. „werktags um 22:30 aus“ vor.
- **Formular: Aufbau** — je Bereich wählen, ob er beim Öffnen auf- oder zugeklappt ist, an welcher Position er
  steht oder ob er ganz ausgeblendet wird (z. B. „Katalog“ und „Entdecken“, wenn alles eingerichtet ist).
- **Bedingungen** über Symcons eigene Bedingungs-Auswahl (z. B. nur werktags, nur wenn ein Schalter an ist).
- **Handbedienung hat Vorrang** — Automat erkennt das Echo eigener Befehle und unterscheidet es von
  Schaltungen von Hand.
- **Entscheidungsgrund**: Statusvariable je Automatismus und Protokoll („Bewegung — nicht eingeschaltet:
  hell genug (120 lx ≥ 30 lx)").
- **Doppelte Steuerung** wird erkannt: Automat warnt, wenn dieselben Geräte schon von einem anderen
  Automatismus, einem ausgelösten Ereignis oder einer anderen Instanz gesteuert werden.

## Entdecken

„Jetzt Vorschläge suchen" durchsucht den Objektbaum nach Bewegungsmeldern, Lichtern (Schalter/Dimmer),
Fenster-/Türkontakten, Heizungs-Sollwerten, Helligkeitssensoren und Leistungsmessungen von
Haushaltsgeräten — über Profile, Darstellungen (Symcon 8+) und Namen. Die Geräte werden nach Raum
gruppiert: aus dem **DG65-Toolkit Raumstruktur**, falls installiert, sonst aus den Gerätenamen
(„Licht Flur" + „Flur Bewegungsmelder" = Flur). Daraus entstehen Vorschläge wie „Bewegungslicht Flur",
„Fenster offen → Heizung runter Bad" oder „Waschmaschine fertig melden".

Vorschläge stehen in einer sortierbaren Liste (nach Raum oder Funktion geordnet). Angehakte Vorschläge landen erst nach „Angehakte in die Liste übernehmen" in der Liste und arbeitet erst nach
„Übernehmen". Unpassende Vorschläge lassen sich ausblenden.

## Melder unterschiedlicher Systeme

Automat achtet nicht auf das Funksystem, sondern auf das Verhalten der Melder-Variable — meist ist nichts einzustellen:

| Verhalten | Was Automat tut |
|---|---|
| Melder setzt nach einigen Sekunden zurück, „aus“ geht aber gelegentlich verloren (Z-Wave, Zigbee, Funk-Homematic, WLAN) | lernt die übliche Meldedauer je Melder (Archiv der letzten 3 Tage, danach laufend) und wertet ihn nach dem Dreifachen ohne neue Meldung als still; jeder Eingriff wird gezählt („📶 3× Meldung verloren (7 Tage)“) |
| Präsenzmelder mit langer Belegung (Radar/mmWave, Zigbee mit eigener Belegungszeit) | lernt automatisch eine lange Zeit (bis 1 h) |
| Melder wiederholt bei Anwesenheit regelmäßig „an“ (z. B. KNX-Präsenzmelder) | jede Wiederholung verlängert |
| Melder sendet nie „aus“ | wird erkannt („ℹ️ Impuls-Melder erkannt“) — dann im Dialog „Nachlauf bei jeder Bewegungsmeldung“ einschalten |

Häufen sich verlorene Meldungen bei einem Melder, lohnt ein Blick auf Empfang, Batterie oder Standort (bei Z-Wave/Zigbee hilft oft ein Repeater in der Nähe).

## Einrichten

1. Modulverwaltung → Hinzufügen → `https://github.com/DG65/Automat`
2. Instanz „DG65-Toolkit Automat" anlegen (eine Instanz für das ganze Haus genügt; mehrere sind möglich).
3. „Entdecken" → Vorschläge prüfen → übernehmen, oder im „Katalog" bzw. mit „+" an der Liste selbst anlegen.
4. Unten „Übernehmen".
5. Optional: Kachel „Automat" in der Kachel-Visualisierung platzieren (kompakte Übersicht mit Suche,
   Filter Läuft/Auffällig/Gesperrt, Gruppen nach Raum oder Funktion, Sortierung; Sperren, Dauerlicht,
   Hausmodus, Pause, Restzeiten).

## Skripte

```php
echo AUTOMAT_Overview($InstanzID);                 // Zustand aller Automatismen als Text
IPS_RequestAction($InstanzID, 'Hausmodus', 2);     // 0 Tag, 1 Abend, 2 Nacht, 3 Abwesend, 4 Urlaub
IPS_RequestAction($InstanzID, 'Pause', true);      // alle pausieren
```

Sperre und Dauerlicht einzelner Automatismen sind Variablen unter der Instanz (`l_<Kennung>`,
`d_<Kennung>`) und per `RequestAction` auf die Variable schaltbar.

## Abgrenzung

Bewusst **nicht** in Automat: Beschattung nach Sonnenstand (dafür gibt es spezialisierte Module),
Alarmanlage mit Zonen, Anwesenheitssimulation (→ **DG65-Toolkit Anwesenheitssimulation**), Batterieüberwachung
(→ **DG65-Toolkit Batteriewächter**), Mittelwerte/Trends (→ **DG65-Toolkit Messwertrechner**).

## Lizenz

PolyForm Noncommercial 1.0.0 — private/nicht-kommerzielle Nutzung ist frei, gewerbliche Nutzung erfordert
eine gesonderte Lizenz vom Rechteinhaber (DG65). Der vollständige Lizenztext liegt im Repo (LICENSE).
Spenden sind willkommen: [paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).
