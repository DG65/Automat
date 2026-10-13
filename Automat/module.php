<?php

declare(strict_types=1);

// ===========================================================================
// Automat (DG65-Toolkit) — alle kleinen Standard-Automatismen eines Hauses in
// EINER Instanz: Zeitlicht (Treppenlicht), Bewegungslicht, Dämmerungs- und
// Zeitschaltung, Fenster offen → Heizung runter, Wächter „bleibt zu lange
// so" und Gerät fertig.
//
// WAS ES ANDERS MACHT ALS EINZEL-INSTANZEN JE AUTOMATIK:
//   Übersicht  — eine Liste mit Zustand und Grund je Automatismus, eine
//                Kachel, ein Protokoll „warum ging das Licht an?“.
//   Entdecken  — sucht im Objektbaum nach Meldern, Lichtern, Kontakten,
//                Thermostaten und Leistungsmessungen, gruppiert sie nach Raum
//                (Raumstruktur, falls installiert, sonst nach Gerätenamen) und
//                schlägt passende Automatismen vor. Angelegt wird nur, was der
//                Nutzer bestätigt.
//   Querschnitt — für alle Typen gleich: Sperre (sichtbar als Variable),
//                globale Pause, Hausmodus (Tag/Abend/Nacht/Abwesend/Urlaub),
//                Symcon-Bedingungen, Respekt vor Handbedienung.
//
// Aufbau: Die Entscheidungen fallen ausschließlich in AUTOMATLogik (ohne
// Symcon testbar), die Typen stehen in AUTOMATKatalog, die Erkennung in
// AUTOMATErkennung. Dieses Modul sammelt Werte, schaltet, meldet und zeigt an.
//
// Eigenständig: setzt kein anderes Modul voraus. Raumstruktur (Räume) und die
// Location-Control-Instanz (Sonnenauf-/-untergang) werden genutzt, wenn
// vorhanden.
// ===========================================================================

require_once __DIR__ . '/AUTOMATKatalog.php';
require_once __DIR__ . '/AUTOMATLogik.php';
require_once __DIR__ . '/AUTOMATErkennung.php';

class Automat extends IPSModule
{
    private const LIBRARY_GUID = '{D7A3CB8F-E43B-4307-AB64-BAD160C1823A}';
    private const MODULE_GUID  = '{A92CA7FD-B746-45C4-902B-3CBAE1F3FA27}';

    // Symcon-Kernmodul Location Control (Idents Sunrise/Sunset, live verifiziert an IPS 9.0 durch die Anwesenheitssimulation, vormals Schein).
    private const LOCATION_CONTROL_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';
    // DG65-Toolkit Raumstruktur (vormals Raumstruktur; Räume/Etagen), Vertrag RAUM_/STRUKT_GetStructure 1.x — optional.
    private const RAUM_GUID = '{CA700334-0982-F356-0617-6952868137E9}';
    // Push-Ziele (SUITE.md Stolperstein 22): klassisches WebFront und Kachel-Visualisierung.
    private const WEBFRONT_GUID = '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}';
    private const VISU_GUID     = '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}';

    // „Neu bis Version“: erst ab der ersten veröffentlichten Version gepflegt
    // (Toolkit-Präzedenzfall Batteriewächter) — bis dahin steht die Geschichte im CHANGELOG.
    private const NEWS_VERSIONS = [];
    private const REPO_URL    = 'https://github.com/DG65/Automat';
    private const LICENSE_URL = 'https://github.com/DG65/Automat/blob/main/LICENSE';
    private const PAYPAL_URL  = 'https://paypal.me/DietmarGureth';

    private const PROFILE_MODE = 'AUTOMAT.Hausmodus';
    // onClick-Vorspann: Schlüssel der angehakten Vorschläge aus der offenen Liste lesen (Zeilen per Array-Zugriff).
    private const PICKED_KEYS = '$k = []; foreach ($SuggestList as $x) { if (!empty($x[\'Pick\'])) { $k[] = (string)$x[\'Key\']; } }';
    private const MAX_TICK_S   = 60;   // spätestens minütlich nachsehen (Dämmerung, Hausmodus, Wächter)
    private const REST_TICK_S  = 5;    // so oft wird „… · Restzeit" nachgeführt, solange unter einer Stunde läuft
    private const ECHO_S       = 15;   // so lange gilt eine Wertänderung als Echo des eigenen Befehls
    private const PROTOCOL_MAX = 200;

    /** Pro Aufruf zwischengespeicherte Instanz-Konfigurationen (Doppelregelungs-Prüfung). */
    private ?array $confCache = null;

    /** Raumnamen aus Raumstruktur (für Zuordnung über Gerätenamen), je Suche gefüllt. */
    private array $roomLabels = [];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Automations', '[]');
        $this->RegisterPropertyBoolean('cfg_ModeAuto', false);
        $this->RegisterPropertyString('cfg_DayFrom', AUTOMATKatalog::time(6, 30));
        $this->RegisterPropertyInteger('cfg_EveningRef', 0);
        $this->RegisterPropertyString('cfg_EveningFrom', AUTOMATKatalog::time(18, 0));
        $this->RegisterPropertyString('cfg_NightFrom', AUTOMATKatalog::time(22, 30));
        $this->RegisterPropertyInteger('cfg_PresenceVar', 0);
        $this->RegisterPropertyBoolean('cfg_PresenceInvert', false);
        $this->RegisterPropertyBoolean('cfg_TileLight', false);
        $this->RegisterPropertyInteger('cfg_ListOrder', 0);
        $this->RegisterPropertyInteger('cfg_SuggestOrder', 0);

