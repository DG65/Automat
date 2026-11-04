<?php

declare(strict_types=1);

require_once __DIR__ . '/AUTOMATKatalog.php';

// ===========================================================================
// AUTOMATErkennung — ordnet Variablen einer Geräte-Rolle zu (Melder, Licht,
// Kontakt, Sollwert, Helligkeit, Leistung) und leitet daraus Vorschläge ab.
// Ohne Symcon: das Modul liefert je Variable einen Steckbrief
//   vid, type (0 Bool, 1 Int, 2 Float, 3 String), action, profile, pres,
//   suffix, name, inst (Name der Geräte-Instanz), ident, room, roomKey
// und bekommt Rollen und Vorschläge zurück.
//
// Grundsatz: lieber einen Vorschlag zu wenig als einen falschen. Jede Regel
// ist an echten Installationen (Z-Wave, Matter, Shelly, KNX, HomeMatic …)
// ausgerichtet, aber kein Vorschlag wird ungefragt angelegt — der Nutzer
// bestätigt im Bearbeiten-Dialog.
// ===========================================================================

class AUTOMATErkennung
{
    // Nebenwerte von Geräten, die nie die Hauptfunktion sind
    private const NOISE = '/stromversorgung|batter|ersatz|sicherung|protection|notification|benachrichtigung|szene|scene|meter\s*\(|zähler|blink|flash|modus aktiv|sabotage|tamper|signal|rssi|firmware|update|reachab|erreichbar|status ?led|led-hell|identify|kalibr/i';
    private const LIGHT_WORDS = '/licht|light|lampe|leuchte|beleuchtung|strahler|spot|deckenl|wandl|stehl/i';
    private const MOTION_WORDS = '/bewegung|motion|präsenz|praesenz|presence|occupancy|\bbwm\b|pir\b/i';
    private const CONTACT_WORDS = '/fenster|window|tür|tuer|door|kontakt|contact|\btor\b|garage/iu';
    private const SETPOINT_EXCLUDE = '/warmwasser|brauchwasser|dhw|beifahrer|fahrer|pool|speicher|puffer|vorlauf|kühl|cool|auto|fahrzeug|klimaanlage/i';
    private const APPLIANCE = '/wasch|trockner|spül|spuel|geschirr|washer|dryer|dishwasher/i';
    private const OUTDOOR = '/außen|aussen|terrasse|garten|fassade|eingang|outdoor|carport|hof\b|einfahrt|vordach|balkon/i';
    private const TIMED_ROOMS = '/flur|treppe|diele|keller|gang\b|windfang|abstell|vorrat|garage|hwr|wc\b|toilette|stair|hall|cellar|speisekammer|dachboden/i';
    private const ROOM_STOP = ['licht', 'light', 'lampe', 'leuchte', 'aktor', 'actor', 'sensor', 'melder', 'bewegungsmelder', 'thermostat',
        'fenster', 'tür', 'tuer', 'kontakt', 'contact', 'stromversorgung', 'status', 'schalter', 'switch', 'dimmer', 'steckdose', 'plug',
        'heizung', 'heating', 'control', 'präsenzmelder', 'motion', 'window', 'door', 'links', 'rechts', 'oben', 'unten', 'senkrecht',
        'kipp', 'l', 'r', 'und', 'the', 'der', 'die', 'das', 'zigbee', 'zwave', 'z-wave', 'matter', 'shelly', 'hue', 'knx'];

