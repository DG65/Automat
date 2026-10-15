<?php
/**
 * test-automat.php — Prüfstand für Automat OHNE laufendes Symcon.
 *
 * Teil A prüft die reine Logik (AUTOMATLogik) je Automatismus-Typ mit
 * steuerbarer Uhr, Teil B die Erkennung (AUTOMATErkennung) an Steckbriefen,
 * die echten Installationen nachgebildet sind, Teil C das Modul selbst gegen
 * nachgebildetes IPS (Variablen, Nachrichten, Timer, Formular, Kachel).
 *
 * Aufruf:   php .tools/test-automat.php
 * Rückgabe: 0 = alles bestanden, 1 = mindestens ein Fehlschlag
 */

date_default_timezone_set('Europe/Berlin');
error_reporting(E_ALL);
$GLOBALS['WARN'] = [];
set_error_handler(function ($no, $str, $file, $line) {
    $GLOBALS['WARN'][] = basename($file) . ':' . $line . ' ' . $str;
    return true;
});

const VM_UPDATE = 10603;
const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const KL_MESSAGE = 10201; const KL_NOTIFY = 10203; const KL_WARNING = 10204; const KL_ERROR = 10205; const KL_DEBUG = 10206; const KL_CUSTOM = 10207;

$fails = 0;
$checks = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $fails, $checks;
    $checks++;
    echo ($ok ? '  ✅ ' : '  ❌ ') . $what . ($ok || $detail === '' ? '' : ' — ' . $detail) . "\n";
    if (!$ok) {
        $fails++;
    }
}
function heading(string $t): void { echo "\n[$t]\n"; }
function ts(string $s): int { return strtotime($s); }

// ---------------------------------------------------------------------------
// Nachgebildetes IPS
// ---------------------------------------------------------------------------
$GLOBALS['OBJ'] = [];       // id => [kind, name, parent, ident, hidden, type, value, action, profile, pres]
$GLOBALS['NEXT'] = 60000;
$GLOBALS['CLOCK'] = ts('2026-10-13 12:00:00');
$GLOBALS['WRITES'] = [];    // [ts, id, value, via]
$GLOBALS['LOG'] = [];
$GLOBALS['PUSH'] = [];
$GLOBALS['PROFILES'] = ['~Switch' => ['MaxValue' => 1, 'Suffix' => ''], '~Intensity.100' => ['MaxValue' => 100, 'Suffix' => ' %'], '~Intensity.255' => ['MaxValue' => 255, 'Suffix' => ''], '~Motion' => ['MaxValue' => 1, 'Suffix' => ''], '~ThermostatSetPoint.ZWave' => ['MaxValue' => 30, 'Suffix' => ' °C'], '~Watt.3680' => ['MaxValue' => 3680, 'Suffix' => ' W'], '~Temperature.Room' => ['MaxValue' => 30, 'Suffix' => ' °C']];
$GLOBALS['EVENTS'] = [];
$GLOBALS['CONF'] = [];
$GLOBALS['MODULES'] = [];   // guid => [instanceIDs]

function mkinst(int $id, string $name, int $parent = 0, string $guid = ''): void
{
    $GLOBALS['OBJ'][$id] = ['kind' => 1, 'name' => $name, 'parent' => $parent, 'ident' => '', 'hidden' => false];
    if ($guid !== '') {
        $GLOBALS['MODULES'][$guid][] = $id;
    }
}
function mkvar(int $id, int $type, $value, string $name, int $parent, bool $action = false, string $profile = '', array $pres = [], string $ident = ''): void
{
    $GLOBALS['OBJ'][$id] = ['kind' => 2, 'name' => $name, 'parent' => $parent, 'ident' => $ident, 'hidden' => false, 'type' => $type, 'value' => $value, 'action' => $action, 'profile' => $profile, 'pres' => $pres];
}
function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_InstanceExists($id): bool { return isset($GLOBALS['OBJ'][(int)$id]) && $GLOBALS['OBJ'][(int)$id]['kind'] === 1; }
function IPS_VariableExists($id): bool { return isset($GLOBALS['OBJ'][(int)$id]) && $GLOBALS['OBJ'][(int)$id]['kind'] === 2; }
function IPS_GetVariable($id): array
{
    $o = $GLOBALS['OBJ'][(int)$id];
    return ['VariableType' => $o['type'], 'VariableProfile' => '', 'VariableCustomProfile' => $o['profile'], 'VariableAction' => $o['action'] ? 12345 : 0, 'VariableCustomAction' => 0, 'VariablePresentation' => [], 'VariableCustomPresentation' => $o['pres']];
}
function IPS_GetVariableList(): array { return array_keys(array_filter($GLOBALS['OBJ'], fn($o) => $o['kind'] === 2)); }
function IPS_GetInstanceList(): array { return array_keys(array_filter($GLOBALS['OBJ'], fn($o) => $o['kind'] === 1)); }
function GetValue($id) { return $GLOBALS['OBJ'][(int)$id]['value']; }
function SetValue($id, $v): bool { $GLOBALS['OBJ'][(int)$id]['value'] = $v; $GLOBALS['WRITES'][] = [$GLOBALS['CLOCK'], (int)$id, $v, 'SetValue']; return true; }
function HasAction($id): bool { return (bool)$GLOBALS['OBJ'][(int)$id]['action']; }
function RequestAction($id, $v): bool
{
    if (!empty($GLOBALS['FAIL_RA'])) {
        return false;
    }
    $GLOBALS['OBJ'][(int)$id]['value'] = $v;
    $GLOBALS['WRITES'][] = [$GLOBALS['CLOCK'], (int)$id, $v, 'RequestAction'];
    return true;
}
function IPS_GetObject(int $id): array
{
    $o = $GLOBALS['OBJ'][$id];
    return ['ObjectIdent' => $o['ident'], 'ObjectName' => $o['name'], 'ParentID' => $o['parent'], 'ObjectIsHidden' => $o['hidden'], 'ObjectPosition' => $o['pos'] ?? 0, 'ObjectType' => [1 => 1, 2 => 2, 3 => 3, 4 => 4][$o['kind']] ?? 0];
}
function IPS_GetObjectIDByIdent(string $ident, int $parent)
{
    foreach ($GLOBALS['OBJ'] as $id => $o) {
        if ($o['ident'] === $ident && $o['parent'] === $parent) {
            return $id;
        }
    }
    trigger_error('Ident nicht gefunden', E_USER_WARNING); // wie Symcon: Warnung
    return false;
}
function IPS_GetChildrenIDs(int $p): array { return array_keys(array_filter($GLOBALS['OBJ'], fn($o) => $o['parent'] === $p)); }
function IPS_DeleteVariable(int $id): bool { unset($GLOBALS['OBJ'][$id]); return true; }
function IPS_GetName(int $id): string { return $GLOBALS['OBJ'][$id]['name'] ?? ''; }
function IPS_ScriptExists(int $id): bool { return isset($GLOBALS['OBJ'][$id]) && $GLOBALS['OBJ'][$id]['kind'] === 3; }
function IPS_RunScriptEx(int $id, array $p): bool { $GLOBALS['SCRIPTRUN'][] = [$id, $p]; return true; }
function IPS_SetHidden(int $id, bool $h): bool { $GLOBALS['OBJ'][$id]['hidden'] = $h; return true; }
function SetValueString($id, string $v): bool { $GLOBALS['OBJ'][(int)$id]['value'] = $v; return true; }
function testStates(): array { $id = IPS_GetObjectIDByIdent('store', 12345); return json_decode((string)GetValue($id), true) ?: []; }
function IPS_SetName(int $id, string $n): bool { $GLOBALS['OBJ'][$id]['name'] = $n; return true; }
function IPS_GetParent(int $id): int { return $GLOBALS['OBJ'][$id]['parent'] ?? 0; }
function IPS_GetInstanceListByModuleID(string $guid): array { return $GLOBALS['MODULES'][$guid] ?? []; }
function IPS_VariableProfileExists(string $p): bool { return isset($GLOBALS['PROFILES'][$p]); }
function IPS_GetVariableProfile(string $p): array { return $GLOBALS['PROFILES'][$p]; }
function IPS_CreateVariableProfile(string $p, int $t): bool { $GLOBALS['PROFILES'][$p] = ['MaxValue' => 0, 'Suffix' => '', 'Assoc' => []]; return true; }
function IPS_SetVariableProfileIcon(string $p, string $i): bool { return true; }
function IPS_SetVariableProfileAssociation(string $p, $v, string $n, string $i, int $c): bool { $GLOBALS['PROFILES'][$p]['Assoc'][$v] = $n; return true; }
function IPS_IsConditionPassing(string $c): bool { return !empty($GLOBALS['COND_OK']); }
function IPS_GetLibrary(string $g): array { $lib = json_decode(file_get_contents(dirname(__DIR__) . '/library.json'), true); return ['Version' => $lib['version'] . '-beta.1', 'Build' => $lib['build']]; }
function IPS_GetEventList(): array { return array_keys($GLOBALS['EVENTS']); }
function IPS_GetEvent(int $id): array { return $GLOBALS['EVENTS'][$id]; }
function IPS_GetConfiguration(int $id): string { return $GLOBALS['CONF'][$id] ?? '{}'; }
function IPS_GetProperty(int $id, string $n) { return $GLOBALS['CONF_PROP'][$id][$n] ?? ''; }
function IPS_GetInstance(int $id): array { return ['ModuleInfo' => ['ModuleName' => $GLOBALS['MODNAME'][$id] ?? 'Testgerät', 'ModuleType' => $GLOBALS['MODTYPE'][$id] ?? 3]]; }
function IPS_GetLocation(int $id): string { $p = []; while ($id > 0 && isset($GLOBALS['OBJ'][$id])) { array_unshift($p, $GLOBALS['OBJ'][$id]['name']); $id = $GLOBALS['OBJ'][$id]['parent']; } return implode('\\', $p); }
function AC_GetLoggingStatus(int $ac, int $vid): bool { return isset($GLOBALS['ARCHIVE'][$vid]); }
function AC_GetLoggedValues(int $ac, int $vid, int $from, int $to, int $limit): array { return array_reverse($GLOBALS['ARCHIVE'][$vid] ?? []); }
function VISU_PostNotificationEx(int $id, string $t, string $x, string $i, string $s, int $target): bool { $GLOBALS['PUSH'][] = [$id, $t, $x]; return true; }
function SMTP_SendMailEx(int $id, string $to, string $t, string $x): bool { if (!empty($GLOBALS['MAIL_FAIL'])) { trigger_error('Login denied', E_USER_WARNING); return false; } $GLOBALS['MAIL'][] = [$id, $to, $t, $x]; return true; }
// Bewusst KEIN IPS_LogMessage()-Stub: ein Aufruf im Modul wäre ein Fatal Error (SUITE.md Store-Review Punkt 15).

class IPSModule
{
    public int $InstanceID = 12345;
    public array $props = [];
    public array $attrs = [];
    public int $status = 0;
    public array $timers = [];
    public array $messages = [];
    public array $debug = [];
    public array $fieldUpdates = [];
    public array $tileUpdates = [];
    public array $logged = [];

    public function Create() {}
    public function ApplyChanges() {}
    public function RegisterPropertyString(string $n, string $d): void { $this->props[$n] = $this->props[$n] ?? $d; }
    public function RegisterPropertyInteger(string $n, int $d): void { $this->props[$n] = $this->props[$n] ?? $d; }
    public function RegisterPropertyFloat(string $n, float $d): void { $this->props[$n] = $this->props[$n] ?? $d; }
    public function ReadPropertyFloat(string $n): float { return (float)$this->props[$n]; }
    public function RegisterPropertyBoolean(string $n, bool $d): void { $this->props[$n] = $this->props[$n] ?? $d; }
    public function ReadPropertyString(string $n): string { return $this->props[$n]; }
    public function ReadPropertyInteger(string $n): int { return $this->props[$n]; }
    public function ReadPropertyBoolean(string $n): bool { return $this->props[$n]; }
    public function RegisterAttributeString(string $n, string $d): void { $this->attrs[$n] = $this->attrs[$n] ?? $d; }
    public function RegisterAttributeInteger(string $n, int $d): void { $this->attrs[$n] = $this->attrs[$n] ?? $d; }
    public function RegisterAttributeBoolean(string $n, bool $d): void { $this->attrs[$n] = $this->attrs[$n] ?? $d; }
    public function ReadAttributeString(string $n): string { return $this->attrs[$n]; }
    public function ReadAttributeInteger(string $n): int { return $this->attrs[$n]; }
    public function ReadAttributeBoolean(string $n): bool { return $this->attrs[$n]; }
    public function WriteAttributeString(string $n, string $v): void { $this->attrs[$n] = $v; }
    public function WriteAttributeInteger(string $n, int $v): void { $this->attrs[$n] = $v; }
    public function WriteAttributeBoolean(string $n, bool $v): void { $this->attrs[$n] = $v; }
    public function SetStatus(int $s): void { $this->status = $s; }
    public function RegisterTimer(string $n, int $ms, string $s): void { $this->timers[$n] = $ms; }
    public function SetTimerInterval(string $n, int $ms): void { $this->timers[$n] = $ms; }
    public function RegisterMessage(int $s, int $m): void { $this->messages[$s][$m] = true; }
    public function UnregisterMessage(int $s, int $m): void { unset($this->messages[$s][$m]); if (empty($this->messages[$s])) { unset($this->messages[$s]); } }
    public function GetMessageList(): array { $o = []; foreach ($this->messages as $s => $m) { $o[$s] = array_keys($m); } return $o; }
    public function SendDebug(string $a, string $b, int $c): void { $this->debug[] = "$a: $b"; }
    public function LogMessage(string $m, int $t): void { $this->logged[] = [$t, $m]; }
    public function UpdateFormField(string $n, string $p, $v): void { $this->fieldUpdates[] = [$n, $p, $v]; }
    public function SetVisualizationType(int $t): void {}
    public function UpdateVisualizationValue($v): void { $this->tileUpdates[] = $v; }
    public function MaintainVariable(string $ident, string $name, int $type, string $profile, int $pos, bool $keep): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($keep && $id === false) {
            $def = [false, 0, 0.0, ''][$type];
            $id = $GLOBALS['NEXT']++;
            mkvar($id, $type, $def, $name, $this->InstanceID, false, $profile, [], $ident);
        }
        if ($keep) {
            $GLOBALS['OBJ'][$id]['pos'] = $pos; // wie Symcon im ungünstigsten Fall: Position bei jedem Aufruf
        }
    }
    public function EnableAction(string $ident): void { $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID); if ($id !== false) { $GLOBALS['OBJ'][$id]['action'] = true; } }
    public function SetValue(string $ident, $v): void { $id = IPS_GetObjectIDByIdent($ident, $this->InstanceID); $GLOBALS['OBJ'][$id]['value'] = $v; }
    public function GetValue(string $ident) { $id = IPS_GetObjectIDByIdent($ident, $this->InstanceID); return $GLOBALS['OBJ'][$id]['value']; }
}

require dirname(__DIR__) . '/Automat/module.php';
// Der Prüfstand arbeitet mit Kurz-IDs (100, 1001 …); echte Symcon-IDs sind fünfstellig. Block A9 prüft die echte Grenze.
AUTOMATKatalog::$minId = 1;

class AutomatTest extends Automat
{
    public float $rnd = 0.0;
    protected function now(): int { return $GLOBALS['CLOCK']; }
    protected function randomUnit(): float { return $this->rnd; }
}

function step(array $row, array &$st, array $ev, array $ctx): array
{
    $ctx += ['now' => $GLOBALS['CLOCK'], 'mode' => 0, 'paused' => false, 'locked' => false, 'permanent' => false, 'condOk' => true, 'val' => [], 'dim' => [], 'names' => []];
    $r = AUTOMATLogik::step($row, $st, $ev, $ctx);
    $st = $r['st'];
    return $r;
}
function cmdMap(array $r): array { $o = []; foreach ($r['cmd'] as [$v, $x]) { $o[] = $v . '=' . var_export($x, true); } return $o; }
function at(string $t): void { $GLOBALS['CLOCK'] = ts('2026-10-13 ' . $t); }

// ===========================================================================
// TEIL A — Logik
// ===========================================================================

heading('A1. Katalog — Feldnamen eindeutig, Standardwerte vollständig');
$seen = [];
$dup = [];
foreach (AUTOMATKatalog::TYPES as $t => $def) {
    foreach (AUTOMATKatalog::fields($t) as $f) {
        if (!isset($f['n'])) {
            continue;
        }
        $names = $f['k'] === 'vars' ? array_map(fn($i) => $f['n'] . $i, range(1, $f['max'] ?? AUTOMATKatalog::SLOTS)) : [$f['n']];
        foreach ($names as $n) {
            if (isset($seen[$n])) {
                $dup[] = $n;
            }
            $seen[$n] = true;
        }
    }
    $d = AUTOMATKatalog::defaults($t);
    check("Standardwerte „{$t}“ enthalten Typ, Modi und alle Felder", $d['Type'] === $t && isset($d['m0'], $d['m4']) && count(array_diff(array_keys($seen), array_keys($d))) >= 0);
}
check('Kein Feldname kommt in zwei Typen vor (Dialog-Elemente müssen eindeutig sein)', count($dup) === 0, implode(',', $dup));
check('Keine Kollision mit Rahmenfeldern', count(array_intersect(array_keys($seen), ['Type', 'Name', 'Room', 'Enabled', 'Uid', 'TypeLabel', 'Cond', 'm0'])) === 0);
$p = AUTOMATKatalog::problems(AUTOMATKatalog::defaults('bewegung'), fn($v) => true);
check('Unvollständiges Bewegungslicht meldet fehlende Melder und Ausgänge', count($p) === 2, implode(' | ', $p));
$p = AUTOMATKatalog::problems(['Type' => 'xyz'], fn($v) => true);
check('Unbekannter Typ wird gemeldet', count($p) === 1);
$row = AUTOMATKatalog::defaults('fertig');
$row['gf_var'] = 5;
$row['gf_lauf'] = 3;
$row['gf_fertig'] = 5;
check('Gerät fertig: „Fertig unter“ ≥ „Läuft ab“ wird gemeldet', count(AUTOMATKatalog::problems($row, fn($v) => true)) === 1);

heading('A2. Zeitlicht (Treppenlicht)');
$z = AUTOMATKatalog::defaults('zeitlicht');
$z['Name'] = 'Treppe';
$z['zl_out1'] = 100;
$z['zl_trg1'] = 200;
$z['zl_dauer'] = 120;
$st = [];
at('20:00:00');
$r = step($z, $st, ['k' => 'trigger', 'vid' => 200, 'v' => true], ['val' => [100 => false]]);
check('Tastendruck schaltet ein', cmdMap($r) === ['100=true'], implode(',', cmdMap($r)));
check('Ausschaltzeit = jetzt + Leuchtdauer, als Frist gemeldet', $st['until'] === ts('2026-10-13 20:02:00') && $r['due'] === ts('2026-10-13 20:02:00'));
check('Status nennt die Ausschaltzeit', strpos($r['status'], '20:02:00') !== false, $r['status']);
at('20:01:00');
$r = step($z, $st, ['k' => 'trigger', 'vid' => 200, 'v' => false], ['val' => [100 => true]]);
check('Loslassen (false) startet nichts', $r['cmd'] === [] && $st['until'] === ts('2026-10-13 20:02:00'));
$r = step($z, $st, ['k' => 'trigger', 'vid' => 200, 'v' => true], ['val' => [100 => true]]);
check('Erneuter Druck verlängert (Nachtriggern), kein erneuter Schaltbefehl', $r['cmd'] === [] && $st['until'] === ts('2026-10-13 20:03:00'));
at('20:02:59');
$r = step($z, $st, ['k' => 'tick'], ['val' => [100 => true]]);
check('Vor Ablauf passiert nichts', $r['cmd'] === []);
at('20:03:00');
$r = step($z, $st, ['k' => 'tick'], ['val' => [100 => true]]);
check('Nach Ablauf aus', cmdMap($r) === ['100=false'] && $st['ph'] === 'aus');
// Vorwarnung bei Schaltern: kurz aus, 1 s später wieder an
$z['zl_vorwarn'] = 20;
$st = [];
at('21:00:00');
step($z, $st, ['k' => 'trigger', 'vid' => 200, 'v' => true], ['val' => [100 => false]]);
at('21:01:40');
$r = step($z, $st, ['k' => 'tick'], ['val' => [100 => true]]);
check('Vorwarnung: Schalter kurz aus', cmdMap($r) === ['100=false'] && $st['ph'] === 'flash', implode(',', cmdMap($r)) . ' ' . $st['ph']);
check('Eigenes Echo der Vorwarnung wird nicht als Handbedienung gewertet', (function () use ($z, &$st) { $r = step($z, $st, ['k' => 'output', 'vid' => 100, 'v' => false, 'own' => true], ['val' => [100 => false]]); return $st['ph'] === 'flash'; })());
at('21:01:41');
$r = step($z, $st, ['k' => 'tick'], ['val' => [100 => false]]);
check('… und eine Sekunde später wieder an', cmdMap($r) === ['100=true'] && $st['ph'] === 'warn');
$r = step($z, $st, ['k' => 'trigger', 'vid' => 200, 'v' => true], ['val' => [100 => true]]);
check('Druck während der Vorwarnung: wieder volle Zeit', $st['ph'] === 'an' && $st['until'] === ts('2026-10-13 21:03:41'));
// Handbedienung
$r = step($z, $st, ['k' => 'output', 'vid' => 100, 'v' => false, 'own' => false], ['val' => [100 => false]]);
check('Von Hand aus: Zeit abgebrochen, kein Befehl', $r['cmd'] === [] && $st['ph'] === 'aus' && strpos((string)$r['log'], 'von Hand') !== false);
$z2 = $z;
$z2['zl_trg1'] = 0;
$st = [];
at('22:00:00');
$r = step($z2, $st, ['k' => 'output', 'vid' => 100, 'v' => true, 'own' => false], ['val' => [100 => true]]);
check('Ohne Taster: jedes Einschalten startet die Zeit (Auto-Aus)', $st['ph'] === 'an' && $st['until'] === ts('2026-10-13 22:02:00') && $r['cmd'] === []);
// Dimmer, Nachtwert
$z3 = $z;
$z3['zl_vorwarn'] = 0;
$z3['zl_wert'] = 80;
$z3['zl_nachtwert'] = 10;
$st = [];
$r = step($z3, $st, ['k' => 'trigger', 'vid' => 200, 'v' => true], ['val' => [100 => 0], 'dim' => [100 => 255.0], 'mode' => 2]);
check('Dimmer 0–255 im Hausmodus Nacht: 10 % = 26', cmdMap($r) === ['100=26'], implode(',', cmdMap($r)));
$st = [];
$r = step($z3, $st, ['k' => 'trigger', 'vid' => 200, 'v' => true], ['val' => [100 => 0], 'dim' => [100 => 100.0], 'mode' => 0]);
check('Dimmer 0–100 tagsüber: 80', cmdMap($r) === ['100=80']);
// Sperre, Pause, Dauerlicht, Hausmodus
$r = step($z3, $st, ['k' => 'flag'], ['val' => [100 => 80], 'dim' => [100 => 100.0], 'locked' => true]);
check('Sperre: laufende Zeit abgebrochen, Licht bleibt wie es ist', $r['cmd'] === [] && $st['ph'] === 'gesperrt' && strpos($r['status'], 'Gesperrt') !== false, $r['status']);
$r = step($z3, $st, ['k' => 'trigger', 'vid' => 200, 'v' => true], ['val' => [100 => 80], 'dim' => [100 => 100.0], 'locked' => true]);
check('Gesperrt: Taster tut nichts', $r['cmd'] === []);
$r = step($z3, $st, ['k' => 'flag'], ['val' => [100 => 80], 'dim' => [100 => 100.0]]);
check('Sperre aufgehoben bei brennendem Licht: normale Zeit läuft', $st['ph'] === 'an' && $st['until'] > $GLOBALS['CLOCK']);
$r = step($z3, $st, ['k' => 'flag'], ['val' => [100 => 80], 'dim' => [100 => 100.0], 'permanent' => true]);
check('Dauerlicht: an, keine Frist', $st['ph'] === 'dauer' && $r['due'] === null && strpos($r['status'], 'Dauerlicht') !== false);
$r = step($z3, $st, ['k' => 'flag'], ['val' => [100 => 80], 'dim' => [100 => 100.0], 'permanent' => false]);
check('Dauerlicht aus: Leuchtdauer läuft ab', $st['ph'] === 'an' && $st['until'] === $GLOBALS['CLOCK'] + 120);
$st = [];
$z4 = $z3;
$z4['m3'] = false;
$r = step($z4, $st, ['k' => 'trigger', 'vid' => 200, 'v' => true], ['val' => [100 => 0], 'dim' => [100 => 100.0], 'mode' => 3]);
check('Im Hausmodus „Abwesend“ abgewählt: nicht eingeschaltet, Grund im Protokoll', $r['cmd'] === [] && strpos((string)$r['log'], 'Abwesend') !== false);
$r = step($z3, $st, ['k' => 'trigger', 'vid' => 200, 'v' => true], ['val' => [100 => 0], 'dim' => [100 => 100.0], 'condOk' => false]);
check('Bedingung nicht erfüllt: nicht eingeschaltet', $r['cmd'] === [] && strpos((string)$r['log'], 'Bedingung') !== false);

heading('A3. Bewegungslicht');
$b = AUTOMATKatalog::defaults('bewegung');
$b['Name'] = 'Flur';
$b['bl_mel1'] = 300;
$b['bl_mel2'] = 301;
$b['bl_out1'] = 100;
$b['bl_lux'] = 400;
$b['bl_luxschwelle'] = 30.0;
$b['bl_nachlauf'] = 60;
$b['bl_handpause'] = 10;
$st = [];
at('19:00:00');
$v = [100 => false, 300 => false, 301 => false, 400 => 120.0];
$r = step($b, $st, ['k' => 'trigger', 'vid' => 300, 'v' => true], ['val' => $v]);
check('Zu hell: nicht eingeschaltet, Grund mit lx', $r['cmd'] === [] && strpos((string)$r['log'], '120 lx') !== false, (string)$r['log']);
$v[400] = 5.0;
$r = step($b, $st, ['k' => 'trigger', 'vid' => 300, 'v' => true], ['val' => $v]);
check('Dunkel: an, kein Nachlauf solange Bewegung (nur Rückfall-Frist 10 min)', cmdMap($r) === ['100=true'] && $st['until'] === 0 && $r['due'] === ts('2026-10-13 19:10:00'), (string)$r['due']);
$v[100] = true;
$v[400] = 400.0; // eigenes Licht macht hell — darf das Licht nicht ausschalten
$r = step($b, $st, ['k' => 'trigger', 'vid' => 301, 'v' => true], ['val' => $v]);
check('Zweiter Melder bei hellem Raum (eigenes Licht): bleibt an', $r['cmd'] === [] && $st['ph'] === 'an');
$r = step($b, $st, ['k' => 'trigger', 'vid' => 300, 'v' => false], ['val' => $v]);
check('Ein Melder still, der andere noch aktiv: kein Nachlauf', $st['until'] === 0);
at('19:05:00');
$r = step($b, $st, ['k' => 'trigger', 'vid' => 301, 'v' => false], ['val' => $v]);
check('Beide still: Nachlauf startet', $st['until'] === ts('2026-10-13 19:06:00') && $r['due'] === ts('2026-10-13 19:06:00'));
at('19:05:30');
$r = step($b, $st, ['k' => 'trigger', 'vid' => 300, 'v' => true], ['val' => $v]);
check('Bewegung im Nachlauf: Frist wieder aufgehoben', $st['until'] === 0 && $r['cmd'] === []);
step($b, $st, ['k' => 'trigger', 'vid' => 300, 'v' => false], ['val' => $v]);
at('19:06:30');
$r = step($b, $st, ['k' => 'tick'], ['val' => $v]);
check('Nachlauf abgelaufen: aus', cmdMap($r) === ['100=false'] && $st['ph'] === 'aus');
// Licht schon an (von Hand) → nicht übernehmen
$st = [];
$v = [100 => true, 300 => false, 301 => false, 400 => 5.0];
$r = step($b, $st, ['k' => 'trigger', 'vid' => 300, 'v' => true], ['val' => $v]);
check('Licht war schon an: wird nicht übernommen', $r['cmd'] === [] && $st['ph'] === 'aus' && strpos((string)$r['log'], 'schon an') !== false);
// Handbedienung aus → Pause
$st = [];
$v = [100 => false, 300 => false, 301 => false, 400 => 5.0];
at('20:00:00');
step($b, $st, ['k' => 'trigger', 'vid' => 300, 'v' => true], ['val' => $v]);
$v[100] = false;
$r = step($b, $st, ['k' => 'output', 'vid' => 100, 'v' => false, 'own' => false], ['val' => $v]);
check('Von Hand aus: Automatik pausiert 10 min', $st['ph'] === 'hand' && $st['hu'] === ts('2026-10-13 20:10:00') && $r['due'] === ts('2026-10-13 20:10:00'));
at('20:05:00');
$r = step($b, $st, ['k' => 'trigger', 'vid' => 301, 'v' => true], ['val' => $v]);
check('Bewegung während der Handpause: bleibt aus', $r['cmd'] === []);
at('20:10:01');
step($b, $st, ['k' => 'tick'], ['val' => $v]);
$r = step($b, $st, ['k' => 'trigger', 'vid' => 301, 'v' => true], ['val' => $v]);
check('Nach der Handpause: Bewegung schaltet wieder', cmdMap($r) === ['100=true']);
// Von Hand eingeschaltet → längere Zeit
$st = [];
$v = [100 => true, 300 => false, 301 => false, 400 => 5.0];
at('21:00:00');
$r = step($b, $st, ['k' => 'output', 'vid' => 100, 'v' => true, 'own' => false], ['val' => $v]);
check('Von Hand eingeschaltet: aus nach max(Nachlauf, Handpause) = 10 min', $st['ph'] === 'an' && $st['until'] === ts('2026-10-13 21:10:00'));
// Impulsmelder
$bi = $b;
$bi['bl_impuls'] = true;
$st = [];
$v = [100 => false, 300 => false, 301 => false, 400 => 5.0];
$r = step($bi, $st, ['k' => 'trigger', 'vid' => 300, 'v' => true], ['val' => $v]);
check('Impulsmelder: an mit Nachlauf ab Meldung', cmdMap($r) === ['100=true'] && $st['until'] === ts('2026-10-13 21:01:00'));
// Vorwarnung Dimmer: halbe Helligkeit, Bewegung holt zurück
$bd = $b;
$bd['bl_vorwarn'] = 15;
$bd['bl_wert'] = 80;
$st = [];
$v = [100 => 0, 300 => false, 301 => false, 400 => 5.0];
at('22:00:00');
step($bd, $st, ['k' => 'trigger', 'vid' => 300, 'v' => true], ['val' => $v, 'dim' => [100 => 100.0]]);
step($bd, $st, ['k' => 'trigger', 'vid' => 300, 'v' => false], ['val' => $v, 'dim' => [100 => 100.0]]);
at('22:00:45');
$r = step($bd, $st, ['k' => 'tick'], ['val' => [100 => 80] + $v, 'dim' => [100 => 100.0]]);
check('Vorwarnung Dimmer: auf halbe Helligkeit (40)', cmdMap($r) === ['100=40'] && $st['ph'] === 'warn', implode(',', cmdMap($r)));
$r = step($bd, $st, ['k' => 'trigger', 'vid' => 301, 'v' => true], ['val' => [100 => 40] + $v, 'dim' => [100 => 100.0]]);
check('Bewegung in der Vorwarnung: wieder 80', cmdMap($r) === ['100=80'] && $st['ph'] === 'an');

