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
    /** Freie Plätze über den belegten hinaus — Mehrfachfelder haben damit keine feste Obergrenze (Dietmar 15.10.2026). */
    public const SPARE = 8;
    /** Fenster-/Türkontakte: deren Richtung (wahr = offen oder zu) erkennt Automat je Variable selbst. */
    public const CONTACT_FIELDS = ['fo_kon', 'gc_kon', 'sl_kon', 'rl_tuer', 'lf_kon'];

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
        'haus'         => '🏠 Haus und Anwesenheit',
    ];

    /** Geräte-Rollen der Erkennung, mit Anzeigename für „dafür fehlt …“. */
    public const ROLES = [
        'light'    => 'Licht (Schalter oder Dimmer)',
        'motion'   => 'Bewegungs-/Präsenzmelder',
        'contact'  => 'Fenster-/Türkontakt',
        'setpoint' => 'Heizungs-Sollwert (Thermostat)',
        'lux'      => 'Helligkeitssensor',
        'power'    => 'Leistungsmessung eines Haushaltsgeräts',
        'presence' => 'Anwesenheit einer Person (z. B. Geofency, Handy im WLAN)',
        'alarm'    => 'Wasser- oder Rauchmelder',
        'shutter'  => 'Rollladen/Jalousie (Position)',
        'air'      => 'Raumluft (Feuchte oder CO₂)',
        'button'   => 'Taster/Szenen-Schalter',
        'bell'     => 'Türklingel',
        'cooling'  => 'Leistungsmessung Kühlschrank/Gefriertruhe',
        'temp'     => 'Temperaturmessung (innen und außen)',
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
        'anwesenheit' => [
            'label' => 'Anwesenheit zusammenfassen', 'icon' => '🏠', 'cat' => 'haus', 'light' => false,
            'desc'  => 'Fasst mehrere Anwesenheitssignale (z. B. je Person Geofency oder Handy im WLAN) zu „Jemand zu Hause“ zusammen — sofort „da“, „niemand da“ erst nach einer Wartezeit gegen kurze Aussetzer. Der Hausmodus nutzt das Ergebnis automatisch.',
            'needs' => ['presence'],
        ],
        'gehen' => [
            'label' => 'Gehen-Check', 'icon' => '🚪', 'cat' => 'ueberwachung', 'light' => false,
            'desc'  => 'Wechselt der Hausmodus auf „Abwesend“ oder „Urlaub“ (wahlweise „Nacht“), prüft er Fenster, Türen und Geräte und meldet in EINER Nachricht, was noch offen oder an ist — wahlweise schaltet er die Geräte gleich aus.',
            'needs' => ['contact'],
        ],
        'klingel' => [
            'label' => 'Türklingel', 'icon' => '🛎', 'cat' => 'haus', 'light' => false,
            'desc'  => 'Klingelt es: melden (bei Abwesenheit deutlich), abends Licht für ein paar Minuten an und Musik leiser — danach wieder wie vorher. Ein Kamerabild geht an Skript-Meldewege mit (z. B. Telegram).',
            'needs' => ['bell'],
        ],
        'sommer' => [
            'label' => 'Sommer-Lüften', 'icon' => '☀️', 'cat' => 'klima', 'light' => false,
            'desc'  => 'Ist es draußen wärmer als drinnen: „Fenster schließen“. Wird es draußen kühler: „Jetzt lüften“. Mit Luftfeuchte innen und außen wird der Taupunkt verglichen — wichtig für den Keller.',
            'needs' => ['temp'],
        ],
        'ausfall' => [
            'label' => 'Ausfall-Wächter', 'icon' => '🧊', 'cat' => 'ueberwachung', 'light' => false,
            'desc'  => 'Meldet, wenn ein Gerät zu lange keinen Strom zieht (Kühlschrank, Gefriertruhe — Kompressor springt nicht mehr an) oder zu lange am Stück läuft (Pumpe, Heizstab). Zeigt, was bisher „normal“ war.',
            'needs' => ['cooling'],
        ],
        'zeitschalt' => [
            'label' => 'Zeitschaltuhr', 'icon' => '📅', 'cat' => 'haus', 'light' => false,
            'desc'  => 'Schaltet Geräte nach Wochentagen und Uhrzeit, mit Feiertagen und Zufallsversatz. Wer zwischendurch von Hand ausschaltet, behält das bis zum nächsten Schaltzeitpunkt.',
            'needs' => [],
        ],
        'alarm' => [
            'label' => 'Wasser-/Rauchmelder-Reaktion', 'icon' => '🚨', 'cat' => 'ueberwachung', 'light' => false,
            'desc'  => 'Löst ein Wasser- oder Rauchmelder aus: Licht an, Rollläden auf, Wasserventil oder Geräte aus und melden — auch in der Ruhezeit und auch bei Pause. Erinnert, bis Entwarnung ist. Schaltet danach nichts zurück (Sicherheit).',
            'needs' => ['alarm'],
        ],
        'rollladen' => [
            'label' => 'Rollladen nach Zeit/Sonne', 'icon' => '🪟', 'cat' => 'haus', 'light' => false,
            'desc'  => 'Morgens auf, abends zu — zur Uhrzeit, nach Sonnenauf-/-untergang, Helligkeit oder Hausmodus, mit frühestens/spätestens und eigener Zeit fürs Wochenende. Aussperrschutz: Ist die Tür offen, bleibt der Rollladen oben, bis sie zu ist.',
            'needs' => ['shutter'],
        ],
        'standby' => [
            'label' => 'Standby-Abschaltung', 'icon' => '🔌', 'cat' => 'haus', 'light' => false,
            'desc'  => 'Bleibt die Leistung eine Weile unter einer Schwelle (Gerät nur noch im Standby), wird die Steckdose ausgeschaltet — z. B. Fernseher, Drucker, PC-Zubehör.',
            'needs' => ['power'],
        ],
        'lueften' => [
            'label' => 'Lüftungserinnerung', 'icon' => '💨', 'cat' => 'klima', 'light' => false,
            'desc'  => 'Ist die Luftfeuchte oder der CO₂-Wert eine Weile zu hoch: „Bitte lüften“. Ist ein Fenster offen und die Luft wieder gut: „Kann wieder zu“.',
            'needs' => ['air'],
        ],
        'heizung' => [
            'label' => 'Heizung nach Hausmodus', 'icon' => '🌡', 'cat' => 'klima', 'light' => false,
            'desc'  => 'Bei „Abwesend“, „Urlaub“ (und wahlweise „Nacht“) die Heizungs-Sollwerte absenken, beim Zurückkommen den gemerkten Wert wiederherstellen. Von Hand geänderte Sollwerte werden nicht überschrieben.',
            'needs' => ['setpoint'],
        ],
        'taster' => [
            'label' => 'Taster-Zuordnung', 'icon' => '🔘', 'cat' => 'licht', 'light' => false,
            'desc'  => 'Ordnet einem Taster je eine Aktion für kurz, doppelt und lang drücken zu — Licht umschalten, Wert setzen oder Hausmodus wechseln. Für Taster, die ihren Druck als Wert melden (z. B. „single“, „double“, „hold“ oder 1/2/3).',
            'needs' => ['button'],
        ],
        'wecklicht' => [
            'label' => 'Aufwach-/Einschlaflicht', 'icon' => '🌅', 'cat' => 'licht', 'light' => false,
            'desc'  => 'Dimmt Licht über eine einstellbare Zeit langsam hoch (zur Weckzeit hell) oder herunter bis aus. Greift jemand von Hand ein, endet die Rampe.',
            'needs' => ['light'],
        ],
        'szene' => [
            'label' => 'Zentral-Aus / Szene nach Hausmodus', 'icon' => '🌙', 'cat' => 'haus', 'light' => false,
            'desc'  => 'Wechselt der Hausmodus (z. B. auf „Nacht“ oder „Abwesend“), schaltet eine Gruppe von Geräten aus, ein oder auf einen Wert — z. B. beim Gehen alles aus, abends die Stimmungsbeleuchtung an. Nur beim Wechsel, danach darf wieder jeder schalten.',
            'needs' => ['light'],
        ],
        'ruecksetzen' => [
            'label' => 'Melder zurücksetzen', 'icon' => '🔁', 'cat' => 'ueberwachung', 'light' => false,
            'desc'  => 'Setzt Bewegungs-/Alarmmelder auf „falsch“ zurück, wenn sie auf „wahr“ stehen und seit der Haltezeit keine neue Meldung mehr kam — z. B. weil die „keine Bewegung“-Meldung im Funk verloren ging. Danach lösen Licht und Ereignisse beim nächsten Erkennen wieder aus. Die Haltezeit sollte länger sein als die Zeit, nach der der Melder selbst zurückfällt.',
            'needs' => ['motion'],
        ],
    ];
    public const SZ_ACTIONS = [0 => 'ausschalten', 1 => 'einschalten', 2 => 'auf einen Wert setzen'];
    public const TG_ACTIONS = [0 => 'nichts', 1 => 'umschalten (an ↔ aus)', 2 => 'einschalten', 3 => 'ausschalten', 4 => 'auf einen Wert setzen', 5 => 'Hausmodus setzen (Wert 0 Tag … 4 Urlaub)'];
    public const RL_UP = [0 => 'zur Uhrzeit', 1 => 'bei Sonnenaufgang (± Versatz)', 2 => 'wenn der Hausmodus auf „Tag“ wechselt'];
    public const RL_DOWN = [0 => 'zur Uhrzeit', 1 => 'bei Sonnenuntergang (± Versatz)', 2 => 'wenn es dunkel wird (Helligkeitssensor unter „🏠 Hausmodus“)', 3 => 'wenn der Hausmodus auf „Nacht“ wechselt'];
    public const WL_DIR = [0 => 'Aufwachen: langsam heller bis zur Weckzeit', 1 => 'Einschlafen: langsam dunkler bis aus'];
    public const WL_START = [0 => 'zur Uhrzeit', 1 => 'Weckzeit aus einer Variable (Zeitstempel oder „HH:MM“)', 2 => 'wenn der Hausmodus auf „Nacht“ wechselt (Einschlafen)'];
    /** Typen, bei denen der Hausmodus selbst Auslöser ist oder die immer gelten müssen — ohne die allgemeinen Modus-Häkchen. */
    public const NO_MODE_FILTER = ['szene', 'alarm', 'heizung', 'gehen'];
    public const AF_MODES = [0 => 'zu lange ohne Strom (z. B. Kühlschrank, Gefriertruhe)', 1 => 'läuft zu lange am Stück (z. B. Pumpe, Heizstab)'];
    public const ZS_MODES = [0 => 'ein- und ausschalten', 1 => 'nur ausschalten', 2 => 'nur einschalten'];
    public const ZS_HOLIDAY = [0 => 'wie der Wochentag', 1 => 'wie Sonntag', 2 => 'gar nicht schalten'];
    public const WEEKDAYS = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
    /** Bundesländer für Feiertage (Kürzel => Name), '' = keine Feiertage berücksichtigen. */
    public const LAENDER = ['' => 'keine Feiertage berücksichtigen', 'BW' => 'Baden-Württemberg', 'BY' => 'Bayern', 'BE' => 'Berlin', 'BB' => 'Brandenburg', 'HB' => 'Bremen', 'HH' => 'Hamburg', 'HE' => 'Hessen', 'MV' => 'Mecklenburg-Vorpommern', 'NI' => 'Niedersachsen', 'NW' => 'Nordrhein-Westfalen', 'RP' => 'Rheinland-Pfalz', 'SL' => 'Saarland', 'SN' => 'Sachsen', 'ST' => 'Sachsen-Anhalt', 'SH' => 'Schleswig-Holstein', 'TH' => 'Thüringen', 'AT' => 'Österreich', 'CH' => 'Schweiz (bundesweite Feiertage)'];

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
                    ['n' => 'dz_einversatz', 'k' => 'int', 'c' => 'Versatz zum Sonnenuntergang (− = früher)', 'd' => 0, 'suffix' => ' min', 'min' => -240, 'max' => 240, 'when' => [['dz_ein', [0]]]],
                    ['n' => 'dz_einzeit', 'k' => 'time', 'c' => 'Einschaltzeit', 'd' => self::time(18, 0), 'when' => [['dz_ein', [1]]]],
                    ['k' => 'info', 'c' => 'Ausschalten'],
                    ['n' => 'dz_aus', 'k' => 'select', 'c' => 'Ausschalten bei', 'd' => 0, 'options' => self::DZ_OFF],
                    ['n' => 'dz_auszeit', 'k' => 'time', 'c' => 'Ausschaltzeit', 'd' => self::time(23, 0), 'when' => [['dz_aus', [0]]]],
                    ['n' => 'dz_ausversatz', 'k' => 'int', 'c' => 'Versatz zum Sonnenaufgang (− = früher)', 'd' => 0, 'suffix' => ' min', 'min' => -240, 'max' => 240, 'when' => [['dz_aus', [1]]]],
                    ['k' => 'info', 'c' => 'Helligkeit und Zufall'],
                    ['n' => 'dz_lux', 'k' => 'var', 'c' => 'Eigener Helligkeitssensor (optional — leer = der zentrale unter „🏠 Hausmodus“; eigener z. B. für einen Raumsensor)', 'when' => [['dz_ein', [2]], ['dz_aus', [2]]]],
                    ['n' => 'dz_luxschwelle', 'k' => 'float', 'c' => 'Schwelle für den eigenen Sensor', 'd' => 20.0, 'suffix' => ' lx', 'min' => 0, 'max' => 100000, 'when' => [['dz_ein', [2]], ['dz_aus', [2]]]],
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
                    ['n' => 'wa_wert', 'k' => 'text', 'c' => 'Vergleichswert (für gleich/ungleich/größer/kleiner)', 'd' => '', 'when' => [['wa_op', [0, 1, 2, 3]]]],
                    ['n' => 'wa_pick', 'k' => 'pick', 'c' => '… oder aus den möglichen Werten der Variable wählen', 'src' => 'wa_var', 'to' => 'wa_wert', 'op' => 'wa_op', 'd' => ''],
                    ['n' => 'wa_dauer', 'k' => 'int', 'c' => '… und zwar länger als', 'd' => 15, 'suffix' => ' min', 'min' => 0, 'max' => 10080],
                    ['k' => 'info', 'c' => 'Meldung'],
                    ['n' => 'wa_text', 'k' => 'text', 'c' => 'Meldungstext (leer = automatisch)', 'd' => ''],
                    ['n' => 'wa_wdh', 'k' => 'int', 'c' => 'Wiederholen alle (0 = einmal)', 'd' => 0, 'suffix' => ' min', 'min' => 0, 'max' => 1440],
                    ['n' => 'wa_ende', 'k' => 'bool', 'c' => 'Entwarnung melden, wenn der Zustand vorbei ist', 'd' => true],
                    ['n' => 'wa_push', 'k' => 'bool', 'c' => 'Melden (wie, legst du unter „🔔 Meldungen“ fest: Push, E-Mail, Ruhezeit)', 'd' => true],
                ];
            case 'anwesenheit':
                return [
                    ['k' => 'info', 'c' => 'Signale für „zu Hause“ (eines reicht für „jemand da“)'],
                    ['n' => 'an_p', 'k' => 'vars', 'max' => 8, 'c' => 'Person / Anwesenheitssignal', 'req' => true,
                        'help' => ['Welche Signale passen hierher?', [
                            'Je Person ein Signal, das „zu Hause“ meldet — z. B. Geofency/Standort-App, Handy im WLAN, ein Schlüsselschalter, eine Anwesenheitsvariable einer anderen Steuerung.',
                            'Zählt als „da“: an/wahr, Zahlen über 0 und Texte wie „home“, „zuhause“, „anwesend“.',
                            'Nicht hierher gehören Präsenzmelder in einzelnen Räumen (die gehören zum Bewegungslicht), „Zu Hause“ von Fahrzeugen und geplante Kalendereinträge.',
                        ]]],
                    ['k' => 'info', 'c' => 'Ergebnis'],
                    ['n' => 'an_weg', 'k' => 'int', 'c' => '„Niemand da“ erst, wenn alle so lange weg sind', 'd' => 10, 'suffix' => ' min', 'min' => 0, 'max' => 1440],
                    ['n' => 'an_push', 'k' => 'bool', 'c' => '„Alle weg“ und „Jemand ist heimgekommen“ melden (wie, legst du unter „🔔 Meldungen“ fest)', 'd' => false],
                ];
            case 'gehen':
                return [
                    ['k' => 'info', 'c' => 'Wann? Beim Wechsel des Hausmodus auf …'],
                    ['n' => 'gc_m3', 'k' => 'bool', 'c' => 'Abwesend', 'd' => true],
                    ['n' => 'gc_m4', 'k' => 'bool', 'c' => 'Urlaub', 'd' => true],
                    ['n' => 'gc_m2', 'k' => 'bool', 'c' => 'Nacht', 'd' => false],
                    ['k' => 'info', 'c' => 'Was soll zu sein?'],
                    ['n' => 'gc_kon', 'k' => 'vars', 'max' => 16, 'c' => 'Fenster/Tür/Tor (offen = wahr)', 'req' => true],
                    ['n' => 'gc_invert', 'k' => 'bool', 'c' => 'Kontakte sind umgekehrt (wahr = zu)', 'd' => false],
                    ['k' => 'info', 'c' => 'Was soll aus sein? (optional)'],
                    ['n' => 'gc_geraet', 'k' => 'vars', 'max' => 8, 'c' => 'Gerät (Schalter oder Leistung)'],
                    ['n' => 'gc_watt', 'k' => 'float', 'c' => 'Bei Leistungswerten: gilt als „an“ ab', 'd' => 10.0, 'suffix' => ' W', 'min' => 0, 'max' => 10000],
                    ['n' => 'gc_aus', 'k' => 'bool', 'c' => 'Eingeschaltete Geräte gleich ausschalten (statt nur melden)', 'd' => false],
                    ['k' => 'info', 'c' => 'Meldung'],
                    ['n' => 'gc_push', 'k' => 'bool', 'c' => 'Melden (wie, legst du unter „🔔 Meldungen“ fest)', 'd' => true],
                    ['n' => 'gc_ok', 'k' => 'bool', 'c' => 'Auch melden, wenn alles zu ist („Alles zu“)', 'd' => false],
                ];
            case 'klingel':
                return [
                    ['k' => 'info', 'c' => 'Klingel'],
                    ['n' => 'kl_taster', 'k' => 'var', 'c' => 'Klingel (wahr/Wert beim Klingeln)', 'req' => true],
                    ['n' => 'kl_impuls', 'k' => 'bool', 'c' => 'Klingel meldet jedes Klingeln mit demselben Wert (bleibt z. B. auf „wahr“)', 'd' => false],
                    ['n' => 'kl_push', 'k' => 'bool', 'c' => 'Melden (wie, legst du unter „🔔 Meldungen“ fest)', 'd' => true],
                    ['n' => 'kl_bild', 'k' => 'object', 'c' => 'Kamerabild (Medienobjekt, optional — geht an Skript-Meldewege mit)'],
                    ['k' => 'info', 'c' => 'Licht (nur bei „Abend“ und „Nacht“)'],
                    ['n' => 'kl_licht', 'k' => 'vars', 'c' => 'Licht'],
                    ['n' => 'kl_dauer', 'k' => 'int', 'c' => 'Danach wieder aus nach', 'd' => 3, 'suffix' => ' min', 'min' => 1, 'max' => 60],
                    ['k' => 'info', 'c' => 'Musik leiser (optional)'],
                    ['n' => 'kl_laut', 'k' => 'vars', 'c' => 'Lautstärke'],
                    ['n' => 'kl_lautwert', 'k' => 'float', 'c' => 'Lautstärke beim Klingeln', 'd' => 5.0, 'min' => 0, 'max' => 100],
                ];
            case 'sommer':
                return [
                    ['k' => 'info', 'c' => 'Temperaturen'],
                    ['n' => 'sl_innen', 'k' => 'var', 'c' => 'Temperatur innen', 'req' => true],
                    ['n' => 'sl_aussen', 'k' => 'var', 'c' => 'Temperatur außen', 'req' => true],
                    ['n' => 'sl_min', 'k' => 'float', 'c' => 'Nur, wenn es innen mindestens so warm ist', 'd' => 23.0, 'suffix' => ' °C', 'min' => 0, 'max' => 40],
                    ['n' => 'sl_diff', 'k' => 'float', 'c' => 'Unterschied, ab dem es zählt', 'd' => 1.5, 'suffix' => ' K', 'min' => 0.5, 'max' => 10],
                    ['k' => 'info', 'c' => 'Luftfeuchte (optional — dann wird der Taupunkt verglichen, z. B. Keller)'],
                    ['n' => 'sl_rhinnen', 'k' => 'var', 'c' => 'Luftfeuchte innen (%)'],
                    ['n' => 'sl_rhaussen', 'k' => 'var', 'c' => 'Luftfeuchte außen (%)'],
                    ['k' => 'info', 'c' => 'Fenster und Meldung'],
                    ['n' => 'sl_kon', 'k' => 'vars', 'c' => 'Fensterkontakt (optional: „schließen“ nur, wenn offen; „lüften“ nur, wenn zu)'],
                    ['n' => 'sl_konInvert', 'k' => 'bool', 'c' => 'Kontakt ist umgekehrt (wahr = zu)', 'd' => false],
                    ['n' => 'sl_push', 'k' => 'bool', 'c' => 'Melden (wie, legst du unter „🔔 Meldungen“ fest)', 'd' => true],
                ];
            case 'ausfall':
                return [
                    ['k' => 'info', 'c' => 'Gerät'],
                    ['n' => 'af_var', 'k' => 'var', 'c' => 'Leistung (W)', 'req' => true],
                    ['n' => 'af_modus', 'k' => 'select', 'c' => 'Melden, wenn das Gerät', 'd' => 0, 'options' => self::AF_MODES],
                    ['n' => 'af_schwelle', 'k' => 'float', 'c' => 'Läuft ab', 'd' => 5.0, 'suffix' => ' W', 'min' => 0, 'max' => 10000],
                    ['n' => 'af_dauer', 'k' => 'int', 'c' => 'Länger als', 'd' => 120, 'suffix' => ' min', 'min' => 1, 'max' => 10080],
                    ['n' => 'af_push', 'k' => 'bool', 'c' => 'Melden (wie, legst du unter „🔔 Meldungen“ fest)', 'd' => true],
                    ['n' => 'af_ende', 'k' => 'bool', 'c' => 'Entwarnung melden', 'd' => true],
                ];
            case 'zeitschalt':
                $o = [
                    ['k' => 'info', 'c' => 'Was?'],
                    ['n' => 'zs_out', 'k' => 'vars', 'max' => 8, 'c' => 'Gerät / Licht', 'req' => true],
                    ['n' => 'zs_modus', 'k' => 'select', 'c' => 'Schalten', 'd' => 0, 'options' => self::ZS_MODES],
                    ['n' => 'zs_wert', 'k' => 'int', 'c' => 'Helligkeit bei Dimmern', 'd' => 100, 'suffix' => ' %', 'min' => 1, 'max' => 100, 'when' => [['zs_modus', [0, 2]]]],
                    ['k' => 'info', 'c' => 'Wann?'],
                    ['n' => 'zs_ein', 'k' => 'time', 'c' => 'Einschalten um', 'd' => self::time(7, 0), 'when' => [['zs_modus', [0, 2]]]],
                    ['n' => 'zs_aus', 'k' => 'time', 'c' => 'Ausschalten um (früher als „ein“ = am nächsten Tag)', 'd' => self::time(8, 0), 'when' => [['zs_modus', [0, 1]]]],
                ];
                foreach (self::WEEKDAYS as $n => $l) {
                    $o[] = ['n' => 'zs_t' . $n, 'k' => 'bool', 'c' => $l, 'd' => true];
                }
                $o[] = ['n' => 'zs_feiertag', 'k' => 'select', 'c' => 'An Feiertagen (Bundesland unter „🏠 Hausmodus“)', 'd' => 1, 'options' => self::ZS_HOLIDAY];
                $o[] = ['n' => 'zs_zufall', 'k' => 'int', 'c' => 'Zufallsversatz je Tag (±)', 'd' => 0, 'suffix' => ' min', 'min' => 0, 'max' => 120];
                return $o;
            case 'alarm':
                return [
                    ['k' => 'info', 'c' => 'Melder'],
                    ['n' => 'al_mel', 'k' => 'vars', 'max' => 8, 'c' => 'Wasser-/Rauchmelder', 'req' => true],
                    ['n' => 'al_invert', 'k' => 'bool', 'c' => 'Melder sind umgekehrt (wahr = alles in Ordnung)', 'd' => false],
                    ['k' => 'info', 'c' => 'Was passiert bei Alarm?'],
                    ['n' => 'al_licht', 'k' => 'vars', 'max' => 8, 'c' => 'Licht einschalten (voll)'],
                    ['n' => 'al_hoch', 'k' => 'vars', 'max' => 8, 'c' => 'Rollladen öffnen'],
                    ['n' => 'al_hochwert', 'k' => 'float', 'c' => 'Wert für „Rollladen offen“ (nur ohne Profil „~Shutter“ — sonst erkennt Automat die Richtung selbst)', 'd' => 0.0, 'min' => 0, 'max' => 255],
                    ['n' => 'al_aus', 'k' => 'vars', 'max' => 8, 'c' => 'Ausschalten (z. B. Wasserventil zu, Heizlüfter, Lüftung)'],
                    ['k' => 'info', 'c' => 'Meldung (geht auch in der Ruhezeit sofort raus)'],
                    ['n' => 'al_push', 'k' => 'bool', 'c' => 'Melden (wie, legst du unter „🔔 Meldungen“ fest)', 'd' => true],
                    ['n' => 'al_wdh', 'k' => 'int', 'c' => 'Erinnern, solange der Alarm anliegt, alle (0 = nicht)', 'd' => 10, 'suffix' => ' min', 'min' => 0, 'max' => 1440],
                    ['n' => 'al_ende', 'k' => 'bool', 'c' => 'Entwarnung melden', 'd' => true],
                ];
            case 'rollladen':
                return [
                    ['k' => 'info', 'c' => 'Rollläden'],
                    ['n' => 'rl_out', 'k' => 'vars', 'max' => 16, 'c' => 'Rollladen (Position)', 'req' => true],
                    ['n' => 'rl_auf', 'k' => 'float', 'c' => 'Wert für „offen“ (nur für Rollläden ohne Profil „~Shutter“ — dort erkennt Automat die Richtung selbst)', 'd' => 0.0, 'min' => 0, 'max' => 255],
                    ['n' => 'rl_zu', 'k' => 'float', 'c' => 'Wert für „zu“ (ebenso)', 'd' => 100.0, 'min' => 0, 'max' => 255],
                    ['k' => 'info', 'c' => 'Morgens öffnen'],
                    ['n' => 'rl_upref', 'k' => 'select', 'c' => 'Öffnen', 'd' => 1, 'options' => self::RL_UP],
                    ['n' => 'rl_upzeit', 'k' => 'time', 'c' => 'Uhrzeit (bei „zur Uhrzeit“)', 'd' => self::time(7, 0), 'when' => [['rl_upref', [0]]]],
                    ['n' => 'rl_upoff', 'k' => 'int', 'c' => 'Versatz zum Sonnenaufgang (− = früher)', 'd' => 0, 'suffix' => ' min', 'min' => -240, 'max' => 240, 'when' => [['rl_upref', [1]]]],
                    ['n' => 'rl_frueh', 'k' => 'time', 'c' => 'Werktags frühestens', 'd' => self::time(6, 30)],
                    ['n' => 'rl_fruehwe', 'k' => 'time', 'c' => 'Am Wochenende frühestens', 'd' => self::time(8, 0)],
                    ['k' => 'info', 'c' => 'Abends schließen'],
                    ['n' => 'rl_downref', 'k' => 'select', 'c' => 'Schließen', 'd' => 1, 'options' => self::RL_DOWN],
                    ['n' => 'rl_downzeit', 'k' => 'time', 'c' => 'Uhrzeit (bei „zur Uhrzeit“)', 'd' => self::time(21, 0), 'when' => [['rl_downref', [0]]]],
                    ['n' => 'rl_downoff', 'k' => 'int', 'c' => 'Versatz zum Sonnenuntergang (− = früher)', 'd' => 0, 'suffix' => ' min', 'min' => -240, 'max' => 240, 'when' => [['rl_downref', [1]]]],
                    ['n' => 'rl_spaet', 'k' => 'time', 'c' => 'Spätestens', 'd' => self::time(22, 30)],
                    ['k' => 'info', 'c' => 'Aussperrschutz'],
                    ['n' => 'rl_tuer', 'k' => 'vars', 'c' => 'Tür-/Fensterkontakt (offen = Rollladen bleibt oben, schließt, sobald zu)'],
                    ['n' => 'rl_tuerinvert', 'k' => 'bool', 'c' => 'Kontakt ist umgekehrt (wahr = zu)', 'd' => false],
                    ['n' => 'rl_push', 'k' => 'bool', 'c' => 'Melden, wenn der Aussperrschutz das Schließen aufhält', 'd' => false],
                ];
            case 'standby':
                return [
                    ['k' => 'info', 'c' => 'Gerät'],
                    ['n' => 'sb_power', 'k' => 'var', 'c' => 'Leistung (W)', 'req' => true],
                    ['n' => 'sb_out', 'k' => 'var', 'c' => 'Steckdose / Schalter', 'req' => true],
                    ['n' => 'sb_schwelle', 'k' => 'float', 'c' => 'Standby unter', 'd' => 5.0, 'suffix' => ' W', 'min' => 0, 'max' => 10000],
                    ['n' => 'sb_dauer', 'k' => 'int', 'c' => 'Ausschalten, wenn so lange im Standby', 'd' => 30, 'suffix' => ' min', 'min' => 1, 'max' => 1440],
                    ['n' => 'sb_push', 'k' => 'bool', 'c' => 'Melden, wenn abgeschaltet wurde', 'd' => false],
                ];
            case 'lueften':
                return [
                    ['k' => 'info', 'c' => 'Messwert'],
                    ['n' => 'lf_var', 'k' => 'var', 'c' => 'Luftfeuchte (%) oder CO₂ (ppm)', 'req' => true],
                    ['n' => 'lf_hoch', 'k' => 'float', 'c' => 'Erinnern ab (z. B. 65 % oder 1200 ppm)', 'd' => 65.0, 'min' => 0, 'max' => 10000],
                    ['n' => 'lf_ok', 'k' => 'float', 'c' => 'Wieder gut unter (z. B. 55 % oder 800 ppm)', 'd' => 55.0, 'min' => 0, 'max' => 10000],
                    ['n' => 'lf_dauer', 'k' => 'int', 'c' => 'Erst melden, wenn so lange zu hoch', 'd' => 10, 'suffix' => ' min', 'min' => 0, 'max' => 240],
                    ['n' => 'lf_kon', 'k' => 'vars', 'c' => 'Fensterkontakt (optional: dann „Kann wieder zu“, wenn offen und Luft gut)'],
                    ['n' => 'lf_konInvert', 'k' => 'bool', 'c' => 'Kontakt ist umgekehrt (wahr = zu)', 'd' => false],
                    ['n' => 'lf_wdh', 'k' => 'int', 'c' => 'Erinnern, solange zu hoch und Fenster zu, alle (0 = nicht)', 'd' => 30, 'suffix' => ' min', 'min' => 0, 'max' => 1440],
                    ['n' => 'lf_push', 'k' => 'bool', 'c' => 'Melden (wie, legst du unter „🔔 Meldungen“ fest)', 'd' => true],
                ];
            case 'heizung':
                return [
                    ['k' => 'info', 'c' => 'Heizungs-Sollwerte'],
                    ['n' => 'hz_soll', 'k' => 'vars', 'max' => 8, 'c' => 'Sollwert', 'req' => true],
                    ['k' => 'info', 'c' => 'Absenken bei …'],
                    ['n' => 'hz_m3', 'k' => 'bool', 'c' => 'Abwesend', 'd' => true],
                    ['n' => 'hz_abw', 'k' => 'float', 'c' => 'Sollwert bei „Abwesend“', 'd' => 17.0, 'suffix' => ' °C', 'min' => 5, 'max' => 30],
                    ['n' => 'hz_m4', 'k' => 'bool', 'c' => 'Urlaub', 'd' => true],
                    ['n' => 'hz_url', 'k' => 'float', 'c' => 'Sollwert bei „Urlaub“', 'd' => 15.0, 'suffix' => ' °C', 'min' => 5, 'max' => 30],
                    ['n' => 'hz_m2', 'k' => 'bool', 'c' => 'Nacht', 'd' => false],
                    ['n' => 'hz_nacht', 'k' => 'float', 'c' => 'Sollwert bei „Nacht“', 'd' => 18.0, 'suffix' => ' °C', 'min' => 5, 'max' => 30],
                ];
            case 'taster':
                $o = [
                    ['k' => 'info', 'c' => 'Taster'],
                    ['n' => 'tg_in', 'k' => 'var', 'c' => 'Taster-Variable (meldet den Druck als Wert)', 'req' => true,
                        'help' => ['Welche Werte meldet mein Taster?', [
                            'Viele Zigbee-/Z-Wave-Taster melden je Druck einen Wert: z. B. „single“, „double“, „hold“ (Zigbee2MQTT „action“) oder Zahlen 1/2/3 (Z-Wave „Central Scene“).',
                            'Den Wert siehst du im Objektbaum, wenn du einmal drückst. Bei einem einfachen Schalter (wahr/falsch) trägst du bei „kurz“ „wahr“ ein.',
                            'Groß-/Kleinschreibung spielt keine Rolle; mehrere Werte mit Komma, z. B. „1, single“.',
                        ]]],
                ];
                foreach (['k' => ['kurz', 'single, 1, wahr'], 'd' => ['doppelt', 'double, 2'], 'l' => ['lang', 'hold, long, 3']] as $p => [$label, $def]) {
                    $o[] = ['k' => 'info', 'c' => ucfirst($label) . ' drücken'];
                    $o[] = ['n' => 'tg_' . $p . 'wert', 'k' => 'text', 'c' => 'Wert der Taster-Variable bei „' . $label . '“', 'd' => $def];
                    $o[] = ['n' => 'tg_' . $p . 'akt', 'k' => 'select', 'c' => 'Aktion', 'd' => $p === 'k' ? 1 : 0, 'options' => self::TG_ACTIONS];
                    $o[] = ['n' => 'tg_' . $p . 'ziel', 'k' => 'var', 'c' => 'Ziel (Licht, Gerät …)', 'when' => [['tg_' . $p . 'akt', [1, 2, 3, 4]]]];
                    $o[] = ['n' => 'tg_' . $p . 'zwert', 'k' => 'float', 'c' => 'Wert (bei „auf Wert setzen“; bei „Hausmodus“ 0 Tag … 4 Urlaub)', 'd' => 0.0, 'min' => -1000, 'max' => 100000, 'when' => [['tg_' . $p . 'akt', [4, 5]]]];
                }
                return $o;
            case 'wecklicht':
                return [
                    ['k' => 'info', 'c' => 'Licht'],
                    ['n' => 'wl_out', 'k' => 'vars', 'c' => 'Dimmer', 'req' => true],
                    ['n' => 'wl_richtung', 'k' => 'select', 'c' => 'Art', 'd' => 0, 'options' => self::WL_DIR],
                    ['k' => 'info', 'c' => 'Wann?'],
                    ['n' => 'wl_start', 'k' => 'select', 'c' => 'Start', 'd' => 0, 'options' => self::WL_START],
                    ['n' => 'wl_zeit', 'k' => 'time', 'c' => 'Uhrzeit (Aufwachen: wann es hell sein soll; Einschlafen: Beginn)', 'd' => self::time(6, 30), 'when' => [['wl_start', [0]]]],
                    ['n' => 'wl_wecker', 'k' => 'var', 'c' => 'Weckzeit-Variable (bei „Weckzeit aus einer Variable“)', 'when' => [['wl_start', [1]]]],
                    ['n' => 'wl_we', 'k' => 'bool', 'c' => 'Auch am Wochenende', 'd' => false],
                    ['k' => 'info', 'c' => 'Verlauf'],
                    ['n' => 'wl_dauer', 'k' => 'int', 'c' => 'Dauer der Rampe', 'd' => 20, 'suffix' => ' min', 'min' => 1, 'max' => 120],
                    ['n' => 'wl_ziel', 'k' => 'int', 'c' => 'Helligkeit am Ende des Aufwachens', 'd' => 80, 'suffix' => ' %', 'min' => 1, 'max' => 100, 'when' => [['wl_richtung', [0]]]],
                ];
            case 'szene':
                return [
                    ['k' => 'info', 'c' => 'Wann? Beim Wechsel des Hausmodus auf …'],
                    ['n' => 'sz_m0', 'k' => 'bool', 'c' => 'Tag', 'd' => false],
                    ['n' => 'sz_m1', 'k' => 'bool', 'c' => 'Abend', 'd' => false],
                    ['n' => 'sz_m2', 'k' => 'bool', 'c' => 'Nacht', 'd' => true],
                    ['n' => 'sz_m3', 'k' => 'bool', 'c' => 'Abwesend', 'd' => true],
                    ['n' => 'sz_m4', 'k' => 'bool', 'c' => 'Urlaub', 'd' => true],
                    ['n' => 'sz_verz', 'k' => 'int', 'c' => 'Verzögerung (z. B. bei „Nacht“ Zeit zum Ins-Bett-Gehen; wechselt der Modus vorher zurück, entfällt es)', 'd' => 0, 'suffix' => ' min', 'min' => 0, 'max' => 240],
                    ['k' => 'info', 'c' => 'Was?'],
                    ['n' => 'sz_out', 'k' => 'vars', 'max' => 16, 'c' => 'Gerät', 'req' => true],
                    ['n' => 'sz_aktion', 'k' => 'select', 'c' => 'Diese Geräte', 'd' => 0, 'options' => self::SZ_ACTIONS],
                    ['n' => 'sz_hell', 'k' => 'int', 'c' => 'Helligkeit bei „einschalten“ (Dimmer)', 'd' => 100, 'suffix' => ' %', 'min' => 1, 'max' => 100, 'when' => [['sz_aktion', [1]]]],
                    ['n' => 'sz_wert', 'k' => 'float', 'c' => 'Wert bei „auf einen Wert setzen“ (z. B. 17 °C, Rollladen 100 %)', 'd' => 0.0, 'min' => -1000, 'max' => 100000, 'when' => [['sz_aktion', [2]]]],
                    ['k' => 'info', 'c' => 'Meldung'],
                    ['n' => 'sz_push', 'k' => 'bool', 'c' => 'Melden, was geschaltet wurde (wie, legst du unter „🔔 Meldungen“ fest)', 'd' => false],
                ];
            case 'ruecksetzen':
                return [
                    ['k' => 'info', 'c' => 'Melder'],
                    ['n' => 'mr_mel', 'k' => 'vars', 'max' => 16, 'c' => 'Melder (wahr = Bewegung/Auslösung)', 'req' => true],
                    ['n' => 'mr_halt', 'k' => 'int', 'c' => 'Zurücksetzen, wenn so lange keine neue Meldung kam (länger als die eigene Rückfallzeit des Melders)', 'd' => 60, 'suffix' => ' s', 'min' => 5, 'max' => 3600],
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
                    ['n' => 'gf_push', 'k' => 'bool', 'c' => 'Melden (wie, legst du unter „🔔 Meldungen“ fest: Push, E-Mail, Ruhezeit)', 'd' => true],
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
                for ($i = 1; $i <= ($f['max'] ?? self::SLOTS); $i++) {
                    $row[$f['n'] . $i] = 0;
                }
            } elseif ($f['k'] === 'var' || $f['k'] === 'object') {
                $row[$f['n']] = 0;
            } else {
                $row[$f['n']] = $f['d'];
            }
        }
        return $row;
    }

    /**
     * Ist ein Feld bei diesen Auswahlwerten sichtbar? 'when' => [[Auswahlfeld, [Werte]], …] — eine erfüllte
     * Bedingung genügt (z. B. Helligkeitssensor bei „Dunkelheit“ ODER „Helligkeit über Schwelle“).
     */
    public static function visibleWhen(array $f, array $values, string $type): bool
    {
        if (!isset($f['when'])) {
            return true;
        }
        $defs = [];
        foreach (self::fields($type) as $g) {
            if (isset($g['n'])) {
                $defs[$g['n']] = $g['d'] ?? null;
            }
        }
        foreach ($f['when'] as [$field, $vals]) {
            $v = array_key_exists($field, $values) ? $values[$field] : ($defs[$field] ?? null);
            if (in_array((int)$v, $vals, true)) {
                return true;
            }
        }
        return false;
    }

    /** Auswahlfelder eines Typs, von denen andere Felder abhängen. */
    public static function controllers(string $type): array
    {
        $c = [];
        foreach (self::fields($type) as $f) {
            foreach ($f['when'] ?? [] as [$field, $_]) {
                $c[$field] = true;
            }
        }
        return array_keys($c);
    }

    /** Feldkürzel je Typ („zl_“, „bl_“, …) — jeder Typ hat genau eines. */
    public static function prefix(string $type): string
    {
        foreach (self::fields($type) as $f) {
            if (isset($f['n'])) {
                return substr((string)$f['n'], 0, 3);
            }
        }
        return '';
    }

    /**
     * Felder ANDERER Typen aus einer Zeile entfernen. Der Bearbeiten-Dialog speicherte bis 0.1.35 die Felder
     * aller Typen in jede Zeile (bei 20 Typen über 350 Werte je Zeile) — daran hat sich die Konsole beim
     * Speichern verschluckt (Dietmar 14.10.2026).
     */
    public static function stripForeign(array $row): array
    {
        $own = self::prefix((string)($row['Type'] ?? ''));
        static $all = null;
        if ($all === null) {
            $all = [];
            foreach (self::TYPES as $t => $_) {
                $all[] = self::prefix($t);
            }
        }
        foreach (array_keys($row) as $k) {
            $p = substr((string)$k, 0, 3);
            if ($p !== $own && in_array($p, $all, true) && preg_match('/^[a-z]{2}_/', (string)$k)) {
                unset($row[$k]);
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
                }, range(1, self::slotsIn($row, $f['n']))) : [$f['n']];
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
            }, range(1, self::slotsIn($row, $f['n']))) : [$f['n']];
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

    /** Zahl ohne überflüssige Nachkommastellen, deutsches Komma („17“, „17,5“). */
    public static function num(float $v): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'));
    }

    /** Anzahl der Plätze eines Mehrfach-Felds (Standard SLOTS, je Feld über 'max' erweiterbar). */
    public static function slots(string $name): int
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::TYPES as $t => $_) {
                foreach (self::fields($t) as $f) {
                    if (isset($f['n']) && $f['k'] === 'vars') {
                        $map[$f['n']] = (int)($f['max'] ?? self::SLOTS);
                    }
                }
            }
        }
        return $map[$name] ?? self::SLOTS;
    }

    /**
     * Plätze eines Mehrfach-Felds in DIESER Zeile: mindestens die Katalog-Anzahl, sonst so viele, wie die Zeile
     * trägt — die Obergrenze ist nur der Startwert, der Dialog bietet immer weitere freie Plätze an.
     */
    public static function slotsIn(array $row, string $name): int
    {
        $n = self::slots($name);
        $len = strlen($name);
        foreach ($row as $k => $_) {
            $k = (string)$k;
            if (strncmp($k, $name, $len) === 0 && ctype_digit($rest = substr($k, $len))) {
                $n = max($n, (int)$rest);
            }
        }
        return $n;
    }

    /** Höchster belegter Platz eines Mehrfach-Felds (0 = keiner). */
    public static function lastFilled(array $row, string $name): int
    {
        $last = 0;
        for ($i = 1; $i <= self::slotsIn($row, $name); $i++) {
            if ((int)($row[$name . $i] ?? 0) >= self::$minId) {
                $last = $i;
            }
        }
        return $last;
    }

    /** Variablen eines Mehrfach-Felds (0 und doppelte entfernt). */
    public static function vars(array $row, string $name): array
    {
        $out = [];
        for ($i = 1; $i <= self::slotsIn($row, $name); $i++) {
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
            case 'anwesenheit':
                $in = self::vars($row, 'an_p');
                break;
            case 'szene':
                $out = self::vars($row, 'sz_out');
                break;
            case 'ruecksetzen':
                $in = self::vars($row, 'mr_mel'); // nur „in“: das eigene Zurücksetzen kommt als Melder-Meldung zurück
                break;
            case 'gehen':
                $read = array_merge(self::vars($row, 'gc_kon'), self::vars($row, 'gc_geraet'));
                if (!empty($row['gc_aus'])) {
                    $out = self::vars($row, 'gc_geraet');
                }
                break;
            case 'klingel':
                if ((int)($row['kl_taster'] ?? 0) >= self::$minId) {
                    $in[] = (int)$row['kl_taster'];
                }
                $out = array_values(array_unique(array_merge(self::vars($row, 'kl_licht'), self::vars($row, 'kl_laut'))));
                break;
            case 'sommer':
                foreach (['sl_innen', 'sl_aussen', 'sl_rhinnen', 'sl_rhaussen'] as $f) {
                    if ((int)($row[$f] ?? 0) >= self::$minId) {
                        $in[] = (int)$row[$f];
                    }
                }
                $in = array_merge($in, self::vars($row, 'sl_kon'));
                break;
            case 'ausfall':
                if ((int)($row['af_var'] ?? 0) >= self::$minId) {
                    $in[] = (int)$row['af_var'];
                }
                break;
            case 'zeitschalt':
                $out = self::vars($row, 'zs_out');
                break;
            case 'alarm':
                $in = self::vars($row, 'al_mel');
                $out = array_values(array_unique(array_merge(self::vars($row, 'al_licht'), self::vars($row, 'al_hoch'), self::vars($row, 'al_aus'))));
                break;
            case 'rollladen':
                $out = self::vars($row, 'rl_out');
                $in = self::vars($row, 'rl_tuer');
                break;
            case 'standby':
                foreach (['sb_power' => 'in', 'sb_out' => 'out'] as $f => $k) {
                    if ((int)($row[$f] ?? 0) >= self::$minId) {
                        ${$k}[] = (int)$row[$f];
                    }
                }
                break;
            case 'lueften':
                if ((int)($row['lf_var'] ?? 0) >= self::$minId) {
                    $in[] = (int)$row['lf_var'];
                }
                $in = array_merge($in, self::vars($row, 'lf_kon'));
                break;
            case 'heizung':
                $out = self::vars($row, 'hz_soll');
                break;
            case 'taster':
                if ((int)($row['tg_in'] ?? 0) >= self::$minId) {
                    $in[] = (int)$row['tg_in'];
                }
                foreach (['k', 'd', 'l'] as $p) {
                    $z = (int)($row['tg_' . $p . 'ziel'] ?? 0);
                    if ($z >= self::$minId && !in_array($z, $out, true)) {
                        $out[] = $z;
                    }
                }
                break;
            case 'wecklicht':
                $out = self::vars($row, 'wl_out');
                if ((int)($row['wl_wecker'] ?? 0) >= self::$minId) {
                    $read[] = (int)$row['wl_wecker'];
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
        if ($t === 'gehen') {
            $any = false;
            foreach ([2, 3, 4] as $m) {
                $any = $any || !empty($row['gc_m' . $m]);
            }
            if (!$any) {
                $p[] = 'Kein Hausmodus angekreuzt, bei dem geprüft wird.';
            }
        }
        if ($t === 'rollladen') {
            foreach (['rl_spaet' => 'Spätestens', 'rl_downzeit' => 'Uhrzeit fürs Schließen'] as $f => $c) {
                $tm = json_decode((string)($row[$f] ?? ''), true);
                if (is_array($tm) && (int)($tm['hour'] ?? 12) < 12 && ($f === 'rl_spaet' || (int)($row['rl_downref'] ?? 1) === 0)) {
                    $p[] = '„' . $c . '“ liegt vor 12 Uhr — Schließzeiten nach Mitternacht werden nicht unterstützt (bitte z. B. 23:59 wählen).';
                }
            }
        }
        if ($t === 'lueften' && (float)($row['lf_ok'] ?? 55) >= (float)($row['lf_hoch'] ?? 65)) {
            $p[] = '„Wieder gut unter“ muss kleiner sein als „Erinnern ab“ — sonst wechselt die Meldung ständig.';
        }
        if ($t === 'zeitschalt') {
            $any = false;
            foreach (self::WEEKDAYS as $n => $_) {
                $any = $any || !empty($row['zs_t' . $n]);
            }
            if (!$any) {
                $p[] = 'Kein Wochentag angekreuzt.';
            }
        }
        if ($t === 'taster') {
            $any = false;
            foreach (['k', 'd', 'l'] as $q) {
                $akt = (int)($row['tg_' . $q . 'akt'] ?? 0);
                $any = $any || $akt > 0;
                if (in_array($akt, [1, 2, 3, 4], true) && (int)($row['tg_' . $q . 'ziel'] ?? 0) < self::$minId) {
                    $p[] = 'Aktion „' . self::TG_ACTIONS[$akt] . '“ braucht ein Ziel.';
                }
            }
            if (!$any) {
                $p[] = 'Keine Aktion für kurz, doppelt oder lang gewählt.';
            }
        }
        if ($t === 'wecklicht' && (int)($row['wl_start'] ?? 0) === 1 && (int)($row['wl_wecker'] ?? 0) < self::$minId) {
            $p[] = 'Weckzeit-Variable ist nicht gewählt.';
        }
        if ($t === 'szene') {
            $any = false;
            foreach (self::MODES as $m => $_) {
                $any = $any || !empty($row['sz_m' . $m]);
            }
            if (!$any) {
                $p[] = 'Kein Hausmodus angekreuzt, bei dessen Wechsel geschaltet wird.';
            }
        }
        if ($t === 'daemmerung') {
            $needLux = false; // ohne eigenen Sensor gilt der zentrale unter „🏠 Hausmodus“ (ab 0.1.39)
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
            case 'gehen':
                $modes = [];
                foreach ([3, 4, 2] as $m) {
                    if (!empty($row['gc_m' . $m])) {
                        $modes[] = self::MODES[$m];
                    }
                }
                $p[] = 'bei ' . (implode('/', $modes) ?: '—');
                $p[] = count(self::vars($row, 'gc_kon')) . ' Kontakt(e)' . (count(self::vars($row, 'gc_geraet')) > 0 ? ', ' . count(self::vars($row, 'gc_geraet')) . ' Gerät(e)' : '');
                break;
            case 'klingel':
                $p[] = count(self::vars($row, 'kl_licht')) > 0 ? 'Licht ' . (int)($row['kl_dauer'] ?? 3) . ' min' : 'nur Meldung';
                break;
            case 'sommer':
                $p[] = 'ab ' . self::num((float)($row['sl_min'] ?? 23)) . ' °C innen, ±' . self::num((float)($row['sl_diff'] ?? 1.5)) . ' K';
                if ((int)($row['sl_rhinnen'] ?? 0) >= self::$minId && (int)($row['sl_rhaussen'] ?? 0) >= self::$minId) {
                    $p[] = 'mit Taupunkt';
                }
                break;
            case 'ausfall':
                $p[] = ((int)($row['af_modus'] ?? 0) === 0 ? 'ohne Strom' : 'am Stück') . ' > ' . (int)($row['af_dauer'] ?? 120) . ' min';
                break;
            case 'zeitschalt':
                $days = [];
                foreach (self::WEEKDAYS as $n => $l) {
                    if (!empty($row['zs_t' . $n])) {
                        $days[] = $l;
                    }
                }
                $mo = (int)($row['zs_modus'] ?? 0);
                $p[] = ($mo !== 1 ? 'ein ' . self::hm((string)($row['zs_ein'] ?? '')) : '') . ($mo === 0 ? ', ' : '') . ($mo !== 2 ? 'aus ' . self::hm((string)($row['zs_aus'] ?? '')) : '');
                $p[] = count($days) === 7 ? 'täglich' : (implode(' ', $days) ?: 'kein Tag');
                break;
            case 'alarm':
                $p[] = count(self::vars($row, 'al_mel')) . ' Melder';
                $n = count(self::vars($row, 'al_licht')) + count(self::vars($row, 'al_hoch')) + count(self::vars($row, 'al_aus'));
                $p[] = $n . ' Gerät(e) bei Alarm';
                break;
            case 'rollladen':
                $p[] = count(self::vars($row, 'rl_out')) . ' Rollladen';
                $p[] = 'auf ' . (self::RL_UP[(int)($row['rl_upref'] ?? 1)] ?? '');
                $p[] = 'zu ' . (self::RL_DOWN[(int)($row['rl_downref'] ?? 1)] ?? '');
                break;
            case 'standby':
                $p[] = 'unter ' . (float)($row['sb_schwelle'] ?? 5) . ' W für ' . (int)($row['sb_dauer'] ?? 30) . ' min → aus';
                break;
            case 'ruecksetzen':
                $p[] = count(self::vars($row, 'mr_mel')) . ' Melder';
                $p[] = 'zurück nach ' . (int)($row['mr_halt'] ?? 60) . ' s ohne neue Meldung';
                break;
            case 'lueften':
                $p[] = 'ab ' . self::num((float)($row['lf_hoch'] ?? 65)) . ', gut unter ' . self::num((float)($row['lf_ok'] ?? 55));
                break;
            case 'heizung':
                $p[] = count(self::vars($row, 'hz_soll')) . ' Sollwert(e)';
                $low = [];
                foreach ([3 => 'hz_abw', 4 => 'hz_url', 2 => 'hz_nacht'] as $m => $f) {
                    if (!empty($row['hz_m' . $m])) {
                        $low[] = self::MODES[$m] . ' ' . self::num((float)($row[$f] ?? 0)) . ' °C';
                    }
                }
                $p[] = implode(', ', $low) ?: 'nichts angekreuzt';
                break;
            case 'taster':
                $acts = [];
                foreach (['k' => 'kurz', 'd' => 'doppelt', 'l' => 'lang'] as $q => $l) {
                    if ((int)($row['tg_' . $q . 'akt'] ?? 0) > 0) {
                        $acts[] = $l;
                    }
                }
                $p[] = implode('/', $acts) ?: 'keine Aktion';
                break;
            case 'wecklicht':
                $p[] = (int)($row['wl_richtung'] ?? 0) === 0 ? 'Aufwachen' : 'Einschlafen';
                $p[] = (int)($row['wl_dauer'] ?? 20) . ' min';
                break;
            case 'szene':
                $modes = [];
                foreach (self::MODES as $m => $l) {
                    if (!empty($row['sz_m' . $m])) {
                        $modes[] = $l;
                    }
                }
                $p[] = 'bei ' . (implode('/', $modes) ?: '—');
                $p[] = count(self::vars($row, 'sz_out')) . ' Gerät(e) ' . (self::SZ_ACTIONS[(int)($row['sz_aktion'] ?? 0)] ?? '');
                if ((int)($row['sz_verz'] ?? 0) > 0) {
                    $p[] = 'nach ' . (int)$row['sz_verz'] . ' min';
                }
                break;
            case 'anwesenheit':
                $p[] = count(self::vars($row, 'an_p')) . ' Signal(e)';
                $p[] = '„niemand da“ nach ' . (int)($row['an_weg'] ?? 10) . ' min';
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