    /** Rolle einer Variable oder '' (nicht zuordenbar). */
    public static function role(array $v): string
    {
        $text = mb_strtolower(trim(($v['name'] ?? '') . ' ' . ($v['inst'] ?? '') . ' ' . ($v['ident'] ?? '')));
        $profile = mb_strtolower((string)($v['profile'] ?? ''));
        $suffix = mb_strtolower(trim((string)($v['suffix'] ?? '')));
        $type = (int)($v['type'] ?? -1);
        $action = !empty($v['action']);
        $presGuid = (string)($v['pres']['PRESENTATION'] ?? '');

        // Leistung eines Haushaltsgeräts (zuerst: „Meter“ ist dort kein Rauschen)
        if (($type === 1 || $type === 2) && ($suffix === 'w' || preg_match('/(^|[.~])watt|\.w$/', $profile))
            && preg_match(self::APPLIANCE, $text)) {
            return 'power';
        }
        // Türklingel (vor Taster: eine Klingel ist auch ein Taster)
        if (!$action && preg_match('/klingel|doorbell|türglocke|\bring\b/iu', $text) && !preg_match('/batter|lautstärke|volume|ton\b|melodie/iu', $text)) {
            return 'bell';
        }
        // Taster melden ihren Druck oft als Szene/Aktion — vor dem Rausch-Filter („szene“ steht dort)
        if (!$action && preg_match('/taster|button|tastendruck|\baction\b|click|klick|central ?scene|zentralszene/i', $text)
            && !preg_match('/batter|signal|rssi|firmware/i', $text)) {
            return 'button';
        }
        // Kühlschrank/Gefriertruhe mit Leistungsmessung (Ausfall-Wächter)
        if (($type === 1 || $type === 2) && ($suffix === 'w' || preg_match('/(^|[.~])watt|\.w$/', $profile))
            && preg_match('/kühlschrank|kuehlschrank|gefrier|tiefkühl|fridge|freezer|kühltruhe/iu', $text)) {
            return 'cooling';
        }
        if (preg_match(self::NOISE, $text)) {
            return '';
        }
        // Raum- und Außentemperatur (Messwert, kein Sollwert, keine Wasser-/Technik-Temperatur)
        if (($type === 1 || $type === 2) && !$action
            && (preg_match('/temperature|temperatur/', $profile) || $suffix === '°c')
            && !preg_match('/soll|setpoint|vorlauf|rücklauf|ruecklauf|wasser|kessel|speicher|puffer|cpu|prognose|forecast|minimal|maximal|\bmin\b|\bmax\b|gefühlt|taupunkt|boden|vpd|mittel|gleitend/iu', $text)) {
            return 'temp';
        }
        // Wasser-/Rauchmelder (Alarmzustand, keine Messwerte wie Wassertemperatur)
        if ($type === 0 && !$action && (preg_match('/smoke|water|leak|flood|moisture/', $profile)
            || preg_match('/wassermelder|wasserleck|leck|leak|water ?leak|flood|überschwemm|rauch|smoke|feuer|fire|kohlenmonoxid|\bco[- ]?melder/iu', $text))
            && !preg_match('/temperatur|wasserstand|füllstand|pegel|druck|warmwasser|brauchwasser/iu', $text)) {
            return 'alarm';
        }
        // Rollläden/Jalousien (Position mit Aktion)
        // ~ShutterMoveStop/~ShutterMoveStep sind Fahrbefehle (0–4), keine Position — dorthin ginge sonst 100
        if (($type === 1 || $type === 2) && $action && !preg_match('/move|step|stop/', $profile) && (preg_match('/shutter|blind|jalousie|rollladen|rolladen/', $profile)
            || preg_match('/rollladen|rolladen|rollo\b|jalousie|raffstore|shutter|blind|markise/iu', $text))) {
            return 'shutter';
        }
        // Raumluft: Feuchte oder CO₂ (keine Bodenfeuchte, kein Außen-/Wetterwert)
        if (($type === 1 || $type === 2) && !$action
            && (preg_match('/humidity|co2/', $profile) || preg_match('/luftfeuchte|luftfeuchtigkeit|relative ?humidity|humidity|\bco2\b|co₂|kohlendioxid/iu', $text) || $suffix === 'ppm')
            && !preg_match('/boden|soil|außen|aussen|outdoor|wetter|prognose|forecast|vpd|taupunkt/iu', $text)) {
            return 'air';
        }
        // Anwesenheit einer Person im Haus (nicht: Präsenzmelder im Raum, Fahrzeuge, Kalender)
        if ($type === 0 && !$action
            && (preg_match('/anwesen|presence/', $profile) || preg_match('/zuhause|zu hause|daheim|anwesenheit|geofenc|presence|\bhome\b/i', $text))
            && !preg_match('/sensor|melder|occupancy|bewegung|motion|geplant|planned|kalender|calendar|krank|homelink|simulation|fahrzeug|vehicle/i', $text)
            && !preg_match('/vehicle|tessie|\bcar\b|fahrzeug|calendar|kalender|ical/i', (string)($v['module'] ?? ''))) {
            return 'presence';
        }
        // Kalender-/Planwerte („Geplante Anwesenheit“) sind keine Messung
        if (preg_match('/geplant|planned/i', $text) || preg_match('/calendar|kalender|ical/i', (string)($v['module'] ?? ''))) {
            return '';
        }
        if ($type === 0 && !$action && (preg_match('/motion|presence|occupancy/', $profile) || preg_match(self::MOTION_WORDS, $text))) {
            return 'motion';
        }
        // Riegel/Schnappverschluss eines Türschlosses sagen „verriegelt“, nicht „offen“ — kein Fenster-/Türkontakt
        // (live 17.10.2026: „Türschloß (Status Türriegel)“ ließ „Fenster offen Windfang“ dauerhaft absenken).
        $lockPart = preg_match('/riegel|schnapp|latch|bolt|verriegel/iu', $text) || preg_match('/^DoorLock(Bolt|Latch)/i', (string)($v['ident'] ?? ''));
        if (($type === 0 || $type === 1) && !$action && !$lockPart
            && (preg_match('/window|door|contact|fenster/', $profile) || preg_match(self::CONTACT_WORDS, $text))) {
            return 'contact';
        }
        if ($type === 0 && $action && preg_match(self::LIGHT_WORDS, $text)
            && ($profile === '' || strpos($profile, 'switch') !== false || $presGuid !== '')) {
            return 'light';
        }
        if (($type === 1 || $type === 2) && $action && preg_match(self::LIGHT_WORDS, $text)
            && (strpos($profile, 'intensity') !== false || $suffix === '%' || strpos($profile, 'brightness') !== false)) {
            return 'light';
        }
        if (($type === 1 || $type === 2) && !$action
            && (preg_match('/illumin|lux/', $profile) || in_array($suffix, ['lx', 'lux'], true) || preg_match('/helligkeit|illuminance|\blux\b/', $text))) {
            return 'lux';
        }
        if (($type === 1 || $type === 2) && $action
            && (preg_match('/setpoint|temperature/', $profile) || $suffix === '°c')
            && !preg_match(self::SETPOINT_EXCLUDE, $text)) {
            return 'setpoint';
        }
        return '';
    }

