<?php

declare(strict_types=1);

require_once __DIR__ . '/AUTOMATKatalog.php';

// ===========================================================================
// AUTOMATLogik — die Entscheidungen aller Automatismen, OHNE Symcon.
//
// Jeder Aufruf von step() bekommt eine Listenzeile (Einstellungen), den
// bisherigen Zustand, ein Ereignis und einen Schnappschuss der Umgebung
// (Uhrzeit, Hausmodus, Sperren, aktuelle Variablenwerte) und liefert zurück:
//   st     — neuer Zustand (wird vom Modul gespeichert)
//   cmd    — Schaltbefehle [[VariablenID, Wert], …]
//   msg    — Meldungen [[Titel, Text], …]
//   log    — Entscheidungsgrund für das Protokoll (null = nichts passiert)
//   status — Kurztext für Statusvariable/Kachel
//   due    — nächster Zeitpunkt, zu dem ein „tick“ nötig ist (null = keiner)
//
// Ereignisse ($ev['k']): 'trigger' (Eingang hat sich gemeldet), 'output'
// (geschaltete Variable hat sich geändert; 'own' = Echo des eigenen Befehls),
// 'tick' (Zeit verstrichen), 'flag' (Sperre/Dauerlicht/Hausmodus/Bedingung).
//
// Sperren: „hart“ = Sperre des Automatismus oder globale Pause — dann
// handelt er gar nicht. „weich“ = Hausmodus nicht freigegeben oder Bedingung
// nicht erfüllt — dann schaltet er nichts NEU ein bzw. meldet nichts, lässt
// laufende Zeiten aber zu Ende laufen (das Licht bleibt nicht ewig an).
// ===========================================================================

class AUTOMATLogik
{
    /**
     * Aufräumen, wenn ein Automatismus abgeschaltet, gelöscht, auf Probelauf gestellt oder umgebaut wird: was er
     * gerade festhält, zurückgeben (abgesenkte Sollwerte). Lichter bleiben, wie sie sind. Liefert [Befehle, Text].
     */
    public static function cleanup(string $type, array $st, array $ctx): array
    {
        $cmd = [];
        $vals = [];
        if ($type === 'fenster' && in_array($st['ph'] ?? '', ['ab'], true)) {
            foreach ((array)($st['saved'] ?? []) as $vid => $v) {
                $cmd[] = [(int)$vid, (float)$v];
                $vals[] = self::fmtNum((float)$v) . ' °C';
            }
        }
        if ($type === 'heizung') {
            foreach ((array)($st['saved'] ?? []) as $k => $v) {
                $cur = array_key_exists((int)$k, $ctx['val'] ?? []) ? (float)$ctx['val'][(int)$k] : null;
                $set = $st['set'][$k] ?? null;
                if ($set !== null && ($cur === null || abs($cur - (float)$set) <= 0.05)) {
                    $cmd[] = [(int)$k, (float)$v];
                    $vals[] = self::fmtNum((float)$v) . ' °C';
                }
            }
        }
        return [$cmd, count($cmd) > 0 ? 'Sollwert zurück auf ' . implode(', ', array_unique($vals)) . '.' : ''];
    }

    public static function step(array $row, array $st, array $ev, array $ctx): array
    {
        $type = (string)($row['Type'] ?? '');
        // 'end' = Zeitpunkt des nächsten sichtbaren Ereignisses (Licht aus, Absenkung, Meldung) für die Restzeit-Anzeige.
        // 'lost' = Melder, bei denen der Rückfall eingreifen musste („aus“-Meldung verloren) — fürs Funkqualitäts-Protokoll.
        $res = ['st' => $st, 'cmd' => [], 'msg' => [], 'log' => null, 'status' => '', 'due' => null, 'end' => null, 'lost' => []];
        switch ($type) {
            case 'zeitlicht':
                return self::zeitlicht($row, $st, $ev, $ctx, $res);
            case 'bewegung':
                return self::bewegung($row, $st, $ev, $ctx, $res);
            case 'daemmerung':
                return self::daemmerung($row, $st, $ev, $ctx, $res);
            case 'fenster':
                return self::fenster($row, $st, $ev, $ctx, $res);
            case 'waechter':
                return self::waechter($row, $st, $ev, $ctx, $res);
            case 'fertig':
                return self::fertig($row, $st, $ev, $ctx, $res);
            case 'anwesenheit':
                return self::anwesenheit($row, $st, $ev, $ctx, $res);
            case 'szene':
                return self::szene($row, $st, $ev, $ctx, $res);
            case 'alarm':
                return self::alarm($row, $st, $ev, $ctx, $res);
            case 'rollladen':
                return self::rollladen($row, $st, $ev, $ctx, $res);
            case 'standby':
                return self::standby($row, $st, $ev, $ctx, $res);
            case 'lueften':
                return self::lueften($row, $st, $ev, $ctx, $res);
            case 'heizung':
                return self::heizung($row, $st, $ev, $ctx, $res);
            case 'taster':
                return self::taster($row, $st, $ev, $ctx, $res);
            case 'wecklicht':
                return self::wecklicht($row, $st, $ev, $ctx, $res);
            case 'gehen':
                return self::gehen($row, $st, $ev, $ctx, $res);
            case 'klingel':
                return self::klingel($row, $st, $ev, $ctx, $res);
            case 'sommer':
                return self::sommer($row, $st, $ev, $ctx, $res);
            case 'ausfall':
                return self::ausfall($row, $st, $ev, $ctx, $res);
            case 'zeitschalt':
                return self::zeitschalt($row, $st, $ev, $ctx, $res);
        }
        $res['status'] = '❔ Unbekannter Typ';
        return $res;
    }

    // ------------------------------------------------------------------
    // Gemeinsames
    // ------------------------------------------------------------------

    /** Harte Sperre: Text oder '' (frei). */
    public static function hardBlock(array $ctx): string
    {
        if (!empty($ctx['paused'])) {
            return 'alle Automatismen pausiert';
        }
        if (!empty($ctx['locked'])) {
            return 'gesperrt';
        }
        return '';
    }

    /** Weiche Sperre: Text oder '' (frei). */
    public static function softBlock(array $row, array $ctx): string
    {
        $mode = (int)($ctx['mode'] ?? 0);
        if (array_key_exists('m' . $mode, $row) && !$row['m' . $mode]) {
            return 'im Hausmodus „' . (AUTOMATKatalog::MODES[$mode] ?? $mode) . '“ nicht aktiv';
        }
        if (isset($ctx['condOk']) && !$ctx['condOk']) {
            return 'Bedingung nicht erfüllt';
        }
        return '';
    }

