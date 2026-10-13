<?php

declare(strict_types=1);

// ===========================================================================
// AUTOMATKatalog — alle Automatismus-Typen an EINER Stelle: Bezeichnung,
// Kategorie, welche Geräte sie brauchen, welche Felder der Bearbeiten-Dialog
// zeigt und welche Standardwerte gelten. Formular (Dialog, Katalog-Panel),
// Laufzeit-Logik und Prüfstand lesen alle aus dieser Tabelle — ein neuer Typ
// entsteht hier und in AUTOMATLogik, nirgends sonst.
//
// Feldnamen tragen ein Typ-Kürzel (zl_, bl_, …): Der Dialog schreibt JEDES
// benannte Element in die Listenzeile, und Namen dürfen im Formular nicht
// doppelt vorkommen (Symcon-Doku „List → form“).
// ===========================================================================

class AUTOMATKatalog
{
    /** Anzahl Variablen-Plätze je Mehrfach-Feld (Melder, Ausgänge, Kontakte, …). */
    public const SLOTS = 4;

    /**
     * Kleinste echte Objekt-ID in Symcon (IDs sind fünfstellig, 10000–59999). Kleinere Werte in einem
     * Variablenfeld heißen „nicht gewählt“ — das Auswahlfeld kann beim Leeren/Abbrechen z. B. 1 speichern
     * (an Dietmars Instanz gesehen, 13.10.2026).
     */
    public static int $minId = 10000; // im Prüfstand mit Kurz-IDs herabsetzbar

    /** Hausmodi (Werte der Variable „Hausmodus“). */
    public const MODES = [0 => 'Tag', 1 => 'Abend', 2 => 'Nacht', 3 => 'Abwesend', 4 => 'Urlaub'];
    public const MODE_NIGHT = 2;
    public const MODE_AWAY = 3;
    public const MODE_HOLIDAY = 4;

    public const CATEGORIES = [
        'licht'        => '💡 Licht',
        'klima'        => '🌡 Klima',
        'ueberwachung' => '🔔 Überwachung',
    ];

    /** Geräte-Rollen der Erkennung, mit Anzeigename für „dafür fehlt …“. */
    public const ROLES = [
        'light'    => 'Licht (Schalter oder Dimmer)',
        'motion'   => 'Bewegungs-/Präsenzmelder',
        'contact'  => 'Fenster-/Türkontakt',
        'setpoint' => 'Heizungs-Sollwert (Thermostat)',
        'lux'      => 'Helligkeitssensor',
        'power'    => 'Leistungsmessung eines Haushaltsgeräts',
    ];

    public const TYPES = [
        'zeitlicht' => [
            'label' => 'Zeitlicht (Treppenlicht)', 'icon' => '⏲', 'cat' => 'licht', 'light' => true,
            'desc'  => 'Licht geht nach einer einstellbaren Zeit von selbst wieder aus — per Taster gestartet oder bei jedem Einschalten. Mit Vorwarnung, Nachtriggern und Dauerlicht.',
            'needs' => ['light'],
        ],
        'bewegung' => [
            'label' => 'Bewegungslicht', 'icon' => '🚶', 'cat' => 'licht', 'light' => true,
            'desc'  => 'Licht an bei Bewegung, aus nach Nachlaufzeit. Bleibt an, solange noch Bewegung gemeldet wird, schaltet nur bei Dunkelheit und respektiert Handbedienung.',
            'needs' => ['motion', 'light'],
        ],
        'daemmerung' => [
            'label' => 'Dämmerungs- und Zeitschaltung', 'icon' => '🌆', 'cat' => 'licht', 'light' => true,
            'desc'  => 'Schaltet z. B. Außenlicht bei Sonnenuntergang, zu einer Uhrzeit oder bei Dunkelheit ein und zu einer Uhrzeit, bei Sonnenaufgang oder bei Helligkeit wieder aus — auf Wunsch mit Zufallsversatz.',
            'needs' => ['light'],
        ],
        'fenster' => [
            'label' => 'Fenster offen → Heizung runter', 'icon' => '🪟', 'cat' => 'klima', 'light' => false,
            'desc'  => 'Senkt den Heizungs-Sollwert ab, sobald ein Fenster länger als die Verzögerung offen ist, und stellt ihn beim Schließen wieder her. Ein zwischenzeitlich vom Wochenplan geänderter Sollwert wird gemerkt.',
            'needs' => ['contact', 'setpoint'],
        ],
        'waechter' => [
            'label' => 'Wächter „bleibt zu lange so“', 'icon' => '⏱', 'cat' => 'ueberwachung', 'light' => false,
            'desc'  => 'Meldet, wenn ein Zustand zu lange anhält — z. B. Tür/Fenster offen, Temperatur zu hoch, Feuchte zu hoch, Wert unverändert. Mit Wiederholung und Entwarnung.',
            'needs' => [],
        ],
        'fertig' => [
            'label' => 'Gerät fertig', 'icon' => '🧺', 'cat' => 'ueberwachung', 'light' => false,
            'desc'  => 'Meldet, wenn z. B. Waschmaschine, Trockner oder Spülmaschine fertig ist — erkannt an der Leistungsaufnahme.',
            'needs' => ['power'],
        ],
    ];