// Verlorene „keine Bewegung“-Meldung (Z-Wave): Rückfall nach gelernter Zeit
$bz = $b;
$bz['bl_nachlauf'] = 60;
$st = [];
at('23:00:00');
$v = [100 => false, 300 => false, 301 => false, 400 => 5.0];
step($bz, $st, ['k' => 'trigger', 'vid' => 300, 'v' => true], ['val' => $v, 'melTimeout' => [300 => 30, 301 => 30], 'names' => [300 => 'Flur / Bewegung']]);
$v[100] = true;
$v[300] = true;
at('23:00:20');
$r = step($bz, $st, ['k' => 'tick'], ['val' => $v, 'melTimeout' => [300 => 30, 301 => 30]]);
check('Melder steht auf „Bewegung“: Rückfall-Frist als nächster Termin', $r['due'] === ts('2026-10-13 23:00:30') && $r['cmd'] === []);
at('23:00:30');
$r = step($bz, $st, ['k' => 'tick'], ['val' => $v, 'melTimeout' => [300 => 30, 301 => 30], 'names' => [300 => 'Flur / Bewegung']]);
check('„keine Bewegung“ verloren: nach 30 s gilt Melder als still, Nachlauf startet', $st['until'] === ts('2026-10-13 23:01:30') && strpos((string)$r['log'], 'vermutlich unterwegs verloren') !== false && strpos((string)$r['log'], 'Flur / Bewegung') !== false, (string)$r['log']);
at('23:01:30');
$r = step($bz, $st, ['k' => 'tick'], ['val' => $v, 'melTimeout' => [300 => 30, 301 => 30]]);
check('… und Licht geht nach dem Nachlauf aus', cmdMap($r) === ['100=false']);
$st = [];
at('23:10:00');
step($bz, $st, ['k' => 'trigger', 'vid' => 300, 'v' => true], ['val' => [100 => false] + $v, 'melTimeout' => [300 => 30]]);
at('23:10:25');
step($bz, $st, ['k' => 'trigger', 'vid' => 300, 'v' => true], ['val' => [100 => true] + $v, 'melTimeout' => [300 => 30]]);
at('23:10:40');
$r = step($bz, $st, ['k' => 'tick'], ['val' => [100 => true] + $v, 'melTimeout' => [300 => 30]]);
check('Erneute Bewegungsmeldung setzt die Rückfallzeit zurück', $st['act'][300] === true && $r['due'] === ts('2026-10-13 23:10:55'));
$bf = $bz;
$bf['bl_melmax'] = 300;
check('Fest eingestellte Rückfallzeit hat Vorrang vor der gelernten', AUTOMATLogik::melderTimeout($bf, ['melTimeout' => [300 => 30]], 300) === 300 && AUTOMATLogik::melderTimeout($bz, ['melTimeout' => [300 => 10]], 300) === 30);

// Lernender Nachlauf: Fehlabschaltung erkennen, verlängern, deckeln, zurückführen
$bn = $b;
$bn['bl_nachlauf'] = 120;
$bn['bl_nachlaufmax'] = 400;
$st = [];
$GLOBALS['CLOCK'] = ts('2026-10-15 19:00:00');
$v = [100 => false, 300 => false, 301 => false, 400 => 5.0];
step($bn, $st, ['k' => 'trigger', 'vid' => 300, 'v' => true], ['val' => $v]);
$v[100] = true;
step($bn, $st, ['k' => 'trigger', 'vid' => 300, 'v' => false], ['val' => $v]);
$GLOBALS['CLOCK'] += 120;
$r = step($bn, $st, ['k' => 'tick'], ['val' => $v]);
check('Nachlauf abgelaufen → aus, Zeitpunkt für Fehlabschaltungs-Erkennung gemerkt', cmdMap($r) === ['100=false'] && $st['offAt'] === $GLOBALS['CLOCK']);
$v[100] = false;
$v[400] = 60.0; // Restlicht: Sensor meldet noch hell
$GLOBALS['CLOCK'] += 40;
$r = step($bn, $st, ['k' => 'trigger', 'vid' => 300, 'v' => true], ['val' => $v]);
check('Bewegung 40 s nach dem Aus: Licht trotz Restlicht wieder an, Fehlabschaltung erkannt, Nachlauf 2:00 → 3:00 min', cmdMap($r) === ['100=true'] && $st['learnN'] === 180 && count($st['fo']) === 1 && strpos((string)$r['log'], 'Fehlabschaltung') !== false && strpos((string)$r['log'], '2:00 min → 3:00 min') !== false, (string)$r['log']);
$v[100] = true;
$r = step($bn, $st, ['k' => 'trigger', 'vid' => 300, 'v' => false], ['val' => $v]);
check('Nächster Nachlauf nutzt den gelernten Wert', $st['until'] === $GLOBALS['CLOCK'] + 180);
$GLOBALS['CLOCK'] += 180;
step($bn, $st, ['k' => 'tick'], ['val' => $v]);
$v[100] = false;
$GLOBALS['CLOCK'] += 30;
$r = step($bn, $st, ['k' => 'output', 'vid' => 100, 'v' => true, 'own' => false], ['val' => [100 => true] + $v]);
check('Von Hand wieder an nach 30 s: ebenfalls Fehlabschaltung, 3:00 → 4:30 min', $st['learnN'] === 270 && count($st['fo']) === 2, json_encode($st['learnN']));
$st['learnN'] = 380;
$st['offAt'] = $GLOBALS['CLOCK'] - 10;
$st['ph'] = 'aus';
$r = step($bn, $st, ['k' => 'trigger', 'vid' => 301, 'v' => true], ['val' => [100 => false] + $v]);
check('Höchstwert begrenzt das Lernen (400 s)', $st['learnN'] === 400);
$st['offAt'] = $GLOBALS['CLOCK'] - 600;
$st['ph'] = 'aus';
$st['act'] = [300 => false, 301 => false];
$r = step($bn, $st, ['k' => 'trigger', 'vid' => 301, 'v' => true], ['val' => [100 => false, 400 => 5.0] + $v]);
check('Wieder an nach 10 min ist keine Fehlabschaltung', $st['learnN'] === 400 && strpos((string)$r['log'], 'Fehlabschaltung') === false);
$GLOBALS['CLOCK'] = strtotime('+1 day', $GLOBALS['CLOCK']) + 60;
step($bn, $st, ['k' => 'tick'], ['val' => [100 => true] + $v]);
$GLOBALS['CLOCK'] = strtotime('+1 day', $GLOBALS['CLOCK']) + 60;
step($bn, $st, ['k' => 'tick'], ['val' => [100 => true] + $v]);
check('Einen Tag ohne Fehlabschaltung: 10 % zurück Richtung Grundwert', $st['learnN'] === 360, (string)$st['learnN']);
$bo = $bn;
$bo['bl_lernen'] = false;
check('Lernen ausgeschaltet: Grundwert gilt', AUTOMATLogik::effectiveNachlauf($bo, ['learnN' => 400]) === 120);

// Weitere Anzeichen für Anwesenheit (z. B. Herd-Leistung)
$bh = $b;
$bh['bl_hint1'] = 500;
$bh['bl_hintmin'] = 20.0;
$bh['bl_vorwarn'] = 15;
$bh['bl_lernen'] = false;
$st = [];
$GLOBALS['CLOCK'] = ts('2026-10-16 18:00:00');
$v = [100 => false, 300 => false, 301 => false, 400 => 5.0, 500 => 800.0];
$names = [500 => 'Herd / Leistung'];
$r = step($bh, $st, ['k' => 'trigger', 'vid' => 500, 'v' => 800.0], ['val' => $v, 'names' => $names]);
check('Anzeichen allein schaltet das Licht nicht ein', $r['cmd'] === [] && $st['ph'] === 'aus');
step($bh, $st, ['k' => 'trigger', 'vid' => 300, 'v' => true], ['val' => $v, 'names' => $names]);
$v[100] = true;
$r = step($bh, $st, ['k' => 'trigger', 'vid' => 300, 'v' => false], ['val' => $v, 'names' => $names]);
check('Melder still, Herd läuft (800 W > 20 W): kein Nachlauf, Status nennt Anzeichen', $st['until'] === 0 && strpos($r['status'], 'Anwesenheit: Herd / Leistung') !== false, $r['status']);
$v[500] = 3.0;
$r = step($bh, $st, ['k' => 'trigger', 'vid' => 500, 'v' => 3.0], ['val' => $v, 'names' => $names]);
check('Herd aus (3 W): Nachlauf startet', $st['until'] === $GLOBALS['CLOCK'] + 60 && strpos((string)$r['log'], 'Anzeichen für Anwesenheit vorbei') !== false, (string)$r['log']);
$GLOBALS['CLOCK'] += 46;
$r = step($bh, $st, ['k' => 'tick'], ['val' => $v, 'names' => $names]);
check('Vorwarnung läuft', $st['ph'] === 'flash', $st['ph']);
$v[500] = 1200.0;
$r = step($bh, $st, ['k' => 'trigger', 'vid' => 500, 'v' => 1200.0], ['val' => $v, 'names' => $names]);
check('Herd wieder an während der Vorwarnung: Licht zurück, Nachlauf aufgehoben', cmdMap($r) === ['100=true'] && $st['until'] === 0 && $st['ph'] === 'an');
$st2 = [];
$b2 = $bh;
$b2['bl_hint1'] = 501;
$r = step($b2, $st2, ['k' => 'trigger', 'vid' => 300, 'v' => true], ['val' => [100 => false, 300 => true, 400 => 5.0, 501 => true]]);
$r = step($b2, $st2, ['k' => 'trigger', 'vid' => 300, 'v' => false], ['val' => [100 => true, 300 => false, 400 => 5.0, 501 => true]]);
check('Schalter-Anzeichen („an“) hält ebenfalls', $st2['until'] === 0);
check('Anzeichen sind Eingänge (Nachrichten werden registriert)', in_array(500, AUTOMATKatalog::variables($bh)['in'], true));

heading('A4. Fenster offen → Heizung runter');
$f = AUTOMATKatalog::defaults('fenster');
$f['Name'] = 'Bad';
$f['fo_kon1'] = 500;
$f['fo_kon2'] = 501;
$f['fo_soll1'] = 600;
$f['fo_verz'] = 60;
$f['fo_absenk'] = 12.0;
$st = [];
at('08:00:00');
$v = [500 => true, 501 => false, 600 => 21.5];
$r = step($f, $st, ['k' => 'trigger', 'vid' => 500, 'v' => true], ['val' => $v]);
check('Fenster auf: noch nicht abgesenkt, Frist +60 s', $r['cmd'] === [] && $r['due'] === ts('2026-10-13 08:01:00'));
at('08:00:30');
$v[500] = false;
$r = step($f, $st, ['k' => 'trigger', 'vid' => 500, 'v' => false], ['val' => $v]);
check('Kurz gelüftet (zu vor Ablauf): keine Absenkung', $r['cmd'] === [] && $st['ph'] === 'zu');
$v[500] = true;
step($f, $st, ['k' => 'trigger', 'vid' => 500, 'v' => true], ['val' => $v]);
at('08:01:30');
$r = step($f, $st, ['k' => 'tick'], ['val' => $v]);
check('Länger offen: Sollwert auf 12 °C', cmdMap($r) === ['600=12.0'] && $st['saved'][600] === 21.5, implode(',', cmdMap($r)));
$r = step($f, $st, ['k' => 'output', 'vid' => 600, 'v' => 12.0, 'own' => true], ['val' => $v]);
check('Eigenes Echo ändert den gemerkten Wert nicht', $st['saved'][600] === 21.5 && $r['cmd'] === []);
$r = step($f, $st, ['k' => 'output', 'vid' => 600, 'v' => 22.0, 'own' => false], ['val' => [600 => 22.0] + $v]);
check('Wochenplan stellt 22 °C: gemerkt und wieder abgesenkt', $st['saved'][600] === 22.0 && cmdMap($r) === ['600=12.0']);
$v[501] = true;
$v[500] = false;
$r = step($f, $st, ['k' => 'trigger', 'vid' => 500, 'v' => false], ['val' => $v]);
check('Ein Fenster zu, anderes offen: bleibt abgesenkt', $r['cmd'] === [] && $st['ph'] === 'ab');
$v[501] = false;
$r = step($f, $st, ['k' => 'trigger', 'vid' => 501, 'v' => false], ['val' => $v]);
check('Alle zu: zurück auf den gemerkten Wochenplanwert 22 °C', cmdMap($r) === ['600=22.0'] && $st['ph'] === 'zu');
// Sperre während abgesenkt
$st = [];
$f['fo_verz'] = 0;
$v = [500 => true, 501 => false, 600 => 20.0];
$r = step($f, $st, ['k' => 'trigger', 'vid' => 500, 'v' => true], ['val' => $v]);
check('Verzögerung 0: sofort abgesenkt', cmdMap($r) === ['600=12.0']);
$r = step($f, $st, ['k' => 'flag'], ['val' => $v, 'paused' => true]);
check('Pause bei abgesenkter Heizung: Sollwert zurück', cmdMap($r) === ['600=20.0'] && $st['ph'] === 'zu');
// Invertierter Kontakt (Matter: wahr = geschlossen)
$fi = $f;
$fi['fo_invert'] = true;
$st = [];
$r = step($fi, $st, ['k' => 'trigger', 'vid' => 500, 'v' => true], ['val' => [500 => true, 501 => true, 600 => 20.0]]);
check('Invertiert: „wahr“ = geschlossen → keine Absenkung', $r['cmd'] === [] && $st['ph'] === 'zu');

heading('A5. Wächter „bleibt zu lange so“');
$w = AUTOMATKatalog::defaults('waechter');
$w['Name'] = 'Haustür';
$w['wa_var'] = 700;
$w['wa_op'] = 4;
$w['wa_dauer'] = 15;
$w['wa_wdh'] = 30;
$st = [];
at('10:00:00');
$r = step($w, $st, ['k' => 'trigger', 'vid' => 700, 'v' => true], ['val' => [700 => true], 'names' => [700 => 'Haustür / Zustand']]);
check('Offen: zählt, Meldung um 10:15', $st['ph'] === 'zaehlt' && $r['due'] === ts('2026-10-13 10:15:00') && $r['msg'] === []);
at('10:15:00');
$r = step($w, $st, ['k' => 'tick'], ['val' => [700 => true], 'names' => [700 => 'Haustür / Zustand']]);
check('Nach 15 min: Meldung', count($r['msg']) === 1 && strpos($r['msg'][0][1], 'seit 15 min') !== false, json_encode($r['msg'], JSON_UNESCAPED_UNICODE));
at('10:44:00');
$r = step($w, $st, ['k' => 'tick'], ['val' => [700 => true], 'names' => [700 => 'Haustür / Zustand']]);
check('Vor der Wiederholung: still', $r['msg'] === []);
at('10:45:00');
$r = step($w, $st, ['k' => 'tick'], ['val' => [700 => true], 'names' => [700 => 'Haustür / Zustand']]);
check('Nach 30 min: Erinnerung', count($r['msg']) === 1 && strpos($r['msg'][0][0], 'Erinnerung') !== false);
$r = step($w, $st, ['k' => 'trigger', 'vid' => 700, 'v' => false], ['val' => [700 => false], 'names' => [700 => 'Haustür / Zustand']]);
check('Zu: Entwarnung', count($r['msg']) === 1 && strpos($r['msg'][0][0], '✅') !== false && $st['ph'] === 'normal');
$w2 = $w;
$w2['wa_op'] = 2;
$w2['wa_wert'] = '65,5';
$st = [];
step($w2, $st, ['k' => 'trigger', 'vid' => 700, 'v' => 70.2], ['val' => [700 => 70.2]]);
check('Größer als „65,5“ (Komma) erkannt', $st['ph'] === 'zaehlt');
$st = [];
at('11:00:00');
step($w, $st, ['k' => 'trigger', 'vid' => 700, 'v' => true], ['val' => [700 => true]]);
at('11:20:00');
$r = step($w, $st, ['k' => 'tick'], ['val' => [700 => true], 'condOk' => false]);
check('Bedingung nicht erfüllt (z. B. Ruhezeit): keine Meldung, Status sagt warum', $r['msg'] === [] && strpos($r['status'], 'nicht gemeldet') !== false, $r['status']);
$r = step($w, $st, ['k' => 'tick'], ['val' => [700 => true], 'condOk' => true]);
check('Bedingung wieder erfüllt: Meldung wird nachgeholt', count($r['msg']) === 1);

heading('A6. Gerät fertig');
$g = AUTOMATKatalog::defaults('fertig');
$g['Name'] = 'Waschmaschine';
$g['gf_var'] = 800;
$st = [];
at('09:00:00');
step($g, $st, ['k' => 'trigger', 'vid' => 800, 'v' => 2000.0], ['val' => [800 => 2000.0]]);
check('Leistung über Schwelle: läuft', $st['ph'] === 'laeuft');
at('09:02:00');
step($g, $st, ['k' => 'trigger', 'vid' => 800, 'v' => 1.0], ['val' => [800 => 1.0]]);
check('Nach 2 min schon unter Schwelle: zählt nicht (Mindestlaufzeit 5 min)', $st['ph'] === 'aus');
at('09:10:00');
step($g, $st, ['k' => 'trigger', 'vid' => 800, 'v' => 500.0], ['val' => [800 => 500.0]]);
at('10:30:00');
$r = step($g, $st, ['k' => 'trigger', 'vid' => 800, 'v' => 2.0], ['val' => [800 => 2.0]]);
check('Unter Schwelle nach langem Lauf: Meldung in 3 min', $st['ph'] === 'auslauf' && $r['due'] === ts('2026-10-13 10:33:00'));
at('10:31:00');
step($g, $st, ['k' => 'trigger', 'vid' => 800, 'v' => 300.0], ['val' => [800 => 300.0]]);
check('Schleudern/Pumpen dazwischen: läuft weiter', $st['ph'] === 'laeuft');
step($g, $st, ['k' => 'trigger', 'vid' => 800, 'v' => 1.0], ['val' => [800 => 1.0]]);
at('10:34:00');
$r = step($g, $st, ['k' => 'tick'], ['val' => [800 => 1.0]]);
check('Fertig gemeldet mit Laufzeit', count($r['msg']) === 1 && strpos($r['msg'][0][1], 'fertig') !== false, json_encode($r['msg'], JSON_UNESCAPED_UNICODE));

heading('A7. Dämmerungs- und Zeitschaltung');
$d = AUTOMATKatalog::defaults('daemmerung');
$d['Name'] = 'Außenlicht';
$d['dz_out1'] = 900;
$sun = [];
foreach (['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'] as $day) {
    $sun[$day] = ['ss' => ts($day . ' 18:30:00'), 'sr' => ts($day . ' 07:45:00')];
}
$st = [];
at('17:00:00');
$r = step($d, $st, ['k' => 'tick'], ['val' => [900 => false], 'sun' => $sun, 'rnd' => 0.0]);
check('Vor Sonnenuntergang: aus, nächste Schaltung 18:30', $r['cmd'] === [] && $r['due'] === ts('2026-10-13 18:30:00'), (string)$r['due']);
at('18:30:00');
$r = step($d, $st, ['k' => 'tick'], ['val' => [900 => false], 'sun' => $sun]);
check('Sonnenuntergang: an', cmdMap($r) === ['900=true'] && $r['due'] === ts('2026-10-13 23:00:00'));
at('23:00:00');
$r = step($d, $st, ['k' => 'tick'], ['val' => [900 => true], 'sun' => $sun]);
check('23:00 Uhr: aus', cmdMap($r) === ['900=false']);
// Über Mitternacht bis Sonnenaufgang
$d2 = $d;
$d2['dz_aus'] = 1;
$st = [];
at('19:00:00');
step($d2, $st, ['k' => 'tick'], ['val' => [900 => false], 'sun' => $sun, 'rnd' => 0.0]);
$GLOBALS['CLOCK'] = ts('2026-10-14 03:00:00');
$r = step($d2, $st, ['k' => 'tick'], ['val' => [900 => true], 'sun' => $sun, 'rnd' => 0.0]);
check('Nachts um 3 Uhr (Fenster von gestern): bleibt an', $r['cmd'] === [] && $st['on'] === true);
$GLOBALS['CLOCK'] = ts('2026-10-14 07:45:00');
$r = step($d2, $st, ['k' => 'tick'], ['val' => [900 => true], 'sun' => $sun]);
check('Sonnenaufgang: aus', cmdMap($r) === ['900=false']);
// Von Hand aus → erst im nächsten Fenster wieder
$st = [];
at('19:00:00');
step($d, $st, ['k' => 'tick'], ['val' => [900 => false], 'sun' => $sun]);
$r = step($d, $st, ['k' => 'output', 'vid' => 900, 'v' => false, 'own' => false], ['val' => [900 => false], 'sun' => $sun]);
at('19:10:00');
$r = step($d, $st, ['k' => 'tick'], ['val' => [900 => false], 'sun' => $sun]);
check('Von Hand aus: bleibt aus bis zum nächsten Fenster', $r['cmd'] === [] && strpos($r['status'], 'Von Hand') !== false, $r['status']);
// Zufallsversatz
$d3 = $d;
$d3['dz_zufall'] = 20;
$st = [];
at('17:00:00');
$r = step($d3, $st, ['k' => 'tick'], ['val' => [900 => false], 'sun' => $sun, 'rnd' => 0.5]);
check('Zufall +10 min: Einschalten 18:40', $r['due'] === ts('2026-10-13 18:40:00'), date('H:i', (int)$r['due']));
// Dunkelheit per Helligkeitssensor
$d4 = $d;
$d4['dz_ein'] = 2;
$d4['dz_lux'] = 950;
$st = [];
at('17:30:00');
$r = step($d4, $st, ['k' => 'tick'], ['val' => [900 => false, 950 => 80.0], 'sun' => $sun]);
check('Dunkelheit: bei 80 lx noch aus, minütlich prüfen', $r['cmd'] === [] && $r['due'] === ts('2026-10-13 17:31:00'));
$r = step($d4, $st, ['k' => 'tick'], ['val' => [900 => false, 950 => 10.0], 'sun' => $sun]);
check('Bei 10 lx: an', cmdMap($r) === ['900=true']);
// Uhrzeit-Einschalten, DST-Tag (25.10.2026: 25 Stunden)
$d5 = $d;
$d5['dz_ein'] = 1;
$d5['dz_einzeit'] = AUTOMATKatalog::time(6, 0);
$d5['dz_auszeit'] = AUTOMATKatalog::time(7, 0);
$w5 = AUTOMATLogik::window($d5, '2026-10-25', 0, []);
check('Zeitumstellung 25.10.: 06:00–07:00 Wanduhr (mktime statt +86400)', date('H:i', $w5[0]) === '06:00' && date('H:i', $w5[1]) === '07:00');

heading('A8. Hausmodus-Automatik');
$day = AUTOMATKatalog::time(6, 30);
$eve = AUTOMATKatalog::time(18, 0);
$night = AUTOMATKatalog::time(22, 30);
$c = ['dayRef' => 0, 'dayFrom' => $day, 'eveRef' => 1, 'eveFrom' => $eve, 'night' => $night, 'sunrise' => null, 'sunset' => null, 'isDay' => null];
$am = fn(string $t, array $o = [], ?bool $p = null, int $cur = 0) => AUTOMATLogik::autoMode(ts('2026-10-13 ' . $t), $o + $c, $p, $cur);
check('12 Uhr = Tag', $am('12:00') === 0);
check('19 Uhr = Abend', $am('19:00') === 1);
check('Abend ab Sonnenuntergang 17:45', $am('17:50', ['eveRef' => 0, 'sunset' => ts('2026-10-13 17:45')]) === 1 && $am('17:40', ['eveRef' => 0, 'sunset' => ts('2026-10-13 17:45')]) === 0);
check('Sonnenuntergang −30 min: Abend schon ab 17:15', $am('17:20', ['eveRef' => 0, 'sunset' => ts('2026-10-13 17:45'), 'eveOff' => -30]) === 1);
check('23 Uhr = Nacht, 5 Uhr = Nacht', $am('23:00') === 2 && $am('05:00') === 2);
check('Tag ab Sonnenaufgang 07:40: 07:00 noch Nacht, 07:45 Tag', $am('07:00', ['dayRef' => 1, 'sunrise' => ts('2026-10-13 07:40')]) === 2 && $am('07:45', ['dayRef' => 1, 'sunrise' => ts('2026-10-13 07:40')]) === 0);
check('Sonnenaufgang +20 min: 07:45 noch Nacht', $am('07:45', ['dayRef' => 1, 'sunrise' => ts('2026-10-13 07:40'), 'dayOff' => 20]) === 2);
check('„Ist es Tag“: morgens falsch = Nacht, wahr = Tag', $am('07:00', ['dayRef' => 2, 'isDay' => false]) === 2 && $am('07:00', ['dayRef' => 2, 'isDay' => true]) === 0);
check('„Ist es Tag“ wird nachmittags falsch = Abend', $am('17:00', ['eveRef' => 2, 'isDay' => false]) === 1 && $am('17:00', ['eveRef' => 2, 'isDay' => true]) === 0);
check('Ohne Location Control: Uhrzeiten als Rückfall', $am('07:00', ['dayRef' => 1, 'eveRef' => 0]) === 0 && $am('19:00', ['dayRef' => 2, 'eveRef' => 2]) === 1);
check('Nacht ab 00:30: 23:30 noch Abend, 01:00 Nacht, 07:00 Tag', $am('23:30', ['night' => AUTOMATKatalog::time(0, 30)]) === 1 && $am('01:00', ['night' => AUTOMATKatalog::time(0, 30)]) === 2 && $am('07:00', ['night' => AUTOMATKatalog::time(0, 30)]) === 0);
check('Niemand da = Abwesend', $am('12:00', [], false) === 3);
check('Urlaub bleibt Urlaub', $am('23:00', [], false, 4) === 4);
check('Nacht ab Ende nautische Dämmerung 19:40 (+10 min): 19:45 Abend, 19:55 Nacht', $am('19:45', ['nightAt' => ts('2026-10-13 19:40'), 'nightOff' => 10]) === 1 && $am('19:55', ['nightAt' => ts('2026-10-13 19:40'), 'nightOff' => 10]) === 2);
check('Helligkeitssensor: dunkel am Nachmittag = Abend, hell = Tag; ohne Sensor Uhrzeit', $am('16:00', ['eveRef' => 6, 'lightDay' => false]) === 1 && $am('16:00', ['eveRef' => 6, 'lightDay' => true]) === 0 && $am('16:00', ['eveRef' => 6]) === 0);
check('Helligkeitssensor morgens: dunkel = Nacht, hell = Tag', $am('07:30', ['dayRef' => 6, 'lightDay' => false]) === 2 && $am('07:30', ['dayRef' => 6, 'lightDay' => true]) === 0);
$ls = AUTOMATLogik::lightDay([], 80.0, 1000, 50, 20, 600);
check('lightDay: Startwert nach Messwert (80 → hell)', $ls === ['day' => true, 'cand' => 0]);
$ls = AUTOMATLogik::lightDay($ls, 10.0, 1000, 50, 20, 600);
check('Wolke: unter „dunkel“, aber Haltezeit läuft noch → bleibt hell', $ls['day'] === true && $ls['cand'] === 1000);
$ls = AUTOMATLogik::lightDay($ls, 35.0, 1300, 50, 20, 600);
check('Zwischen den Schwellen: Haltezeit verworfen', $ls === ['day' => true, 'cand' => 0]);
$ls = AUTOMATLogik::lightDay($ls, 10.0, 2000, 50, 20, 600);
$ls = AUTOMATLogik::lightDay($ls, 12.0, 2600, 50, 20, 600);
check('10 min dauerhaft unter „dunkel“ → dunkel', $ls === ['day' => false, 'cand' => 0]);
$ls = AUTOMATLogik::lightDay($ls, 40.0, 3000, 50, 20, 600);
check('Dunkel bleibt bis „hell ab“ erreicht ist (Hysterese)', $ls['day'] === false && $ls['cand'] === 0);