    /**
     * Meldet ein Kontakt „wahr“ = geschlossen? Aus den Darstellungs-Optionen
     * (Beschriftung „Geschlossen“ am Wert true) oder ~Window.Reversed.
     */
    /** Suchmuster für Fenster-/Türkontakte (Namen). */
    public static function contactPattern(): string
    {
        return self::CONTACT_WORDS;
    }

    /**
     * Meldet dieser Kontakt „wahr“ für ZU? Maßgeblich ist zuerst die Beschriftung des Werts (Darstellung, sonst
     * Profil): „Geschlossen“, „Gesperrt“, „Verriegelt“ … bei wahr → ja; „Offen“, „Entsperrt“ … bei wahr → nein.
     * Ein Türschloss mit „~Lock“ (wahr = Gesperrt) heißt also: gesperrt = zu (Dietmar 17.10.2026, Haustüre #32185).
     * Ohne Beschriftung: Matter „Boolean State“ (wahr = Kontakt = zu), dann Profil „…Reversed“.
     */
    public static function contactInverted(array $v): bool
    {
        $closed = '/geschlossen|closed|\bzu\b|gesperrt|verriegelt|abgeschlossen|\blocked|gesichert|secured/iu';
        $open = '/offen|geöffnet|open|entsperrt|unlocked|entriegelt|ungesichert|unsecured/iu';
        $cap = [];
        $opts = $v['pres']['OPTIONS'] ?? null;
        if (is_string($opts)) {
            $opts = json_decode($opts, true);
        }
        foreach (is_array($opts) ? $opts : [] as $o) {
            if (is_array($o) && array_key_exists('Value', $o) && trim((string)($o['Caption'] ?? '')) !== '') {
                $cap[!empty($o['Value']) ? 1 : 0] = (string)$o['Caption'];
            }
        }
        if (count($cap) === 0) {
            foreach ((array)($v['assoc'] ?? []) as $val => $name) {
                if (trim((string)$name) !== '') {
                    $cap[!empty($val) ? 1 : 0] = (string)$name;
                }
            }
        }
        if (isset($cap[1])) {
            if (preg_match($open, $cap[1])) {
                return false;
            }
            if (preg_match($closed, $cap[1])) {
                return true;
            }
        }
        if (isset($cap[0])) {
            if (preg_match($open, $cap[0])) {
                return true;
            }
            if (preg_match($closed, $cap[0])) {
                return false;
            }
        }
        // Matter-Kontaktsensor (Cluster „Boolean State“): wahr = Kontakt = GESCHLOSSEN — live 17.10.2026
        if (preg_match('/^BooleanState/i', (string)($v['ident'] ?? ''))) {
            return true;
        }
        return strpos(mb_strtolower((string)($v['profile'] ?? '')), 'reversed') !== false;
    }

    /** Grundformen von Raumwörtern: Kurz- und Langform werden gleich („Essen“/„Esszimmer“ → „ess“). */
    private const ROOM_STEMS = [
        'essen' => 'ess', 'ess' => 'ess',
        'wohnen' => 'wohn', 'wohn' => 'wohn',
        'schlafen' => 'schlaf', 'schlaf' => 'schlaf',
        'kinder' => 'kind', 'kinds' => 'kind', 'kind' => 'kind',
        'bade' => 'bad', 'bad' => 'bad',
        'gäste' => 'gast', 'gaeste' => 'gast', 'gasts' => 'gast', 'gast' => 'gast',
        'arbeiten' => 'arbeit', 'arbeits' => 'arbeit', 'arbeit' => 'arbeit',
        'spielen' => 'spiel', 'spiel' => 'spiel',
        'kochen' => 'koch', 'koch' => 'koch',
        'baden' => 'bad',
    ];

    /** Grundform eines Raumworts: „Esszimmer“ → „ess“, „Kinderzimmer“ → „kind“, „Wohnen“ → „wohn“. */
    public static function roomStem(string $word): string
    {
        $w = mb_strtolower(trim($word));
        if (mb_strlen($w) > 7 && mb_substr($w, -6) === 'zimmer') {
            $w = mb_substr($w, 0, -6);
        }
        return self::ROOM_STEMS[$w] ?? $w;
    }

