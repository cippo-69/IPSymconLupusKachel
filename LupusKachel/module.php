<?php

declare(strict_types=1);

/**
 * Lupus Kachel (HTML-SDK)
 *
 * Kachel für eine Lupus-Alarmzentrale (XT1 Plus / XT2 Plus / XT3, LAN):
 * Modus je Bereich, Alarm, Zustand der Zentrale, alle Geräte mit Batterie, Sabotage, Funk,
 * Tür-/Fensterkontakte offen/zu und Heizkörperthermostate (Ist, Soll, Ventil).
 *
 * NUR LESEND: GET /action/panelCondGet und /action/deviceListGet (Fallback /action/deviceGet).
 * Die Kachel schaltet nichts an der Anlage (kein Scharf/Unscharf, keine Thermostate).
 *
 * Die JSON-API der Zentrale ist nicht offiziell dokumentiert (Quellen: lupupy, ioBroker.lupusec).
 * Ein Firmware-Update der Zentrale kann sie ändern.
 */
class LupusKachel extends IPSModule
{
    private const MODES = [0 => 'Unscharf', 1 => 'Scharf', 2 => 'Home'];

    /** Platzhalter der Lupus-Oberfläche → Klartext; unbekannte bleiben als Rohtext sichtbar */
    private const TXT = [
        'WEB_MSG_DC_CLOSE' => 'geschlossen',
        'WEB_MSG_DC_OPEN'  => 'offen',
        'WEB_MSG_NORMAL'   => 'normal',
        'WEB_MSG_NA'       => 'n. v.',
        'WEB_MSG_STRONG'   => '',
        'WEB_MSG_WEAK'     => 'schwach',
        'WEB_MSG_GOOD'     => 'gut',
        'WEB_MSG_TAMPER'   => 'Sabotage',
    ];