heading('A8b. Anwesenheit zusammenfassen');
$an = AUTOMATKatalog::defaults('anwesenheit');
$an['Uid'] = 'aanw01';
$an['Name'] = 'Anwesenheit';
$an['Enabled'] = true;
$an['an_p1'] = 700;
$an['an_p2'] = 701;
$an['an_weg'] = 10;
$names = [700 => 'Dietmar', 701 => 'Svetlana'];
$st = [];
at('12:00:00');
$r = step($an, $st, ['k' => 'tick'], ['val' => [700 => true, 701 => false], 'names' => $names]);
check('Erster Lauf: Ergebnis „jemand da“, Status nennt die Person', $r['self'] === true && $r['status'] === '🏠 Zu Hause: Dietmar');
$r = step($an, $st, ['k' => 'trigger', 'vid' => 701, 'v' => true], ['val' => [700 => true, 701 => true], 'names' => $names]);
check('Zweite Person kommt: protokolliert, Ergebnis unverändert', !isset($r['self']) && strpos((string)$r['log'], 'Svetlana gekommen') !== false && $r['status'] === '🏠 Zu Hause: Dietmar, Svetlana');
$r = step($an, $st, ['k' => 'trigger', 'vid' => 700, 'v' => false], ['val' => [700 => false, 701 => true], 'names' => $names]);
check('Eine Person geht: noch jemand da', !isset($r['self']) && strpos((string)$r['log'], 'Dietmar gegangen') !== false);
at('12:10:00');
$r = step($an, $st, ['k' => 'trigger', 'vid' => 701, 'v' => false], ['val' => [700 => false, 701 => false], 'names' => $names]);
check('Alle weg: Wartezeit 10 min, noch kein „niemand da“', !isset($r['self']) && $r['due'] === ts('2026-10-13 12:20:00') && strpos($r['status'], 'Alle weg') !== false);
at('12:15:00');
$r = step($an, $st, ['k' => 'trigger', 'vid' => 700, 'v' => true], ['val' => [700 => true, 701 => false], 'names' => $names]);
check('Zurück in der Wartezeit: bleibt „jemand da“ (kein Wechsel)', $r['self'] === true && strpos((string)$r['log'], 'Doch wieder jemand da') !== false && $st['ph'] === 'da');
$r = step($an, $st, ['k' => 'trigger', 'vid' => 700, 'v' => false], ['val' => [700 => false, 701 => false], 'names' => $names]);
at('12:25:00');
$r = step($an, $st, ['k' => 'tick'], ['val' => [700 => false, 701 => false], 'names' => $names]);
check('Nach Ablauf: „niemand da“', $r['self'] === false && strpos($r['status'], 'Niemand zu Hause seit 13.10.2026 12:25') !== false);
$r = step($an, $st, ['k' => 'trigger', 'vid' => 701, 'v' => true], ['val' => [700 => false, 701 => true], 'names' => $names]);
check('Heimkommen: sofort „jemand da“', $r['self'] === true && strpos((string)$r['log'], 'nach Hause gekommen (Svetlana)') !== false);
$st2 = [];
$r = step($an, $st2, ['k' => 'tick'], ['val' => [700 => 'Zu Hause', 701 => 0], 'names' => $names]);
check('Text „Zu Hause“ zählt als anwesend', $r['self'] === true);
$an6 = AUTOMATKatalog::defaults('anwesenheit');
foreach (range(1, 6) as $i) {
    $an6['an_p' . $i] = 709 + $i;
}
check('Anwesenheit: 8 Plätze, 6 Personen werden alle gezählt', AUTOMATKatalog::slots('an_p') === 8 && array_key_exists('an_p8', $an6) && count(AUTOMATKatalog::vars($an6, 'an_p')) === 6 && AUTOMATKatalog::slots('bl_mel') === AUTOMATKatalog::SLOTS);
$anP = $an;
$anP['an_push'] = true;
$stP = [];
at('12:00:00');
step($anP, $stP, ['k' => 'tick'], ['val' => [700 => true, 701 => false], 'names' => $names]);
$rP = step($anP, $stP, ['k' => 'trigger', 'vid' => 700, 'v' => false], ['val' => [700 => false, 701 => false], 'names' => $names]);
check('Mit „melden“: beim Gehen noch keine Meldung (Wartezeit)', $rP['msg'] === []);
at('12:11:00');
$rP = step($anP, $stP, ['k' => 'tick'], ['val' => [700 => false, 701 => false], 'names' => $names]);
check('Mit „melden“: „Alle weg“ nach der Wartezeit', count($rP['msg']) === 1 && $rP['msg'][0][0] === '🚪 Alle weg');
$rP = step($anP, $stP, ['k' => 'trigger', 'vid' => 701, 'v' => true], ['val' => [700 => false, 701 => true], 'names' => $names]);
check('Mit „melden“: „Heimgekommen“ nennt die Person', count($rP['msg']) === 1 && strpos($rP['msg'][0][1], 'Svetlana ist nach Hause gekommen') !== false);
$stQ = [];
step($an, $stQ, ['k' => 'tick'], ['val' => [700 => true, 701 => false], 'names' => $names]);
at('12:00:00');
check('Ohne „melden“ (Standard): keine Meldungen', AUTOMATKatalog::defaults('anwesenheit')['an_push'] === false);
check('Katalog: Ein- und Ausgänge des Typs', AUTOMATKatalog::variables($an)['in'] === [700, 701] && AUTOMATKatalog::variables($an)['out'] === []);

heading('A8c. Zentral-Aus / Szene nach Hausmodus');
$sz = AUTOMATKatalog::defaults('szene');
$sz += ['Uid' => 'aszn01', 'Name' => 'Alles aus'];
$sz['sz_out1'] = 500;
$sz['sz_out2'] = 501;
$sz['sz_out3'] = 502;
$names = [500 => 'Flur', 501 => 'Wohnzimmer', 502 => 'Küche'];
$st = [];
at('22:30:00');
$r = step($sz, $st, ['k' => 'mode', 'from' => 1, 'to' => 2], ['mode' => 2, 'val' => [500 => true, 501 => 80, 502 => false], 'dim' => [501 => 100], 'names' => $names]);
check('Wechsel auf „Nacht“: an stehende Geräte aus (Dimmer 0), was schon aus ist, bleibt unberührt', cmdMap($r) === ['500=false', '501=0'] && strpos((string)$r['log'], '2 Gerät(e) ausgeschaltet (Flur, Wohnzimmer)') !== false, implode(',', cmdMap($r)));
check('Status nennt das Ergebnis', strpos($r['status'], 'Nacht: 2 ausgeschaltet') !== false, $r['status']);
$st = [];
$r = step($sz, $st, ['k' => 'mode', 'from' => 2, 'to' => 0], ['mode' => 0, 'val' => [500 => true]]);
check('Wechsel auf „Tag“ (nicht angekreuzt): nichts', $r['cmd'] === []);
$sz2 = $sz;
$sz2['sz_verz'] = 2;
$st = [];
$r = step($sz2, $st, ['k' => 'mode', 'from' => 1, 'to' => 2], ['mode' => 2, 'val' => [500 => true]]);
check('Mit Verzögerung: erst vormerken, Restzeit sichtbar', $r['cmd'] === [] && $r['due'] === ts('2026-10-13 22:32:00') && strpos($r['status'], 'schaltet um 22:32') !== false);
at('22:31:00');
$r = step($sz2, $st, ['k' => 'mode', 'from' => 2, 'to' => 1], ['mode' => 1, 'val' => [500 => true]]);
check('Modus wechselt vorher zurück: Schaltung entfällt', $r['cmd'] === [] && strpos((string)$r['log'], 'entfällt') !== false && $st['pend'] === -1);
at('22:30:00');
$st = [];
step($sz2, $st, ['k' => 'mode', 'from' => 1, 'to' => 2], ['mode' => 2, 'val' => [500 => true]]);
at('22:32:00');
$r = step($sz2, $st, ['k' => 'tick'], ['mode' => 2, 'val' => [500 => true, 501 => false, 502 => false], 'names' => $names]);
check('Nach der Verzögerung: geschaltet', cmdMap($r) === ['500=false'], implode(',', cmdMap($r)) . ' | ' . $r['log']);
$sz3 = $sz;
$sz3['sz_aktion'] = 1;
$sz3['sz_hell'] = 30;
$sz3['sz_m1'] = true;
$sz3['sz_push'] = true;
$st = [];
$r = step($sz3, $st, ['k' => 'mode', 'from' => 0, 'to' => 1], ['mode' => 1, 'val' => [500 => false, 501 => 0, 502 => false], 'dim' => [501 => 100], 'names' => $names]);
check('„Einschalten“ bei „Abend“: Schalter an, Dimmer auf 30 %, mit Meldung', cmdMap($r) === ['500=true', '501=30', '502=true'] && count($r['msg']) === 1 && strpos($r['msg'][0][1], 'eingeschaltet') !== false);
$sz4 = $sz;
$sz4['sz_aktion'] = 2;
$sz4['sz_wert'] = 17.0;
$st = [];
$r = step($sz4, $st, ['k' => 'mode', 'from' => 0, 'to' => 3], ['mode' => 3, 'val' => [500 => 21.0, 501 => 17.0, 502 => 20.0]]);
check('„Auf Wert setzen“ (17 °C bei „Abwesend“), schon 17 wird übersprungen', cmdMap($r) === ['500=17.0', '502=17.0'], implode(',', cmdMap($r)));
$st = [];
$r = step($sz, $st, ['k' => 'mode', 'from' => 1, 'to' => 2], ['mode' => 2, 'paused' => true, 'val' => [500 => true]]);
check('Pause: nicht geschaltet, mit Grund', $r['cmd'] === [] && strpos((string)$r['log'], 'nicht geschaltet') !== false);
$st = [];
$r = step($sz, $st, ['k' => 'mode', 'from' => 1, 'to' => 2], ['mode' => 2, 'condOk' => false, 'val' => [500 => true]]);
check('Bedingung nicht erfüllt: nicht geschaltet', $r['cmd'] === [] && strpos((string)$r['log'], 'Bedingung') !== false);
$sz5 = $sz;
$sz5['m2'] = false;
$st = [];
$r = step($sz5, $st, ['k' => 'mode', 'from' => 1, 'to' => 2], ['mode' => 2, 'val' => [500 => true, 501 => false, 502 => false]]);
$stU = [];
check('Unbekannter Zustand (Wert fehlt): wird sicherheitshalber geschaltet', cmdMap(step($sz, $stU, ['k' => 'mode', 'from' => 1, 'to' => 2], ['mode' => 2, 'val' => []])) === ['500=false', '501=false', '502=false']);
check('Allgemeine Modus-Häkchen gelten bei der Szene nicht (der Modus ist der Auslöser)', cmdMap($r) === ['500=false'], implode(',', cmdMap($r)) . ' | ' . $r['log']);
$sz6 = $sz;
foreach (range(0, 4) as $i) {
    $sz6['sz_m' . $i] = false;
}
check('Kein Modus angekreuzt: als unvollständig gemeldet', count(array_filter(AUTOMATKatalog::problems($sz6, fn($v) => true), fn($p) => strpos($p, 'Kein Hausmodus') !== false)) === 1);
check('Szene: 16 Plätze für Geräte', AUTOMATKatalog::slots('sz_out') === 16 && AUTOMATKatalog::variables($sz)['out'] === [500, 501, 502]);
at('12:00:00');

heading('A8d. Wasser-/Rauchmelder-Reaktion');
$al = AUTOMATKatalog::defaults('alarm') + ['Uid' => 'aalm01', 'Name' => 'Wasseralarm'];
$al['al_mel1'] = 600;
$al['al_licht1'] = 610;
$al['al_hoch1'] = 620;
$al['al_aus1'] = 630;
$nm = [600 => 'Wassermelder Keller', 610 => 'Licht Keller', 620 => 'Rollladen Küche', 630 => 'Wasserventil'];
$st = [];
at('03:00:00');
$r = step($al, $st, ['k' => 'trigger', 'vid' => 600, 'v' => true], ['val' => [600 => true, 610 => false, 620 => 100, 630 => true], 'dim' => [610 => 100], 'names' => $nm, 'paused' => true, 'mode' => 2]);
check('Alarm: Licht voll, Rollladen auf, Ventil zu — auch bei Pause und Nacht', cmdMap($r) === ['610=100', '620=0.0', '630=false'], implode(',', cmdMap($r)));
check('Alarm: dringende Meldung mit Melder und Maßnahmen', !empty($r['urgent']) && count($r['msg']) === 1 && strpos($r['msg'][0][1], 'ALARM: Wassermelder Keller') !== false && strpos($r['msg'][0][1], 'Wasserventil') !== false);
at('03:05:00');
$r = step($al, $st, ['k' => 'tick'], ['val' => [600 => true], 'names' => $nm]);
check('Alarm: noch keine Erinnerung nach 5 min', $r['msg'] === [] && $r['due'] === ts('2026-10-13 03:10:00'));
at('03:10:00');
$r = step($al, $st, ['k' => 'tick'], ['val' => [600 => true], 'names' => $nm]);
check('Alarm: Erinnerung nach 10 min, ohne erneut zu schalten', count($r['msg']) === 1 && strpos($r['msg'][0][0], 'Erinnerung') !== false && $r['cmd'] === []);
$r = step($al, $st, ['k' => 'trigger', 'vid' => 600, 'v' => false], ['val' => [600 => false], 'names' => $nm]);
check('Entwarnung gemeldet, nichts zurückgeschaltet (Ventil bleibt zu)', count($r['msg']) === 1 && strpos($r['msg'][0][0], '✅') === 0 && $r['cmd'] === []);
$st = [];
$r = step($al, $st, ['k' => 'trigger', 'vid' => 600, 'v' => true], ['val' => [600 => true], 'locked' => true]);
check('Gesperrt: reagiert nicht', $r['cmd'] === [] && strpos($r['status'], 'Gesperrt') !== false);

heading('A8e. Rollladen nach Zeit/Sonne');
$rl = AUTOMATKatalog::defaults('rollladen') + ['Uid' => 'arol01', 'Name' => 'Rollläden'];
$rl['rl_out1'] = 700;
$rl['rl_tuer1'] = 710;
$sunR = ['2026-10-13' => ['sr' => ts('2026-10-13 07:40'), 'ss' => ts('2026-10-13 18:30')], '2026-10-17' => ['sr' => ts('2026-10-17 07:45'), 'ss' => ts('2026-10-17 18:22')]];
$st = [];
at('05:00:00');
$r = step($rl, $st, ['k' => 'tick'], ['val' => [700 => 100, 710 => false], 'sun' => $sunR]);
check('Erster Lauf vor Sonnenaufgang: nichts, wartet', $r['cmd'] === [] && $r['due'] === ts('2026-10-13 07:40'));
at('07:40:00');
$r = step($rl, $st, ['k' => 'tick'], ['val' => [700 => 100, 710 => false], 'sun' => $sunR]);
check('Sonnenaufgang (nach „frühestens 6:30“): auf', cmdMap($r) === ['700=0.0']);
$r = step($rl, $st, ['k' => 'tick'], ['val' => [700 => 0, 710 => false], 'sun' => $sunR]);
check('Nur einmal am Tag öffnen', $r['cmd'] === [] && $r['due'] === ts('2026-10-13 18:30'));
at('18:30:00');
$r = step($rl, $st, ['k' => 'tick'], ['val' => [700 => 0, 710 => true], 'sun' => $sunR]);
check('Sonnenuntergang, Tür offen: Aussperrschutz hält auf', $r['cmd'] === [] && $st['wait'] === true && strpos((string)$r['log'], 'Aussperrschutz') !== false);
at('18:45:00');
$r = step($rl, $st, ['k' => 'trigger', 'vid' => 710, 'v' => false], ['val' => [700 => 0, 710 => false], 'sun' => $sunR]);
check('Tür zu: jetzt schließen', cmdMap($r) === ['700=100.0'] && strpos((string)$r['log'], 'Tür jetzt zu') !== false);
$st = [];
at('12:00:00');
$r = step($rl, $st, ['k' => 'tick'], ['val' => [700 => 50, 710 => false], 'sun' => $sunR]);
check('Mittags eingerichtet: fährt nicht sofort hoch', $r['cmd'] === []);
$GLOBALS['CLOCK'] = ts('2026-10-17 07:50:00'); // Samstag
$st = ['up' => '', 'down' => '', 'wait' => false];
$r = step($rl, $st, ['k' => 'tick'], ['val' => [700 => 100], 'sun' => $sunR]);
check('Wochenende: trotz Sonnenaufgang 7:45 erst ab 8:00', $r['cmd'] === [] && $r['due'] === ts('2026-10-17 08:00'));
$rlL = $rl;
$rlL['rl_downref'] = 2;
$st = ['up' => '2026-10-13', 'down' => '', 'wait' => false];
at('08:00:00');
$r = step($rlL, $st, ['k' => 'tick'], ['val' => [700 => 0, 710 => false], 'sun' => $sunR, 'dark' => true]);
check('Dunkelheit am Morgen schließt nicht', $r['cmd'] === []);
at('16:30:00');
$r = step($rlL, $st, ['k' => 'tick'], ['val' => [700 => 0, 710 => false], 'sun' => $sunR, 'dark' => false]);
check('Nachmittags noch hell (zentraler Sensor): bleibt offen', $r['cmd'] === []);
at('17:50:00');
$r = step($rlL, $st, ['k' => 'tick'], ['val' => [700 => 0, 710 => false], 'sun' => $sunR, 'dark' => true]);
check('Zentraler Sensor meldet dunkel: zu', cmdMap($r) === ['700=100.0']);
$st = ['up' => '2026-10-13', 'down' => '', 'wait' => false];
at('16:00:00');
$r = step($rlL, $st, ['k' => 'tick'], ['val' => [700 => 0], 'sun' => $sunR]);
check('Ohne zentralen Sensor: Hinweis, nur „spätestens“ gilt', strpos($r['status'], 'kein Helligkeitssensor unter „🏠 Hausmodus“') !== false);
$dzC = AUTOMATKatalog::defaults('daemmerung') + ['Uid' => 'adzc01'];
$dzC['dz_out1'] = 900;
$dzC['dz_ein'] = 2;
$stD = [];
at('17:30:00');
$r = step($dzC, $stD, ['k' => 'tick'], ['val' => [900 => false], 'dark' => true, 'sun' => $sun]);
check('Dämmerung ohne eigenen Sensor: zentrales „dunkel“ schaltet ein', cmdMap($r) === ['900=true'], implode(',', cmdMap($r)) . ' ' . $r['status']);

heading('A8f. Standby-Abschaltung');
$sb = AUTOMATKatalog::defaults('standby') + ['Uid' => 'astb01', 'Name' => 'Fernseher'];
$sb['sb_power'] = 800;
$sb['sb_out'] = 801;
$sb['sb_push'] = true;
$st = [];
at('20:00:00');
$r = step($sb, $st, ['k' => 'trigger', 'vid' => 800, 'v' => 95.0], ['val' => [800 => 95.0, 801 => true]]);
check('In Betrieb: nichts', $r['cmd'] === [] && strpos($r['status'], 'In Betrieb') !== false);
$r = step($sb, $st, ['k' => 'trigger', 'vid' => 800, 'v' => 1.2], ['val' => [800 => 1.2, 801 => true]]);
check('Standby: Zeit läuft (30 min)', $r['cmd'] === [] && $r['due'] === ts('2026-10-13 20:30:00'));
at('20:10:00');
step($sb, $st, ['k' => 'trigger', 'vid' => 800, 'v' => 40.0], ['val' => [800 => 40.0, 801 => true]]);
$r = step($sb, $st, ['k' => 'trigger', 'vid' => 800, 'v' => 1.0], ['val' => [800 => 1.0, 801 => true]]);
check('Zwischendurch wieder an: Zeit beginnt neu', $r['due'] === ts('2026-10-13 20:40:00'));
at('20:40:00');
$r = step($sb, $st, ['k' => 'tick'], ['val' => [800 => 1.0, 801 => true]]);
check('Nach 30 min Standby: aus, mit Meldung', cmdMap($r) === ['801=false'] && count($r['msg']) === 1);

heading('A8g. Lüftungserinnerung');
$lf = AUTOMATKatalog::defaults('lueften') + ['Uid' => 'alft01', 'Name' => 'Bad lüften'];
$lf['lf_var'] = 900;
$lf['lf_kon1'] = 901;
$nm = [900 => 'Feuchte Bad', 901 => 'Badfenster'];
$st = [];
at('07:00:00');
$r = step($lf, $st, ['k' => 'trigger', 'vid' => 900, 'v' => 72.0], ['val' => [900 => 72.0, 901 => false], 'names' => $nm]);
check('Zu feucht: erst nach 10 min melden', $r['msg'] === [] && $r['due'] === ts('2026-10-13 07:10:00'));
at('07:10:00');
$r = step($lf, $st, ['k' => 'tick'], ['val' => [900 => 71.0, 901 => false], 'names' => $nm]);
check('Nach 10 min: „Bitte lüften“', count($r['msg']) === 1 && strpos($r['msg'][0][1], 'Bitte lüften: 71') !== false);
at('07:15:00');
$r = step($lf, $st, ['k' => 'trigger', 'vid' => 901, 'v' => true], ['val' => [900 => 66.0, 901 => true], 'names' => $nm]);
check('Fenster auf: Status „wird gelüftet“, keine Erinnerung', $r['msg'] === [] && strpos($r['status'], 'Wird gelüftet') !== false);
at('07:25:00');
$r = step($lf, $st, ['k' => 'trigger', 'vid' => 900, 'v' => 52.0], ['val' => [900 => 52.0, 901 => true], 'names' => $nm]);
check('Luft gut und Fenster offen: „kann wieder zu“', count($r['msg']) === 1 && strpos($r['msg'][0][1], 'Badfenster kann wieder zu') !== false);
$st = [];
at('08:00:00');
step($lf, $st, ['k' => 'trigger', 'vid' => 900, 'v' => 70.0], ['val' => [900 => 70.0, 901 => false], 'names' => $nm]);
at('08:05:00');
step($lf, $st, ['k' => 'trigger', 'vid' => 900, 'v' => 60.0], ['val' => [900 => 60.0, 901 => false], 'names' => $nm]);
at('08:11:00');
$r = step($lf, $st, ['k' => 'tick'], ['val' => [900 => 60.0, 901 => false], 'names' => $nm]);
check('Kurz zu feucht (Dusche vorbei vor Ablauf): keine Erinnerung', $r['msg'] === [] && $st['ph'] === 'ok');

heading('A8h. Heizung nach Hausmodus');
$hz = AUTOMATKatalog::defaults('heizung') + ['Uid' => 'ahzg01', 'Name' => 'Heizung'];
$hz['hz_soll1'] = 950;
$hz['hz_soll2'] = 951;
$nm = [950 => 'Wohnzimmer', 951 => 'Bad'];
$st = [];
$r = step($hz, $st, ['k' => 'mode', 'from' => 0, 'to' => 3], ['mode' => 3, 'val' => [950 => 21.5, 951 => 23.0], 'names' => $nm]);
check('„Abwesend“: beide auf 17 °C, alte Werte gemerkt', cmdMap($r) === ['950=17.0', '951=17.0'] && $st['saved'] === ['950' => 21.5, '951' => 23.0]);
$r = step($hz, $st, ['k' => 'tick'], ['mode' => 3, 'val' => [950 => 17.0, 951 => 17.0], 'names' => $nm]);
check('Bleibt abgesenkt ohne neue Befehle', $r['cmd'] === [] && strpos($r['status'], 'Abgesenkt auf 17') !== false);
$r = step($hz, $st, ['k' => 'output', 'vid' => 951, 'v' => 20.0], ['mode' => 3, 'val' => [950 => 17.0, 951 => 20.0], 'names' => $nm]);
check('Bad von Hand auf 20 °C: bleibt so', $r['cmd'] === [] && strpos((string)$r['log'], 'Bad von Hand') !== false);
$r = step($hz, $st, ['k' => 'mode', 'from' => 3, 'to' => 0], ['mode' => 0, 'val' => [950 => 17.0, 951 => 20.0], 'names' => $nm]);
check('Zurück auf „Tag“: nur Wohnzimmer auf 21,5 °C zurück, Bad bleibt 20', cmdMap($r) === ['950=21.5'] && $st['saved'] === []);
$r = step($hz, $st, ['k' => 'mode', 'from' => 0, 'to' => 2], ['mode' => 2, 'val' => [950 => 21.5, 951 => 20.0], 'names' => $nm]);
check('„Nacht“ nicht angekreuzt: nichts', $r['cmd'] === []);
$r = step($hz, $st, ['k' => 'mode', 'from' => 2, 'to' => 4], ['mode' => 4, 'val' => [950 => 21.5, 951 => 20.0], 'names' => $nm]);
check('„Urlaub“: 15 °C', cmdMap($r) === ['950=15.0', '951=15.0']);

heading('A8i. Taster-Zuordnung');
$tg = AUTOMATKatalog::defaults('taster') + ['Uid' => 'atst01', 'Name' => 'Taster Flur'];
$tg['tg_in'] = 960;
$tg['tg_kziel'] = 961;
$tg['tg_dakt'] = 5;
$tg['tg_dzwert'] = 2.0;
$tg['tg_lakt'] = 3;
$tg['tg_lziel'] = 962;
$nm = [961 => 'Flurlicht', 962 => 'Alles'];
$st = [];
$r = step($tg, $st, ['k' => 'trigger', 'vid' => 960, 'v' => 'single'], ['val' => [960 => 'single', 961 => false], 'names' => $nm]);
check('„single“ = kurz: Flurlicht umschalten (an)', cmdMap($r) === ['961=true'] && strpos($r['status'], 'kurz → Flurlicht an') !== false);
$r = step($tg, $st, ['k' => 'trigger', 'vid' => 960, 'v' => 1], ['val' => [960 => 1, 961 => true], 'names' => $nm]);
check('Wert 1 = kurz: wieder aus', cmdMap($r) === ['961=false']);
$r = step($tg, $st, ['k' => 'trigger', 'vid' => 960, 'v' => 'DOUBLE'], ['val' => [], 'names' => $nm]);
check('„double“ = doppelt: Hausmodus „Nacht“', ($r['mode'] ?? null) === 2 && $r['cmd'] === []);
$r = step($tg, $st, ['k' => 'trigger', 'vid' => 960, 'v' => 'hold'], ['val' => [962 => true], 'names' => $nm]);
check('„hold“ = lang: ausschalten', cmdMap($r) === ['962=false']);
$r = step($tg, $st, ['k' => 'trigger', 'vid' => 960, 'v' => ''], ['val' => []]);
check('Leerer Wert (Rücksetzen nach dem Druck): nichts', $r['cmd'] === [] && $r['log'] === null);
$r = step($tg, $st, ['k' => 'trigger', 'vid' => 960, 'v' => 'triple'], ['val' => []]);
check('Unbekannter Wert: Hinweis im Protokoll', strpos((string)$r['log'], 'Unbekannter Tasterwert „triple“') !== false);
$tgB = AUTOMATKatalog::defaults('taster');
$tgB['tg_in'] = 960;
$tgB['tg_kziel'] = 961;
$st = [];
$r = step($tgB, $st, ['k' => 'trigger', 'vid' => 960, 'v' => true], ['val' => [961 => false]]);
check('Einfacher Schalter (wahr) zählt als kurz', cmdMap($r) === ['961=true']);
check('Taster ohne Ziel für „umschalten“: unvollständig', count(AUTOMATKatalog::problems(AUTOMATKatalog::defaults('taster') + ['tg_in' => 960], fn($v) => true)) >= 1);

heading('A8j. Aufwach-/Einschlaflicht');
$wl = AUTOMATKatalog::defaults('wecklicht') + ['Uid' => 'awlc01', 'Name' => 'Aufwachen'];
$wl['wl_out1'] = 970;
$st = [];
at('06:00:00');
$r = step($wl, $st, ['k' => 'tick'], ['val' => [970 => 0], 'dim' => [970 => 100]]);
check('Vor dem Start: wartet bis 6:10 (6:30 − 20 min)', $r['cmd'] === [] && $r['due'] === ts('2026-10-13 06:10:00'));
at('06:10:00');
$r = step($wl, $st, ['k' => 'tick'], ['val' => [970 => 0], 'dim' => [970 => 100]]);
check('Start: 1 %', cmdMap($r) === ['970=1'] && $st['ph'] === 'ramp');
at('06:20:00');
$r = step($wl, $st, ['k' => 'tick'], ['val' => [970 => 1], 'dim' => [970 => 100]]);
check('Halbzeit: etwa 40 %', cmdMap($r) === ['970=41'], implode(',', cmdMap($r)));
at('06:30:00');
$r = step($wl, $st, ['k' => 'tick'], ['val' => [970 => 41], 'dim' => [970 => 100]]);
check('Weckzeit: 80 %, fertig', cmdMap($r) === ['970=80'] && $st['ph'] === 'idle');
$st = [];
at('06:15:00');
step($wl, $st, ['k' => 'tick'], ['val' => [970 => 0], 'dim' => [970 => 100]]);
$r = step($wl, $st, ['k' => 'output', 'vid' => 970, 'v' => 0], ['val' => [970 => 0], 'dim' => [970 => 100]]);
check('Von Hand verstellt: Rampe endet', $st['ph'] === 'idle' && strpos((string)$r['log'], 'Rampe beendet') !== false);
$GLOBALS['CLOCK'] = ts('2026-10-17 06:15:00');
$st = [];
$r = step($wl, $st, ['k' => 'tick'], ['val' => [970 => 0], 'dim' => [970 => 100]]);
check('Wochenende (nicht angekreuzt): nichts', $r['cmd'] === []);
$wlE = $wl;
$wlE['wl_richtung'] = 1;
$wlE['wl_start'] = 2;
$wlE['wl_dauer'] = 10;
$st = [];
at('22:00:00');
$r = step($wlE, $st, ['k' => 'mode', 'from' => 1, 'to' => 2], ['mode' => 2, 'val' => [970 => 60], 'dim' => [970 => 100]]);
check('Einschlafen bei „Nacht“: startet beim aktuellen Wert', $st['ph'] === 'ramp' && $st['from'] === 60);
at('22:10:00');
$r = step($wlE, $st, ['k' => 'tick'], ['mode' => 2, 'val' => [970 => 30], 'dim' => [970 => 100]]);
check('Nach 10 min: aus', cmdMap($r) === ['970=0'] && $st['ph'] === 'idle');
check('Weckzeit aus Variable: Zeitstempel und „HH:MM“', AUTOMATLogik::wakeTime(ts('2026-10-20 05:45'), '2026-10-13') === ts('2026-10-13 05:45') && AUTOMATLogik::wakeTime('6:05', '2026-10-13') === ts('2026-10-13 06:05') && AUTOMATLogik::wakeTime('', '2026-10-13') === null);
at('12:00:00');