        $this->RegisterAttributeString('States', '{}');
        $this->RegisterAttributeString('Status', '{}');
        $this->RegisterAttributeString('Due', '{}');
        $this->RegisterAttributeString('End', '{}');
        $this->RegisterAttributeString('Sent', '{}');
        $this->RegisterAttributeString('Protocol', '[]');
        $this->RegisterAttributeString('Suggestions', '[]');
        $this->RegisterAttributeString('Dismissed', '[]');
        $this->RegisterAttributeString('RoleCounts', '');
        $this->RegisterAttributeString('RoomSource', '');
        $this->RegisterAttributeInteger('LastDiscoveryTs', 0);
        $this->RegisterAttributeInteger('LastAutoMode', -1);
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);

        $this->RegisterTimer('Tick', 0, 'AUTOMAT_Tick($_IPS[\'TARGET\']);');
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->ensureModeProfile();
        $this->MaintainVariable('Hausmodus', 'Hausmodus', VARIABLETYPE_INTEGER, self::PROFILE_MODE, 1, true);
        $this->EnableAction('Hausmodus');
        $this->MaintainVariable('Pause', 'Alle Automatismen pausieren', VARIABLETYPE_BOOLEAN, '~Switch', 2, true);
        $this->EnableAction('Pause');
        $this->MaintainVariable('LastMessage', 'Letzte Meldung', VARIABLETYPE_STRING, '', 3, true);

        foreach ($this->GetMessageList() as $sender => $messages) {
            foreach ($messages as $message) {
                if ($sender !== 0) {
                    $this->UnregisterMessage($sender, $message);
                }
            }
        }

        $rows = $this->rows();
        $keep = [];
        $enabled = 0;
        foreach ($rows as $i => $row) {
            $uid = $row['Uid'];
            $name = $this->rowName($row);
            $pos = 10 + $i * 4;
            $this->ensureVar('s_' . $uid, $name . ' · Status', VARIABLETYPE_STRING, '', $pos);
            $this->ensureVar('r_' . $uid, $name . ' · Restzeit', VARIABLETYPE_STRING, '', $pos + 1);
            $this->ensureVar('l_' . $uid, $name . ' · gesperrt', VARIABLETYPE_BOOLEAN, '~Switch', $pos + 2);
            $this->EnableAction('l_' . $uid);
            $keep['s_' . $uid] = $keep['r_' . $uid] = $keep['l_' . $uid] = true;
            if (!empty(AUTOMATKatalog::TYPES[$row['Type']]['light']) && $row['Type'] !== 'daemmerung') {
                $this->ensureVar('d_' . $uid, $name . ' · Dauerlicht', VARIABLETYPE_BOOLEAN, '~Switch', $pos + 3);
                $this->EnableAction('d_' . $uid);
                $keep['d_' . $uid] = true;
            }
            if (empty($row['Enabled'])) {
                continue;
            }
            $enabled++;
            foreach (AUTOMATKatalog::variables($row) as $role => $list) {
                if ($role === 'read') {
                    continue;
                }
                foreach ($list as $vid) {
                    if (IPS_VariableExists($vid)) {
                        $this->RegisterMessage($vid, VM_UPDATE);
                    }
                }
            }
        }
        $presence = $this->ReadPropertyInteger('cfg_PresenceVar');
        if ($presence > 0 && IPS_VariableExists($presence)) {
            $this->RegisterMessage($presence, VM_UPDATE);
        }

        // Variablen entfernter Automatismen aufräumen (nur eigene Idents).
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $ident = (string)(IPS_GetObject($cid)['ObjectIdent'] ?? '');
            if (preg_match('/^[sldr]_[a-z0-9]+$/', $ident) && !isset($keep[$ident]) && IPS_VariableExists($cid)) {
                IPS_DeleteVariable($cid);
            }
        }
        // Zustände entfernter Automatismen vergessen.
        $uids = array_column($rows, 'Uid');
        foreach (['States', 'Status', 'Due', 'End'] as $attr) {
            $map = $this->attrMap($attr);
            $this->WriteAttributeString($attr, json_encode(array_intersect_key($map, array_flip($uids))));
        }

        $this->SetStatus($enabled > 0 ? 102 : 104);
        foreach ($rows as $row) {
            $this->process($row, ['k' => 'flag']);
        }
        $this->evaluateMode(false);
        $this->reschedule();
        $this->refreshTile();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE) {
            if (($Data[0] ?? null) === KR_READY) {
                $this->ApplyChanges();
            }
            return;
        }
        if ($Message !== VM_UPDATE || IPS_GetKernelRunlevel() !== KR_READY || !IPS_InstanceExists($this->InstanceID)) {
            return;
        }
        $vid = (int)$SenderID;
        $value = $Data[0] ?? null;
        if ($vid === $this->ReadPropertyInteger('cfg_PresenceVar')) {
            $this->evaluateMode(true);
        }
        $own = $this->consumeEcho($vid, $value);
        $touched = false;
        foreach ($this->rows() as $row) {
            if (empty($row['Enabled'])) {
                continue;
            }
            $vars = AUTOMATKatalog::variables($row);
            if (in_array($vid, $vars['in'], true)) {
                $this->process($row, ['k' => 'trigger', 'vid' => $vid, 'v' => $value]);
                $touched = true;
            }
            if (in_array($vid, $vars['out'], true)) {
                $this->process($row, ['k' => 'output', 'vid' => $vid, 'v' => $value, 'own' => $own]);
                $touched = true;
            }
        }
        if ($touched) {
            $this->reschedule();
            $this->refreshTile();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        $Ident = (string)$Ident;
        if ($Ident === 'tile') {
            $this->tileAction((string)$Value);
            return;
        }
        if ($Ident === 'Hausmodus') {
            $mode = (int)$Value;
            if (!isset(AUTOMATKatalog::MODES[$mode])) {
                throw new Exception('Unbekannter Hausmodus: ' . $mode);
            }
            $this->setMode($mode, 'von Hand');
        } elseif ($Ident === 'Pause') {
            $this->SetValue('Pause', (bool)$Value);
            $this->addLog('', (bool)$Value ? '⏸ Alle Automatismen pausiert.' : '▶️ Pause beendet — alle Automatismen arbeiten wieder.');
            foreach ($this->rows() as $row) {
                $this->process($row, ['k' => 'flag']);
            }
        } elseif (preg_match('/^([ld])_([a-z0-9]+)$/', $Ident, $m)) {
            $row = $this->rowByUid($m[2]);
            if ($row === null) {
                throw new Exception('Unbekannter Automatismus: ' . $m[2]);
            }
            $this->SetValue($Ident, (bool)$Value);
            $this->addLog($this->rowName($row), $m[1] === 'l'
                ? ((bool)$Value ? '🔒 Gesperrt.' : '🔓 Sperre aufgehoben.')
                : ((bool)$Value ? '🔆 Dauerlicht an.' : 'Dauerlicht aus.'));
            $this->process($row, ['k' => 'flag']);
        } else {
            throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
        $this->reschedule();
        $this->refreshTile();
    }

    // =====================================================================
    //  Laufzeit
    // =====================================================================

    /** Timer-Einstieg: alle Automatismen bekommen ein „tick“. */
    public function Tick(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $this->evaluateMode(false);
        foreach ($this->rows() as $row) {
            $this->process($row, ['k' => 'tick']);
        }
        $this->reschedule();
        $this->refreshTile();
    }

    /** Eine Zeile mit einem Ereignis durch die Logik schicken und das Ergebnis umsetzen. */
    private function process(array $row, array $ev): void
    {
        $uid = $row['Uid'];
        $states = $this->attrMap('States');
        $status = $this->attrMap('Status');
        $due = $this->attrMap('Due');
        if (empty($row['Enabled'])) {
            $status[$uid] = '⚫ Ausgeschaltet (in der Liste)';
            unset($due[$uid]);
            $this->WriteAttributeString('Status', json_encode($status));
            $this->WriteAttributeString('Due', json_encode($due));
            $this->setEnd($uid, null);
            $this->setVarIfChanged('s_' . $uid, $status[$uid]);
            return;
        }
        $problems = AUTOMATKatalog::problems($row, function (int $vid) {
            return IPS_VariableExists($vid);
        });
        if (count($problems) > 0) {
            $status[$uid] = '⚠️ ' . $problems[0];
            unset($due[$uid]);
            $this->WriteAttributeString('Status', json_encode($status));
            $this->WriteAttributeString('Due', json_encode($due));
            $this->setEnd($uid, null);
            $this->setVarIfChanged('s_' . $uid, $status[$uid]);
            return;
        }

        $ctx = $this->context($row);
        $st = is_array($states[$uid] ?? null) ? $states[$uid] : [];
        try {
            $res = AUTOMATLogik::step($row, $st, $ev, $ctx);
        } catch (Throwable $e) {
            $this->LogMessage('Automatismus „' . $this->rowName($row) . '“: Fehler in der Auswertung — ' . $e->getMessage(), KL_ERROR);
            return;
        }
        $states[$uid] = $res['st'];
        $status[$uid] = $res['status'];
        if ($res['due'] !== null) {
            $due[$uid] = (int)$res['due'];
        } else {
            unset($due[$uid]);
        }
        $this->WriteAttributeString('States', json_encode($states));
        $this->WriteAttributeString('Status', json_encode($status));
        $this->WriteAttributeString('Due', json_encode($due));

        $failed = [];
        foreach ($res['cmd'] as [$vid, $value]) {
            if (!$this->switchVariable((int)$vid, $value)) {
                $failed[] = $this->varLabel((int)$vid);
            }
        }
        if ($res['log'] !== null) {
            $this->addLog($this->rowName($row), $res['log'] . (count($failed) > 0 ? ' ⚠️ Schalten fehlgeschlagen: ' . implode(', ', $failed) : ''));
        }
        $push = !empty($row['wa_push']) && $row['Type'] === 'waechter' || !empty($row['gf_push']) && $row['Type'] === 'fertig';
        foreach ($res['msg'] as [$title, $text]) {
            $this->message((string)$title, (string)$text, $push);
        }
        $this->setVarIfChanged('s_' . $uid, $res['status']);
        $this->setEnd($uid, isset($res['end']) ? (int)$res['end'] : null);
    }

    /** Zeitpunkt des nächsten Ereignisses merken und „… · Restzeit" nachführen. */
    private function setEnd(string $uid, ?int $end): void
    {
        $map = $this->attrMap('End');
        if ($end !== null && $end > $this->now()) {
            $map[$uid] = $end;
        } else {
            unset($map[$uid]);
            $end = null;
        }
        $this->WriteAttributeString('End', json_encode($map));
        $this->setVarIfChanged('r_' . $uid, $end === null ? '' : AUTOMATLogik::fmtRest($end, $this->now()));
    }

    /** Schnappschuss der Umgebung für die Logik. */
    private function context(array $row): array
    {
        $uid = $row['Uid'];
        $vars = AUTOMATKatalog::variables($row);
        $val = [];
        $names = [];
        foreach (array_merge($vars['in'], $vars['out'], $vars['read']) as $vid) {
            if (IPS_VariableExists($vid)) {
                $val[$vid] = GetValue($vid);
                $names[$vid] = $this->varLabel($vid);
            }
        }
        $dim = [];
        foreach ($vars['out'] as $vid) {
            if (IPS_VariableExists($vid)) {
                $dim[$vid] = $this->dimMax($vid);
            }
        }
        $ctx = [
            'now'       => $this->now(),
            'mode'      => (int)$this->GetValue('Hausmodus'),
            'paused'    => (bool)$this->GetValue('Pause'),
            'locked'    => (bool)$this->getOwn('l_' . $uid, false),
            'permanent' => (bool)$this->getOwn('d_' . $uid, false),
            'condOk'    => $this->conditionOk((string)($row['Cond'] ?? '')),
            'val'       => $val,
            'dim'       => $dim,
            'names'     => $names,
            'rnd'       => $this->randomUnit(),
        ];
        if ($row['Type'] === 'daemmerung') {
            $today = date('Y-m-d', $ctx['now']);
            foreach ([-1, 0, 1, 2] as $n) {
                $d = AUTOMATLogik::addDays($today, $n);
                $ctx['sun'][$d] = ['ss' => $this->sunOn('Sunset', $d), 'sr' => $this->sunOn('Sunrise', $d)];
            }
        }
        return $ctx;
    }

    /** Symcon-Bedingung auswerten; leer = erfüllt. */
    private function conditionOk(string $cond): bool
    {
        $c = trim($cond);
        if ($c === '' || $c === '[]') {
            return true;
        }
        try {
            return (bool)IPS_IsConditionPassing($c);
        } catch (Throwable $e) {
            $this->SendDebug('Bedingung', 'Nicht auswertbar: ' . $e->getMessage(), 0);
            return false;
        }
    }

    /** Timer auf die nächste fällige Frist stellen (höchstens MAX_TICK_S). */
    private function reschedule(): void
    {
        $rows = array_filter($this->rows(), function ($r) {
            return !empty($r['Enabled']);
        });
        if (count($rows) === 0 && !$this->ReadPropertyBoolean('cfg_ModeAuto')) {
            $this->SetTimerInterval('Tick', 0);
            return;
        }
        $now = $this->now();
        $next = $now + self::MAX_TICK_S;
        foreach ($this->attrMap('Due') as $ts) {
            $next = min($next, (int)$ts);
        }
        // Läuft irgendwo ein Countdown unter einer Stunde, „Restzeit" alle paar Sekunden nachführen.
        foreach ($this->attrMap('End') as $ts) {
            if ((int)$ts > $now && (int)$ts - $now <= 3600) {
                $next = min($next, $now + self::REST_TICK_S);
            }
        }
        $this->SetTimerInterval('Tick', max(1, $next - $now) * 1000);
    }

    /** Schalten einer Zielvariable; merkt sich den Wert, damit das Echo nicht als Handbedienung zählt. */
    private function switchVariable(int $vid, $value): bool
    {
        if (!IPS_VariableExists($vid)) {
            return false;
        }
        $value = $this->castForVariable($vid, $value);
        $sent = $this->attrMap('Sent');
        $sent[$vid] = [$value, $this->now()];
        $this->WriteAttributeString('Sent', json_encode($sent));
        $ok = false;
        $error = '';
        try {
            if (HasAction($vid)) {
                $ok = (bool)RequestAction($vid, $value);
            } else {
                $ok = (bool)SetValue($vid, $value);
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        if (!$ok) {
            // Dauerhaft loggen, nicht nur ins Debug (SUITE.md „Sichtbare Rückmeldung“ Regel 5).
            $this->LogMessage('Schalten fehlgeschlagen: ' . $this->varLabel($vid) . ' → ' . AUTOMATLogik::fmtNum($value) . ($error !== '' ? ' (' . $error . ')' : ''), KL_WARNING);
        }
        return $ok;
    }

    /** War diese Wertänderung das Echo eines eigenen Befehls? (verbraucht den Eintrag) */
    private function consumeEcho(int $vid, $value): bool
    {
        $sent = $this->attrMap('Sent');
        if (!isset($sent[$vid])) {
            return false;
        }
        [$want, $ts] = $sent[$vid];
        $now = $this->now();
        $fresh = $now - (int)$ts <= self::ECHO_S;
        $same = $this->sameValue($value, $want);
        if (!$fresh || $same) {
            unset($sent[$vid]);
            $this->WriteAttributeString('Sent', json_encode($sent));
        }
        return $fresh && $same;
    }

    // =====================================================================
    //  Hausmodus
    // =====================================================================

    private function ensureModeProfile(): void
    {
        if (IPS_VariableProfileExists(self::PROFILE_MODE)) {
            return;
        }
        IPS_CreateVariableProfile(self::PROFILE_MODE, VARIABLETYPE_INTEGER);
        IPS_SetVariableProfileIcon(self::PROFILE_MODE, 'House');
        $colors = [0 => 0xF5C542, 1 => 0xE08A3C, 2 => 0x3C5BA8, 3 => 0x8A8A8A, 4 => 0x2E8B3D];
        foreach (AUTOMATKatalog::MODES as $v => $label) {
            IPS_SetVariableProfileAssociation(self::PROFILE_MODE, $v, $label, '', $colors[$v]);
        }
    }

    private function setMode(int $mode, string $source): void
    {
        if ((int)$this->GetValue('Hausmodus') === $mode) {
            return;
        }
        $this->SetValue('Hausmodus', $mode);
        $this->addLog('', '🏠 Hausmodus „' . AUTOMATKatalog::MODES[$mode] . '“ (' . $source . ').');
        foreach ($this->rows() as $row) {
            $this->process($row, ['k' => 'flag']);
        }
    }

    /**
     * Hausmodus-Automatik: nur bei einem WECHSEL des automatisch ermittelten
     * Modus umstellen — eine Handeinstellung bleibt so bis zum nächsten
     * Übergang (z. B. „Nacht“ von Hand um 21 Uhr bleibt bis zum Morgen).
     */
    private function evaluateMode(bool $presenceChanged): void
    {
        $presVid = $this->ReadPropertyInteger('cfg_PresenceVar');
        $presence = null;
        if ($presVid > 0 && IPS_VariableExists($presVid)) {
            $presence = AUTOMATLogik::isTrue(GetValue($presVid));
            if ($this->ReadPropertyBoolean('cfg_PresenceInvert')) {
                $presence = !$presence;
            }
        }
        if (!$this->ReadPropertyBoolean('cfg_ModeAuto') && $presence === null) {
            return;
        }
        $current = (int)$this->GetValue('Hausmodus');
        $now = $this->now();
        if ($this->ReadPropertyBoolean('cfg_ModeAuto')) {
            $target = AUTOMATLogik::autoMode($now, $this->ReadPropertyString('cfg_DayFrom'), $this->ReadPropertyInteger('cfg_EveningRef'),
                $this->ReadPropertyString('cfg_EveningFrom'), $this->ReadPropertyString('cfg_NightFrom'),
                $this->sunOn('Sunset', date('Y-m-d', $now)), $presence, $current);
        } else {
            // Nur Anwesenheit: Abwesend setzen bzw. beim Heimkommen auf Tag zurück.
            if ($current === AUTOMATKatalog::MODE_HOLIDAY) {
                return;
            }
            $target = $presence === false ? AUTOMATKatalog::MODE_AWAY : ($current === AUTOMATKatalog::MODE_AWAY ? 0 : $current);
        }
        $last = $this->ReadAttributeInteger('LastAutoMode');
        if ($target !== $last || ($presenceChanged && $target !== $current)) {
            $this->WriteAttributeInteger('LastAutoMode', $target);
            if ($target !== $current) {
                $this->setMode($target, $presenceChanged ? 'Anwesenheit' : 'automatisch');
            }
        }
    }

    // =====================================================================
    //  Sonnenstand (Location Control)
    // =====================================================================

    /** Sonnenauf-/-untergang am Kalendertag $ymd, abgeleitet aus Location Control (zeigt immer aufs NÄCHSTE Ereignis). */
    private function sunOn(string $ident, string $ymd): ?int
    {
        $vid = $this->locationVariable($ident);
        if ($vid === 0) {
            return null;
        }
        $v = (int)GetValue($vid);
        if ($v <= 0) {
            return null;
        }
        for ($i = 0; $i < 400 && date('Y-m-d', $v) !== $ymd; $i++) {
            $v = date('Y-m-d', $v) < $ymd ? strtotime('+1 day', $v) : strtotime('-1 day', $v);
        }
        return date('Y-m-d', $v) === $ymd ? $v : null;
    }

    private function locationVariable(string $ident): int
    {
        foreach (IPS_GetInstanceListByModuleID(self::LOCATION_CONTROL_GUID) as $iid) {
            $vid = @IPS_GetObjectIDByIdent($ident, $iid);
            if ($vid && IPS_VariableExists($vid)) {
                return (int)$vid;
            }
        }
        return 0;
    }

    // =====================================================================
    //  Meldungen und Protokoll
    // =====================================================================

    private function message(string $title, string $text, bool $push): void
    {
        $this->SetValue('LastMessage', date('d.m.Y H:i', $this->now()) . ' — ' . $title . ': ' . $text);
        if (!$push) {
            return;
        }
        $sent = 0;
        foreach ($this->pushTargets() as $t) {
            try {
                if ($t['typ'] === 'kachel' && function_exists('VISU_PostNotificationEx')) {
                    $ok = VISU_PostNotificationEx($t['id'], $this->truncateBytes($title, 32), $this->truncateBytes($text, 256), 'Bell', 'bell', 0);
                } elseif ($t['typ'] === 'webfront' && function_exists('WFC_PushNotification')) {
                    $ok = WFC_PushNotification($t['id'], $this->truncateBytes($title, 32), $this->truncateBytes($text, 256), 'bell', $this->InstanceID);
                } else {
                    continue;
                }
            } catch (Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                $sent++;
            } else {
                $this->LogMessage('Push an „' . $t['name'] . '“ fehlgeschlagen: ' . $title, KL_WARNING);
            }
        }
        $this->SendDebug('Meldung', $title . ' — ' . $text . ' (Push an ' . $sent . ' Ziel(e))', 0);
    }

    private function pushTargets(): array
    {
        $out = [];
        foreach ([[self::VISU_GUID, 'kachel'], [self::WEBFRONT_GUID, 'webfront']] as [$guid, $typ]) {
            foreach (@IPS_GetInstanceListByModuleID($guid) ?: [] as $id) {
                $out[] = ['id' => (int)$id, 'name' => IPS_GetName($id), 'typ' => $typ];
            }
        }
        return $out;
    }

    private function truncateBytes(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }
        $r = '';
        foreach (mb_str_split($s) as $ch) {
            if (strlen($r . $ch) > $max) {
                break;
            }
            $r .= $ch;
        }
        return $r;
    }

    private function addLog(string $name, string $text): void
    {
        $log = json_decode($this->ReadAttributeString('Protocol'), true);
        if (!is_array($log)) {
            $log = [];
        }
        array_unshift($log, [$this->now(), $name, $text]);
        $this->WriteAttributeString('Protocol', json_encode(array_slice($log, 0, self::PROTOCOL_MAX)));
        $this->SendDebug('Protokoll', ($name !== '' ? $name . ': ' : '') . $text, 0);
    }

    private function protocolLines(int $n): array
    {
        $log = json_decode($this->ReadAttributeString('Protocol'), true);
        $out = [];
        foreach (array_slice(is_array($log) ? $log : [], 0, $n) as $e) {
            $out[] = date('d.m.Y H:i:s', (int)$e[0]) . ' · ' . ($e[1] !== '' ? $e[1] . ': ' : '') . $e[2];
        }
        return $out;
    }

    /** AUTOMAT_TestMessage($id): string — Testmeldung über alle Wege. */
    public function TestMessage(): string
    {
        $targets = $this->pushTargets();
        $this->message('🧪 Automat', 'Testmeldung — so sehen Meldungen von Wächter und „Gerät fertig“ aus.', true);
        return count($targets) > 0
            ? '✅ Testmeldung an ' . count($targets) . ' Visualisierung(en) gesendet und in „Letzte Meldung“ geschrieben. Kommt nichts an, steht der Grund im Meldungsprotokoll.'
            : 'ℹ️ Keine Visualisierung gefunden — die Testmeldung steht nur in der Variable „Letzte Meldung“.';
    }

    // =====================================================================
    //  Entdecken
    // =====================================================================

    /** AUTOMAT_Discover($id): string — Objektbaum durchsuchen und Vorschläge bilden. */
    public function Discover(): string
    {
        $this->confCache = null;
        $items = $this->collectItems();
        $sugg = AUTOMATErkennung::suggest($items, $this->rows(), $this->dismissed(), $this->roomLabels);
        foreach ($sugg as &$s) {
            $s['conflicts'] = self::conflictLines($this->conflicts($s['row'], ''));
        }
        unset($s);
        $this->WriteAttributeString('Suggestions', json_encode($sugg));
        $this->WriteAttributeString('RoleCounts', json_encode(AUTOMATErkennung::roleCounts($items)));
        $this->WriteAttributeInteger('LastDiscoveryTs', $this->now());

        $this->refreshSuggestionsForm($this->ReadPropertyInteger('cfg_SuggestOrder'));
        $src = $this->ReadAttributeString('RoomSource');
        $this->UpdateFormField('RoomSource', 'caption', $src);
        $this->UpdateFormField('RoomSource', 'color', strpos($src, '🔗') !== false ? 0x2E8B3D : -1);
        $counts = AUTOMATErkennung::roleCounts($items);
        $parts = [];
        foreach ($counts as $role => $n) {
            $parts[] = $n . '× ' . AUTOMATKatalog::ROLES[$role];
        }
        return (count($sugg) > 0 ? '✅ ' . count($sugg) . ' Vorschläge gefunden.' : 'ℹ️ Keine neuen Vorschläge.')
            . "\nGefunden: " . implode(', ', $parts) . '.'
            . (count($sugg) > 0 ? "\nGewünschte Vorschläge in der Liste anhaken und „Angehakte in die Liste übernehmen“ klicken — angelegt wird erst mit „Übernehmen“ unten." : '');
    }

    /** Steckbriefe aller Variablen, die eine Rolle spielen könnten. */
    private function collectItems(): array
    {
        $this->roomLabels = [];
        $roomByInstance = $this->raumRooms();
        $items = [];
        foreach (IPS_GetVariableList() as $vid) {
            $obj = IPS_GetObject($vid);
            $parent = (int)$obj['ParentID'];
            if ($parent === $this->InstanceID || !empty($obj['ObjectIsHidden'])) {
                continue;
            }
            $v = IPS_GetVariable($vid);
            $pres = $v['VariableCustomPresentation'] ?? [];
            if (!is_array($pres) || count($pres) === 0) {
                $pres = $v['VariablePresentation'] ?? [];
            }
            if (!is_array($pres)) {
                $pres = [];
            }
            $profile = (string)($v['VariableCustomProfile'] ?? '') ?: (string)($v['VariableProfile'] ?? '') ?: (string)($pres['PROFILE'] ?? '');
            $suffix = (string)($pres['SUFFIX'] ?? '');
            if ($suffix === '' && $profile !== '' && IPS_VariableProfileExists($profile)) {
                $suffix = (string)(IPS_GetVariableProfile($profile)['Suffix'] ?? '');
            }
            $instID = IPS_InstanceExists($parent) ? $parent : 0;
            $item = [
                'vid' => $vid, 'type' => (int)$v['VariableType'],
                'action' => ((int)($v['VariableCustomAction'] ?? 0) > 1) || ((int)($v['VariableAction'] ?? 0) > 1),
                'profile' => $profile, 'pres' => $pres, 'suffix' => trim($suffix),
                'name' => (string)$obj['ObjectName'], 'inst' => $parent > 0 ? IPS_GetName($parent) : '', 'ident' => (string)$obj['ObjectIdent'],
                'room' => $roomByInstance[$instID] ?? ($roomByInstance[$vid] ?? null),
            ];
            if ($item['room'] === null && count($this->roomLabels) > 0) {
                // Gerät hängt nicht in einem Raum, nennt ihn aber im Namen („Thermostat Kind II“).
                $item['room'] = AUTOMATErkennung::roomFromName($item['inst'] . ' ' . $item['name'], $this->roomLabels);
            }
            $item['role'] = AUTOMATErkennung::role($item);
            if ($item['role'] !== '') {
                $items[] = $item;
            }
        }
        return $items;
    }

    /** Raumzuordnung aus Raumstruktur (optional): [InstanzID => Raumname]. */
    private function raumRooms(): array
    {
        $map = [];
        $ids = @IPS_GetInstanceListByModuleID(self::RAUM_GUID) ?: [];
        // Präfix-Umbenennung 13.10.2026 (TOOLKIT.md): RAUM_ zuerst, STRUKT_ (Raumstruktur) als Rückfall.
        $data = null;
        $available = function_exists('RAUM_GetStructure') || function_exists('STRUKT_GetStructure');
        if (count($ids) === 0 || !$available) {
            $this->WriteAttributeString('RoomSource', 'ℹ️ Raumstruktur nicht installiert — Räume werden aus den Gerätenamen abgeleitet (z. B. „Licht Flur“ + „Bewegungsmelder Flur“ = Flur).');
            return $map;
        }
        try {
            if (function_exists('RAUM_GetStructure')) {
                $data = json_decode((string)RAUM_GetStructure((int)$ids[0]), true);
            } elseif (function_exists('STRUKT_GetStructure')) {
                $data = json_decode((string)STRUKT_GetStructure((int)$ids[0]), true);
            }
        } catch (Throwable $e) {
            $data = null;
        }
        if (!is_array($data) || !isset($data['rooms']) || version_compare((string)($data['contractVersion'] ?? '1.0'), '2.0', '>=')) {
            $this->WriteAttributeString('RoomSource', '⚠️ Raumstruktur #' . $ids[0] . ' gefunden, liefert aber keine verwertbaren Räume — Räume werden aus den Gerätenamen abgeleitet.');
            return $map;
        }
        $n = 0;
        $this->roomLabels = array_values(array_filter(array_map(function ($r) {
            return (string)($r['label'] ?? '');
        }, $data['rooms'])));
        foreach ($data['rooms'] as $room) {
            foreach ((array)($room['deviceInstanceIDs'] ?? []) as $iid) {
                $map[(int)$iid] = (string)($room['label'] ?? $room['key'] ?? '');
                $n++;
            }
        }
        $this->WriteAttributeString('RoomSource', '✅ 🔗 Räume von Raumstruktur #' . $ids[0] . ' übernommen: ' . count($data['rooms']) . ' Räume, ' . $n . ' Geräte zugeordnet (Vertrag ' . ($data['contractVersion'] ?? '1.0') . '). Geräte ohne Raum werden nach Namen gruppiert.');
        return $map;
    }

    private function dismissed(): array
    {
        $d = json_decode($this->ReadAttributeString('Dismissed'), true);
        return is_array($d) ? $d : [];
    }

    private function suggestions(): array
    {
        $s = json_decode($this->ReadAttributeString('Suggestions'), true);
        if (!is_array($s)) {
            return [];
        }
        // Vorschläge, die inzwischen angelegt wurden, nicht mehr anbieten.
        $used = [];
        foreach ($this->rows() as $row) {
            foreach (AUTOMATKatalog::variables($row) as $list) {
                foreach ($list as $vid) {
                    $used[$row['Type']][$vid] = true;
                }
            }
        }
        return array_values(array_filter($s, function ($x) use ($used) {
            foreach (AUTOMATKatalog::variables($x['row']) as $list) {
                foreach ($list as $vid) {
                    if (isset($used[$x['type']][$vid])) {
                        return false;
                    }
                }
            }
            return true;
        }));
    }

    /** Vorschläge geordnet: 0 = Raum → Art → Titel, 1 = Art → Raum → Titel. */
    private function sortedSuggestions(int $order): array
    {
        $list = $this->suggestions();
        usort($list, function ($a, $b) use ($order) {
            $ka = $this->suggestionSortKey($a, $order);
            $kb = $this->suggestionSortKey($b, $order);
            return strcmp($ka, $kb);
        });
        return $list;
    }

    private function suggestionSortKey(array $s, int $order): string
    {
        $room = mb_strtolower(trim((string)$s['room']) ?: '~');
        $type = (string)$s['type'];
        $title = mb_strtolower((string)$s['title']);
        return $order === 1 ? $type . '|' . $room . '|' . $title : $room . '|' . $type . '|' . $title;
    }

    private function suggestionWarnings(array $s): int
    {
        return count(array_filter($s['conflicts'], function ($c) {
            return strpos((string)$c, '⚠️') === 0;
        }));
    }

    /** Zeilen der Vorschlagsliste (nur Anzeige, nichts davon wird gespeichert). */
    private function suggestionRows(): array
    {
        $rows = [];
        foreach ($this->suggestions() as $s) {
            $rows[] = [
                'Pick' => false,
                'Key' => $s['key'],
                'Room' => trim((string)$s['room']) ?: 'ohne Raum',
                'TypeLabel' => AUTOMATKatalog::label((string)$s['type']),
                'Title' => $s['title'],
                'Why' => $s['why'],
                'Hints' => count($s['conflicts']) > 0 ? implode(' | ', $s['conflicts']) : '—',
                'SortRoom' => $this->suggestionSortKey($s, 0),
                'SortType' => $this->suggestionSortKey($s, 1),
                'rowColor' => $this->suggestionWarnings($s) > 0 ? '#FFFFC0' : '',
            ];
        }
        return $rows;
    }

    private function suggestionSort(int $order): array
    {
        return ['column' => $order === 1 ? 'TypeLabel' : 'Room', 'direction' => 'ascending'];
    }

    /** Kopfzeile, Liste und Auswahl der Vorschläge im offenen Formular gemeinsam auffrischen (SUITE.md: nie einzeln). */
    private function refreshSuggestionsForm(int $order): void
    {
        $this->UpdateFormField('DiscoverHead', 'caption', $this->discoverHeadLine());
        $this->UpdateFormField('SuggestList', 'values', json_encode($this->suggestionRows()));
        $this->UpdateFormField('SuggestList', 'rowCount', max(5, min(15, count($this->suggestions()))));
    }

    /** AUTOMAT_UISetSuggestOrder($id, $order) — Vorschläge nach Raum (0) oder Funktion (1) ordnen. */
    public function UISetSuggestOrder(int $order): void
    {
        $this->UpdateFormField('SuggestList', 'sort', json_encode($this->suggestionSort($order)));
    }

    private function discoverHeadLine(): string
    {
        $ts = $this->ReadAttributeInteger('LastDiscoveryTs');
        if ($ts === 0) {
            return 'ℹ️ Noch nicht gesucht — „Jetzt Vorschläge suchen“ durchsucht den Objektbaum.';
        }
        $n = count($this->suggestions());
        return ($n > 0 ? '✅ ' : '⚠️ ') . $n . ' Vorschläge gefunden (zuletzt ' . date('H:i:s', $ts) . ' Uhr).';
    }

    /**
     * AUTOMAT_UIAdoptMany($id, $keysJson, $rowsJson): string — angehakte Vorschläge als neue Zeilen
     * in die OFFENE Liste einfügen (gespeichert wird erst mit „Übernehmen“).
     */
    public function UIAdoptMany(string $keysJson, string $rowsJson): string
    {
        $keys = json_decode($keysJson, true);
        if (!is_array($keys) || count($keys) === 0) {
            return 'ℹ️ Bitte zuerst in der Vorschlagsliste die gewünschten Vorschläge anhaken (Spalte „✔“).';
        }
        $new = [];
        foreach ($this->suggestions() as $s) {
            if (in_array($s['key'], $keys, true)) {
                $new[] = $s['row'];
            }
        }
        if (count($new) === 0) {
            return 'ℹ️ Die angehakten Vorschläge gibt es nicht mehr — bitte „Jetzt Vorschläge suchen“.';
        }
        $names = array_map(function ($r) {
            return '„' . $r['Name'] . '“';
        }, $new);
        return $this->insertRows($new, $rowsJson, count($new) === 1 ? $names[0] : count($new) . ' Automatismen (' . implode(', ', $names) . ')');
    }

    /** AUTOMAT_UIAddType($id, $type, $rowsJson): string — leeren Automatismus eines Typs einfügen. */
    public function UIAddType(string $type, string $rowsJson): string
    {
        if (!AUTOMATKatalog::has($type)) {
            return 'ℹ️ Bitte zuerst einen Typ auswählen.';
        }
        $row = AUTOMATKatalog::defaults($type);
        $row['Name'] = AUTOMATKatalog::TYPES[$type]['label'];
        $row['Room'] = '';
        return $this->insertRows([$row], $rowsJson, 'Ein neuer Automatismus „' . $row['Name'] . '“');
    }

    private function insertRows(array $newRows, string $rowsJson, string $what): string
    {
        $rows = json_decode($rowsJson, true);
        $note = '';
        $valid = is_array($rows);
        if ($valid) {
            foreach ($rows as $r) {
                if (!is_array($r) || !array_key_exists('Type', $r)) {
                    $valid = false;
                    break;
                }
            }
        }
        if (!$valid) {
            // Die Liste der offenen Maske war nicht lesbar: auf den gespeicherten Stand aufsetzen.
            $rows = $this->rows();
            $note = "\n⚠️ Noch nicht übernommene Änderungen in der Liste konnten nicht gelesen werden — die Liste zeigt jetzt den gespeicherten Stand plus den neuen Eintrag.";
        }
        foreach ($newRows as $row) {
            $row['Uid'] = $this->newUid($rows);
            $row['Enabled'] = true;
            $rows[] = $row;
        }
        $this->UpdateFormField('Automations', 'values', json_encode($this->listValues($rows)));
        $this->refreshSuggestionsForm($this->ReadPropertyInteger('cfg_SuggestOrder'));
        return '✅ ' . $what . (count($newRows) === 1 ? ' steht' : ' stehen') . ' jetzt in der Liste — noch NICHT gespeichert.' . "\n"
            . 'Bitte mit ✏️ prüfen (Geräte, Zeiten) und unten „Übernehmen“ klicken.' . $note;
    }

    /** AUTOMAT_DismissSuggestions($id, $keysJson): string — angehakte Vorschläge nicht mehr anbieten. */
    public function DismissSuggestions(string $keysJson): string
    {
        $keys = json_decode($keysJson, true);
        if (!is_array($keys) || count($keys) === 0) {
            return 'ℹ️ Bitte zuerst in der Vorschlagsliste die Vorschläge anhaken, die nicht mehr erscheinen sollen.';
        }
        $d = array_values(array_unique(array_merge($this->dismissed(), array_map('strval', $keys))));
        $this->WriteAttributeString('Dismissed', json_encode($d));
        $sugg = array_values(array_filter(json_decode($this->ReadAttributeString('Suggestions'), true) ?: [], function ($s) use ($keys) {
            return !in_array($s['key'], $keys, true);
        }));
        $this->WriteAttributeString('Suggestions', json_encode($sugg));
        $this->refreshSuggestionsForm($this->ReadPropertyInteger('cfg_SuggestOrder'));
        return '🙈 ' . count($keys) . ' Vorschlag/Vorschläge ausgeblendet — auch bei der nächsten Suche nicht mehr. („Ausgeblendete Vorschläge wieder anzeigen“ holt sie zurück.)';
    }

    /** AUTOMAT_RestoreSuggestions($id): string */
    public function RestoreSuggestions(): string
    {
        $n = count($this->dismissed());
        $this->WriteAttributeString('Dismissed', '[]');
        return $n > 0 ? '✅ ' . $n . ' ausgeblendete Vorschläge kommen bei der nächsten Suche wieder. ' . $this->Discover() : 'ℹ️ Es war nichts ausgeblendet.';
    }

    // =====================================================================
    //  Doppelregelung
    // =====================================================================

    /**
     * Hinweise, wenn dieselben Variablen schon anderswo gesteuert oder ausgewertet werden.
     * Rückgabe: Liste ['level' => '⚠️'|'ℹ️', 'text' => …, 'id' => Objekt zum Öffnen].
     * ⚠️ = schaltet nachweislich dieselbe Variable; ℹ️ = reagiert nur auf dieselbe Variable bzw. reicht sie weiter.
     */
    private function conflicts(array $row, string $ownUid): array
    {
        $vars = AUTOMATKatalog::variables($row);
        $outs = $vars['out'];
        $ins = $vars['in'];
        $out = [];
        // Andere Automatismen dieser und anderer Automat-Instanzen
        foreach (IPS_GetInstanceListByModuleID(self::MODULE_GUID) as $iid) {
            $list = $iid === $this->InstanceID ? $this->rows() : (json_decode((string)@IPS_GetProperty($iid, 'Automations'), true) ?: []);
            foreach ($list as $r) {
                if ($iid === $this->InstanceID && ($r['Uid'] ?? '') === $ownUid) {
                    continue;
                }
                foreach (array_intersect($outs, AUTOMATKatalog::variables($r)['out']) as $vid) {
                    $out[] = ['level' => '⚠️', 'id' => $iid, 'text' => 'Automatismus „' . $this->rowName($r) . '“' . ($iid !== $this->InstanceID ? ' (Automat #' . $iid . ')' : ' (diese Instanz)') . ' schaltet „' . $this->varLabel($vid) . '“ ebenfalls.'];
                }
            }
        }
        // Ausgelöste Ereignisse auf denselben Variablen
        foreach (IPS_GetEventList() as $eid) {
            $e = @IPS_GetEvent($eid);
            if (!is_array($e) || (int)($e['EventType'] ?? -1) !== 0 || empty($e['EventActive'])) {
                continue;
            }
            $tv = (int)($e['TriggerVariableID'] ?? 0);
            if ($tv <= 0 || (!in_array($tv, $ins, true) && !in_array($tv, $outs, true))) {
                continue;
            }
            [$does, $target] = $this->eventAction($eid, $e);
            $same = $target > 0 && in_array($target, $outs, true);
            $out[] = ['level' => $same ? '⚠️' : 'ℹ️', 'id' => $eid, 'text' => $this->eventLabel($eid) . ': ' . $this->triggerText($e, $tv) . ' → ' . $does
                . ($same ? ' — schaltet dasselbe wie dieser Automatismus.' : ' — prüfen, ob es dasselbe tut.')];
        }
        // Andere Instanzen, deren Konfiguration eine der geschalteten Variablen nennt
        if ($this->confCache === null) {
            $this->confCache = [];
            $own = IPS_GetInstanceListByModuleID(self::MODULE_GUID);
            foreach (IPS_GetInstanceList() as $iid) {
                if (in_array($iid, $own, true)) {
                    continue;
                }
                $conf = (string)@IPS_GetConfiguration($iid);
                if ($conf !== '' && $conf !== '{}') {
                    $this->confCache[$iid] = $conf;
                }
            }
        }
        foreach ($outs as $vid) {
            $rx = '/[:\[,]\s*"?' . $vid . '"?\s*[,\]}]/';
            foreach ($this->confCache as $iid => $conf) {
                if (!preg_match($rx, $conf)) {
                    continue;
                }
                $module = $this->moduleName($iid);
                $where = 'Instanz „' . IPS_GetName($iid) . '“ (#' . $iid . ', Modul ' . $module . ', ' . $this->location($iid) . ')';
                if (preg_match('/anwesenheit|schein|presence ?sim/i', $module . ' ' . IPS_GetName($iid))) {
                    $out[] = ['level' => 'ℹ️', 'id' => $iid, 'text' => $where . ' schaltet „' . $this->varLabel($vid) . '“ nur, solange die Anwesenheitssimulation läuft (niemand zu Hause). Tipp: diesen Automatismus im Hausmodus „Abwesend“ abwählen (Dialog → „Wann ist der Automatismus aktiv?“).'];
                } elseif ($this->isPassThrough($iid, $module)) {
                    $out[] = ['level' => 'ℹ️', 'id' => $iid, 'text' => $where . ' gibt „' . $this->varLabel($vid) . '“ nur an ein anderes System weiter oder zeigt es an — kein zweiter Regler.'];
                } else {
                    $out[] = ['level' => '⚠️', 'id' => $iid, 'text' => $where . ' verwendet „' . $this->varLabel($vid) . '“ ebenfalls — regelt sie das schon, entsteht ein zweiter Regler.'];
                }
            }
        }
        $uniq = [];
        foreach ($out as $c) {
            $uniq[$c['text']] = $c;
        }
        return array_values($uniq);
    }

    /** Konflikte als Textzeilen (für Vorschlagsliste und Speicher). */
    private static function conflictLines(array $conflicts): array
    {
        return array_map(function ($c) {
            return is_array($c) ? $c['level'] . ' ' . $c['text'] : (string)$c;
        }, $conflicts);
    }

    /** Lesbarer Name eines Ereignisses: Symcons automatische Namen („Bei bestimmtem Wert for Variable …") durch Ort ersetzen. */
    private function eventLabel(int $eid): string
    {
        $parent = IPS_GetParent($eid);
        $name = IPS_GetName($eid);
        $auto = preg_match('/^(Bei |On |Trigger)/i', $name) || stripos($name, 'for Variable') !== false;
        $kind = $parent > 0 ? ([0 => 'Kategorie', 1 => 'Instanz', 2 => 'Variable', 3 => 'Skript'][(int)(@IPS_GetObject($parent)['ObjectType'] ?? -1)] ?? 'Objekt') : '';
        return 'Ereignis #' . $eid . ($auto ? '' : ' „' . $name . '“') . ($parent > 0 ? ' unter ' . $kind . ' „' . IPS_GetName($parent) . '“ (' . $this->location($parent) . ')' : '');
    }

    private function triggerText(array $e, int $tv): string
    {
        $val = $e['TriggerValue'] ?? null;
        $v = is_bool($val) ? ($val ? '„an“' : '„aus“') : '„' . AUTOMATLogik::fmtNum($val) . '“';
        switch ((int)($e['TriggerType'] ?? -1)) {
            case 0:
                $t = 'bei jeder Aktualisierung';
                break;
            case 1:
                $t = 'bei jeder Änderung';
                break;
            case 2:
                $t = 'beim Überschreiten von ' . $v;
                break;
            case 3:
                $t = 'beim Unterschreiten von ' . $v;
                break;
            case 4:
                $t = 'bei Wert ' . $v;
                break;
            default:
                $t = 'bei Auslösung';
        }
        $cond = is_array($e['EventConditions'] ?? null) && count($e['EventConditions']) > 0;
        return $t . ' von „' . $this->varLabel($tv) . '“' . ($cond ? ' (mit Bedingung)' : '');
    }

    /** Was ein Ereignis tut, als Text, und welche Variable es ggf. schaltet: [Text, VariablenID|0]. */
    private function eventAction(int $eid, array $e): array
    {
        $params = $e['EventActionParameters'] ?? [];
        if (is_string($params)) {
            $params = json_decode($params, true) ?: [];
        }
        $target = (int)($params['TARGET'] ?? 0);
        if ($target > 0 && IPS_VariableExists($target)) {
            return ['schaltet „' . $this->varLabel($target) . '“' . (isset($params['VALUE']) ? ' auf ' . AUTOMATLogik::fmtNum($params['VALUE']) : ''), $target];
        }
        if (trim((string)($e['EventScript'] ?? '')) !== '') {
            return ['führt eigenen Skriptcode aus', 0];
        }
        $parent = IPS_GetParent($eid);
        if ($parent > 0 && (int)(@IPS_GetObject($parent)['ObjectType'] ?? -1) === 3) {
            return ['startet das Skript', 0];
        }
        if ($target > 0) {
            return ['schaltet Objekt #' . $target, 0];
        }
        return ['führt eine Aktion aus', 0];
    }

    private function moduleName(int $iid): string
    {
        $i = @IPS_GetInstance($iid);
        return is_array($i) ? (string)($i['ModuleInfo']['ModuleName'] ?? '?') : '?';
    }

    private function location(int $id): string
    {
        $loc = function_exists('IPS_GetLocation') ? (string)@IPS_GetLocation($id) : '';
        if ($loc === '') {
            return 'Objekt #' . $id;
        }
        // Lange Pfade vorne kürzen, das Ende ist das Aussagekräftige.
        $parts = explode('\\', $loc);
        return count($parts) > 3 ? '…\\' . implode('\\', array_slice($parts, -3)) : $loc;
    }

    /**
     * Instanzen, die Variablen nur weiterreichen oder anzeigen (Sprachassistenten, HomeKit, Visualisierungen,
     * Archiv, Export): kein zweiter Regler. Erkannt am Modultyp (kein Gerät) bzw. am Modulnamen.
     */
    private function isPassThrough(int $iid, string $module): bool
    {
        if (preg_match('/homekit|alexa|google|assistant|sprach|bridge|matter|visu|webfront|dashboard|kachel|archive|archiv|export|mqtt ?server|modbus ?server|influx|grafana|logging/i', $module)) {
            return true;
        }
        $i = @IPS_GetInstance($iid);
        $type = is_array($i) ? (int)($i['ModuleInfo']['ModuleType'] ?? 3) : 3;
        return in_array($type, [4, 5, 6], true); // Konfigurator, Discovery, Visualisierung
    }

    // =====================================================================
    //  Bearbeiten-Dialog (List → form)
    // =====================================================================

    /**
     * AUTOMAT_UIEditForm($id, $uid, $type): string — Elemente des Bearbeiten-Dialogs
     * als JSON. Wird bei jedem Öffnen neu gebaut (Symcon-Doku „List → form“ als
     * PHP-Code), daher je Zeile passend. Alle Typen sind enthalten, sichtbar ist
     * nur der gewählte — so kann der Typ beim Anlegen im Dialog gewählt werden.
     */
    public function UIEditForm(string $uid, string $type): string
    {
        $this->confCache = null;
        $isNew = $uid === '';
        $typeOpts = [['caption' => '— bitte wählen —', 'value' => '']];
        foreach (AUTOMATKatalog::TYPES as $t => $def) {
            $typeOpts[] = ['caption' => $def['icon'] . ' ' . $def['label'], 'value' => $t];
        }
        $el = [
            ['type' => 'Select', 'name' => 'Type', 'caption' => 'Art des Automatismus', 'options' => $typeOpts, 'value' => $type, 'width' => '420px', 'onChange' => 'AUTOMAT_UIDialogType($id, $Type);'],
            ['type' => 'Label', 'name' => 'TypeDesc', 'caption' => AUTOMATKatalog::has($type) ? AUTOMATKatalog::TYPES[$type]['desc'] : 'Zuerst die Art wählen — danach erscheinen die passenden Einstellungen.'],
            ['type' => 'ValidationTextBox', 'name' => 'Name', 'caption' => 'Bezeichnung (z. B. „Flurlicht“)', 'width' => '420px'],
            ['type' => 'ValidationTextBox', 'name' => 'Room', 'caption' => 'Raum (optional, für Übersicht und Kachel)', 'width' => '420px'],
            ['type' => 'CheckBox', 'name' => 'Enabled', 'caption' => 'Eingeschaltet', 'value' => true],
            ['type' => 'ValidationTextBox', 'name' => 'Uid', 'caption' => 'Kennung', 'visible' => false, 'value' => $isNew ? $this->newUid($this->rows()) : $uid],
            ['type' => 'ValidationTextBox', 'name' => 'TypeLabel', 'caption' => 'Typ', 'visible' => false, 'value' => AUTOMATKatalog::has($type) ? AUTOMATKatalog::label($type) : ''],
        ];
        foreach (AUTOMATKatalog::TYPES as $t => $def) {
            $items = [];
            foreach (AUTOMATKatalog::fields($t) as $f) {
                foreach ($this->fieldElements($f) as $e) {
                    $items[] = $e;
                }
            }
            $el[] = ['type' => 'ExpansionPanel', 'name' => 'sec_' . $t, 'caption' => $def['icon'] . ' Einstellungen: ' . $def['label'], 'expanded' => true, 'visible' => $t === $type, 'items' => $items];
        }
        $modeItems = [['type' => 'Label', 'caption' => 'Aktiv in diesen Hausmodi (Variable „Hausmodus“ dieser Instanz):']];
        foreach (AUTOMATKatalog::MODES as $m => $label) {
            $modeItems[] = ['type' => 'CheckBox', 'name' => 'm' . $m, 'caption' => $label, 'value' => true];
        }
        $modeItems[] = ['type' => 'SelectCondition', 'name' => 'Cond', 'caption' => 'Zusätzliche Bedingung (optional, z. B. nur werktags oder nur wenn ein Schalter an ist)', 'multi' => true];
        $el[] = ['type' => 'ExpansionPanel', 'name' => 'sec_when', 'caption' => '🕒 Wann ist der Automatismus aktiv?', 'expanded' => false, 'items' => $modeItems];

        if (!$isNew) {
            $row = $this->rowByUid($uid);
            if ($row !== null) {
                $c = $this->conflicts($row, $uid);
                if (count($c) === 0) {
                    $el[] = ['type' => 'Label', 'caption' => '✅ Keine andere Steuerung derselben Geräte gefunden (gespeicherter Stand).'];
                } else {
                    $items = [['type' => 'Label', 'caption' => '⚠️ = schaltet nachweislich dasselbe · ℹ️ = reagiert auf dieselbe Variable oder reicht sie nur weiter. „Öffnen“ springt zur Fundstelle im Objektbaum.']];
                    foreach ($c as $i => $x) {
                        $items[] = ['type' => 'RowLayout', 'items' => [
                            ['type' => 'OpenObjectButton', 'caption' => 'Öffnen', 'objectID' => $x['id'], 'width' => '90px'],
                            ['type' => 'Label', 'caption' => $x['level'] . ' ' . $x['text']],
                        ]];
                    }
                    $warn = count(array_filter($c, function ($x) {
                        return $x['level'] === '⚠️';
                    }));
                    $el[] = ['type' => 'ExpansionPanel', 'name' => 'sec_conflicts', 'expanded' => $warn > 0,
                        'caption' => ($warn > 0 ? '⚠️ ' : 'ℹ️ ') . 'Andere Steuerungen derselben Geräte: ' . count($c) . ($warn > 0 ? ' (davon ' . $warn . ' schalten dasselbe)' : ''), 'items' => $items];
                }
            }
        } else {
            $el[] = ['type' => 'Label', 'caption' => 'ℹ️ Nach „Übernehmen“ prüft Automat, ob dieselben Geräte schon von einem Ereignis oder einer anderen Instanz gesteuert werden (Hinweis beim nächsten Öffnen).'];
        }
        return json_encode($el);
    }

    /** Formular-Elemente eines Katalog-Felds, ggf. mit Hilfe-Knopf (volle Frage als Beschriftung, SUITE.md Feld-Hilfestellung). */
    private function fieldElements(array $f): array
    {
        $els = $this->fieldInputs($f);
        if (isset($f['help'])) {
            [$question, $lines] = $f['help'];
            $els[] = ['type' => 'PopupButton', 'caption' => $question, 'width' => '420px', 'popup' => [
                'caption' => $question,
                'items' => array_map(function ($l) {
                    return ['type' => 'Label', 'caption' => $l];
                }, $lines),
            ]];
        }
        return $els;
    }

    private function fieldInputs(array $f): array
    {
        $k = $f['k'];
        if ($k === 'info') {
            return [['type' => 'Label', 'caption' => '— ' . $f['c'] . ' —', 'bold' => true]];
        }
        $base = ['name' => $f['n'], 'caption' => $f['c']];
        switch ($k) {
            case 'vars':
                $o = [];
                for ($i = 1; $i <= AUTOMATKatalog::SLOTS; $i++) {
                    $o[] = ['type' => 'SelectVariable', 'name' => $f['n'] . $i, 'caption' => $f['c'] . ' ' . $i . ($i > 1 || empty($f['req']) ? ' (optional)' : ''), 'value' => 0, 'width' => '520px'];
                }
                return $o;
            case 'var':
                return [$base + ['type' => 'SelectVariable', 'value' => 0, 'width' => '520px']];
            case 'int':
                return [$base + ['type' => 'NumberSpinner', 'value' => $f['d'], 'suffix' => $f['suffix'] ?? '', 'minimum' => $f['min'] ?? 0, 'maximum' => $f['max'] ?? 100000]];
            case 'float':
                return [$base + ['type' => 'NumberSpinner', 'value' => $f['d'], 'digits' => 1, 'suffix' => $f['suffix'] ?? '', 'minimum' => $f['min'] ?? 0, 'maximum' => $f['max'] ?? 100000]];
            case 'bool':
                return [$base + ['type' => 'CheckBox', 'value' => $f['d']]];
            case 'select':
                $opts = [];
                foreach ($f['options'] as $v => $c) {
                    $opts[] = ['caption' => $c, 'value' => $v];
                }
                return [$base + ['type' => 'Select', 'value' => $f['d'], 'options' => $opts, 'width' => '420px']];
            case 'time':
                return [$base + ['type' => 'SelectTime', 'value' => $f['d']]];
            case 'text':
                return [$base + ['type' => 'ValidationTextBox', 'value' => $f['d'], 'width' => '520px']];
        }
        return [];
    }

    /** AUTOMAT_UIDialogType($id, $type) — im offenen Dialog den passenden Einstellungsblock zeigen. */
    public function UIDialogType(string $type): void
    {
        foreach (AUTOMATKatalog::TYPES as $t => $_) {
            $this->UpdateFormField('sec_' . $t, 'visible', $t === $type);
        }
        $this->UpdateFormField('TypeLabel', 'value', AUTOMATKatalog::has($type) ? AUTOMATKatalog::label($type) : '');
        $this->UpdateFormField('TypeDesc', 'caption', AUTOMATKatalog::has($type) ? AUTOMATKatalog::TYPES[$type]['desc'] : 'Zuerst die Art wählen — danach erscheinen die passenden Einstellungen.');
    }

    // =====================================================================
    //  Kachel
    // =====================================================================

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        return $html . '<script>handleMessage(' . json_encode($this->tilePayload(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) . ');</script>';
    }

    private function refreshTile(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->tilePayload(), JSON_UNESCAPED_UNICODE));
    }

    private function tilePayload(): array
    {
        $status = $this->attrMap('Status');
        $due = $this->attrMap('End');
        $groups = [];
        foreach ($this->rows() as $row) {
            $uid = $row['Uid'];
            $room = trim((string)($row['Room'] ?? '')) ?: 'Ohne Raum';
            $light = !empty(AUTOMATKatalog::TYPES[$row['Type']]['light']) && $row['Type'] !== 'daemmerung';
            $groups[$room][] = [
                'uid' => $uid, 'name' => $this->rowName($row), 'icon' => AUTOMATKatalog::TYPES[$row['Type']]['icon'] ?? '❔',
                'type' => AUTOMATKatalog::TYPES[$row['Type']]['label'] ?? '', 'status' => (string)($status[$uid] ?? ''),
                'due' => isset($due[$uid]) ? (int)$due[$uid] : null, 'enabled' => !empty($row['Enabled']),
                'locked' => (bool)$this->getOwn('l_' . $uid, false), 'permanent' => $light ? (bool)$this->getOwn('d_' . $uid, false) : null,
            ];
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        $out = [];
        foreach ($groups as $room => $items) {
            $out[] = ['room' => $room, 'items' => $items];
        }
        return [
            'light' => $this->ReadPropertyBoolean('cfg_TileLight'),
            'now' => $this->now(),
            'mode' => (int)$this->getOwn('Hausmodus', 0),
            'modes' => array_values(AUTOMATKatalog::MODES),
            'paused' => (bool)$this->getOwn('Pause', false),
            'groups' => $out,
            'log' => $this->protocolLines(8),
        ];
    }

    /** Rückkanal aus der Kachel: {"a":"lock|perm|pause|mode","uid":…,"v":…}. */
    private function tileAction(string $json): void
    {
        $d = json_decode($json, true);
        if (!is_array($d)) {
            return;
        }
        $a = (string)($d['a'] ?? '');
        $uid = preg_replace('/[^a-z0-9]/', '', (string)($d['uid'] ?? ''));
        if ($a === 'lock' && $this->rowByUid($uid) !== null) {
            $this->RequestAction('l_' . $uid, !empty($d['v']));
        } elseif ($a === 'perm' && $this->rowByUid($uid) !== null && @IPS_GetObjectIDByIdent('d_' . $uid, $this->InstanceID)) {
            $this->RequestAction('d_' . $uid, !empty($d['v']));
        } elseif ($a === 'pause') {
            $this->RequestAction('Pause', !empty($d['v']));
        } elseif ($a === 'mode' && isset(AUTOMATKatalog::MODES[(int)($d['v'] ?? -1)])) {
            $this->RequestAction('Hausmodus', (int)$d['v']);
        } else {
            $this->refreshTile();
        }
    }

    // =====================================================================
    //  Helfer
    // =====================================================================

    /** Testbar überschreibbar (Prüfstand ohne Symcon). */
    protected function now(): int
    {
        return time();
    }

    protected function randomUnit(): float
    {
        return random_int(-1000, 1000) / 1000;
    }

    /** Listenzeilen mit garantierter Kennung und bekanntem Typ. */
    private function rows(): array
    {
        $rows = json_decode((string)$this->ReadPropertyString('Automations'), true);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach (array_values($rows) as $i => $r) {
            if (!is_array($r)) {
                continue;
            }
            $r['Uid'] = preg_replace('/[^a-z0-9]/', '', strtolower((string)($r['Uid'] ?? '')));
            if ($r['Uid'] === '') {
                $r['Uid'] = 'r' . $i; // Rückfall für Zeilen ohne Kennung
            }
            $r['Type'] = (string)($r['Type'] ?? '');
            $out[] = $r;
        }
        return $out;
    }

    private function rowByUid(string $uid): ?array
    {
        foreach ($this->rows() as $r) {
            if ($r['Uid'] === $uid) {
                return $r;
            }
        }
        return null;
    }

    private function rowName(array $row): string
    {
        $n = trim((string)($row['Name'] ?? ''));
        return $n !== '' ? $n : (AUTOMATKatalog::TYPES[$row['Type'] ?? '']['label'] ?? 'Automatismus');
    }

    private function newUid(array $rows): string
    {
        $used = array_flip(array_map(function ($r) {
            return (string)($r['Uid'] ?? '');
        }, $rows));
        // Immer mit „a“ beginnend — kollidiert so nie mit den Rückfall-Kennungen „r<Index>“.
        do {
            $uid = 'a' . substr(bin2hex(random_bytes(4)), 0, 5);
        } while (isset($used[$uid]));
        return $uid;
    }

    private function attrMap(string $name): array
    {
        $m = json_decode((string)$this->ReadAttributeString($name), true);
        return is_array($m) ? $m : [];
    }

    private function ensureVar(string $ident, string $name, int $type, string $profile, int $pos): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        $this->MaintainVariable($ident, $name, $type, $profile, $pos, true);
        if ($id) {
            // MaintainVariable benennt nicht um (Pitfall 16): nur Standardnamen nachziehen, Nutzernamen bleiben.
            $cur = IPS_GetName($id);
            if ($cur !== $name && preg_match('/ · (Status|Restzeit|gesperrt|Dauerlicht)$/u', $cur)) {
                IPS_SetName($id, $name);
            }
        }
    }

    private function getOwn(string $ident, $default)
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        return $id ? GetValue($id) : $default;
    }

    private function setVarIfChanged(string $ident, string $value): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id && GetValue($id) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    /** Variablenname mit Gerät: „Licht Flur / Status“. */
    private function varLabel(int $vid): string
    {
        if (!IPS_VariableExists($vid)) {
            return '#' . $vid;
        }
        $p = IPS_GetParent($vid);
        $name = IPS_GetName($vid);
        return $p > 0 && IPS_InstanceExists($p) ? IPS_GetName($p) . ' / ' . $name : $name;
    }

    /** Größter Wert eines Dimmers (0 = Schalter). */
    private function dimMax(int $vid): float
    {
        $v = IPS_GetVariable($vid);
        $type = (int)$v['VariableType'];
        if ($type === VARIABLETYPE_BOOLEAN || $type === VARIABLETYPE_STRING) {
            return 0;
        }
        $pres = $v['VariableCustomPresentation'] ?? [];
        if (!is_array($pres) || count($pres) === 0) {
            $pres = $v['VariablePresentation'] ?? [];
        }
        if (is_array($pres) && isset($pres['MAX']) && (float)$pres['MAX'] > 0) {
            return (float)$pres['MAX'];
        }
        $profile = (string)($v['VariableCustomProfile'] ?? '') ?: (string)($v['VariableProfile'] ?? '') ?: (string)($pres['PROFILE'] ?? '');
        if ($profile !== '' && IPS_VariableProfileExists($profile)) {
            $max = (float)(IPS_GetVariableProfile($profile)['MaxValue'] ?? 0);
            if ($max > 0) {
                return $max;
            }
        }
        return 100;
    }

    private function castForVariable(int $vid, $value)
    {
        switch ((int)(IPS_GetVariable($vid)['VariableType'] ?? -1)) {
            case VARIABLETYPE_BOOLEAN:
                return (bool)$value;
            case VARIABLETYPE_INTEGER:
                return (int)round((float)$value);
            case VARIABLETYPE_FLOAT:
                return (float)$value;
        }
        return (string)$value;
    }

    private function sameValue($a, $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return AUTOMATLogik::isTrue($a) === AUTOMATLogik::isTrue($b);
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float)$a - (float)$b) <= max(0.02, abs((float)$b) * 0.02);
        }
        return (string)$a === (string)$b;
    }

    // =====================================================================
    //  Formular
    // =====================================================================

    /** Listenwerte für das Formular: gespeicherte Zeilen plus berechneter Zustand. */
    private function listValues(array $rows): array
    {
        $status = $this->attrMap('Status');
        $out = [];
        foreach ($rows as $r) {
            $uid = (string)($r['Uid'] ?? '');
            $p = AUTOMATKatalog::problems($r, function (int $vid) {
                return IPS_VariableExists($vid);
            });
            $r['TypeLabel'] = AUTOMATKatalog::has((string)($r['Type'] ?? '')) ? AUTOMATKatalog::label((string)$r['Type']) : '❔ Typ wählen (✏️)';
            $r['State'] = empty($r['Enabled']) ? '⚫ Ausgeschaltet' : (count($p) > 0 ? '⚠️ ' . $p[0] : (string)($status[$uid] ?? 'ℹ️ Nach „Übernehmen“ aktiv'));
            $r['Settings'] = AUTOMATKatalog::has((string)($r['Type'] ?? '')) ? AUTOMATKatalog::summary($r, function (int $vid) {
                return $this->liveText($vid);
            }) : '';
            // Sortierschlüssel: Raum → Art → Name bzw. Art → Raum → Name (Spaltenkopf oder „Ordnen nach“).
            $room = mb_strtolower(trim((string)($r['Room'] ?? '')) ?: '~');
            $type = (string)($r['Type'] ?? '');
            $name = mb_strtolower((string)($r['Name'] ?? ''));
            $r['SortRoom'] = $room . '|' . $type . '|' . $name;
            $r['SortType'] = $type . '|' . $room . '|' . $name;
            if (count($p) > 0 && !empty($r['Enabled'])) {
                $r['rowColor'] = '#FFC0C0';
            } elseif (empty($r['Enabled'])) {
                $r['rowColor'] = '#DFDFDF';
            }
            $out[] = $r;
        }
        return $out;
    }

    /** Aktueller Wert einer Variable kurz als Text mit Einheit („12 lx"), null wenn unbekannt. */
    private function liveText(int $vid): ?string
    {
        if (!IPS_VariableExists($vid)) {
            return null;
        }
        $v = GetValue($vid);
        if (!is_numeric($v)) {
            return null;
        }
        $num = rtrim(rtrim(number_format((float)$v, 1, ',', '.'), '0'), ',');
        return $num . ' lx';
    }

    /** AUTOMAT_UISetListOrder($id, $order) — Liste im offenen Formular nach Raum (0) oder Art (1) ordnen. */
    public function UISetListOrder(int $order): void
    {
        $this->UpdateFormField('Automations', 'sort', json_encode($this->listSort($order)));
    }

    private function listSort(int $order): array
    {
        return ['column' => $order === 1 ? 'TypeLabel' : 'Room', 'direction' => 'ascending'];
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    public function AckNews(): void
    {
        $lib = @IPS_GetLibrary(self::LIBRARY_GUID);
        $this->WriteAttributeString('SeenNews', preg_replace('/-.*$/', '', is_array($lib) ? (string)($lib['Version'] ?? '') : ''));
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    /** AUTOMAT_Overview($id): string — Zustand aller Automatismen als Text. */
    public function Overview(): string
    {
        $rows = $this->rows();
        if (count($rows) === 0) {
            return 'ℹ️ Noch kein Automatismus eingerichtet.';
        }
        $status = $this->attrMap('Status');
        $lines = ['🏠 Hausmodus: ' . (AUTOMATKatalog::MODES[(int)$this->getOwn('Hausmodus', 0)] ?? '?') . ((bool)$this->getOwn('Pause', false) ? ' · ⏸ ALLE PAUSIERT' : '')];
        foreach ($rows as $r) {
            $lines[] = (AUTOMATKatalog::TYPES[$r['Type']]['icon'] ?? '❔') . ' ' . $this->rowName($r) . ': ' . ($status[$r['Uid']] ?? '—');
        }
        return implode("\n", $lines);
    }

    private function PurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true, 'caption' => '👋  Wozu dieses Modul?',
            'items' => [
                ['type' => 'Label', 'caption' => 'Automat bündelt die kleinen Standard-Automatismen eines Hauses in einer Instanz: Treppenlicht, Bewegungslicht, Dämmerungsschaltung, Fenster offen → Heizung runter, „Tür zu lange offen“-Wächter und „Waschmaschine fertig“.'],
                ['type' => 'Label', 'caption' => 'Der Nutzen: eine Liste statt dutzender Einzel-Instanzen und Ereignisse, je Automatismus ein sichtbarer Zustand mit Grund, ein Protokoll, eine Kachel — und „Entdecken“ schlägt aus den vorhandenen Geräten vor, was sich lohnt.'],
                ['type' => 'Label', 'caption' => 'Für alle gleich: Sperre je Automatismus, globale Pause, Hausmodus (Tag/Abend/Nacht/Abwesend/Urlaub) und Respekt vor Handbedienung. Räume kommen aus dem Toolkit-Modul Raumstruktur, falls installiert. Für Urlaub und Abwesenheit gibt es die Anwesenheitssimulation, für Batterien den Batteriewächter (beide ebenfalls DG65-Toolkit).'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'AUTOMAT_AckPurposeIntro($id);'],
            ],
        ];
    }

    private function NewsBanner(): ?array
    {
        $seen = $this->ReadAttributeString('SeenNews') ?: '0';
        $pending = [];
        foreach (self::NEWS_VERSIONS as $version => $lines) {
            if (version_compare((string)$version, $seen, '>')) {
                $pending[(string)$version] = $lines;
            }
        }
        if (count($pending) === 0) {
            return null;
        }
        uksort($pending, 'version_compare');
        $items = [];
        foreach ($pending as $version => $lines) {
            $items[] = ['type' => 'Label', 'caption' => 'Version ' . $version . ':'];
            foreach ($lines as $line) {
                $items[] = ['type' => 'Label', 'caption' => $line];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'AUTOMAT_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true, 'caption' => '🆕  Neu bis Version ' . array_key_last($pending), 'items' => $items];
    }

    private function DocPanel(): array
    {
        $lib = @IPS_GetLibrary(self::LIBRARY_GUID);
        $ver = is_array($lib) && isset($lib['Version']) ? 'ℹ️ Automat Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')' : 'ℹ️ Automat';
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '📖  Dokumentation & Hilfe',
            'items' => [
                ['type' => 'Label', 'caption' => $ver],
                ['type' => 'Label', 'caption' => 'Anlegen: „Entdecken“ schlägt Automatismen aus den vorhandenen Geräten vor, „Katalog“ legt einen leeren Automatismus eines Typs an, „+“ an der Liste öffnet den Dialog direkt. Immer gilt: mit ✏️ prüfen, dann unten „Übernehmen“.'],
                ['type' => 'Label', 'caption' => 'Je Automatismus entstehen unter der Instanz: „… · Status“ (Zustand und Grund), „… · Restzeit“ (läuft mit, solange etwas ansteht: Licht aus, Absenkung, Meldung — sonst leer), „… · gesperrt“ (Schalter: Automatismus tut nichts, z. B. Putzen, Party, Handwerker) und bei Licht-Automatismen „… · Dauerlicht“. Alle drei sind auch aus WebFront, Kachel und Skript (IPS_RequestAction) bedienbar.'],
                ['type' => 'Label', 'caption' => 'Handbedienung hat Vorrang: Wer ein Licht von Hand ausschaltet, beendet die laufende Zeit; beim Bewegungslicht pausiert die Automatik danach für die eingestellte Zeit. Wer es von Hand einschaltet, bekommt beim Zeitlicht die normale Leuchtdauer, beim Bewegungslicht die längere aus Nachlauf und Handpause.'],
                ['type' => 'Label', 'caption' => 'Hausmodus: „Nacht“ nutzt bei Licht-Automatismen den Nacht-Helligkeitswert; jeder Automatismus kann auf bestimmte Modi beschränkt werden (im Dialog unter „Wann ist der Automatismus aktiv?“). Die Automatik stellt den Modus nur bei einem Übergang um — eine Handeinstellung bleibt bis zum nächsten Übergang. Urlaub wird nie automatisch gesetzt oder verlassen.'],
                ['type' => 'Label', 'caption' => 'Sperre und Pause sind „hart“ (gar nichts passiert, laufende Zeiten werden abgebrochen, ein abgesenkter Heizungs-Sollwert wird zurückgestellt). Hausmodus und Bedingung sind „weich“: es wird nichts NEU eingeschaltet oder gemeldet, laufende Zeiten laufen aber zu Ende.'],
                ['type' => 'Label', 'caption' => 'Doppelte Steuerung: Automat warnt im Dialog und bei Vorschlägen, wenn dieselben Geräte schon von einem anderen Automatismus, einem ausgelösten Ereignis oder einer anderen Instanz verwendet werden. Zwei Regler an einem Licht widersprechen sich — eine Seite abschalten.'],
                ['type' => 'Label', 'caption' => 'Skripte: AUTOMAT_Overview(<InstanzID>) liefert den Zustand aller Automatismen als Text; IPS_RequestAction(<InstanzID>, \'Hausmodus\', 0…4) bzw. \'Pause\', true/false schaltet global.'],
            ],
        ];
    }

    private function AutomationsPanel(): array
    {
        $rows = $this->rows();
        $enabled = 0;
        $problems = 0;
        $locked = 0;
        foreach ($rows as $r) {
            if (empty($r['Enabled'])) {
                continue;
            }
            $enabled++;
            if (count(AUTOMATKatalog::problems($r, function (int $vid) {
                return IPS_VariableExists($vid);
            })) > 0) {
                $problems++;
            }
            if ($this->getOwn('l_' . $r['Uid'], false)) {
                $locked++;
            }
        }
        $summary = count($rows) === 0
            ? 'ℹ️ Noch kein Automatismus — unten „Entdecken“ nutzen, im „Katalog“ einen Typ wählen oder „+“ an der Liste klicken.'
            : ($problems > 0 ? '⚠️ ' : '✅ ') . count($rows) . ' Automatismen, ' . $enabled . ' eingeschaltet'
                . ($problems > 0 ? ', ' . $problems . ' unvollständig (rot)' : '') . ($locked > 0 ? ', ' . $locked . ' gesperrt' : '')
                . ' · Hausmodus „' . (AUTOMATKatalog::MODES[(int)$this->getOwn('Hausmodus', 0)] ?? '?') . '“' . ((bool)$this->getOwn('Pause', false) ? ' · ⏸ ALLE PAUSIERT' : '');
        return [
            'type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '⚙️  Automatismen',
            'items' => [
                ['type' => 'Label', 'name' => 'Summary', 'caption' => $summary],
                ['type' => 'Select', 'name' => 'cfg_ListOrder', 'caption' => 'Ordnen nach', 'width' => '320px', 'onChange' => 'AUTOMAT_UISetListOrder($id, $cfg_ListOrder);', 'options' => [
                    ['caption' => 'Raum, darin nach Art', 'value' => 0],
                    ['caption' => 'Art (Funktion), darin nach Raum', 'value' => 1],
                ]],
                [
                    'type' => 'List', 'name' => 'Automations', 'caption' => 'Automatismen', 'rowCount' => max(12, min(25, count($rows) + 3)),
                    'add' => true, 'delete' => true, 'loadValuesFromConfiguration' => false, 'values' => $this->listValues($rows),
                    'sort' => $this->listSort($this->ReadPropertyInteger('cfg_ListOrder')),
                    'columns' => [
                        ['caption' => 'An', 'name' => 'Enabled', 'width' => '50px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Raum', 'name' => 'Room', 'width' => '120px', 'add' => '', 'save' => true, 'sortColumn' => 'SortRoom'],
                        ['caption' => 'Art', 'name' => 'TypeLabel', 'width' => '210px', 'add' => '', 'save' => true, 'sortColumn' => 'SortType'],
                        ['caption' => 'Bezeichnung', 'name' => 'Name', 'width' => '200px', 'add' => '', 'save' => true],
                        ['caption' => 'Einstellungen', 'name' => 'Settings', 'width' => 'auto', 'add' => '', 'save' => false],
                        ['caption' => 'Zustand', 'name' => 'State', 'width' => '340px', 'add' => '', 'save' => false],
                        ['caption' => 'Sortierung Raum', 'name' => 'SortRoom', 'width' => '10px', 'add' => '', 'save' => false, 'visible' => false],
                        ['caption' => 'Sortierung Art', 'name' => 'SortType', 'width' => '10px', 'add' => '', 'save' => false, 'visible' => false],
                        ['caption' => 'Typ', 'name' => 'Type', 'width' => '80px', 'add' => '', 'save' => true, 'visible' => false],
                        ['caption' => 'Kennung', 'name' => 'Uid', 'width' => '80px', 'add' => '', 'save' => true, 'visible' => false],
                    ],
                    'form' => "return json_decode(AUTOMAT_UIEditForm(\$id, (string)(\$Automations['Uid'] ?? ''), (string)(\$Automations['Type'] ?? '')), true);",
                ],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '📋 Übersicht anzeigen', 'onClick' => 'echo AUTOMAT_Overview($id);'],
                    ['type' => 'PopupButton', 'caption' => 'Wie lege ich einen Automatismus an?', 'width' => '420px', 'popup' => [
                        'caption' => 'Wie lege ich einen Automatismus an?',
                        'items' => [
                            ['type' => 'Label', 'caption' => '1. Am einfachsten über „🔎 Entdecken“: Vorschläge anhaken, „Angehakte in die Liste übernehmen“.'],
                            ['type' => 'Label', 'caption' => '2. Oder im „📚 Katalog“ einen Typ wählen und „Leeren Automatismus anlegen“.'],
                            ['type' => 'Label', 'caption' => '3. Oder „+“ an der Liste: Im Dialog zuerst die Art wählen, dann Geräte und Zeiten.'],
                            ['type' => 'Label', 'caption' => 'Danach mit ✏️ prüfen und unten „Übernehmen“ klicken — erst dann arbeitet der Automatismus. Rot markierte Zeilen sind unvollständig, die Spalte „Zustand“ sagt, was fehlt.'],
                        ],
                    ]],
                ]],
            ],
        ];
    }

    private function DiscoverPanel(): array
    {
        $src = $this->ReadAttributeString('RoomSource');
        if ($src === '') {
            $src = count(@IPS_GetInstanceListByModuleID(self::RAUM_GUID) ?: []) > 0
                ? 'ℹ️ Raumstruktur gefunden — die Räume werden bei der Suche übernommen.'
                : 'ℹ️ Raumstruktur nicht installiert — Räume werden aus den Gerätenamen abgeleitet (z. B. „Licht Flur“ + „Bewegungsmelder Flur“ = Flur).';
        }
        $order = $this->ReadPropertyInteger('cfg_SuggestOrder');
        $n = count($this->suggestions());
        return [
            'type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '🔎  Entdecken — Vorschläge aus deinen Geräten',
            'items' => [
                ['type' => 'Button', 'caption' => '🔎 Jetzt Vorschläge suchen', 'onClick' => 'echo AUTOMAT_Discover($id);'],
                ['type' => 'Label', 'name' => 'DiscoverHead', 'caption' => $this->discoverHeadLine()],
                ['type' => 'Label', 'name' => 'RoomSource', 'caption' => $src, 'color' => strpos($src, '🔗') !== false ? 0x2E8B3D : -1],
                ['type' => 'Select', 'name' => 'cfg_SuggestOrder', 'caption' => 'Ordnen nach', 'width' => '320px', 'onChange' => 'AUTOMAT_UISetSuggestOrder($id, $cfg_SuggestOrder);', 'options' => [
                    ['caption' => 'Raum, darin nach Art', 'value' => 0],
                    ['caption' => 'Art (Funktion), darin nach Raum', 'value' => 1],
                ]],
                [
                    'type' => 'List', 'name' => 'SuggestList', 'caption' => 'Vorschläge (gelb = dieselben Geräte werden schon anderswo geschaltet)', 'rowCount' => max(5, min(15, $n)),
                    'add' => false, 'delete' => false, 'loadValuesFromConfiguration' => false, 'values' => $this->suggestionRows(), 'sort' => $this->suggestionSort($order),
                    'columns' => [
                        ['caption' => '✔', 'name' => 'Pick', 'width' => '45px', 'save' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Raum', 'name' => 'Room', 'width' => '120px', 'save' => false, 'sortColumn' => 'SortRoom'],
                        ['caption' => 'Art', 'name' => 'TypeLabel', 'width' => '230px', 'save' => false, 'sortColumn' => 'SortType'],
                        ['caption' => 'Vorschlag', 'name' => 'Title', 'width' => '240px', 'save' => false],
                        ['caption' => 'Warum', 'name' => 'Why', 'width' => 'auto', 'save' => false],
                        ['caption' => 'Hinweise', 'name' => 'Hints', 'width' => '320px', 'save' => false],
                        ['caption' => 'Sortierung Raum', 'name' => 'SortRoom', 'width' => '10px', 'save' => false, 'visible' => false],
                        ['caption' => 'Sortierung Art', 'name' => 'SortType', 'width' => '10px', 'save' => false, 'visible' => false],
                        ['caption' => 'Schlüssel', 'name' => 'Key', 'width' => '10px', 'save' => false, 'visible' => false],
                    ],
                ],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '➕ Angehakte in die Liste übernehmen', 'onClick' => self::PICKED_KEYS . ' $r = []; foreach ($Automations as $x) { $r[] = $x; } echo AUTOMAT_UIAdoptMany($id, json_encode($k), json_encode($r));'],
                    ['type' => 'Button', 'caption' => '🙈 Angehakte ausblenden', 'onClick' => self::PICKED_KEYS . ' echo AUTOMAT_DismissSuggestions($id, json_encode($k));'],
                ]],
                ['type' => 'Button', 'caption' => 'Ausgeblendete Vorschläge wieder anzeigen', 'visible' => count($this->dismissed()) > 0, 'onClick' => 'echo AUTOMAT_RestoreSuggestions($id);'],
                ['type' => 'Label', 'caption' => 'ℹ️ Gesucht wird nach Bewegungsmeldern, Lichtern (Schalter/Dimmer), Fenster-/Türkontakten, Heizungs-Sollwerten, Helligkeitssensoren und Leistungsmessungen von Haushaltsgeräten. ⚠️ bzw. gelb heißt: dieselben Geräte werden schon anderswo geschaltet — Details mit „Öffnen“-Knopf im Bearbeiten-Dialog nach dem Übernehmen.'],
            ],
        ];
    }

    private function CatalogPanel(): array
    {
        $counts = json_decode($this->ReadAttributeString('RoleCounts'), true);
        $items = [['type' => 'Label', 'caption' => 'Alle Arten von Automatismen. Ob passende Geräte da sind, steht nach „Jetzt Vorschläge suchen“ darunter.']];
        foreach (AUTOMATKatalog::CATEGORIES as $cat => $catLabel) {
            $items[] = ['type' => 'Label', 'caption' => $catLabel, 'bold' => true];
            foreach (AUTOMATKatalog::TYPES as $t => $def) {
                if ($def['cat'] !== $cat) {
                    continue;
                }
                $items[] = ['type' => 'Label', 'caption' => $def['icon'] . ' ' . $def['label'] . ' — ' . $def['desc']];
                if (is_array($counts)) {
                    $missing = [];
                    $have = [];
                    foreach ($def['needs'] as $role) {
                        if ((int)($counts[$role] ?? 0) > 0) {
                            $have[] = $counts[$role] . '× ' . AUTOMATKatalog::ROLES[$role];
                        } else {
                            $missing[] = AUTOMATKatalog::ROLES[$role];
                        }
                    }
                    $items[] = ['type' => 'Label', 'caption' => count($def['needs']) === 0 ? '      ✅ geht mit jeder Variable'
                        : (count($missing) === 0 ? '      ✅ passende Geräte gefunden: ' . implode(', ', $have) : '      ⚪ dafür fehlt: ' . implode(', ', $missing) . ' (oder wurde nicht erkannt — von Hand wählbar)')];
                }
            }
        }
        $opts = [['caption' => '— Art wählen —', 'value' => '']];
        foreach (AUTOMATKatalog::TYPES as $t => $def) {
            $opts[] = ['caption' => $def['icon'] . ' ' . $def['label'], 'value' => $t];
        }
        $items[] = ['type' => 'Select', 'name' => 'cfg_NewType', 'caption' => 'Neu anlegen', 'options' => $opts, 'value' => '', 'width' => '420px'];
        $items[] = ['type' => 'Button', 'caption' => '➕ Leeren Automatismus dieser Art anlegen', 'onClick' => '$r = []; foreach ($Automations as $x) { $r[] = $x; } echo AUTOMAT_UIAddType($id, (string)$cfg_NewType, json_encode($r));'];
        return ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '📚  Katalog', 'items' => $items];
    }

    private function ModePanel(): array
    {
        $mode = AUTOMATKatalog::MODES[(int)$this->getOwn('Hausmodus', 0)] ?? '?';
        $loc = $this->locationVariable('Sunset') > 0
            ? '✅ 🔗 Sonnenuntergang aus Symcons Location Control.'
            : 'ℹ️ Keine Location-Control-Instanz — „bei Sonnenuntergang“ fällt auf die Uhrzeit zurück (Einstellungen → Standort).';
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🏠  Hausmodus (aktuell: ' . $mode . ')',
            'items' => [
                ['type' => 'Label', 'caption' => 'Der Hausmodus ist eine Variable dieser Instanz (Tag, Abend, Nacht, Abwesend, Urlaub) — von Hand, per Skript oder hier automatisch gesetzt. Automatismen können auf Modi beschränkt werden; Licht nutzt bei „Nacht“ den Nachtwert.'],
                ['type' => 'CheckBox', 'name' => 'cfg_ModeAuto', 'caption' => 'Tag/Abend/Nacht automatisch nach Uhrzeit setzen'],
                ['type' => 'SelectTime', 'name' => 'cfg_DayFrom', 'caption' => 'Tag ab'],
                ['type' => 'Select', 'name' => 'cfg_EveningRef', 'caption' => 'Abend ab', 'options' => [['caption' => 'Sonnenuntergang', 'value' => 0], ['caption' => 'Uhrzeit (unten)', 'value' => 1]]],
                ['type' => 'SelectTime', 'name' => 'cfg_EveningFrom', 'caption' => 'Abend ab (Uhrzeit)'],
                ['type' => 'SelectTime', 'name' => 'cfg_NightFrom', 'caption' => 'Nacht ab'],
                ['type' => 'Label', 'caption' => $loc, 'color' => strpos($loc, '🔗') !== false ? 0x2E8B3D : -1],
                ['type' => 'SelectVariable', 'name' => 'cfg_PresenceVar', 'caption' => 'Anwesenheit (optional, Bool: wahr = jemand zu Hause)', 'width' => '520px'],
                ['type' => 'CheckBox', 'name' => 'cfg_PresenceInvert', 'caption' => 'Variable ist umgekehrt (wahr = niemand da, z. B. „Abwesend“)'],
                ['type' => 'Label', 'caption' => 'ℹ️ Mit Anwesenheits-Variable wird bei „niemand da“ automatisch „Abwesend“ gesetzt und beim Heimkommen wieder der Tageszeit-Modus. „Urlaub“ setzt und beendet nur der Nutzer.'],
            ],
        ];
    }

    private function MessagesPanel(): array
    {
        $t = $this->pushTargets();
        $line = count($t) > 0
            ? '✅ 🔗 ' . count($t) . ' Visualisierung(en) gefunden: ' . implode(', ', array_map(function ($x) {
                return '„' . $x['name'] . '“';
            }, $t)) . ' — Meldungen mit Push gehen dorthin.'
            : 'ℹ️ Keine WebFront-/Kachel-Visualisierung gefunden — Meldungen landen nur in „Letzte Meldung“ und im Protokoll.';
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🔔  Meldungen, Protokoll und Kachel',
            'items' => [
                ['type' => 'Label', 'caption' => $line, 'color' => strpos($line, '🔗') !== false ? 0x2E8B3D : -1],
                ['type' => 'Button', 'caption' => '🔔 Testmeldung senden', 'onClick' => 'echo AUTOMAT_TestMessage($id);'],
                ['type' => 'Label', 'caption' => 'Protokoll (neueste zuerst) — warum etwas geschaltet oder gemeldet wurde:'],
                ['type' => 'Label', 'name' => 'ProtocolText', 'caption' => implode("\n", $this->protocolLines(30)) ?: '— noch leer —'],
                ['type' => 'CheckBox', 'name' => 'cfg_TileLight', 'caption' => 'Helle Darstellung der Kachel'],
            ],
        ];
    }

    private function ForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true, 'caption' => '💬  Rückmeldungen',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 Automat ist neu — Fragen, Wünsche nach weiteren Automatismen oder Fehler sind willkommen (GitHub; ein Forum-Thread folgt).'],
                ['type' => 'Button', 'caption' => 'Zum Repository', 'onClick' => "echo '" . self::REPO_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'AUTOMAT_AckForumHint($id);'],
            ],
        ];
    }

    private function LicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    public function GetConfigurationForm()
    {
        $base = json_decode((string)file_get_contents(__DIR__ . '/form.json'), true);
        $elements = array_values(array_filter([
            $this->PurposeIntro(), $this->NewsBanner(), $this->DocPanel(),
            $this->AutomationsPanel(), $this->DiscoverPanel(), $this->CatalogPanel(), $this->ModePanel(), $this->MessagesPanel(),
            $this->ForumHint(), $this->LicenseHint(),
        ]));
        return json_encode(['elements' => $elements, 'actions' => [], 'status' => $base['status'] ?? []]);
    }
}
