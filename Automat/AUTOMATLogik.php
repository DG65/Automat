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
        return [$cmd, count($cmd) > 0 ? AUTOMATText::t('Setpoint back to %s.', implode(', ', array_unique($vals))) : ''];
    }

    public static function step(array $row, array $st, array $ev, array $ctx): array
    {
        $type = (string)($row['Type'] ?? '');
        // 'end' = Zeitpunkt des nächsten sichtbaren Ereignisses (Licht aus, Absenkung, Meldung) für die Restzeit-Anzeige.
        // 'lost' = Melder, bei denen der Rückfall eingreifen musste („aus“-Meldung verloren) — fürs Funkqualitäts-Protokoll.
        $res = ['st' => $st, 'cmd' => [], 'msg' => [], 'log' => null, 'status' => '', 'due' => null, 'end' => null, 'lost' => []];
        // „Jetzt ausführen“ (Button im Formular/Kachel): Arten mit eigener Behandlung bekommen das Ereignis, alle
        // anderen einen normalen Durchlauf (holt nach, was gerade fällig ist)
        if (($ev['k'] ?? '') === 'run' && !in_array($type, self::RUN_TYPES, true)) {
            $ev = ['k' => 'tick'];
        }
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
            case 'ruecksetzen':
                return self::ruecksetzen($row, $st, $ev, $ctx, $res);
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
        $res['status'] = AUTOMATText::t('❔ Unknown type');
        return $res;
    }

    // ------------------------------------------------------------------
    // Gemeinsames
    // ------------------------------------------------------------------

    /** Harte Sperre: Text oder '' (frei). */
    public static function hardBlock(array $ctx): string
    {
        if (!empty($ctx['paused'])) {
            return AUTOMATText::t('all automations paused');
        }
        if (!empty($ctx['locked'])) {
            return AUTOMATText::t('locked');
        }
        return '';
    }

    /** Weiche Sperre: Text oder '' (frei). */
    public static function softBlock(array $row, array $ctx): string
    {
        $mode = (int)($ctx['mode'] ?? 0);
        if (array_key_exists('m' . $mode, $row) && !$row['m' . $mode]) {
            return AUTOMATText::t('not active in house mode "%s"', AUTOMATKatalog::modes()[$mode] ?? $mode);
        }
        if (isset($ctx['condOk']) && !$ctx['condOk']) {
            return AUTOMATText::t('condition not met');
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
        return AUTOMATText::t('%s (in %s)', $day . date($rest >= 3600 ? 'H:i' : 'H:i:s', $ts), $txt);
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
            return $v ? AUTOMATText::t('on') : AUTOMATText::t('off');
        }
        if (is_float($v)) {
            return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
        }
        return (string)$v;
    }

    private static function hardStatus(string $why): string
    {
        return AUTOMATText::t('⏸ %s — switches nothing', ucfirst($why));
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
                    $res['log'] = AUTOMATText::t('%s — not switched on: %s.', $why, $soft);
                    return;
                }
                $res['cmd'] = array_merge($res['cmd'], self::onCmds($outs, $row, $ctx, 'zl'));
                $st['ph'] = 'an';
                $st['until'] = $now + $dauer;
                $res['log'] = AUTOMATText::t('%s — light on, off at %s.', $why, date('H:i:s', $st['until']));
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
            $res['log'] = AUTOMATText::t('%s — time extended until %s.', $why, date('H:i:s', $st['until']));
        };

        if ($k === 'run') {
            $start(AUTOMATText::t('Triggered manually via Automat'));
        } elseif ($k === 'trigger' && in_array($vid, $trg, true) && self::isTrue($ev['v'] ?? false)) {
            $start(AUTOMATText::t('Button/trigger pressed'));
        } elseif ($k === 'output' && empty($ev['own']) && in_array($vid, $outs, true)) {
            if (self::isTrue($ev['v'] ?? false)) {
                if ($st['ph'] === 'aus') {
                    // Von Hand (oder von außen) eingeschaltet: Treppenlicht-Prinzip — die Zeit läuft trotzdem.
                    $st['ph'] = 'an';
                    $st['until'] = $now + $dauer;
                    $res['log'] = AUTOMATText::t('Light was switched on — goes off again at %s.', date('H:i:s', $st['until']));
                }
            } elseif (!self::anyOn($outs, $ctx) && in_array($st['ph'], ['an', 'warn', 'flash'], true)) {
                $st['ph'] = 'aus';
                $st['until'] = 0;
                $res['log'] = !empty($ev['central']) ? AUTOMATText::t('Switched off centrally (%s) — time cancelled.', (string)$ev['central']) : AUTOMATText::t('Light was switched off manually — time cancelled.');
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
                $res['log'] = AUTOMATText::t('Automation %s — running time cancelled, light stays as it is.', $hard);
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
            $res['log'] = $st['ph'] === 'an' ? ($st['until'] > 0 ? AUTOMATText::t('Lock lifted — light is on, goes off at %s.', date('H:i:s', $st['until'])) : AUTOMATText::t('Lock lifted — light is on, motion detected.')) : AUTOMATText::t('Lock lifted.');
        }
        if (!empty($ctx['permanent'])) {
            if ($st['ph'] !== 'dauer') {
                $res['cmd'] = self::onCmds($outs, $row, $ctx, $p);
                $res['log'] = AUTOMATText::t('Permanent light switched on.');
            }
            $st['ph'] = 'dauer';
            $st['until'] = 0;
            $res['st'] = $st;
            $res['status'] = AUTOMATText::t('🔆 Permanent light');
            $res['done'] = true;
            return $res;
        }
        if ($st['ph'] === 'dauer') {
            $st['ph'] = 'an';
            $st['until'] = $active ? 0 : $now + $dauer;
            $res['log'] = $active ? AUTOMATText::t('Permanent light ended — motion detected, follow-up time starts afterwards.') : AUTOMATText::t('Permanent light ended — light goes off at %s.', date('H:i:s', $st['until']));
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
            $res['log'] = $res['log'] ?? AUTOMATText::t('Pre-warning — light goes off at %s.', date('H:i:s', (int)$st['until']));
        }
        if (in_array($st['ph'], ['an', 'warn', 'flash'], true) && (int)$st['until'] > 0 && $now >= (int)$st['until']) {
            $res['cmd'] = array_merge($res['cmd'], self::offCmds($outs, $ctx));
            $st['ph'] = 'aus';
            $st['until'] = 0;
            $res['log'] = AUTOMATText::t('Time elapsed — light off.');
        }
        $res['st'] = $st;
        switch ($st['ph']) {
            case 'an':
                if ((int)$st['until'] > 0) {
                    $res['due'] = $vorwarn > 0 && (int)$st['until'] - $vorwarn > $now ? (int)$st['until'] - $vorwarn : (int)$st['until'];
                    $res['status'] = AUTOMATText::t('💡 On — off at %s', self::fmtClock((int)$st['until'], $now));
                    $res['end'] = (int)$st['until'];
                } else {
                    $res['status'] = AUTOMATText::t('💡 On — motion detected, follow-up time starts afterwards');
                }
                break;
            case 'flash':
                $res['due'] = (int)$st['fl'];
                $res['status'] = AUTOMATText::t('⚠️ Pre-warning — off at %s', self::fmtClock((int)$st['until'], $now));
                $res['end'] = (int)$st['until'];
                break;
            case 'warn':
                $res['due'] = (int)$st['until'];
                $res['status'] = AUTOMATText::t('⚠️ Pre-warning — off at %s', self::fmtClock((int)$st['until'], $now));
                $res['end'] = (int)$st['until'];
                break;
            case 'hand':
                $res['due'] = (int)($st['hu'] ?? 0) ?: null;
                $res['status'] = AUTOMATText::t('✋ Switched off manually — automation paused until %s', date('H:i', (int)($st['hu'] ?? $now)));
                $res['end'] = (int)($st['hu'] ?? 0) ?: null;
                break;
            default:
                $soft = self::softBlock($row, $ctx);
                $res['status'] = $soft !== '' ? AUTOMATText::t('🌙 Ready, but %s', $soft) : AUTOMATText::t('⚪ Off — ready');
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Bewegungslicht
    // ------------------------------------------------------------------

    /** Zeitfenster nach einem automatischen Ausschalten, in dem Wieder-Einschalten als Fehlabschaltung gilt. */
    public const FALSE_OFF_WINDOW = 180;
    public const LEARN_FACTOR_MAX = 4;
    /** Arten, die „Jetzt ausführen“ selbst behandeln (siehe step()). */
    public const RUN_TYPES = ['rollladen', 'szene', 'zeitschalt', 'heizung', 'gehen', 'zeitlicht'];

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
            $txt = AUTOMATText::t('False switch-off: light switched back on %s, %s after the automatic switch-off.', $how, AUTOMATKatalog::dur($gap));
            if ($learnOn) {
                $old = self::effectiveNachlauf(['bl_nachlauf' => $base, 'bl_nachlaufmax' => $maxN, 'bl_lernen' => true], $st);
                // Höchstwert = $maxN (schon auf das Vierfache des Grundwerts begrenzt)
                $st['learnN'] = min($maxN, max($base, (int)round($old * 1.5)));
                $st['lastDecay'] = $now;
                $nach = $st['learnN'];
                $txt .= ' ' . ($st['learnN'] > $old ? AUTOMATText::t('Follow-up time learned: %s → %s.', AUTOMATKatalog::dur($old), AUTOMATKatalog::dur($st['learnN'])) : AUTOMATText::t('Follow-up time is already at its maximum %s.', AUTOMATKatalog::dur($maxN)));
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
                            $res['log'] = AUTOMATText::t('Motion — not switched on: %s.', $soft);
                        } elseif ($luxVal !== null && $luxVal >= $luxMax && ((int)$st['offAt'] <= 0 || $now - (int)$st['offAt'] > $window)) {
                            // Helligkeit zählt nur in den ersten Minuten nach dem eigenen Ausschalten nicht — vorher galt
                            // das ab dem letzten automatischen Aus für immer (Licht ging mittags bei 70–540 lx an, Dietmar 16.10.2026).
                            $res['log'] = AUTOMATText::t('Motion — not switched on: bright enough (%s lx ≥ %s lx).', self::fmtNum($luxVal), self::fmtNum($luxMax));
                        } elseif (self::anyOn($outs, $ctx)) {
                            $res['log'] = AUTOMATText::t('Motion — light was already on (manually or by another control), not taken over.');
                        } else {
                            // Kurz nach dem eigenen Ausschalten zählt die Helligkeit nicht (Restlicht/Nachleuchten): sonst bliebe es nach einer Fehlabschaltung dunkel.
                            $res['cmd'] = self::onCmds($outs, $row, $ctx, 'bl');
                            $st['ph'] = 'an';
                            $res['log'] = AUTOMATText::t('Motion — light on.');
                            $noteReOn(AUTOMATText::t('by motion'));
                            $st['until'] = $impuls ? $now + $nach : 0;
                        }
                    } else {
                        if ($st['ph'] === 'warn' || $st['ph'] === 'flash') {
                            $res['cmd'] = self::onCmds($outs, $row, $ctx, 'bl');
                            $res['log'] = AUTOMATText::t('Motion during pre-warning — light stays on.');
                        }
                        $st['ph'] = 'an';
                        $st['until'] = $impuls ? $now + $nach : 0;
                    }
                }
            } elseif (in_array($st['ph'], ['an', 'warn', 'flash'], true) && (int)$st['until'] === 0 && count($hintNames) === 0) {
                $st['until'] = $now + $nach;
                $res['log'] = $nach > $base
                    ? AUTOMATText::t('No more motion — light goes off at %s (learned follow-up time %s, set %s).', date('H:i:s', $st['until']), AUTOMATKatalog::dur($nach), AUTOMATKatalog::dur($base))
                    : AUTOMATText::t('No more motion — light goes off at %s.', date('H:i:s', $st['until']));
            }
        } elseif ($k === 'output' && empty($ev['own']) && in_array($vid, $outs, true)) {
            if (self::isTrue($ev['v'] ?? false)) {
                if ($st['ph'] === 'aus' || $st['ph'] === 'hand') {
                    $st['ph'] = 'an';
                    $noteReOn(AUTOMATText::t('manually'));
                    // Meldet ein Melder noch Bewegung, startet der Nachlauf erst mit „keine Bewegung“
                    $st['until'] = $activeNow ? 0 : $now + max($nach, $handPause);
                    $res['log'] = ($res['log'] !== null ? $res['log'] . ' ' : '') . ($st['until'] > 0 ? AUTOMATText::t('Light was switched on manually — goes off at %s, motion extends.', date('H:i:s', $st['until'])) : AUTOMATText::t('Light was switched on manually — motion detected, follow-up time starts afterwards.'));
                }
            } elseif (!self::anyOn($outs, $ctx) && in_array($st['ph'], ['an', 'warn', 'flash'], true)) {
                $st['offAt'] = 0; // von Hand aus: kein Lernanlass
                if (!empty($ev['central'])) {
                    // Zentral-Aus/Szene: kein Handeingriff — keine Pause, die nächste Bewegung schaltet wieder ein.
                    $st['ph'] = 'aus';
                    $st['until'] = 0;
                    $res['log'] = AUTOMATText::t('Switched off centrally (%s) — next motion switches on again.', (string)$ev['central']);
                } elseif ($handPause > 0) {
                    $st['ph'] = 'hand';
                    $st['hu'] = $now + $handPause;
                    $st['until'] = 0;
                    $res['log'] = AUTOMATText::t('Light was switched off manually — motion will not switch it on again until %s.', date('H:i', $st['hu']));
                } else {
                    $st['ph'] = 'aus';
                    $st['until'] = 0;
                    $res['log'] = AUTOMATText::t('Light was switched off manually.');
                }
            }
        }
        if ($st['ph'] === 'hand' && $now >= (int)$st['hu']) {
            $st['ph'] = 'aus';
            $res['log'] = $res['log'] ?? AUTOMATText::t('Pause after manual operation over — motion switches again.');
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
                    $lost[] = AUTOMATText::t('%s (for %s)', (string)($ctx['names'][$m] ?? ('#' . $m)), self::fmtDuration(max(60, $limit)));
                } else {
                    $t = (int)$st['since'][$m] + $limit;
                    $nextFallback = $nextFallback === null ? $t : min($nextFallback, $t);
                }
            }
            if (count($lost) > 0) {
                $txt = AUTOMATText::t('Sensor %s reports nothing anymore — counts as "no motion" (message probably lost on the way).', implode(', ', $lost));
                if (!in_array(true, $st['act'], true) && count($hintNames) === 0 && in_array($st['ph'], ['an', 'warn', 'flash'], true) && (int)$st['until'] === 0) {
                    $st['until'] = $now + $nach;
                    $txt .= ' ' . AUTOMATText::t('Light goes off at %s.', date('H:i:s', $st['until']));
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
                    $txt = AUTOMATText::t('Presence (%s) — light stays on.', implode(', ', $hintNames));
                    $res['log'] = $res['log'] !== null ? $res['log'] . ' ' . $txt : $txt;
                }
            } elseif ((int)$st['until'] === 0 && ($impuls || !in_array(true, $st['act'], true))) {
                $st['until'] = $now + $nach;
                $txt = $k === 'trigger' && in_array($vid, $hints, true)
                    ? AUTOMATText::t('Signs of presence over — light goes off at %s.', date('H:i:s', $st['until']))
                    : AUTOMATText::t('No more motion and no more signs — light goes off at %s.', date('H:i:s', $st['until']));
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
            $res['status'] = AUTOMATText::t('💡 On — presence: %s', implode(', ', $hintNames));
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
            $res['log'] = !empty($ev['central']) ? AUTOMATText::t('Switched off centrally (%s) — stays off until the next switching window.', (string)$ev['central']) : AUTOMATText::t('Switched off manually — stays off until the next switching window.');
        }

        if ($win === null) {
            if ($st['on']) {
                if ($aus !== 3) {
                    $res['cmd'] = self::offCmds($outs, $ctx);
                    $res['log'] = AUTOMATText::t('Switch-off time reached — off.');
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
                        $res['status'] = AUTOMATText::t('🌙 Would switch on now, but %s', $soft);
                    } else {
                        $res['cmd'] = self::onCmds($outs, $row, $ctx, 'dz');
                        $st['on'] = true;
                        $res['log'] = $ein === 2 ? AUTOMATText::t('Got dark (%s lx) — on.', self::fmtNum($lux)) : ($ein === 0 ? AUTOMATText::t('Sunset — on.') : AUTOMATText::t('Switch-on time — on.'));
                    }
                }
            } elseif ($aus === 2 && $lux !== null && $lux > $hi) {
                $res['cmd'] = self::offCmds($outs, $ctx);
                $st['on'] = false;
                $st['manual'] = $key; // im selben Fenster nicht wieder einschalten
                $res['log'] = AUTOMATText::t('Got bright (%s lx) — off.', self::fmtNum($lux));
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
                $res['status'] = $win !== null && $aus !== 3 && $aus !== 2 ? AUTOMATText::t('💡 On — off at %s', self::fmtClock($win[1], $now)) : AUTOMATText::t('💡 On');
                $res['end'] = $win !== null && $aus !== 3 && $aus !== 2 ? $win[1] : null;
            } elseif ($win !== null && $st['manual'] === $key) {
                $res['status'] = AUTOMATText::t('✋ Off manually — again from the next switching window');
            } elseif ($win !== null && $ein === 2) {
                $res['status'] = $lux !== null ? AUTOMATText::t('⚪ Off — waiting for darkness (%s lx)', self::fmtNum($lux)) : AUTOMATText::t('⚪ Off — waiting for darkness (brightness sensor missing)');
            } else {
                $res['status'] = $next !== null ? AUTOMATText::t('⚪ Off — next switching %s', self::fmtClock($next, $now)) : AUTOMATText::t('⚪ Off');
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
                $res['log'] = AUTOMATText::t('Automation %s — setpoint back to %s.', $hard, $restore());
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
                $res['log'] = AUTOMATText::t('Setpoint was changed to %s °C while the window was open — remembered, stays lowered.', self::fmtNum($v));
            }
        }

        if ($open) {
            if ($st['ph'] === 'zu') {
                $soft = self::softBlock($row, $ctx);
                if ($soft !== '') {
                    $res['status'] = AUTOMATText::t('🪟 Open — no lowering: %s', $soft);
                    $res['st'] = $st;
                    return $res;
                }
                $st['ph'] = 'wartet';
                $st['until'] = $now + $verz;
                $res['log'] = $verz > 0 ? AUTOMATText::t('Window opened — lowering if still open at %s.', date('H:i:s', $st['until'])) : AUTOMATText::t('Window opened.');
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
                $res['log'] = AUTOMATText::t('Window open — setpoint lowered from %s to %s °C.', implode(', ', array_unique($from)), self::fmtNum($absenk));
            }
        } else {
            if ($st['ph'] === 'wartet') {
                $st['ph'] = 'zu';
                $res['log'] = AUTOMATText::t('Window closed before the delay elapsed — no lowering.');
            } elseif ($st['ph'] === 'ab') {
                $res['log'] = !empty($ev['startup']) ? AUTOMATText::t('Caught up at startup: all windows closed, thermostat was still lowered — setpoint back to %s.', $restore()) : AUTOMATText::t('Window closed — setpoint back to %s.', $restore());
                $st['ph'] = 'zu';
            }
        }
        $res['st'] = $st;
        switch ($st['ph']) {
            case 'wartet':
                $res['due'] = (int)$st['until'];
                $res['status'] = AUTOMATText::t('🪟 Open — lowering at %s', self::fmtClock((int)$st['until'], $now));
                $res['end'] = (int)$st['until'];
                break;
            case 'ab':
                $res['status'] = AUTOMATText::t('🪟 Open — lowered to %s °C', self::fmtNum($absenk));
                break;
            default:
                $res['status'] = AUTOMATText::t('✅ All closed');
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
            return AUTOMATText::t('%s days', round($sec / 86400, 1));
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
        $name = trim((string)($row['Name'] ?? '')) ?: AUTOMATText::t('Watchdog');
        $varName = (string)($ctx['names'][$vid] ?? ('#' . $vid));
        $st += ['ph' => 'normal', 'since' => 0, 'last' => 0];
        $v = $ctx['val'][$vid] ?? null;
        $cond = $v !== null && self::compare($v, $op, $ref);
        $opText = AUTOMATKatalog::tr(AUTOMATKatalog::WA_OPS)[$op] ?? '';
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
            return AUTOMATText::t('%s %s — for %s (currently %s).', $varName, $cmpText, self::fmtDuration(max(60, $now - $since)), $labels[self::valueKey($v)] ?? self::fmtNum($v));
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
                    $res['log'] = AUTOMATText::t('Notified: %s', $text((int)$st['since']));
                }
            } elseif ($st['ph'] === 'alarm' && $wdh > 0 && $now >= (int)$st['last'] + $wdh && $blocked === '') {
                $res['msg'][] = [AUTOMATText::t('⏱ %s (reminder)', $name), $text((int)$st['since'])];
                $st['last'] = $now;
                $res['log'] = AUTOMATText::t('Reminder sent.');
            }
        } elseif ($st['ph'] === 'alarm' && !self::waechterFrei($row, $v)) {
            // Hysterese: Erst wenn der Wert deutlich zurück ist, gilt es als vorbei — sonst gäbe es bei einem Wert,
            // der um die Grenze pendelt, jedes Mal eine neue Meldung (Wunsch Dietmar 29.10.2026, Frigate-Speicher)
        } else {
            if ($st['ph'] === 'alarm' && !empty($row['wa_ende']) && $hard === '') {
                $res['msg'][] = ['✅ ' . $name, AUTOMATText::t('%s is OK again (currently %s).', $varName, self::fmtNum($v))];
                $res['log'] = AUTOMATText::t('All-clear sent.');
            }
            $st['ph'] = 'normal';
            $st['since'] = 0;
        }
        $res['st'] = $st;
        if ($st['ph'] === 'zaehlt') {
            $res['due'] = (int)$st['since'] + $dauer;
            $res['end'] = (int)$st['since'] + $dauer;
            $res['status'] = AUTOMATText::t('⏳ %s %s — notification at %s', $varName, $cmpText, self::fmtClock((int)$st['since'] + $dauer, $now))
                . ($blocked !== '' ? ' ' . AUTOMATText::t('(will not be notified: %s)', $blocked) : '');
            if ($now >= (int)$st['since'] + $dauer) {
                $res['due'] = $now + 60;
            }
        } elseif ($st['ph'] === 'alarm') {
            $res['due'] = $wdh > 0 ? (int)$st['last'] + $wdh : null;
            $frei = trim((string)($row['wa_frei'] ?? ''));
            $res['status'] = AUTOMATText::t('🔔 Notified — %s %s since %s', $varName, $cmpText, date('d.m.Y H:i', (int)$st['since']))
                . (!$cond && $frei !== '' ? ($op === 3 ? AUTOMATText::t(' — OK again from %s (currently %s)', $frei, self::fmtNum($v)) : AUTOMATText::t(' — OK again below %s (currently %s)', $frei, self::fmtNum($v))) : '');
        } else {
            $res['status'] = $hard !== '' ? self::hardStatus($hard) : ($v === null ? AUTOMATText::t('❔ Variable missing') : AUTOMATText::t('✅ OK (%s)', self::fmtNum($v)));
        }
        return $res;
    }

    /** Ist der Wert über die Freigabe-Schwelle („Hysterese“) zurück? Ohne Angabe: sofort. */
    public static function waechterFrei(array $row, $v): bool
    {
        $op = (int)($row['wa_op'] ?? 4);
        $frei = str_replace(',', '.', trim((string)($row['wa_frei'] ?? '')));
        if ($frei === '' || !is_numeric($frei) || !in_array($op, [2, 3], true) || !is_numeric($v)) {
            return true;
        }
        return $op === 3 ? (float)$v >= (float)$frei : (float)$v <= (float)$frei;
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
        $name = trim((string)($row['Name'] ?? '')) ?: AUTOMATText::t('Appliance');
        $st += ['ph' => 'aus', 'start' => 0, 'below' => 0];
        $p = array_key_exists($vid, $ctx['val'] ?? []) ? (float)$ctx['val'][$vid] : null;
        if ($p !== null) {
            if ($p >= $lauf) {
                if ($st['ph'] === 'aus') {
                    $st['ph'] = 'laeuft';
                    $st['start'] = $now;
                    $res['log'] = AUTOMATText::t('Running (%s W).', self::fmtNum($p));
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
                        $res['log'] = AUTOMATText::t('Only a short power peak — does not count as a run.');
                    }
                }
            }
        }
        if ($st['ph'] === 'auslauf' && $now >= (int)$st['below'] + $nach) {
            $hard = self::hardBlock($ctx);
            $blocked = $hard !== '' ? $hard : self::softBlock($row, $ctx);
            $t = trim((string)($row['gf_text'] ?? ''));
            $text = $t !== '' ? $t : AUTOMATText::t('%s is finished (running time %s).', $name, self::fmtDuration(max(60, (int)$st['below'] - (int)$st['start'])));
            if ($blocked === '') {
                $res['msg'][] = ['🧺 ' . $name, $text];
                $res['log'] = AUTOMATText::t('Finished, notified.');
            } else {
                $res['log'] = AUTOMATText::t('Finished — not notified: %s.', $blocked);
            }
            $st['ph'] = 'aus';
            $st['last'] = $now;
        }
        $res['st'] = $st;
        switch ($st['ph']) {
            case 'laeuft':
                $res['status'] = $p !== null ? AUTOMATText::t('▶️ Running since %s (%s W)', date('H:i', (int)$st['start']), self::fmtNum($p)) : AUTOMATText::t('▶️ Running since %s', date('H:i', (int)$st['start']));
                break;
            case 'auslauf':
                $res['due'] = (int)$st['below'] + $nach;
                $res['status'] = AUTOMATText::t('⏳ Almost finished — notification at %s', self::fmtClock((int)$st['below'] + $nach, $now));
                $res['end'] = (int)$st['below'] + $nach;
                break;
            default:
                $res['status'] = !empty($st['last']) ? AUTOMATText::t('⚪ Off — last finished %s', date('d.m.Y H:i', (int)$st['last'])) : AUTOMATText::t('⚪ Off');
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
            // Ein Gewitter am Nachmittag ist noch kein Abend: frühestens 2 h vor Sonnenuntergang (wenn bekannt)
            $ssAny = $c['ssAny'] ?? null;
            $evening = !$eveFlag && $now >= ($ssAny !== null ? max($noon, (int)$ssAny - 7200) : $noon);
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
            $why = AUTOMATText::t('Login denied: user name or password of the SMTP instance is wrong (providers with two-factor authentication need an app password)');
        } elseif (strpos($h, 'resolve') !== false || strpos($h, 'getaddrinfo') !== false) {
            $why = AUTOMATText::t('Server not found: check the server name of the SMTP instance');
        } elseif (strpos($h, 'timed out') !== false || strpos($h, 'timeout') !== false) {
            $why = AUTOMATText::t('Timeout: the mail server does not respond (check server, port and network)');
        } elseif (strpos($h, 'refused') !== false) {
            $why = AUTOMATText::t('Connection refused: check server or port of the SMTP instance');
        } elseif (strpos($h, 'certificate') !== false || strpos($h, 'ssl') !== false || strpos($h, 'tls') !== false) {
            $why = AUTOMATText::t('Encryption or certificate: check SSL setting and port of the SMTP instance');
        } elseif (strpos($h, 'recipient') !== false || strpos($h, 'rcpt') !== false) {
            $why = AUTOMATText::t('Recipient rejected: check the recipient address');
        } else {
            $why = AUTOMATText::t('Message from the SMTP module');
        }
        return AUTOMATText::t('%s ("%s")', $why, $raw);
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
            $list = [$fix(1, 1) => 'New Year\'s Day', $fix(1, 6) => 'Epiphany', $rel(1) => 'Easter Monday', $fix(5, 1) => 'State Holiday',
                $rel(39) => 'Ascension Day', $rel(50) => 'Whit Monday', $rel(60) => 'Corpus Christi', $fix(8, 15) => 'Assumption Day',
                $fix(10, 26) => 'National Holiday', $fix(11, 1) => 'All Saints\' Day', $fix(12, 8) => 'Immaculate Conception', $fix(12, 25) => 'Christmas Day', $fix(12, 26) => 'St. Stephen\'s Day'];
        } elseif ($land === 'CH') {
            // Bundesweit nur 1.8.; dazu die in fast allen Kantonen geltenden Feiertage (kantonale Unterschiede möglich)
            $list = [$fix(1, 1) => 'New Year\'s Day', $rel(-2) => 'Good Friday', $rel(1) => 'Easter Monday', $rel(39) => 'Ascension',
                $rel(50) => 'Whit Monday', $fix(8, 1) => 'Swiss National Day', $fix(12, 25) => 'Christmas', $fix(12, 26) => 'Saint Stephen\'s Day'];
        } else {
            $list = [$fix(1, 1) => 'New Year\'s Day', $rel(-2) => 'Good Friday', $rel(1) => 'Easter Monday', $fix(5, 1) => 'Labour Day',
                $rel(39) => 'Ascension Day', $rel(50) => 'Whit Monday', $fix(10, 3) => 'German Unity Day',
                $fix(12, 25) => 'First Day of Christmas', $fix(12, 26) => 'Second Day of Christmas'];
            $in = function (string $l) use ($land): bool {
                return in_array($land, explode(',', $l), true);
            };
            if ($in('BW,BY,ST')) {
                $list[$fix(1, 6)] = 'Epiphany';
            }
            if ($in('BE,MV')) {
                $list[$fix(3, 8)] = 'International Women\'s Day';
            }
            if ($in('BW,BY,HE,NW,RP,SL')) {
                $list[$rel(60)] = 'Corpus Christi';
            }
            if ($in('SL')) {
                $list[$fix(8, 15)] = 'Assumption Day';
            }
            if ($in('TH')) {
                $list[$fix(9, 20)] = 'World Children\'s Day';
            }
            if ($in('BB,MV,SN,ST,TH,HB,HH,NI,SH')) {
                $list[$fix(10, 31)] = 'Reformation Day';
            }
            if ($in('BW,BY,NW,RP,SL')) {
                $list[$fix(11, 1)] = 'All Saints\' Day';
            }
            if ($in('SN')) {
                // Buß- und Bettag: Mittwoch vor dem 23. November
                $t = mktime(12, 0, 0, 11, 22, $y);
                while ((int)date('N', $t) !== 3) {
                    $t = mktime(12, 0, 0, 11, (int)date('j', $t) - 1, $y);
                }
                $list[date('Y-m-d', $t)] = 'Day of Repentance and Prayer';
            }
        }
        // Namen stehen englisch in der Liste, die Anzeige übersetzt sie (deutsche Originale in locale.json)
        return isset($list[$ymd]) ? AUTOMATText::t($list[$ymd]) : '';
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
        $name = trim((string)($row['Name'] ?? '')) ?: AUTOMATText::t('Leaving check');
        $hard = self::hardBlock($ctx);
        if (($ev['k'] ?? '') === 'run') {
            // Probe-Check von Hand: wie beim Gehen, ohne den Hausmodus zu ändern
            $first = 3;
            foreach ([3, 4, 2] as $m) {
                if (!empty($row['gc_m' . $m])) {
                    $first = $m;
                    break;
                }
            }
            $row['gc_m' . $first] = true;
            $ev = ['k' => 'mode', 'from' => (int)($ctx['mode'] ?? 0), 'to' => $first];
            $hard = '';
        }
        if (($ev['k'] ?? '') === 'mode' && !empty($row['gc_m' . (int)($ev['to'] ?? -1)])) {
            $to = (int)$ev['to'];
            if ($hard !== '') {
                $res['log'] = AUTOMATText::t('House mode "%s" — not checked: %s.', AUTOMATKatalog::modes()[$to], $hard);
            } else {
                $open = self::activeOf(AUTOMATKatalog::vars($row, 'gc_kon'), $ctx, !empty($row['gc_invert']));
                $on = [];
                $off = [];
                $watt = (float)($row['gc_watt'] ?? 10);
                foreach (AUTOMATKatalog::vars($row, 'gc_geraet') as $vid) {
                    if (!array_key_exists($vid, $ctx['val'] ?? [])) {
                        continue;
                    }
                    $v = $ctx['val'][$vid];
                    $isOn = is_bool($v) ? $v : (is_numeric($v) ? (float)$v >= $watt : self::isTrue($v));
                    if ($isOn) {
                        // Leistungswerte kann man nicht ausschalten — nur schaltbare Variablen bekommen den Befehl
                        if (!empty($row['gc_aus']) && (!isset($ctx['switchable']) || !empty($ctx['switchable'][$vid]))) {
                            $res['cmd'][] = [$vid, self::offValue($vid, $ctx)];
                            $off[] = self::nameOf($vid, $ctx);
                        } else {
                            $on[] = self::nameOf($vid, $ctx);
                        }
                    }
                }
                $parts = [];
                if ($open) {
                    $parts[] = AUTOMATText::t('open: %s', implode(', ', $open));
                }
                if ($off) {
                    $parts[] = AUTOMATText::t('switched off: %s', implode(', ', $off));
                }
                if ($on) {
                    $parts[] = AUTOMATText::t('still on: %s', implode(', ', $on));
                }
                $txt = $parts ? implode('; ', $parts) : AUTOMATText::t('everything closed and off');
                $st['last'] = AUTOMATKatalog::modes()[$to] . ' — ' . $txt;
                $st['lastTs'] = $now;
                $st['bad'] = count($open) > 0 || count($on) > 0; // was noch an ist, wurde nicht ausgeschaltet
                $res['log'] = AUTOMATText::t('Leaving check at "%s": %s.', AUTOMATKatalog::modes()[$to], $txt);
                if (!empty($row['gc_push']) && ($parts || !empty($row['gc_ok']))) {
                    $res['msg'][] = [($parts ? '🚪 ' : '✅ ') . $name, $parts
                        ? ($to === AUTOMATKatalog::MODE_NIGHT ? AUTOMATText::t('When going to bed — %s.', $txt) : AUTOMATText::t('When leaving — %s.', $txt))
                        : ucfirst($txt) . '.'];
                }
            }
        }
        $res['st'] = $st;
        $res['status'] = $hard !== '' ? self::hardStatus($hard)
            : ($st['last'] === '' ? AUTOMATText::t('💤 Checks at the next house mode change')
                : ($st['bad'] ? '⚠️ ' : '✅ ') . AUTOMATText::t('Last %s — %s', date('d.m.Y H:i', (int)$st['lastTs']), $st['last']));
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
        $name = trim((string)($row['Name'] ?? '')) ?: AUTOMATText::t('Doorbell');
        $hard = self::hardBlock($ctx);
        // Handeingriff während der Klingelzeit: dieses Gerät nicht mehr zurückstellen
        if (($ev['k'] ?? '') === 'output' && empty($ev['own'])) {
            $k = (string)(int)($ev['vid'] ?? 0);
            if (isset($st['lights'][$k]) || isset($st['vol'][$k])) {
                unset($st['lights'][$k], $st['vol'][$k]);
                $res['log'] = AUTOMATText::t('%s adjusted manually — stays that way.', self::nameOf((int)$k, $ctx));
            }
        }
        $in = (int)($row['kl_taster'] ?? 0);
        if (($ev['k'] ?? '') === 'trigger' && (int)($ev['vid'] ?? 0) === $in && $hard === '') {
            $key = mb_strtolower(self::valueKey($ev['v'] ?? ''));
            // Dieselbe Meldung ohne Wertänderung (Funkqualität, Abfrage) ist kein neues Klingeln — außer die Klingel
            // meldet bewusst immer denselben Wert (Prüfbericht 17.10.2026)
            $repeat = array_key_exists('changed', $ev) && !$ev['changed'] && empty($row['kl_impuls']);
            if (!$repeat && !in_array($key, ['', 'false', '0'], true) && $now - (int)$st['last'] >= 10) {
                $st['last'] = $now;
                $away = in_array($mode, [AUTOMATKatalog::MODE_AWAY, AUTOMATKatalog::MODE_HOLIDAY], true);
                $res['log'] = $away ? AUTOMATText::t('The doorbell rang (nobody at home).') : AUTOMATText::t('The doorbell rang.');
                if (!empty($row['kl_push'])) {
                    $res['msg'][] = [$away ? AUTOMATText::t('🛎 %s — nobody at home', $name) : '🛎 ' . $name, AUTOMATText::t('The doorbell rang at %s.', date('H:i', $now))];
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
                $res['log'] = AUTOMATText::t('Doorbell time over — light off, volume back.');
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
            $res['status'] = AUTOMATText::t('🛎 Rang — back at %s', self::fmtClock((int)$st['until'], $now));
        } else {
            $res['status'] = (int)$st['last'] > 0 ? AUTOMATText::t('🛎 Last rang %s', date('d.m.Y H:i', (int)$st['last'])) : AUTOMATText::t('💤 Waiting for the doorbell');
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
        $name = trim((string)($row['Name'] ?? '')) ?: AUTOMATText::t('Summer ventilation');
        $hard = self::hardBlock($ctx);
        if ($ti === null || $ta === null || $hard !== '') {
            $res['st'] = $st;
            $res['status'] = $hard !== '' ? self::hardStatus($hard) : AUTOMATText::t('❔ Indoor or outdoor temperature missing');
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
        $txt = $dp
            ? AUTOMATText::t('indoor %s °C, outdoor %s °C (dew point indoor %s, outdoor %s °C)', self::fmtNum($ti), self::fmtNum($ta), self::fmtNum(round($dpi, 1)), self::fmtNum(round($dpa, 1)))
            : AUTOMATText::t('indoor %s °C, outdoor %s °C', self::fmtNum($ti), self::fmtNum($ta));
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
                $res['log'] = $new === 'close' ? AUTOMATText::t('Warmer outside — %s.', $txt) : AUTOMATText::t('Cooler outside — %s.', $txt);
                if (!empty($row['sl_push'])) {
                    $res['msg'][] = $new === 'close'
                        ? ['☀️ ' . $name, AUTOMATText::t('Close windows%s — it is warmer%s outside: %s.', count($open) > 0 ? ' (' . implode(', ', $open) . ')' : '', $dp && $dpa > $dpi + 1.0 ? AUTOMATText::t(' or more humid') : '', $txt)]
                        : ['🌬 ' . $name, $dp ? AUTOMATText::t('Ventilating now is worthwhile — it is cooler and drier outside: %s.', $txt) : AUTOMATText::t('Ventilating now is worthwhile — it is cooler outside: %s.', $txt)];
                }
            }
        }
        $res['st'] = $st;
        $res['status'] = $st['ph'] === 'close' ? AUTOMATText::t('☀️ Warmer outside — keep windows closed: %s', $txt)
            : ($st['ph'] === 'open' ? AUTOMATText::t('🌬 Cooler outside — ventilating is worthwhile: %s', $txt)
                : ($warm ? AUTOMATText::t('✅ No action needed: %s', $txt) : AUTOMATText::t('💤 Indoor below %s °C: %s', self::fmtNum($min), $txt)));
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
            $res['status'] = $hard !== '' ? self::hardStatus($hard) : AUTOMATText::t('❔ Power unknown');
            return $res;
        }
        $active = $p >= $schwelle;
        // Eingefrorener Messwert (Zwischenstecker offline): der letzte Wert bliebe sonst ewig „läuft“ — genau dann
        // wäre der Ausfall unbemerkt. Ab 6 h ohne neue Meldung (mindestens die eingestellte Dauer) wird gewarnt.
        $upd = $ctx['upd'][$vid] ?? null;
        $stale = $upd !== null && $now - (int)$upd >= max($dauer, 6 * 3600);
        if ($stale && empty($st['staleTold'])) {
            $st['staleTold'] = true;
            $res['log'] = AUTOMATText::t('%s: measured value not updated for %s — metering plug offline?', $name, self::fmtDuration($now - (int)$upd));
            if (!empty($row['af_push'])) {
                $res['msg'][] = ['🧊 ' . $name, $res['log'] . ' ' . AUTOMATText::t('The watchdog cannot detect a failure this way. Please check.')];
            }
        } elseif (!$stale) {
            $st['staleTold'] = false;
        }
        // „since“: Beginn der beobachteten Phase (ohne Strom bzw. Lauf am Stück)
        $watching = $runMode ? $active : !$active;
        if ($watching) {
            if ((int)$st['since'] === 0) {
                $st['since'] = $now;
            }
            if ($st['ph'] === 'ok' && $now - (int)$st['since'] >= $dauer) {
                $st['ph'] = 'alarm';
                $what = $runMode ? AUTOMATText::t('has been running for %s without a break', self::fmtDuration($now - (int)$st['since'])) : AUTOMATText::t('has drawn no power for %s', self::fmtDuration($now - (int)$st['since']));
                $res['log'] = $name . ' ' . $what . ' (' . self::fmtNum($p) . ' W).';
                if (!empty($row['af_push'])) {
                    $res['msg'][] = ['🧊 ' . $name, $name . ' ' . $what . ' (' . self::fmtNum($p) . ' W). ' . AUTOMATText::t('Please check.')];
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
                $res['log'] = $runMode ? AUTOMATText::t('All clear: %s is no longer running.', $name) : AUTOMATText::t('All clear: %s is drawing power again.', $name);
                if (!empty($row['af_ende']) && !empty($row['af_push'])) {
                    $res['msg'][] = ['✅ ' . $name, $runMode ? AUTOMATText::t('All clear: %s is no longer running.', $name) : AUTOMATText::t('All clear: %s is drawing power again (%s W).', $name, self::fmtNum($p))];
                }
            }
        }
        $longest = $st['gaps'] ? max($st['gaps']) : 0;
        $norm = $longest > 0 ? ($runMode ? AUTOMATText::t(' · longest run so far %s', self::fmtDuration($longest)) : AUTOMATText::t(' · longest pause so far %s', self::fmtDuration($longest))) : '';
        $res['st'] = $st;
        if ($st['ph'] === 'alarm') {
            $res['status'] = ($runMode ? AUTOMATText::t('⚠️ Running too long since %s', date('d.m.Y H:i', (int)$st['since'])) : AUTOMATText::t('⚠️ No power since %s', date('d.m.Y H:i', (int)$st['since']))) . $norm;
        } else {
            if ((int)$st['since'] > 0) {
                $res['due'] = (int)$st['since'] + $dauer;
            }
            $warn = $longest > $dauer ? AUTOMATText::t(' — ⚠️ limit is below the longest value so far, false alarms possible') : '';
            if ($stale) {
                $warn .= AUTOMATText::t(' — ⚠️ measured value not updated for %s', self::fmtDuration($now - (int)$upd));
            }
            $res['status'] = ($active ? AUTOMATText::t('✅ Running (%s W)', self::fmtNum($p)) : AUTOMATText::t('✅ Off (%s W)', self::fmtNum($p))) . $norm . $warn;
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
                $res['log'] = AUTOMATText::t('Switched off manually — stays off until the next switching time.');
            } elseif (($ev['k'] ?? '') === 'output' && empty($ev['own']) && in_array((int)($ev['vid'] ?? 0), $outs, true)
                && $key !== null && !$st['on'] && self::isTrue($ev['v'] ?? false)) {
                // Im Fenster von Hand wieder eingeschaltet: am Fensterende wieder normal ausschalten
                $st['on'] = true;
                $st['manual'] = '';
            }
            if (($ev['k'] ?? '') === 'run') {
                // Von Hand über Automat: den Zustand herstellen, der jetzt gelten soll (im Fenster an, sonst aus)
                $setAll($key !== null);
                $st['on'] = $key !== null;
                $st['manual'] = '';
                $res['log'] = $key !== null ? AUTOMATText::t('Triggered manually — within the switching window: on.') : AUTOMATText::t('Triggered manually — outside the switching window: off.');
            } elseif ($first) {
                $st['on'] = $key !== null; // beim Einrichten nichts nachholen, nur den Zustand übernehmen
            } elseif ($hard === '') {
                // Eine weiche Sperre (Hausmodus, Bedingung) verhindert nur das Einschalten — laufende Zeiten enden normal
                if ($key !== null && !$st['on'] && $st['manual'] !== $key && $soft === '') {
                    $setAll(true);
                    $st['on'] = true;
                    $res['log'] = AUTOMATText::t('Switching time — on.');
                } elseif ($key === null && $st['on']) {
                    $setAll(false);
                    $st['on'] = false;
                    $res['log'] = AUTOMATText::t('Switching time — off.');
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
            if (($ev['k'] ?? '') === 'run') {
                $setAll($want);
                $res['log'] = $want ? AUTOMATText::t('Triggered manually — on.') : AUTOMATText::t('Triggered manually — off.');
            } elseif ($first) {
                $st['did'][$today] = $now >= $t;
            } elseif (self::zsDay($row, $today, $hol) && $now >= $t && $now - $t < 3600 && empty($st['did'][$today]) && $hard === '' && $soft === '') {
                $setAll($want);
                $res['log'] = $want ? AUTOMATText::t('Switching time — on.') : AUTOMATText::t('Switching time — off.');
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
        $hn = !empty($hol[$today]) ? AUTOMATText::t(' (public holiday today: %s)', $hol[$today]) : '';
        if ($hard !== '') {
            $res['status'] = self::hardStatus($hard);
        } elseif ($modus === 0 && $st['on']) {
            $res['status'] = ($next !== null ? AUTOMATText::t('💡 Switched on — off at %s', self::fmtClock($next, $now)) : AUTOMATText::t('💡 Switched on')) . $hn;
        } else {
            $res['status'] = ($soft !== '' ? '🌙 ' . $soft . ' — ' : '⏰ ') . ($next !== null ? AUTOMATText::t('Next switching %s', self::fmtClock($next, $now)) : AUTOMATText::t('No switching in the next two days')) . $hn;
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
        return array_key_exists($vid, $ctx['inv'] ?? []) ? (bool)$ctx['inv'][$vid] : $rowInvert;
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
        $name = trim((string)($row['Name'] ?? '')) ?: AUTOMATText::t('Alarm');
        if (!empty($ctx['locked'])) {
            $res['st'] = $st;
            $res['status'] = AUTOMATText::t('⏸ Locked — does not react to alarms');
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
                $did[AUTOMATText::t('Light on')][] = self::nameOf($vid, $ctx);
            }
            foreach (AUTOMATKatalog::vars($row, 'al_hoch') as $vid) {
                // Steuert ein Rollladen-Automatismus denselben Rollladen, gilt dessen „offen“-Wert (täglich erprobt) —
                // ein falsch herum eingetragener Wert würde beim Rauchalarm sonst den Fluchtweg schließen.
                $res['cmd'][] = [$vid, isset($ctx['rlDir'][$vid]) ? (float)$ctx['rlDir'][$vid]['open'] : (array_key_exists($vid, $ctx['rlOpen'] ?? []) ? (float)$ctx['rlOpen'][$vid] : (float)($row['al_hochwert'] ?? 0))];
                $did[AUTOMATText::t('Shutter up')][] = self::nameOf($vid, $ctx);
            }
            foreach (AUTOMATKatalog::vars($row, 'al_aus') as $vid) {
                $res['cmd'][] = [$vid, self::offValue($vid, $ctx)];
                $did[AUTOMATText::t('off')][] = self::nameOf($vid, $ctx);
            }
            $what = [];
            foreach ($did as $k => $l) {
                $what[] = $k . ': ' . implode(', ', $l);
            }
            $res['log'] = $what ? AUTOMATText::t('🚨 Alarm from %s — %s.', implode(', ', $who), implode('; ', $what)) : AUTOMATText::t('🚨 Alarm from %s.', implode(', ', $who));
            if (!empty($row['al_push'])) {
                $res['msg'][] = ['🚨 ' . $name, $what ? AUTOMATText::t('ALARM: %s (%s). %s.', implode(', ', $who), date('H:i', $now), implode('; ', $what)) : AUTOMATText::t('ALARM: %s (%s).', implode(', ', $who), date('H:i', $now))];
            }
        } elseif (count($who) > 0) {
            $added = array_values(array_diff($who, (array)$st['who']));
            if (count($added) > 0) {
                // Weiterer Melder während eines laufenden Alarms (z. B. Rauch breitet sich aus) — sofort melden
                $res['log'] = AUTOMATText::t('🚨 Additional sensor: %s.', implode(', ', $added));
                if (!empty($row['al_push'])) {
                    $res['msg'][] = ['🚨 ' . $name, AUTOMATText::t('ALARM now also: %s (%s). All: %s.', implode(', ', $added), date('H:i', $now), implode(', ', $who))];
                }
            }
            if ($wdh > 0 && $now >= (int)$st['last'] + $wdh) {
                $st['last'] = $now;
                if (!empty($row['al_push'])) {
                    $res['msg'][] = [AUTOMATText::t('🚨 %s (reminder)', $name), AUTOMATText::t('Alarm still active since %s: %s.', date('H:i', (int)$st['since']), implode(', ', $who))];
                }
            }
            if ($wdh > 0) {
                $res['due'] = (int)$st['last'] + $wdh;
            }
        } elseif ($st['ph'] === 'alarm') {
            $st['ph'] = 'ok';
            $res['log'] = AUTOMATText::t('✅ All clear — no sensor reports an alarm anymore. Switched devices stay as they are (please reset them yourself).');
            if (!empty($row['al_ende']) && !empty($row['al_push'])) {
                $res['msg'][] = ['✅ ' . $name, AUTOMATText::t('All clear: no sensor reports an alarm anymore (%s). Please switch valves and devices back on yourself.', date('H:i', $now))];
            }
        }
        $st['who'] = $who;
        $res['st'] = $st;
        $res['status'] = $st['ph'] === 'alarm'
            ? AUTOMATText::t('🚨 Alarm since %s — %s', date('H:i', (int)$st['since']), implode(', ', $who))
            : AUTOMATText::t('✅ No alarm');
        return $res;
    }

    // ------------------------------------------------------------------
    // Rollladen nach Zeit/Sonne
    // ------------------------------------------------------------------

    /** Zielwert eines Rollladens: aus der Richtung seines Profils (ctx['rlDir']), sonst aus dem Automatismus. */
    public static function shutterTarget(int $vid, bool $close, array $row, array $ctx): float
    {
        if (isset($ctx['rlDir'][$vid])) {
            return (float)$ctx['rlDir'][$vid][$close ? 'closed' : 'open'];
        }
        return $close ? (float)($row['rl_zu'] ?? 100) : (float)($row['rl_auf'] ?? 0);
    }

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
            // Frühestens 2 h vor Sonnenuntergang — ein Gewitter um 14 Uhr schloss sonst für den ganzen Tag
            $darkFrom = $sun['ss'] !== null ? max($noon + 7200, (int)$sun['ss'] - 7200) : $noon + 7200;
            $downReady = ($now >= $darkFrom && ($ctx['dark'] ?? null) === true) || $now >= $latest;
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
        // Immer senden — auch wenn die Anzeige schon am Ziel steht: Viele Rollladen-Aktoren (z. B. Z-Wave) setzen die
        // Position schon beim Befehl. Ging ein Befehl im Funk verloren, stünde die Anzeige sonst für immer „richtig“
        // und kein Befehl käme mehr an (live 17.10.2026: Wohnzimmer oben, Anzeige 0 — auch von Hand ging nichts).
        // Ziel je Rollladen: Richtung aus dessen Profil (~Shutter: 0 = offen; ~Shutter.Reversed: 0 = zu), sonst die
        // Werte im Automatismus — so passen gemischte Aktoren (Z-Wave + Shelly) in EINEM Automatismus (live 17.10.2026)
        $set = function (bool $close) use ($outs, $ctx, $row, &$res, &$st, $now): string {
            $sent = [];
            $already = [];
            $targets = [];
            foreach ($outs as $vid) {
                $v = self::shutterTarget($vid, $close, $row, $ctx);
                $targets[$vid] = $v;
                $cur = $ctx['val'][$vid] ?? null;
                $res['cmd'][] = [$vid, $v];
                $sent[] = self::nameOf($vid, $ctx);
                if ($cur !== null && abs((float)$cur - $v) <= 0.5) {
                    $already[] = self::nameOf($vid, $ctx);
                }
            }
            $st['chk'] = count($sent) > 0 ? ['at' => $now + 180, 't' => $targets, 'n' => 0] : null;
            $list = implode(', ', $sent);
            $known = implode(', ', $already);
            return ($close ? AUTOMATText::t('Shutters closed: %s', $list) : AUTOMATText::t('Shutters opened: %s', $list))
                . (count($already) > 0 ? ' ' . (count($already) > 1
                    ? AUTOMATText::t('(according to the display, %s were already there — command sent anyway)', $known)
                    : AUTOMATText::t('(according to the display, %s was already there — command sent anyway)', $known)) : '') . '.';
        };
        // Aussperrschutz von gestern Abend: nach Mitternacht noch schließen, sobald die Tür zu ist — aber nicht mehr,
        // wenn morgens das Öffnen ansteht. Vorher blieb „wartet“ für immer stehen (Status falsch, nächste Meldung fehlte).
        if ($st['wait'] && $st['waitDay'] !== $today) {
            if ($st['waitDay'] !== $yesterday || $upReady || $now >= $noon) {
                $st['wait'] = false;
            }
        }
        if (($ev['k'] ?? '') === 'run') {
            // Von Hand über Automat: schließen/öffnen (oder das gerade Fällige), auch in Pause/Sperre — nur der
            // Aussperrschutz gilt weiter
            $what = (string)($ev['what'] ?? '');
            if ($what === '') {
                $what = $downReady ? 'down' : 'up';
            }
            if ($what === 'down' && count($open) > 0) {
                $res['log'] = AUTOMATText::t('Close requested manually — not closed, open: %s (lock-out protection).', implode(', ', $open));
            } elseif ($what === 'down') {
                $res['log'] = AUTOMATText::t('Triggered manually — %s', $set(true));
                $st['down'] = $today;
                $st['up'] = $today;
                $st['wait'] = false;
            } else {
                $res['log'] = AUTOMATText::t('Triggered manually — %s', $set(false));
                $st['up'] = $today;
            }
            $ev = ['k' => 'tick'];
        }
        if ($hard === '' && $soft === '' && $st['wait'] && $st['waitDay'] === $yesterday && count($open) === 0) {
            $res['log'] = AUTOMATText::t('Door closed now — %s', $set(true));
            $st['wait'] = false;
            $st['down'] = $yesterday; // gehört zur Nacht davor — morgens wird normal geöffnet
        }
        $chkOff = [];
        $chkT = function (int $vid) use (&$st): float {
            return (float)($st['chk']['t'][$vid] ?? $st['chk']['v'] ?? 0);
        };
        if (is_array($st['chk'])) {
            foreach ($outs as $vid) {
                $cur = $ctx['val'][$vid] ?? null;
                if ($cur !== null && abs((float)$cur - $chkT($vid)) > 2.0) {
                    $chkOff[] = $vid;
                }
            }
            if (count($chkOff) === 0) {
                $st['chk'] = null; // alle am Ziel
            }
        }
        if ($hard === '' && $soft === '' && is_array($st['chk']) && $now >= (int)$st['chk']['at']) {
            $off = $chkOff;
            if ((int)$st['chk']['n'] === 0) {
                foreach ($off as $vid) {
                    $res['cmd'][] = [$vid, $chkT($vid)];
                }
                $res['log'] = AUTOMATText::t('Follow-up check: %s not at target — command sent again.', implode(', ', array_map(function ($v) use ($ctx) {
                    return self::nameOf($v, $ctx);
                }, $off)));
                $st['chk']['at'] = $now + 180;
                $st['chk']['n'] = 1;
            } else {
                $res['log'] = AUTOMATText::t('⚠️ %s did not respond to the second command either.', implode(', ', array_map(function ($v) use ($ctx) {
                    return self::nameOf($v, $ctx);
                }, $off)));
                $res['fault'] = $res['log'];
                $st['chk'] = null;
            }
        }
        if ($hard === '' && $soft === '') {
            if ($downReady && $st['down'] !== $today) {
                if (count($open) > 0) {
                    if (!$st['wait']) {
                        $res['log'] = AUTOMATText::t('Closing held back — open: %s (lock-out protection). Closes as soon as closed.', implode(', ', $open));
                        if (!empty($row['rl_push'])) {
                            $res['msg'][] = ['🪟 ' . (trim((string)($row['Name'] ?? '')) ?: AUTOMATText::t('Shutter')), AUTOMATText::t('Stays up because open: %s. Closes as soon as closed.', implode(', ', $open))];
                        }
                    }
                    $st['wait'] = true;
                    $st['waitDay'] = $today;
                } else {
                    $closed = $set(true);
                    $res['log'] = $st['wait'] ? AUTOMATText::t('Door closed now — %s', $closed) : $closed;
                    $st['down'] = $today;
                    $st['wait'] = false;
                    $st['up'] = $today; // heute nicht mehr öffnen
                }
            } elseif ($upReady && $st['up'] !== $today && !$downReady) {
                $res['log'] = $set(false);
                $st['up'] = $today;
            }
        }
        $res['st'] = $st;
        if ($hard !== '') {
            $res['status'] = self::hardStatus($hard);
        } elseif ($st['wait']) {
            $res['status'] = AUTOMATText::t('⏳ Stays up — open: %s', implode(', ', $open));
            $res['due'] = $now + 60;
        } elseif (is_array($st['chk'])) {
            $res['status'] = AUTOMATText::t('⏳ Command sent — follow-up check at %s', date('H:i', (int)$st['chk']['at']));
            $res['due'] = (int)$st['chk']['at'];
        } elseif ($soft !== '') {
            $res['status'] = AUTOMATText::t('🌙 Paused: %s', $soft);
        } elseif ($st['down'] === $today) {
            $res['status'] = AUTOMATText::t('⬇️ Close command sent (this evening) — opens tomorrow morning');
        } elseif ($st['up'] === $today) {
            $res['status'] = (in_array($downRef, [0, 1], true) ? AUTOMATText::t('⬆️ Open — closes at %s', self::fmtClock(min($downTs, $latest), $now))
                : ($downRef === 2 ? AUTOMATText::t('⬆️ Open — closes when dark, at the latest %s', date('H:i', $latest)) : AUTOMATText::t('⬆️ Open — closes at "Night", at the latest %s', date('H:i', $latest))))
                . ($downRef === 2 && ($ctx['dark'] ?? null) === null ? ' ' . AUTOMATText::t('(⚠️ no brightness sensor under "🏠 House mode" — only "at the latest" applies)') : '');
            $res['due'] = in_array($downRef, [0, 1], true) ? min($downTs, $latest) : $now + 60;
        } else {
            $res['status'] = $upRef === 2 ? AUTOMATText::t('⬇️ Closed — opens at "Day", at the earliest %s', date('H:i', $earliest)) : AUTOMATText::t('⬇️ Closed — opens at %s', self::fmtClock($upAt, $now));
            $res['due'] = $upRef === 2 ? $now + 60 : $upAt;
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Melder zurücksetzen
    // ------------------------------------------------------------------

    /**
     * Ersetzt die frühere „Zwangsschaltung“ (Ablaufpläne mit Passwort/JSON-RPC, Dietmar 26.10.2026): Steht ein Melder
     * auf „wahr“ und kam seit der Haltezeit keine neue Meldung, wird er auf „falsch“ gesetzt. Gemessen ab der LETZTEN
     * Meldung — ein Melder, der bei Anwesenheit regelmäßig „wahr“ wiederholt, wird nicht dazwischen zurückgesetzt.
     */
    private static function ruecksetzen(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $mel = AUTOMATKatalog::vars($row, 'mr_mel');
        $halt = max(5, (int)($row['mr_halt'] ?? 60));
        $st += ['t' => [], 'hist' => []];
        $res['raw'] = true; // Sensorvariablen haben keine Aktion — direkt setzen
        $vid = (int)($ev['vid'] ?? 0);
        if (($ev['k'] ?? '') === 'trigger' && in_array($vid, $mel, true)) {
            if (self::isTrue($ev['v'] ?? false)) {
                $st['t'][(string)$vid] = $now;
            } else {
                unset($st['t'][(string)$vid]);
            }
        }
        foreach ($mel as $m) {
            $on = self::isTrue($ctx['val'][$m] ?? false);
            if ($on && !isset($st['t'][(string)$m])) {
                // Steht schon auf „wahr“ (erster Lauf, Neustart): letzte Meldung als Beginn
                $st['t'][(string)$m] = (int)($ctx['upd'][$m] ?? $now);
            } elseif (!$on) {
                unset($st['t'][(string)$m]);
            }
        }
        $hard = self::hardBlock($ctx);
        $done = [];
        if ($hard === '') {
            foreach ($st['t'] as $k => $t) {
                if ($now - (int)$t >= $halt) {
                    $res['cmd'][] = [(int)$k, false];
                    $done[] = self::nameOf((int)$k, $ctx);
                    $st['hist'][] = [$now, (int)$k];
                    unset($st['t'][$k]);
                }
            }
        }
        $st['hist'] = array_values(array_filter((array)$st['hist'], function ($h) use ($now) {
            return (int)$h[0] >= strtotime('-7 days', $now);
        }));
        if (count($done) > 0) {
            $res['log'] = AUTOMATText::t('%s: no new "true" report for %s s — reset.', implode(', ', $done), $halt);
        }
        $res['st'] = $st;
        $next = null;
        foreach ($st['t'] as $t) {
            $next = $next === null ? (int)$t + $halt : min($next, (int)$t + $halt);
        }
        if ($next !== null && $hard === '') {
            $res['due'] = $next;
        }
        $cnt = count($st['hist']);
        $per = [];
        foreach ($st['hist'] as $h) {
            $n = self::nameOf((int)$h[1], $ctx);
            $per[$n] = ($per[$n] ?? 0) + 1;
        }
        arsort($per);
        $top = array_slice($per, 0, 3, true);
        $res['status'] = $hard !== '' ? self::hardStatus($hard)
            : (count($st['t']) > 0 ? AUTOMATText::t('⏳ %s sensor(s) at "true" — reset at %s if no new report arrives', count($st['t']), date('H:i:s', (int)$next))
                : AUTOMATText::t('✅ All sensors quiet'))
            . ($cnt > 0 ? AUTOMATText::t(' · reset %s× in 7 days (%s)', $cnt, implode(', ', array_map(function ($n, $c) {
                return $n . ' ' . $c . '×';
            }, array_keys($top), $top))) : '');
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
            $res['status'] = AUTOMATText::t('❔ Power unknown');
            return $res;
        }
        if (!$on) {
            $st['below'] = 0;
            $res['status'] = AUTOMATText::t('⚪ Off');
        } elseif ($p >= $schwelle) {
            $st['below'] = 0;
            $res['status'] = AUTOMATText::t('▶️ In operation (%s W)', self::fmtNum($p));
        } else {
            if ((int)$st['below'] === 0) {
                $st['below'] = $now;
            }
            $at = (int)$st['below'] + $dauer;
            $soft = self::softBlock($row, $ctx);
            if ($now >= $at && $soft === '') {
                $res['cmd'][] = [$ov, self::offValue($ov, $ctx)];
                $res['log'] = AUTOMATText::t('In standby for %s (%s W) — switched off.', self::fmtDuration($now - (int)$st['below']), self::fmtNum($p));
                if (!empty($row['sb_push'])) {
                    $res['msg'][] = ['🔌 ' . (trim((string)($row['Name'] ?? '')) ?: self::nameOf($ov, $ctx)), AUTOMATText::t('Was in standby for %s — switched off.', self::fmtDuration($now - (int)$st['below']))];
                }
                $st['below'] = 0;
                $res['status'] = AUTOMATText::t('⚪ Switched off (standby)');
            } else {
                $res['due'] = $at;
                $res['end'] = $at;
                $res['status'] = $soft !== '' ? AUTOMATText::t('💤 Standby (%s W) — does not switch off: %s', self::fmtNum($p), $soft) : AUTOMATText::t('💤 Standby (%s W) — off at %s', self::fmtNum($p), self::fmtClock($at, $now));
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
        $name = trim((string)($row['Name'] ?? '')) ?: AUTOMATText::t('Ventilate');
        $st += ['ph' => 'ok', 'since' => 0, 'last' => 0];
        $hard = self::hardBlock($ctx);
        if ($hard !== '' || $v === null) {
            $res['st'] = $st;
            $res['status'] = $hard !== '' ? self::hardStatus($hard) : AUTOMATText::t('❔ Measured value missing');
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
            $res['log'] = AUTOMATText::t('Air too poor: %s — reminder to ventilate.', $txt);
            $res['msg'][] = ['💨 ' . $name, AUTOMATText::t('Please ventilate: %s.', $txt)];
        } elseif ($st['ph'] === 'hoch') {
            if ($v < $ok) {
                $st['ph'] = 'ok';
                if (count($kon) === 0 || count($open) > 0) {
                    $msg = count($open) > 0 ? AUTOMATText::t('Air good again (%s) — %s can be closed again.', self::fmtNum($v), implode(', ', $open)) : AUTOMATText::t('Air good again (%s).', self::fmtNum($v));
                    $res['log'] = $msg;
                    if ($soft === '') {
                        $res['msg'][] = ['✅ ' . $name, $msg];
                    }
                }
            } elseif (count($open) === 0 && $wdh > 0 && $now >= (int)$st['last'] + $wdh) {
                $st['last'] = $now;
                if ($soft === '') {
                    $res['msg'][] = [AUTOMATText::t('💨 %s (reminder)', $name), AUTOMATText::t('Still too high: %s.', $txt)];
                }
            }
        }
        $res['st'] = $st;
        switch ($st['ph']) {
            case 'steigt':
                $res['due'] = (int)$st['since'] + $dauer;
                $res['status'] = AUTOMATText::t('⏳ Too high: %s — reminder at %s', self::fmtNum($v), self::fmtClock((int)$st['since'] + $dauer, $now));
                break;
            case 'hoch':
                $res['status'] = count($open) > 0 ? AUTOMATText::t('🪟 Being ventilated: %s (good below %s)', self::fmtNum($v), self::fmtNum($ok)) : AUTOMATText::t('💨 Please ventilate: %s', self::fmtNum($v));
                if (count($open) === 0 && $wdh > 0) {
                    $res['due'] = (int)$st['last'] + $wdh;
                }
                break;
            default:
                $res['status'] = AUTOMATText::t('✅ Air good: %s', self::fmtNum($v));
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
        $force = ($ev['k'] ?? '') === 'run';
        if ($force) {
            // Von Hand über Automat: Handeingriffe vergessen und den Sollwert für den Hausmodus neu setzen
            $st['hand'] = [];
        }
        // Handbedienung nur an einer echten fremden Änderung erkennen (nicht am Tick: Funk-Thermostate melden den
        // neuen Wert oft erst nach Minuten, andere Automatismen schalten mit „eigenem“ Echo). Prüfbericht 17.10.2026.
        if (($ev['k'] ?? '') === 'output' && empty($ev['own']) && in_array((int)($ev['vid'] ?? 0), $soll, true)) {
            $hk = (string)(int)$ev['vid'];
            if (isset($set[$hk]) && abs((float)($ev['v'] ?? 0) - (float)$set[$hk]) > 0.05) {
                unset($set[$hk]);
                $st['hand'][$hk] = true; // Grundwert ($saved) bleibt gemerkt — gilt beim Heimkommen
                $logs[] = AUTOMATText::t('%s set manually to %s °C — stays that way', self::nameOf((int)$ev['vid'], $ctx), self::fmtNum((float)($ev['v'] ?? 0)));
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
                if (($force || !isset($set[$k]) || abs((float)$set[$k] - $target) > 0.05) && abs($cur - $target) > 0.05) {
                    $res['cmd'][] = [$vid, $target];
                }
                $set[$k] = $target;
            } elseif (isset($saved[$k])) {
                if (!empty($ctx['held'][$vid])) {
                    continue; // Fenster noch offen: zurückstellen, wenn es zu ist
                }
                if (($force || (isset($set[$k]) && abs($cur - (float)$set[$k]) <= 0.05)) && abs($cur - (float)$saved[$k]) > 0.05) {
                    $res['cmd'][] = [$vid, (float)$saved[$k]];
                    $logs[] = AUTOMATText::t('%s back to %s °C', self::nameOf($vid, $ctx), self::fmtNum((float)$saved[$k]));
                }
                unset($saved[$k], $set[$k]);
            }
        }
        $lowered = $target !== null && count($res['cmd']) > 0;
        if ($lowered) {
            $logs[] = AUTOMATText::t('%s setpoint(s) lowered to %s °C ("%s")', count($res['cmd']), self::fmtNum($target), AUTOMATKatalog::modes()[$mode]);
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
            $res['status'] = AUTOMATText::t('🌡 Lowered to %s °C ("%s")', self::fmtNum($target), AUTOMATKatalog::modes()[$mode]);
        } else {
            $res['status'] = AUTOMATText::t('✅ Normal operation');
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
        $res['status'] = $st['last'] !== '' ? AUTOMATText::t('🔘 Last: %s', $st['last']) : AUTOMATText::t('🔘 Waiting for a button press');
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
        foreach (['k' => 'short', 'd' => 'double', 'l' => 'long'] as $p => $label) {
            $label = AUTOMATText::t($label);
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
                $res['log'] = AUTOMATText::t('%s pressed — nothing done: %s.', ucfirst($label), $hard ?: $soft);
                return $res;
            }
            $z = (int)($row['tg_' . $p . 'ziel'] ?? 0);
            $zw = (float)($row['tg_' . $p . 'zwert'] ?? 0);
            switch ($akt) {
                case 1:
                    $isOn = self::isTrue($ctx['val'][$z] ?? false);
                    $res['cmd'][] = [$z, $isOn ? self::offValue($z, $ctx) : self::onValue($z, 100, $ctx)];
                    $did = $isOn ? AUTOMATText::t('%s off', self::nameOf($z, $ctx)) : AUTOMATText::t('%s on', self::nameOf($z, $ctx));
                    break;
                case 2:
                    $res['cmd'][] = [$z, self::onValue($z, 100, $ctx)];
                    $did = AUTOMATText::t('%s on', self::nameOf($z, $ctx));
                    break;
                case 3:
                    $res['cmd'][] = [$z, self::offValue($z, $ctx)];
                    $did = AUTOMATText::t('%s off', self::nameOf($z, $ctx));
                    break;
                case 4:
                    $res['cmd'][] = [$z, $zw];
                    $did = AUTOMATText::t('%s to %s', self::nameOf($z, $ctx), self::fmtNum($zw));
                    break;
                default:
                    $m = max(0, min(4, (int)round($zw)));
                    $res['mode'] = $m;
                    $did = AUTOMATText::t('House mode "%s"', AUTOMATKatalog::modes()[$m]);
            }
            $st['last'] = $label . ' → ' . $did . ' (' . date('H:i', $now) . ')';
            $res['st'] = $st;
            $res['log'] = AUTOMATText::t('%s pressed — %s.', ucfirst($label), $did);
            $res['status'] = AUTOMATText::t('🔘 Last: %s', $st['last']);
            return $res;
        }
        $res['log'] = AUTOMATText::t('Unknown button value "%s" — enter it in the dialog under short/double/long.', (string)($ev['v'] ?? ''));
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
                $res['log'] = AUTOMATText::t('Ramp cancelled: %s.', $hard);
            }
            $st['ph'] = 'idle';
            $res['st'] = $st;
            $res['status'] = self::hardStatus($hard);
            return $res;
        }
        if ($st['ph'] === 'ramp' && ($ev['k'] ?? '') === 'output' && empty($ev['own']) && in_array((int)($ev['vid'] ?? 0), $outs, true)) {
            $st['ph'] = 'idle';
            $res['log'] = AUTOMATText::t('Light adjusted manually — ramp ended.');
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
            $res['log'] = $up ? AUTOMATText::t('Wake-up light starts — until %s.', date('H:i', (int)$st['t1'])) : AUTOMATText::t('Fall-asleep light starts — until %s.', date('H:i', (int)$st['t1']));
            if (!$up && $from === 0) {
                $st['ph'] = 'idle';
                $res['log'] = AUTOMATText::t('Fall-asleep light: light is already off — nothing to do.');
            } elseif ($up && $from >= $ziel) {
                $st['ph'] = 'idle';
                $res['log'] = AUTOMATText::t('Wake-up light: light is already %s %% bright — nothing to do.', $from);
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
                $res['log'] = $up ? AUTOMATText::t('Wake-up light finished — %s %%.', $ziel) : AUTOMATText::t('Fall-asleep light finished — off.');
                $res['status'] = $up ? AUTOMATText::t('🌅 Finished — %s %%', $ziel) : AUTOMATText::t('🌙 Finished — off');
            } else {
                $res['due'] = min($t1, $now + 30);
                $res['end'] = $t1;
                $res['status'] = $up ? AUTOMATText::t('🌅 Getting brighter: %s %% (until %s)', $pct, date('H:i', $t1)) : AUTOMATText::t('🌙 Getting darker: %s %% (until %s)', $pct, date('H:i', $t1));
            }
        } else {
            if ($start === 2) {
                $res['status'] = AUTOMATText::t('💤 Starts on change to "Night"');
            } elseif ($begin === null) {
                $res['status'] = AUTOMATText::t('❔ No wake-up time');
            } else {
                $next = $begin > $now ? $begin : null;
                $res['status'] = $next !== null && $dayOk ? AUTOMATText::t('💤 Starts at %s', date('H:i', $next)) : AUTOMATText::t('💤 Done today or not a day for it');
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
        $modeName = AUTOMATKatalog::modes()[$mode] ?? (string)$mode;
        $name = trim((string)($row['Name'] ?? '')) ?: AUTOMATText::t('Scene');
        if (($ev['k'] ?? '') === 'run') {
            // Von Hand über Automat: jetzt ausführen, ohne Verzögerung und unabhängig vom Hausmodus
            $st['pend'] = $mode;
            $st['at'] = $now;
            $hard = '';
        }
        if (($ev['k'] ?? '') === 'mode') {
            $to = (int)($ev['to'] ?? $mode);
            if ((int)$st['pend'] >= 0 && (int)$st['pend'] !== $to) {
                $res['log'] = AUTOMATText::t('House mode changed to "%s" before the delay elapsed — "%s" switching cancelled.', AUTOMATKatalog::modes()[$to] ?? $to, AUTOMATKatalog::modes()[(int)$st['pend']] ?? '');
                $st['pend'] = -1;
                $st['at'] = 0;
            }
            if (!empty($row['sz_m' . $to])) {
                if ($hard !== '') {
                    $res['log'] = AUTOMATText::t('House mode "%s" — not switched: %s.', AUTOMATKatalog::modes()[$to] ?? $to, $hard);
                } elseif (isset($ctx['condOk']) && !$ctx['condOk']) {
                    $res['log'] = AUTOMATText::t('House mode "%s" — not switched: %s.', AUTOMATKatalog::modes()[$to] ?? $to, AUTOMATText::t('condition not met'));
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
                $res['log'] = AUTOMATText::t('House mode is no longer "%s" — switching cancelled.', AUTOMATKatalog::modes()[$pend] ?? $pend);
            } elseif ($hard !== '') {
                $res['log'] = AUTOMATText::t('Not switched: %s.', $hard);
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
                $verb = AUTOMATKatalog::tr(AUTOMATKatalog::SZ_ACTIONS)[$aktion] ?? AUTOMATText::t('switched');
                $verbPast = [0 => AUTOMATText::t('switched off'), 1 => AUTOMATText::t('switched on'), 2 => AUTOMATText::t('set to %s', self::fmtNum((float)($row['sz_wert'] ?? 0)))][$aktion] ?? AUTOMATText::t('switched');
                $res['log'] = count($changed) > 0
                    ? AUTOMATText::t('House mode "%s": %s device(s) %s (%s).', $modeName, count($changed), $verbPast, implode(', ', $changed))
                    : AUTOMATText::t('House mode "%s": all devices were already correct — nothing to %s.', $modeName, $verb);
                $st['last'] = $modeName . ': ' . (count($changed) > 0 ? count($changed) . ' ' . $verbPast : AUTOMATText::t('nothing to do'));
                $st['lastTs'] = $now;
                if (count($changed) > 0 && !empty($row['sz_push'])) {
                    $res['msg'][] = ['🌙 ' . $name, AUTOMATText::t('House mode "%s": %s %s.', $modeName, implode(', ', $changed), $verbPast)];
                }
            }
        }
        $res['st'] = $st;
        if ($hard !== '') {
            $res['status'] = self::hardStatus($hard);
        } elseif ((int)$st['pend'] >= 0) {
            $res['due'] = (int)$st['at'];
            $res['end'] = (int)$st['at'];
            $res['status'] = AUTOMATText::t('⏳ "%s": switches at %s', AUTOMATKatalog::modes()[(int)$st['pend']] ?? '', self::fmtClock((int)$st['at'], $now));
        } else {
            $res['status'] = $st['last'] !== '' ? '✅ ' . AUTOMATText::t('Last %s — %s', date('d.m.Y H:i', (int)$st['lastTs']), $st['last']) : AUTOMATText::t('💤 Waiting for the next house mode change');
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
            $res['status'] = self::hardStatus($hard) . ' ' . ($st['ph'] === 'weg' ? AUTOMATText::t('(result stays "nobody there")') : AUTOMATText::t('(result stays "somebody there")'));
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
                $res['log'] = $st['ph'] === 'weg' ? AUTOMATText::t('Somebody came home (%s).', implode(', ', $came ?: $home)) : AUTOMATText::t('Somebody is there again after all (%s).', implode(', ', $came ?: $home));
                if ($st['ph'] === 'weg' && !empty($row['an_push'])) {
                    $res['msg'][] = [AUTOMATText::t('🏠 Came home'), AUTOMATText::t('%s came home.', implode(', ', $came ?: $home))];
                }
                $st['since'] = $now;
            } elseif (count($came) > 0 || count($left) > 0) {
                $res['log'] = trim((count($came) > 0 ? AUTOMATText::t('%s arrived.', implode(', ', $came)) . ' ' : '') . (count($left) > 0 ? AUTOMATText::t('%s left.', implode(', ', $left)) . ' ' : '') . AUTOMATText::t('At home: %s.', implode(', ', $home)));
            }
            $st['ph'] = 'da';
            $st['until'] = 0;
        } else {
            if ($st['ph'] === 'da') {
                $st['ph'] = 'wartet';
                $st['until'] = $now + $wait;
                $allGone = $wait > 0 ? AUTOMATText::t('everybody gone, "nobody there" from %s if nobody comes back.', date('H:i', $st['until'])) : AUTOMATText::t('everybody gone.');
                $res['log'] = count($left) > 0 ? AUTOMATText::t('%s left — %s', implode(', ', $left), $allGone) : $allGone;
            }
            if ($st['ph'] === 'wartet' && $now >= (int)$st['until']) {
                $st['ph'] = 'weg';
                $st['since'] = $now;
                $res['self'] = false;
                $res['log'] = ($res['log'] !== null ? $res['log'] . ' ' : '') . AUTOMATText::t('Nobody at home anymore.');
                if (!empty($row['an_push'])) {
                    $res['msg'][] = [AUTOMATText::t('🚪 Everybody gone'), AUTOMATText::t('Nobody at home anymore (since %s).', date('H:i', $now))];
                }
            }
        }
        $st['home'] = $home;
        $res['st'] = $st;
        // Ergebnis bei jedem Lauf melden (das Modul schreibt nur bei Änderung) — nach einem Probelauf stand die
        // Variable sonst bis zum nächsten Wechsel falsch (Prüfbericht 17.10.2026).
        $res['self'] = $st['ph'] !== 'weg';
        switch ($st['ph']) {
            case 'da':
                $res['status'] = AUTOMATText::t('🏠 At home: %s', implode(', ', $home));
                break;
            case 'wartet':
                $res['due'] = (int)$st['until'];
                $res['end'] = (int)$st['until'];
                $res['status'] = AUTOMATText::t('🚪 Everybody gone — "nobody there" at %s', self::fmtClock((int)$st['until'], $now));
                break;
            default:
                $res['status'] = AUTOMATText::t('🚪 Nobody at home since %s', date('d.m.Y H:i', (int)$st['since']));
        }
        return $res;
    }
}