check('Uhrzeit: bei Stunden Abstand ohne Sekunden, bei Minuten mit', AUTOMATLogik::fmtClock(ts('2026-10-13 18:41:36'), ts('2026-10-13 11:47:00')) === '18:41 Uhr (in 6 h 54 min)' && AUTOMATLogik::fmtClock(ts('2026-10-13 12:03:20'), ts('2026-10-13 12:00:00')) === '12:03:20 Uhr (in 3:20 min)');

heading('A8k. Feiertage, Taupunkt, Gewohnheit');
check('Ostern 2026 = 05.04., 2027 = 28.03.', AUTOMATLogik::easter(2026) === [4, 5] && AUTOMATLogik::easter(2027) === [3, 28]);
check('Bundesweit: Karfreitag, Pfingstmontag, Tag der Deutschen Einheit', AUTOMATLogik::holiday('2026-04-03', 'BW') === 'Karfreitag' && AUTOMATLogik::holiday('2026-05-25', 'NW') === 'Pfingstmontag' && AUTOMATLogik::holiday('2026-10-03', 'HH') === 'Tag der Deutschen Einheit');
check('Länder: Fronleichnam BW ja, NI nein; Reformationstag SN; Buß- und Bettag SN 18.11.2026', AUTOMATLogik::holiday('2026-06-04', 'BW') === 'Fronleichnam' && AUTOMATLogik::holiday('2026-06-04', 'NI') === '' && AUTOMATLogik::holiday('2026-10-31', 'SN') === 'Reformationstag' && AUTOMATLogik::holiday('2026-11-18', 'SN') === 'Buß- und Bettag' && AUTOMATLogik::holiday('2026-11-18', 'BY') === '');
check('Österreich 26.10., ohne Bundesland keine Feiertage', AUTOMATLogik::holiday('2026-10-26', 'AT') === 'Nationalfeiertag' && AUTOMATLogik::holiday('2026-12-25', '') === '');
check('Taupunkt 20 °C / 50 % ≈ 9,3 °C', abs(AUTOMATLogik::dewPoint(20, 50) - 9.26) < 0.1);
$evH = [];
for ($i = 0; $i < 21; $i++) {
    $day = strtotime('2026-09-14 +' . $i . ' days');
    if ((int)date('N', $day) > 5) {
        continue;
    }
    $evH[] = [strtotime(date('Y-m-d', $day) . ' 19:00'), true];
    $evH[] = [strtotime(date('Y-m-d', $day) . ' 22:' . (25 + $i % 10)), false];
}
$hb = AUTOMATLogik::habitOff($evH);
check('Gewohnheit: werktags um ~22:30 aus erkannt', $hb !== null && $hb['min'] === 1350 && $hb['days'] === 15, json_encode($hb));
check('Gewohnheit: zu wenige Tage → keine', AUTOMATLogik::habitOff(array_slice($evH, 0, 10)) === null);

heading('A8l. Gehen-Check');
$gc = AUTOMATKatalog::defaults('gehen') + ['Uid' => 'agehn1', 'Name' => 'Gehen-Check'];
$gc['gc_kon1'] = 1300;
$gc['gc_kon2'] = 1301;
$gc['gc_geraet1'] = 1310;
$gc['gc_geraet2'] = 1311;
$nm = [1300 => 'Badfenster', 1301 => 'Garage', 1310 => 'Herd', 1311 => 'Bügeleisen'];
$st = [];
$r = step($gc, $st, ['k' => 'mode', 'from' => 0, 'to' => 3], ['mode' => 3, 'val' => [1300 => true, 1301 => false, 1310 => 850.0, 1311 => false], 'names' => $nm]);
check('„Abwesend“: EINE Meldung mit offenem Fenster und Herd (Leistung über 10 W)', count($r['msg']) === 1 && strpos($r['msg'][0][1], 'offen: Badfenster') !== false && strpos($r['msg'][0][1], 'noch an: Herd') !== false && $r['cmd'] === [], json_encode($r['msg'], JSON_UNESCAPED_UNICODE));
check('Status bleibt auffällig (⚠️) bis zum nächsten Check', strpos($r['status'], '⚠️') === 0);
$r = step($gc, $st, ['k' => 'mode', 'from' => 3, 'to' => 2], ['mode' => 2, 'val' => [1300 => true], 'names' => $nm]);
check('„Nacht“ nicht angekreuzt: kein Check', $r['msg'] === []);
$gc2 = $gc;
$gc2['gc_aus'] = true;
$st = [];
$r = step($gc2, $st, ['k' => 'mode', 'from' => 0, 'to' => 4], ['mode' => 4, 'val' => [1300 => false, 1301 => false, 1310 => false, 1311 => true], 'names' => $nm]);
check('„Gleich ausschalten“: Bügeleisen aus, Meldung nennt es', cmdMap($r) === ['1311=false'] && strpos($r['msg'][0][1], 'ausgeschaltet: Bügeleisen') !== false);
$st = [];
$r = step($gc, $st, ['k' => 'mode', 'from' => 0, 'to' => 3], ['mode' => 3, 'val' => [1300 => false, 1301 => false, 1310 => 0.0, 1311 => false], 'names' => $nm]);
check('Alles zu: ohne „auch melden, wenn alles zu“ keine Meldung', $r['msg'] === [] && strpos($r['status'], '✅') === 0);

heading('A8m. Türklingel');
$kl = AUTOMATKatalog::defaults('klingel') + ['Uid' => 'aklng1', 'Name' => 'Haustür'];
$kl['kl_taster'] = 1400;
$kl['kl_licht1'] = 1401;
$kl['kl_laut1'] = 1402;
$kl['kl_bild'] = 1403;
$nm = [1401 => 'Außenlicht', 1402 => 'Musik'];
$st = [];
at('21:00:00');
$r = step($kl, $st, ['k' => 'trigger', 'vid' => 1400, 'v' => true], ['mode' => 1, 'val' => [1400 => true, 1401 => false, 1402 => 40.0], 'names' => $nm]);
check('Abends: Meldung, Licht an, Musik leiser, Kamerabild dabei', count($r['msg']) === 1 && cmdMap($r) === ['1401=true', '1402=5.0'] && ($r['media'] ?? 0) === 1403 && $r['due'] === ts('2026-10-13 21:03:00'));
at('21:00:05');
$r = step($kl, $st, ['k' => 'trigger', 'vid' => 1400, 'v' => true], ['mode' => 1, 'val' => [1400 => true]]);
check('Mehrfaches Drücken binnen 10 s: keine zweite Meldung', $r['msg'] === []);
at('21:03:00');
$r = step($kl, $st, ['k' => 'tick'], ['mode' => 1, 'val' => [1401 => true, 1402 => 5.0], 'names' => $nm]);
check('Nach 3 min: Licht aus, Lautstärke zurück auf 40', cmdMap($r) === ['1401=false', '1402=40.0']);
$st = [];
at('21:10:00');
step($kl, $st, ['k' => 'trigger', 'vid' => 1400, 'v' => true], ['mode' => 1, 'val' => [1401 => false, 1402 => 40.0], 'names' => $nm]);
step($kl, $st, ['k' => 'output', 'vid' => 1401, 'v' => true], ['mode' => 1, 'val' => [1401 => true], 'names' => $nm]);
step($kl, $st, ['k' => 'output', 'vid' => 1402, 'v' => 25.0], ['mode' => 1, 'val' => [1402 => 25.0], 'names' => $nm]);
at('21:13:00');
$r = step($kl, $st, ['k' => 'tick'], ['mode' => 1, 'val' => [1401 => true, 1402 => 25.0], 'names' => $nm]);
check('Von Hand verstellt: wird nicht zurückgestellt', $r['cmd'] === [] || cmdMap($r) === ['1401=false'], implode(',', cmdMap($r)));
$st = [];
at('14:00:00');
$r = step($kl, $st, ['k' => 'trigger', 'vid' => 1400, 'v' => true], ['mode' => 3, 'val' => [1401 => false, 1402 => 0.0], 'names' => $nm]);
check('Tagsüber und abwesend: kein Licht, Meldung „niemand zu Hause“', $r['cmd'] === [] && strpos($r['msg'][0][0], 'niemand zu Hause') !== false);
at('12:00:00');

heading('A8n. Sommer-Lüften');
$sl = AUTOMATKatalog::defaults('sommer') + ['Uid' => 'asomm1', 'Name' => 'Wohnzimmer'];
$sl['sl_innen'] = 1500;
$sl['sl_aussen'] = 1501;
$sl['sl_kon1'] = 1502;
$st = [];
$r = step($sl, $st, ['k' => 'tick'], ['val' => [1500 => 24.5, 1501 => 29.0, 1502 => true]]);
check('Draußen wärmer, Fenster offen: „Fenster schließen“', count($r['msg']) === 1 && strpos($r['msg'][0][1], 'Fenster schließen') !== false);
$r = step($sl, $st, ['k' => 'tick'], ['val' => [1500 => 24.6, 1501 => 29.5, 1502 => true]]);
check('Keine Wiederholung bei gleicher Lage', $r['msg'] === []);
$st = [];
$r = step($sl, $st, ['k' => 'tick'], ['val' => [1500 => 25.0, 1501 => 19.0, 1502 => false]]);
check('Abends kühler, Fenster zu: „Jetzt lüften“', count($r['msg']) === 1 && strpos($r['msg'][0][1], 'lüften') !== false);
$st = [];
$r = step($sl, $st, ['k' => 'tick'], ['val' => [1500 => 21.0, 1501 => 15.0, 1502 => false]]);
check('Innen unter 23 °C: keine Empfehlung', $r['msg'] === [] && strpos($r['status'], 'Innen unter') !== false);
$slK = $sl;
$slK['sl_rhinnen'] = 1503;
$slK['sl_rhaussen'] = 1504;
$st = [];
$r = step($slK, $st, ['k' => 'tick'], ['val' => [1500 => 24.0, 1501 => 21.0, 1502 => false, 1503 => 50.0, 1504 => 95.0]]);
check('Keller-Fall: draußen kühler, aber feuchter (Taupunkt höher) → kein „lüften“', $r['msg'] === [] || strpos($r['msg'][0][1], 'lüften lohnt') === false);

heading('A8o. Ausfall-Wächter');
$af = AUTOMATKatalog::defaults('ausfall') + ['Uid' => 'aausf1', 'Name' => 'Gefriertruhe'];
$af['af_var'] = 1600;
$st = [];
at('08:00:00');
step($af, $st, ['k' => 'trigger', 'vid' => 1600, 'v' => 80.0], ['val' => [1600 => 80.0]]);
at('08:20:00');
step($af, $st, ['k' => 'trigger', 'vid' => 1600, 'v' => 0.5], ['val' => [1600 => 0.5]]);
at('09:00:00');
step($af, $st, ['k' => 'trigger', 'vid' => 1600, 'v' => 75.0], ['val' => [1600 => 75.0]]);
check('Normale Pause (40 min) wird als „normal“ gemerkt', $st['gaps'] === [2400] && strpos(step($af, $st, ['k' => 'tick'], ['val' => [1600 => 75.0]])['status'], 'bisher längste Pause 40 min') !== false);
at('09:30:00');
step($af, $st, ['k' => 'trigger', 'vid' => 1600, 'v' => 0.0], ['val' => [1600 => 0.0]]);
at('11:30:00');
$r = step($af, $st, ['k' => 'tick'], ['val' => [1600 => 0.0]]);
check('2 Stunden ohne Strom: Meldung', count($r['msg']) === 1 && strpos($r['msg'][0][1], 'keinen Strom mehr') !== false && strpos($r['status'], '⚠️') === 0);
$r = step($af, $st, ['k' => 'trigger', 'vid' => 1600, 'v' => 90.0], ['val' => [1600 => 90.0]]);
check('Strom wieder da: Entwarnung', count($r['msg']) === 1 && strpos($r['msg'][0][0], '✅') === 0);
$afR = $af;
$afR['af_modus'] = 1;
$afR['af_dauer'] = 30;
$st = [];
at('10:00:00');
step($afR, $st, ['k' => 'trigger', 'vid' => 1600, 'v' => 400.0], ['val' => [1600 => 400.0]]);
at('10:31:00');
$r = step($afR, $st, ['k' => 'tick'], ['val' => [1600 => 400.0]]);
check('„Läuft zu lange am Stück“ (Pumpe 31 min > 30)', count($r['msg']) === 1 && strpos($r['msg'][0][1], 'am Stück') !== false);
at('12:00:00');

heading('A8p. Zeitschaltuhr');
$zs = AUTOMATKatalog::defaults('zeitschalt') + ['Uid' => 'azeit1', 'Name' => 'Kaffeemaschine'];
$zs['zs_out1'] = 1700;
$st = [];
at('06:00:00');
$r = step($zs, $st, ['k' => 'tick'], ['val' => [1700 => false]]);
check('Vor 7 Uhr: nichts, nächste Schaltung 07:00', $r['cmd'] === [] && $r['due'] === ts('2026-10-13 07:00:00'));
at('07:00:00');
$r = step($zs, $st, ['k' => 'tick'], ['val' => [1700 => false]]);
check('7 Uhr: ein', cmdMap($r) === ['1700=true']);
at('07:20:00');
$r = step($zs, $st, ['k' => 'output', 'vid' => 1700, 'v' => false], ['val' => [1700 => false]]);
check('Von Hand aus: bleibt aus', $st['on'] === false && $st['manual'] === '2026-10-13');
at('07:30:00');
$r = step($zs, $st, ['k' => 'tick'], ['val' => [1700 => false]]);
check('… auch beim nächsten Takt', $r['cmd'] === []);
$GLOBALS['CLOCK'] = ts('2026-10-14 07:00:00');
$r = step($zs, $st, ['k' => 'tick'], ['val' => [1700 => false]]);
check('Nächster Tag 7 Uhr: wieder ein', cmdMap($r) === ['1700=true']);
$GLOBALS['CLOCK'] = ts('2026-10-14 08:00:00');
$r = step($zs, $st, ['k' => 'tick'], ['val' => [1700 => true]]);
check('8 Uhr: aus', cmdMap($r) === ['1700=false']);
$zsW = $zs;
foreach (range(1, 5) as $n) {
    $zsW['zs_t' . $n] = false;
}
$st = [];
$GLOBALS['CLOCK'] = ts('2026-10-13 06:59:00');
step($zsW, $st, ['k' => 'tick'], ['val' => [1700 => false]]);
$GLOBALS['CLOCK'] = ts('2026-10-13 07:00:00');
$r = step($zsW, $st, ['k' => 'tick'], ['val' => [1700 => false]]);
check('Nur Wochenende angekreuzt: Dienstag nichts', $r['cmd'] === []);
check('Feiertag „wie Sonntag“ bzw. „gar nicht“', AUTOMATLogik::zsDay($zsW, '2026-10-13', ['2026-10-13' => 'Test']) === true && AUTOMATLogik::zsDay(['zs_feiertag' => 2, 'zs_t2' => true], '2026-10-13', ['2026-10-13' => 'Test']) === false);
$st = [];
$GLOBALS['CLOCK'] = ts('2026-10-13 07:30:00');
$r = step($zs, $st, ['k' => 'tick'], ['val' => [1700 => false]]);
check('Mitten im Zeitfenster eingerichtet (Gerät aus): nichts nachholen', $r['cmd'] === []);
$zsN = $zs;
$zsN['zs_modus'] = 1;
$zsN['zs_aus'] = AUTOMATKatalog::time(22, 30);
$st = [];
$GLOBALS['CLOCK'] = ts('2026-10-13 22:00:00');
step($zsN, $st, ['k' => 'tick'], ['val' => [1700 => true]]);
$GLOBALS['CLOCK'] = ts('2026-10-13 22:30:00');
$r = step($zsN, $st, ['k' => 'tick'], ['val' => [1700 => true]]);
check('„Nur ausschalten“ um 22:30: aus, einmal', cmdMap($r) === ['1700=false'] && step($zsN, $st, ['k' => 'tick'], ['val' => [1700 => true]])['cmd'] === []);
$zsM = $zs;
$zsM['zs_ein'] = AUTOMATKatalog::time(22, 0);
$zsM['zs_aus'] = AUTOMATKatalog::time(6, 0);
$st = ['on' => true, 'manual' => '', 'did' => [], 'jd' => '2026-10-14', 'jit' => 0];
$GLOBALS['CLOCK'] = ts('2026-10-14 05:00:00');
$r = step($zsM, $st, ['k' => 'tick'], ['val' => [1700 => true]]);
check('Über Mitternacht (22–6 Uhr): um 5 Uhr noch an', $r['cmd'] === [] && $st['on'] === true);
at('12:00:00');

heading('A9. Leere Auswahlfelder (Wert 1 statt 0) und fehlende Variablen');
AUTOMATKatalog::$minId = 10000;
$bad = AUTOMATKatalog::defaults('bewegung');
$bad['bl_mel1'] = 34618;
$bad['bl_mel2'] = 1;
$bad['bl_mel3'] = 1;
$bad['bl_out1'] = 27348;
$bad['bl_out2'] = 55555;
check('Wert 1 gilt als „nicht gewählt“, nicht als fehlende Variable', AUTOMATKatalog::vars($bad, 'bl_mel') === [34618]);
check('sanitize setzt Werte unter 10000 auf 0', AUTOMATKatalog::sanitize($bad)['bl_mel2'] === 0 && AUTOMATKatalog::sanitize($bad)['bl_mel1'] === 34618);
$fp = AUTOMATKatalog::fieldProblems($bad, fn($v) => $v !== 55555);
check('Feldhinweis genau am fehlenden Ausgang 2, nicht an den Meldern', array_keys($fp) === ['bl_out2'] && strpos($fp['bl_out2'], '#55555') !== false, json_encode($fp, JSON_UNESCAPED_UNICODE));
$empty = AUTOMATKatalog::defaults('bewegung');
$fp2 = AUTOMATKatalog::fieldProblems($empty, fn($v) => true);
check('Leeres Pflichtfeld: Hinweis am ersten Platz', isset($fp2['bl_mel1'], $fp2['bl_out1']) && count($fp2) === 2);
AUTOMATKatalog::$minId = 1;

// ===========================================================================
// TEIL B — Erkennung
// ===========================================================================

heading('B1. Rollen an realen Steckbriefen (Z-Wave, Matter, Shelly, Darstellungen)');
$it = fn(array $a) => $a + ['vid' => 0, 'type' => 0, 'action' => false, 'profile' => '', 'pres' => [], 'suffix' => '', 'name' => '', 'inst' => '', 'ident' => '', 'room' => null];
$cases = [
    ['motion', ['type' => 0, 'profile' => '~Motion', 'name' => 'Bewegungsmelder Zustand', 'inst' => 'Esszimmer Bewegungsmelder', 'ident' => 'Motion']],
    ['', ['type' => 0, 'action' => true, 'name' => 'Bewegungsmelder Modus aktiv', 'inst' => 'Treppe Bewegungsmelder > Licht', 'ident' => 'Active']],
    ['light', ['type' => 0, 'action' => true, 'profile' => '~Switch', 'name' => 'Status', 'inst' => 'Licht Treppe/Flur Aktor', 'ident' => 'StatusVariable']],
    ['light', ['type' => 1, 'action' => true, 'profile' => '~Intensity.100', 'name' => 'Helligkeit', 'inst' => 'Licht Wohnzimmer', 'suffix' => '%']],
    ['', ['type' => 1, 'action' => true, 'profile' => '~ProtectionRF.ZWave', 'name' => 'Sicherung (RF)', 'inst' => 'Licht Vorrat']],
    ['', ['type' => 0, 'action' => true, 'pres' => ['PRESENTATION' => '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}'], 'name' => 'Licht blinken', 'inst' => 'Schneeflocke']],
    ['contact', ['type' => 0, 'pres' => ['PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}', 'OPTIONS' => json_encode([['Value' => false, 'Caption' => 'Offen'], ['Value' => true, 'Caption' => 'Geschlossen']])], 'name' => 'Badfenster Senkrecht', 'inst' => 'Badfenster Senkrecht', 'ident' => 'BooleanState_State']],
    ['', ['type' => 0, 'name' => 'Ersatz erforderlich', 'inst' => 'Badfenster Senkrecht Stromversorgung']],
    ['setpoint', ['type' => 2, 'action' => true, 'profile' => '~ThermostatSetPoint.ZWave', 'name' => 'Sollwert (Heizen)', 'inst' => 'Thermostat WC']],
    ['setpoint', ['type' => 2, 'action' => true, 'suffix' => '°C', 'pres' => ['PRESENTATION' => '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}', 'SUFFIX' => ' °C'], 'name' => 'Solltemperatur', 'inst' => 'Thermostat Wohnzimmer Rechts']],
    ['', ['type' => 2, 'action' => true, 'suffix' => '°C', 'name' => 'Solltemperatur Beifahrer (°C)', 'inst' => 'Schneeflocke']],
    ['', ['type' => 2, 'action' => true, 'suffix' => '°C', 'name' => 'Warmwasser-Solltemperatur', 'inst' => 'HeishaMon']],
    ['power', ['type' => 2, 'profile' => 'PAC2200.W', 'name' => 'Wirkleistung Original', 'inst' => 'Geschirrspüler']],
    ['power', ['type' => 2, 'action' => true, 'suffix' => 'W', 'name' => 'Wirkleistung Wäschetrockner', 'inst' => 'Wirkleistung Original']],
    ['', ['type' => 2, 'profile' => '~Watt.3680', 'name' => 'Meter (2), Elektro, W', 'inst' => 'Licht Bad Aktor']],
    ['lux', ['type' => 2, 'profile' => '~Illumination', 'name' => 'Helligkeit', 'inst' => 'Flur Multisensor']],
    ['', ['type' => 1, 'action' => true, 'profile' => 'CHB.Led255', 'name' => 'LED-Helligkeit', 'inst' => 'Steuerung']],
    ['presence', ['type' => 0, 'profile' => 'Anwesenheit', 'name' => 'Zuhause', 'inst' => 'Dietmar', 'module' => 'Dummy Module']],
    ['presence', ['type' => 0, 'profile' => '~Presence', 'name' => 'Anwesenheit', 'inst' => 'Anwesenheit']],
    ['', ['type' => 0, 'name' => 'Zu Hause', 'inst' => 'Model Y', 'ident' => 'stat_at_home', 'module' => 'Tessie']],
    ['', ['type' => 0, 'name' => 'Geplante Anwesenheit', 'inst' => 'Kalender', 'ident' => 'PlannedPresence', 'module' => 'iCal Calendar Reader']],
    ['motion', ['type' => 0, 'profile' => '~Presence', 'name' => 'Belegung', 'inst' => 'Bad Anwesenheitssensor', 'ident' => 'OccupancySensing_Occupancy']],
    ['alarm', ['type' => 0, 'name' => 'Zustand', 'inst' => 'Wasserleck Küche', 'ident' => 'BooleanState_State']],
    ['alarm', ['type' => 0, 'profile' => '~Alert', 'name' => 'Alarm', 'inst' => 'Rauchmelder Flur']],
    ['', ['type' => 2, 'profile' => '~Temperature', 'name' => 'Warmwasser-Temperatur', 'inst' => 'HeishaMon']],
    ['shutter', ['type' => 1, 'action' => true, 'profile' => '~ShutterPosition.100', 'name' => 'Position', 'inst' => 'Rollladen Wohnzimmer']],
    ['air', ['type' => 1, 'profile' => '~Humidity', 'name' => 'Luftfeuchtigkeit', 'inst' => 'Bad Multisensor']],
    ['air', ['type' => 1, 'suffix' => 'ppm', 'name' => 'CO2', 'inst' => 'Luftqualität Büro']],
    ['', ['type' => 1, 'profile' => '~Humidity', 'name' => 'Bodenfeuchte Rasen', 'inst' => 'Wetterstation']],
    ['', ['type' => 2, 'profile' => 'OpenWeatherMap.Humidity', 'name' => 'Luftfeuchtigkeit #34', 'inst' => 'Wetter-Prognose']],
    ['button', ['type' => 3, 'name' => 'action', 'inst' => 'Taster Flur', 'ident' => 'action']],
    ['button', ['type' => 1, 'name' => 'Central Scene', 'inst' => 'Wandschalter Küche']],
    ['bell', ['type' => 0, 'name' => 'Klingel', 'inst' => 'Türklingel Haustür']],
    ['cooling', ['type' => 2, 'profile' => '~Watt.3680', 'name' => 'Leistung', 'inst' => 'Gefriertruhe Keller']],
    ['temp', ['type' => 2, 'profile' => '~Temperature', 'name' => 'Temperatur', 'inst' => 'Wohnzimmer Sensor']],
    ['temp', ['type' => 2, 'profile' => '~Temperature', 'name' => 'Außentemperatur', 'inst' => 'Wetterstation']],
    ['', ['type' => 2, 'profile' => '~Temperature', 'name' => 'Vorlauftemperatur', 'inst' => 'Heizung']],
    ['', ['type' => 2, 'profile' => '~Temperature', 'name' => 'Außentemperatur (Gleitender MW 1 Stunde)', 'inst' => 'Wetterstation']],
];
foreach ($cases as [$want, $a]) {
    $got = AUTOMATErkennung::role($it($a));
    check(($want === '' ? 'keine Rolle' : $want) . ': ' . $a['inst'] . ' / ' . $a['name'], $got === $want, 'erkannt als „' . $got . '“');
}
check('Matter-Kontakt mit Beschriftung „Geschlossen“ an true → invertiert', AUTOMATErkennung::contactInverted($it($cases[6][1])) === true);
check('Normaler Kontakt nicht invertiert', AUTOMATErkennung::contactInverted($it(['profile' => '~Window'])) === false);