    /** Zustand der Zentrale aus panelCondGet (Feld _ok = 1 heißt in Ordnung) */
    private const PANEL = [
        'ac_activation' => 'Stromversorgung',
        'battery'       => 'Akku',
        'tamper'        => 'Sabotage',
        'interference'  => 'Funkstörung',
        'sig_gsm'       => 'GSM',
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('Interval', 30);
        $this->RegisterPropertyBoolean('ShowWebLink', true);
        $this->RegisterPropertyString('Notes', '[]');

        $this->RegisterAttributeString('State', '{}');

        $this->RegisterTimer('Update', 0, 'LUPK_Update($_IPS[\'TARGET\']);');

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->WatchDesign();

        if (!$this->Configured()) {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(104);
            $this->Push();
            return;
        }
        // erste Abfrage kurz nach dem Start (ApplyChanges nicht blockieren), danach im Intervall
        $this->SetTimerInterval('Update', 2000);
        $this->Push();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IM_CHANGESETTINGS) { // Theme-Wechsel in „Visu Design“
            $this->Push();
        }
    }

    public function GetVisualizationTile()
    {
        $initial = '<script>handleMessage(' . json_encode(json_encode($this->BuildState())) . ');</script>';
        return $this->DesignStyle() . file_get_contents(__DIR__ . '/module.html') . $initial;
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Refresh':
                $this->Update();
                break;
            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
    }

    /** Zentrale abfragen, Zustand speichern und an die Kachel senden. */
    public function Update(): bool
    {
        $this->SetTimerInterval('Update', max(10, $this->ReadPropertyInteger('Interval')) * 1000);
        if (!$this->Configured()) {
            $this->SetStatus(104);
            return false;
        }

        $ch = $this->Curl();
        $p = $this->Req($ch, 'panelCondGet');
        if (isset($p['_err'])) {
            curl_close($ch);
            $old = json_decode($this->ReadAttributeString('State'), true) ?: [];
            $old['ok'] = false;
            $old['err'] = $p['_err'];
            $old['tsErr'] = time();
            $this->WriteAttributeString('State', json_encode($old));
            $this->SetStatus(($p['_code'] ?? 0) === 401 ? 202 : 201);
            $this->SendDebug('Fehler', $p['_err'], 0);
            $this->Push();
            return false;
        }
        $d = $this->Req($ch, 'deviceListGet');
        if (isset($d['_err'])) {
            $d = $this->Req($ch, 'deviceGet');
        }
        curl_close($ch);

        $state = $this->Parse($p, isset($d['_err']) ? null : $d);
        $state['devErr'] = $d['_err'] ?? '';
        $this->WriteAttributeString('State', json_encode($state));
        $this->SetStatus(IS_ACTIVE);
        $this->Push();
        return true;
    }

    // ---------------------------------------------------------------- Auswertung

    private function Parse(array $p, ?array $d): array
    {
        $forms = $p['forms'] ?? [];
        $upd = $p['updates'] ?? [];

        $modes = [];
        foreach ([1, 2] as $a) {
            $m = $forms['pcondform' . $a]['mode'] ?? null;
            if ($m === null && isset($upd['mode_a' . $a]) && preg_match('/AREA_MODE_(\d+)/', (string) $upd['mode_a' . $a], $mm)) {
                $m = $mm[1];
            }
            if ($m !== null) {
                $modes[] = ['area' => $a, 'mode' => (int) $m, 'txt' => self::MODES[(int) $m] ?? 'Modus ' . $m];
            }
        }

        $issues = [];
        foreach (self::PANEL as $k => $label) {
            if (isset($upd[$k . '_ok']) && (string) $upd[$k . '_ok'] !== '1') {
                $t = $this->Txt($upd[$k] ?? '');
                $issues[] = ['who' => 'Zentrale', 'what' => $label . ($t !== '' && $t !== $label ? ' (' . $t . ')' : ' gemeldet')];
            }
        }

        $devices = [];
        foreach ($d === null ? [] : $this->Rows($d) as $s) {
            $typ = (int) ($s['type'] ?? 0);
            $status = (string) ($s['status'] ?? '');
            $name = trim((string) $s['name']);
            $rssiT = (string) ($s['rssi'] ?? '');
            $rssiN = preg_match('/(\d+)\s*$/', $rssiT, $mr) ? (int) $mr[1] : null;
            $dev = [
                'name'  => $name,
                'zone'  => (int) ($s['zone'] ?? 0),
                'type'  => $typ,
                'bat'   => (string) ($s['battery_ok'] ?? '1') === '1',
                'sab'   => (string) ($s['tamper_ok'] ?? '1') === '1',
                'alarm' => $this->Txt($s['alarm_status'] ?? ''),
                'rssi'  => $rssiN,
                'rssiT' => $rssiN === null ? $this->Txt($rssiT) : $this->Txt(preg_replace('/\s*\d+\s*$/', '', $rssiT)),
                'weak'  => strpos($rssiT, 'WEB_MSG_WEAK') !== false,
            ];

            if ($typ === 79 || strpos($status, 'WEB_MSG_TRV') !== false) {
                $dev['group'] = 'thermo';
                $dev['ist'] = $this->Num('/\{WEB_MSG_TS_DEGREE\}\s*([-\d.,]+)/', $status);
                $dev['soll'] = $this->Num('/\{WEB_MSG_TRV_SETPOINT\}\s*([\d.,]+)/', $status);
                $dev['valve'] = $this->Num('/\{WEB_MSG_TRV_VALVE\}\s*([\d.,]+)/', $status);
                $dev['op'] = strpos($status, '{WEB_MSG_TRV_OFF}') !== false ? 'aus'
                    : (strpos($status, '{WEB_MSG_TRV_AUTO}') !== false ? 'Zeitplan'
                    : (strpos($status, '{WEB_MSG_TRV_MANUAL}') !== false ? 'manuell' : ''));
                if ($dev['ist'] === null) {
                    $issues[] = ['who' => $name, 'what' => 'meldet keine Werte'];
                }
            } elseif ($typ === 4) {
                $dev['group'] = 'contact';
                $dev['open'] = (string) ($s['status_ex'] ?? '0') === '1';
                $dev['state'] = $dev['open'] ? 'offen' : ($this->Txt($status) ?: 'geschlossen');
            } elseif ($typ === 9) {
                $dev['group'] = 'motion';
            } else {
                $dev['group'] = 'other';
                $dev['state'] = $this->Txt($status);
            }

            if (!$dev['bat']) {
                $issues[] = ['who' => $name, 'what' => 'Batterie leer'];
            }
            if (!$dev['sab']) {
                $issues[] = ['who' => $name, 'what' => 'Sabotage'];
            }
            if ($dev['alarm'] !== '') {
                $issues[] = ['who' => $name, 'what' => $dev['alarm']];
            }
            if ($dev['weak']) {
                $issues[] = ['who' => $name, 'what' => 'Funk schwach' . ($rssiN !== null ? ' (' . $rssiN . ')' : '')];
            }
            $devices[] = $dev;
        }
        usort($devices, fn ($x, $y) => [$x['zone'], $x['name']] <=> [$y['zone'], $y['name']]);

        return [
            'ok'      => true,
            'err'     => '',
            'ts'      => time(),
            'modes'   => $modes,
            'alarm'   => (string) ($upd['alarm_ex'] ?? '0') !== '0',
            'issues'  => $issues,
            'devices' => $devices,
        ];
    }

    private function BuildState(): array
    {
        if (!$this->Configured()) {
            return ['error' => 'Adresse, Benutzer und Passwort der Zentrale in der Instanz eintragen.'];
        }
        $s = json_decode($this->ReadAttributeString('State'), true) ?: [];
        $notes = [];
        foreach ((array) json_decode($this->ReadPropertyString('Notes'), true) as $n) {
            if (trim((string) ($n['Label'] ?? '')) !== '') {
                $notes[] = ['label' => (string) $n['Label'], 'on' => (bool) ($n['Active'] ?? false),
                            'state' => (string) ($n['State'] ?? ''), 'info' => (string) ($n['Info'] ?? '')];
            }
        }
        $host = trim($this->ReadPropertyString('Host'));
        return $s + [
            'error'    => '',
            'now'      => time(),
            'interval' => max(10, $this->ReadPropertyInteger('Interval')),
            'web'      => $this->ReadPropertyBoolean('ShowWebLink') ? 'https://' . $host : '',
            'notes'    => $notes,
        ];
    }

    private function Push(): void
    {
        $this->UpdateVisualizationValue(json_encode(['design' => $this->DesignCSS()] + $this->BuildState()));
    }

    // ---------------------------------------------------------------- HTTP

    private function Configured(): bool
    {
        return trim($this->ReadPropertyString('Host')) !== ''
            && $this->ReadPropertyString('Username') !== ''
            && $this->ReadPropertyString('Password') !== '';
    }

    private function Curl()
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->ReadPropertyString('Username') . ':' . $this->ReadPropertyString('Password'),
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => false, // Zentrale nutzt ein selbstsigniertes Zertifikat
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_COOKIEFILE     => '',    // Sitzung über beide Anfragen halten
            CURLOPT_HTTPGET        => true,
        ]);
        return $ch;
    }

    private function Req($ch, string $path): array
    {
        $host = preg_replace('#^https?://#i', '', trim($this->ReadPropertyString('Host')));
        curl_setopt($ch, CURLOPT_URL, 'https://' . rtrim($host, '/') . '/action/' . $path);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            return ['_err' => curl_error($ch), '_code' => 0];
        }
        if ($code === 401) {
            return ['_err' => 'Benutzer oder Passwort falsch', '_code' => 401];
        }
        if ($code !== 200) {
            return ['_err' => 'HTTP ' . $code, '_code' => $code];
        }
        // Die Zentrale liefert teils Steuerzeichen im JSON
        $j = json_decode((string) preg_replace('/[\x00-\x1F]+/', ' ', $raw), true);
        $this->SendDebug($path, substr((string) $raw, 0, 2000), 0);
        return is_array($j) ? $j : ['_err' => 'keine gültige JSON-Antwort', '_code' => $code];
    }

    /** sammelt alle Einträge mit sid + name, egal wie tief die Antwort verschachtelt ist */
    private function Rows($node): array
    {
        if (!is_array($node)) {
            return [];
        }
        if (isset($node['sid'], $node['name']) && !is_array($node['name'])) {
            return [$node];
        }
        $out = [];
        foreach ($node as $sub) {
            $out = array_merge($out, $this->Rows($sub));
        }
        return $out;
    }

    private function Txt($s): string
    {
        $t = preg_replace_callback('/\{([A-Z0-9_]+)\}/', fn ($m) => self::TXT[$m[1]] ?? $m[1], (string) $s);
        $t = preg_replace_callback('/\bWEB_MSG_[A-Z0-9_]+\b/', fn ($m) => self::TXT[$m[0]] ?? $m[0], (string) $t);
        return trim((string) preg_replace('/\s+/', ' ', (string) $t));
    }

    private function Num(string $rx, string $text): ?float
    {
        return preg_match($rx, $text, $m) ? (float) str_replace(',', '.', $m[1]) : null;
    }

    // ---------------------------------------------------------------- Farben (Visu Design)

    private function DesignStyle(): string
    {
        return '<style id="w5d">' . $this->DesignCSS() . '</style>';
    }

    private function DesignID(): int
    {
        $guid = '{018EEC85-8FFC-4E1F-A966-702C4BBDC2E8}';
        if (!IPS_ModuleExists($guid)) {
            return 0;
        }
        $ids = IPS_GetInstanceListByModuleID($guid);
        sort($ids);
        return $ids[0] ?? 0;
    }

    private function DesignCSS(): string
    {
        $id = $this->DesignID();
        return $id > 0 && function_exists('W5D_GetCSS') ? W5D_GetCSS($id) : '';
    }

    private function WatchDesign(): void
    {
        $id = $this->DesignID();
        if ($id > 0) {
            $this->RegisterMessage($id, IM_CHANGESETTINGS);
        }
    }
}