    /** Text als Folge von Raum-Grundformen, mit Leerzeichen umrahmt (für Ganzwort-Vergleiche). */
    /** Gleicher Raum? Kurz- und Langform zählen gleich („Bad“/„Badezimmer“, „Essen“/„Esszimmer“). */
    public static function sameRoom(string $a, string $b): bool
    {
        return trim($a) !== '' && self::stemSeq(mb_strtolower(trim($a))) === self::stemSeq(mb_strtolower(trim($b)));
    }

    private static function stemSeq(string $s): string
    {
        $s = mb_strtolower($s);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
        return ' ' . implode(' ', array_map([self::class, 'roomStem'], $parts)) . ' ';
    }

    /**
     * Bekannten Raum im Gerätenamen finden („Thermostat Kind II“ → „Kind II“, „Licht Essen“ → „Esszimmer“).
     * Verglichen wird über Raum-Grundformen; längster Treffer gewinnt, nur ganze Wörter („Kind I“ passt
     * nicht in „Kind II“); führende Raumnummern („101 Küche“) zählen optional.
     */
    public static function roomFromName(string $text, array $rooms): ?string
    {
        $hay = self::stemSeq($text);
        $best = null;
        $bestLen = 0;
        foreach ($rooms as $room) {
            $room = (string)$room;
            foreach (array_unique([self::stemSeq($room), self::stemSeq(preg_replace('/^\s*\d+\s*/u', '', $room))]) as $needle) {
                $len = mb_strlen(trim($needle));
                if ($len >= 2 && $len > $bestLen && mb_strpos($hay, $needle) !== false) {
                    $best = $room;
                    $bestLen = $len;
                }
            }
        }
        return $best;
    }

    /** Raumwörter aus einem Instanznamen (Gerätewörter entfernt): [Grundform => erstes Originalwort]. */
    public static function roomTokens(string $name): array
    {
        $name = mb_strtolower($name);
        $name = preg_replace('/\(.*?\)|[0-9]+/u', ' ', $name);
        $parts = preg_split('/[^\p{L}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($parts as $p) {
            if (mb_strlen($p) < 2 || in_array($p, self::ROOM_STOP, true)) {
                continue;
            }
            $stem = self::roomStem($p);
            if (!isset($out[$stem])) {
                $out[$stem] = $p;
            }
        }
        return $out;
    }

    /**
     * Gruppiert Steckbriefe nach Raum. Hat der Steckbrief ein 'room' (aus
     * Raumstruktur), gilt das. Sonst Namensgleichheit: Geräte, deren
     * Instanznamen ein Raumwort teilen („Licht Flur“, „Flur Bewegungsmelder“).
     * Rückgabe: [Raumname => [Steckbrief, …]]
     */
    public static function groupRooms(array $items, array $known = []): array
    {
        $rooms = [];
        $count = [];
        $pending = [];
        $orig = [];
        foreach ($items as $it) {
            if (!empty($it['room'])) {
                $rooms[(string)$it['room']][] = $it;
                continue;
            }
            $words = self::roomTokens((string)($it['inst'] ?? $it['name'] ?? ''));
            if (count($words) === 0) {
                continue;
            }
            $orig += $words;
            $tokens = array_map('strval', array_keys($words));
            foreach ($tokens as $t) {
                $count[$t] = ($count[$t] ?? 0) + 1;
            }
            $pending[] = [$it, $tokens];
        }
        // Jedes Gerät landet in genau EINEM Raum: dem Raumwort, das die meisten
        // Geräte teilen („Licht Treppe/Flur“ mit drei Flur-Geräten → Flur).
        $groups = [];
        foreach ($pending as [$it, $tokens]) {
            usort($tokens, function ($a, $b) use ($count) {
                return ($count[$b] <=> $count[$a]) ?: strcmp($a, $b);
            });
            $groups[$tokens[0]][] = $it;
        }
        foreach ($groups as $t => $list) {
            if (count($list) < 2) {
                continue;
            }
            $label = mb_convert_case((string)($orig[$t] ?? $t), MB_CASE_TITLE);
            foreach ($known as $k) {
                // Gleicher Raum in Raumstruktur (auch Kurz-/Langform: „Essen“ ↔ „Esszimmer“) → deren Name und Schreibweise
                if (trim(self::stemSeq((string)$k)) === $t || trim(self::stemSeq(preg_replace('/^\s*\d+\s*/u', '', (string)$k))) === $t) {
                    $label = (string)$k;
                }
            }
            if (isset($rooms[$label])) {
                $rooms[$label] = array_merge($rooms[$label], $list);
            } else {
                $rooms[$label] = $list;
            }
        }
        return $rooms;
    }