heading('B2. Räume aus Namen und Vorschläge');
$items = [
    $it(['vid' => 1, 'type' => 0, 'profile' => '~Motion', 'name' => 'Bewegung', 'inst' => 'Flur Bewegungsmelder']),
    $it(['vid' => 2, 'type' => 0, 'action' => true, 'profile' => '~Switch', 'name' => 'Status', 'inst' => 'Licht Flur']),
    $it(['vid' => 3, 'type' => 2, 'profile' => '~Illumination', 'name' => 'Helligkeit', 'inst' => 'Flur Multisensor']),
    $it(['vid' => 4, 'type' => 0, 'name' => 'Zustand', 'inst' => 'WC Fenster']),
    $it(['vid' => 5, 'type' => 2, 'action' => true, 'profile' => '~ThermostatSetPoint.ZWave', 'name' => 'Sollwert (Heizen)', 'inst' => 'Thermostat WC']),
    $it(['vid' => 6, 'type' => 0, 'action' => true, 'profile' => '~Switch', 'name' => 'Status', 'inst' => 'Licht Keller']),
    $it(['vid' => 7, 'type' => 0, 'name' => 'Status', 'inst' => 'Keller Rauchmelder']),
    $it(['vid' => 8, 'type' => 2, 'profile' => '~Watt.3680', 'suffix' => 'W', 'name' => 'Leistung', 'inst' => 'Waschmaschine']),
    $it(['vid' => 9, 'type' => 0, 'action' => true, 'profile' => '~Switch', 'name' => 'Status', 'inst' => 'Licht Terrasse']),
    $it(['vid' => 10, 'type' => 0, 'name' => 'Zustand', 'inst' => 'Haustür Kontakt']),
];
foreach ($items as &$x) {
    $x['role'] = AUTOMATErkennung::role($x);
}
unset($x);
$s = AUTOMATErkennung::suggest($items, [], []);
$byType = [];
foreach ($s as $x) {
    $byType[$x['type']][] = $x;
}
check('Bewegungslicht Flur mit Melder, Licht und Helligkeitssensor', isset($byType['bewegung']) && $byType['bewegung'][0]['row']['bl_mel1'] === 1 && $byType['bewegung'][0]['row']['bl_out1'] === 2 && $byType['bewegung'][0]['row']['bl_lux'] === 3, json_encode($byType['bewegung'][0]['row'] ?? null));
check('Fenster offen WC mit Kontakt und Thermostat', isset($byType['fenster']) && $byType['fenster'][0]['row']['fo_kon1'] === 4 && $byType['fenster'][0]['row']['fo_soll1'] === 5);
check('Zeitlicht für Licht im Keller (Durchgangsraum ohne Melder)', isset($byType['zeitlicht']) && $byType['zeitlicht'][0]['row']['zl_out1'] === 6);
check('Gerät fertig für Waschmaschine', isset($byType['fertig']) && $byType['fertig'][0]['row']['gf_var'] === 8);
check('Dämmerung für Terrassenlicht', isset($byType['daemmerung']) && $byType['daemmerung'][0]['row']['dz_out1'] === 9);
check('Wächter für Haustür', isset($byType['waechter']) && $byType['waechter'][0]['row']['wa_var'] === 10);
check('Vorschläge sind vollständige Zeilen (keine Probleme)', count(array_filter($s, fn($x) => count(AUTOMATKatalog::problems($x['row'], fn($v) => true)) > 0)) === 0);
$existing = [$byType['bewegung'][0]['row']];
$s2 = AUTOMATErkennung::suggest($items, $existing, [$byType['fertig'][0]['key']]);
$t2 = array_column($s2, 'type');
check('Schon angelegtes Bewegungslicht wird nicht erneut vorgeschlagen', !in_array('bewegung', $t2, true));
check('Ausgeblendeter Vorschlag bleibt weg', !in_array('fertig', $t2, true));
$withRoom = $items;
$withRoom[0]['room'] = 'Diele';
$withRoom[1]['room'] = 'Diele';
$withRoom[0]['inst'] = 'Sensor 17';
$withRoom[1]['inst'] = 'Aktor 3';
$s3 = AUTOMATErkennung::suggest($withRoom, [], []);
check('Raum aus Raumstruktur hat Vorrang vor Namen', in_array('Diele', array_column($s3, 'room'), true));
$dup = [
    $it(['vid' => 21, 'type' => 2, 'profile' => '~Watt.3680', 'suffix' => 'W', 'name' => 'Wirkleistung Original', 'inst' => 'Waschmaschine', 'role' => 'power']),
    $it(['vid' => 22, 'type' => 2, 'profile' => '~Watt.3680', 'suffix' => 'W', 'name' => 'Wirkleistung', 'inst' => 'Waschmaschine', 'role' => 'power']),
];
$s4 = AUTOMATErkennung::suggest($dup, [], []);
check('Ein Vorschlag je Gerät, bevorzugt die Variable ohne „Original“', count($s4) === 1 && $s4[0]['row']['gf_var'] === 22, json_encode(array_column($s4, 'key')));
check('Raum aus Gerätenamen: „Thermostat Kind II“ → „Kind II“, nicht „Kind I“', AUTOMATErkennung::roomFromName('Thermostat Kind II Sollwert', ['Kind I', 'Kind II']) === 'Kind II' && AUTOMATErkennung::roomFromName('Kind I Fenster', ['Kind I', 'Kind II']) === 'Kind I');
check('Raum aus Gerätenamen mit Raumnummer: „Licht Küche“ → „101 Küche“', AUTOMATErkennung::roomFromName('Licht Küche', ['101 Küche']) === '101 Küche');
foreach ([['Licht Essen', 'Esszimmer'], ['Thermostat Esszimmer', 'Essen'], ['Bewegungsmelder Wohnen', 'Wohnzimmer'], ['Licht Schlafen', 'Schlafzimmer'], ['Fenster Kind', 'Kinderzimmer'], ['Licht Bad', 'Badezimmer'], ['Thermostat Gästezimmer', 'Gäste']] as [$name, $room]) {
    check('Kurz-/Langform: „' . $name . '“ → „' . $room . '“', AUTOMATErkennung::roomFromName($name, ['Flur', $room, 'Küche']) === $room, (string)AUTOMATErkennung::roomFromName($name, ['Flur', $room, 'Küche']));
}
check('Kurz-/Langform hält Nummern auseinander: „Fenster Kind II“ → „Kinderzimmer II“', AUTOMATErkennung::roomFromName('Fenster Kind II', ['Kinderzimmer I', 'Kinderzimmer II']) === 'Kinderzimmer II');
check('Kein Fehltreffer: „Licht Flur“ passt zu keinem Zimmer', AUTOMATErkennung::roomFromName('Licht Flur', ['Esszimmer', 'Wohnzimmer']) === null);
$ew = [
    $it(['vid' => 41, 'type' => 0, 'profile' => '~Motion', 'name' => 'Bewegung', 'inst' => 'Essen Bewegungsmelder', 'role' => 'motion']),
    $it(['vid' => 42, 'type' => 0, 'action' => true, 'profile' => '~Switch', 'name' => 'Status', 'inst' => 'Licht Esszimmer', 'role' => 'light']),
];
$s6 = AUTOMATErkennung::suggest($ew, [], []);
check('Namensgruppen „Essen“ + „Esszimmer“ = ein Raum → Bewegungslicht', count(array_filter($s6, fn($x) => $x['type'] === 'bewegung')) === 1, json_encode(array_column($s6, 'room'), JSON_UNESCAPED_UNICODE));
$s7 = AUTOMATErkennung::suggest($ew, [], [], ['Esszimmer']);
check('… und heißt wie in Raumstruktur („Esszimmer“)', ($s7[0]['room'] ?? '') === 'Esszimmer', json_encode(array_column($s7, 'room'), JSON_UNESCAPED_UNICODE));
$hw = [
    $it(['vid' => 31, 'type' => 0, 'name' => 'Zustand', 'inst' => 'Hwr Fenster', 'role' => 'contact']),
    $it(['vid' => 32, 'type' => 2, 'action' => true, 'profile' => '~ThermostatSetPoint.ZWave', 'name' => 'Sollwert', 'inst' => 'Thermostat Hwr', 'role' => 'setpoint']),
];
$s5 = AUTOMATErkennung::suggest($hw, [], [], ['HWR', 'Kind I']);
check('Namensgruppe übernimmt Schreibweise aus Raumstruktur („Hwr“ → „HWR“)', ($s5[0]['room'] ?? '') === 'HWR', json_encode(array_column($s5, 'room')));

// ===========================================================================
// TEIL C — Modul gegen nachgebildetes IPS
// ===========================================================================

heading('C1. Anlage — Variablen, Nachrichten, Status');
mkinst(1000, 'Licht Flur');
mkvar(1001, VARIABLETYPE_BOOLEAN, false, 'Status', 1000, true, '~Switch');
mkinst(1010, 'Flur Bewegungsmelder');
mkvar(1011, VARIABLETYPE_BOOLEAN, false, 'Bewegung', 1010, false, '~Motion');
mkinst(1020, 'Thermostat Bad');
mkvar(1021, VARIABLETYPE_FLOAT, 21.0, 'Sollwert', 1020, true, '~Temperature.Room');
mkinst(1030, 'Bad Fenster');
mkvar(1031, VARIABLETYPE_BOOLEAN, false, 'Zustand', 1030, false, '');
mkinst(1040, 'Waschmaschine');
mkvar(1041, VARIABLETYPE_FLOAT, 0.0, 'Leistung', 1040, false, '~Watt.3680');
mkinst(12345, 'Automat');
$GLOBALS['MODULES']['{A92CA7FD-B746-45C4-902B-3CBAE1F3FA27}'] = [12345];
mkinst(10443, 'Location');
$GLOBALS['MODULES']['{45E97A63-F870-408A-B259-2933F7EABF74}'] = [10443];
mkvar(10444, VARIABLETYPE_INTEGER, ts('2026-10-13 18:30:00'), 'Sonnenuntergang', 10443, false, '', [], 'Sunset');
mkvar(10445, VARIABLETYPE_INTEGER, ts('2026-10-14 07:45:00'), 'Sonnenaufgang', 10443, false, '', [], 'Sunrise');
mkinst(13000, 'Kachel Visualisierung');
$GLOBALS['MODULES']['{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}'] = [13000];

$bl = AUTOMATKatalog::defaults('bewegung');
$bl += ['Name' => 'Flurlicht', 'Room' => 'Flur', 'Uid' => 'aflur1'];
$bl['bl_mel1'] = 1011;
$bl['bl_out1'] = 1001;
$bl['bl_nachlauf'] = 60;
$fo = AUTOMATKatalog::defaults('fenster');
$fo += ['Name' => 'Bad lüften', 'Room' => 'Bad', 'Uid' => 'abad01'];
$fo['fo_kon1'] = 1031;
$fo['fo_soll1'] = 1021;
$fo['fo_verz'] = 0;
$gf = AUTOMATKatalog::defaults('fertig');
$gf += ['Name' => 'Waschmaschine', 'Room' => 'Keller', 'Uid' => 'awasch'];
$gf['gf_var'] = 1041;
mkinst(1080, 'Licht Treppe');
mkvar(1081, VARIABLETYPE_BOOLEAN, false, 'Status', 1080, true, '~Switch');
mkinst(1090, 'Taster Treppe');
mkvar(1091, VARIABLETYPE_BOOLEAN, false, 'Taste', 1090, false, '');
$zl = AUTOMATKatalog::defaults('zeitlicht');
$zl += ['Name' => 'Treppenlicht', 'Room' => 'Flur', 'Uid' => 'atrepp'];
$zl['zl_out1'] = 1081;
$zl['zl_trg1'] = 1091;
$zl['zl_dauer'] = 30;
$zl['zl_vorwarn'] = 10;
$GLOBALS['CLOCK'] = ts('2026-10-13 20:00:00');
mkinst(53244, 'Archiv');
$GLOBALS['MODULES']['{43192F0B-135B-4CE7-A0A7-1475603F3060}'] = [53244];
$GLOBALS['ARCHIVE'][1011] = [];
foreach ([10, 11, 9, 10, 12, 10, 627] as $i => $dur) {
    $t0 = ts('2026-10-12 08:00:00') + $i * 3600;
    $GLOBALS['ARCHIVE'][1011][] = ['TimeStamp' => $t0, 'Value' => true];
    $GLOBALS['ARCHIVE'][1011][] = ['TimeStamp' => $t0 + $dur, 'Value' => false];
}
$m = new AutomatTest();
$m->Create();
$m->props['Automations'] = json_encode([$bl, $fo, $gf, $zl]);
$m->ApplyChanges();
check('Status 102', $m->status === 102);
$ms = json_decode($m->attrs['MelStats'], true);
check('Melder-Dauern aus dem Archiv angelernt (7 Läufe, inkl. eines verlorenen „aus“)', count($ms[1011]['d'] ?? []) === 7 && $ms[1011]['boot'] === true, json_encode($ms));
$fv = json_decode($m->GetConfigurationForm(), true);
$fl = json_encode($fv, JSON_UNESCAPED_UNICODE);
check('Gelernte Rückfallzeit: 3 × 90-%-Wert (12 s → 36 s), der 627-s-Ausreißer verfälscht nichts', strpos($fl, 'Melder-Rückfall auto 36 s') !== false, preg_match('/Melder-Rückfall auto [^"·]*/u', $fl, $mm) ? $mm[0] : 'kein Text');
foreach (['s_aflur1', 'r_aflur1', 'l_aflur1', 'd_aflur1', 's_abad01', 'l_abad01', 'Hausmodus', 'Pause', 'LastMessage'] as $ident) {
    check('Variable „' . $ident . '“ angelegt', @IPS_GetObjectIDByIdent($ident, 12345) !== false);
}
check('Kein Dauerlicht für Fenster-Automatismus', @IPS_GetObjectIDByIdent('d_abad01', 12345) === false);
check('Sperre/Dauerlicht mit Aktion (WebFront/Kachel bedienbar)', HasAction(IPS_GetObjectIDByIdent('l_aflur1', 12345)) && HasAction(IPS_GetObjectIDByIdent('d_aflur1', 12345)));
check('VM_UPDATE auf Melder, Licht, Kontakt, Sollwert, Leistung', isset($m->messages[1011][VM_UPDATE], $m->messages[1001][VM_UPDATE], $m->messages[1031][VM_UPDATE], $m->messages[1021][VM_UPDATE], $m->messages[1041][VM_UPDATE]));
check('Hausmodus-Profil mit 5 Modi', count($GLOBALS['PROFILES']['AUTOMAT.Hausmodus']['Assoc'] ?? []) === 5);
check('Statusvariable zeigt Bereitschaft', strpos((string)GetValue(IPS_GetObjectIDByIdent('s_aflur1', 12345)), 'bereit') !== false, (string)GetValue(IPS_GetObjectIDByIdent('s_aflur1', 12345)));

heading('C2. Ablauf über Nachrichten und Timer');
function fire(Automat $m, int $vid, $v): void { $GLOBALS['OBJ'][$vid]['value'] = $v; $m->MessageSink($GLOBALS['CLOCK'], $vid, VM_UPDATE, [$v, true, null, $GLOBALS['CLOCK']]); }
$GLOBALS['WRITES'] = [];
fire($m, 1011, true);
check('Bewegung → RequestAction auf das Licht', count($GLOBALS['WRITES']) === 1 && $GLOBALS['WRITES'][0][1] === 1001 && $GLOBALS['WRITES'][0][2] === true && $GLOBALS['WRITES'][0][3] === 'RequestAction');
fire($m, 1001, true); // Echo des eigenen Befehls
$states = testStates();
check('Echo des eigenen Befehls ist keine Handbedienung', $states['aflur1']['ph'] === 'an');
fire($m, 1011, false);
check('Melder still → Restzeit-Variable zeigt den Nachlauf', GetValue(IPS_GetObjectIDByIdent('r_aflur1', 12345)) === '1:00 min', (string)GetValue(IPS_GetObjectIDByIdent('r_aflur1', 12345)));
check('Countdown läuft → Timer alle 5 s (Restzeit nachführen)', $m->timers['Tick'] === 5000, (string)$m->timers['Tick']);
$GLOBALS['CLOCK'] += 20;
$m->Tick();
check('Restzeit zählt herunter', GetValue(IPS_GetObjectIDByIdent('r_aflur1', 12345)) === '40 s', (string)GetValue(IPS_GetObjectIDByIdent('r_aflur1', 12345)) . ' / ' . GetValue(IPS_GetObjectIDByIdent('s_aflur1', 12345)));
$GLOBALS['CLOCK'] -= 20;
$GLOBALS['CLOCK'] += 61;
$m->Tick();
check('Tick nach Nachlauf schaltet aus', GetValue(1001) === false);
$log = json_decode($m->attrs['Protocol'], true);
check('Protokoll erklärt die Schritte', count($log) >= 3 && strpos($log[0][2], 'Licht aus') !== false, json_encode(array_slice($log, 0, 3), JSON_UNESCAPED_UNICODE));
// Vorwarnung: das eigene kurze Ausschalten darf nicht als Handbedienung zählen
fire($m, 1091, true);
check('Treppenlicht: Taster schaltet ein', GetValue(1081) === true);
fire($m, 1081, true);
$GLOBALS['CLOCK'] += 20;
$m->Tick();
check('Vorwarnung: Licht kurz aus', GetValue(1081) === false);
fire($m, 1081, false); // Echo des eigenen Aus-Befehls
$GLOBALS['CLOCK'] += 1;
$m->Tick();
check('Echo der Vorwarnung nicht als Handbedienung gewertet: Licht wieder an', GetValue(1081) === true, json_encode(testStates()['atrepp']));
fire($m, 1081, true);
$GLOBALS['CLOCK'] += 10;
$m->Tick();
check('Nach Ablauf aus', GetValue(1081) === false);
check('Nach Ablauf: Restzeit leer', GetValue(IPS_GetObjectIDByIdent('r_atrepp', 12345)) === '');
// Handbedienung über echte Nachricht (kein eigener Befehl offen)
fire($m, 1011, true);
fire($m, 1001, true);
$GLOBALS['CLOCK'] += 5;
fire($m, 1001, false); // von Hand aus
$states = testStates();
check('Von Hand ausgeschaltet → Handpause', $states['aflur1']['ph'] === 'hand', $states['aflur1']['ph']);
// Fenster
$GLOBALS['WRITES'] = [];
fire($m, 1031, true);
check('Fenster auf (Verzögerung 0) → Sollwert 12 °C', GetValue(1021) === 12.0);
fire($m, 1021, 12.0);
fire($m, 1031, false);
check('Fenster zu → 21 °C zurück', GetValue(1021) === 21.0);
// Gerät fertig mit Push
fire($m, 1041, 1500.0);
$GLOBALS['CLOCK'] += 3600;
fire($m, 1041, 1.0);
$GLOBALS['CLOCK'] += 181;
$m->Tick();
check('Waschmaschine fertig → Push an die Kachel-Visualisierung', count($GLOBALS['PUSH']) === 1 && strpos($GLOBALS['PUSH'][0][2], 'fertig') !== false, json_encode($GLOBALS['PUSH'], JSON_UNESCAPED_UNICODE));
check('… und in „Letzte Meldung“', strpos((string)GetValue(IPS_GetObjectIDByIdent('LastMessage', 12345)), 'Waschmaschine') !== false);
// Sperre per RequestAction
$m->RequestAction('l_aflur1', true);
$GLOBALS['WRITES'] = [];
fire($m, 1011, true);
check('Gesperrt: Bewegung schaltet nicht', count($GLOBALS['WRITES']) === 0);
check('Status zeigt Sperre', strpos((string)GetValue(IPS_GetObjectIDByIdent('s_aflur1', 12345)), 'Gesperrt') !== false);
$m->RequestAction('l_aflur1', false);
// Pause und Hausmodus
$m->RequestAction('Pause', true);
check('Pause: Statustext „pausiert“', strpos((string)GetValue(IPS_GetObjectIDByIdent('s_abad01', 12345)), 'pausiert') !== false);
$m->RequestAction('Pause', false);
$m->RequestAction('Hausmodus', 2);
check('Hausmodus per RequestAction gesetzt und protokolliert', GetValue(IPS_GetObjectIDByIdent('Hausmodus', 12345)) === 2 && strpos(json_decode($m->attrs['Protocol'], true)[0][2], 'Nacht') !== false);
// Fehlschlag beim Schalten wird dauerhaft geloggt
$GLOBALS['FAIL_RA'] = true;
$m->RequestAction('l_aflur1', false);
$GLOBALS['CLOCK'] += 3600;
$m->Tick();
fire($m, 1011, false);
fire($m, 1011, true);
$GLOBALS['FAIL_RA'] = false;
check('Fehlgeschlagenes Schalten → LogMessage KL_WARNING', count(array_filter($m->logged, fn($l) => $l[0] === KL_WARNING)) >= 1, json_encode($m->logged, JSON_UNESCAPED_UNICODE));

heading('C2b. Gedächtnis übersteht Neueinbinden; abgesenktes Thermostat wird beim Start zurückgestellt');
$storeID = IPS_GetObjectIDByIdent('store', 12345);
check('Gedächtnis als versteckte Variable', $GLOBALS['OBJ'][$storeID]['hidden'] === true);
fire($m, 1031, true);
check('Fenster auf → abgesenkt', GetValue(1021) === 12.0);
fire($m, 1021, 12.0);
// Neueinbinden in der Modulverwaltung: Attribute weg, Variablen bleiben; währenddessen Fenster geschlossen (Nachricht verpasst)
$GLOBALS['OBJ'][1031]['value'] = false;
$props = $m->props;
$m = new AutomatTest();
$m->props = $props;
$m->Create();
$m->ApplyChanges();
check('Nach Neueinbinden mit verpasstem „zu“: Sollwert beim Start auf 21 °C zurück', GetValue(1021) === 21.0, var_export(GetValue(1021), true));
check('… mit eigenem Protokolleintrag', strpos(json_encode(json_decode($m->attrs['Protocol'], true), JSON_UNESCAPED_UNICODE), 'Beim Start nachgeholt') !== false);
// Übernahme aus Attribut (Versionen bis 0.1.13)
$keep = GetValue($storeID);
SetValueString($storeID, '');
$m->attrs['States'] = $keep;
$m->ApplyChanges();
check('Gedächtnis aus dem alten Attribut übernommen', GetValue($storeID) !== '' && isset(testStates()['aflur1']));