    public static function isTrue($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return $v > 0;
        }
        if (is_string($v)) {
            $s = mb_strtolower(trim($v));
            if (is_numeric($s)) {
                return (float)$s > 0;
            }
            return in_array($s, ['true', 'wahr', 'an', 'ein', 'on', 'ja', 'offen', 'open', 'home', 'zuhause', 'zu hause', 'anwesend', 'present'], true);
        }
        return false;
    }

    /** Einschaltwert: Dimmer (max > 0) skaliert auf Prozent, Schalter true. */
    public static function onValue(int $vid, int $pct, array $ctx)
    {
        $max = (float)($ctx['dim'][$vid] ?? 0);
        if ($max <= 0) {
            return true;
        }
        $pct = max(1, min(100, $pct));
        if ($max <= 1.0) {
            return round($pct / 100 * $max, 2);
        }
        return max(1, (int)round($pct * $max / 100));
    }

    public static function offValue(int $vid, array $ctx)
    {
        $max = (float)($ctx['dim'][$vid] ?? 0);
        if ($max <= 0) {
            return false;
        }
        return $max <= 1.0 ? 0.0 : 0;
    }

    private static function lightPct(array $row, array $ctx, string $p): int
    {
        $pct = (int)($row[$p . '_wert'] ?? 100);
        $night = (int)($row[$p . '_nachtwert'] ?? 0);
        if ((int)($ctx['mode'] ?? 0) === AUTOMATKatalog::MODE_NIGHT && $night > 0) {
            $pct = $night;
        }
        return $pct;
    }

    private static function onCmds(array $outs, array $row, array $ctx, string $p): array
    {
        $pct = self::lightPct($row, $ctx, $p);
        $c = [];
        foreach ($outs as $vid) {
            $c[] = [$vid, self::onValue($vid, $pct, $ctx)];
        }
        return $c;
    }

    private static function offCmds(array $outs, array $ctx): array
    {
        $c = [];
        foreach ($outs as $vid) {
            $c[] = [$vid, self::offValue($vid, $ctx)];
        }
        return $c;
    }

    /**
     * Vorwarnung: Dimmer auf halbe Helligkeit, Schalter kurz aus (das
     * Wiedereinschalten folgt eine Sekunde später, Phase 'flash').
     */
    private static function warnCmds(array $outs, array $row, array $ctx, string $p, bool &$hasSwitch): array
    {
        $pct = self::lightPct($row, $ctx, $p);
        $c = [];
        $hasSwitch = false;
        foreach ($outs as $vid) {
            if ((float)($ctx['dim'][$vid] ?? 0) > 0) {
                $c[] = [$vid, self::onValue($vid, max(1, (int)floor($pct / 2)), $ctx)];
            } else {
                $c[] = [$vid, false];
                $hasSwitch = true;
            }
        }
        return $c;
    }

    private static function anyOn(array $outs, array $ctx): bool
    {
        foreach ($outs as $vid) {
            if (self::isTrue($ctx['val'][$vid] ?? false)) {
                return true;
            }
        }
        return false;
    }

    public static function fmtClock(int $ts, int $now): string
    {
        $rest = max(0, $ts - $now);
        $txt = $rest >= 3600 ? floor($rest / 3600) . ' h ' . sprintf('%02d', floor(($rest % 3600) / 60)) . ' min'
            : ($rest >= 60 ? floor($rest / 60) . ':' . sprintf('%02d', $rest % 60) . ' min' : $rest . ' s');
        $day = date('Y-m-d', $ts) === date('Y-m-d', $now) ? '' : date('d.m.Y ', $ts);
        // Sekunden nur bei kurzen Zeiten (Treppenlicht); bei Stunden Abstand stören sie („schließt um 18:41:36“)
        return $day . date($rest >= 3600 ? 'H:i' : 'H:i:s', $ts) . ' Uhr (in ' . $txt . ')';
    }

    /** Restzeit kurz: „45 s", „1:35 min", „2 h 05 min"; '' wenn vorbei. */
    public static function fmtRest(int $end, int $now): string
    {
        $r = $end - $now;
        if ($r <= 0) {
            return '';
        }
        if ($r >= 3600) {
            return intdiv($r, 3600) . ' h ' . sprintf('%02d', intdiv($r % 3600, 60)) . ' min';
        }
        if ($r >= 60) {
            return intdiv($r, 60) . ':' . sprintf('%02d', $r % 60) . ' min';
        }
        return $r . ' s';
    }

    public static function fmtNum($v): string
    {
        if (is_bool($v)) {
            return $v ? 'an' : 'aus';
        }
        if (is_float($v)) {
            return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
        }
        return (string)$v;
    }

    private static function hardStatus(string $why): string
    {
        return '⏸ ' . ucfirst($why) . ' — schaltet nichts';
    }

    // ------------------------------------------------------------------
    // Zeitlicht (Treppenlicht)
    // ------------------------------------------------------------------

    private static function zeitlicht(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $outs = AUTOMATKatalog::vars($row, 'zl_out');
        $trg = AUTOMATKatalog::vars($row, 'zl_trg');
        $dauer = max(1, (int)($row['zl_dauer'] ?? 180));
        $st += ['ph' => 'aus', 'until' => 0, 'fl' => 0];
        $res = self::lightCommon($row, $st, $ev, $ctx, $res, $outs, 'zl', $dauer);
        if ($res['done'] ?? false) {
            unset($res['done']);
            return $res;
        }
        $st = $res['st'];
        $k = $ev['k'] ?? 'tick';
        $vid = (int)($ev['vid'] ?? 0);

        $start = function (string $why) use (&$st, &$res, $row, $ctx, $outs, $now, $dauer) {
            $soft = self::softBlock($row, $ctx);
            if ($st['ph'] === 'aus') {
                if ($soft !== '') {
                    $res['log'] = $why . ' — nicht eingeschaltet: ' . $soft . '.';
                    return;
                }
                $res['cmd'] = array_merge($res['cmd'], self::onCmds($outs, $row, $ctx, 'zl'));
                $st['ph'] = 'an';
                $st['until'] = $now + $dauer;
                $res['log'] = $why . ' — Licht an, aus um ' . date('H:i:s', $st['until']) . ' Uhr.';
                return;
            }
            if (empty($row['zl_nachtrigger'])) {
                return;
            }
            if ($st['ph'] === 'warn' || $st['ph'] === 'flash') {
                $res['cmd'] = array_merge($res['cmd'], self::onCmds($outs, $row, $ctx, 'zl'));
            }
            $st['ph'] = 'an';
            $st['until'] = $now + $dauer;
            $res['log'] = $why . ' — Zeit verlängert bis ' . date('H:i:s', $st['until']) . ' Uhr.';
        };

        if ($k === 'trigger' && in_array($vid, $trg, true) && self::isTrue($ev['v'] ?? false)) {
            $start('Taster/Auslöser betätigt');
        } elseif ($k === 'output' && empty($ev['own']) && in_array($vid, $outs, true)) {
            if (self::isTrue($ev['v'] ?? false)) {
                if ($st['ph'] === 'aus') {
                    // Von Hand (oder von außen) eingeschaltet: Treppenlicht-Prinzip — die Zeit läuft trotzdem.
                    $st['ph'] = 'an';
                    $st['until'] = $now + $dauer;
                    $res['log'] = 'Licht wurde eingeschaltet — geht um ' . date('H:i:s', $st['until']) . ' Uhr wieder aus.';
                }
            } elseif (!self::anyOn($outs, $ctx) && in_array($st['ph'], ['an', 'warn', 'flash'], true)) {
                $st['ph'] = 'aus';
                $st['until'] = 0;
                $res['log'] = !empty($ev['central']) ? 'Zentral ausgeschaltet (' . (string)$ev['central'] . ') — Zeit abgebrochen.' : 'Licht wurde von Hand ausgeschaltet — Zeit abgebrochen.';
            }
        }
        $res['st'] = $st;
        // Vorwarnung höchstens die halbe Leuchtdauer — sonst ginge das Licht gleich beim Einschalten wieder aus
        return self::lightTimers($row, $res, $ctx, $outs, 'zl', min((int)($row['zl_vorwarn'] ?? 0), intdiv($dauer, 2)));
    }

    /**
     * Gemeinsamer Teil Zeitlicht/Bewegungslicht: harte Sperre und Dauerlicht.
     * Setzt $res['done'] = true, wenn die Entscheidung damit gefallen ist.
     */
    private static function lightCommon(array $row, array $st, array $ev, array $ctx, array $res, array $outs, string $p, int $dauer, bool $active = false): array
    {
        $now = (int)$ctx['now'];
        $hard = self::hardBlock($ctx);
        if ($hard !== '') {
            if ($st['ph'] === 'flash') {
                // Sperre genau in der Vorwarn-Sekunde: Schalter waren kurz aus — wieder einschalten, „bleibt wie es ist“
                foreach (self::onCmds($outs, $row, $ctx, $p) as $c) {
                    if ((float)($ctx['dim'][$c[0]] ?? 0) <= 0) {
                        $res['cmd'][] = $c;
                    }
                }
            }
            if ($st['ph'] !== 'aus' && $st['ph'] !== 'gesperrt') {
                $res['log'] = 'Automatik ' . $hard . ' — laufende Zeit abgebrochen, Licht bleibt wie es ist.';
            }
            $st['ph'] = 'gesperrt';
            $st['until'] = 0;
            $res['st'] = $st;
            $res['status'] = self::hardStatus($hard);
            $res['done'] = true;
            return $res;
        }
        if ($st['ph'] === 'gesperrt') {
            $st['ph'] = self::anyOn($outs, $ctx) ? 'an' : 'aus';
            // Meldet ein Melder noch Bewegung, läuft der Nachlauf erst danach (sonst Licht aus bei Bewegung)
            $st['until'] = $st['ph'] === 'an' && !$active ? $now + $dauer : 0;
            $res['log'] = 'Sperre aufgehoben' . ($st['ph'] === 'an' ? ($st['until'] > 0 ? ' — Licht ist an, geht um ' . date('H:i:s', $st['until']) . ' Uhr aus.' : ' — Licht ist an, Bewegung erkannt.') : '.');
        }
        if (!empty($ctx['permanent'])) {
            if ($st['ph'] !== 'dauer') {
                $res['cmd'] = self::onCmds($outs, $row, $ctx, $p);
                $res['log'] = 'Dauerlicht eingeschaltet.';
            }
            $st['ph'] = 'dauer';
            $st['until'] = 0;
            $res['st'] = $st;
            $res['status'] = '🔆 Dauerlicht';
            $res['done'] = true;
            return $res;
        }
        if ($st['ph'] === 'dauer') {
            $st['ph'] = 'an';
            $st['until'] = $active ? 0 : $now + $dauer;
            $res['log'] = 'Dauerlicht beendet — ' . ($active ? 'Bewegung erkannt, Nachlauf startet danach.' : 'Licht geht um ' . date('H:i:s', $st['until']) . ' Uhr aus.');
        }
        $res['st'] = $st;
        return $res;
    }

    /** Vorwarnung und Ausschalten nach Ablauf; setzt status und due. */
    private static function lightTimers(array $row, array $res, array $ctx, array $outs, string $p, int $vorwarn): array
    {
        $now = (int)$ctx['now'];
        $st = $res['st'];
        if ($st['ph'] === 'flash' && $now >= (int)$st['fl']) {
            // Schalter waren zur Vorwarnung kurz aus — wieder ein.
            foreach (self::onCmds($outs, $row, $ctx, $p) as $c) {
                if ((float)($ctx['dim'][$c[0]] ?? 0) <= 0) {
                    $res['cmd'][] = $c;
                }
            }
            $st['ph'] = 'warn';
        }
        if ($st['ph'] === 'an' && (int)$st['until'] > 0 && $vorwarn > 0 && $now >= (int)$st['until'] - $vorwarn && $now < (int)$st['until']) {
            $hasSwitch = false;
            $res['cmd'] = array_merge($res['cmd'], self::warnCmds($outs, $row, $ctx, $p, $hasSwitch));
            $st['ph'] = $hasSwitch ? 'flash' : 'warn';
            $st['fl'] = $now + 1;
            $res['log'] = $res['log'] ?? 'Vorwarnung — Licht geht um ' . date('H:i:s', (int)$st['until']) . ' Uhr aus.';
        }
        if (in_array($st['ph'], ['an', 'warn', 'flash'], true) && (int)$st['until'] > 0 && $now >= (int)$st['until']) {
            $res['cmd'] = array_merge($res['cmd'], self::offCmds($outs, $ctx));
            $st['ph'] = 'aus';
            $st['until'] = 0;
            $res['log'] = 'Zeit abgelaufen — Licht aus.';
        }
        $res['st'] = $st;
        switch ($st['ph']) {
            case 'an':
                if ((int)$st['until'] > 0) {
                    $res['due'] = $vorwarn > 0 && (int)$st['until'] - $vorwarn > $now ? (int)$st['until'] - $vorwarn : (int)$st['until'];
                    $res['status'] = '💡 An — aus um ' . self::fmtClock((int)$st['until'], $now);
                    $res['end'] = (int)$st['until'];
                } else {
                    $res['status'] = '💡 An — Bewegung erkannt, Nachlauf startet danach';
                }
                break;
            case 'flash':
                $res['due'] = (int)$st['fl'];
                $res['status'] = '⚠️ Vorwarnung — aus um ' . self::fmtClock((int)$st['until'], $now);
                $res['end'] = (int)$st['until'];
                break;
            case 'warn':
                $res['due'] = (int)$st['until'];
                $res['status'] = '⚠️ Vorwarnung — aus um ' . self::fmtClock((int)$st['until'], $now);
                $res['end'] = (int)$st['until'];
                break;
            case 'hand':
                $res['due'] = (int)($st['hu'] ?? 0) ?: null;
                $res['status'] = '✋ Von Hand ausgeschaltet — Automatik pausiert bis ' . date('H:i', (int)($st['hu'] ?? $now)) . ' Uhr';
                $res['end'] = (int)($st['hu'] ?? 0) ?: null;
                break;
            default:
                $soft = self::softBlock($row, $ctx);
                $res['status'] = $soft !== '' ? '🌙 Bereit, aber ' . $soft : '⚪ Aus — bereit';
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Bewegungslicht
    // ------------------------------------------------------------------

    /** Zeitfenster nach einem automatischen Ausschalten, in dem Wieder-Einschalten als Fehlabschaltung gilt. */
    public const FALSE_OFF_WINDOW = 180;
    public const LEARN_FACTOR_MAX = 4;

    /** Aktive „weitere Anzeichen für Anwesenheit“ (Namen); Zahlen zählen über der Schwelle, sonst „an“. */
    private static function activeHints(array $row, array $ctx): array
    {
        $min = (float)($row['bl_hintmin'] ?? 10);
        $out = [];
        foreach (AUTOMATKatalog::vars($row, 'bl_hint') as $h) {
            if (!array_key_exists($h, $ctx['val'] ?? [])) {
                continue;
            }
            $v = $ctx['val'][$h];
            $active = is_bool($v) ? $v : ((is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) ? (float)$v > $min : self::isTrue($v));
            if ($active) {
                $out[] = (string)($ctx['names'][$h] ?? ('#' . $h));
            }
        }
        return $out;
    }

    /** Gelernter Nachlauf: Grundwert, bei Fehlabschaltungen verlängert, höchstens bl_nachlaufmax. */
    public static function effectiveNachlauf(array $row, array $st): int
    {
        $base = max(1, (int)($row['bl_nachlauf'] ?? 120));
        if (!($row['bl_lernen'] ?? true)) {
            return $base;
        }
        return min(self::learnCap($row), max($base, (int)($st['learnN'] ?? 0)));
    }

    /**
     * Obergrenze des gelernten Nachlaufs: höchstens das Vierfache des eingestellten Werts (und nie über „höchstens“).
     * Ohne diese Grenze schaukelte sich ein Flur mit 30 s in zwei Tagen auf 13 min hoch (Dietmar 16.10.2026).
     */
    public static function learnCap(array $row): int
    {
        $base = max(1, (int)($row['bl_nachlauf'] ?? 120));
        return max($base, min(max($base, (int)($row['bl_nachlaufmax'] ?? 1800)), $base * self::LEARN_FACTOR_MAX));
    }

    /**
     * Wie lange nach dem automatischen Aus ein Wieder-An als Fehlabschaltung zählt: so lange wie der eingestellte
     * Nachlauf, mindestens 30 s, höchstens FALSE_OFF_WINDOW. Wer im Flur nach zwei Minuten zurückkommt, ist ein neuer
     * Gang, keine Fehlabschaltung.
     */
    public static function falseOffWindow(array $row): int
    {
        return max(30, min(self::FALSE_OFF_WINDOW, (int)($row['bl_nachlauf'] ?? 120)));
    }

    private static function bewegung(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $mel = AUTOMATKatalog::vars($row, 'bl_mel');
        $hints = AUTOMATKatalog::vars($row, 'bl_hint');
        $outs = AUTOMATKatalog::vars($row, 'bl_out');
        $base = max(1, (int)($row['bl_nachlauf'] ?? 120));
        $learnOn = (bool)($row['bl_lernen'] ?? true);
        $maxN = self::learnCap($row);
        $window = self::falseOffWindow($row);
        $impuls = !empty($row['bl_impuls']);
        $handPause = max(0, (int)($row['bl_handpause'] ?? 0)) * 60;
        $st += ['ph' => 'aus', 'until' => 0, 'fl' => 0, 'hu' => 0, 'learnN' => 0, 'offAt' => 0, 'fo' => [], 'lastDecay' => 0];
        // Eingestellten Nachlauf geändert: Gelerntes verwerfen — der neue Wert gilt ab sofort.
        if ((int)($st['learnBase'] ?? 0) !== $base) {
            $st['learnN'] = 0;
            $st['fo'] = [];
            $st['learnBase'] = $base;
        }
        // Gelernten Nachlauf langsam zurückführen: je Tag ohne Fehlabschaltung 10 % Richtung Grundwert.
        if ($learnOn && (int)$st['learnN'] > $base) {
            if ((int)$st['lastDecay'] === 0) {
                $st['lastDecay'] = $now;
            } elseif ($now >= strtotime('+1 day', (int)$st['lastDecay'])) {
                $recent = array_filter((array)$st['fo'], function ($t) use ($st) {
                    return (int)$t >= (int)$st['lastDecay'];
                });
                if (count($recent) === 0) {
                    $st['learnN'] = max($base, (int)round((int)$st['learnN'] * 0.9));
                }
                $st['lastDecay'] = $now;
            }
        }
        $st['fo'] = array_values(array_filter((array)$st['fo'], function ($t) use ($now) {
            return (int)$t >= strtotime('-7 days', $now);
        }));
        $nach = self::effectiveNachlauf($row, $st);
        if (!isset($st['act']) || !is_array($st['act'])) {
            // Erster Lauf: Melderzustand aus den aktuellen Werten übernehmen.
            $st['act'] = [];
            foreach ($mel as $m) {
                $st['act'][$m] = !$impuls && self::isTrue($ctx['val'][$m] ?? false);
            }
        }
        if (!isset($st['since']) || !is_array($st['since'])) {
            $st['since'] = [];
        }
        // Melderzustand auch während Sperre/Dauerlicht nachführen — sonst geht ein „aus“ in der Sperre verloren und
        // der Rückfall meldet danach einen Funkverlust, den es nie gab (Prüfbericht 17.10.2026).
        if (($ev['k'] ?? '') === 'trigger' && !$impuls && in_array((int)($ev['vid'] ?? 0), $mel, true)) {
            $mv = (int)$ev['vid'];
            $st['act'][$mv] = self::isTrue($ev['v'] ?? false);
            if ($st['act'][$mv]) {
                $st['since'][$mv] = $now;
            } else {
                unset($st['since'][$mv]);
            }
        }
        foreach ($mel as $m) {
            if (!empty($st['act'][$m]) && !isset($st['since'][$m])) {
                $st['since'][$m] = $now;
            }
        }
        $activeNow = !$impuls && (in_array(true, (array)$st['act'], true) || count(self::activeHints($row, $ctx)) > 0);
        $res = self::lightCommon($row, $st, $ev, $ctx, $res, $outs, 'bl', $nach, $activeNow);
        if ($res['done'] ?? false) {
            unset($res['done']);
            return $res;
        }
        $st = $res['st'];
        $k = $ev['k'] ?? 'tick';
        $vid = (int)($ev['vid'] ?? 0);
        $hintNames = self::activeHints($row, $ctx);

        // Wieder an kurz nach automatischem Aus = Fehlabschaltung → Nachlauf verlängern.
        $noteReOn = function (string $how) use (&$st, &$res, $now, $learnOn, $maxN, $base, &$nach, $window) {
            if ((int)$st['offAt'] <= 0 || $now - (int)$st['offAt'] > $window) {
                $st['offAt'] = 0;
                return;
            }
            $gap = $now - (int)$st['offAt'];
            $st['offAt'] = 0;
            $st['fo'][] = $now;
            $txt = 'Fehlabschaltung: Licht ' . $how . ' ' . AUTOMATKatalog::dur($gap) . ' nach dem automatischen Ausschalten wieder an.';
            if ($learnOn) {
                $old = self::effectiveNachlauf(['bl_nachlauf' => $base, 'bl_nachlaufmax' => $maxN, 'bl_lernen' => true], $st);
                // Höchstwert = $maxN (schon auf das Vierfache des Grundwerts begrenzt)
                $st['learnN'] = min($maxN, max($base, (int)round($old * 1.5)));
                $st['lastDecay'] = $now;
                $nach = $st['learnN'];
                $txt .= $st['learnN'] > $old ? ' Nachlauf gelernt: ' . AUTOMATKatalog::dur($old) . ' → ' . AUTOMATKatalog::dur($st['learnN']) . '.' : ' Nachlauf ist schon beim Höchstwert ' . AUTOMATKatalog::dur($maxN) . '.';
            }
            $res['log'] = $res['log'] !== null ? $res['log'] . ' ' . $txt : $txt;
        };

        if ($k === 'trigger' && in_array($vid, $mel, true)) {
            $active = self::isTrue($ev['v'] ?? false);
            if (!$impuls) {
                $st['act'][$vid] = $active;
                if ($active) {
                    $st['since'][$vid] = $now; // jede neue „Bewegung“ startet die Rückfallzeit neu
                } else {
                    unset($st['since'][$vid]);
                }
            }
            $anyActive = $impuls ? $active : in_array(true, $st['act'], true);
            if ($anyActive) {
                if ($st['ph'] === 'hand' && $now < (int)$st['hu']) {
                    // Handbedienung hat Vorrang — still bleiben.
                } else {
                    if ($st['ph'] === 'hand') {
                        $st['ph'] = 'aus';
                    }
                    if ($st['ph'] === 'aus') {
                        $soft = self::softBlock($row, $ctx);
                        $luxVid = (int)($row['bl_lux'] ?? 0);
                        $luxVal = $luxVid > 0 && array_key_exists($luxVid, $ctx['val'] ?? []) ? (float)$ctx['val'][$luxVid] : null;
                        $luxMax = (float)($row['bl_luxschwelle'] ?? 30);
                        if ($soft !== '') {
                            $res['log'] = 'Bewegung — nicht eingeschaltet: ' . $soft . '.';
                        } elseif ($luxVal !== null && $luxVal >= $luxMax && ((int)$st['offAt'] <= 0 || $now - (int)$st['offAt'] > $window)) {
                            // Helligkeit zählt nur in den ersten Minuten nach dem eigenen Ausschalten nicht — vorher galt
                            // das ab dem letzten automatischen Aus für immer (Licht ging mittags bei 70–540 lx an, Dietmar 16.10.2026).
                            $res['log'] = 'Bewegung — nicht eingeschaltet: hell genug (' . self::fmtNum($luxVal) . ' lx ≥ ' . self::fmtNum($luxMax) . ' lx).';
                        } elseif (self::anyOn($outs, $ctx)) {
                            $res['log'] = 'Bewegung — Licht war schon an (von Hand oder anderer Steuerung), wird nicht übernommen.';
                        } else {
                            // Kurz nach dem eigenen Ausschalten zählt die Helligkeit nicht (Restlicht/Nachleuchten): sonst bliebe es nach einer Fehlabschaltung dunkel.
                            $res['cmd'] = self::onCmds($outs, $row, $ctx, 'bl');
                            $st['ph'] = 'an';
                            $res['log'] = 'Bewegung — Licht an.';
                            $noteReOn('durch Bewegung');
                            $st['until'] = $impuls ? $now + $nach : 0;
                        }
                    } else {
                        if ($st['ph'] === 'warn' || $st['ph'] === 'flash') {
                            $res['cmd'] = self::onCmds($outs, $row, $ctx, 'bl');
                            $res['log'] = 'Bewegung während der Vorwarnung — Licht bleibt an.';
                        }
                        $st['ph'] = 'an';
                        $st['until'] = $impuls ? $now + $nach : 0;
                    }
                }
            } elseif (in_array($st['ph'], ['an', 'warn', 'flash'], true) && (int)$st['until'] === 0 && count($hintNames) === 0) {
                $st['until'] = $now + $nach;
                $res['log'] = 'Keine Bewegung mehr — Licht geht um ' . date('H:i:s', $st['until']) . ' Uhr aus'
                    . ($nach > $base ? ' (gelernter Nachlauf ' . AUTOMATKatalog::dur($nach) . ', eingestellt ' . AUTOMATKatalog::dur($base) . ').' : '.');
            }
        } elseif ($k === 'output' && empty($ev['own']) && in_array($vid, $outs, true)) {
            if (self::isTrue($ev['v'] ?? false)) {
                if ($st['ph'] === 'aus' || $st['ph'] === 'hand') {
                    $st['ph'] = 'an';
                    $noteReOn('von Hand');
                    // Meldet ein Melder noch Bewegung, startet der Nachlauf erst mit „keine Bewegung“
                    $st['until'] = $activeNow ? 0 : $now + max($nach, $handPause);
                    $res['log'] = ($res['log'] !== null ? $res['log'] . ' ' : '') . 'Licht wurde von Hand eingeschaltet — ' . ($st['until'] > 0 ? 'geht um ' . date('H:i:s', $st['until']) . ' Uhr aus, Bewegung verlängert.' : 'Bewegung erkannt, Nachlauf startet danach.');
                }
            } elseif (!self::anyOn($outs, $ctx) && in_array($st['ph'], ['an', 'warn', 'flash'], true)) {
                $st['offAt'] = 0; // von Hand aus: kein Lernanlass
                if (!empty($ev['central'])) {
                    // Zentral-Aus/Szene: kein Handeingriff — keine Pause, die nächste Bewegung schaltet wieder ein.
                    $st['ph'] = 'aus';
                    $st['until'] = 0;
                    $res['log'] = 'Zentral ausgeschaltet (' . (string)$ev['central'] . ') — nächste Bewegung schaltet wieder ein.';
                } elseif ($handPause > 0) {
                    $st['ph'] = 'hand';
                    $st['hu'] = $now + $handPause;
                    $st['until'] = 0;
                    $res['log'] = 'Licht wurde von Hand ausgeschaltet — Bewegung schaltet bis ' . date('H:i', $st['hu']) . ' Uhr nicht wieder ein.';
                } else {
                    $st['ph'] = 'aus';
                    $st['until'] = 0;
                    $res['log'] = 'Licht wurde von Hand ausgeschaltet.';
                }
            }
        }
        if ($st['ph'] === 'hand' && $now >= (int)$st['hu']) {
            $st['ph'] = 'aus';
            $res['log'] = $res['log'] ?? 'Pause nach Handbedienung vorbei — Bewegung schaltet wieder.';
        }
        // Melder-Rückfall: meldet ein Melder zu lange nichts Neues, gilt er als „keine Bewegung“ —
        // Funkmelder (z. B. Z-Wave) verlieren gelegentlich genau diese Meldung, dann bliebe das Licht an.
        $nextFallback = null;
        if (!$impuls) {
            $lost = [];
            foreach ($mel as $m) {
                if (empty($st['act'][$m]) || !isset($st['since'][$m])) {
                    continue;
                }
                $limit = self::melderTimeout($row, $ctx, $m);
                if ($now - (int)$st['since'][$m] >= $limit) {
                    $st['act'][$m] = false;
                    unset($st['since'][$m]);
                    $res['lost'][] = $m;
                    $lost[] = ((string)($ctx['names'][$m] ?? ('#' . $m))) . ' (seit ' . self::fmtDuration(max(60, $limit)) . ')';
                } else {
                    $t = (int)$st['since'][$m] + $limit;
                    $nextFallback = $nextFallback === null ? $t : min($nextFallback, $t);
                }
            }
            if (count($lost) > 0) {
                $txt = 'Melder ' . implode(', ', $lost) . ' meldet nichts mehr — gilt als „keine Bewegung“ (Meldung vermutlich unterwegs verloren).';
                if (!in_array(true, $st['act'], true) && count($hintNames) === 0 && in_array($st['ph'], ['an', 'warn', 'flash'], true) && (int)$st['until'] === 0) {
                    $st['until'] = $now + $nach;
                    $txt .= ' Licht geht um ' . date('H:i:s', $st['until']) . ' Uhr aus.';
                }
                $res['log'] = $res['log'] !== null ? $res['log'] . ' ' . $txt : $txt;
            }
        }
        // Weitere Anzeichen für Anwesenheit halten das Licht an (schalten aber nie ein).
        if (in_array($st['ph'], ['an', 'warn', 'flash'], true)) {
            if (count($hintNames) > 0) {
                if ((int)$st['until'] > 0) {
                    if ($st['ph'] === 'warn' || $st['ph'] === 'flash') {
                        $res['cmd'] = array_merge($res['cmd'], self::onCmds($outs, $row, $ctx, 'bl'));
                    }
                    $st['ph'] = 'an';
                    $st['until'] = 0;
                    $txt = 'Anwesenheit (' . implode(', ', $hintNames) . ') — Licht bleibt an.';
                    $res['log'] = $res['log'] !== null ? $res['log'] . ' ' . $txt : $txt;
                }
            } elseif ((int)$st['until'] === 0 && ($impuls || !in_array(true, $st['act'], true))) {
                $st['until'] = $now + $nach;
                $txt = ($k === 'trigger' && in_array($vid, $hints, true) ? 'Anzeichen für Anwesenheit vorbei' : 'Keine Bewegung und kein Anzeichen mehr') . ' — Licht geht um ' . date('H:i:s', $st['until']) . ' Uhr aus.';
                $res['log'] = $res['log'] !== null ? $res['log'] . ' ' . $txt : $txt;
            }
        }
        $phBefore = $st['ph'];
        $res['st'] = $st;
        $res = self::lightTimers($row, $res, $ctx, $outs, 'bl', min((int)($row['bl_vorwarn'] ?? 0), intdiv($nach, 2)));
        if (in_array($phBefore, ['an', 'warn', 'flash'], true) && $res['st']['ph'] === 'aus') {
            $res['st']['offAt'] = $now; // automatisch aus — Wieder-An in den nächsten Minuten wäre eine Fehlabschaltung
        }
        if (count($hintNames) > 0 && $res['st']['ph'] === 'an') {
            $res['status'] = '💡 An — Anwesenheit: ' . implode(', ', $hintNames);
        }
        if ($nextFallback !== null && ($res['due'] === null || $nextFallback < $res['due'])) {
            $res['due'] = $nextFallback;
        }
        return $res;
    }

    /** Rückfallzeit eines Melders: fest eingestellt (bl_melmax > 0) oder vom Modul gelernt (ctx), sonst 10 min. */
    public static function melderTimeout(array $row, array $ctx, int $vid): int
    {
        $fix = (int)($row['bl_melmax'] ?? 0);
        if ($fix > 0) {
            return $fix;
        }
        return max(30, (int)($ctx['melTimeout'][$vid] ?? 600));
    }

    // ------------------------------------------------------------------
    // Dämmerungs- und Zeitschaltung
    // ------------------------------------------------------------------

    /** Zeitstempel einer SelectTime-Angabe am Kalendertag $ymd (DST-sicher per mktime). */
    public static function timeOn(string $timeJson, string $ymd): int
    {
        $t = json_decode($timeJson, true);
        $h = (int)($t['hour'] ?? 0);
        $m = (int)($t['minute'] ?? 0);
        [$y, $mo, $d] = array_map('intval', explode('-', $ymd));
        return mktime($h, $m, (int)($t['second'] ?? 0), $mo, $d, $y);
    }

    public static function addDays(string $ymd, int $n): string
    {
        return date('Y-m-d', strtotime($ymd . ' 12:00:00 ' . ($n >= 0 ? '+' : '') . $n . ' days'));
    }

    /** Schaltfenster, das am Tag $ymd beginnt: [start, ende|null], ende null = schaltet nie aus. */
    public static function window(array $row, string $ymd, int $jitter, array $sun): ?array
    {
        $ein = (int)($row['dz_ein'] ?? 0);
        $aus = (int)($row['dz_aus'] ?? 0);
        $next = self::addDays($ymd, 1);
        if ($ein === 0) {
            $ss = $sun[$ymd]['ss'] ?? null;
            if ($ss === null) {
                return null;
            }
            $start = (int)$ss + (int)($row['dz_einversatz'] ?? 0) * 60;
        } elseif ($ein === 1) {
            $start = self::timeOn((string)($row['dz_einzeit'] ?? AUTOMATKatalog::time(18, 0)), $ymd);
        } else {
            $start = self::timeOn(AUTOMATKatalog::time(12, 0), $ymd); // Dunkelheit: frühestens ab Mittag
        }
        if ($ein !== 2) {
            $start += $jitter;
        }
        if ($aus === 0) {
            // Liegt die Ausschaltzeit vor dem Einschalten und vor Mittag, gehört sie zur Nacht danach (18:00 → 01:00).
            // Sonst bleibt sie am selben Tag — liegt der Sonnenuntergang im Sommer nach der Ausschaltzeit, wird heute
            // gar nicht geschaltet. Vorher wurde das Ende auf den nächsten Abend geschoben, das Licht brannte fast
            // 24 h (Prüfbericht 17.10.2026).
            $auszeit = (string)($row['dz_auszeit'] ?? AUTOMATKatalog::time(23, 0));
            $end = self::timeOn($auszeit, $ymd);
            if ($end <= $start - ($ein !== 2 ? $jitter : 0) && $end < self::timeOn(AUTOMATKatalog::time(12, 0), $ymd)) {
                $end = self::timeOn($auszeit, $next);
            }
            $end += $jitter;
        } elseif ($aus === 1) {
            $sr = $sun[$next]['sr'] ?? null;
            if ($sr === null) {
                return null;
            }
            $end = (int)$sr + (int)($row['dz_ausversatz'] ?? 0) * 60 + $jitter;
        } else {
            // Helligkeit oder „gar nicht“: Fenster endet spätestens am nächsten Mittag.
            $end = self::timeOn(AUTOMATKatalog::time(12, 0), $next);
        }
        if ($end <= $start) {
            return null;
        }
        return [$start, $end];
    }

    private static function daemmerung(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $outs = AUTOMATKatalog::vars($row, 'dz_out');
        $ein = (int)($row['dz_ein'] ?? 0);
        $aus = (int)($row['dz_aus'] ?? 0);
        $zufall = max(0, (int)($row['dz_zufall'] ?? 0)) * 60;
        $sun = $ctx['sun'] ?? [];
        $today = date('Y-m-d', $now);
        $yesterday = self::addDays($today, -1);
        $st += ['day' => '', 'jit' => 0, 'jitPrev' => 0, 'on' => false, 'manual' => ''];
        if ($st['day'] !== $today) {
            $st['jitPrev'] = $st['day'] === $yesterday ? (int)$st['jit'] : 0;
            $st['jit'] = (int)round((float)($ctx['rnd'] ?? 0) * $zufall);
            $st['day'] = $today;
        }
        $hard = self::hardBlock($ctx);
        if ($hard !== '') {
            $res['st'] = $st;
            $res['status'] = self::hardStatus($hard);
            return $res;
        }
        // Aktuelles Fenster (gestern begonnen und noch offen, oder heute begonnen)
        $win = null;
        $key = '';
        foreach ([[$yesterday, (int)$st['jitPrev']], [$today, (int)$st['jit']]] as [$d, $j]) {
            $w = self::window($row, $d, $j, $sun);
            if ($w !== null && $now >= $w[0] && $now < $w[1]) {
                $win = $w;
                $key = $d;
            }
        }
        $luxVid = (int)($row['dz_lux'] ?? 0);
        $lux = $luxVid > 0 && array_key_exists($luxVid, $ctx['val'] ?? []) ? (float)$ctx['val'][$luxVid] : null;
        $schwelle = (float)($row['dz_luxschwelle'] ?? 20);
        if ($luxVid <= 0 && ($ctx['dark'] ?? null) !== null) {
            // Kein eigener Sensor: zentrales hell/dunkel (Schwellen und Haltezeit unter „🏠 Hausmodus“)
            $lux = $ctx['dark'] ? -1.0 : 1.0e9;
        }
        $hi = $schwelle * 1.25 + 5; // Hysterese gegen Flackern in der Dämmerung

        if (($ev['k'] ?? '') === 'output' && empty($ev['own']) && in_array((int)($ev['vid'] ?? 0), $outs, true)
            && !self::isTrue($ev['v'] ?? false) && $st['on'] && !self::anyOn($outs, $ctx)) {
            $st['on'] = false;
            $st['manual'] = $key;
            $res['log'] = (!empty($ev['central']) ? 'Zentral ausgeschaltet (' . (string)$ev['central'] . ')' : 'Von Hand ausgeschaltet') . ' — bis zum nächsten Schaltfenster bleibt es aus.';
        }

        if ($win === null) {
            if ($st['on']) {
                if ($aus !== 3) {
                    $res['cmd'] = self::offCmds($outs, $ctx);
                    $res['log'] = 'Ausschaltzeit erreicht — aus.';
                }
                $st['on'] = false;
            }
        } elseif ($st['manual'] !== $key) {
            if (!$st['on']) {
                $want = true;
                if ($ein === 2) {
                    $want = $lux !== null && $lux < $schwelle;
                }
                if ($aus === 2 && $lux !== null && $lux > $hi) {
                    $want = false;
                }
                if ($want) {
                    $soft = self::softBlock($row, $ctx);
                    if ($soft !== '') {
                        $res['status'] = '🌙 Würde jetzt einschalten, aber ' . $soft;
                    } else {
                        $res['cmd'] = self::onCmds($outs, $row, $ctx, 'dz');
                        $st['on'] = true;
                        $res['log'] = ($ein === 2 ? 'Dunkel geworden (' . self::fmtNum($lux) . ' lx)' : ($ein === 0 ? 'Sonnenuntergang' : 'Einschaltzeit')) . ' — an.';
                    }
                }
            } elseif ($aus === 2 && $lux !== null && $lux > $hi) {
                $res['cmd'] = self::offCmds($outs, $ctx);
                $st['on'] = false;
                $st['manual'] = $key; // im selben Fenster nicht wieder einschalten
                $res['log'] = 'Hell geworden (' . self::fmtNum($lux) . ' lx) — aus.';
            }
        }

        // Nächster Zeitpunkt: nächste Fenstergrenze, bei Helligkeit minütlich.
        $next = null;
        foreach ([[$today, (int)$st['jit']], [self::addDays($today, 1), 0]] as [$d, $j]) {
            $w = self::window($row, $d, $j, $sun);
            if ($w === null) {
                continue;
            }
            foreach ($w as $t) {
                if ($t > $now && ($next === null || $t < $next)) {
                    $next = $t;
                }
            }
        }
        if ($win !== null && ($win[1] > $now) && ($next === null || $win[1] < $next)) {
            $next = $win[1];
        }
        if (($ein === 2 || $aus === 2) && $win !== null) {
            $next = $next === null ? $now + 60 : min($next, $now + 60);
        }
        $res['due'] = $next;
        $res['st'] = $st;
        if ($res['status'] === '') {
            if ($st['on']) {
                $res['status'] = '💡 An' . ($win !== null && $aus !== 3 && $aus !== 2 ? ' — aus um ' . self::fmtClock($win[1], $now) : '');
                $res['end'] = $win !== null && $aus !== 3 && $aus !== 2 ? $win[1] : null;
            } elseif ($win !== null && $st['manual'] === $key) {
                $res['status'] = '✋ Von Hand aus — wieder ab dem nächsten Schaltfenster';
            } elseif ($win !== null && $ein === 2) {
                $res['status'] = '⚪ Aus — wartet auf Dunkelheit' . ($lux !== null ? ' (' . self::fmtNum($lux) . ' lx)' : ' (Helligkeitssensor fehlt)');
            } else {
                $res['status'] = '⚪ Aus' . ($next !== null ? ' — nächste Schaltung ' . self::fmtClock($next, $now) : '');
                $res['end'] = $next;
            }
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Fenster offen → Heizung runter
    // ------------------------------------------------------------------

    private static function fenster(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $kon = AUTOMATKatalog::vars($row, 'fo_kon');
        $soll = AUTOMATKatalog::vars($row, 'fo_soll');
        $verz = max(0, (int)($row['fo_verz'] ?? 60));
        $absenk = (float)($row['fo_absenk'] ?? 12);
        $invert = !empty($row['fo_invert']);
        $st += ['ph' => 'zu', 'until' => 0, 'saved' => []];
        $open = false;
        foreach ($kon as $k) {
            if (!array_key_exists($k, $ctx['val'] ?? [])) {
                continue;
            }
            $v = self::isTrue($ctx['val'][$k]);
            if (self::contactInvert($k, $ctx, $invert) ? !$v : $v) {
                $open = true;
            }
        }
        $restore = function () use (&$st, &$res) {
            // Alle gemerkten zurückstellen — auch ein inzwischen aus der Liste genommenes Thermostat
            foreach ((array)$st['saved'] as $vid => $v) {
                $res['cmd'][] = [(int)$vid, (float)$v];
            }
            $vals = [];
            foreach ($st['saved'] as $v) {
                $vals[] = self::fmtNum((float)$v) . ' °C';
            }
            $st['saved'] = [];
            return implode(', ', array_unique($vals));
        };

        $hard = self::hardBlock($ctx);
        if ($hard !== '') {
            if ($st['ph'] === 'ab') {
                $res['log'] = 'Automatik ' . $hard . ' — Sollwert zurück auf ' . $restore() . '.';
            }
            $st['ph'] = 'zu';
            $res['st'] = $st;
            $res['status'] = self::hardStatus($hard);
            return $res;
        }

        $k = $ev['k'] ?? 'tick';
        $vid = (int)($ev['vid'] ?? 0);
        if ($k === 'output' && empty($ev['own']) && $st['ph'] === 'ab' && in_array($vid, $soll, true)) {
            $v = (float)($ev['v'] ?? 0);
            if (abs($v - $absenk) > 0.05) {
                // Wochenplan, Thermostat oder Mensch hat den Sollwert geändert:
                // merken (das soll beim Schließen gelten) und wieder absenken.
                $st['saved'][$vid] = $v;
                $res['cmd'][] = [$vid, $absenk];
                $res['log'] = 'Sollwert wurde bei offenem Fenster auf ' . self::fmtNum($v) . ' °C geändert — gemerkt, bleibt abgesenkt.';
            }
        }

        if ($open) {
            if ($st['ph'] === 'zu') {
                $soft = self::softBlock($row, $ctx);
                if ($soft !== '') {
                    $res['status'] = '🪟 Offen — keine Absenkung: ' . $soft;
                    $res['st'] = $st;
                    return $res;
                }
                $st['ph'] = 'wartet';
                $st['until'] = $now + $verz;
                $res['log'] = 'Fenster geöffnet' . ($verz > 0 ? ' — senke ab, falls noch offen um ' . date('H:i:s', $st['until']) . ' Uhr.' : '.');
            }
            if ($st['ph'] === 'wartet' && $now >= (int)$st['until']) {
                $st['saved'] = [];
                $from = [];
                foreach ($soll as $s) {
                    if (!array_key_exists($s, $ctx['val'] ?? [])) {
                        continue; // ohne Messwert nichts merken — sonst „zurück“ auf den Absenkwert
                    }
                    $cur = (float)$ctx['val'][$s];
                    $st['saved'][$s] = $cur;
                    $from[] = self::fmtNum($cur) . ' °C';
                    $res['cmd'][] = [$s, $absenk];
                }
                $st['ph'] = 'ab';
                $res['log'] = 'Fenster offen — Sollwert von ' . implode(', ', array_unique($from)) . ' auf ' . self::fmtNum($absenk) . ' °C abgesenkt.';
            }
        } else {
            if ($st['ph'] === 'wartet') {
                $st['ph'] = 'zu';
                $res['log'] = 'Fenster vor Ablauf der Verzögerung geschlossen — keine Absenkung.';
            } elseif ($st['ph'] === 'ab') {
                $res['log'] = (!empty($ev['startup']) ? 'Beim Start nachgeholt: alle Fenster zu, Thermostat stand noch abgesenkt' : 'Fenster geschlossen') . ' — Sollwert zurück auf ' . $restore() . '.';
                $st['ph'] = 'zu';
            }
        }
        $res['st'] = $st;
        switch ($st['ph']) {
            case 'wartet':
                $res['due'] = (int)$st['until'];
                $res['status'] = '🪟 Offen — Absenkung um ' . self::fmtClock((int)$st['until'], $now);
                $res['end'] = (int)$st['until'];
                break;
            case 'ab':
                $res['status'] = '🪟 Offen — abgesenkt auf ' . self::fmtNum($absenk) . ' °C';
                break;
            default:
                $res['status'] = '✅ Alles zu';
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Wächter „bleibt zu lange so“
    // ------------------------------------------------------------------

    public static function compare($v, int $op, string $ref): bool
    {
        switch ($op) {
            case 4:
                return self::isTrue($v);
            case 5:
                return !self::isTrue($v);
        }
        $r = trim($ref);
        if (is_bool($v)) {
            $eq = self::isTrue($v) === self::isTrue($r);
            return $op === 1 ? !$eq : ($op === 0 ? $eq : false);
        }
        $rn = str_replace(',', '.', $r);
        if (is_numeric($rn) && (is_numeric($v) || is_float($v) || is_int($v))) {
            $a = (float)$v;
            $b = (float)$rn;
            switch ($op) {
                case 0:
                    return abs($a - $b) < 0.000001;
                case 1:
                    return abs($a - $b) >= 0.000001;
                case 2:
                    return $a > $b;
                case 3:
                    return $a < $b;
            }
            return false;
        }
        $eq = mb_strtolower(trim((string)$v)) === mb_strtolower($r);
        return $op === 0 ? $eq : ($op === 1 ? !$eq : false);
    }

    /** Einheitlicher Schlüssel eines Werts für Beschriftungen: true/false, Zahlen ohne „.0“, Text wie er ist. */
    public static function valueKey($v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        $s = trim((string)$v);
        $n = str_replace(',', '.', $s);
        if (is_numeric($n)) {
            return (string)(0 + $n);
        }
        $l = mb_strtolower($s);
        return in_array($l, ['true', 'false'], true) ? $l : $s;
    }

    public static function fmtDuration(int $sec): string
    {
        if ($sec >= 86400) {
            return round($sec / 86400, 1) . ' Tagen';
        }
        if ($sec >= 3600) {
            $h = intdiv($sec, 3600);
            $m = intdiv($sec % 3600, 60);
            return $h . ' h' . ($m > 0 ? ' ' . $m . ' min' : '');
        }
        return max(1, intdiv($sec, 60)) . ' min';
    }

    private static function waechter(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $vid = (int)($row['wa_var'] ?? 0);
        $op = (int)($row['wa_op'] ?? 4);
        $ref = (string)($row['wa_wert'] ?? '');
        $dauer = max(0, (int)($row['wa_dauer'] ?? 15)) * 60;
        $wdh = max(0, (int)($row['wa_wdh'] ?? 0)) * 60;
        $name = trim((string)($row['Name'] ?? '')) ?: 'Wächter';
        $varName = (string)($ctx['names'][$vid] ?? ('#' . $vid));
        $st += ['ph' => 'normal', 'since' => 0, 'last' => 0];
        $v = $ctx['val'][$vid] ?? null;
        $cond = $v !== null && self::compare($v, $op, $ref);
        $opText = AUTOMATKatalog::WA_OPS[$op] ?? '';
        // Beschriftungen der Variable (z. B. „Offen“ statt „true“), wenn das Modul sie mitgibt
        $labels = (array)($ctx['labels'][$vid] ?? []);
        $cmpText = in_array($op, [4, 5], true) ? $opText : $opText . ' ' . ($labels[self::valueKey($ref)] ?? $ref);
        $hard = self::hardBlock($ctx);
        $blocked = $hard !== '' ? $hard : self::softBlock($row, $ctx);
        $text = function (int $since) use ($row, $varName, $cmpText, $now, $v, $labels) {
            $t = trim((string)($row['wa_text'] ?? ''));
            if ($t !== '') {
                return $t;
            }
            return $varName . ' ' . $cmpText . ' — seit ' . self::fmtDuration(max(60, $now - $since)) . ' (aktuell ' . ($labels[self::valueKey($v)] ?? self::fmtNum($v)) . ').';
        };

        if ($cond) {
            if ($st['ph'] === 'normal') {
                $st['ph'] = 'zaehlt';
                $st['since'] = $now;
            }
            if ($st['ph'] === 'zaehlt' && $now >= (int)$st['since'] + $dauer) {
                if ($blocked === '') {
                    $res['msg'][] = ['⏱ ' . $name, $text((int)$st['since'])];
                    $st['ph'] = 'alarm';
                    $st['last'] = $now;
                    $res['log'] = 'Gemeldet: ' . $text((int)$st['since']);
                }
            } elseif ($st['ph'] === 'alarm' && $wdh > 0 && $now >= (int)$st['last'] + $wdh && $blocked === '') {
                $res['msg'][] = ['⏱ ' . $name . ' (Erinnerung)', $text((int)$st['since'])];
                $st['last'] = $now;
                $res['log'] = 'Erinnerung gesendet.';
            }
        } else {
            if ($st['ph'] === 'alarm' && !empty($row['wa_ende']) && $hard === '') {
                $res['msg'][] = ['✅ ' . $name, $varName . ' ist wieder in Ordnung (aktuell ' . self::fmtNum($v) . ').'];
                $res['log'] = 'Entwarnung gesendet.';
            }
            $st['ph'] = 'normal';
            $st['since'] = 0;
        }
        $res['st'] = $st;
        if ($st['ph'] === 'zaehlt') {
            $res['due'] = (int)$st['since'] + $dauer;
            $res['end'] = (int)$st['since'] + $dauer;
            $res['status'] = '⏳ ' . $varName . ' ' . $cmpText . ' — Meldung um ' . self::fmtClock((int)$st['since'] + $dauer, $now)
                . ($blocked !== '' ? ' (wird nicht gemeldet: ' . $blocked . ')' : '');
            if ($now >= (int)$st['since'] + $dauer) {
                $res['due'] = $now + 60;
            }
        } elseif ($st['ph'] === 'alarm') {
            $res['due'] = $wdh > 0 ? (int)$st['last'] + $wdh : null;
            $res['status'] = '🔔 Gemeldet — ' . $varName . ' ' . $cmpText . ' seit ' . date('d.m.Y H:i', (int)$st['since']) . ' Uhr';
        } else {
            $res['status'] = $hard !== '' ? self::hardStatus($hard) : ($v === null ? '❔ Variable fehlt' : '✅ In Ordnung (' . self::fmtNum($v) . ')');
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Gerät fertig
    // ------------------------------------------------------------------

    private static function fertig(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $vid = (int)($row['gf_var'] ?? 0);
        $lauf = (float)($row['gf_lauf'] ?? 20);
        $fertig = (float)($row['gf_fertig'] ?? 5);
        $minlauf = max(0, (int)($row['gf_minlauf'] ?? 5)) * 60;
        $nach = max(0, (int)($row['gf_nach'] ?? 3)) * 60;
        $name = trim((string)($row['Name'] ?? '')) ?: 'Gerät';
        $st += ['ph' => 'aus', 'start' => 0, 'below' => 0];
        $p = array_key_exists($vid, $ctx['val'] ?? []) ? (float)$ctx['val'][$vid] : null;
        if ($p !== null) {
            if ($p >= $lauf) {
                if ($st['ph'] === 'aus') {
                    $st['ph'] = 'laeuft';
                    $st['start'] = $now;
                    $res['log'] = 'Läuft (' . self::fmtNum($p) . ' W).';
                } elseif ($st['ph'] === 'auslauf') {
                    $st['ph'] = 'laeuft';
                }
            } elseif ($p <= $fertig) {
                if ($st['ph'] === 'laeuft') {
                    if ($now - (int)$st['start'] >= $minlauf) {
                        $st['ph'] = 'auslauf';
                        $st['below'] = $now;
                    } else {
                        $st['ph'] = 'aus';
                        $res['log'] = 'Nur kurze Leistungsspitze — zählt nicht als Lauf.';
                    }
                }
            }
        }
        if ($st['ph'] === 'auslauf' && $now >= (int)$st['below'] + $nach) {
            $hard = self::hardBlock($ctx);
            $blocked = $hard !== '' ? $hard : self::softBlock($row, $ctx);
            $t = trim((string)($row['gf_text'] ?? ''));
            $text = $t !== '' ? $t : $name . ' ist fertig (Laufzeit ' . self::fmtDuration(max(60, (int)$st['below'] - (int)$st['start'])) . ').';
            if ($blocked === '') {
                $res['msg'][] = ['🧺 ' . $name, $text];
                $res['log'] = 'Fertig gemeldet.';
            } else {
                $res['log'] = 'Fertig — nicht gemeldet: ' . $blocked . '.';
            }
            $st['ph'] = 'aus';
            $st['last'] = $now;
        }
        $res['st'] = $st;
        switch ($st['ph']) {
            case 'laeuft':
                $res['status'] = '▶️ Läuft seit ' . date('H:i', (int)$st['start']) . ' Uhr' . ($p !== null ? ' (' . self::fmtNum($p) . ' W)' : '');
                break;
            case 'auslauf':
                $res['due'] = (int)$st['below'] + $nach;
                $res['status'] = '⏳ Fast fertig — Meldung um ' . self::fmtClock((int)$st['below'] + $nach, $now);
                $res['end'] = (int)$st['below'] + $nach;
                break;
            default:
                $res['status'] = !empty($st['last']) ? '⚪ Aus — zuletzt fertig ' . date('d.m.Y H:i', (int)$st['last']) . ' Uhr' : '⚪ Aus';
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Hausmodus-Automatik
    // ------------------------------------------------------------------

    /**
     * Welcher Hausmodus gälte jetzt nach Tageszeit und Anwesenheit?
     * $c: dayRef (0 Uhrzeit, 1 Sonnenaufgang, 2 „Ist es Tag“), dayFrom (SelectTime-JSON), dayOff (min),
     *     eveRef (0 Sonnenuntergang, 1 Uhrzeit, 2 „Ist es Tag“ wird falsch), eveFrom, eveOff (min),
     *     night (SelectTime-JSON), sunrise/sunset (Zeitstempel heute oder null), isDay (bool|null),
     *     nightAt (Zeitstempel heute statt „night“, z. B. Ende der Dämmerung, oder null), nightOff (min).
     *     „sunrise“/„sunset“ stehen für den gewählten Zeitpunkt der Location Control (auch Dämmerungsbeginn/-ende).
     *     dayRef/eveRef 6 = Helligkeitssensor: wie „Ist es Tag“, aber mit lightDay (bool|null, siehe lightDay()).
     * $presence: true = jemand da, false = niemand da, null = unbekannt.
     * Urlaub wird nie automatisch gesetzt und nie automatisch verlassen.
     */
    public static function autoMode(int $now, array $c, ?bool $presence, int $current): int
    {
        if ($current === AUTOMATKatalog::MODE_HOLIDAY) {
            return $current;
        }
        if ($presence === false) {
            return AUTOMATKatalog::MODE_AWAY;
        }
        $today = date('Y-m-d', $now);
        $noon = self::timeOn(AUTOMATKatalog::time(12, 0), $today);
        $dayRef = (int)($c['dayRef'] ?? 0);
        $eveRef = (int)($c['eveRef'] ?? 0);
        // „Ist es Tag“ (2) und Helligkeitssensor (6) wirken gleich: ein Ja/Nein statt eines Zeitpunkts.
        $flag = function (int $ref) use ($c) {
            return $ref === 6 ? ($c['lightDay'] ?? null) : ($c['isDay'] ?? null);
        };
        $dayFlag = $flag($dayRef);
        $eveFlag = $flag($eveRef);
        // Fehlt die Angabe (keine Location Control, Sensor fehlt), gilt die eingetragene Uhrzeit.
        if ($dayRef === 1 && ($c['sunrise'] ?? null) === null || ($dayRef === 2 || $dayRef === 6) && $dayFlag === null) {
            $dayRef = 0;
        }
        if ($eveRef === 0 && ($c['sunset'] ?? null) === null || ($eveRef === 2 || $eveRef === 6) && $eveFlag === null) {
            $eveRef = 1;
        }
        if ($dayRef === 2 || $dayRef === 6) {
            $beforeDay = !$dayFlag && $now < $noon;
        } else {
            $dayStart = $dayRef === 1 ? (int)$c['sunrise'] + (int)($c['dayOff'] ?? 0) * 60 : self::timeOn((string)($c['dayFrom'] ?? AUTOMATKatalog::time(6, 30)), $today);
            $beforeDay = $now < $dayStart;
        }
        if ($eveRef === 2 || $eveRef === 6) {
            $evening = !$eveFlag && $now >= $noon;
        } else {
            $eveStart = $eveRef === 0 ? (int)$c['sunset'] + (int)($c['eveOff'] ?? 0) * 60 : self::timeOn((string)($c['eveFrom'] ?? AUTOMATKatalog::time(18, 0)), $today);
            $evening = $now >= $eveStart;
        }
        $night = ($c['nightAt'] ?? null) !== null
            ? (int)$c['nightAt'] + (int)($c['nightOff'] ?? 0) * 60
            : self::timeOn((string)($c['night'] ?? AUTOMATKatalog::time(22, 30)), $today);
        if ($night < $noon) {
            // „Nacht ab 00:30“: nach Mitternacht bis dahin noch Abend, danach Nacht bis zum Tag.
            if ($now < $noon) {
                return $now >= $night && $beforeDay ? AUTOMATKatalog::MODE_NIGHT : ($beforeDay ? 1 : 0);
            }
            return $evening ? 1 : 0;
        }
        if ($now >= $night || $beforeDay) {
            return AUTOMATKatalog::MODE_NIGHT;
        }
        return $evening ? 1 : 0;
    }

    /** Rohe SMTP-Fehlermeldung in einen verständlichen Grund übersetzen (wie Batteriewächter). */
    public static function mailError(string $raw): string
    {
        $h = mb_strtolower($raw);
        if (strpos($h, 'login denied') !== false || strpos($h, 'authentication') !== false || strpos($h, 'auth failed') !== false || strpos($h, '535') !== false) {
            $why = 'Anmeldung abgelehnt: Benutzername oder Passwort der SMTP-Instanz stimmen nicht (bei Anbietern mit Zwei-Faktor-Anmeldung braucht es ein App-Passwort)';
        } elseif (strpos($h, 'resolve') !== false || strpos($h, 'getaddrinfo') !== false) {
            $why = 'Server nicht gefunden: Servername der SMTP-Instanz prüfen';
        } elseif (strpos($h, 'timed out') !== false || strpos($h, 'timeout') !== false) {
            $why = 'Zeitüberschreitung: der Mailserver antwortet nicht (Server, Port und Netzwerk prüfen)';
        } elseif (strpos($h, 'refused') !== false) {
            $why = 'Verbindung abgelehnt: Server oder Port der SMTP-Instanz prüfen';
        } elseif (strpos($h, 'certificate') !== false || strpos($h, 'ssl') !== false || strpos($h, 'tls') !== false) {
            $why = 'Verschlüsselung oder Zertifikat: SSL-Einstellung und Port der SMTP-Instanz prüfen';
        } elseif (strpos($h, 'recipient') !== false || strpos($h, 'rcpt') !== false) {
            $why = 'Empfänger abgelehnt: Empfängeradresse prüfen';
        } else {
            $why = 'Meldung des SMTP-Moduls';
        }
        return $why . ' („' . $raw . '“)';
    }

    /**
     * Hell oder dunkel nach einem Helligkeits-/Einstrahlungssensor, mit zwei Schwellen und Haltezeit,
     * damit eine Wolke oder ein Schatten nicht umschaltet. $st: ['day' => bool, 'cand' => Zeitstempel|0].
     * Hell wird es erst, wenn der Wert $hold Sekunden lang mindestens $up ist; dunkel, wenn er $hold Sekunden
     * lang unter $down liegt. Dazwischen bleibt es, wie es ist.
     */
    public static function lightDay(array $st, float $v, int $now, float $up, float $down, int $hold): array
    {
        if (!isset($st['day'])) {
            return ['day' => $v >= ($up + $down) / 2, 'cand' => 0];
        }
        $crossing = $st['day'] ? $v < $down : $v >= $up;
        if (!$crossing) {
            return ['day' => (bool)$st['day'], 'cand' => 0];
        }
        $cand = (int)($st['cand'] ?? 0) ?: $now;
        if ($now - $cand >= $hold) {
            return ['day' => !$st['day'], 'cand' => 0];
        }
        return ['day' => (bool)$st['day'], 'cand' => $cand];
    }

    // ------------------------------------------------------------------
    // Feiertage und Taupunkt (ab 0.1.35)
    // ------------------------------------------------------------------

    /** Ostersonntag (Gauß/Lichtenberg) als [Monat, Tag] — ohne die calendar-Erweiterung. */
    public static function easter(int $y): array
    {
        $a = $y % 19;
        $b = intdiv($y, 100);
        $c = $y % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return [$month, $day];
    }

    /** Feiertag am Datum (JJJJ-MM-TT) für ein Land/Bundesland, sonst ''. Name des Feiertags als Rückgabe. */
    public static function holiday(string $ymd, string $land): string
    {
        if ($land === '') {
            return '';
        }
        [$y, $mo, $d] = array_map('intval', explode('-', $ymd));
        [$em, $ed] = self::easter($y);
        $rel = function (int $off) use ($y, $em, $ed): string {
            return date('Y-m-d', mktime(12, 0, 0, $em, $ed + $off, $y));
        };
        $fix = function (int $m, int $dd) use ($y): string {
            return sprintf('%04d-%02d-%02d', $y, $m, $dd);
        };
        $list = [];
        if ($land === 'AT') {
            $list = [$fix(1, 1) => 'Neujahr', $fix(1, 6) => 'Heilige Drei Könige', $rel(1) => 'Ostermontag', $fix(5, 1) => 'Staatsfeiertag',
                $rel(39) => 'Christi Himmelfahrt', $rel(50) => 'Pfingstmontag', $rel(60) => 'Fronleichnam', $fix(8, 15) => 'Mariä Himmelfahrt',
                $fix(10, 26) => 'Nationalfeiertag', $fix(11, 1) => 'Allerheiligen', $fix(12, 8) => 'Mariä Empfängnis', $fix(12, 25) => 'Christtag', $fix(12, 26) => 'Stefanitag'];
        } elseif ($land === 'CH') {
            $list = [$fix(1, 1) => 'Neujahr', $rel(39) => 'Auffahrt', $fix(8, 1) => 'Bundesfeier', $fix(12, 25) => 'Weihnachten'];
        } else {
            $list = [$fix(1, 1) => 'Neujahr', $rel(-2) => 'Karfreitag', $rel(1) => 'Ostermontag', $fix(5, 1) => 'Tag der Arbeit',
                $rel(39) => 'Christi Himmelfahrt', $rel(50) => 'Pfingstmontag', $fix(10, 3) => 'Tag der Deutschen Einheit',
                $fix(12, 25) => '1. Weihnachtstag', $fix(12, 26) => '2. Weihnachtstag'];
            $in = function (string $l) use ($land): bool {
                return in_array($land, explode(',', $l), true);
            };
            if ($in('BW,BY,ST')) {
                $list[$fix(1, 6)] = 'Heilige Drei Könige';
            }
            if ($in('BE,MV')) {
                $list[$fix(3, 8)] = 'Frauentag';
            }
            if ($in('BW,BY,HE,NW,RP,SL')) {
                $list[$rel(60)] = 'Fronleichnam';
            }
            if ($in('SL')) {
                $list[$fix(8, 15)] = 'Mariä Himmelfahrt';
            }
            if ($in('TH')) {
                $list[$fix(9, 20)] = 'Weltkindertag';
            }
            if ($in('BB,MV,SN,ST,TH,HB,HH,NI,SH')) {
                $list[$fix(10, 31)] = 'Reformationstag';
            }
            if ($in('BW,BY,NW,RP,SL')) {
                $list[$fix(11, 1)] = 'Allerheiligen';
            }
            if ($in('SN')) {
                // Buß- und Bettag: Mittwoch vor dem 23. November
                $t = mktime(12, 0, 0, 11, 22, $y);
                while ((int)date('N', $t) !== 3) {
                    $t = mktime(12, 0, 0, 11, (int)date('j', $t) - 1, $y);
                }
                $list[date('Y-m-d', $t)] = 'Buß- und Bettag';
            }
        }
        return $list[$ymd] ?? '';
    }

    /** Taupunkt in °C (Magnus-Formel). */
    public static function dewPoint(float $t, float $rh): float
    {
        $rh = max(1.0, min(100.0, $rh));
        $g = log($rh / 100) + 17.62 * $t / (243.12 + $t);
        return 243.12 * $g / (17.62 - $g);
    }

    // ------------------------------------------------------------------
    // Gehen-Check
    // ------------------------------------------------------------------

    private static function gehen(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $st += ['last' => '', 'lastTs' => 0, 'bad' => false];
        $name = trim((string)($row['Name'] ?? '')) ?: 'Gehen-Check';
        $hard = self::hardBlock($ctx);
        if (($ev['k'] ?? '') === 'mode' && !empty($row['gc_m' . (int)($ev['to'] ?? -1)])) {
            $to = (int)$ev['to'];
            if ($hard !== '') {
                $res['log'] = 'Hausmodus „' . AUTOMATKatalog::MODES[$to] . '“ — nicht geprüft: ' . $hard . '.';
            } else {
                $open = self::activeOf(AUTOMATKatalog::vars($row, 'gc_kon'), $ctx, !empty($row['gc_invert']));
                $on = [];
                $watt = (float)($row['gc_watt'] ?? 10);
                foreach (AUTOMATKatalog::vars($row, 'gc_geraet') as $vid) {
                    if (!array_key_exists($vid, $ctx['val'] ?? [])) {
                        continue;
                    }
                    $v = $ctx['val'][$vid];
                    $isOn = is_bool($v) ? $v : (is_numeric($v) ? (float)$v >= $watt : self::isTrue($v));
                    if ($isOn) {
                        $on[] = self::nameOf($vid, $ctx);
                        if (!empty($row['gc_aus'])) {
                            $res['cmd'][] = [$vid, self::offValue($vid, $ctx)];
                        }
                    }
                }
                $parts = [];
                if ($open) {
                    $parts[] = 'offen: ' . implode(', ', $open);
                }
                if ($on) {
                    $parts[] = (!empty($row['gc_aus']) ? 'ausgeschaltet: ' : 'noch an: ') . implode(', ', $on);
                }
                $txt = $parts ? implode('; ', $parts) : 'alles zu und aus';
                $st['last'] = AUTOMATKatalog::MODES[$to] . ' — ' . $txt;
                $st['lastTs'] = $now;
                $st['bad'] = count($open) > 0 || (count($on) > 0 && empty($row['gc_aus']));
                $res['log'] = 'Gehen-Check bei „' . AUTOMATKatalog::MODES[$to] . '“: ' . $txt . '.';
                if (!empty($row['gc_push']) && ($parts || !empty($row['gc_ok']))) {
                    $res['msg'][] = [($parts ? '🚪 ' : '✅ ') . $name, ($parts ? 'Beim ' . ($to === AUTOMATKatalog::MODE_NIGHT ? 'Zubettgehen' : 'Gehen') . ' — ' . $txt : ucfirst($txt)) . '.'];
                }
            }
        }
        $res['st'] = $st;
        $res['status'] = $hard !== '' ? self::hardStatus($hard)
            : ($st['last'] === '' ? '💤 Prüft beim nächsten Wechsel des Hausmodus'
                : ($st['bad'] ? '⚠️ ' : '✅ ') . 'Zuletzt ' . date('d.m.Y H:i', (int)$st['lastTs']) . ' — ' . $st['last']);
        return $res;
    }

    // ------------------------------------------------------------------
    // Türklingel
    // ------------------------------------------------------------------

    private static function klingel(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $mode = (int)($ctx['mode'] ?? 0);
        $st += ['last' => 0, 'until' => 0, 'lights' => [], 'vol' => []];
        $name = trim((string)($row['Name'] ?? '')) ?: 'Türklingel';
        $hard = self::hardBlock($ctx);
        // Handeingriff während der Klingelzeit: dieses Gerät nicht mehr zurückstellen
        if (($ev['k'] ?? '') === 'output' && empty($ev['own'])) {
            $k = (string)(int)($ev['vid'] ?? 0);
            if (isset($st['lights'][$k]) || isset($st['vol'][$k])) {
                unset($st['lights'][$k], $st['vol'][$k]);
                $res['log'] = self::nameOf((int)$k, $ctx) . ' von Hand verstellt — bleibt so.';
            }
        }
        $in = (int)($row['kl_taster'] ?? 0);
        if (($ev['k'] ?? '') === 'trigger' && (int)($ev['vid'] ?? 0) === $in && $hard === '') {
            $key = mb_strtolower(self::valueKey($ev['v'] ?? ''));
            if (!in_array($key, ['', 'false', '0'], true) && $now - (int)$st['last'] >= 10) {
                $st['last'] = $now;
                $away = in_array($mode, [AUTOMATKatalog::MODE_AWAY, AUTOMATKatalog::MODE_HOLIDAY], true);
                $res['log'] = 'Es hat geklingelt' . ($away ? ' (niemand zu Hause)' : '') . '.';
                if (!empty($row['kl_push'])) {
                    $res['msg'][] = ['🛎 ' . $name . ($away ? ' — niemand zu Hause' : ''), 'Es hat um ' . date('H:i', $now) . ' Uhr geklingelt.'];
                }
                if ((int)($row['kl_bild'] ?? 0) >= AUTOMATKatalog::$minId) {
                    $res['media'] = (int)$row['kl_bild'];
                }
                $dauer = max(1, (int)($row['kl_dauer'] ?? 3)) * 60;
                if (in_array($mode, [1, AUTOMATKatalog::MODE_NIGHT], true)) {
                    foreach (AUTOMATKatalog::vars($row, 'kl_licht') as $vid) {
                        if (!self::isTrue($ctx['val'][$vid] ?? false)) {
                            $res['cmd'][] = [$vid, self::onValue($vid, 100, $ctx)];
                            $st['lights'][(string)$vid] = true;
                        }
                    }
                }
                $lw = (float)($row['kl_lautwert'] ?? 5);
                foreach (AUTOMATKatalog::vars($row, 'kl_laut') as $vid) {
                    $cur = $ctx['val'][$vid] ?? null;
                    if ($cur !== null && (float)$cur > $lw) {
                        if (!isset($st['vol'][(string)$vid])) {
                            $st['vol'][(string)$vid] = (float)$cur;
                        }
                        $res['cmd'][] = [$vid, $lw];
                    }
                }
                if ($st['lights'] || $st['vol']) {
                    $st['until'] = $now + $dauer;
                }
            }
        }
        if ((int)$st['until'] > 0 && $now >= (int)$st['until']) {
            foreach (array_keys($st['lights']) as $k) {
                $res['cmd'][] = [(int)$k, self::offValue((int)$k, $ctx)];
            }
            foreach ($st['vol'] as $k => $v) {
                $res['cmd'][] = [(int)$k, (float)$v];
            }
            if ($st['lights'] || $st['vol']) {
                $res['log'] = 'Klingelzeit vorbei — Licht aus, Lautstärke zurück.';
            }
            $st['lights'] = [];
            $st['vol'] = [];
            $st['until'] = 0;
        }
        $res['st'] = $st;
        if ($hard !== '') {
            $res['status'] = self::hardStatus($hard);
        } elseif ((int)$st['until'] > 0) {
            $res['due'] = (int)$st['until'];
            $res['end'] = (int)$st['until'];
            $res['status'] = '🛎 Geklingelt — zurück um ' . self::fmtClock((int)$st['until'], $now);
        } else {
            $res['status'] = (int)$st['last'] > 0 ? '🛎 Zuletzt geklingelt ' . date('d.m.Y H:i', (int)$st['last']) . ' Uhr' : '💤 Wartet aufs Klingeln';
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Sommer-Lüften
    // ------------------------------------------------------------------

    private static function sommer(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $val = function (string $f) use ($row, $ctx): ?float {
            $vid = (int)($row[$f] ?? 0);
            return $vid >= AUTOMATKatalog::$minId && array_key_exists($vid, $ctx['val'] ?? []) && is_numeric($ctx['val'][$vid]) ? (float)$ctx['val'][$vid] : null;
        };
        $ti = $val('sl_innen');
        $ta = $val('sl_aussen');
        $st += ['ph' => '', 'sent' => [], 'told' => ''];
        $name = trim((string)($row['Name'] ?? '')) ?: 'Sommer-Lüften';
        $hard = self::hardBlock($ctx);
        if ($ti === null || $ta === null || $hard !== '') {
            $res['st'] = $st;
            $res['status'] = $hard !== '' ? self::hardStatus($hard) : '❔ Temperatur innen oder außen fehlt';
            return $res;
        }
        $min = (float)($row['sl_min'] ?? 23);
        $diff = max(0.1, (float)($row['sl_diff'] ?? 1.5));
        $rhi = $val('sl_rhinnen');
        $rha = $val('sl_rhaussen');
        $dp = $rhi !== null && $rha !== null;
        $dpi = $dp ? self::dewPoint($ti, $rhi) : 0.0;
        $dpa = $dp ? self::dewPoint($ta, $rha) : 0.0;
        $kon = AUTOMATKatalog::vars($row, 'sl_kon');
        $open = self::activeOf($kon, $ctx, !empty($row['sl_konInvert']));
        $warm = $ti >= $min;
        $close = $warm && ($ta > $ti + $diff || ($dp && $dpa > $dpi + 1.0));
        $vent = $warm && $ta < $ti - $diff && (!$dp || $dpa < $dpi - 1.0);
        // Hysterese: eine Empfehlung bleibt, bis sich die Lage um die Hälfte des Unterschieds gedreht hat
        if ($st['ph'] === 'close' && !($ta > $ti + $diff / 2 || ($dp && $dpa > $dpi))) {
            $st['ph'] = '';
        }
        if ($st['ph'] === 'open' && !($ta < $ti - $diff / 2 && $warm)) {
            $st['ph'] = '';
        }
        $new = $close ? 'close' : ($vent ? 'open' : '');
        $txt = 'innen ' . self::fmtNum($ti) . ' °C, außen ' . self::fmtNum($ta) . ' °C' . ($dp ? ' (Taupunkt innen ' . self::fmtNum(round($dpi, 1)) . ', außen ' . self::fmtNum(round($dpa, 1)) . ' °C)' : '');
        if ($new !== '' && $new !== $st['ph']) {
            $st['ph'] = $new;
        }
        if ($st['ph'] === '') {
            $st['told'] = '';
        }
        // Gemeldet wird, sobald die Lage gilt UND es gerade passt (Fenster offen bzw. zu, keine Sperre) — auch wenn
        // das erst nach dem Umschlag so ist (Fenster geht später auf, Heimkommen). Vorher ging das verloren.
        $cur = $st['ph'];
        if ($cur !== '' && $st['told'] !== $cur) {
            $relevant = count($kon) === 0 || ($cur === 'close' ? count($open) > 0 : count($open) === 0);
            $soft = self::softBlock($row, $ctx);
            $cool = $now - (int)($st['sent'][$cur] ?? 0) >= 7200; // höchstens alle 2 Stunden dieselbe Empfehlung
            $new = $cur;
            if ($relevant && $soft === '' && $cool) {
                $st['told'] = $cur;
                $st['sent'][$new] = $now;
                $res['log'] = ($new === 'close' ? 'Draußen wärmer' : 'Draußen kühler') . ' — ' . $txt . '.';
                if (!empty($row['sl_push'])) {
                    $res['msg'][] = $new === 'close'
                        ? ['☀️ ' . $name, 'Fenster schließen' . (count($open) > 0 ? ' (' . implode(', ', $open) . ')' : '') . ' — draußen ist es wärmer' . ($dp && $dpa > $dpi + 1.0 ? ' bzw. feuchter' : '') . ': ' . $txt . '.']
                        : ['🌬 ' . $name, 'Jetzt lüften lohnt sich — draußen ist es kühler' . ($dp ? ' und trockener' : '') . ': ' . $txt . '.'];
                }
            }
        }
        $res['st'] = $st;
        $res['status'] = $st['ph'] === 'close' ? '☀️ Draußen wärmer — Fenster zu lassen: ' . $txt
            : ($st['ph'] === 'open' ? '🌬 Draußen kühler — lüften lohnt: ' . $txt
                : ($warm ? '✅ Kein Handlungsbedarf: ' : '💤 Innen unter ' . self::fmtNum($min) . ' °C: ') . $txt);
        return $res;
    }

    // ------------------------------------------------------------------
    // Ausfall-Wächter
    // ------------------------------------------------------------------

    private static function ausfall(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $vid = (int)($row['af_var'] ?? 0);
        $p = array_key_exists($vid, $ctx['val'] ?? []) && is_numeric($ctx['val'][$vid]) ? (float)$ctx['val'][$vid] : null;
        $runMode = (int)($row['af_modus'] ?? 0) === 1;
        $schwelle = (float)($row['af_schwelle'] ?? 5);
        $dauer = max(1, (int)($row['af_dauer'] ?? 120)) * 60;
        $name = trim((string)($row['Name'] ?? '')) ?: self::nameOf($vid, $ctx);
        $st += ['ph' => 'ok', 'since' => 0, 'gaps' => []];
        $hard = self::hardBlock($ctx);
        if ($p === null || $hard !== '') {
            // Was in der Pause/Sperre oder ohne Messwert passiert ist, sieht der Wächter nicht — danach neu beginnen,
            // sonst gäbe es beim Ende der Pause einen Fehlalarm über die ganze Pausenzeit (Prüfbericht 17.10.2026).
            if ($st['ph'] === 'ok') {
                $st['since'] = 0;
            }
            $res['st'] = $st;
            $res['status'] = $hard !== '' ? self::hardStatus($hard) : '❔ Leistung unbekannt';
            return $res;
        }
        $active = $p >= $schwelle;
        // „since“: Beginn der beobachteten Phase (ohne Strom bzw. Lauf am Stück)
        $watching = $runMode ? $active : !$active;
        if ($watching) {
            if ((int)$st['since'] === 0) {
                $st['since'] = $now;
            }
            if ($st['ph'] === 'ok' && $now - (int)$st['since'] >= $dauer) {
                $st['ph'] = 'alarm';
                $what = $runMode ? 'läuft seit ' . self::fmtDuration($now - (int)$st['since']) . ' am Stück' : 'zieht seit ' . self::fmtDuration($now - (int)$st['since']) . ' keinen Strom mehr';
                $res['log'] = $name . ' ' . $what . ' (' . self::fmtNum($p) . ' W).';
                if (!empty($row['af_push'])) {
                    $res['msg'][] = ['🧊 ' . $name, $name . ' ' . $what . ' (' . self::fmtNum($p) . ' W). Bitte nachsehen.'];
                }
            }
        } else {
            if ((int)$st['since'] > 0 && $st['ph'] !== 'alarm') {
                // nur normale Pausen/Läufe als Maßstab — ein echter Ausfall ist kein „bisher längster Wert“
                $len = $now - (int)$st['since'];
                if ($len >= 60) {
                    $st['gaps'][] = $len;
                    $st['gaps'] = array_slice($st['gaps'], -50);
                }
            }
            $st['since'] = 0;
            if ($st['ph'] === 'alarm') {
                $st['ph'] = 'ok';
                $res['log'] = 'Entwarnung: ' . $name . ($runMode ? ' läuft nicht mehr.' : ' zieht wieder Strom.');
                if (!empty($row['af_ende']) && !empty($row['af_push'])) {
                    $res['msg'][] = ['✅ ' . $name, 'Entwarnung: ' . $name . ($runMode ? ' läuft nicht mehr.' : ' zieht wieder Strom (' . self::fmtNum($p) . ' W).')];
                }
            }
        }
        $longest = $st['gaps'] ? max($st['gaps']) : 0;
        $norm = $longest > 0 ? ' · bisher längste' . ($runMode ? 'r Lauf ' : ' Pause ') . self::fmtDuration($longest) : '';
        $res['st'] = $st;
        if ($st['ph'] === 'alarm') {
            $res['status'] = '⚠️ ' . ($runMode ? 'Läuft zu lange' : 'Kein Strom') . ' seit ' . date('d.m.Y H:i', (int)$st['since']) . ' Uhr' . $norm;
        } else {
            if ((int)$st['since'] > 0) {
                $res['due'] = (int)$st['since'] + $dauer;
            }
            $warn = $longest > $dauer ? ' — ⚠️ Grenze liegt unter dem bisher längsten Wert, Fehlalarme möglich' : '';
            $res['status'] = '✅ ' . ($active ? 'Läuft (' . self::fmtNum($p) . ' W)' : 'Aus (' . self::fmtNum($p) . ' W)') . $norm . $warn;
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Zeitschaltuhr
    // ------------------------------------------------------------------

    /** Schaltet die Zeitschaltuhr an diesem Tag? Wochentag, Feiertag (wie Sonntag / gar nicht). */
    public static function zsDay(array $row, string $ymd, array $holidays): bool
    {
        $n = (int)date('N', mktime(12, 0, 0, (int)substr($ymd, 5, 2), (int)substr($ymd, 8, 2), (int)substr($ymd, 0, 4)));
        if (!empty($holidays[$ymd])) {
            $h = (int)($row['zs_feiertag'] ?? 1);
            if ($h === 2) {
                return false;
            }
            if ($h === 1) {
                $n = 7;
            }
        }
        return !empty($row['zs_t' . $n]);
    }

    private static function zeitschalt(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $today = date('Y-m-d', $now);
        $outs = AUTOMATKatalog::vars($row, 'zs_out');
        $modus = (int)($row['zs_modus'] ?? 0);
        $hol = (array)($ctx['holiday'] ?? []);
        $first = !isset($st['on']);
        $st += ['on' => false, 'manual' => '', 'did' => [], 'jd' => '', 'jit' => 0, 'jits' => []];
        // Zufallsversatz je Starttag des Fensters merken: ein Fenster über Mitternacht behält den Versatz seines
        // Abends (vorher wurde um 00:00 neu gewürfelt und das Fenster von gestern verschoben — Prüfbericht 17.10.2026).
        if (!isset($st['jits'][$today])) {
            $st['jits'][$today] = (int)round((float)($ctx['rnd'] ?? 0) * max(0, (int)($row['zs_zufall'] ?? 0)) * 60);
            if ($st['jd'] === self::addDays($today, -1)) {
                $st['jits'][$st['jd']] = $st['jits'][$st['jd']] ?? (int)$st['jit']; // Übernahme aus der alten Form
            }
            $st['jits'] = array_slice($st['jits'], -3, null, true);
        }
        $st['jd'] = $today;
        $st['jit'] = (int)$st['jits'][$today];
        $jitOf = function (string $d) use (&$st): int {
            return (int)($st['jits'][$d] ?? $st['jits'][array_key_last($st['jits'])] ?? 0);
        };
        $jit = (int)$st['jit'];
        $win = function (string $d) use ($row, $jitOf): array {
            $jit = $jitOf($d);
            $on = self::timeOn((string)($row['zs_ein'] ?? AUTOMATKatalog::time(7, 0)), $d) + $jit;
            $off = self::timeOn((string)($row['zs_aus'] ?? AUTOMATKatalog::time(8, 0)), $d) + $jit;
            if ($off <= $on) {
                $off = self::timeOn((string)($row['zs_aus'] ?? AUTOMATKatalog::time(8, 0)), self::addDays($d, 1)) + $jit;
            }
            return [$on, $off];
        };
        $hard = self::hardBlock($ctx);
        $soft = self::softBlock($row, $ctx);
        $pct = (int)($row['zs_wert'] ?? 100);
        $setAll = function (bool $on) use ($outs, $ctx, $pct, &$res) {
            foreach ($outs as $vid) {
                if (self::isTrue($ctx['val'][$vid] ?? !$on) !== $on) {
                    $res['cmd'][] = [$vid, $on ? self::onValue($vid, $pct, $ctx) : self::offValue($vid, $ctx)];
                }
            }
        };
        $next = null;
        if ($modus === 0) {
            $key = null;
            foreach ([self::addDays($today, -1), $today] as $d) {
                [$a, $b] = $win($d);
                if (self::zsDay($row, $d, $hol) && $now >= $a && $now < $b) {
                    $key = $d;
                }
            }
            if (($ev['k'] ?? '') === 'output' && empty($ev['own']) && in_array((int)($ev['vid'] ?? 0), $outs, true)
                && $key !== null && $st['on'] && !self::isTrue($ev['v'] ?? false)) {
                $st['on'] = false;
                $st['manual'] = $key;
                $res['log'] = 'Von Hand ausgeschaltet — bleibt aus bis zum nächsten Schaltzeitpunkt.';
            } elseif (($ev['k'] ?? '') === 'output' && empty($ev['own']) && in_array((int)($ev['vid'] ?? 0), $outs, true)
                && $key !== null && !$st['on'] && self::isTrue($ev['v'] ?? false)) {
                // Im Fenster von Hand wieder eingeschaltet: am Fensterende wieder normal ausschalten
                $st['on'] = true;
                $st['manual'] = '';
            }
            if ($first) {
                $st['on'] = $key !== null; // beim Einrichten nichts nachholen, nur den Zustand übernehmen
            } elseif ($hard === '') {
                // Eine weiche Sperre (Hausmodus, Bedingung) verhindert nur das Einschalten — laufende Zeiten enden normal
                if ($key !== null && !$st['on'] && $st['manual'] !== $key && $soft === '') {
                    $setAll(true);
                    $st['on'] = true;
                    $res['log'] = 'Schaltzeit — ein.';
                } elseif ($key === null && $st['on']) {
                    $setAll(false);
                    $st['on'] = false;
                    $res['log'] = 'Schaltzeit — aus.';
                }
            }
            foreach ([self::addDays($today, -1), $today, self::addDays($today, 1)] as $d) {
                if (self::zsDay($row, $d, $hol)) {
                    foreach ($win($d) as $t) {
                        if ($t > $now && ($next === null || $t < $next)) {
                            $next = $t;
                        }
                    }
                }
            }
        } else {
            // nur aus (1) bzw. nur ein (2): einmal je Tag zum Zeitpunkt, nicht Stunden später nachholen
            $want = $modus === 2;
            [$a, $b] = $win($today);
            $t = $want ? $a : self::timeOn((string)($row['zs_aus'] ?? AUTOMATKatalog::time(8, 0)), $today) + $jit;
            if ($first) {
                $st['did'][$today] = $now >= $t;
            } elseif (self::zsDay($row, $today, $hol) && $now >= $t && $now - $t < 3600 && empty($st['did'][$today]) && $hard === '' && $soft === '') {
                $setAll($want);
                $res['log'] = 'Schaltzeit — ' . ($want ? 'ein.' : 'aus.');
                $st['did'][$today] = true;
            }
            $st['did'] = array_slice((array)$st['did'], -3, null, true);
            foreach ([$today, self::addDays($today, 1)] as $d) {
                $tt = $want ? $win($d)[0] : self::timeOn((string)($row['zs_aus'] ?? AUTOMATKatalog::time(8, 0)), $d) + $jit;
                if ($tt > $now && self::zsDay($row, $d, $hol) && ($next === null || $tt < $next)) {
                    $next = $tt;
                }
            }
        }
        $res['st'] = $st;
        $res['due'] = $next !== null ? min($next, $now + 3600) : $now + 3600;
        $hn = !empty($hol[$today]) ? ' (heute Feiertag: ' . $hol[$today] . ')' : '';
        if ($hard !== '') {
            $res['status'] = self::hardStatus($hard);
        } elseif ($modus === 0 && $st['on']) {
            $res['status'] = '💡 Ein' . ($next !== null ? ' — aus um ' . self::fmtClock($next, $now) : '') . $hn;
        } else {
            $res['status'] = ($soft !== '' ? '🌙 ' . $soft . ' — ' : '⏰ ') . ($next !== null ? 'Nächste Schaltung ' . self::fmtClock($next, $now) : 'Keine Schaltung in den nächsten zwei Tagen') . $hn;
        }
        return $res;
    }

    /**
     * Gewohnheit aus dem Archiv: an welchen Werktagsabenden geht ein Licht typischerweise aus?
     * $events: [[ts, an(bool)], …] aufsteigend. Rückgabe ['min' => Minuten nach Mitternacht (20:00–03:00 → >1440 möglich), 'days' => n, 'hits' => n] oder null.
     */
    public static function habitOff(array $events, int $minDays = 8): ?array
    {
        $byDay = [];
        $prev = null;
        foreach ($events as [$ts, $on]) {
            $ts = (int)$ts;
            if ($prev === true && !$on) {
                $h = (int)date('G', $ts);
                if ($h >= 20 || $h < 3) {
                    // Abend zuordnen: nach Mitternacht zählt zum Vortag
                    $evening = $h < 3 ? strtotime('-1 day', $ts) : $ts;
                    if ((int)date('N', $evening) <= 5) {
                        $min = (int)date('G', $ts) * 60 + (int)date('i', $ts) + ($h < 3 ? 1440 : 0);
                        $byDay[date('Y-m-d', $evening)] = $min; // letztes Aus des Abends
                    }
                }
            }
            $prev = (bool)$on;
        }
        if (count($byDay) < $minDays) {
            return null;
        }
        $vals = array_values($byDay);
        sort($vals);
        $med = $vals[intdiv(count($vals), 2)];
        $hits = count(array_filter($vals, function ($v) use ($med) {
            return abs($v - $med) <= 30;
        }));
        if ($hits < 0.6 * count($vals)) {
            return null;
        }
        return ['min' => (int)(round($med / 5) * 5), 'days' => count($vals), 'hits' => $hits];
    }

    // ------------------------------------------------------------------
    // Gemeinsam für die Typen ab 0.1.31
    // ------------------------------------------------------------------

    /**
     * Ist dieser Kontakt umgekehrt (wahr = zu)? Erkennt Automat die Richtung selbst (Matter-Kontakt, Profil
     * „…Reversed“, Darstellung „geschlossen“ bei wahr), gilt das — sonst das Häkchen im Automatismus. So meldet
     * derselbe Sensor in jedem Automatismus dasselbe, auch gemischt mit anderen Kontakten.
     */
    public static function contactInvert(int $vid, array $ctx, bool $rowInvert): bool
    {
        return !empty($ctx['inv'][$vid]) ? true : $rowInvert;
    }

    /** Namen der Variablen, deren Wert „aktiv“ ist (bei $invert umgekehrt). Fehlende Werte zählen nicht. */
    private static function activeOf(array $vids, array $ctx, bool $invert): array
    {
        $out = [];
        foreach ($vids as $vid) {
            if (!array_key_exists($vid, $ctx['val'] ?? [])) {
                continue;
            }
            if (self::isTrue($ctx['val'][$vid]) !== self::contactInvert($vid, $ctx, $invert)) {
                $out[] = (string)($ctx['names'][$vid] ?? ('#' . $vid));
            }
        }
        return $out;
    }

    private static function nameOf(int $vid, array $ctx): string
    {
        return (string)($ctx['names'][$vid] ?? ('#' . $vid));
    }

    // ------------------------------------------------------------------
    // Wasser-/Rauchmelder-Reaktion
    // ------------------------------------------------------------------

    /**
     * Sicherheitsfunktion: Pause und Hausmodus halten sie NICHT auf (nur die ausdrückliche Sperre).
     * Meldungen gehen auch in der Ruhezeit sofort raus ($res['urgent']). Nach dem Alarm wird nichts
     * zurückgeschaltet — ein geschlossenes Ventil öffnet nur der Mensch.
     */
    private static function alarm(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $res['urgent'] = true;
        $st += ['ph' => 'ok', 'since' => 0, 'last' => 0, 'who' => []];
        $name = trim((string)($row['Name'] ?? '')) ?: 'Alarm';
        if (!empty($ctx['locked'])) {
            $res['st'] = $st;
            $res['status'] = '⏸ Gesperrt — reagiert nicht auf Alarm';
            return $res;
        }
        $who = self::activeOf(AUTOMATKatalog::vars($row, 'al_mel'), $ctx, !empty($row['al_invert']));
        $wdh = max(0, (int)($row['al_wdh'] ?? 10)) * 60;
        if (count($who) > 0 && $st['ph'] === 'ok') {
            $st['ph'] = 'alarm';
            $st['since'] = $now;
            $st['last'] = $now;
            $did = [];
            foreach (AUTOMATKatalog::vars($row, 'al_licht') as $vid) {
                $res['cmd'][] = [$vid, self::onValue($vid, 100, $ctx)];
                $did['Licht an'][] = self::nameOf($vid, $ctx);
            }
            foreach (AUTOMATKatalog::vars($row, 'al_hoch') as $vid) {
                // Steuert ein Rollladen-Automatismus denselben Rollladen, gilt dessen „offen“-Wert (täglich erprobt) —
                // ein falsch herum eingetragener Wert würde beim Rauchalarm sonst den Fluchtweg schließen.
                $res['cmd'][] = [$vid, array_key_exists($vid, $ctx['rlOpen'] ?? []) ? (float)$ctx['rlOpen'][$vid] : (float)($row['al_hochwert'] ?? 0)];
                $did['Rollladen auf'][] = self::nameOf($vid, $ctx);
            }
            foreach (AUTOMATKatalog::vars($row, 'al_aus') as $vid) {
                $res['cmd'][] = [$vid, self::offValue($vid, $ctx)];
                $did['aus'][] = self::nameOf($vid, $ctx);
            }
            $what = [];
            foreach ($did as $k => $l) {
                $what[] = $k . ': ' . implode(', ', $l);
            }
            $res['log'] = '🚨 Alarm von ' . implode(', ', $who) . ($what ? ' — ' . implode('; ', $what) : '') . '.';
            if (!empty($row['al_push'])) {
                $res['msg'][] = ['🚨 ' . $name, 'ALARM: ' . implode(', ', $who) . ' (' . date('H:i', $now) . ' Uhr)' . ($what ? '. ' . implode('; ', $what) : '') . '.'];
            }
        } elseif (count($who) > 0) {
            if ($wdh > 0 && $now >= (int)$st['last'] + $wdh) {
                $st['last'] = $now;
                if (!empty($row['al_push'])) {
                    $res['msg'][] = ['🚨 ' . $name . ' (Erinnerung)', 'Alarm besteht weiter seit ' . date('H:i', (int)$st['since']) . ' Uhr: ' . implode(', ', $who) . '.'];
                }
            }
            if ($wdh > 0) {
                $res['due'] = (int)$st['last'] + $wdh;
            }
        } elseif ($st['ph'] === 'alarm') {
            $st['ph'] = 'ok';
            $res['log'] = '✅ Entwarnung — kein Melder meldet mehr Alarm. Geschaltetes bleibt so (bitte selbst zurückstellen).';
            if (!empty($row['al_ende']) && !empty($row['al_push'])) {
                $res['msg'][] = ['✅ ' . $name, 'Entwarnung: kein Melder meldet mehr Alarm (' . date('H:i', $now) . ' Uhr). Ventile und Geräte bitte selbst wieder einschalten.'];
            }
        }
        $st['who'] = $who;
        $res['st'] = $st;
        $res['status'] = $st['ph'] === 'alarm'
            ? '🚨 Alarm seit ' . date('H:i', (int)$st['since']) . ' Uhr — ' . implode(', ', $who)
            : '✅ Kein Alarm';
        return $res;
    }

    // ------------------------------------------------------------------
    // Rollladen nach Zeit/Sonne
    // ------------------------------------------------------------------

    private static function rollladen(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $today = date('Y-m-d', $now);
        $outs = AUTOMATKatalog::vars($row, 'rl_out');
        $we = (int)date('N', $now) >= 6 || !empty($ctx['holiday'][$today]); // Feiertag wie Wochenende
        $sun = $ctx['sun'][$today] ?? ['sr' => null, 'ss' => null];
        $noon = self::timeOn(AUTOMATKatalog::time(12, 0), $today);
        $mode = (int)($ctx['mode'] ?? 0);
        $first = !isset($st['up']);
        $st += ['up' => '', 'down' => '', 'wait' => false, 'waitDay' => '', 'chk' => null];
        $yesterday = self::addDays($today, -1);

        // Öffnen
        $upRef = (int)($row['rl_upref'] ?? 1);
        $earliest = self::timeOn((string)($row[$we ? 'rl_fruehwe' : 'rl_frueh'] ?? AUTOMATKatalog::time(7, 0)), $today);
        $upTs = $upRef === 1 && $sun['sr'] !== null ? (int)$sun['sr'] + (int)($row['rl_upoff'] ?? 0) * 60 : self::timeOn((string)($row['rl_upzeit'] ?? AUTOMATKatalog::time(7, 0)), $today);
        $upAt = max($upTs, $earliest);
        $upReady = $upRef === 2 ? ($mode === 0 && $now >= $earliest) : $now >= $upAt;

        // Schließen
        $downRef = (int)($row['rl_downref'] ?? 1);
        $latest = self::timeOn((string)($row['rl_spaet'] ?? AUTOMATKatalog::time(22, 30)), $today);
        $downTs = $downRef === 1 && $sun['ss'] !== null ? (int)$sun['ss'] + (int)($row['rl_downoff'] ?? 0) * 60 : self::timeOn((string)($row['rl_downzeit'] ?? AUTOMATKatalog::time(21, 0)), $today);
        if ($downRef === 2) {
            // Zentraler Helligkeitssensor („🏠 Hausmodus“: Schwellen und Haltezeit dort). Nur nachmittags — morgens ist es auch dunkel.
            $downReady = ($now >= $noon + 7200 && ($ctx['dark'] ?? null) === true) || $now >= $latest;
        } elseif ($downRef === 3) {
            $downReady = ($now >= $noon && $mode === AUTOMATKatalog::MODE_NIGHT) || $now >= $latest;
        } else {
            $downReady = $now >= min($downTs, $latest);
        }

        if ($first) {
            // Beim ersten Lauf nichts nachholen (sonst führe ein mittags eingerichteter Rollladen sofort hoch).
            $st['up'] = $upReady ? $today : '';
            $st['down'] = $downReady ? $today : '';
        }
        $hard = self::hardBlock($ctx);
        $soft = self::softBlock($row, $ctx);
        $open = self::activeOf(AUTOMATKatalog::vars($row, 'rl_tuer'), $ctx, !empty($row['rl_tuerinvert']));
        // Schaltet und sagt, wer wirklich einen Befehl bekam und wer schon so stand — „Rollläden geschlossen“ war
        // vorher auch dann im Protokoll, wenn gar nichts gesendet wurde (Prüfbericht 17.10.2026).
        $set = function (float $v, string $verb) use ($outs, $ctx, &$res, &$st, $now): string {
            $sent = [];
            $already = [];
            foreach ($outs as $vid) {
                $cur = $ctx['val'][$vid] ?? null;
                if ($cur === null || abs((float)$cur - $v) > 0.5) {
                    $res['cmd'][] = [$vid, $v];
                    $sent[] = self::nameOf($vid, $ctx);
                } else {
                    $already[] = self::nameOf($vid, $ctx);
                }
            }
            // Nachkontrolle in 3 Minuten: steht die Position dann nicht am Ziel, einmal erneut senden
            $st['chk'] = count($sent) > 0 ? ['at' => $now + 180, 'v' => $v, 'n' => 0] : null;
            return 'Rollläden ' . $verb . (count($sent) > 0 ? ': ' . implode(', ', $sent) : '') . (count($already) > 0 ? (count($sent) > 0 ? ' — ' : ': ') . implode(', ', $already) . ' stand' . (count($already) > 1 ? 'en' : '') . ' schon so' : '') . '.';
        };
        // Aussperrschutz von gestern Abend: nach Mitternacht noch schließen, sobald die Tür zu ist — aber nicht mehr,
        // wenn morgens das Öffnen ansteht. Vorher blieb „wartet“ für immer stehen (Status falsch, nächste Meldung fehlte).
        if ($st['wait'] && $st['waitDay'] !== $today) {
            if ($st['waitDay'] !== $yesterday || $upReady || $now >= $noon) {
                $st['wait'] = false;
            }
        }
        if ($hard === '' && $soft === '' && $st['wait'] && $st['waitDay'] === $yesterday && count($open) === 0) {
            $res['log'] = 'Tür jetzt zu — ' . $set((float)($row['rl_zu'] ?? 100), 'geschlossen');
            $st['wait'] = false;
            $st['down'] = $yesterday; // gehört zur Nacht davor — morgens wird normal geöffnet
        }
        $chkOff = [];
        if (is_array($st['chk'])) {
            foreach ($outs as $vid) {
                $cur = $ctx['val'][$vid] ?? null;
                if ($cur !== null && abs((float)$cur - (float)$st['chk']['v']) > 2.0) {
                    $chkOff[] = $vid;
                }
            }
            if (count($chkOff) === 0) {
                $st['chk'] = null; // alle am Ziel
            }
        }
        if ($hard === '' && $soft === '' && is_array($st['chk']) && $now >= (int)$st['chk']['at']) {
            $want = (float)$st['chk']['v'];
            $off = $chkOff;
            if ((int)$st['chk']['n'] === 0) {
                foreach ($off as $vid) {
                    $res['cmd'][] = [$vid, $want];
                }
                $res['log'] = 'Nachkontrolle: ' . implode(', ', array_map(function ($v) use ($ctx) {
                    return self::nameOf($v, $ctx);
                }, $off)) . ' steht nicht am Ziel — Befehl erneut gesendet.';
                $st['chk'] = ['at' => $now + 180, 'v' => $want, 'n' => 1];
            } else {
                $res['log'] = '⚠️ ' . implode(', ', array_map(function ($v) use ($ctx) {
                    return self::nameOf($v, $ctx);
                }, $off)) . ' hat auch auf den zweiten Befehl nicht reagiert.';
                $res['fault'] = $res['log'];
                $st['chk'] = null;
            }
        }
        if ($hard === '' && $soft === '') {
            if ($downReady && $st['down'] !== $today) {
                if (count($open) > 0) {
                    if (!$st['wait']) {
                        $res['log'] = 'Schließen aufgehalten — offen: ' . implode(', ', $open) . ' (Aussperrschutz). Schließt, sobald zu.';
                        if (!empty($row['rl_push'])) {
                            $res['msg'][] = ['🪟 ' . (trim((string)($row['Name'] ?? '')) ?: 'Rollladen'), 'Bleibt oben, weil offen: ' . implode(', ', $open) . '. Schließt, sobald zu.'];
                        }
                    }
                    $st['wait'] = true;
                    $st['waitDay'] = $today;
                } else {
                    $res['log'] = ($st['wait'] ? 'Tür jetzt zu — ' : '') . $set((float)($row['rl_zu'] ?? 100), 'geschlossen');
                    $st['down'] = $today;
                    $st['wait'] = false;
                    $st['up'] = $today; // heute nicht mehr öffnen
                }
            } elseif ($upReady && $st['up'] !== $today && !$downReady) {
                $res['log'] = $set((float)($row['rl_auf'] ?? 0), 'geöffnet');
                $st['up'] = $today;
            }
        }
        $res['st'] = $st;
        if ($hard !== '') {
            $res['status'] = self::hardStatus($hard);
        } elseif ($st['wait']) {
            $res['status'] = '⏳ Bleibt oben — offen: ' . implode(', ', $open);
            $res['due'] = $now + 60;
        } elseif (is_array($st['chk'])) {
            $res['status'] = '⏳ Befehl gesendet — Nachkontrolle um ' . date('H:i', (int)$st['chk']['at']) . ' Uhr';
            $res['due'] = (int)$st['chk']['at'];
        } elseif ($soft !== '') {
            $res['status'] = '🌙 Pausiert: ' . $soft;
        } elseif ($st['down'] === $today) {
            $res['status'] = '⬇️ Zu-Befehl gesendet (heute Abend) — öffnet morgen früh';
        } elseif ($st['up'] === $today) {
            $res['status'] = '⬆️ Offen' . (in_array($downRef, [0, 1], true) ? ' — schließt um ' . self::fmtClock(min($downTs, $latest), $now) : ' — schließt bei ' . ($downRef === 2 ? 'Dunkelheit' : '„Nacht“') . ', spätestens ' . date('H:i', $latest) . ' Uhr')
                . ($downRef === 2 && ($ctx['dark'] ?? null) === null ? ' (⚠️ kein Helligkeitssensor unter „🏠 Hausmodus“ — nur „spätestens“ gilt)' : '');
            $res['due'] = in_array($downRef, [0, 1], true) ? min($downTs, $latest) : $now + 60;
        } else {
            $res['status'] = '⬇️ Zu — öffnet ' . ($upRef === 2 ? 'bei „Tag“, frühestens ' . date('H:i', $earliest) . ' Uhr' : 'um ' . self::fmtClock($upAt, $now));
            $res['due'] = $upRef === 2 ? $now + 60 : $upAt;
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Standby-Abschaltung
    // ------------------------------------------------------------------

    private static function standby(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $pv = (int)($row['sb_power'] ?? 0);
        $ov = (int)($row['sb_out'] ?? 0);
        $st += ['below' => 0];
        $p = array_key_exists($pv, $ctx['val'] ?? []) ? (float)$ctx['val'][$pv] : null;
        $on = self::isTrue($ctx['val'][$ov] ?? false);
        $schwelle = (float)($row['sb_schwelle'] ?? 5);
        $dauer = max(1, (int)($row['sb_dauer'] ?? 30)) * 60;
        $hard = self::hardBlock($ctx);
        if ($hard !== '') {
            $st['below'] = 0;
            $res['st'] = $st;
            $res['status'] = self::hardStatus($hard);
            return $res;
        }
        if ($p === null) {
            $res['st'] = $st;
            $res['status'] = '❔ Leistung unbekannt';
            return $res;
        }
        if (!$on) {
            $st['below'] = 0;
            $res['status'] = '⚪ Aus';
        } elseif ($p >= $schwelle) {
            $st['below'] = 0;
            $res['status'] = '▶️ In Betrieb (' . self::fmtNum($p) . ' W)';
        } else {
            if ((int)$st['below'] === 0) {
                $st['below'] = $now;
            }
            $at = (int)$st['below'] + $dauer;
            $soft = self::softBlock($row, $ctx);
            if ($now >= $at && $soft === '') {
                $res['cmd'][] = [$ov, self::offValue($ov, $ctx)];
                $res['log'] = 'Seit ' . self::fmtDuration($now - (int)$st['below']) . ' im Standby (' . self::fmtNum($p) . ' W) — ausgeschaltet.';
                if (!empty($row['sb_push'])) {
                    $res['msg'][] = ['🔌 ' . (trim((string)($row['Name'] ?? '')) ?: self::nameOf($ov, $ctx)), 'War ' . self::fmtDuration($now - (int)$st['below']) . ' im Standby — ausgeschaltet.'];
                }
                $st['below'] = 0;
                $res['status'] = '⚪ Ausgeschaltet (Standby)';
            } else {
                $res['due'] = $at;
                $res['end'] = $at;
                $res['status'] = '💤 Standby (' . self::fmtNum($p) . ' W)' . ($soft !== '' ? ' — schaltet nicht ab: ' . $soft : ' — aus um ' . self::fmtClock($at, $now));
            }
        }
        $res['st'] = $st;
        return $res;
    }

    // ------------------------------------------------------------------
    // Lüftungserinnerung
    // ------------------------------------------------------------------

    private static function lueften(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $vid = (int)($row['lf_var'] ?? 0);
        $v = array_key_exists($vid, $ctx['val'] ?? []) ? (float)$ctx['val'][$vid] : null;
        $hoch = (float)($row['lf_hoch'] ?? 65);
        $ok = (float)($row['lf_ok'] ?? 55);
        $dauer = max(0, (int)($row['lf_dauer'] ?? 10)) * 60;
        $wdh = max(0, (int)($row['lf_wdh'] ?? 30)) * 60;
        $kon = AUTOMATKatalog::vars($row, 'lf_kon');
        $open = self::activeOf($kon, $ctx, !empty($row['lf_konInvert']));
        $name = trim((string)($row['Name'] ?? '')) ?: 'Lüften';
        $st += ['ph' => 'ok', 'since' => 0, 'last' => 0];
        $hard = self::hardBlock($ctx);
        if ($hard !== '' || $v === null) {
            $res['st'] = $st;
            $res['status'] = $hard !== '' ? self::hardStatus($hard) : '❔ Messwert fehlt';
            return $res;
        }
        $soft = self::softBlock($row, $ctx);
        $txt = self::fmtNum($v) . ' (' . self::nameOf($vid, $ctx) . ')';
        if ($st['ph'] === 'ok' && $v >= $hoch) {
            $st['ph'] = 'steigt';
            $st['since'] = $now;
        }
        if ($st['ph'] === 'steigt') {
            if ($v < $hoch) {
                $st['ph'] = 'ok';
            } elseif ($now >= (int)$st['since'] + $dauer) {
                $st['ph'] = 'hoch';
                $st['last'] = $now;
                $st['told'] = false;
            }
        }
        if ($st['ph'] === 'hoch' && empty($st['told']) && count($open) === 0 && $soft === '' && $v >= $ok) {
            // Erste Erinnerung — auch nachträglich, wenn sie beim Überschreiten wegen Sperre/Hausmodus nicht ging
            $st['told'] = true;
            $st['last'] = $now;
            $res['log'] = 'Luft zu schlecht: ' . $txt . ' — Erinnerung zum Lüften.';
            $res['msg'][] = ['💨 ' . $name, 'Bitte lüften: ' . $txt . '.'];
        } elseif ($st['ph'] === 'hoch') {
            if ($v < $ok) {
                $st['ph'] = 'ok';
                if (count($kon) === 0 || count($open) > 0) {
                    $msg = count($open) > 0 ? 'Luft wieder gut (' . self::fmtNum($v) . ') — ' . implode(', ', $open) . ' kann wieder zu.' : 'Luft wieder gut (' . self::fmtNum($v) . ').';
                    $res['log'] = $msg;
                    if ($soft === '') {
                        $res['msg'][] = ['✅ ' . $name, $msg];
                    }
                }
            } elseif (count($open) === 0 && $wdh > 0 && $now >= (int)$st['last'] + $wdh) {
                $st['last'] = $now;
                if ($soft === '') {
                    $res['msg'][] = ['💨 ' . $name . ' (Erinnerung)', 'Immer noch zu hoch: ' . $txt . '.'];
                }
            }
        }
        $res['st'] = $st;
        switch ($st['ph']) {
            case 'steigt':
                $res['due'] = (int)$st['since'] + $dauer;
                $res['status'] = '⏳ Zu hoch: ' . self::fmtNum($v) . ' — Erinnerung um ' . self::fmtClock((int)$st['since'] + $dauer, $now);
                break;
            case 'hoch':
                $res['status'] = count($open) > 0 ? '🪟 Wird gelüftet: ' . self::fmtNum($v) . ' (gut unter ' . self::fmtNum($ok) . ')' : '💨 Bitte lüften: ' . self::fmtNum($v);
                if (count($open) === 0 && $wdh > 0) {
                    $res['due'] = (int)$st['last'] + $wdh;
                }
                break;
            default:
                $res['status'] = '✅ Luft gut: ' . self::fmtNum($v);
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Heizung nach Hausmodus
    // ------------------------------------------------------------------

    /**
     * Wie „Fenster offen“: Sollwert merken, absenken, beim Zurückkommen wiederherstellen. Wer während
     * der Absenkung selbst einen Sollwert ändert, behält ihn — er wird nicht überschrieben und nicht
     * „zurückgestellt“.
     */
    private static function heizung(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $mode = (int)($ctx['mode'] ?? 0);
        $soll = AUTOMATKatalog::vars($row, 'hz_soll');
        $st += ['saved' => [], 'set' => [], 'hand' => [], 'mode' => -1];
        if ((int)$st['mode'] !== $mode) {
            $st['hand'] = [];
            $st['mode'] = $mode;
        }
        $target = null;
        foreach ([3 => 'hz_abw', 4 => 'hz_url', 2 => 'hz_nacht'] as $m => $f) {
            if ($mode === $m && !empty($row['hz_m' . $m])) {
                $target = (float)($row[$f] ?? 17);
            }
        }
        $hard = self::hardBlock($ctx);
        if ($hard !== '' || (isset($ctx['condOk']) && !$ctx['condOk'])) {
            $target = null;
        }
        $saved = (array)$st['saved'];
        $set = (array)$st['set'];
        $logs = [];
        // Handbedienung nur an einer echten fremden Änderung erkennen (nicht am Tick: Funk-Thermostate melden den
        // neuen Wert oft erst nach Minuten, andere Automatismen schalten mit „eigenem“ Echo). Prüfbericht 17.10.2026.
        if (($ev['k'] ?? '') === 'output' && empty($ev['own']) && in_array((int)($ev['vid'] ?? 0), $soll, true)) {
            $hk = (string)(int)$ev['vid'];
            if (isset($set[$hk]) && abs((float)($ev['v'] ?? 0) - (float)$set[$hk]) > 0.05) {
                unset($set[$hk]);
                $st['hand'][$hk] = true; // Grundwert ($saved) bleibt gemerkt — gilt beim Heimkommen
                $logs[] = self::nameOf((int)$ev['vid'], $ctx) . ' von Hand auf ' . self::fmtNum((float)($ev['v'] ?? 0)) . ' °C gestellt — bleibt so';
            }
        }
        foreach ($soll as $vid) {
            $k = (string)$vid;
            $cur = array_key_exists($vid, $ctx['val'] ?? []) ? (float)$ctx['val'][$vid] : null;
            if ($cur === null) {
                continue;
            }
            if ($target !== null) {
                if (!empty($st['hand'][$k])) {
                    continue;
                }
                if (!empty($ctx['held'][$vid]) && !isset($set[$k])) {
                    // „Fenster offen“ hält dieses Thermostat gerade abgesenkt: nicht dazwischenfahren. Ist das Fenster
                    // zu, stellt es seinen Wert zurück, dann senkt der nächste Durchlauf hier ab.
                    continue;
                }
                if (!isset($saved[$k])) {
                    $saved[$k] = $cur;
                }
                if ((!isset($set[$k]) || abs((float)$set[$k] - $target) > 0.05) && abs($cur - $target) > 0.05) {
                    $res['cmd'][] = [$vid, $target];
                }
                $set[$k] = $target;
            } elseif (isset($saved[$k])) {
                if (!empty($ctx['held'][$vid])) {
                    continue; // Fenster noch offen: zurückstellen, wenn es zu ist
                }
                if (isset($set[$k]) && abs($cur - (float)$set[$k]) <= 0.05 && abs($cur - (float)$saved[$k]) > 0.05) {
                    $res['cmd'][] = [$vid, (float)$saved[$k]];
                    $logs[] = self::nameOf($vid, $ctx) . ' zurück auf ' . self::fmtNum((float)$saved[$k]) . ' °C';
                }
                unset($saved[$k], $set[$k]);
            }
        }
        $lowered = $target !== null && count($res['cmd']) > 0;
        if ($lowered) {
            $logs[] = count($res['cmd']) . ' Sollwert(e) auf ' . self::fmtNum($target) . ' °C abgesenkt („' . AUTOMATKatalog::MODES[$mode] . '“)';
        }
        if ($logs) {
            $res['log'] = implode('; ', $logs) . '.';
        }
        $st['saved'] = $saved;
        $st['set'] = $set;
        $res['st'] = $st;
        if ($hard !== '') {
            $res['status'] = self::hardStatus($hard);
        } elseif ($target !== null && count($set) > 0) {
            $res['status'] = '🌡 Abgesenkt auf ' . self::fmtNum($target) . ' °C („' . AUTOMATKatalog::MODES[$mode] . '“)';
        } else {
            $res['status'] = '✅ Normalbetrieb';
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Taster-Zuordnung
    // ------------------------------------------------------------------

    private static function taster(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $in = (int)($row['tg_in'] ?? 0);
        $st += ['last' => ''];
        $res['st'] = $st;
        $res['status'] = $st['last'] !== '' ? '🔘 Zuletzt: ' . $st['last'] : '🔘 Wartet auf einen Tastendruck';
        if (($ev['k'] ?? '') !== 'trigger' || (int)($ev['vid'] ?? 0) !== $in) {
            return $res;
        }
        $key = mb_strtolower(self::valueKey($ev['v'] ?? ''));
        $map = ['wahr' => 'true', 'falsch' => 'false', 'an' => 'true', 'aus' => 'false'];
        $wants = [];
        foreach (['k', 'd', 'l'] as $p) {
            $wants[$p] = array_map(function ($x) use ($map) {
                $x = mb_strtolower(trim($x));
                return $map[$x] ?? mb_strtolower(self::valueKey($x));
            }, explode(',', (string)($row['tg_' . $p . 'wert'] ?? '')));
        }
        // „falsch“/0 ist meist das Loslassen — außer, der Wert ist ausdrücklich einer Druckart zugeordnet
        if ($key === '' || (($key === 'false' || $key === '0') && !in_array($key, array_merge(...array_values($wants)), true))) {
            return $res; // Loslassen bzw. Rücksetzen
        }
        foreach (['k' => 'kurz', 'd' => 'doppelt', 'l' => 'lang'] as $p => $label) {
            $want = $wants[$p];
            if (!in_array($key, $want, true)) {
                continue;
            }
            $akt = (int)($row['tg_' . $p . 'akt'] ?? 0);
            $hard = self::hardBlock($ctx);
            $soft = self::softBlock($row, $ctx);
            if ($akt === 0) {
                return $res;
            }
            if ($hard !== '' || $soft !== '') {
                $res['log'] = ucfirst($label) . ' gedrückt — nichts getan: ' . ($hard ?: $soft) . '.';
                return $res;
            }
            $z = (int)($row['tg_' . $p . 'ziel'] ?? 0);
            $zw = (float)($row['tg_' . $p . 'zwert'] ?? 0);
            switch ($akt) {
                case 1:
                    $isOn = self::isTrue($ctx['val'][$z] ?? false);
                    $res['cmd'][] = [$z, $isOn ? self::offValue($z, $ctx) : self::onValue($z, 100, $ctx)];
                    $did = self::nameOf($z, $ctx) . ($isOn ? ' aus' : ' an');
                    break;
                case 2:
                    $res['cmd'][] = [$z, self::onValue($z, 100, $ctx)];
                    $did = self::nameOf($z, $ctx) . ' an';
                    break;
                case 3:
                    $res['cmd'][] = [$z, self::offValue($z, $ctx)];
                    $did = self::nameOf($z, $ctx) . ' aus';
                    break;
                case 4:
                    $res['cmd'][] = [$z, $zw];
                    $did = self::nameOf($z, $ctx) . ' auf ' . self::fmtNum($zw);
                    break;
                default:
                    $m = max(0, min(4, (int)round($zw)));
                    $res['mode'] = $m;
                    $did = 'Hausmodus „' . AUTOMATKatalog::MODES[$m] . '“';
            }
            $st['last'] = $label . ' → ' . $did . ' (' . date('H:i', $now) . ')';
            $res['st'] = $st;
            $res['log'] = ucfirst($label) . ' gedrückt — ' . $did . '.';
            $res['status'] = '🔘 Zuletzt: ' . $st['last'];
            return $res;
        }
        $res['log'] = 'Unbekannter Tasterwert „' . (string)($ev['v'] ?? '') . '“ — im Dialog bei kurz/doppelt/lang eintragen.';
        return $res;
    }

    // ------------------------------------------------------------------
    // Aufwach-/Einschlaflicht
    // ------------------------------------------------------------------

    /**
     * Weckzeit heute aus Variable oder null. Ein Zeitstempel mit Datum in der Zukunft ist der nächste Wecker und
     * gilt nur an seinem Tag — ein Wecker für Montag startete sonst schon am Samstag (Prüfbericht 17.10.2026). Liegt
     * das Datum heute oder zurück (Variable nur für die Uhrzeit, z. B. ~UnixTimestampTime), gilt die Uhrzeit täglich.
     * „HH:MM“ gilt jeden Tag.
     */
    public static function wakeTime($v, string $ymd): ?int
    {
        [$y, $mo, $d] = array_map('intval', explode('-', $ymd));
        if ((is_int($v) || is_float($v) || (is_string($v) && ctype_digit($v))) && (int)$v > 86400) {
            if (date('Y-m-d', (int)$v) > $ymd) {
                return null;
            }
            return mktime((int)date('G', (int)$v), (int)date('i', (int)$v), 0, $mo, $d, $y);
        }
        if (is_string($v) && preg_match('/^(\d{1,2}):(\d{2})/', trim($v), $m)) {
            return mktime((int)$m[1], (int)$m[2], 0, $mo, $d, $y);
        }
        return null;
    }

    private static function wecklicht(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $today = date('Y-m-d', $now);
        $outs = AUTOMATKatalog::vars($row, 'wl_out');
        $up = (int)($row['wl_richtung'] ?? 0) === 0;
        $start = (int)($row['wl_start'] ?? 0);
        $dauer = max(1, (int)($row['wl_dauer'] ?? 20)) * 60;
        $ziel = max(1, min(100, (int)($row['wl_ziel'] ?? 80)));
        $st += ['ph' => 'idle', 't0' => 0, 't1' => 0, 'from' => 0, 'day' => ''];
        $hard = self::hardBlock($ctx);
        if ($hard !== '') {
            if ($st['ph'] === 'ramp') {
                $res['log'] = 'Rampe abgebrochen: ' . $hard . '.';
            }
            $st['ph'] = 'idle';
            $res['st'] = $st;
            $res['status'] = self::hardStatus($hard);
            return $res;
        }
        if ($st['ph'] === 'ramp' && ($ev['k'] ?? '') === 'output' && empty($ev['own']) && in_array((int)($ev['vid'] ?? 0), $outs, true)) {
            $st['ph'] = 'idle';
            $res['log'] = 'Licht von Hand verstellt — Rampe beendet.';
        }
        // Wann heute?
        $at = null;
        if ($start === 1) {
            $wv = (int)($row['wl_wecker'] ?? 0);
            $at = self::wakeTime($ctx['val'][$wv] ?? null, $today);
        } elseif ($start === 0) {
            $at = self::timeOn((string)($row['wl_zeit'] ?? AUTOMATKatalog::time(6, 30)), $today);
        }
        $dayOk = !empty($row['wl_we']) || ((int)date('N', $now) < 6 && empty($ctx['holiday'][$today]));
        // Aufwachen: zur Zeit hell → Beginn = Zeit − Dauer. Einschlafen: Beginn = Zeit.
        $begin = $at !== null ? ($up ? $at - $dauer : $at) : null;
        $soft = self::softBlock($row, $ctx);
        $go = false;
        // „beim Wechsel auf Nacht“: keine Tagessperre (der Wechsel kurz nach Mitternacht gehört zur Nacht davor),
        // nur nicht zweimal binnen 6 Stunden.
        $fresh = $start === 2 ? $now - (int)$st['t0'] >= 6 * 3600 : $st['day'] !== $today;
        if ($st['ph'] === 'idle' && $fresh && $soft === '') {
            if ($start === 2 && ($ev['k'] ?? '') === 'mode' && (int)($ev['to'] ?? -1) === AUTOMATKatalog::MODE_NIGHT) {
                $go = true;
            } elseif ($begin !== null && $dayOk && $now >= $begin && $now < $begin + $dauer) {
                $go = true;
            }
        }
        $levelOf = function (int $vid) use ($ctx): int {
            $max = (float)($ctx['dim'][$vid] ?? 0);
            $cur = $ctx['val'][$vid] ?? null;
            if ($max <= 0) {
                return self::isTrue($cur) ? 100 : 0;
            }
            return (int)round((float)$cur / $max * 100);
        };
        if ($go) {
            $st['ph'] = 'ramp';
            $st['day'] = $today;
            $st['t0'] = $now;
            $st['t1'] = $begin !== null && $start !== 2 ? $begin + $dauer : $now + $dauer;
            $from = 0;
            foreach ($outs as $vid) {
                $from = max($from, $levelOf($vid));
            }
            // Aufwachen beginnt beim aktuellen Pegel — ein schon helles Licht wird nicht erst auf 1 % gedimmt
            $st['from'] = max(1, $from);
            $res['log'] = ($up ? 'Aufwachlicht' : 'Einschlaflicht') . ' startet — bis ' . date('H:i', (int)$st['t1']) . ' Uhr.';
            if (!$up && $from === 0) {
                $st['ph'] = 'idle';
                $res['log'] = 'Einschlaflicht: Licht ist schon aus — nichts zu tun.';
            } elseif ($up && $from >= $ziel) {
                $st['ph'] = 'idle';
                $res['log'] = 'Aufwachlicht: Licht ist schon ' . $from . ' % hell — nichts zu tun.';
            }
        }
        if ($st['ph'] === 'ramp') {
            $t0 = (int)$st['t0'];
            $t1 = max($t0 + 1, (int)$st['t1']);
            $f = min(1.0, max(0.0, ($now - $t0) / ($t1 - $t0)));
            $to = $up ? $ziel : 0;
            $pct = (int)round((int)$st['from'] + ($to - (int)$st['from']) * $f);
            foreach ($outs as $vid) {
                $max = (float)($ctx['dim'][$vid] ?? 0);
                if ($max <= 0) {
                    // Schalter: Aufwachen am Anfang an, Einschlafen am Ende aus
                    if ($up && !self::isTrue($ctx['val'][$vid] ?? false)) {
                        $res['cmd'][] = [$vid, true];
                    } elseif (!$up && $f >= 1.0) {
                        $res['cmd'][] = [$vid, false];
                    }
                    continue;
                }
                $res['cmd'][] = [$vid, $pct <= 0 ? self::offValue($vid, $ctx) : self::onValue($vid, $pct, $ctx)];
            }
            if ($f >= 1.0) {
                $st['ph'] = 'idle';
                $res['log'] = ($up ? 'Aufwachlicht fertig — ' . $ziel . ' %.' : 'Einschlaflicht fertig — aus.');
                $res['status'] = $up ? '🌅 Fertig — ' . $ziel . ' %' : '🌙 Fertig — aus';
            } else {
                $res['due'] = min($t1, $now + 30);
                $res['end'] = $t1;
                $res['status'] = ($up ? '🌅 Wird heller: ' : '🌙 Wird dunkler: ') . $pct . ' % (bis ' . date('H:i', $t1) . ' Uhr)';
            }
        } else {
            if ($start === 2) {
                $res['status'] = '💤 Startet beim Wechsel auf „Nacht“';
            } elseif ($begin === null) {
                $res['status'] = '❔ Keine Weckzeit';
            } else {
                $next = $begin > $now ? $begin : null;
                $res['status'] = $next !== null && $dayOk ? '💤 Startet um ' . date('H:i', $next) . ' Uhr' : '💤 Heute erledigt oder kein Tag dafür';
                if ($next !== null && $dayOk) {
                    $res['due'] = $next;
                }
            }
        }
        $res['st'] = $st;
        return $res;
    }

    // ------------------------------------------------------------------
    // Zentral-Aus / Szene nach Hausmodus
    // ------------------------------------------------------------------

    /**
     * Ereignis 'mode' (from, to) vom Modul bei jedem Wechsel des Hausmodus. Geschaltet wird nur beim Wechsel
     * auf einen angekreuzten Modus (sz_m0…4), wahlweise verzögert; wechselt der Modus vorher weiter, entfällt es.
     * Die allgemeinen Modus-Häkchen („Wann aktiv?“) gelten hier nicht — der Modus IST der Auslöser.
     */
    private static function szene(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $outs = AUTOMATKatalog::vars($row, 'sz_out');
        $mode = (int)($ctx['mode'] ?? 0);
        $st += ['pend' => -1, 'at' => 0, 'last' => '', 'lastTs' => 0];
        $hard = self::hardBlock($ctx);
        $modeName = AUTOMATKatalog::MODES[$mode] ?? (string)$mode;
        $name = trim((string)($row['Name'] ?? '')) ?: 'Szene';
        if (($ev['k'] ?? '') === 'mode') {
            $to = (int)($ev['to'] ?? $mode);
            if ((int)$st['pend'] >= 0 && (int)$st['pend'] !== $to) {
                $res['log'] = 'Hausmodus wechselte vor Ablauf der Verzögerung auf „' . (AUTOMATKatalog::MODES[$to] ?? $to) . '“ — „' . (AUTOMATKatalog::MODES[(int)$st['pend']] ?? '') . '“-Schaltung entfällt.';
                $st['pend'] = -1;
                $st['at'] = 0;
            }
            if (!empty($row['sz_m' . $to])) {
                if ($hard !== '') {
                    $res['log'] = 'Hausmodus „' . (AUTOMATKatalog::MODES[$to] ?? $to) . '“ — nicht geschaltet: ' . $hard . '.';
                } elseif (isset($ctx['condOk']) && !$ctx['condOk']) {
                    $res['log'] = 'Hausmodus „' . (AUTOMATKatalog::MODES[$to] ?? $to) . '“ — nicht geschaltet: Bedingung nicht erfüllt.';
                } else {
                    $st['pend'] = $to;
                    $st['at'] = $now + max(0, (int)($row['sz_verz'] ?? 0)) * 60;
                }
            }
        }
        if ((int)$st['pend'] >= 0 && $now >= (int)$st['at']) {
            $pend = (int)$st['pend'];
            $st['pend'] = -1;
            $st['at'] = 0;
            if ($mode !== $pend) {
                $res['log'] = 'Hausmodus ist nicht mehr „' . (AUTOMATKatalog::MODES[$pend] ?? $pend) . '“ — Schaltung entfällt.';
            } elseif ($hard !== '') {
                $res['log'] = 'Nicht geschaltet: ' . $hard . '.';
            } else {
                $aktion = (int)($row['sz_aktion'] ?? 0);
                $changed = [];
                foreach ($outs as $vid) {
                    if ($aktion === 1) {
                        $want = self::onValue($vid, (int)($row['sz_hell'] ?? 100), $ctx);
                    } elseif ($aktion === 2) {
                        $want = (float)($row['sz_wert'] ?? 0);
                    } else {
                        $want = self::offValue($vid, $ctx);
                    }
                    $cur = $ctx['val'][$vid] ?? null;
                    // Was schon so steht, wird nicht angefasst (keine unnötigen Funkbefehle).
                    $same = $aktion === 2 ? ($cur !== null && abs((float)$cur - (float)$want) < 0.001)
                        : ($cur !== null && self::isTrue($cur) === self::isTrue($want) && ($aktion === 0 || (float)$cur == (float)$want || is_bool($want)));
                    if (!$same) {
                        $res['cmd'][] = [$vid, $want];
                        $changed[] = (string)($ctx['names'][$vid] ?? ('#' . $vid));
                    }
                }
                $verb = AUTOMATKatalog::SZ_ACTIONS[$aktion] ?? 'geschaltet';
                $verbPast = [0 => 'ausgeschaltet', 1 => 'eingeschaltet', 2 => 'auf ' . self::fmtNum((float)($row['sz_wert'] ?? 0)) . ' gesetzt'][$aktion] ?? 'geschaltet';
                $res['log'] = 'Hausmodus „' . $modeName . '“: ' . (count($changed) > 0 ? count($changed) . ' Gerät(e) ' . $verbPast . ' (' . implode(', ', $changed) . ').' : 'alle Geräte standen schon richtig — nichts zu ' . $verb . '.');
                $st['last'] = $modeName . ': ' . (count($changed) > 0 ? count($changed) . ' ' . $verbPast : 'nichts zu tun');
                $st['lastTs'] = $now;
                if (count($changed) > 0 && !empty($row['sz_push'])) {
                    $res['msg'][] = ['🌙 ' . $name, 'Hausmodus „' . $modeName . '“: ' . implode(', ', $changed) . ' ' . $verbPast . '.'];
                }
            }
        }
        $res['st'] = $st;
        if ($hard !== '') {
            $res['status'] = self::hardStatus($hard);
        } elseif ((int)$st['pend'] >= 0) {
            $res['due'] = (int)$st['at'];
            $res['end'] = (int)$st['at'];
            $res['status'] = '⏳ „' . (AUTOMATKatalog::MODES[(int)$st['pend']] ?? '') . '“: schaltet um ' . self::fmtClock((int)$st['at'], $now);
        } else {
            $res['status'] = $st['last'] !== '' ? '✅ Zuletzt ' . date('d.m.Y H:i', (int)$st['lastTs']) . ' — ' . $st['last'] : '💤 Wartet auf den nächsten Wechsel des Hausmodus';
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Anwesenheit zusammenfassen
    // ------------------------------------------------------------------

    private static function anwesenheit(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $sig = AUTOMATKatalog::vars($row, 'an_p');
        $wait = max(0, (int)($row['an_weg'] ?? 10)) * 60;
        $home = [];
        foreach ($sig as $vid) {
            if (self::isTrue($ctx['val'][$vid] ?? false)) {
                $home[] = (string)($ctx['names'][$vid] ?? ('#' . $vid));
            }
        }
        $first = !isset($st['ph']);
        $st += ['ph' => count($home) > 0 ? 'da' : 'weg', 'until' => 0, 'home' => [], 'since' => $now];
        $hard = self::hardBlock($ctx);
        if ($hard !== '') {
            $res['st'] = $st;
            $res['status'] = self::hardStatus($hard) . ' (Ergebnis bleibt ' . ($st['ph'] === 'weg' ? '„niemand da“' : '„jemand da“') . ')';
            return $res;
        }
        if ($first) {
            $res['self'] = $st['ph'] !== 'weg';
        }
        $came = array_values(array_diff($home, (array)$st['home']));
        $left = array_values(array_diff((array)$st['home'], $home));
        if (count($home) > 0) {
            if ($st['ph'] !== 'da') {
                $res['self'] = true;
                $res['log'] = ($st['ph'] === 'weg' ? 'Jemand ist nach Hause gekommen' : 'Doch wieder jemand da') . ' (' . implode(', ', $came ?: $home) . ').';
                if ($st['ph'] === 'weg' && !empty($row['an_push'])) {
                    $res['msg'][] = ['🏠 Heimgekommen', implode(', ', $came ?: $home) . ' ist nach Hause gekommen.'];
                }
                $st['since'] = $now;
            } elseif (count($came) > 0 || count($left) > 0) {
                $res['log'] = trim((count($came) > 0 ? implode(', ', $came) . ' gekommen. ' : '') . (count($left) > 0 ? implode(', ', $left) . ' gegangen. ' : '') . 'Zu Hause: ' . implode(', ', $home) . '.');
            }
            $st['ph'] = 'da';
            $st['until'] = 0;
        } else {
            if ($st['ph'] === 'da') {
                $st['ph'] = 'wartet';
                $st['until'] = $now + $wait;
                $res['log'] = (count($left) > 0 ? implode(', ', $left) . ' gegangen — ' : '') . 'alle weg' . ($wait > 0 ? ', „niemand da“ ab ' . date('H:i', $st['until']) . ' Uhr, falls niemand zurückkommt.' : '.');
            }
            if ($st['ph'] === 'wartet' && $now >= (int)$st['until']) {
                $st['ph'] = 'weg';
                $st['since'] = $now;
                $res['self'] = false;
                $res['log'] = ($res['log'] !== null ? $res['log'] . ' ' : '') . 'Niemand mehr zu Hause.';
                if (!empty($row['an_push'])) {
                    $res['msg'][] = ['🚪 Alle weg', 'Niemand mehr zu Hause (seit ' . date('H:i', $now) . ' Uhr).'];
                }
            }
        }
        $st['home'] = $home;
        $res['st'] = $st;
        switch ($st['ph']) {
            case 'da':
                $res['status'] = '🏠 Zu Hause: ' . implode(', ', $home);
                break;
            case 'wartet':
                $res['due'] = (int)$st['until'];
                $res['end'] = (int)$st['until'];
                $res['status'] = '🚪 Alle weg — „niemand da“ um ' . self::fmtClock((int)$st['until'], $now);
                break;
            default:
                $res['status'] = '🚪 Niemand zu Hause seit ' . date('d.m.Y H:i', (int)$st['since']) . ' Uhr';
        }
        return $res;
    }
}