    /**
     * Vorschläge. $items = klassifizierte Steckbriefe (mit 'role'),
     * $existing = vorhandene Listenzeilen, $dismissed = ausgeblendete Schlüssel.
     * Rückgabe: Liste ['key','type','room','title','row' (vorbelegte Listenzeile),'why']
     */
    public static function suggest(array $items, array $existing, array $dismissed, array $knownRooms = [], array $habits = []): array
    {
        $used = [];
        foreach ($existing as $row) {
            $t = (string)($row['Type'] ?? '');
            foreach (AUTOMATKatalog::variables($row) as $list) {
                foreach ($list as $vid) {
                    $used[$t][$vid] = true;
                }
            }
        }
        $out = [];
        $add = function (string $type, string $room, string $title, array $row, string $why, array $vids) use (&$out, $used, $dismissed) {
            sort($vids);
            $key = $type . ':' . implode(',', $vids);
            if (in_array($key, $dismissed, true) || isset($out[$key])) {
                return;
            }
            foreach ($vids as $vid) {
                if (isset($used[$type][$vid])) {
                    return; // ein Automatismus dieses Typs nutzt die Variable schon
                }
            }
            $out[$key] = ['key' => $key, 'type' => $type, 'room' => $room, 'title' => $title, 'row' => $row, 'why' => $why];
        };
        $fill = function (array &$row, string $field, array $vids) {
            $i = 1;
            foreach ($vids as $vid) { // keine Obergrenze: der Dialog bietet immer weitere Plätze an
                $row[$field . $i++] = $vid;
            }
        };
        $byRole = function (array $list, string $role): array {
            $o = [];
            foreach ($list as $it) {
                if (($it['role'] ?? '') === $role) {
                    $o[$it['vid']] = $it;
                }
            }
            return array_values($o);
        };

        foreach (self::groupRooms($items, $knownRooms) as $room => $list) {
            $lights = $byRole($list, 'light');
            $motions = $byRole($list, 'motion');
            $contacts = $byRole($list, 'contact');
            $setpoints = $byRole($list, 'setpoint');
            $luxes = $byRole($list, 'lux');
            $lightIds = array_column($lights, 'vid');

            if (count($lights) > 0 && count($motions) > 0) {
                $row = AUTOMATKatalog::defaults('bewegung');
                $row['Name'] = AUTOMATText::t('Motion light %s', $room);
                $row['Room'] = $room;
                $fill($row, 'bl_mel', array_column($motions, 'vid'));
                $fill($row, 'bl_out', $lightIds);
                if (count($luxes) > 0) {
                    $row['bl_lux'] = $luxes[0]['vid'];
                }
                $add('bewegung', $room, AUTOMATText::t('Motion light %s', $room),
                    $row, AUTOMATText::t('%1$s sensor(s) and %2$s light(s) in the same room', count($motions), count($lights)) . (count($luxes) > 0 ? AUTOMATText::t(', with brightness sensor') : ''),
                    array_merge(array_column($motions, 'vid'), $lightIds));
            } elseif (count($lights) > 0 && preg_match(self::TIMED_ROOMS, $room)) {
                $row = AUTOMATKatalog::defaults('zeitlicht');
                $row['Name'] = AUTOMATText::t('Timed light %s', $room);
                $row['Room'] = $room;
                $fill($row, 'zl_out', $lightIds);
                $add('zeitlicht', $room, AUTOMATText::t('Timed light %s', $room), $row,
                    AUTOMATText::t('Light in a passage room without motion sensor — switches itself off after the light duration'), $lightIds);
            }
            if (count($contacts) > 0 && count($setpoints) > 0) {
                $row = AUTOMATKatalog::defaults('fenster');
                $row['Name'] = AUTOMATText::t('Window open %s', $room);
                $row['Room'] = $room;
                $fill($row, 'fo_kon', array_column($contacts, 'vid'));
                $fill($row, 'fo_soll', array_column($setpoints, 'vid'));
                $row['fo_invert'] = self::contactInverted($contacts[0]);
                $add('fenster', $room, AUTOMATText::t('Window open → heating down %s', $room), $row,
                    AUTOMATText::t('%1$s contact(s) and %2$s thermostat(s) in the same room', count($contacts), count($setpoints))
                    . ($row['fo_invert'] ? AUTOMATText::t(' (contact reports "true" = closed, detected)') : ''),
                    array_merge(array_column($contacts, 'vid'), array_column($setpoints, 'vid')));
            }
        }

        // Anwesenheit zusammenfassen: alle Personen-Signale des Hauses in einem Automatismus
        $pres = array_values(array_filter($items, function ($it) {
            return ($it['role'] ?? '') === 'presence';
        }));
        if (count($pres) > 0) {
            usort($pres, function ($a, $b) {
                return strcmp((string)$a['inst'] . $a['name'], (string)$b['inst'] . $b['name']);
            });
            $row = AUTOMATKatalog::defaults('anwesenheit');
            $row['Name'] = AUTOMATText::t('Presence');
            $row['Room'] = '';
            $fill($row, 'an_p', array_column($pres, 'vid'));
            $names = array_map(function ($it) {
                return trim((string)$it['inst']) ?: (string)$it['name'];
            }, $pres);
            $add('anwesenheit', '', AUTOMATText::t('Combine presence'), $row, AUTOMATText::t('%1$s presence signal(s) found (%2$s) — the house mode uses the result for "Away"', count($pres), implode(', ', $names)), array_column($pres, 'vid'));
        }

        // Zentral-Aus: beim Gehen/Schlafen alle gefundenen Lichter aus (ein Vorschlag fürs ganze Haus)
        $lights = array_values(array_filter($items, function ($it) {
            return ($it['role'] ?? '') === 'light';
        }));
        if (count($lights) >= 2) {
            usort($lights, function ($a, $b) {
                return strcmp((string)($a['room'] ?? '') . $a['inst'], (string)($b['room'] ?? '') . $b['inst']);
            });
            $row = AUTOMATKatalog::defaults('szene');
            $row['Name'] = AUTOMATText::t('Everything off when leaving and at night');
            $row['Room'] = '';
            $fill($row, 'sz_out', array_column($lights, 'vid'));
            $add('szene', '', AUTOMATText::t('Central off by house mode'), $row, AUTOMATText::t('%s light(s) found — off in "Night", "Away" and "Holiday"; please check in the dialog which of them should really go off', count($lights)), array_column($lights, 'vid'));
        }

        $byR = function (string $role) use ($items): array {
            $l = array_values(array_filter($items, function ($it) use ($role) {
                return ($it['role'] ?? '') === $role;
            }));
            usort($l, function ($a, $b) {
                return strcmp((string)($a['room'] ?? '') . $a['inst'] . $a['name'], (string)($b['room'] ?? '') . $b['inst'] . $b['name']);
            });
            return $l;
        };
        $labels = function (array $l, int $n): string {
            return implode(', ', array_map(function ($it) {
                return trim((string)$it['inst']) ?: (string)$it['name'];
            }, array_slice($l, 0, $n)));
        };
        // Wasser-/Rauchmelder: ein Vorschlag fürs ganze Haus (Reaktionen trägt der Nutzer ein)
        $al = $byR('alarm');
        if (count($al) > 0) {
            $row = AUTOMATKatalog::defaults('alarm');
            $row['Name'] = AUTOMATText::t('Water/smoke alarm');
            $row['Room'] = '';
            $fill($row, 'al_mel', array_column($al, 'vid'));
            $add('alarm', '', AUTOMATText::t('Water/smoke detector response'), $row, AUTOMATText::t('%1$s detector(s) found (%2$s) — enter in the dialog what should happen on alarm (light, shutters, valve)', count($al), $labels($al, 4)), array_column($al, 'vid'));
        }
        // Rollläden: ein Vorschlag mit allen
        $rl = $byR('shutter');
        if (count($rl) > 0) {
            $row = AUTOMATKatalog::defaults('rollladen');
            $row['Name'] = AUTOMATText::t('Shutters up in the morning, down in the evening');
            $row['Room'] = '';
            $fill($row, 'rl_out', array_column($rl, 'vid'));
            $add('rollladen', '', AUTOMATText::t('Shutters by time/sun'), $row, AUTOMATText::t('%1$s shutter(s) found (%2$s) — sunrise/sunset, weekdays not before 6:30, weekends 8:00', count($rl), $labels($rl, 4)), array_column($rl, 'vid'));
        }
        // Heizung nach Hausmodus: alle Sollwerte
        $hz = $byR('setpoint');
        if (count($hz) > 0) {
            $row = AUTOMATKatalog::defaults('heizung');
            $row['Name'] = AUTOMATText::t('Lower heating when away');
            $row['Room'] = '';
            $fill($row, 'hz_soll', array_column($hz, 'vid'));
            $add('heizung', '', AUTOMATText::t('Heating by house mode'), $row, AUTOMATText::t('%1$s heating setpoint(s) found (%2$s) — 17 °C in "Away", 15 °C in "Holiday"', count($hz), $labels($hz, 4)), array_column($hz, 'vid'));
        }
        // Lüften: je Raum der erste Feuchte-/CO₂-Messwert
        $seenAir = [];
        foreach ($byR('air') as $it) {
            $room = (string)($it['room'] ?? '');
            if ($room === '' || isset($seenAir[$room])) {
                continue;
            }
            $seenAir[$room] = true;
            $co2 = (bool)preg_match('/co2|co₂|kohlendioxid|ppm/iu', $it['name'] . ' ' . $it['inst'] . ' ' . ($it['suffix'] ?? ''));
            $row = AUTOMATKatalog::defaults('lueften');
            $row['Name'] = AUTOMATText::t('Ventilate %s', $room);
            $row['Room'] = $room;
            $row['lf_var'] = $it['vid'];
            if ($co2) {
                $row['lf_hoch'] = 1200.0;
                $row['lf_ok'] = 800.0;
            }
            $add('lueften', $room, AUTOMATText::t('Ventilation reminder'), $row, ($co2 ? AUTOMATText::t('CO₂ "%s" — reminds from 1200 ppm', trim((string)$it['inst']) ?: $it['name']) : AUTOMATText::t('Humidity "%s" — reminds from 65 %%', trim((string)$it['inst']) ?: $it['name'])), [$it['vid']]);
        }
        // Gehen-Check: alle Fenster-/Türkontakte des Hauses
        $gk = $byR('contact');
        if (count($gk) > 0) {
            $row = AUTOMATKatalog::defaults('gehen');
            $row['Name'] = AUTOMATText::t('Leaving check');
            $row['Room'] = '';
            foreach ($gk as $it) {
                if (self::contactInverted($it)) {
                    $row['gc_invert'] = true; // nur, wenn die Kontakte einheitlich so melden — im Dialog prüfen
                }
            }
            $fill($row, 'gc_kon', array_column($gk, 'vid'));
            $add('gehen', '', AUTOMATText::t('Leaving check'), $row, AUTOMATText::t('%1$s window/door contact(s) found (%2$s) — on change to "Away"/"Holiday" it reports what is still open', count($gk), $labels($gk, 4)), array_column($gk, 'vid'));
        }
        // Melder zurücksetzen: alle Bewegungsmelder in einem Automatismus (Absicherung gegen verlorene „keine Bewegung“)
        $mot = $byR('motion');
        if (count($mot) > 0) {
            $row = AUTOMATKatalog::defaults('ruecksetzen');
            $row['Name'] = AUTOMATText::t('Reset sensors');
            $row['Room'] = '';
            $fill($row, 'mr_mel', array_column($mot, 'vid'));
            $add('ruecksetzen', '', AUTOMATText::t('Reset sensors'), $row, AUTOMATText::t('%1$s motion sensor(s) (%2$s) — resets a sensor that has been "true" for 60 s without a new report (e.g. lost "no motion" report)', count($mot), $labels($mot, 4)), array_column($mot, 'vid'));
        }
        // Türklingel
        foreach (array_slice($byR('bell'), 0, 2) as $it) {
            $row = AUTOMATKatalog::defaults('klingel');
            $row['Name'] = AUTOMATText::t('Doorbell');
            $row['Room'] = (string)($it['room'] ?? '');
            $row['kl_taster'] = $it['vid'];
            $add('klingel', (string)($it['room'] ?? ''), AUTOMATText::t('Doorbell'), $row, AUTOMATText::t('"%s" — notification when it rings, light on in the evening', trim((string)$it['inst']) ?: $it['name']), [$it['vid']]);
        }
        // Ausfall-Wächter: Kühlschrank/Gefriertruhe
        foreach ($byR('cooling') as $it) {
            $row = AUTOMATKatalog::defaults('ausfall');
            $row['Name'] = AUTOMATText::t('Failure %s', trim((string)$it['inst']) ?: $it['name']);
            $row['Room'] = (string)($it['room'] ?? '');
            $row['af_var'] = $it['vid'];
            $add('ausfall', (string)($it['room'] ?? ''), AUTOMATText::t('Failure watchdog'), $row, AUTOMATText::t('"%s" — notifies when the compressor does not start for 2 hours', trim((string)$it['inst']) ?: $it['name']), [$it['vid']]);
        }
        // Sommer-Lüften: nur mit Außentemperatur, je Raum die erste Raumtemperatur (höchstens 6)
        $temps = $byR('temp');
        $outside = null;
        foreach ($temps as $it) {
            if (preg_match('/außen|aussen|outdoor|outside/iu', $it['name'] . ' ' . $it['inst'])) {
                $outside = $it;
                break;
            }
        }
        if ($outside !== null) {
            $seenT = [];
            foreach ($temps as $it) {
                $room = (string)($it['room'] ?? '');
                if ($it === $outside || $room === '' || isset($seenT[$room]) || count($seenT) >= 6 || preg_match('/außen|aussen|outdoor|outside/iu', $it['name'] . ' ' . $it['inst'])) {
                    continue;
                }
                $seenT[$room] = true;
                $row = AUTOMATKatalog::defaults('sommer');
                $row['Name'] = AUTOMATText::t('Summer ventilation %s', $room);
                $row['Room'] = $room;
                $row['sl_innen'] = $it['vid'];
                $row['sl_aussen'] = $outside['vid'];
                $add('sommer', $room, AUTOMATText::t('Summer ventilation'), $row, AUTOMATText::t('Inside "%1$s", outside "%2$s" — "close the windows" or "ventilate now" from 23 °C inside', trim((string)$it['inst']) ?: $it['name'], trim((string)$outside['inst']) ?: $outside['name']), [$it['vid'], $outside['vid']]);
            }
        }
        // Gewohnheiten aus dem Archiv: Licht geht werktags meist zur selben Zeit aus → Zeitschaltuhr „nur ausschalten“
        $lightBy = [];
        foreach ($items as $it) {
            $lightBy[(int)$it['vid']] = $it;
        }
        foreach ($habits as $vid => $h) {
            $it = $lightBy[(int)$vid] ?? null;
            if ($it === null) {
                continue;
            }
            $m = (int)$h['min'] % 1440;
            $row = AUTOMATKatalog::defaults('zeitschalt');
            $label = trim((string)$it['inst']) ?: (string)$it['name'];
            $row['Name'] = AUTOMATText::t('%s off (habit)', $label);
            $row['Room'] = (string)($it['room'] ?? '');
            $row['zs_out1'] = (int)$vid;
            $row['zs_modus'] = 1;
            $row['zs_aus'] = AUTOMATKatalog::time(intdiv($m, 60), $m % 60);
            $row['zs_t6'] = false;
            $row['zs_t7'] = false;
            $row['zs_feiertag'] = 2;
            $add('zeitschalt', (string)($it['room'] ?? ''), AUTOMATText::t('Habit: timer switch'), $row, AUTOMATText::t('"%1$s" went off on %2$s of %3$s weekday evenings at %4$s (±30 min) — switch off in case it is forgotten', $label, $h['hits'], $h['days'], sprintf('%02d:%02d', intdiv($m, 60), $m % 60)), [(int)$vid]);
        }

        // Taster: je Taster ein Vorschlag (Aktionen trägt der Nutzer ein)
        foreach (array_slice($byR('button'), 0, 6) as $it) {
            $row = AUTOMATKatalog::defaults('taster');
            $row['Name'] = AUTOMATText::t('Button %s', trim((string)$it['inst']) ?: $it['name']);
            $row['Room'] = (string)($it['room'] ?? '');
            $row['tg_in'] = $it['vid'];
            $add('taster', (string)($it['room'] ?? ''), AUTOMATText::t('Button assignment'), $row, AUTOMATText::t('"%s" — enter in the dialog what short/double/long press should do', trim((string)$it['inst']) ?: $it['name']), [$it['vid']]);
        }

        // Gerät fertig: ein Vorschlag je Gerät — hat ein Gerät mehrere Leistungsvariablen
        // (z. B. „Wirkleistung“ und „Wirkleistung Original“), gewinnt die ohne „Original“.
        $powerBest = [];
        foreach ($items as $it) {
            if (($it['role'] ?? '') !== 'power') {
                continue;
            }
            $label = mb_strtolower(trim((string)($it['inst'] ?? '')) ?: (string)($it['name'] ?? ''));
            $score = (stripos((string)$it['name'], 'original') === false ? 2 : 0) + (empty($it['action']) ? 1 : 0);
            if (!isset($powerBest[$label]) || $score > $powerBest[$label][0]) {
                $powerBest[$label] = [$score, $it['vid']];
            }
        }
        foreach ($items as $it) {
            if (($it['role'] ?? '') === 'power') {
                $pl = mb_strtolower(trim((string)($it['inst'] ?? '')) ?: (string)($it['name'] ?? ''));
                if (($powerBest[$pl][1] ?? null) !== $it['vid']) {
                    continue;
                }
            }
            $text = ($it['name'] ?? '') . ' ' . ($it['inst'] ?? '');
            $label = trim((string)($it['inst'] ?? '')) ?: (string)($it['name'] ?? '');
            if (($it['role'] ?? '') === 'power') {
                $row = AUTOMATKatalog::defaults('fertig');
                $row['Name'] = $label;
                $row['Room'] = (string)($it['room'] ?? '');
                $row['gf_var'] = $it['vid'];
                $add('fertig', (string)($it['room'] ?? ''), AUTOMATText::t('Notify when %s is finished', $label), $row, AUTOMATText::t('Power measurement of a household appliance detected'), [$it['vid']]);
            }
            if (($it['role'] ?? '') === 'light' && preg_match(self::OUTDOOR, $text)) {
                $row = AUTOMATKatalog::defaults('daemmerung');
                $row['Name'] = AUTOMATText::t('Dusk %s', $label);
                $row['Room'] = (string)($it['room'] ?? '');
                $row['dz_out1'] = $it['vid'];
                $add('daemmerung', (string)($it['room'] ?? ''), AUTOMATText::t('Dusk switching %s', $label), $row, AUTOMATText::t('Outdoor lighting detected — on at sunset, off at 23:00'), [$it['vid']]);
            }
            if (($it['role'] ?? '') === 'contact' && preg_match('/tür|tuer|door|\btor\b|garage/iu', $text)) {
                $row = AUTOMATKatalog::defaults('waechter');
                $row['Name'] = AUTOMATText::t('%s open', $label);
                $row['Room'] = (string)($it['room'] ?? '');
                $row['wa_var'] = $it['vid'];
                $row['wa_op'] = self::contactInverted($it) ? 5 : 4;
                $add('waechter', (string)($it['room'] ?? ''), AUTOMATText::t('%s: notify when open for too long', $label), $row, AUTOMATText::t('Door/gate contact detected — notification after 15 min open'), [$it['vid']]);
            }
        }
        return array_values($out);
    }

    /** Wie viele Geräte je Rolle gefunden wurden (für den Katalog). */
    public static function roleCounts(array $items): array
    {
        $c = array_fill_keys(array_keys(AUTOMATKatalog::roles()), 0);
        foreach ($items as $it) {
            $r = (string)($it['role'] ?? '');
            if (isset($c[$r])) {
                $c[$r]++;
            }
        }
        return $c;
    }
}