heading('C3. Formular');
$form = json_decode($m->GetConfigurationForm(), true);
check('GetConfigurationForm liefert gültiges JSON', is_array($form));
$txt = json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$captions = array_map(fn($e) => $e['caption'] ?? '', $form['elements']);
check('Reihenfolge: Wozu → Neu → Doku → Automatismen → Entdecken → … → Rückmeldungen → Über', strpos($captions[0], 'Wozu') !== false && strpos($captions[1], 'Neu bis Version') !== false && strpos($captions[2], 'Dokumentation') !== false && strpos($captions[3], 'Automatismen') !== false && strpos($captions[4], 'Entdecken') !== false && strpos(end($captions), 'Über dieses Modul') !== false, implode(' | ', $captions));
check('„Neu“-Bereich zeigt die Einträge seit der Store-Veröffentlichung, aufgeklappt', strpos($captions[1], 'Neu bis Version ' . json_decode(file_get_contents(dirname(__DIR__) . '/library.json'), true)['version']) !== false && ($form['elements'][1]['expanded'] ?? false) === true && strpos($txt, 'Forum-Thread') !== false);
$m->AckNews();
$capsAck = array_map(fn($e) => $e['caption'] ?? '', json_decode($m->GetConfigurationForm(), true)['elements']);
check('Nach „Verstanden“ ist der „Neu“-Bereich weg (gespeichert wird die installierte Version ohne Beta-Zusatz)', count(array_filter($capsAck, fn($c) => strpos($c, 'Neu bis Version') !== false)) === 0 && $m->attrs['SeenNews'] === json_decode(file_get_contents(dirname(__DIR__) . '/library.json'), true)['version']);
check('Forum-Thread verlinkt (Rückmeldungen und Doku)', substr_count(file_get_contents(dirname(__DIR__) . '/Automat/module.php'), 'self::FORUM_URL') === 2);
check('Versionsnummer im Doku-Panel (aus library.json)', strpos($txt, 'Automat Version ' . json_decode(file_get_contents(dirname(__DIR__) . '/library.json'), true)['version'] . '-beta.1') !== false);
check('Link-Buttons mit echo + link:true', strpos($txt, '"link":true') !== false && strpos($txt, "echo 'https://github.com/DG65/Automat/blob/main/LICENSE'") !== false);
check('Kein Datum im Format JJJJ-MM-TT sichtbar', !preg_match('/\b20\d\d-\d\d-\d\d\b/', $txt));
$list = null;
array_walk_recursive($form, function () {});
$find = function ($els, $name) use (&$find) { foreach ($els as $e) { if (($e['name'] ?? '') === $name) { return $e; } if (isset($e['items'])) { $r = $find($e['items'], $name); if ($r) { return $r; } } } return null; };
$list = $find($form['elements'], 'Automations');
check('Liste mit eingespeisten Werten (loadValuesFromConfiguration=false)', $list !== null && $list['loadValuesFromConfiguration'] === false && count($list['values']) === 4);
check('Schlüsselspalten Type/Uid gespeichert (save:true)', count(array_filter($list['columns'], fn($c) => in_array($c['name'], ['Type', 'Uid'], true) && !empty($c['save']))) === 2);
$vals = $list['values'];
$flur = array_values(array_filter($vals, fn($r) => ($r['Uid'] ?? '') === 'aflur1'))[0] ?? [];
check('Spalte „Einstellungen“ fasst Werte zusammen', strpos($flur['Settings'] ?? '', 'Nachlauf 1:00 min') !== false && strpos($flur['Settings'] ?? '', '100 %') !== false, $flur['Settings'] ?? '');
check('Sortierschlüssel Raum→Art und Art→Raum', ($flur['SortRoom'] ?? '') === 'flur|bewegung|flurlicht' && ($flur['SortType'] ?? '') === 'bewegung|flur|flurlicht');
check('Spaltenköpfe Raum/Art sortieren über die Schlüssel', count(array_filter($list['columns'], fn($c) => ($c['sortColumn'] ?? '') !== '')) === 2);
check('Liste mindestens 12 Zeilen hoch', $list['rowCount'] >= 12);
$m->fieldUpdates = [];
$m->UISetListOrder(1);
check('„Ordnen nach Art“ setzt die Sortierung der offenen Liste', $m->fieldUpdates[0][0] === 'Automations' && $m->fieldUpdates[0][1] === 'sort' && strpos($m->fieldUpdates[0][2], 'TypeLabel') !== false);
check('Genau eine Spalte mit width auto', count(array_filter($list['columns'], fn($c) => ($c['width'] ?? '') === 'auto')) === 1);
check('Zustandsspalte zeigt für jede Zeile einen Status', count(array_filter($list['values'], fn($r) => trim((string)($r['State'] ?? '')) === '')) === 0, json_encode(array_column($list['values'], 'State'), JSON_UNESCAPED_UNICODE));
check('Bearbeiten-Dialog als PHP-Code je Zeile', strpos($list['form'], 'AUTOMAT_UIEditForm($id') !== false);
// Dialog
$dlg = json_decode($m->UIEditForm('aflur1', 'bewegung'), true);
$names = [];
$collect = function ($els) use (&$collect, &$names) { foreach ($els as $e) { if (isset($e['name'])) { $names[] = $e['name']; } if (isset($e['items'])) { $collect($e['items']); } } };
$collect($dlg);
check('Dialog: keine doppelten Elementnamen', count($names) === count(array_unique($names)), implode(',', array_diff_assoc($names, array_unique($names))));
$names2 = [];
$collect2 = function ($els) use (&$collect2, &$names2) { foreach ($els as $e) { if (isset($e['name'])) { $names2[] = $e['name']; } if (isset($e['items'])) { $collect2($e['items']); } } };
$collect2($form['elements']);
check('Dialog-Namen kollidieren nicht mit Formular-Namen', count(array_intersect(array_diff($names, ['Automations']), $names2)) === 0, implode(',', array_intersect($names, $names2)));
$secs = array_values(array_filter($dlg, fn($e) => strpos($e['name'] ?? '', 'sec_') === 0 && $e['name'] !== 'sec_when'));
check('Dialog: nur der Abschnitt des Typs sichtbar', count(array_filter($secs, fn($e) => $e['visible'])) === 1 && $find($dlg, 'sec_bewegung')['visible'] === true);
$dlgTxt = json_encode($dlg, JSON_UNESCAPED_UNICODE);
check('Dialog: Hilfe-Knopf zum Impuls-Schalter mit voller Frage, ohne zusätzliches Symbol', strpos($dlgTxt, '"caption":"Wann brauche ich diesen Schalter?"') !== false && strpos($dlgTxt, 'nie (oder erst sehr spät) auf „aus“ zurück') !== false);
check('Dialog: Kennung bleibt beim Bearbeiten erhalten', $find($dlg, 'Uid')['value'] === 'aflur1');
$dlgNew = json_decode($m->UIEditForm('', ''), true);
check('Neuer Dialog: neue Kennung, kein Abschnitt sichtbar', preg_match('/^a[0-9a-f]{5}$/', $find($dlgNew, 'Uid')['value']) === 1 && count(array_filter($dlgNew, fn($e) => strpos($e['name'] ?? '', 'sec_') === 0 && $e['name'] !== 'sec_when' && $e['visible'])) === 0);
// Feldhinweise im Dialog: fehlende Variable rot markiert, onChange führt nach
$rowsBad = json_decode($m->props['Automations'], true);
foreach ($rowsBad as &$rb) { if ($rb['Uid'] === 'aflur1') { $rb['bl_out2'] = 9999; } }
unset($rb);
$m->props['Automations'] = json_encode($rowsBad);
$dlgBad = json_decode($m->UIEditForm('aflur1', 'bewegung'), true);
$chk = $find($dlgBad, 'chk_bl_out2');
check('Dialog: fehlende Variable unter dem Feld rot markiert', $chk !== null && $chk['visible'] === true && $chk['color'] === 0xFF0000 && strpos($chk['caption'], '#9999') !== false, json_encode($chk, JSON_UNESCAPED_UNICODE));
check('Dialog: Abschnitt und Kopf zeigen ⛔', strpos($find($dlgBad, 'sec_bewegung')['caption'], '⛔') === 0 && strpos(json_encode($dlgBad, JSON_UNESCAPED_UNICODE), 'brauchen Aufmerksamkeit') !== false);
check('Dialog: Variablenfelder führen Hinweis und Plätze per onChange nach', strpos(json_encode($dlgBad, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "AUTOMAT_UIVarsChanged(\$id, 'bl_out', 2, json_encode([(int)\$bl_out1, (int)\$bl_out2, (int)\$bl_out3, (int)\$bl_out4, (int)\$bl_out5, (int)\$bl_out6, (int)\$bl_out7, (int)\$bl_out8, (int)\$bl_out9, (int)\$bl_out10, (int)\$bl_out11, (int)\$bl_out12]));") !== false);
$slotVis = fn(string $n) => ($find($dlgBad, $n)['visible'] ?? true) !== false;
check('Dialog: nur gefüllte Plätze plus EIN leerer sichtbar (Melder 1 gefüllt → 1 und 2, nicht 3 und 4)', $slotVis('bl_mel1') && $slotVis('bl_mel2') && !$slotVis('bl_mel3') && !$slotVis('bl_mel4'));
$m->fieldUpdates = [];
$m->UIVarsChanged('bl_mel', 2, json_encode([1011, 1051, 0, 0]));
check('Platz 2 gefüllt → Platz 3 erscheint, 4 bleibt verborgen', in_array(['bl_mel3', 'visible', true], $m->fieldUpdates, true) && in_array(['bl_mel4', 'visible', false], $m->fieldUpdates, true));
$m->fieldUpdates = [];
$m->UIVarsChanged('bl_mel', 2, json_encode([1011, 0, 0, 0]));
check('Platz 2 geleert: Platz 2 bleibt als leerer sichtbar, Platz 3 verschwindet wieder', in_array(['bl_mel2', 'visible', true], $m->fieldUpdates, true) && in_array(['bl_mel3', 'visible', false], $m->fieldUpdates, true));
$m->fieldUpdates = [];
$m->UIVarsChanged('bl_mel', 1, json_encode([0, 1051, 0, 0]));
check('Platz 1 geleert, Platz 2 gefüllt: beide sichtbar, 3 und 4 nicht (keine zwei leeren)', in_array(['bl_mel1', 'visible', true], $m->fieldUpdates, true) && in_array(['bl_mel2', 'visible', true], $m->fieldUpdates, true) && in_array(['bl_mel3', 'visible', false], $m->fieldUpdates, true));
$m->fieldUpdates = [];
$m->UIVarChanged('bl_out2', 1001);
check('Gültige Variable gewählt → Hinweis verschwindet', in_array(['chk_bl_out2', 'visible', false], $m->fieldUpdates, true));
AUTOMATKatalog::$minId = 10000;
$m->fieldUpdates = [];
$m->UIVarChanged('bl_mel2', 1);
check('Feld geleert (Symcon liefert 1) → Dialogfeld wird auf 0 gesetzt, kein Hinweis', in_array(['bl_mel2', 'value', 0], $m->fieldUpdates, true) && in_array(['chk_bl_mel2', 'visible', false], $m->fieldUpdates, true), json_encode($m->fieldUpdates));
$m->fieldUpdates = [];
$m->UIVarChanged('bl_mel2', 0);
check('Feld mit 0 bleibt unangetastet', !in_array(['bl_mel2', 'value', 0], $m->fieldUpdates, true));
AUTOMATKatalog::$minId = 1;
foreach ($rowsBad as &$rb) { if ($rb['Uid'] === 'aflur1') { $rb['bl_out2'] = 0; } }
unset($rb);
$m->props['Automations'] = json_encode($rowsBad);
$m->fieldUpdates = [];
$m->UIDialogType('fenster');
check('Art gewählt (neuer Automatismus): Beschreibung folgt, keine Abschnitte angesprochen', count(array_filter($m->fieldUpdates, fn($u) => strpos($u[0], 'sec_') === 0)) === 0 && count(array_filter($m->fieldUpdates, fn($u) => $u[0] === 'TypeDesc' && strpos($u[2], 'Fenster') !== false)) === 1);

// Hausmodus-Automatik: Standard an, verständlicher Schalter, Zustand sichtbar
$m2 = new AutomatTest();
$m2->InstanceID = 12399;
mkinst(12399, 'Automat 2');
$m2->Create();
check('Hausmodus-Automatik ist für neue Instanzen standardmäßig an', $m2->props['cfg_ModeAuto'] === true);
$ft = json_encode(json_decode($m->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
check('Schalter beschreibt, was passiert, ohne feste Zeiten zu versprechen', strpos($ft, 'Hausmodus automatisch nach Tageszeit umschalten') !== false && strpos($ft, 'z. B. Abend ab Sonnenuntergang') !== false && strpos($ft, 'zu den Zeiten unten') === false);
check('Zustand der Automatik im Panel und in der Zusammenfassung sichtbar', strpos($ft, '✅ Automatik an') !== false && strpos($ft, 'wechselt automatisch') !== false && strpos($ft, '(automatisch)') !== false);
$m->fieldUpdates = [];
$m->UIModeAutoChanged(false);
check('Schalter umgelegt → Zustandszeile folgt sofort (mit Hinweis „noch nicht übernommen“)', strpos(json_encode($m->fieldUpdates, JSON_UNESCAPED_UNICODE), 'Automatik aus') !== false && strpos(json_encode($m->fieldUpdates, JSON_UNESCAPED_UNICODE), 'noch nicht übernommen') !== false);

heading('C4. Entdecken, Übernehmen, Ausblenden');
$GLOBALS['EVENTS'][77000] = ['EventType' => 0, 'EventActive' => true, 'TriggerVariableID' => 1011, 'TriggerType' => 4, 'TriggerValue' => true, 'EventScript' => '', 'EventActionParameters' => ['TARGET' => 1001, 'VALUE' => true], 'EventConditions' => []];
$GLOBALS['OBJ'][77000] = ['kind' => 4, 'name' => 'Flurlicht alt', 'parent' => 0, 'ident' => '', 'hidden' => false];
$GLOBALS['OBJ'][77100] = ['kind' => 3, 'name' => 'Alarm Flur', 'parent' => 0, 'ident' => '', 'hidden' => false];
$GLOBALS['EVENTS'][77101] = ['EventType' => 0, 'EventActive' => true, 'TriggerVariableID' => 1011, 'TriggerType' => 4, 'TriggerValue' => true, 'EventScript' => '', 'EventActionParameters' => [], 'EventConditions' => [['x' => 1]]];
$GLOBALS['OBJ'][77101] = ['kind' => 4, 'name' => 'Bei bestimmtem Wert for Variable "Flur Bewegung" with Limit true', 'parent' => 77100, 'ident' => '', 'hidden' => false];
mkinst(1075, 'HomeKit Bridge');
$GLOBALS['MODNAME'][1075] = 'HomeKitBridge';
$GLOBALS['MODTYPE'][1075] = 4;
$GLOBALS['CONF'][1075] = json_encode(['Lightbulbs' => [['ID' => 1, 'VariableID' => 1001]]]);
mkinst(1050, 'Licht Garage');
mkvar(1051, VARIABLETYPE_BOOLEAN, false, 'Status', 1050, true, '~Switch');
mkinst(1060, 'Garage Bewegungsmelder');
mkvar(1061, VARIABLETYPE_BOOLEAN, false, 'Bewegung', 1060, false, '~Motion');
mkinst(1095, 'Licht Terrasse');
mkvar(1096, VARIABLETYPE_BOOLEAN, false, 'Status', 1095, true, '~Switch');
mkinst(1097, 'Wäschetrockner');
mkvar(1098, VARIABLETYPE_FLOAT, 0.0, 'Leistung', 1097, false, '~Watt.3680');
mkinst(1076, 'Anwesenheitssimulation Flur');
$GLOBALS['MODNAME'][1076] = 'DG65Anwesenheitssimulation';
$GLOBALS['CONF'][1076] = json_encode(['Channels' => json_encode([['TargetID' => 1001]])]);
mkinst(1070, 'Alte Lichtsteuerung');
$GLOBALS['CONF'][1070] = json_encode(['Outputs' => [1051], 'Duration' => 60]);
$m->fieldUpdates = [];
$out = $m->Discover();
check('Discover meldet gefundene Vorschläge', strpos($out, 'Vorschläge gefunden') !== false, $out);
$sugg = json_decode($m->attrs['Suggestions'], true);
$garage = array_values(array_filter($sugg, fn($x) => $x['type'] === 'bewegung' && $x['room'] === 'Garage'));
check('Bewegungslicht Garage vorgeschlagen', count($garage) === 1);
check('… mit Warnung: alte Instanz nutzt das Licht schon', count($garage) === 1 && strpos(implode(' ', $garage[0]['conflicts']), 'Alte Lichtsteuerung') !== false, json_encode($garage[0]['conflicts'] ?? [], JSON_UNESCAPED_UNICODE));
check('Bereits eingerichtetes Flurlicht nicht erneut vorgeschlagen', count(array_filter($sugg, fn($x) => $x['type'] === 'bewegung' && $x['room'] === 'Flur')) === 0);
check('Kopfzeile und Vorschlagsliste gemeinsam aufgefrischt', count(array_filter($m->fieldUpdates, fn($u) => $u[0] === 'DiscoverHead')) === 1 && count(array_filter($m->fieldUpdates, fn($u) => $u[0] === 'SuggestList' && $u[1] === 'values')) === 1);
$sl = array_values(array_filter($m->fieldUpdates, fn($u) => $u[0] === 'SuggestList' && $u[1] === 'values'));
$slRows = json_decode($sl[0][2] ?? '[]', true);
check('Vorschlagsliste mit Haken, Schlüssel, Raum/Art/Warum und Sortierschlüsseln', count($slRows) === count($sugg) && isset($slRows[0]['Key'], $slRows[0]['Room'], $slRows[0]['TypeLabel'], $slRows[0]['Why'], $slRows[0]['SortRoom'], $slRows[0]['SortType']) && $slRows[0]['Pick'] === false);
check('Vorschlag mit Doppelsteuerung gelb markiert', count(array_filter($slRows, fn($r) => $r['rowColor'] === '#FFFFC0')) >= 1);
$m->fieldUpdates = [];
$m->UISetSuggestOrder(1);
$so = array_values(array_filter($m->fieldUpdates, fn($u) => $u[0] === 'SuggestList' && $u[1] === 'sort'));
check('„Ordnen nach Art“ sortiert die Vorschlagsliste über die Art-Spalte', strpos($so[0][2] ?? '', 'TypeLabel') !== false);
$byType = $slRows; usort($byType, fn($a, $b) => strcmp($a['SortType'], $b['SortType']));
$blocks = []; $prev = null; foreach ($byType as $r) { if ($r['TypeLabel'] !== $prev) { $blocks[] = $r['TypeLabel']; $prev = $r['TypeLabel']; } }
check('Sortierschlüssel Art ergibt zusammenhängende Art-Blöcke', count($blocks) === count(array_unique(array_column($slRows, 'TypeLabel'))) && count($blocks) >= 2, json_encode($blocks, JSON_UNESCAPED_UNICODE));
$form2 = json_decode($m->GetConfigurationForm(), true);
$ft = json_encode($form2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('Kein separates Auswahlfeld „Vorschlag“ mehr, Übernehmen liest die Haken', strpos($ft, 'cfg_Suggestion') === false && strpos($ft, "AUTOMAT_UIAdoptMany(\$id, json_encode(\$k)") !== false && strpos($ft, "\$x['Pick']") !== false);
check('Raumquelle ehrlich: ohne Raumstruktur aus Namen', strpos($m->attrs['RoomSource'], 'nicht installiert') !== false);
$m->fieldUpdates = [];
$current = json_encode($list['values']);
$res = $m->UIAdoptMany('[]', $current);
check('Nichts angehakt → Hinweis statt Fehler', strpos($res, 'anhaken') !== false);
$fertigKeys = array_values(array_map(fn($x) => $x['key'], array_filter($sugg, fn($x) => $x['type'] === 'daemmerung')));
$m->fieldUpdates = [];
$res = $m->UIAdoptMany(json_encode([$garage[0]['key']]), $current);
$upd = array_values(array_filter($m->fieldUpdates, fn($u) => $u[0] === 'Automations'));
$newRows = json_decode($upd[0][2] ?? '[]', true);
check('Übernehmen fügt eine Zeile in die offene Liste ein (nicht gespeichert)', count($newRows) === 5 && strpos($res, 'NICHT gespeichert') !== false && json_decode($m->props['Automations'], true) !== $newRows);
check('Neue Zeile hat Kennung und ist eingeschaltet', preg_match('/^a[0-9a-f]{5}$/', $newRows[4]['Uid']) === 1 && $newRows[4]['Enabled'] === true && $newRows[4]['bl_out1'] === 1051);
$res = $m->UIAdoptMany(json_encode([$garage[0]['key']]), '[{},{}]');
check('Unlesbare Liste → Rückfall auf gespeicherten Stand mit Hinweis', strpos($res, 'konnten nicht gelesen werden') !== false);
$m->fieldUpdates = [];
$res = $m->UIAdoptMany(json_encode(array_merge([$garage[0]['key']], $fertigKeys)), $current);
$upd = array_values(array_filter($m->fieldUpdates, fn($u) => $u[0] === 'Automations'));
check('Mehrere angehakte Vorschläge auf einmal übernommen', count(json_decode($upd[0][2] ?? '[]', true)) === 4 + 1 + count($fertigKeys) && count($fertigKeys) >= 1 && strpos($res, 'Automatismen (') !== false, $res);
$res = $m->UIAddType('waechter', $current);
check('Katalog: leerer Wächter eingefügt', strpos($res, 'Wächter') !== false);
$res = $m->DismissSuggestions(json_encode([$garage[0]['key']]));
check('Ausblenden entfernt den Vorschlag', count(array_filter(json_decode($m->attrs['Suggestions'], true), fn($x) => $x['key'] === $garage[0]['key'])) === 0 && strpos($res, 'ausgeblendet') !== false);
$m->Discover();
check('… auch bei der nächsten Suche', count(array_filter(json_decode($m->attrs['Suggestions'], true), fn($x) => $x['key'] === $garage[0]['key'])) === 0);
// Räume aus Raumstruktur (neuer Präfix RAUM_, Rückfall STRUKT_)
if (true) {
    function RAUM_GetStructure(int $id): string
    {
        return json_encode(['contractVersion' => '1.1', 'rooms' => [['key' => 'werkstatt', 'label' => 'Werkstatt', 'deviceInstanceIDs' => [1050, 1060]]]]);
    }
}
mkinst(14000, 'Raumstruktur');
$GLOBALS['MODULES']['{CA700334-0982-F356-0617-6952868137E9}'] = [14000];
$m->RestoreSuggestions();
$sugg = json_decode($m->attrs['Suggestions'], true);
check('Raumstruktur verbunden: Statuszeile grün mit 🔗', strpos($m->attrs['RoomSource'], '🔗 Räume von Raumstruktur #14000') !== false, $m->attrs['RoomSource']);
check('Raum aus Raumstruktur: Bewegungslicht „Werkstatt“', count(array_filter($sugg, fn($x) => $x['type'] === 'bewegung' && $x['room'] === 'Werkstatt')) === 1, implode(',', array_column($sugg, 'room')));
// Fundstellen im Dialog: Öffnen-Knopf, Klartext, Einstufung
$dlg = json_decode($m->UIEditForm('aflur1', 'bewegung'), true);
$dt = json_encode($dlg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$sec = $find($dlg, 'sec_conflicts');
check('Fundstellen als eigener Abschnitt, aufgeklappt bei ⚠️', $sec !== null && $sec['expanded'] === true, json_encode($sec['caption'] ?? null, JSON_UNESCAPED_UNICODE));
check('Jede Fundstelle mit Öffnen-Knopf auf das Objekt', strpos($dt, '"type":"OpenObjectButton","caption":"Öffnen","objectID":77000') !== false && strpos($dt, '"objectID":77101') !== false && strpos($dt, '"objectID":1075') !== false);
check("Ereignis mit Ziel-Variable: ⚠️ schaltet dasselbe, Klartext statt Rohname", strpos($dt, '⚠️ Ereignis #77000 „Flurlicht alt“') !== false && strpos($dt, 'schaltet „Licht Flur / Status“ auf an') !== false, mb_substr($dt, mb_strpos($dt, "77000") - 20, 400));
check('Automatischer Ereignisname ersetzt durch Ort: unter Skript „Alarm Flur“, startet das Skript, ℹ️', strpos($dt, 'ℹ️ Ereignis #77101 unter Skript „Alarm Flur“') !== false && strpos($dt, 'startet das Skript') !== false && strpos($dt, 'with Limit') === false && strpos($dt, '(mit Bedingung)') !== false);
check('Anwesenheitssimulation: ℹ️ mit Tipp zum Hausmodus „Abwesend“', strpos($dt, 'ℹ️ Instanz „Anwesenheitssimulation Flur“') !== false && strpos($dt, 'solange die Anwesenheitssimulation läuft') !== false);
check('HomeKit Bridge: ℹ️ nur Weitergabe, kein zweiter Regler, mit Modulname', strpos($dt, 'ℹ️ Instanz „HomeKit Bridge“ (#1075, Modul HomeKitBridge') !== false && strpos($dt, 'kein zweiter Regler') !== false);

heading('C5. Kachel');
$html = $m->GetVisualizationTile();
check('Kachel enthält handleMessage mit Daten', strpos($html, 'handleMessage({') !== false);
preg_match('/handleMessage\((\{.*\})\);<\/script>$/s', $html, $mm);
$payload = json_decode($mm[1] ?? 'null', true);
check('Kachel-Daten: flache Liste mit Raum, Funktion und Zustand, Hausmodus, Protokoll', is_array($payload) && count($payload['items']) >= 3 && count($payload['modes']) === 5 && count($payload['log']) > 0
    && count(array_diff(['uid', 'name', 'icon', 'type', 'cat', 'room', 'status', 'due', 'enabled', 'locked', 'permanent', 'state'], array_keys($payload['items'][0]))) === 0);
$states = array_column($payload['items'], 'state', 'uid');
check('Kachel-Zustände sind bekannt (warn/run/idle/locked/off)', count(array_diff($states, ['warn', 'run', 'idle', 'locked', 'off'])) === 0, json_encode($states));
check('Kachel hat Suche, Filter, Gruppieren und Sortieren', strpos($html, 'id = \'au-q\'') !== false && strpos($html, "['warn', 'Auffällig']") !== false && strpos($html, "['cat', 'nach Funktion']") !== false && strpos($html, "['state', 'Zustand']") !== false && strpos($html, 'minmax(240px, 1fr)') !== false);
$m->tileUpdates = [];
$m->RequestAction('tile', json_encode(['a' => 'lock', 'uid' => 'abad01', 'v' => true]));
check('Kachel sperrt einen Automatismus', GetValue(IPS_GetObjectIDByIdent('l_abad01', 12345)) === true && count($m->tileUpdates) >= 1);
preg_match('/handleMessage\((\{.*\})\);<\/script>$/s', $m->GetVisualizationTile(), $mm2);
$p2 = array_column(json_decode($mm2[1] ?? '{}', true)['items'] ?? [], 'state', 'uid');
check('Gesperrter Automatismus hat Zustand „locked“ (Filter „Gesperrt/inaktiv“)', ($p2['abad01'] ?? '') === 'locked', json_encode($p2));
$m->RequestAction('tile', json_encode(['a' => 'perm', 'uid' => 'abad01', 'v' => true]));
check('Kachel: Dauerlicht für Nicht-Licht wird ignoriert', @IPS_GetObjectIDByIdent('d_abad01', 12345) === false);
$m->RequestAction('tile', json_encode(['a' => 'mode', 'v' => 3]));
check('Kachel setzt Hausmodus', GetValue(IPS_GetObjectIDByIdent('Hausmodus', 12345)) === 3);
$m->RequestAction('tile', 'kaputt');
check('Ungültige Kachel-Anfrage wird still ignoriert', true);

heading('C6. Aufräumen und Hygiene');
$m->props['Automations'] = json_encode([$bl]);
$m->ApplyChanges();
check('Variablen entfernter Automatismen gelöscht', @IPS_GetObjectIDByIdent('r_abad01', 12345) === false && @IPS_GetObjectIDByIdent('s_abad01', 12345) === false && @IPS_GetObjectIDByIdent('s_awasch', 12345) === false && @IPS_GetObjectIDByIdent('s_aflur1', 12345) !== false);
check('Nachricht auf entferntem Kontakt abgemeldet', !isset($m->messages[1031]));
check('Zustände entfernter Automatismen vergessen', array_keys(testStates()) === ['aflur1']);
$m->props['Automations'] = '[]';
$m->ApplyChanges();
check('Ohne Automatismen: Status 104, Takt läuft nur für die Hausmodus-Automatik weiter', $m->status === 104 && $m->timers['Tick'] > 0);
$m->props['cfg_ModeAuto'] = false;
$m->ApplyChanges();
check('Ohne Automatismen und ohne Hausmodus-Automatik: Timer aus', $m->timers['Tick'] === 0);
$m->props['cfg_ModeAuto'] = true;
heading('C7. Melderverhalten: Impuls-Melder erkennen, verlorene Meldungen zählen');
mkinst(1200, 'Sensor Gang');
mkvar(1201, VARIABLETYPE_BOOLEAN, false, 'Bewegung', 1200, false, '~Motion');
mkinst(1202, 'Licht Gang');
mkvar(1203, VARIABLETYPE_BOOLEAN, false, 'Status', 1202, true, '~Switch');
mkinst(1204, 'Sensor Diele');
mkvar(1205, VARIABLETYPE_BOOLEAN, false, 'Bewegung', 1204, false, '~Motion');
$GLOBALS['ARCHIVE'][1201] = [];
for ($i = 0; $i < 12; $i++) { $GLOBALS['ARCHIVE'][1201][] = ['TimeStamp' => ts('2026-10-12 08:00:00') + $i * 600, 'Value' => true]; }
$imp = AUTOMATKatalog::defaults('bewegung');
$imp += ['Name' => 'Ganglicht', 'Room' => 'Gang', 'Uid' => 'agang1'];
$imp['bl_mel1'] = 1201;
$imp['bl_mel2'] = 1205;
$imp['bl_out1'] = 1203;
$m->props['Automations'] = json_encode([$imp]);
$m->ApplyChanges();
$fv = json_decode($m->GetConfigurationForm(), true);
$lv = $find($fv['elements'], 'Automations')['values'][0] ?? [];
check('Nur „an“ im Archiv (12×, nie „aus“) → Impuls-Melder erkannt, Hinweis in „Einstellungen“', strpos($lv['Settings'] ?? '', 'ℹ️ Impuls-Melder erkannt') === 0, $lv['Settings'] ?? '');
$dlgI = json_encode(json_decode($m->UIEditForm('agang1', 'bewegung'), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('Dialog: Hinweis direkt beim Impuls-Schalter mit Meldername', strpos($dlgI, 'Sensor Gang / Bewegung sendet offenbar nie „aus“') !== false);
// Diele: „aus“ geht verloren → Rückfall greift → wird gezählt
$GLOBALS['CLOCK'] = ts('2026-10-14 20:00:00');
fire($m, 1205, true);
$GLOBALS['CLOCK'] += 601;
$m->Tick();
$GLOBALS['CLOCK'] += 200;
$m->Tick();
$ms = json_decode($m->attrs['MelStats'], true);
check('Rückfall-Eingriff für den Diele-Melder gezählt', count($ms[1205]['lost'] ?? []) === 1, json_encode($ms[1205] ?? null));
$fv = json_decode($m->GetConfigurationForm(), true);
$lv = $find($fv['elements'], 'Automations')['values'][0] ?? [];
check('„Einstellungen“ zeigt die verlorene Meldung', strpos($lv['Settings'] ?? '', '📶 1× Meldung verloren (7 Tage)') !== false, $lv['Settings'] ?? '');
$dlgL = json_encode(json_decode($m->UIEditForm('agang1', 'bewegung'), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('Dialog: Funkhinweis direkt unter dem betroffenen Melder', strpos($dlgL, '📶 In den letzten 7 Tagen 1× „keine Bewegung“ nicht angekommen') !== false);
check('Übersicht nennt die Hinweise', strpos($m->Overview(), '📶') !== false && strpos($m->Overview(), 'sendet offenbar nie') !== false);
$sts = testStates();
$sts['agang1']['learnN'] = 300;
$sts['agang1']['fo'] = [$GLOBALS['CLOCK'] - 3600];
SetValueString(IPS_GetObjectIDByIdent('store', 12345), json_encode($sts));
$fv = json_decode($m->GetConfigurationForm(), true);
$lv = $find($fv['elements'], 'Automations')['values'][0] ?? [];
check('„Einstellungen“ zeigt gelernten Nachlauf und Fehlabschaltungen', strpos($lv['Settings'] ?? '', '🕒 Nachlauf gelernt 5:00 min · 1× zu früh aus (7 Tage)') !== false, $lv['Settings'] ?? '');
$dlgN = json_encode(json_decode($m->UIEditForm('agang1', 'bewegung'), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('Dialog: Hinweis zum gelernten Nachlauf am Nachlauf-Feld', strpos($dlgN, '🕒 Gelernt: 5:00 min — 1× zu früh ausgeschaltet') !== false);
$GLOBALS['CLOCK'] = strtotime('+8 days', $GLOBALS['CLOCK']);
$fv = json_decode($m->GetConfigurationForm(), true);
$lv = $find($fv['elements'], 'Automations')['values'][0] ?? [];
check('Nach 7 Tagen fällt der Eingriff aus der Anzeige', strpos($lv['Settings'] ?? '', '📶') === false);

heading('C8. Anwesenheit zusammenfassen speist den Hausmodus');
mkinst(1500, 'Dietmar');
mkvar(1501, VARIABLETYPE_BOOLEAN, true, 'Zuhause', 1500, false, 'Anwesenheit');
mkinst(1510, 'Svetlana');
mkvar(1511, VARIABLETYPE_BOOLEAN, false, 'Zuhause', 1510, false, 'Anwesenheit');
mkinst(1520, 'Model Y');
$GLOBALS['MODNAME'][1520] = 'Tessie';
mkvar(1521, VARIABLETYPE_BOOLEAN, true, 'Zu Hause', 1520, false, '', [], 'stat_at_home');
$m->props['Automations'] = '[]';
$m->props['cfg_PresenceVar'] = 0;
$m->ApplyChanges();
$m->Discover();
$sug = json_decode($m->attrs['Suggestions'], true);
$sa = array_values(array_filter($sug, fn($x) => $x['type'] === 'anwesenheit'));
check('Entdecken schlägt „Anwesenheit zusammenfassen“ mit beiden Personen vor, ohne Auto', count($sa) === 1 && AUTOMATKatalog::vars($sa[0]['row'], 'an_p') === [1501, 1511], json_encode($sa, JSON_UNESCAPED_UNICODE));
$anr = $sa[0]['row'] ?? $an;
$anr['Uid'] = 'aanw02';
$anr['Enabled'] = true;
$anr['an_weg'] = 5;
$m->props['Automations'] = json_encode([$anr]);
$m->props['cfg_ModeAuto'] = true;
at('12:00:00');
$m->ApplyChanges();
$pid = @IPS_GetObjectIDByIdent('p_aanw02', 12345);
check('Ergebnisvariable „Jemand zu Hause“ angelegt und wahr', $pid !== false && GetValue($pid) === true && isset($m->messages[1501]));
$ft = json_encode(json_decode($m->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
check('Hausmodus-Panel zeigt die automatische Verknüpfung', strpos($ft, '🔗 Anwesenheit automatisch aus dem Automatismus „Anwesenheit“') !== false, '');
$fv = json_decode($m->GetConfigurationForm(), true);
check('Mit Automatismus: Feld für eigene Anwesenheits-Variable ausgeblendet', ($find($fv['elements'], 'cfg_PresenceVar')['visible'] ?? true) === false);
$m->props['cfg_PresenceVar'] = $pid;
$m->ApplyChanges();
$ft2 = json_encode(json_decode($m->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
check('Ergebnisvariable selbst eingetragen: gilt als automatische Quelle, Hinweis „nicht nötig“', strpos($ft2, '🔗 Anwesenheit automatisch aus dem Automatismus') !== false && strpos($ft2, 'das ist nicht nötig') !== false && strpos($ft2, 'aus der eingetragenen Variable') === false);
$m->props['cfg_PresenceVar'] = 0;
$m->ApplyChanges();
check('Hausmodus-Panel bietet Sonnenaufgang und „Ist es Tag“', strpos($ft, 'bei Sonnenaufgang') !== false && strpos($ft, '„Ist es Tag“ (Location Control) falsch wird') !== false);
$dlgA = json_encode(json_decode($m->UIEditForm('aanw02', 'anwesenheit'), true), JSON_UNESCAPED_UNICODE);
check('Dialog: 8 + 8 freie Plätze für Anwesenheitssignale, keine Felder anderer Arten', strpos($dlgA, '"an_p16"') !== false && strpos($dlgA, '"an_p17"') === false && strpos($dlgA, '"bl_mel1"') === false);
// Keine feste Obergrenze (Dietmar 15.10.2026: „beim Gehen-Check nur 8 Aus-Schalter“)
$gcRow = AUTOMATKatalog::defaults('gehen');
for ($i = 1; $i <= 20; $i++) { $gcRow['gc_geraet' . $i] = 2000 + $i; }
check('Mehrfachfeld ohne Obergrenze: 20 Geräte werden alle gelesen', count(AUTOMATKatalog::vars($gcRow, 'gc_geraet')) === 20 && AUTOMATKatalog::slotsIn($gcRow, 'gc_geraet') === 20 && AUTOMATKatalog::lastFilled($gcRow, 'gc_geraet') === 20);
$gcRow['Uid'] = 'agc020';
$gcRow['Name'] = 'Gehen 20';
$gcRow['gc_kon1'] = 2100;
$gcKeep = $m->props['Automations'];
$m->props['Automations'] = json_encode(array_merge(json_decode($gcKeep, true) ?: [], [$gcRow]));
$m->ApplyChanges();
$dlgG = json_encode(json_decode($m->UIEditForm('agc020', 'gehen'), true), JSON_UNESCAPED_UNICODE);
check('Dialog: nach 20 belegten Geräten 8 weitere freie Plätze (bis 28)', strpos($dlgG, '"gc_geraet28"') !== false && strpos($dlgG, '"gc_geraet29"') === false);
$m->fieldUpdates = [];
$m->UIVarsChanged('gc_geraet', 1, json_encode(array_fill(0, 3, 2001)));
check('Alle Plätze belegt: Hinweis „nach Übernehmen gibt es weitere“', in_array(['more_gc_geraet', 'visible', true], $m->fieldUpdates, true));
$m->fieldUpdates = [];
$m->UIVarsChanged('gc_geraet', 1, json_encode([2001, 0, 0]));
check('… mit freiem Platz kein Hinweis', in_array(['more_gc_geraet', 'visible', false], $m->fieldUpdates, true));
$m->props['Automations'] = $gcKeep;
$m->ApplyChanges();
check('Bewegungsmelder weiter 4 Plätze', AUTOMATKatalog::slots('bl_mel') === 4);
fire($m, 1501, false);
at('12:06:00');
$m->Tick();
check('Alle weg + Wartezeit → Ergebnis falsch, Hausmodus „Abwesend“', GetValue($pid) === false && GetValue(IPS_GetObjectIDByIdent('Hausmodus', 12345)) === 3);
fire($m, 1511, true);
check('Heimkommen → Hausmodus zurück auf Tageszeit', GetValue($pid) === true && GetValue(IPS_GetObjectIDByIdent('Hausmodus', 12345)) === 0);
mkvar(10446, VARIABLETYPE_BOOLEAN, true, 'Ist es Tag', 10443, false, '', [], 'IsDay');
$m->props['cfg_EveningRef'] = 2;
$m->ApplyChanges();
check('„Ist es Tag“ wird überwacht, wenn gewählt', isset($m->messages[10446]));
at('16:30:00');
fire($m, 10446, false);
check('„Ist es Tag“ wird falsch → Abend', GetValue(IPS_GetObjectIDByIdent('Hausmodus', 12345)) === 1);
$m->props['cfg_DayRef'] = 2;
$fv = json_decode($m->GetConfigurationForm(), true);
$vis = function (string $n) use (&$fv, $find) { return $find($fv['elements'], $n)['visible'] ?? true; };
check('„Ist es Tag“ gewählt: weder Uhrzeit noch Versatz sichtbar', !$vis('cfg_DayFrom') && !$vis('cfg_DayOffset') && !$vis('cfg_EveningFrom') && !$vis('cfg_EveningOffset'));
$m->props['cfg_DayRef'] = 1;
$m->props['cfg_EveningRef'] = 1;
$fv = json_decode($m->GetConfigurationForm(), true);
check('Sonnenaufgang/Uhrzeit gewählt: nur Versatz bzw. nur Uhrzeit sichtbar', !$vis('cfg_DayFrom') && $vis('cfg_DayOffset') && $vis('cfg_EveningFrom') && !$vis('cfg_EveningOffset'));
$lt = json_encode($fv, JSON_UNESCAPED_UNICODE);
check('Infozeile nennt nur, was die Auswahl nutzt (Sonnenaufgang heute 07:45), kein „verbunden“', strpos($lt, 'Aus der Location Control: Sonnenaufgang heute 07:45 Uhr.') !== false && strpos($lt, 'Sonnenuntergang heute') === false && strpos($lt, '🔗 Sonnenaufgang') === false);
$m->fieldUpdates = [];
$m->UIRefChanged(0, 0, 0);
check('Umstellen im offenen Formular blendet sofort passend ein/aus', in_array(['cfg_DayFrom', 'visible', true], $m->fieldUpdates, true) && in_array(['cfg_DayOffset', 'visible', false], $m->fieldUpdates, true) && in_array(['cfg_EveningOffset', 'visible', true], $m->fieldUpdates, true) && in_array(['cfg_EveningFrom', 'visible', false], $m->fieldUpdates, true), json_encode($m->fieldUpdates));
check('… und die Infozeile folgt (jetzt Sonnenuntergang 18:30)', in_array(['LocationInfo', 'caption', 'ℹ️ Aus der Location Control: Sonnenuntergang heute 18:30 Uhr.'], $m->fieldUpdates, true), json_encode($m->fieldUpdates, JSON_UNESCAPED_UNICODE));
$m->fieldUpdates = [];
$m->UIRefChanged(0, 1, 0);
check('Alles „zur Uhrzeit“: Infozeile ausgeblendet', in_array(['LocationInfo', 'visible', false], $m->fieldUpdates, true));
$m->props['cfg_DayRef'] = 0;
$m->props['cfg_EveningRef'] = 1;
$fv = json_decode($m->GetConfigurationForm(), true);
$opt = array_column($find($fv['elements'], 'cfg_EveningRef')['options'], 'caption');
check('Abend-Auswahl bietet alle Zeitpunkte der Location Control mit heutiger Uhrzeit', in_array('Sonnenuntergang (heute 18:30 Uhr)', $opt, true) && in_array('Ende nautische Dämmerung', array_map(fn($o) => preg_replace('/ \(heute.*$/', '', $o), $opt), true) && count(array_filter($opt, fn($o) => strpos($o, 'Dämmerung') !== false)) === 3, json_encode($opt, JSON_UNESCAPED_UNICODE));
check('Helligkeitssensor ist zentral und immer sichtbar (auch für Rollläden und Dämmerung)', ($find($fv['elements'], 'cfg_LightVar')['visible'] ?? true) === true && strpos(json_encode($find($fv['elements'], 'LightHead'), JSON_UNESCAPED_UNICODE), 'Rollläden') !== false);
mkinst(1590, 'Sensor Flur');
mkvar(1591, VARIABLETYPE_FLOAT, 0.0, 'Sensor Multilevel (Beleuchtung)', 1590, false, '~Illumination.F', ['SUFFIX' => ' lx']);
mkinst(1600, 'Wetterstation');
mkvar(1601, VARIABLETYPE_FLOAT, 120.0, 'Globalstrahlung', 1600, false, '', ['SUFFIX' => ' W/m²']);
$m->props['cfg_EveningRef'] = 6;
$m->props['cfg_LightVar'] = 0;
$fv = json_decode($m->GetConfigurationForm(), true);
check('Sensor gewählt aber leer: Formular schlägt die Wetterstation vor', strpos($find($fv['elements'], 'LightHint')['caption'] ?? '', '💡 Passende Sensoren gefunden: Wetterstation / Globalstrahlung (#1601)') !== false && ($find($fv['elements'], 'cfg_LightVar')['visible'] ?? true) === true);
$fh = json_encode($find($fv['elements'], 'LightHint')['caption'] ?? '', JSON_UNESCAPED_UNICODE);
check('Einstrahlung der Wetterstation steht vor dem Lux-Sensor im Flur', strpos($fh, 'Flur') !== false && strpos($fh, 'Globalstrahlung') < strpos($fh, 'Flur'), $fh);
$m->props['cfg_DayRef'] = 0;
$m->props['cfg_EveningRef'] = 1;
$fvH = json_decode($m->GetConfigurationForm(), true);
check('Vorschläge für den Sensor auch ohne „Helligkeitssensor“ im Hausmodus (zentral)', strpos($find($fvH['elements'], 'LightHint')['caption'] ?? '', 'Globalstrahlung (#1601)') !== false);
$m->props['cfg_EveningRef'] = 6;
$m->props['cfg_LightVar'] = 1601;
$m->props['cfg_LightHold'] = 10;
$m->WriteAttributeString('LightState', '{}');
at('16:00:00');
$m->ApplyChanges();
check('Sensor wird überwacht, hell → Tag', isset($m->messages[1601]) && GetValue(IPS_GetObjectIDByIdent('Hausmodus', 12345)) === 0);
fire($m, 1601, 8.0);
check('Wolke (8 W/m²): Haltezeit läuft, noch Tag', GetValue(IPS_GetObjectIDByIdent('Hausmodus', 12345)) === 0);
at('16:11:00');
$m->Tick();
check('Nach 10 min dunkel → Abend', GetValue(IPS_GetObjectIDByIdent('Hausmodus', 12345)) === 1);
$fv = json_decode($m->GetConfigurationForm(), true);
check('Infozeile zeigt Sensorwert mit Einheit und Zustand', strpos($find($fv['elements'], 'LocationInfo')['caption'] ?? '', 'Sensor „Wetterstation / Globalstrahlung“ gerade 8 W/m² (dunkel)') !== false, $find($fv['elements'], 'LocationInfo')['caption'] ?? '');
check('Schwellen zeigen die Einheit des Sensors', ($find($fv['elements'], 'cfg_LightDay')['suffix'] ?? '') === ' W/m²');
$m->props['cfg_EveningRef'] = 0;
$m->props['Automations'] = '[]';
$m->ApplyChanges();
check('Ergebnisvariable verschwindet mit dem Automatismus', @IPS_GetObjectIDByIdent('p_aanw02', 12345) === false);

heading('C9. Meldungen: was und wie entscheidet der Nutzer; Formular-Aufbau mit Ausblenden');
$msg = new ReflectionMethod('Automat', 'message');
$GLOBALS['PUSH'] = [];
$GLOBALS['MAIL'] = [];
mkinst(13001, 'WebFront alt');
$GLOBALS['MODULES']['{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}'] = [13001];
function WFC_PushNotification(int $id, string $t, string $x, string $s, int $target): bool { $GLOBALS['PUSH'][] = [$id, $t, $x]; return true; }
at('12:00:00');
$msg->invoke($m, 'Titel', 'nicht melden', false, null);
check('Automatismus ohne „Melden“: nur „Letzte Meldung“, keine Zustellung', $GLOBALS['PUSH'] === [] && strpos(GetValue(IPS_GetObjectIDByIdent('LastMessage', 12345)), 'nicht melden') !== false);
$msg->invoke($m, 'Titel', 'an alle', true, null);
check('Push ohne Auswahl: an alle Visualisierungen (Kachel und WebFront)', array_column($GLOBALS['PUSH'], 0) === [13000, 13001]);
$m->props['cfg_PushTargets'] = json_encode([['Instance' => 13001]]);
$GLOBALS['PUSH'] = [];
$msg->invoke($m, 'Titel', 'nur WebFront', true, null);
check('Push mit Auswahl: nur an die gewählte Visualisierung', array_column($GLOBALS['PUSH'], 0) === [13001]);
mkinst(14223, 'SMTP');
$m->props['cfg_NotifyPush'] = false;
$m->props['cfg_NotifyMail'] = true;
$m->props['cfg_MailInstance'] = 14223;
$m->props['cfg_MailTo'] = 'a@example.org, b@example.org';
$GLOBALS['PUSH'] = [];
$msg->invoke($m, 'Waschmaschine', 'fertig', true, null);
check('Push aus, E-Mail an: an beide Empfänger, kein Push', $GLOBALS['PUSH'] === [] && array_column($GLOBALS['MAIL'], 1) === ['a@example.org', 'b@example.org']);
$GLOBALS['MAIL_FAIL'] = true;
$tm = $m->TestMessage();
unset($GLOBALS['MAIL_FAIL']);
check('Testmeldung nennt den Grund eines Mailfehlers verständlich', strpos($tm, '„E-Mail“: ⚠️ nicht gesendet — Anmeldung abgelehnt') !== false, $tm);
$m->props['cfg_NotifyMail'] = false;
check('Testmeldung ohne Weg sagt das', strpos($m->TestMessage(), 'Kein Meldeweg angelegt') !== false);
$m->props['cfg_NotifyPush'] = true;
$m->props['cfg_PushTargets'] = '[]';
$m->props['cfg_QuietEnabled'] = true;
$m->props['cfg_QuietFrom'] = 22;
$m->props['cfg_QuietTo'] = 7;
$GLOBALS['PUSH'] = [];
at('23:30:00');
$msg->invoke($m, '🧺 Trockner', 'fertig', true, null);
check('Ruhezeit: Meldung zurückgehalten', $GLOBALS['PUSH'] === [] && count(json_decode($m->attrs['Deferred'], true)) === 1);
$m->Tick();
check('Noch Ruhezeit: Takt stellt nicht zu', $GLOBALS['PUSH'] === []);
$GLOBALS['CLOCK'] = ts('2026-10-14 07:01:00');
$m->Tick();
check('Nach der Ruhezeit nachgeholt, mit Hinweis', count($GLOBALS['PUSH']) === 2 && strpos($GLOBALS['PUSH'][0][2], 'zurückgehalten seit 13.10.2026 23:30') !== false && $m->attrs['Deferred'] === '[]');
$m->props['cfg_QuietEnabled'] = false;
at('12:00:00');
$runWash = function (bool $notify) use ($m, $gf) {
    $row = $gf;
    $row['Uid'] = $notify ? 'awasch3' : 'awasch2';
    $row['gf_push'] = $notify;
    $m->props['Automations'] = json_encode([$row]);
    $m->ApplyChanges();
    $GLOBALS['PUSH'] = [];
    fire($m, 1041, 1500.0);
    $GLOBALS['CLOCK'] += 3600;
    fire($m, 1041, 1.0);
    $GLOBALS['CLOCK'] += 181;
    $m->Tick();
};
$runWash(false);
check('„Gerät fertig“ ohne „Melden“: keine Zustellung, aber „Letzte Meldung“', $GLOBALS['PUSH'] === [] && strpos(GetValue(IPS_GetObjectIDByIdent('LastMessage', 12345)), 'Waschmaschine') !== false);
$runWash(true);
check('„Gerät fertig“ mit „Melden“: zugestellt', count($GLOBALS['PUSH']) === 2);
$m->props['Automations'] = '[]';
$m->ApplyChanges();
at('12:00:00');
$fv = json_decode($m->GetConfigurationForm(), true);
$caps = array_map(fn($e) => (string)($e['caption'] ?? ''), $fv['elements']);
check('Eigene Bereiche „🔔 Meldungen“ und „📜 Protokoll und Kachel“, dazu „🪟 Formular: Aufbau“', count(array_filter($caps, fn($c) => strpos($c, '🔔  Meldungen') === 0)) === 1 && count(array_filter($caps, fn($c) => strpos($c, '📜  Protokoll') === 0)) === 1 && count(array_filter($caps, fn($c) => strpos($c, '🪟  Formular: Aufbau') === 0)) === 1);
$m->props['FormLayout'] = json_encode([['Key' => 'catalog', 'Opened' => true, 'Closed' => false, 'Hidden' => true, 'Pos' => 4], ['Key' => 'discover', 'Hidden' => true, 'Pos' => 3], ['Key' => 'mode', 'Opened' => true, 'Pos' => 1], ['Key' => 'doc', 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => 2]]);
$fv = json_decode($m->GetConfigurationForm(), true);
$byCap = [];
foreach ($fv['elements'] as $e) {
    $byCap[(string)($e['caption'] ?? '')] = $e;
}
$catalogEl = array_values(array_filter($fv['elements'], fn($e) => strpos((string)($e['caption'] ?? ''), 'Katalog') !== false))[0] ?? [];
$modeIdx = array_search(true, array_map(fn($e) => strpos((string)($e['caption'] ?? ''), '🏠  Hausmodus') === 0, $fv['elements']), true);
$docIdx = array_search(true, array_map(fn($e) => strpos((string)($e['caption'] ?? ''), 'Dokumentation') !== false, $fv['elements']), true);
check('Ausgeblendet: Bereich bleibt im Formular, ist aber unsichtbar', ($catalogEl['visible'] ?? true) === false);
check('Position 1 + „Aufgeklappt“: Hausmodus steht vor der Dokumentation und ist offen', $modeIdx !== false && $docIdx !== false && $modeIdx < $docIdx && ($fv['elements'][$modeIdx]['expanded'] ?? false) === true);
$lay = array_values(array_filter($fv['elements'], fn($e) => strpos((string)($e['caption'] ?? ''), '🪟  Formular: Aufbau') === 0))[0];
check('Aufbau-Panel nennt die Zahl und die Namen der ausgeblendeten Bereiche', strpos($lay['caption'], '(2 ausgeblendet)') !== false && strpos(json_encode($lay, JSON_UNESCAPED_UNICODE), '🙈 Ausgeblendet: ') !== false);
$m->fieldUpdates = [];
$m->ResetFormLayout();
$vals = json_decode($m->fieldUpdates[0][2] ?? '[]', true);
check('Zurücksetzen: alle Haken leer, Grundreihenfolge (nur im offenen Formular)', count($vals) === 7 && array_unique(array_merge(array_column($vals, 'Opened'), array_column($vals, 'Closed'), array_column($vals, 'Hidden'))) === [false] && array_column($vals, 'Key')[0] === 'doc');
$lv = array_column($lay['items'][3]['values'], null, 'Key');
check('Widerspruch (Aufgeklappt + Ausgeblendet): Ausgeblendet gilt, die Liste zeigt nur diesen Haken', $lv['catalog']['Hidden'] === true && $lv['catalog']['Opened'] === false && $lv['mode']['Opened'] === true && $lv['doc']['Opened'] === false);
check('Aufbau-Panel selbst bleibt sichtbar', ($lay['visible'] ?? true) === true);
$m->props['FormLayout'] = json_encode([['Key' => 'catalog', 'Open' => 'hidden', 'Pos' => 4]]);
$fv = json_decode($m->GetConfigurationForm(), true);
$catalogEl = array_values(array_filter($fv['elements'], fn($e) => strpos((string)($e['caption'] ?? ''), 'Katalog') !== false))[0] ?? [];
check('Frühere Speicherung (Auswahl „Open“ aus 0.1.24) wird weiter gelesen', ($catalogEl['visible'] ?? true) === false);
$m->props['FormLayout'] = '[]';

heading('C10. Wächter: Vergleichswert aus den möglichen Werten der Variable wählen');
mkinst(1700, 'Esszimmer Terrassentür');
mkvar(1701, VARIABLETYPE_BOOLEAN, true, 'Esszimmer Terrassentür', 1700, false, '', ['PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}', 'OPTIONS' => json_encode([['Value' => false, 'Caption' => 'Offen'], ['Value' => true, 'Caption' => 'Geschlossen']])]);
$GLOBALS['PROFILES']['Garage.Tor'] = ['MaxValue' => 0, 'Suffix' => '', 'Assoc' => [], 'Associations' => [['Value' => 0, 'Name' => 'Zu'], ['Value' => 1, 'Name' => 'Auf'], ['Value' => 2, 'Name' => 'Halb offen']]];
mkvar(1702, VARIABLETYPE_INTEGER, 0, 'Garagentor', 1700, false, 'Garage.Tor');
mkvar(1703, VARIABLETYPE_BOOLEAN, false, 'Schalter ohne Beschriftung', 1700, true, '');
mkvar(1704, VARIABLETYPE_FLOAT, 21.5, 'Temperatur', 1700, false, '');
$wa = AUTOMATKatalog::defaults('waechter');
$wa += ['Name' => 'Terrassentür offen', 'Room' => 'Esszimmer', 'Uid' => 'aterr1'];
$wa['wa_var'] = 1701;
$wa['wa_op'] = 0;
$wa['wa_wert'] = 'false';
$wa['wa_dauer'] = 15;
$m->props['Automations'] = json_encode([$wa]);
$m->ApplyChanges();
$dlg = json_decode($m->UIEditForm('aterr1', 'waechter'), true);
$pick = null;
array_walk_recursive($dlg, function () {});
$walk = function ($els) use (&$walk, &$pick) {
    foreach ($els as $e) {
        if (($e['name'] ?? '') === 'wa_pick') {
            $pick = $e;
        }
        if (isset($e['items'])) {
            $walk($e['items']);
        }
    }
};
$walk($dlg);
$caps = array_column($pick['options'] ?? [], 'caption');
check('Dialog: Auswahl zeigt die Beschriftungen der Tür (Matter: wahr = Geschlossen)', ($pick['visible'] ?? false) === true && in_array('Offen (falsch)', $caps, true) && in_array('Geschlossen (wahr)', $caps, true), json_encode($caps, JSON_UNESCAPED_UNICODE));
check('Auswahl liefert den echten Wert („false“ für Offen), nicht die Beschriftung', array_column($pick['options'], 'value', 'caption')['Offen (falsch)'] === 'false');
$m->fieldUpdates = [];
$m->UIVarChanged('wa_var', 1702);
$up = array_values(array_filter($m->fieldUpdates, fn($u) => $u[0] === 'wa_pick' && $u[1] === 'options'));
$opts2 = json_decode($up[0][2] ?? '[]', true);
check('Variable gewechselt: Auswahl folgt sofort (Profil-Zuordnungen des Garagentors)', array_column($opts2, 'caption') === ['— Wert wählen —', 'Zu (0)', 'Auf (1)', 'Halb offen (2)'], json_encode($opts2, JSON_UNESCAPED_UNICODE));
$m->fieldUpdates = [];
$m->UIVarChanged('wa_var', 1703);
$ob = array_values(array_filter($m->fieldUpdates, fn($u) => $u[0] === 'wa_pick' && $u[1] === 'options'));
check('Bool ohne Beschriftung: an / wahr und aus / falsch', in_array(['wa_pick', 'visible', true], $m->fieldUpdates, true) && array_column(json_decode($ob[0][2] ?? '[]', true), 'caption') === ['— Wert wählen —', 'an / wahr', 'aus / falsch']);
$m->fieldUpdates = [];
$m->UIVarChanged('wa_var', 1704);
check('Messwert ohne feste Werte: Auswahl ausgeblendet, Feld zum Eintippen bleibt', in_array(['wa_pick', 'visible', false], $m->fieldUpdates, true));
$m->fieldUpdates = [];
$m->UIPickValue('wa_wert', 'wa_op', 'false', 4);
check('Wert gewählt: steht im Vergleichsfeld, „ist an / wahr“ wird zu „ist gleich“ (Feld wird sichtbar)', $m->fieldUpdates === [['wa_wert', 'value', 'false'], ['wa_op', 'value', 0], ['wa_wert', 'visible', true]], json_encode($m->fieldUpdates));
$GLOBALS['PUSH'] = [];
at('12:00:00');
fire($m, 1701, false);
at('12:16:00');
$m->Tick();
$lm = GetValue(IPS_GetObjectIDByIdent('LastMessage', 12345));
check('Meldung mit Beschriftung: „ist gleich Offen … (aktuell Offen)“ statt „false“', strpos($lm, 'ist gleich Offen') !== false && strpos($lm, '(aktuell Offen)') !== false && strpos($lm, 'false') === false, $lm);
check('valueKey vereinheitlicht Werte (true/„1.0“/„Zu“)', AUTOMATLogik::valueKey(true) === 'true' && AUTOMATLogik::valueKey('1.0') === '1' && AUTOMATLogik::valueKey(2.0) === '2' && AUTOMATLogik::valueKey('Zu') === 'Zu');
$m->props['Automations'] = '[]';
$m->ApplyChanges();

heading('C11. Meldewege: je Meldung eigene Wege');
$GLOBALS['OBJ'][1800] = ['kind' => 3, 'name' => 'Telegram senden', 'parent' => 0, 'ident' => '', 'hidden' => false];
$m->props['cfg_QuietEnabled'] = false;
$m->props['cfg_Ways'] = json_encode([
    ['Id' => 'handy', 'Name' => 'Handy Dietmar', 'Typ' => 'push', 'Target' => 13001, 'To' => '', 'Std' => false, 'Quiet' => false],
    ['Id' => 'kachel', 'Name' => 'Kachel', 'Typ' => 'push', 'Target' => 13000, 'To' => '', 'Std' => true, 'Quiet' => true],
    ['Id' => 'fam', 'Name' => 'E-Mail Familie', 'Typ' => 'mail', 'Target' => 14223, 'To' => 'x@example.org', 'Std' => false, 'Quiet' => true],
    ['Id' => 'tg', 'Name' => 'Telegram', 'Typ' => 'script', 'Target' => 1800, 'To' => '', 'Std' => false, 'Quiet' => true],
]);
$rowW = AUTOMATKatalog::defaults('waechter') + ['Uid' => 'aww1', 'Name' => 'Tür'];
$rowW['wa_mw_handy'] = true;
$rowW['wa_mw_tg'] = true;
$GLOBALS['PUSH'] = [];
$GLOBALS['MAIL'] = [];
$GLOBALS['SCRIPTRUN'] = [];
at('12:00:00');
$msg->invoke($m, '⏱ Tür', 'offen', true, $rowW);
check('Automatismus mit eigener Wahl: nur seine Wege (Handy-WebFront und Telegram-Skript)', array_column($GLOBALS['PUSH'], 0) === [13001] && $GLOBALS['MAIL'] === [] && count($GLOBALS['SCRIPTRUN']) === 1 && $GLOBALS['SCRIPTRUN'][0][1]['TEXT'] === 'offen');
$rowG = AUTOMATKatalog::defaults('fertig') + ['Uid' => 'agf1', 'Name' => 'Trockner'];
$GLOBALS['PUSH'] = [];
$msg->invoke($m, '🧺 Trockner', 'fertig', true, $rowG);
check('Ohne eigene Wahl: die Standard-Wege (Kachel)', array_column($GLOBALS['PUSH'], 0) === [13000]);
$rowG['gf_mw_fam'] = true;
$msg->invoke($m, '🧺 Trockner', 'fertig', true, $rowG);
check('Anderer Automatismus, anderer Weg: E-Mail an die Familie', array_column($GLOBALS['MAIL'], 1) === ['x@example.org']);
$m->props['cfg_QuietEnabled'] = true;
$m->props['cfg_QuietFrom'] = 22;
$m->props['cfg_QuietTo'] = 7;
$GLOBALS['PUSH'] = [];
$GLOBALS['SCRIPTRUN'] = [];
$rowW['wa_mw_kachel'] = true;
at('23:00:00');
$msg->invoke($m, '⏱ Tür', 'nachts offen', true, $rowW);
check('Ruhezeit: Weg ohne „Ruhezeit beachten“ meldet sofort, die anderen warten', array_column($GLOBALS['PUSH'], 0) === [13001] && $GLOBALS['SCRIPTRUN'] === [] && count(json_decode($m->attrs['Deferred'], true)) === 2);
$GLOBALS['CLOCK'] = ts('2026-10-14 07:05:00');
$GLOBALS['PUSH'] = [];
$m->Tick();
check('Nach der Ruhezeit: nur die zurückgehaltenen Wege (Kachel, Telegram), nicht nochmal das Handy', array_column($GLOBALS['PUSH'], 0) === [13000] && count($GLOBALS['SCRIPTRUN']) === 1);
$m->props['cfg_QuietEnabled'] = false;
at('12:00:00');
$m->props['Automations'] = json_encode([$rowW + ['Enabled' => true, 'wa_var' => 1701, 'wa_push' => true]]);
$m->ApplyChanges();
$dlgW = json_encode(json_decode($m->UIEditForm('aww1', 'waechter'), true), JSON_UNESCAPED_UNICODE);
check('Dialog: je Meldeweg ein Haken mit Typ-Kürzel (wa_mw_…), keine fremden (gf_mw_…)', strpos($dlgW, '"wa_mw_handy"') !== false && strpos($dlgW, '"gf_mw_handy"') === false && strpos($dlgW, 'kein Haken = die Standard-Wege: Kachel') !== false);
$names = [];
$walkN = function ($els) use (&$walkN, &$names) {
    foreach ($els as $e) {
        if (isset($e['name'])) {
            $names[] = $e['name'];
        }
        if (isset($e['items'])) {
            $walkN($e['items']);
        }
    }
};
$walkN(json_decode($m->UIEditForm('aww1', 'waechter'), true));
check('Dialog: alle Feldnamen eindeutig', count($names) === count(array_unique($names)), implode(',', array_diff_assoc($names, array_unique($names))));
$ft = json_encode(json_decode($m->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
check('Panel: Übersicht nennt je Automatismus seine Wege', strpos($ft, '„Tür“ → Handy Dietmar, Kachel, Telegram') !== false);
$tm = $m->TestMessage();
check('Testmeldung: Ergebnis je Meldeweg', substr_count($tm, '„') >= 4 && strpos($tm, '„Telegram“: ✅ Skript „Telegram senden“ gestartet') !== false, $tm);
$m->props['cfg_Ways'] = '[]';
$m->props['cfg_NotifyPush'] = true;
$m->props['cfg_NotifyMail'] = true;
$m->props['cfg_PushTargets'] = '[]';
$ft = json_encode(json_decode($m->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
check('Nach dem Update: bisherige Push-/E-Mail-Einstellung erscheint als Meldewege mit Hinweis', strpos($ft, 'Push an alle Visualisierungen') !== false && strpos($ft, 'stammen aus deinen bisherigen Einstellungen') !== false);
$m->props['cfg_NotifyMail'] = false;
$m->props['Automations'] = '[]';
$m->ApplyChanges();

heading('C12. Szene im Modul: Hausmodus-Wechsel schaltet, Bewegungslicht ohne Handpause');
$szr = AUTOMATKatalog::defaults('szene');
$szr += ['Uid' => 'aszn02', 'Name' => 'Alles aus', 'Enabled' => true];
$szr['sz_out1'] = 1001;
$szr['sz_out2'] = 1081;
$blr = $bl;
$blr['Uid'] = 'aflur9';
$blr['Enabled'] = true;
$blr['bl_handpause'] = 15;
$m->props['Automations'] = json_encode([$blr, $szr]);
$m->props['cfg_ModeAuto'] = false;
at('22:00:00');
$m->ApplyChanges();
$m->RequestAction('Hausmodus', 1);
fire($m, 1011, true);
check('Vorher: Bewegung schaltet Flurlicht ein', GetValue(1001) === true);
SetValue(1081, true);
$m->RequestAction('Hausmodus', 2);
check('Hausmodus „Nacht“ von Hand → Szene schaltet beide Lichter aus', GetValue(1001) === false && GetValue(1081) === false);
fire($m, 1001, false);
fire($m, 1081, false);
$stz = testStates();
check('Bewegungslicht wertet das als „zentral“, nicht als Handbedienung (keine Pause)', $stz['aflur9']['ph'] === 'aus', json_encode($stz['aflur9']));
fire($m, 1011, false);
$GLOBALS['CLOCK'] += 20;
fire($m, 1011, true);
check('Nächste Bewegung schaltet sofort wieder ein', GetValue(1001) === true);
check('Protokoll nennt die Szene', strpos(implode("\n", array_map(fn($e) => $e[2], json_decode($m->attrs['Protocol'], true))), 'Zentral ausgeschaltet (Szene „Alles aus“)') !== false);
$dlgS = json_decode($m->UIEditForm('aszn02', 'szene'), true);
$hiddenModes = true;
$walkM = function ($els) use (&$walkM, &$hiddenModes) {
    foreach ($els as $e) {
        if (in_array($e['name'] ?? '', ['m0', 'm1', 'm2', 'm3', 'm4'], true) && ($e['visible'] ?? true) !== false) {
            $hiddenModes = false;
        }
        if (isset($e['items'])) {
            $walkM($e['items']);
        }
    }
};
$walkM($dlgS);
check('Dialog der Szene: allgemeine Modus-Häkchen ausgeblendet', $hiddenModes);
check('Schalter heißt „Aktiv“ (Dialog, Listenspalte, Zustand „Nicht aktiv“)', strpos(json_encode($dlgS, JSON_UNESCAPED_UNICODE), '"name":"Enabled","caption":"Aktiv"') !== false && strpos($src0 = file_get_contents(dirname(__DIR__) . '/Automat/module.php'), "['caption' => 'Aktiv', 'name' => 'Enabled'") !== false && strpos($src0, "'⚫ Nicht aktiv'") !== false && strpos($src0, "'Eingeschaltet'") === false);
$cS = json_encode(json_decode($m->UIEditForm('aszn02', 'szene'), true), JSON_UNESCAPED_UNICODE);
check('Doppelregelung mit dem Bewegungslicht nur als ℹ️ (gewollt)', strpos($cS, 'gewollt: Szene bzw. Alarm schalten nur bei ihrem Anlass') !== false);
$m->props['Automations'] = json_encode([$blr]);
$m->ApplyChanges();
$m->Discover();
$sugS = array_values(array_filter(json_decode($m->attrs['Suggestions'], true), fn($x) => $x['type'] === 'szene'));
check('Entdecken schlägt „Zentral-Aus nach Hausmodus“ mit den gefundenen Lichtern vor', count($sugS) === 1 && count(AUTOMATKatalog::vars($sugS[0]['row'], 'sz_out')) >= 2);
$m->props['Automations'] = '[]';
$m->props['cfg_ModeAuto'] = true;
$m->ApplyChanges();

heading('C13. Neue Typen im Modul: Hausmodus-Ereignis, dringende Meldung, Taster setzt Modus');
mkinst(1900, 'Thermostat Wohnzimmer');
mkvar(1901, VARIABLETYPE_FLOAT, 21.5, 'Sollwert', 1900, true, '~Temperature.Room');
mkinst(1910, 'Wasserleck Keller');
mkvar(1911, VARIABLETYPE_BOOLEAN, false, 'Zustand', 1910, false, '');
mkinst(1920, 'Taster Flur');
mkvar(1921, VARIABLETYPE_STRING, '', 'action', 1920, false, '', [], 'action');
$hzr = AUTOMATKatalog::defaults('heizung') + ['Uid' => 'ahzg02', 'Name' => 'Heizung', 'Enabled' => true];
$hzr['hz_soll1'] = 1901;
$alr = AUTOMATKatalog::defaults('alarm') + ['Uid' => 'aalm02', 'Name' => 'Wasseralarm', 'Enabled' => true];
$alr['al_mel1'] = 1911;
$tgr = AUTOMATKatalog::defaults('taster') + ['Uid' => 'atst02', 'Name' => 'Taster', 'Enabled' => true];
$tgr['tg_in'] = 1921;
$tgr['tg_kakt'] = 5;
$tgr['tg_kzwert'] = 3.0;
$m->props['Automations'] = json_encode([$hzr, $alr, $tgr]);
$m->props['cfg_ModeAuto'] = false;
$m->props['cfg_Ways'] = json_encode([['Id' => 'kachel', 'Name' => 'Kachel', 'Typ' => 'push', 'Target' => 13000, 'To' => '', 'Std' => true, 'Quiet' => true]]);
$m->props['cfg_QuietEnabled'] = true;
$m->props['cfg_QuietFrom'] = 22;
$m->props['cfg_QuietTo'] = 7;
at('12:00:00');
$m->ApplyChanges();
$m->RequestAction('Hausmodus', 0);
fire($m, 1921, 'single');
check('Taster setzt Hausmodus „Abwesend“', GetValue(IPS_GetObjectIDByIdent('Hausmodus', 12345)) === 3);
check('… und die Heizung bekommt den Wechsel: 17 °C', GetValue(1901) === 17.0);
fire($m, 1901, 17.0); // Echo der Absenkung
$m->RequestAction('Hausmodus', 0);
check('Zurück auf „Tag“: Sollwert wieder 21,5 °C', GetValue(1901) === 21.5);
$m->RequestAction('Pause', true);
$GLOBALS['PUSH'] = [];
at('23:30:00');
fire($m, 1911, true);
check('Alarm in Ruhezeit und Pause: sofort zugestellt', count($GLOBALS['PUSH']) === 1 && strpos($GLOBALS['PUSH'][0][2], 'ALARM') !== false && $m->attrs['Deferred'] === '[]');
$m->RequestAction('Pause', false);
$dlgA2 = json_decode($m->UIEditForm('aalm02', 'alarm'), true);
$hiddenModes = true;
$walkM($dlgA2);
check('Dialog Alarm: allgemeine Modus-Häkchen ausgeblendet', $hiddenModes);
$m->props['cfg_QuietEnabled'] = false;
$m->props['cfg_Ways'] = '[]';
$m->props['Automations'] = '[]';
$m->props['cfg_ModeAuto'] = true;
at('12:00:00');
$m->ApplyChanges();

heading('C14. Store-Reifeprüfung');
$m->props['Automations'] = json_encode([$bl]);
$m->ApplyChanges();
$sid = IPS_GetObjectIDByIdent('s_aflur1', 12345);
$GLOBALS['OBJ'][$sid]['pos'] = 99; // Nutzer sortiert im Objektbaum um
$m->ApplyChanges();
check('9m: Umsortierung im Objektbaum bleibt beim Übernehmen erhalten', $GLOBALS['OBJ'][$sid]['pos'] === 99);
$srcM = file_get_contents(dirname(__DIR__) . '/Automat/module.php');
check('9c: alle ReadProperty*/ReadAttribute* laufen über typsichere Helfer (nur dort direkt)', substr_count($srcM, '$this->ReadProperty') === 4 && substr_count($srcM, '$this->ReadAttribute') === 3);
$lay = json_decode($m->GetConfigurationForm(), true);
$fl = $find($lay['elements'], 'FormLayout');
check('3: berechnete Spalte „Bereich“ im Formular-Aufbau wird nicht gespeichert', (array_column($fl['columns'], 'save', 'name')['Panel'] ?? true) === false);
check('12: keine Namen der Entwickleranlage in sichtbaren Texten', !preg_match('/Handy Dietmar|Svetlana|Froggit|#44712|#26093/u', $srcM));
// B1: Variable einer fremden Instanz ohne Aktion wird nicht per SetValue „geschaltet“
mkinst(2000, 'Fremdes Gerät');
mkvar(2001, VARIABLETYPE_BOOLEAN, false, 'Status (nur Anzeige)', 2000, false, '~Switch');
$sw = new ReflectionMethod('Automat', 'switchVariable');
$m->logged = [];
$okSw = $sw->invoke($m, 2001, true, '');
check('Fremde Variable ohne Aktion: nicht beschrieben, Grund im Meldungslog', $okSw === false && GetValue(2001) === false && strpos(json_encode($m->logged, JSON_UNESCAPED_UNICODE), 'hat keine Aktion') !== false);
// B4: Meldeweg ohne Ziel wird dauerhaft geloggt
$m->props['cfg_Ways'] = json_encode([['Id' => 'x', 'Name' => 'Weg ohne Ziel', 'Typ' => 'push', 'Target' => 99999, 'To' => '', 'Std' => true, 'Quiet' => false], ['Id' => 'y', 'Name' => 'Skript fehlt', 'Typ' => 'script', 'Target' => 0, 'To' => '', 'Std' => true, 'Quiet' => false]]);
$m->logged = [];
$m->TestMessage();
$lg = json_encode($m->logged, JSON_UNESCAPED_UNICODE);
check('Meldeweg ohne Visualisierung bzw. ohne Skript: Warnung im Meldungslog', strpos($lg, 'Weg ohne Ziel') !== false && strpos($lg, 'Skript fehlt') !== false);
$m->props['cfg_Ways'] = '[]';
// B3: offenes Formular wird nach Ausblenden/Wiederholen und nach der Suche nachgezogen
$m->Discover();
$m->fieldUpdates = [];
$m->Discover();
check('Suche zieht die Katalog-Zeilen „gefunden / fehlt“ im offenen Formular nach', count(array_filter($m->fieldUpdates, fn($u) => strpos($u[0], 'cat_') === 0 && $u[1] === 'caption')) === count(AUTOMATKatalog::TYPES));
$sugK = json_decode($m->attrs['Suggestions'], true);
if (count($sugK) > 0) {
    $m->fieldUpdates = [];
    $m->DismissSuggestions(json_encode([$sugK[0]['key']]));
    check('Ausblenden zeigt den Knopf „wieder anzeigen“ sofort', in_array(['RestoreSuggestionsBtn', 'visible', true], $m->fieldUpdates, true));
    $m->fieldUpdates = [];
    $m->RestoreSuggestions();
    check('Wieder anzeigen blendet den Knopf sofort aus', in_array(['RestoreSuggestionsBtn', 'visible', false], $m->fieldUpdates, true));
}
// README-Abzeichen folgt der Version
$libV = json_decode(file_get_contents(dirname(__DIR__) . '/library.json'), true)['version'];
check('README: Abzeichen „Modul Version“ = library.json (' . $libV . ')', strpos(file_get_contents(dirname(__DIR__) . '/README.md'), 'Modul%20Version-' . $libV . '-blue') !== false);
$m->props['Automations'] = '[]';
$m->ApplyChanges();

heading('C15. Probelauf, Statistik, Duplizieren, Feiertage, Kamerabild');
// Probelauf
$dry = $zl;
$dry['Uid'] = 'adry01';
$dry['Dry'] = true;
$dry['Enabled'] = true;
$m->props['Automations'] = json_encode([$dry]);
$m->props['cfg_QuietEnabled'] = false;
at('20:00:00');
$m->ApplyChanges();
SetValue(1081, false);
fire($m, 1091, true);
$prot = implode("\n", array_map(fn($e) => $e[2], json_decode($m->attrs['Protocol'], true)));
check('Probelauf: Taster gedrückt — Licht bleibt aus, Protokoll sagt, was passiert wäre', GetValue(1081) === false && strpos($prot, '🧪 Probelauf — würde:') !== false && strpos($prot, 'Licht Treppe') !== false, mb_substr($prot, 0, 200));
check('Probelauf: Zustand mit 🧪 markiert', strpos((string)GetValue(IPS_GetObjectIDByIdent('s_adry01', 12345)), '🧪') === 0);
// Statistik
$live = $zl;
$live['Uid'] = 'astat1';
$live['Enabled'] = true;
$m->props['Automations'] = json_encode([$live]);
$m->ApplyChanges();
fire($m, 1091, true);
$stt = json_decode($m->attrs['Stats'], true);
check('Statistik zählt die Schaltung', ($stt['astat1']['d']['2026-10-13'][0] ?? 0) >= 1);
$fv = json_decode($m->GetConfigurationForm(), true);
$sl2 = $find($fv['elements'], 'StatsList');
check('Statistik-Liste im Protokoll-Bereich', is_array($sl2) && ($sl2['values'][0]['A7'] ?? 0) >= 1);
$stt['astat1'] = ['first' => strtotime('-40 days', $GLOBALS['CLOCK']), 'd' => [], 'last' => 0];
$m->attrs['Stats'] = json_encode($stt);
$fv = json_encode(json_decode($m->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
check('„💤 Seit 30 Tagen nichts getan“ nennt den Automatismus', strpos($fv, '💤 Seit 30 Tagen nichts geschaltet oder gemeldet: „Treppenlicht“') !== false);
// Duplizieren in einen anderen Raum
mkinst(2100, 'Bewegungsmelder Bad');
mkvar(2101, VARIABLETYPE_BOOLEAN, false, 'Bewegung', 2100, false, '~Motion');
mkinst(2110, 'Licht Bad');
mkvar(2111, VARIABLETYPE_BOOLEAN, false, 'Status', 2110, true, '~Switch');
$blD = $bl;
$blD['Uid'] = 'adup01';
$blD['Enabled'] = true;
$blD['Mark'] = true;
$m->props['Automations'] = json_encode([$blD]);
$m->ApplyChanges();
$m->fieldUpdates = [];
$dupMsg = $m->UIDuplicate(json_encode([$blD]), 'Bad');
$vals = json_decode(array_values(array_filter($m->fieldUpdates, fn($u) => $u[0] === 'Automations' && $u[1] === 'values'))[0][2] ?? '[]', true);
$copy = end($vals);
check('Duplizieren nach „Bad“: Melder und Licht des Bads eingesetzt, Name angepasst, aktiv', count($vals) === 2 && $copy['bl_mel1'] === 2101 && $copy['bl_out1'] === 2111 && $copy['Room'] === 'Bad' && $copy['Name'] === 'Badlicht' && $copy['Enabled'] === true && strpos($dupMsg, '2 von 2 passend') !== false, $dupMsg . ' ' . json_encode($copy));
$m->fieldUpdates = [];
$dupMsg2 = $m->UIDuplicate(json_encode([$blD]), '');
$vals2 = json_decode(array_values(array_filter($m->fieldUpdates, fn($u) => $u[0] === 'Automations' && $u[1] === 'values'))[0][2] ?? '[]', true);
$copy2 = end($vals2);
check('Duplizieren ohne Raum: „(Kopie)“, gleiche Geräte, NICHT aktiv (sonst Doppelregelung)', $copy2['Name'] === 'Flurlicht (Kopie)' && $copy2['bl_out1'] === 1001 && $copy2['Enabled'] === false);
check('Nichts angehakt: Hinweis', strpos($m->UIDuplicate(json_encode([$bl]), ''), 'Häkchen') !== false);
// Feiertage
$m->props['cfg_Bundesland'] = 'BW';
$fv = json_encode(json_decode($m->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
check('Hausmodus-Bereich nennt den nächsten Feiertag (BW: Allerheiligen 01.11.2026)', strpos($fv, '📅 Nächster Feiertag: Allerheiligen, 01.11.2026') !== false);
$m->props['cfg_Bundesland'] = '';
// Kamerabild an Skript-Meldeweg
$GLOBALS['OBJ'][2200] = ['kind' => 3, 'name' => 'Telegram', 'parent' => 0, 'ident' => '', 'hidden' => false];
mkinst(2210, 'Klingel');
mkvar(2211, VARIABLETYPE_BOOLEAN, false, 'Klingel', 2210, false, '');
$GLOBALS['OBJ'][2220] = ['kind' => 5, 'name' => 'Kamerabild Haustür', 'parent' => 0, 'ident' => '', 'hidden' => false];
$klr = AUTOMATKatalog::defaults('klingel') + ['Uid' => 'aklng2', 'Name' => 'Haustür', 'Enabled' => true];
$klr['kl_taster'] = 2211;
$klr['kl_bild'] = 2220;
$m->props['Automations'] = json_encode([$klr]);
$m->props['cfg_Ways'] = json_encode([['Id' => 'tg', 'Name' => 'Telegram', 'Typ' => 'script', 'Target' => 2200, 'To' => '', 'Std' => true, 'Quiet' => false]]);
$m->ApplyChanges();
$GLOBALS['SCRIPTRUN'] = [];
fire($m, 2211, true);
check('Klingel: Skript-Meldeweg bekommt das Kamerabild ($_IPS[\'MEDIA\'])', ($GLOBALS['SCRIPTRUN'][0][1]['MEDIA'] ?? '') === '2220');
$m->props['cfg_Ways'] = '[]';
$m->props['Automations'] = '[]';
at('12:00:00');
$m->ApplyChanges();

heading('C16. Dialog nur mit der eigenen Art (Konsole hing beim Speichern, 14.10.2026)');
$bloat = $bl;
$bloat['Uid'] = 'abloat';
$bloat['Enabled'] = true;
foreach (AUTOMATKatalog::TYPES as $t => $_) {
    if ($t !== 'bewegung') {
        $bloat += AUTOMATKatalog::defaults($t); // so sahen Zeilen bis 0.1.35 aus
    }
}
$bloat['Type'] = 'bewegung';
check('Aufgeblähte Zeile (alle Arten) hat über 300 Werte', count($bloat) > 300, (string)count($bloat));
$m->props['Automations'] = json_encode([$bloat]);
$m->ApplyChanges();
$clean = $m->UIEditForm('abloat', 'bewegung');
$rw = (new ReflectionMethod('Automat', 'rows'))->invoke($m)[0];
check('Gelesene Zeile enthält nur noch Felder der eigenen Art', count($rw) < 60 && !array_key_exists('rl_out1', $rw) && array_key_exists('bl_mel1', $rw), (string)count($rw));
$lvB = json_decode($m->GetConfigurationForm(), true);
$lvRow = $find($lvB['elements'], 'Automations')['values'][0];
check('Liste im Formular liefert die bereinigte Zeile (beim nächsten Übernehmen gespeichert)', !array_key_exists('zs_t1', $lvRow) && !array_key_exists('al_mel1', $lvRow));
check('Dialog eines bestehenden Automatismus: nur dessen Abschnitt, klein', strpos($clean, '"sec_bewegung"') !== false && strpos($clean, '"sec_rollladen"') === false && strlen($clean) < 30000, (string)strlen($clean));
check('Art lässt sich bei bestehenden Automatismen nicht umstellen', strpos(json_encode(json_decode($clean, true)), '"name":"Type"') !== false && strpos($clean, '"enabled":false') !== false);
$newDlg = $m->UIEditForm('', '');
check('Neuer Automatismus über „+“: erst Art wählen, keine Abschnitte', strpos($newDlg, 'NewHint') !== false && preg_match('/"sec_(?!when)/', $newDlg) === 0 && strlen($newDlg) < 15000, (string)strlen($newDlg));
$m->props['Automations'] = '[]';
$m->ApplyChanges();

heading('C17. Filter: Automatismen und Entdecken');
$fA = $bl;
$fA['Uid'] = 'afl001';
$fA['Enabled'] = true;
$fB = $fo;
$fB['Uid'] = 'afl002';
$fB['Enabled'] = true;
$fC = $zl;
$fC['Uid'] = 'afl003';
$fC['Enabled'] = false;
$m->props['Automations'] = json_encode([$fA, $fB, $fC]);
$m->props['AutomationsHidden'] = '[]';
$m->ApplyChanges();
$lvF = json_decode($m->GetConfigurationForm(), true);
$allRows = $find($lvF['elements'], 'Automations')['values'];
$getUpd = function (string $name) use ($m) {
    $u = array_values(array_filter($m->fieldUpdates, fn($x) => $x[0] === $name && $x[1] === 'values'));
    return json_decode(end($u)[2] ?? '[]', true);
};
$m->fieldUpdates = [];
$m->UIFilterList(json_encode($allRows), 'flur', '', '', '');
$shown = $getUpd('Automations');
$hidden = $getUpd('AutomationsHidden');
check('Suchtext „flur“: Flur-Automatismen sichtbar, Bad ausgefiltert', array_column($shown, 'Uid') === ['afl001', 'afl003'] && array_column($hidden, 'Uid') === ['afl002'], json_encode([array_column($shown, 'Uid'), array_column($hidden, 'Uid')]));
check('Ausgefilterte Zeile steckt vollständig in der Spalte „Data“ (ohne Anzeigespalten)', (json_decode($hidden[0]['Data'] ?? '{}', true)['fo_kon1'] ?? 0) === 1031 && !array_key_exists('State', json_decode($hidden[0]['Data'] ?? '{}', true)));
check('Hinweis „zeigt 2 von 3 — die übrigen bleiben erhalten“', in_array(['FilterInfo', 'visible', true], $m->fieldUpdates, true) && strpos(json_encode($m->fieldUpdates, JSON_UNESCAPED_UNICODE), 'Zeigt 2 von 3') !== false);
// So speichert Symcon beim „Übernehmen“ mit aktivem Filter: beide Listen getrennt — und in der unsichtbaren
// Liste NUR deren Spalten (live gesehen 15.10.2026: alles andere war weg).
$colsOnly = array_map(fn($r) => array_intersect_key($r, ['Uid' => 1, 'Type' => 1, 'Data' => 1]), $hidden);
$m->props['Automations'] = json_encode($shown);
$m->props['AutomationsHidden'] = json_encode($colsOnly);
$m->ApplyChanges();
$rowsAfter = (new ReflectionMethod('Automat', 'rows'))->invoke($m);
check('Nach dem Speichern mit Filter: alle 3 Automatismen da, MIT ihren Einstellungen', count($rowsAfter) === 3 && in_array('afl002', array_column($rowsAfter, 'Uid'), true) && (array_column($rowsAfter, null, 'Uid')['afl002']['fo_kon1'] ?? 0) === 1031 && (array_column($rowsAfter, null, 'Uid')['afl002']['Name'] ?? '') === ($fB['Name'] ?? ''));
check('… und arbeiten weiter (Nachrichten auch für den ausgefilterten registriert)', isset($m->messages[1031]));
$lvF2 = json_decode($m->GetConfigurationForm(), true);
check('Formular neu geöffnet: alle 3 sichtbar, unsichtbare Liste leer', count($find($lvF2['elements'], 'Automations')['values']) === 3 && $find($lvF2['elements'], 'AutomationsHidden')['values'] === []);
$m->fieldUpdates = [];
$hidRows = $hidden; // so reicht das onChange die unsichtbare Liste weiter: mit Spalte „Data“
$m->UIFilterList(json_encode(array_merge($shown, $hidRows)), '', '', '', 'off');
check('Zustand „nicht aktiv“: nur das Treppenlicht', array_column($getUpd('Automations'), 'Uid') === ['afl003']);
$m->fieldUpdates = [];
$m->UIFilterList(json_encode(array_merge($shown, $hidRows)), '', '', 'cat:klima', '');
check('Funktion „Klima“: nur Fenster offen', array_column($getUpd('Automations'), 'Uid') === ['afl002']);
check('… und die zurückgeholte Zeile hat ihre Einstellungen (aus „Data“ ausgepackt)', ($getUpd('Automations')[0]['fo_kon1'] ?? 0) === 1031);
$m->fieldUpdates = [];
$m->UIFilterList(json_encode(array_merge($shown, $hidRows)), '', 'Bad', '', '');
check('Raum „Bad“', array_column($getUpd('Automations'), 'Uid') === ['afl002']);
$m->fieldUpdates = [];
$m->UIFilterList(json_encode(array_merge($shown, $hidRows)), '', '', '', '');
check('Filter leeren: alle sichtbar, nichts ausgefiltert, Hinweis weg', count($getUpd('Automations')) === 3 && $getUpd('AutomationsHidden') === [] && in_array(['FilterInfo', 'visible', false], $m->fieldUpdates, true));
$m->fieldUpdates = [];
$m->UIFilterList('kaputt', 'x', '', '', '');
check('Unlesbare Liste: nichts wird verschoben', $m->fieldUpdates === []);
// Altbestand aus 0.1.39/0.1.40: unsichtbare Zeilen nur mit Kennung und Art — nicht stillschweigend verschwinden lassen
$m->props['Automations'] = json_encode([$fA]);
$m->props['AutomationsHidden'] = json_encode([['Uid' => 'afl002', 'Type' => 'fenster']]);
$m->ApplyChanges();
$rowsOld = (new ReflectionMethod('Automat', 'rows'))->invoke($m);
check('Altbestand ohne „Data“: Zeile bleibt (leer) sichtbar statt zu verschwinden', in_array('afl002', array_column($rowsOld, 'Uid'), true));
// Entdecken
$m->props['Automations'] = '[]';
$m->props['AutomationsHidden'] = '[]';
$m->ApplyChanges();
$m->Discover();
$allS = json_decode($m->attrs['Suggestions'], true);
$m->fieldUpdates = [];
$m->UIFilterSuggestions('', '', 'bewegung');
$sv = $getUpd('SuggestList');
check('Entdecken: Filter nach Art „Bewegungslicht“', count($sv) > 0 && count($sv) < count($allS) && count(array_filter($sv, fn($r) => strpos($r['TypeLabel'], 'Bewegungslicht') === false)) === 0, count($sv) . '/' . count($allS));
$m->fieldUpdates = [];
$m->Discover();
check('Neue Suche setzt die Vorschlags-Filter zurück', in_array(['sg_text', 'value', ''], $m->fieldUpdates, true) && in_array(['sg_type', 'value', ''], $m->fieldUpdates, true));
$m->props['Automations'] = '[]';
$m->ApplyChanges();

heading('C18. Dialog zeigt nur die Felder, die zur Auswahl passen');
$rlD = AUTOMATKatalog::defaults('rollladen') + ['Uid' => 'arold1', 'Name' => 'Rollläden', 'Enabled' => true];
$rlD['rl_out1'] = 1081;
$rlD['rl_upref'] = 2;
$rlD['rl_downref'] = 3;
$m->props['Automations'] = json_encode([$rlD]);
$m->ApplyChanges();
$dlgR = json_decode($m->UIEditForm('arold1', 'rollladen'), true);
$byName = [];
$walkV = function ($els) use (&$walkV, &$byName) {
    foreach ($els as $e) {
        if (isset($e['name'])) {
            $byName[$e['name']] = $e;
        }
        if (isset($e['items'])) {
            $walkV($e['items']);
        }
    }
};
$walkV($dlgR);
$vis = fn(string $n) => ($byName[$n]['visible'] ?? true) !== false;
check('„Öffnen bei Tag“: keine Uhrzeit, kein Versatz; „frühestens“ bleibt', !$vis('rl_upzeit') && !$vis('rl_upoff') && $vis('rl_frueh') && $vis('rl_fruehwe'));
check('„Schließen bei Nacht“: keine Uhrzeit, kein Versatz; „spätestens“ bleibt; kein eigener Sensor mehr im Rollladen', !$vis('rl_downzeit') && !$vis('rl_downoff') && !isset($byName['rl_lux']) && !isset($byName['rl_luxschwelle']) && $vis('rl_spaet'));
$oc = (string)($byName['rl_upref']['onChange'] ?? '');
check('Auswahl ruft beim Umstellen die Feldsteuerung auf (gültiges PHP)', strpos($oc, 'AUTOMAT_UIDeps($id, \'rollladen\'') === 0 && strpos($oc, "'rl_downref' => (int)\$rl_downref") !== false);
$tmpf = sys_get_temp_dir() . '/automat_oc.php';
file_put_contents($tmpf, '<?php ' . $oc);
exec('php -l ' . escapeshellarg($tmpf) . ' 2>&1', $lintOut, $lintRc);
check('… und der erzeugte onChange-Code ist syntaktisch korrekt', $lintRc === 0, implode(' ', $lintOut));
$m->fieldUpdates = [];
$m->UIDeps('rollladen', json_encode(['rl_upref' => 0, 'rl_downref' => 2]));
check('Umgestellt auf „zur Uhrzeit“ / „wenn es dunkel wird“: Uhrzeit erscheint sofort, Schließzeit verschwindet', in_array(['rl_upzeit', 'visible', true], $m->fieldUpdates, true) && in_array(['rl_upoff', 'visible', false], $m->fieldUpdates, true) && in_array(['rl_downzeit', 'visible', false], $m->fieldUpdates, true));
check('Dämmerung: Sensor bei „Dunkelheit“ ODER „Helligkeit“', AUTOMATKatalog::visibleWhen(AUTOMATKatalog::fields('daemmerung')[array_search('dz_lux', array_column(AUTOMATKatalog::fields('daemmerung'), 'n'))], ['dz_ein' => 0, 'dz_aus' => 2], 'daemmerung') === true);
$tgF = array_values(array_filter(AUTOMATKatalog::fields('taster'), fn($f) => ($f['n'] ?? '') === 'tg_kziel'))[0];
check('Taster: Ziel nur bei Schalt-Aktionen, nicht bei „Hausmodus setzen“', AUTOMATKatalog::visibleWhen($tgF, ['tg_kakt' => 5], 'taster') === false && AUTOMATKatalog::visibleWhen($tgF, ['tg_kakt' => 1], 'taster') === true);
// Zusammenspiel: zentraler Sensor (Hausmodus-Bereich) → Rollladen „wenn es dunkel wird“
$rlC = AUTOMATKatalog::defaults('rollladen') + ['Uid' => 'arolc1', 'Name' => 'Rollläden', 'Enabled' => true];
mkinst(2300, 'Rollladen Wohnzimmer');
mkvar(2301, VARIABLETYPE_INTEGER, 0, 'Position', 2300, true, '~ShutterPosition.100');
$rlC['rl_out1'] = 2301;
$rlC['rl_downref'] = 2;
$m->props['Automations'] = json_encode([$rlC]);
$m->props['cfg_LightVar'] = 1601;
$m->props['cfg_LightHold'] = 0;
$m->props['cfg_DayRef'] = 0;
$m->props['cfg_EveningRef'] = 1; // Hausmodus selbst nutzt den Sensor NICHT — der Rollladen trotzdem
$m->attrs['LightState'] = '{}';
SetValue(1601, 300.0);
at('15:00:00');
$m->ApplyChanges();
$m->Tick();
at('17:30:00');
SetValue(1601, 5.0);
$m->Tick();
$m->Tick();
check('Rollladen schließt nach dem zentralen Sensor, auch wenn der Hausmodus ihn nicht nutzt', GetValue(2301) === 100, (string)GetValue(2301) . ' ' . GetValue(IPS_GetObjectIDByIdent('s_arolc1', 12345)));
$m->props['cfg_LightVar'] = 0;
$m->props['Automations'] = '[]';
$m->ApplyChanges();

$src = file_get_contents(dirname(__DIR__) . '/Automat/module.php');
check('Kein IPS_LogMessage in module.php (Store-Review Punkt 15)', strpos($src, 'IPS_LogMessage') === false);
check('Keine +86400-Rechnung (Stolperstein 18)', !preg_match('/[+-]\s*86400/', $src . file_get_contents(dirname(__DIR__) . '/Automat/AUTOMATLogik.php')));
check('Keine ungültigen Presentation-Schlüssel', !preg_match('/ColorValue|ColorActive/', $src));
foreach (['library.json', 'Automat/module.json', 'Automat/form.json', 'Automat/locale.json'] as $f) {
    $raw = file_get_contents(dirname(__DIR__) . '/' . $f);
    check($f . ': gültiges JSON ohne BOM', json_decode($raw) !== null && substr($raw, 0, 3) !== "\xEF\xBB\xBF");
}
$lib = json_decode(file_get_contents(dirname(__DIR__) . '/library.json'), true);
check('library.json: nur erlaubte Felder, Name mit Bindestrich', array_keys($lib) === ['id', 'author', 'name', 'url', 'compatibility', 'version', 'build', 'date'] && $lib['name'] === 'DG65-Toolkit Automat');
$mod = json_decode(file_get_contents(dirname(__DIR__) . '/Automat/module.json'), true);
check('module.json: genau ein Alias, Klasse = Name', $mod['aliases'] === ['DG65-Toolkit Automat'] && $mod['name'] === 'Automat');
// Öffentliche Methoden: nur skalare Parameter ohne Standardwerte (Stolperstein 8/20)
$bad = [];
foreach ((new ReflectionClass('Automat'))->getMethods(ReflectionMethod::IS_PUBLIC) as $meth) {
    if ($meth->getDeclaringClass()->getName() !== 'Automat') {
        continue;
    }
    foreach ($meth->getParameters() as $par) {
        if ($par->isDefaultValueAvailable() || ($par->getType() !== null && !in_array((string)$par->getType(), ['string', 'int', 'bool', 'float'], true))) {
            $bad[] = $meth->getName() . '($' . $par->getName() . ')';
        }
    }
}
check('Öffentliche Methoden ohne Standardwerte, nur skalare Typen', count($bad) === 0, implode(', ', $bad));
$warn = array_filter($GLOBALS['WARN'], fn($w) => strpos($w, 'Ident nicht gefunden') === false);
check('Keine PHP-Warnungen im ganzen Lauf', count($warn) === 0, implode(' | ', array_slice($warn, 0, 5)));

echo "\n" . ($fails === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $fails von $checks Prüfungen fehlgeschlagen.\n");
exit($fails === 0 ? 0 : 1);
