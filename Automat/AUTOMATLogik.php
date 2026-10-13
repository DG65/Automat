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
    public static function step(array $row, array $st, array $ev, array $ctx): array
    {
        $type = (string)($row['Type'] ?? '');
        // 'end' = Zeitpunkt des nächsten sichtbaren Ereignisses (Licht aus, Absenkung, Meldung) für die Restzeit-Anzeige.
        $res = ['st' => $st, 'cmd' => [], 'msg' => [], 'log' => null, 'status' => '', 'due' => null, 'end' => null];
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
            return in_array($s, ['true', 'wahr', 'an', 'ein', 'on', 'ja', 'offen', 'open'], true);
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
        return $day . date('H:i:s', $ts) . ' Uhr (in ' . $txt . ')';
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
                $res['log'] = 'Licht wurde von Hand ausgeschaltet — Zeit abgebrochen.';
            }
        }
        $res['st'] = $st;
        return self::lightTimers($row, $res, $ctx, $outs, 'zl', (int)($row['zl_vorwarn'] ?? 0));
    }

    /**
     * Gemeinsamer Teil Zeitlicht/Bewegungslicht: harte Sperre und Dauerlicht.
     * Setzt $res['done'] = true, wenn die Entscheidung damit gefallen ist.
     */
    private static function lightCommon(array $row, array $st, array $ev, array $ctx, array $res, array $outs, string $p, int $dauer): array
    {
        $now = (int)$ctx['now'];
        $hard = self::hardBlock($ctx);
        if ($hard !== '') {
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
            $st['until'] = $st['ph'] === 'an' ? $now + $dauer : 0;
            $res['log'] = 'Sperre aufgehoben' . ($st['ph'] === 'an' ? ' — Licht ist an, geht um ' . date('H:i:s', $st['until']) . ' Uhr aus.' : '.');
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
            $st['until'] = $now + $dauer;
            $res['log'] = 'Dauerlicht beendet — Licht geht um ' . date('H:i:s', $st['until']) . ' Uhr aus.';
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

    private static function bewegung(array $row, array $st, array $ev, array $ctx, array $res): array
    {
        $now = (int)$ctx['now'];
        $mel = AUTOMATKatalog::vars($row, 'bl_mel');
        $outs = AUTOMATKatalog::vars($row, 'bl_out');
        $nach = max(1, (int)($row['bl_nachlauf'] ?? 120));
        $impuls = !empty($row['bl_impuls']);
        $handPause = max(0, (int)($row['bl_handpause'] ?? 0)) * 60;
        $st += ['ph' => 'aus', 'until' => 0, 'fl' => 0, 'hu' => 0];
        if (!isset($st['act']) || !is_array($st['act'])) {
            // Erster Lauf: Melderzustand aus den aktuellen Werten übernehmen.
            $st['act'] = [];
            foreach ($mel as $m) {
                $st['act'][$m] = !$impuls && self::isTrue($ctx['val'][$m] ?? false);
            }
        }
        $res = self::lightCommon($row, $st, $ev, $ctx, $res, $outs, 'bl', $nach);
        if ($res['done'] ?? false) {
            unset($res['done']);
            return $res;
        }
        $st = $res['st'];
        $k = $ev['k'] ?? 'tick';
        $vid = (int)($ev['vid'] ?? 0);

        if ($k === 'trigger' && in_array($vid, $mel, true)) {
            $active = self::isTrue($ev['v'] ?? false);
            if (!$impuls) {
                $st['act'][$vid] = $active;
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
                        } elseif ($luxVal !== null && $luxVal >= $luxMax) {
                            $res['log'] = 'Bewegung — nicht eingeschaltet: hell genug (' . self::fmtNum($luxVal) . ' lx ≥ ' . self::fmtNum($luxMax) . ' lx).';
                        } elseif (self::anyOn($outs, $ctx)) {
                            $res['log'] = 'Bewegung — Licht war schon an (von Hand oder anderer Steuerung), wird nicht übernommen.';
                        } else {
                            $res['cmd'] = self::onCmds($outs, $row, $ctx, 'bl');
                            $st['ph'] = 'an';
                            $st['until'] = $impuls ? $now + $nach : 0;
                            $res['log'] = 'Bewegung — Licht an.';
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
            } elseif (in_array($st['ph'], ['an', 'warn', 'flash'], true) && (int)$st['until'] === 0) {
                $st['until'] = $now + $nach;
                $res['log'] = 'Keine Bewegung mehr — Licht geht um ' . date('H:i:s', $st['until']) . ' Uhr aus.';
            }
        } elseif ($k === 'output' && empty($ev['own']) && in_array($vid, $outs, true)) {
            if (self::isTrue($ev['v'] ?? false)) {
                if ($st['ph'] === 'aus' || $st['ph'] === 'hand') {
                    $st['ph'] = 'an';
                    $st['until'] = $now + max($nach, $handPause);
                    $res['log'] = 'Licht wurde von Hand eingeschaltet — geht um ' . date('H:i:s', $st['until']) . ' Uhr aus, Bewegung verlängert.';
                }
            } elseif (!self::anyOn($outs, $ctx) && in_array($st['ph'], ['an', 'warn', 'flash'], true)) {
                if ($handPause > 0) {
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
        $res['st'] = $st;
        return self::lightTimers($row, $res, $ctx, $outs, 'bl', (int)($row['bl_vorwarn'] ?? 0));
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
            $end = self::timeOn((string)($row['dz_auszeit'] ?? AUTOMATKatalog::time(23, 0)), $ymd);
            if ($end <= $start) {
                $end = self::timeOn((string)($row['dz_auszeit'] ?? AUTOMATKatalog::time(23, 0)), $next);
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
        $hi = $schwelle * 1.25 + 5; // Hysterese gegen Flackern in der Dämmerung

        if (($ev['k'] ?? '') === 'output' && empty($ev['own']) && in_array((int)($ev['vid'] ?? 0), $outs, true)
            && !self::isTrue($ev['v'] ?? false) && $st['on'] && !self::anyOn($outs, $ctx)) {
            $st['on'] = false;
            $st['manual'] = $key;
            $res['log'] = 'Von Hand ausgeschaltet — bis zum nächsten Schaltfenster bleibt es aus.';
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
            $v = self::isTrue($ctx['val'][$k] ?? false);
            if ($invert ? !$v : $v) {
                $open = true;
            }
        }
        $restore = function () use (&$st, &$res, $soll) {
            foreach ($soll as $vid) {
                if (isset($st['saved'][$vid])) {
                    $res['cmd'][] = [$vid, (float)$st['saved'][$vid]];
                }
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
                    $cur = (float)($ctx['val'][$s] ?? $absenk);
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
                $res['log'] = 'Fenster geschlossen — Sollwert zurück auf ' . $restore() . '.';
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
        $cmpText = in_array($op, [4, 5], true) ? $opText : $opText . ' ' . $ref;
        $hard = self::hardBlock($ctx);
        $blocked = $hard !== '' ? $hard : self::softBlock($row, $ctx);
        $text = function (int $since) use ($row, $varName, $cmpText, $now, $v) {
            $t = trim((string)($row['wa_text'] ?? ''));
            if ($t !== '') {
                return $t;
            }
            return $varName . ' ' . $cmpText . ' — seit ' . self::fmtDuration(max(60, $now - $since)) . ' (aktuell ' . self::fmtNum($v) . ').';
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
     * Welcher Hausmodus gälte jetzt nach Uhrzeit/Anwesenheit?
     * $presence: true = jemand da, false = niemand da, null = keine Variable.
     * Urlaub wird nie automatisch gesetzt und nie automatisch verlassen
     * (außer der Nutzer stellt selbst um).
     */
    public static function autoMode(int $now, string $dayFrom, int $eveningRef, string $eveningFrom, string $nightFrom, ?int $sunset, ?bool $presence, int $current): int
    {
        if ($current === AUTOMATKatalog::MODE_HOLIDAY) {
            return $current;
        }
        if ($presence === false) {
            return AUTOMATKatalog::MODE_AWAY;
        }
        $today = date('Y-m-d', $now);
        $day = self::timeOn($dayFrom, $today);
        $evening = $eveningRef === 0 && $sunset !== null ? $sunset : self::timeOn($eveningFrom, $today);
        $night = self::timeOn($nightFrom, $today);
        if ($now < $day || $now >= $night) {
            return AUTOMATKatalog::MODE_NIGHT;
        }
        if ($now >= $evening) {
            return 1;
        }
        return 0;
    }
}