    // Auswahllisten
    public const WA_OPS = [0 => 'ist gleich', 1 => 'ist ungleich', 2 => 'ist größer als', 3 => 'ist kleiner als', 4 => 'ist an / wahr', 5 => 'ist aus / falsch'];
    public const DZ_ON = [0 => 'Sonnenuntergang (± Versatz)', 1 => 'Uhrzeit', 2 => 'Dunkelheit (Helligkeit unter Schwelle)'];
    public const DZ_OFF = [0 => 'Uhrzeit', 1 => 'Sonnenaufgang (± Versatz)', 2 => 'Helligkeit über Schwelle', 3 => 'gar nicht (nur einschalten)'];

    /** Typ bekannt? */
    public static function has(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function label(string $type): string
    {
        return isset(self::TYPES[$type]) ? self::TYPES[$type]['icon'] . ' ' . self::TYPES[$type]['label'] : '❔ Unbekannter Typ';
    }

    public static function time(int $h, int $m): string
    {
        return json_encode(['hour' => $h, 'minute' => $m, 'second' => 0]);
    }

    /**
     * Felder je Typ. 'k' = Art (var, vars, int, float, bool, select, time, text, info),
     * 'd' = Standardwert. Bei 'vars' entstehen SLOTS Felder name1..nameN.
     */
    public static function fields(string $type): array
    {
        switch ($type) {
            case 'zeitlicht':
                return [
                    ['k' => 'info', 'c' => 'Was soll geschaltet werden?'],
                    ['n' => 'zl_out', 'k' => 'vars', 'c' => 'Licht / Ausgang', 'req' => true],
                    ['k' => 'info', 'c' => 'Wodurch startet die Zeit? (leer lassen = jedes Einschalten des Lichts startet die Zeit, z. B. über den normalen Lichtschalter)'],
                    ['n' => 'zl_trg', 'k' => 'vars', 'c' => 'Taster / Auslöser'],
                    ['k' => 'info', 'c' => 'Zeiten und Helligkeit'],
                    ['n' => 'zl_dauer', 'k' => 'int', 'c' => 'Leuchtdauer', 'd' => 180, 'suffix' => ' s', 'min' => 5, 'max' => 86400],
                    ['n' => 'zl_vorwarn', 'k' => 'int', 'c' => 'Vorwarnung vor dem Ausschalten (0 = aus)', 'd' => 0, 'suffix' => ' s', 'min' => 0, 'max' => 600],
                    ['n' => 'zl_nachtrigger', 'k' => 'bool', 'c' => 'Erneutes Auslösen verlängert die Zeit (Nachtriggern)', 'd' => true],
                    ['n' => 'zl_wert', 'k' => 'int', 'c' => 'Helligkeit bei Dimmern', 'd' => 100, 'suffix' => ' %', 'min' => 1, 'max' => 100],
                    ['n' => 'zl_nachtwert', 'k' => 'int', 'c' => 'Helligkeit im Hausmodus „Nacht“ (0 = wie oben)', 'd' => 0, 'suffix' => ' %', 'min' => 0, 'max' => 100],
                ];
            case 'bewegung':
                return [
                    ['k' => 'info', 'c' => 'Melder (einer reicht — mehrere werden ODER-verknüpft)'],
                    ['n' => 'bl_mel', 'k' => 'vars', 'c' => 'Bewegungsmelder', 'req' => true],
                    ['n' => 'bl_impuls', 'k' => 'bool', 'c' => 'Melder setzt die Variable nie (oder erst sehr spät) auf „aus“ zurück — Nachlauf bei jeder Bewegungsmeldung neu starten', 'd' => false,
                        'help' => ['Wann brauche ich diesen Schalter?', [
                            'Ein Bewegungsmelder setzt seine Variable bei Bewegung auf „an“ (true) und normalerweise einige Zeit später von selbst wieder auf „aus“ (false).',
                            'Schalter AUS (Normalfall): Das Licht bleibt an, solange irgendein Melder noch auf „an“ steht. Erst wenn alle wieder „aus“ melden, beginnt die Nachlaufzeit.',
                            'Schalter AN: Automat beachtet nur, wann zuletzt „an“ gemeldet wurde, und startet ab da jedes Mal die Nachlaufzeit neu. Ein „aus“ wird ignoriert.',
                            'Einschalten, wenn das Licht dauerhaft an bleibt, obwohl niemand mehr da ist — dann geht die Variable des Melders nie oder erst sehr spät auf „aus“ zurück (z. B. Melder, die bei jeder Bewegung nur erneut „an“ schreiben).',
                        ]]],
                    ['n' => 'bl_melmax', 'k' => 'int', 'c' => 'Melder gilt als „keine Bewegung“, wenn er so lange nichts meldet (0 = automatisch gelernt)', 'd' => 0, 'suffix' => ' s', 'min' => 0, 'max' => 7200,
                        'help' => ['Wozu dient die Rückfallzeit der Melder?', [
                            'Funkmelder (z. B. Z-Wave) verlieren gelegentlich die Meldung „keine Bewegung“. Dann steht der Melder scheinbar dauerhaft auf „Bewegung“ und das Licht ginge nie aus.',
                            'Automat merkt sich je Melder, wie lange „Bewegung“ normalerweise ansteht (aus dem Archiv der letzten Tage und laufend dazugelernt). Meldet ein Melder länger als das Dreifache davon nichts Neues (mindestens 30 s), gilt er als „keine Bewegung“ und der Nachlauf beginnt. Das steht dann im Protokoll.',
                            'Eine erneute Bewegungsmeldung setzt die Zeit zurück — wer sich im Raum bewegt, behält Licht. Präsenzmelder, die lange „an“ bleiben, lernen automatisch eine lange Zeit.',
                            '0 = automatisch (empfohlen). Ein fester Wert gilt für alle Melder dieses Automatismus.',
                        ]]],
                    ['k' => 'info', 'c' => 'Was soll geschaltet werden?'],
                    ['n' => 'bl_out', 'k' => 'vars', 'c' => 'Licht / Ausgang', 'req' => true],
                    ['k' => 'info', 'c' => 'Zeiten, Helligkeit und Handbedienung'],
                    ['n' => 'bl_nachlauf', 'k' => 'int', 'c' => 'Nachlaufzeit nach der letzten Bewegung', 'd' => 120, 'suffix' => ' s', 'min' => 5, 'max' => 86400],
                    ['n' => 'bl_vorwarn', 'k' => 'int', 'c' => 'Vorwarnung vor dem Ausschalten (0 = aus)', 'd' => 0, 'suffix' => ' s', 'min' => 0, 'max' => 600],
                    ['n' => 'bl_lux', 'k' => 'var', 'c' => 'Helligkeitssensor (optional)'],
                    ['n' => 'bl_luxschwelle', 'k' => 'float', 'c' => 'Nur einschalten, wenn dunkler als', 'd' => 30.0, 'suffix' => ' lx', 'min' => 0, 'max' => 100000],
                    ['n' => 'bl_wert', 'k' => 'int', 'c' => 'Helligkeit bei Dimmern', 'd' => 100, 'suffix' => ' %', 'min' => 1, 'max' => 100],
                    ['n' => 'bl_nachtwert', 'k' => 'int', 'c' => 'Helligkeit im Hausmodus „Nacht“ (0 = wie oben)', 'd' => 0, 'suffix' => ' %', 'min' => 0, 'max' => 100],
                    ['n' => 'bl_handpause', 'k' => 'int', 'c' => 'Nach Ausschalten von Hand: Automatik pausieren für', 'd' => 15, 'suffix' => ' min', 'min' => 0, 'max' => 1440],
                    ['k' => 'info', 'c' => 'Lernen und weitere Anzeichen für Anwesenheit'],
                    ['n' => 'bl_lernen', 'k' => 'bool', 'c' => 'Nachlauf aus Fehlabschaltungen lernen (verlängert sich, wenn das Licht kurz nach dem Ausschalten wieder angeht)', 'd' => true],
                    ['n' => 'bl_nachlaufmax', 'k' => 'int', 'c' => '… gelernter Nachlauf höchstens', 'd' => 1800, 'suffix' => ' s', 'min' => 10, 'max' => 86400,
                        'help' => ['Wie lernt der Nachlauf?', [
                            'Geht das Licht innerhalb von 3 Minuten nach dem automatischen Ausschalten wieder an — durch Bewegung oder von Hand —, war jemand noch da, den der Melder nicht gesehen hat (still am Tisch, am Herd). Das ist eine Fehlabschaltung.',
                            'Dann verlängert Automat den Nachlauf dieses Automatismus um die Hälfte, höchstens bis zum eingestellten Höchstwert. Bleiben Fehlabschaltungen einen Tag lang aus, geht er um 10 % Richtung Grundwert zurück.',
                            'Kurz nach dem eigenen Ausschalten schaltet Bewegung das Licht auch dann wieder ein, wenn der Raum laut Helligkeitssensor gerade noch hell genug wäre (Restlicht der eben ausgeschalteten Lampe).',
                            'Der aktuelle Wert und die Zahl der Fehlabschaltungen der letzten 7 Tage stehen in der Spalte „Einstellungen“ und im Protokoll.',
                        ]]],
                    ['n' => 'bl_hint', 'k' => 'vars', 'c' => 'Weiteres Anzeichen für Anwesenheit',
                        'help' => ['Was sind weitere Anzeichen für Anwesenheit?', [
                            'Variablen, die zeigen, dass jemand im Raum ist, auch wenn der Melder nichts sieht — z. B. Leistung von Herd, Dunstabzug oder Kaffeemaschine, ein laufender Fernseher, Musik, ein Schalter.',
                            'Solange ein Anzeichen aktiv ist, beginnt kein Nachlauf und eine Vorwarnung wird zurückgenommen. Erst wenn kein Melder und kein Anzeichen mehr aktiv ist, läuft die Zeit. Anzeichen schalten das Licht nie selbst ein.',
                            'Aktiv heißt: Schalter auf „an“, Zahlen (z. B. Leistung in W) über der Schwelle darunter. Geräte, die stundenlang laufen und mit Anwesenheit nichts zu tun haben (Spülmaschine, Kühlschrank), nicht eintragen.',
                        ]]],
                    ['n' => 'bl_hintmin', 'k' => 'float', 'c' => 'Anzeichen gilt als aktiv über (bei Zahlenwerten, z. B. Leistung in W)', 'd' => 10.0, 'suffix' => '', 'min' => 0, 'max' => 100000],
                ];
            case 'daemmerung':
                return [
                    ['k' => 'info', 'c' => 'Was soll geschaltet werden?'],
                    ['n' => 'dz_out', 'k' => 'vars', 'c' => 'Licht / Ausgang', 'req' => true],
                    ['k' => 'info', 'c' => 'Einschalten'],
                    ['n' => 'dz_ein', 'k' => 'select', 'c' => 'Einschalten bei', 'd' => 0, 'options' => self::DZ_ON],
                    ['n' => 'dz_einversatz', 'k' => 'int', 'c' => 'Versatz zum Sonnenuntergang (− = früher)', 'd' => 0, 'suffix' => ' min', 'min' => -240, 'max' => 240],
                    ['n' => 'dz_einzeit', 'k' => 'time', 'c' => 'Einschaltzeit', 'd' => self::time(18, 0)],
                    ['k' => 'info', 'c' => 'Ausschalten'],
                    ['n' => 'dz_aus', 'k' => 'select', 'c' => 'Ausschalten bei', 'd' => 0, 'options' => self::DZ_OFF],
                    ['n' => 'dz_auszeit', 'k' => 'time', 'c' => 'Ausschaltzeit', 'd' => self::time(23, 0)],
                    ['n' => 'dz_ausversatz', 'k' => 'int', 'c' => 'Versatz zum Sonnenaufgang (− = früher)', 'd' => 0, 'suffix' => ' min', 'min' => -240, 'max' => 240],
                    ['k' => 'info', 'c' => 'Helligkeit und Zufall'],
                    ['n' => 'dz_lux', 'k' => 'var', 'c' => 'Helligkeitssensor (nur für „Dunkelheit“/„Helligkeit“)'],
                    ['n' => 'dz_luxschwelle', 'k' => 'float', 'c' => 'Helligkeitsschwelle', 'd' => 20.0, 'suffix' => ' lx', 'min' => 0, 'max' => 100000],
                    ['n' => 'dz_zufall', 'k' => 'int', 'c' => 'Zufallsversatz je Tag (±)', 'd' => 0, 'suffix' => ' min', 'min' => 0, 'max' => 120],
                    ['n' => 'dz_wert', 'k' => 'int', 'c' => 'Helligkeit bei Dimmern', 'd' => 100, 'suffix' => ' %', 'min' => 1, 'max' => 100],
                ];
            case 'fenster':
                return [
                    ['k' => 'info', 'c' => 'Fenster-/Türkontakte (eines offen reicht)'],
                    ['n' => 'fo_kon', 'k' => 'vars', 'c' => 'Kontakt', 'req' => true],
                    ['n' => 'fo_invert', 'k' => 'bool', 'c' => 'Kontakte melden „wahr“ = GESCHLOSSEN (manche Matter-/Zigbee-Kontakte)', 'd' => false],
                    ['k' => 'info', 'c' => 'Heizungs-Sollwerte, die abgesenkt werden'],
                    ['n' => 'fo_soll', 'k' => 'vars', 'c' => 'Sollwert', 'req' => true],
                    ['k' => 'info', 'c' => 'Absenkung'],
                    ['n' => 'fo_verz', 'k' => 'int', 'c' => 'Erst absenken, wenn länger offen als', 'd' => 60, 'suffix' => ' s', 'min' => 0, 'max' => 3600],
                    ['n' => 'fo_absenk', 'k' => 'float', 'c' => 'Abgesenkter Sollwert', 'd' => 12.0, 'suffix' => ' °C', 'min' => 0, 'max' => 30],
                ];
            case 'waechter':
                return [
                    ['k' => 'info', 'c' => 'Was wird überwacht?'],
                    ['n' => 'wa_var', 'k' => 'var', 'c' => 'Variable', 'req' => true],
                    ['n' => 'wa_op', 'k' => 'select', 'c' => 'Melden, wenn der Wert', 'd' => 4, 'options' => self::WA_OPS],
                    ['n' => 'wa_wert', 'k' => 'text', 'c' => 'Vergleichswert (für gleich/ungleich/größer/kleiner)', 'd' => ''],
                    ['n' => 'wa_dauer', 'k' => 'int', 'c' => '… und zwar länger als', 'd' => 15, 'suffix' => ' min', 'min' => 0, 'max' => 10080],
                    ['k' => 'info', 'c' => 'Meldung'],
                    ['n' => 'wa_text', 'k' => 'text', 'c' => 'Meldungstext (leer = automatisch)', 'd' => ''],
                    ['n' => 'wa_wdh', 'k' => 'int', 'c' => 'Wiederholen alle (0 = einmal)', 'd' => 0, 'suffix' => ' min', 'min' => 0, 'max' => 1440],
                    ['n' => 'wa_ende', 'k' => 'bool', 'c' => 'Entwarnung melden, wenn der Zustand vorbei ist', 'd' => true],
                    ['n' => 'wa_push', 'k' => 'bool', 'c' => 'Als Push-Nachricht an alle Visualisierungen senden', 'd' => true],
                ];
            case 'fertig':
                return [
                    ['k' => 'info', 'c' => 'Leistungsmessung des Geräts'],
                    ['n' => 'gf_var', 'k' => 'var', 'c' => 'Leistung (W)', 'req' => true],
                    ['n' => 'gf_lauf', 'k' => 'float', 'c' => 'Läuft ab', 'd' => 20.0, 'suffix' => ' W', 'min' => 0, 'max' => 10000],
                    ['n' => 'gf_fertig', 'k' => 'float', 'c' => 'Fertig unter', 'd' => 5.0, 'suffix' => ' W', 'min' => 0, 'max' => 10000],
                    ['n' => 'gf_minlauf', 'k' => 'int', 'c' => 'Mindestlaufzeit (kürzere Spitzen zählen nicht)', 'd' => 5, 'suffix' => ' min', 'min' => 0, 'max' => 600],
                    ['n' => 'gf_nach', 'k' => 'int', 'c' => 'Fertig, wenn so lange unter der Schwelle', 'd' => 3, 'suffix' => ' min', 'min' => 0, 'max' => 120],
                    ['k' => 'info', 'c' => 'Meldung'],
                    ['n' => 'gf_text', 'k' => 'text', 'c' => 'Meldungstext (leer = „<Name> ist fertig.“)', 'd' => ''],
                    ['n' => 'gf_push', 'k' => 'bool', 'c' => 'Als Push-Nachricht an alle Visualisierungen senden', 'd' => true],
                ];
        }
        return [];
    }

    /** Standardwerte eines Typs als Zeile (ohne Name/Raum/Uid). */
    public static function defaults(string $type): array
    {
        $row = ['Type' => $type, 'TypeLabel' => self::label($type), 'Enabled' => true, 'Cond' => ''];
        foreach (self::MODES as $m => $_) {
            $row['m' . $m] = true;
        }
        foreach (self::fields($type) as $f) {
            if (!isset($f['n'])) {
                continue;
            }
            if ($f['k'] === 'vars') {
                for ($i = 1; $i <= self::SLOTS; $i++) {
                    $row[$f['n'] . $i] = 0;
                }
            } elseif ($f['k'] === 'var') {
                $row[$f['n']] = 0;
            } else {
                $row[$f['n']] = $f['d'];
            }
        }
        return $row;
    }

    /** Alle Variablenfelder einer Zeile bereinigen: Werte unter MIN_ID werden 0 („nicht gewählt“). */
    public static function sanitize(array $row): array
    {
        foreach (self::TYPES as $t => $_) {
            foreach (self::fields($t) as $f) {
                if (!isset($f['n']) || ($f['k'] !== 'var' && $f['k'] !== 'vars')) {
                    continue;
                }
                $names = $f['k'] === 'vars' ? array_map(function ($i) use ($f) {
                    return $f['n'] . $i;
                }, range(1, self::SLOTS)) : [$f['n']];
                foreach ($names as $n) {
                    if (array_key_exists($n, $row) && (int)$row[$n] < self::$minId) {
                        $row[$n] = 0;
                    }
                }
            }
        }
        return $row;
    }

    /**
     * Probleme je Feld für den Dialog: [Feldname => Text]. Fehlt eine Pflichtangabe, steht der
     * Hinweis am ersten Platz des Felds.
     */
    public static function fieldProblems(array $row, callable $exists): array
    {
        $t = (string)($row['Type'] ?? '');
        $out = [];
        foreach (self::fields($t) as $f) {
            if (!isset($f['n']) || ($f['k'] !== 'var' && $f['k'] !== 'vars')) {
                continue;
            }
            $names = $f['k'] === 'vars' ? array_map(function ($i) use ($f) {
                return $f['n'] . $i;
            }, range(1, self::SLOTS)) : [$f['n']];
            $any = false;
            foreach ($names as $n) {
                $v = (int)($row[$n] ?? 0);
                if ($v >= self::$minId) {
                    $any = true;
                    if (!$exists($v)) {
                        $out[$n] = '⛔ Variable #' . $v . ' existiert nicht mehr — bitte neu wählen oder leeren.';
                    }
                }
            }
            if (!$any && !empty($f['req'])) {
                $out[$names[0]] = '⛔ Bitte wählen — ohne „' . $f['c'] . '“ kann der Automatismus nicht arbeiten.';
            }
        }
        return $out;
    }

    /** Variablen eines Mehrfach-Felds (0 und doppelte entfernt). */
    public static function vars(array $row, string $name): array
    {
        $out = [];
        for ($i = 1; $i <= self::SLOTS; $i++) {
            $v = (int)($row[$name . $i] ?? 0);
            if ($v >= self::$minId && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    /**
     * Alle Variablen einer Zeile nach Rolle: 'in' (Ereignisse, auf die reagiert
     * wird), 'out' (wird geschaltet), 'read' (wird nur gelesen).
     */
    public static function variables(array $row): array
    {
        $t = (string)($row['Type'] ?? '');
        $in = $out = $read = [];
        switch ($t) {
            case 'zeitlicht':
                $out = self::vars($row, 'zl_out');
                $in = self::vars($row, 'zl_trg');
                break;
            case 'bewegung':
                $in = array_values(array_unique(array_merge(self::vars($row, 'bl_mel'), self::vars($row, 'bl_hint'))));
                $out = self::vars($row, 'bl_out');
                if ((int)($row['bl_lux'] ?? 0) >= self::$minId) {
                    $read[] = (int)$row['bl_lux'];
                }
                break;
            case 'daemmerung':
                $out = self::vars($row, 'dz_out');
                if ((int)($row['dz_lux'] ?? 0) >= self::$minId) {
                    $read[] = (int)$row['dz_lux'];
                }
                break;
            case 'fenster':
                $in = self::vars($row, 'fo_kon');
                $out = self::vars($row, 'fo_soll');
                break;
            case 'waechter':
                if ((int)($row['wa_var'] ?? 0) >= self::$minId) {
                    $in[] = (int)$row['wa_var'];
                }
                break;
            case 'fertig':
                if ((int)($row['gf_var'] ?? 0) >= self::$minId) {
                    $in[] = (int)$row['gf_var'];
                }
                break;
        }
        return ['in' => $in, 'out' => $out, 'read' => $read];
    }

    /**
     * Was fehlt, damit der Automatismus arbeiten kann? Leere Liste = vollständig.
     * $exists: callable(int $vid): bool — ob die Variable existiert.
     */
    public static function problems(array $row, callable $exists): array
    {
        $t = (string)($row['Type'] ?? '');
        if (!self::has($t)) {
            return ['Typ fehlt oder ist unbekannt — Zeile bearbeiten und einen Typ wählen.'];
        }
        $p = [];
        foreach (self::fields($t) as $f) {
            if (empty($f['req'])) {
                continue;
            }
            $vals = $f['k'] === 'vars' ? self::vars($row, $f['n']) : (((int)($row[$f['n']] ?? 0) >= self::$minId) ? [(int)$row[$f['n']]] : []);
            if (count($vals) === 0) {
                $p[] = '„' . $f['c'] . '“ ist nicht gewählt.';
            }
        }
        foreach (self::variables($row) as $list) {
            foreach ($list as $vid) {
                if (!$exists($vid)) {
                    $p[] = 'Variable #' . $vid . ' existiert nicht mehr.';
                }
            }
        }
        if ($t === 'daemmerung') {
            $needLux = (int)($row['dz_ein'] ?? 0) === 2 || (int)($row['dz_aus'] ?? 0) === 2;
            if ($needLux && (int)($row['dz_lux'] ?? 0) < self::$minId) {
                $p[] = 'Für „Dunkelheit“/„Helligkeit“ fehlt der Helligkeitssensor.';
            }
        }
        if ($t === 'fertig' && (float)($row['gf_fertig'] ?? 0) >= (float)($row['gf_lauf'] ?? 0)) {
            $p[] = '„Fertig unter“ muss kleiner sein als „Läuft ab“.';
        }
        return $p;
    }

    /** Dauer kurz: „45 s", „2:00 min", „1 h 30 min". */
    public static function dur(int $sec): string
    {
        if ($sec >= 3600) {
            $m = intdiv($sec % 3600, 60);
            return intdiv($sec, 3600) . ' h' . ($m > 0 ? ' ' . $m . ' min' : '');
        }
        if ($sec >= 60) {
            return intdiv($sec, 60) . ':' . sprintf('%02d', $sec % 60) . ' min';
        }
        return $sec . ' s';
    }

    private static function hm(string $timeJson): string
    {
        $t = json_decode($timeJson, true);
        return sprintf('%02d:%02d', (int)($t['hour'] ?? 0), (int)($t['minute'] ?? 0));
    }

    private static function offset(int $min): string
    {
        return $min === 0 ? '' : ' ' . ($min > 0 ? '+' : '−') . abs($min) . ' min';
    }

    /**
     * Die wichtigsten Einstellungen einer Zeile in einer Zeile Text (Spalte „Einstellungen").
     * $live: callable(int $vid): ?string — aktueller Wert einer gelesenen Variable als Text (z. B. „12 lx"), null = unbekannt.
     */
    public static function summary(array $row, callable $live): string
    {
        $t = (string)($row['Type'] ?? '');
        $p = [];
        $light = function (string $pre) use ($row) {
            $w = (int)($row[$pre . '_wert'] ?? 100);
            $n = (int)($row[$pre . '_nachtwert'] ?? 0);
            return $w . ' %' . ($n > 0 ? ', Nacht ' . $n . ' %' : '');
        };
        switch ($t) {
            case 'zeitlicht':
                $p[] = 'Dauer ' . self::dur((int)($row['zl_dauer'] ?? 0));
                if ((int)($row['zl_vorwarn'] ?? 0) > 0) {
                    $p[] = 'Vorwarnung ' . self::dur((int)$row['zl_vorwarn']);
                }
                $p[] = $light('zl');
                $n = count(self::vars($row, 'zl_trg'));
                $p[] = $n > 0 ? $n . ' Taster' : 'startet bei jedem Einschalten';
                break;
            case 'bewegung':
                $p[] = 'Nachlauf ' . self::dur((int)($row['bl_nachlauf'] ?? 0));
                if ((int)($row['bl_lux'] ?? 0) >= self::$minId) {
                    $now = $live((int)$row['bl_lux']);
                    $p[] = 'unter ' . rtrim(rtrim(number_format((float)($row['bl_luxschwelle'] ?? 0), 1, ',', ''), '0'), ',') . ' lx' . ($now !== null ? ' (jetzt ' . $now . ')' : '');
                }
                $p[] = $light('bl');
                if ((int)($row['bl_vorwarn'] ?? 0) > 0) {
                    $p[] = 'Vorwarnung ' . self::dur((int)$row['bl_vorwarn']);
                }
                if ((int)($row['bl_handpause'] ?? 0) > 0) {
                    $p[] = 'Handpause ' . (int)$row['bl_handpause'] . ' min';
                }
                $p[] = count(self::vars($row, 'bl_mel')) . ' Melder';
                $nh = count(self::vars($row, 'bl_hint'));
                if ($nh > 0) {
                    $p[] = $nh . ' Anzeichen für Anwesenheit (über ' . rtrim(rtrim(number_format((float)($row['bl_hintmin'] ?? 10), 1, ',', ''), '0'), ',') . ')';
                }
                if ((int)($row['bl_melmax'] ?? 0) > 0) {
                    $p[] = 'Melder-Rückfall ' . self::dur((int)$row['bl_melmax']);
                }
                break;
            case 'daemmerung':
                $ein = (int)($row['dz_ein'] ?? 0);
                $aus = (int)($row['dz_aus'] ?? 0);
                $p[] = 'an: ' . ($ein === 0 ? 'Sonnenuntergang' . self::offset((int)($row['dz_einversatz'] ?? 0)) : ($ein === 1 ? self::hm((string)($row['dz_einzeit'] ?? '')) : 'Dunkelheit'));
                $p[] = 'aus: ' . ($aus === 0 ? self::hm((string)($row['dz_auszeit'] ?? '')) : ($aus === 1 ? 'Sonnenaufgang' . self::offset((int)($row['dz_ausversatz'] ?? 0)) : ($aus === 2 ? 'Helligkeit' : 'nie')));
                if ((int)($row['dz_zufall'] ?? 0) > 0) {
                    $p[] = 'Zufall ±' . (int)$row['dz_zufall'] . ' min';
                }
                if ((int)($row['dz_lux'] ?? 0) >= self::$minId && ($ein === 2 || $aus === 2)) {
                    $now = $live((int)$row['dz_lux']);
                    $p[] = 'Schwelle ' . rtrim(rtrim(number_format((float)($row['dz_luxschwelle'] ?? 0), 1, ',', ''), '0'), ',') . ' lx' . ($now !== null ? ' (jetzt ' . $now . ')' : '');
                }
                break;
            case 'fenster':
                $p[] = 'nach ' . self::dur((int)($row['fo_verz'] ?? 0)) . ' auf ' . rtrim(rtrim(number_format((float)($row['fo_absenk'] ?? 0), 1, ',', ''), '0'), ',') . ' °C';
                $p[] = count(self::vars($row, 'fo_kon')) . ' Kontakt(e), ' . count(self::vars($row, 'fo_soll')) . ' Sollwert(e)';
                break;
            case 'waechter':
                $op = (int)($row['wa_op'] ?? 4);
                $p[] = (self::WA_OPS[$op] ?? '') . (in_array($op, [4, 5], true) ? '' : ' ' . (string)($row['wa_wert'] ?? ''));
                $p[] = 'länger als ' . self::dur((int)($row['wa_dauer'] ?? 0) * 60);
                if ((int)($row['wa_wdh'] ?? 0) > 0) {
                    $p[] = 'Erinnerung alle ' . (int)$row['wa_wdh'] . ' min';
                }
                break;
            case 'fertig':
                $p[] = 'läuft ab ' . (float)($row['gf_lauf'] ?? 0) . ' W';
                $p[] = 'fertig unter ' . (float)($row['gf_fertig'] ?? 0) . ' W nach ' . (int)($row['gf_nach'] ?? 0) . ' min';
                break;
        }
        $off = [];
        foreach (self::MODES as $m => $label) {
            if (array_key_exists('m' . $m, $row) && !$row['m' . $m]) {
                $off[] = $label;
            }
        }
        if (count($off) > 0) {
            $p[] = 'nicht bei ' . implode('/', $off);
        }
        if (trim((string)($row['Cond'] ?? '')) !== '' && trim((string)$row['Cond']) !== '[]') {
            $p[] = 'mit Bedingung';
        }
        return implode(' · ', $p);
    }
}
