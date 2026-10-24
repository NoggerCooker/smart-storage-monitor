<?php
/**
 * Smart Storage Monitor - single-file SMART dashboard
 * Requires: PHP CLI/Apache and smartmontools (smartctl) on the host.
 * Run locally: php -S 127.0.0.1:8080 index.php
 */

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function json_response($payload, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function smartctl_binary() {
    $candidates = array('/usr/sbin/smartctl', '/usr/bin/smartctl');
    foreach ($candidates as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }
    $path = trim((string)shell_exec('command -v smartctl 2>/dev/null'));
    if ($path !== '' && is_executable($path)) {
        return $path;
    }
    return null;
}

function run_command($command, &$exitCode) {
    $output = array();
    $exitCode = 127;
    exec($command . ' 2>&1', $output, $exitCode);
    return implode("\n", $output);
}

function require_smartctl() {
    $binary = smartctl_binary();
    if ($binary === null) {
        json_response(array(
            'ok' => false,
            'error' => 'smartctl wurde nicht gefunden.',
            'hint' => 'Installiere smartmontools und stelle sicher, dass der Webserver darauf zugreifen darf.'
        ), 503);
    }
    return $binary;
}

function valid_device($device) {
    return is_string($device) && preg_match('#^/dev/[A-Za-z0-9._+:/-]+$#', $device);
}

function parse_json_output($raw) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    $start = strpos($raw, '{');
    $end = strrpos($raw, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return null;
}

function scan_devices() {
    $binary = require_smartctl();
    $exitCode = 0;
    $raw = run_command(escapeshellcmd($binary) . ' --scan-open', $exitCode);
    $devices = array();
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '/dev/') !== 0) {
            continue;
        }
        $parts = preg_split('/\s+/', $line);
        $device = isset($parts[0]) ? $parts[0] : '';
        if (!valid_device($device)) {
            continue;
        }
        $type = null;
        if (isset($parts[1]) && $parts[1] === '-d' && isset($parts[2])) {
            $type = preg_match('/^[A-Za-z0-9._+-]+$/', $parts[2]) ? $parts[2] : null;
        }
        $key = $device . '|' . (string)$type;
        $devices[$key] = array('device' => $device, 'type' => $type);
    }
    return array_values($devices);
}

function device_data($device, $type) {
    $binary = require_smartctl();
    if (!valid_device($device)) {
        json_response(array('ok' => false, 'error' => 'Ungültiger Gerätepfad.'), 400);
    }
    $args = ' -x -j ' . escapeshellarg($device);
    if ($type !== null && preg_match('/^[A-Za-z0-9._+-]+$/', $type)) {
        $args .= ' -d ' . escapeshellarg($type);
    }
    $exitCode = 0;
    $raw = run_command(escapeshellcmd($binary) . $args, $exitCode);
    $json = parse_json_output($raw);
    return array(
        'device' => $device,
        'type' => $type,
        'exit_code' => $exitCode,
        'available' => $json !== null,
        'data' => $json,
        'raw' => $json === null ? $raw : null
    );
}

function remote_json_request($url) {
    $context = stream_context_create(array('http' => array(
        'method' => 'GET',
        'timeout' => 10,
        'ignore_errors' => true,
        'header' => "Accept: application/json\r\n"
    )));
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        return array('ok' => false, 'error' => 'Der entfernte Server konnte nicht erreicht werden.');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return array('ok' => false, 'error' => 'Der entfernte Server hat kein gültiges JSON geliefert.');
    }
    if (isset($decoded['ok']) && $decoded['ok'] === false) {
        return array('ok' => false, 'error' => isset($decoded['error']) ? $decoded['error'] : 'Fehler auf dem entfernten Server.');
    }
    return array('ok' => true, 'data' => $decoded);
}

function remote_server_data($host, $port) {
    if (!filter_var($host, FILTER_VALIDATE_IP)) {
        return array('ok' => false, 'error' => 'Bitte eine gültige IPv4- oder IPv6-Adresse eingeben.');
    }
    $port = filter_var($port, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 65535)));
    if ($port === false) {
        return array('ok' => false, 'error' => 'Der Port muss zwischen 1 und 65535 liegen.');
    }
    $authority = strpos($host, ':') !== false ? '[' . $host . ']' : $host;
    $scriptPath = isset($_SERVER['SCRIPT_NAME']) && $_SERVER['SCRIPT_NAME'] !== '' ? $_SERVER['SCRIPT_NAME'] : '/index_v2.php';
    $base = 'http://' . $authority . ':' . $port . $scriptPath;
    $scanUrl = $base . '?api=scan';
    $scan = remote_json_request($scanUrl);
    if (!$scan['ok']) {
        return array('ok' => false, 'error' => $scan['error']);
    }
    $scanData = $scan['data'];
    $devices = array();
    foreach (isset($scanData['devices']) && is_array($scanData['devices']) ? $scanData['devices'] : array() as $device) {
        if (!isset($device['device'])) {
            continue;
        }
        $params = array('api' => 'device', 'device' => $device['device']);
        if (isset($device['type']) && $device['type'] !== null && $device['type'] !== '') {
            $params['type'] = $device['type'];
        }
        $result = remote_json_request($base . '?' . http_build_query($params));
        if ($result['ok'] && isset($result['data']['result'])) {
            $devices[] = $result['data']['result'];
        } else {
            $devices[] = array('device' => $device['device'], 'type' => isset($device['type']) ? $device['type'] : null, 'available' => false, 'exit_code' => null, 'data' => null, 'raw' => $result['error']);
        }
    }
    return array('ok' => true, 'server' => array(
        'host' => $host,
        'port' => $port,
        'name' => isset($scanData['host']) ? $scanData['host'] : $host,
        'devices' => $devices,
        'count' => count($devices)
    ));
}

$api = isset($_GET['api']) ? (string)$_GET['api'] : '';
if ($api !== '') {
    if ($api === 'scan') {
        $devices = scan_devices();
        json_response(array('ok' => true, 'host' => gethostname(), 'devices' => $devices, 'count' => count($devices)));
    }
    if ($api === 'device') {
        $device = isset($_GET['device']) ? (string)$_GET['device'] : '';
        $type = isset($_GET['type']) && $_GET['type'] !== '' ? (string)$_GET['type'] : null;
        $result = device_data($device, $type);
        json_response(array('ok' => true, 'host' => gethostname(), 'result' => $result));
    }
    if ($api === 'remote') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            json_response(array('ok' => false, 'error' => 'Ungültige Anfrage. Erwartet wird JSON mit host und port.'), 400);
        }
        $remote = remote_server_data(isset($input['host']) ? (string)$input['host'] : '', isset($input['port']) ? $input['port'] : null);
        json_response($remote, $remote['ok'] ? 200 : 400);
    }
    json_response(array('ok' => false, 'error' => 'Unbekannte API-Route.'), 404);
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Smart Storage Monitor</title>
<style>
:root { color-scheme: dark; --bg:#0d1117; --panel:#161b22; --line:#30363d; --text:#e6edf3; --muted:#8b949e; --green:#3fb950; --yellow:#d29922; --red:#f85149; --blue:#58a6ff; }
* { box-sizing:border-box; }
body { margin:0; font-family:system-ui,-apple-system,Segoe UI,sans-serif; background:var(--bg); color:var(--text); }
header { padding:28px 32px 20px; border-bottom:1px solid var(--line); background:#11161d; }
h1 { margin:0 0 6px; font-size:25px; } .subtitle { color:var(--muted); }
main { max-width:1600px; margin:0 auto; padding:24px 32px 50px; }
.toolbar { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:18px; }
button { border:1px solid var(--line); border-radius:7px; color:var(--text); background:#21262d; padding:9px 14px; cursor:pointer; }
button:hover { border-color:var(--blue); } .status { color:var(--muted); font-size:14px; }
.grid { display:grid; grid-template-columns:repeat(auto-fill,360px); gap:16px; align-items:stretch; justify-content:start; }
.server-section { margin-bottom:28px; }
.server-overview { margin-top:24px; }
.server-heading { display:flex; align-items:baseline; justify-content:space-between; gap:14px; margin:0 0 12px; }
.server-heading h2 { margin:0; font-size:19px; }
.server-heading span { color:var(--muted); font-size:13px; }
.remote-form { display:flex; gap:8px; align-items:center; flex-wrap:wrap; width:100%; }
.remote-form label { color:var(--muted); font-size:14px; }
.remote-form input { min-width:150px; border:1px solid var(--line); border-radius:7px; background:#0b0f14; color:var(--text); padding:9px 10px; }
.card { width:360px; max-width:100%; min-width:0; min-height:620px; display:flex; flex-direction:column; background:var(--panel); border:1px solid var(--line); border-radius:10px; overflow:hidden; cursor:pointer; transition:border-color .15s, transform .15s; }
.card:hover { border-color:var(--blue); transform:translateY(-1px); }
.summary-card { cursor:default; }
.summary-card:hover { transform:none; }
.card:focus { outline:2px solid var(--blue); outline-offset:2px; }
.card-head { padding:16px 18px; display:flex; justify-content:space-between; align-items:flex-start; gap:12px; border-bottom:1px solid var(--line); }
.card h2 { margin:0 0 4px; font-size:17px; line-height:1.25; font-weight:650; } .device-path { color:var(--muted); font-family:monospace; font-size:13px; margin-top:4px; }
.server-source { color:var(--blue); font-size:13px; font-weight:700; margin-bottom:5px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.badge { align-self:flex-start; border-radius:999px; padding:4px 9px; font-size:12px; font-weight:700; white-space:nowrap; }
.good { color:#b7f0bd; background:#17351d; border:1px solid #2d6a35; } .warn { color:#ffe1a1; background:#3a2c0d; border:1px solid #765b1b; } .bad { color:#ffb4ae; background:#4a1717; border:1px solid #8f302b; } .neutral { color:#c9d1d9; background:#30363d; border:1px solid #59636e; }
.metric-good { color:#b7f0bd; } .metric-warn { color:#ffe1a1; } .metric-bad { color:#ffb4ae; font-weight:650; }
.modal { position:fixed; inset:0; z-index:10; display:flex; align-items:center; justify-content:center; padding:24px; background:rgba(0,0,0,.72); }
.modal.hidden { display:none; }
.modal-dialog { width:min(1000px,100%); max-height:calc(100vh - 48px); overflow:auto; background:var(--panel); border:1px solid var(--line); border-radius:10px; box-shadow:0 20px 70px rgba(0,0,0,.55); }
.modal-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; padding:18px 20px; border-bottom:1px solid var(--line); position:sticky; top:0; background:var(--panel); }
.modal-head h2 { margin:0 0 4px; font-size:19px; }
.modal-body { padding:18px 20px 24px; }
.modal-close { font-size:20px; line-height:1; padding:6px 10px; }
.modal-section { margin-top:18px; } .modal-section:first-child { margin-top:0; }
.modal-section h3 { margin:0 0 8px; font-size:15px; color:var(--muted); }
.attribute-table { width:100%; border-collapse:collapse; font-size:13px; }
.attribute-table th, .attribute-table td { padding:8px 10px; border:1px solid var(--line); text-align:left; vertical-align:top; }
.attribute-table th { color:var(--muted); background:#11161d; font-weight:600; }
.attribute-table td code { color:#c9d1d9; font-family:monospace; }
.attribute-table tr.metric-good td { color:var(--text); background:rgba(63,185,80,.18); } .attribute-table tr.metric-warn td { color:var(--text); background:rgba(210,153,34,.20); } .attribute-table tr.metric-bad td { color:var(--text); background:rgba(248,81,73,.20); font-weight:650; }
.attribute-table tr.metric-good td code, .attribute-table tr.metric-warn td code, .attribute-table tr.metric-bad td code { color:var(--text); }
.modal-section pre { margin:0; }
.rows { flex:1; padding:12px 18px 16px; } .row { display:flex; justify-content:space-between; gap:18px; border-bottom:1px solid #242b33; padding:8px 0; font-size:14px; } .row:last-child{border:0}.label{color:var(--muted)} .value{text-align:right; word-break:break-word}
.rows .row.metric-good { background:#17351d; border-bottom-color:#2d6a35; border-radius:4px; padding-left:8px; padding-right:8px; } .rows .row.metric-warn { background:#3a2c0d; border-bottom-color:#765b1b; border-radius:4px; padding-left:8px; padding-right:8px; } .rows .row.metric-bad { background:#4a1717; border-bottom-color:#8f302b; border-radius:4px; padding-left:8px; padding-right:8px; }
.rows .row.metric-good .label, .rows .row.metric-good .value, .rows .row.metric-warn .label, .rows .row.metric-warn .value, .rows .row.metric-bad .label, .rows .row.metric-bad .value { color:var(--text); }
.empty, .error { padding:22px; border:1px dashed var(--line); border-radius:10px; color:var(--muted); } .error { color:#ffb4ae; border-color:#6e2b2b; }
pre { white-space:pre-wrap; overflow:auto; max-height:360px; font-size:11px; background:#0b0f14; padding:12px; border-radius:6px; }
@media(max-width:600px){ header,main{padding-left:16px;padding-right:16px;} }
@media(max-width:400px){ .grid{grid-template-columns:minmax(0,1fr);} .card{width:100%;} }
</style>
</head>
<body>
<header><h1>Smart Storage Monitor</h1><div class="subtitle">Lokale SMART-Übersicht für diesen Linux-Server</div></header>
<main>
<div class="toolbar"><button id="refresh">Laufwerke neu einlesen</button><span id="status" class="status">Initialisiere …</span></div>
<form id="remote-form" class="remote-form" autocomplete="off"><label for="remote-host">Zusätzlicher Server:</label><input id="remote-host" name="host" type="text" inputmode="decimal" placeholder="IP-Adresse, z. B. 192.168.178.63" required><input id="remote-port" name="port" type="number" min="1" max="65535" value="8080" placeholder="Port" required><button type="submit">Server hinzufügen</button><span id="remote-status" class="status">Die Eingabe wird nicht gespeichert.</span></form>
<div id="content"></div>
</main>
<div id="modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="modal-title">
  <div class="modal-dialog">
    <div class="modal-head"><div><h2 id="modal-title">Datenträgerdetails</h2><div id="modal-subtitle" class="device-path"></div></div><div><span id="modal-status" class="badge neutral">—</span><button id="modal-close" class="modal-close" type="button" aria-label="Schließen">×</button></div></div>
    <div id="modal-body" class="modal-body"></div>
  </div>
</div>
<script>
(function () {
  'use strict';
  var content = document.getElementById('content');
  var status = document.getElementById('status');
  var refresh = document.getElementById('refresh');
  var remoteForm = document.getElementById('remote-form');
  var remoteHost = document.getElementById('remote-host');
  var remotePort = document.getElementById('remote-port');
  var remoteStatus = document.getElementById('remote-status');
  var modal = document.getElementById('modal');
  var modalTitle = document.getElementById('modal-title');
  var modalSubtitle = document.getElementById('modal-subtitle');
  var modalStatus = document.getElementById('modal-status');
  var modalBody = document.getElementById('modal-body');
  var modalClose = document.getElementById('modal-close');
  function esc(v) { var d=document.createElement('div'); d.textContent=(v===null||v===undefined?'—':String(v)); return d.innerHTML; }
  function api(path) { return fetch('?api=' + path, {headers:{'Accept':'application/json'}}).then(function(r){ return r.json().then(function(j){ if(!r.ok || j.ok===false) throw new Error(j.error || 'Backend-Fehler'); return j; }); }); }
  function apiPost(path, payload) { return fetch('?api=' + path, {method:'POST', headers:{'Accept':'application/json','Content-Type':'application/json'}, body:JSON.stringify(payload)}).then(function(r){ return r.json().then(function(j){ if(!r.ok || j.ok===false) throw new Error(j.error || 'Backend-Fehler'); return j; }); }); }
  function value(obj, keys) { var x=obj; for(var i=0;i<keys.length;i++){ if(!x || typeof x!=='object' || !(keys[i] in x)) return null; x=x[keys[i]]; } return x; }
  function numberValue(v) { return typeof v === 'number' && isFinite(v) ? v : null; }
  function sumScsiUncorrectedErrors(d) {
    var log=value(d,['scsi_error_counter_log']);
    if(!log || typeof log!=='object') return null;
    var total=0, found=false;
    ['read','write','verify'].forEach(function(kind){
      var n=numberValue(value(log,[kind,'total_uncorrected_errors']));
      if(n !== null){ total += n; found=true; }
    });
    return found ? total : null;
  }
  function formatDays(hours) {
    var n=numberValue(hours);
    if(n === null) return null;
    var days=n / 24;
    return days >= 365 ? (days / 365).toFixed(1).replace('.', ',') + ' Jahre' : days.toFixed(1).replace('.', ',') + ' Tage';
  }
  function formatGigabytes(v) {
    if(v === null || v === undefined || v === '') return null;
    return String(v).replace('.', ',') + ' GB';
  }
  function formatDataUnitsAsGigabytes(v) {
    var units=numberValue(v);
    if(units === null) return null;
    var gigabytes=(units * 512000) / 1000000000;
    return gigabytes.toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' GB';
  }
  function nvmeLifetimeValue(d, key) {
    return formatDataUnitsAsGigabytes(value(d,['nvme_smart_health_information_log',key]));
  }
  function capacityBytes(d) {
    var bytes=numberValue(value(d,['user_capacity','bytes']));
    if(bytes !== null) return bytes;
    var blocks=numberValue(value(d,['user_capacity','blocks']));
    var blockSize=numberValue(value(d,['logical_block_size'])) || 512;
    return blocks === null ? null : blocks * blockSize;
  }
  function formatCapacity(bytes) {
    if(bytes === null || bytes === undefined) return '—';
    if(bytes >= 1000000000000) return (bytes / 1000000000000).toFixed(2).replace('.', ',') + ' TB';
    if(bytes >= 1000000000) return (bytes / 1000000000).toFixed(2).replace('.', ',') + ' GB';
    if(bytes >= 1000000) return (bytes / 1000000).toFixed(1).replace('.', ',') + ' MB';
    return Math.round(bytes).toLocaleString('de-DE') + ' B';
  }
  function enduranceUsed(d) {
    var direct=numberValue(value(d,['scsi_percentage_used_endurance_indicator']));
    if(direct !== null) return direct;
    var nvme=numberValue(value(d,['nvme_smart_health_information_log','percentage_used']));
    if(nvme !== null) return nvme;
    var table=value(d,['ata_smart_attributes','table']);
    if(Array.isArray(table)) {
      for(var i=0;i<table.length;i++) {
        var name=String(table[i].name || '').toLowerCase();
        if(name.indexOf('percent_lifetime_remain') >= 0 || name.indexOf('ssd_life_left') >= 0 || name.indexOf('media_wearout') >= 0 || name.indexOf('remaining_life') >= 0) {
          var remaining=numberValue(table[i].value);
          if(remaining !== null && remaining >= 0 && remaining <= 100) return 100 - remaining;
        }
      }
    }
    return null;
  }
  function powerCycles(d) {
    var direct=numberValue(value(d,['power_cycle_count']));
    if(direct !== null) return direct;
    var scsi=numberValue(value(d,['scsi_start_stop_cycle_counter','accumulated_start_stop_cycles']));
    if(scsi !== null) return scsi;
    var nvme=numberValue(value(d,['nvme_smart_health_information_log','power_cycles']));
    if(nvme !== null) return nvme;
    var table=value(d,['ata_smart_attributes','table']);
    if(Array.isArray(table)) {
      for(var i=0;i<table.length;i++) {
        var name=String(table[i].name || '').toLowerCase();
        if(table[i].id === 12 || name.indexOf('power_cycle') >= 0 || name.indexOf('power cycle') >= 0) {
          var raw=numberValue(value(table[i],['raw','value']));
          if(raw !== null) return raw;
        }
      }
    }
    return null;
  }
  function metricClass(label, raw, d, result) {
    var n=numberValue(raw);
    if(label === 'SMART-Status') return raw === 'PASSED' ? 'metric-good' : (raw === 'FAILED' ? 'metric-bad' : 'metric-warn');
    if(n === null) return '';
    if(label === 'Temperatur') {
      var trip=numberValue(value(d,['temperature','drive_trip']));
      if(trip !== null && trip > 0) return n >= trip - 5 ? 'metric-bad' : (n >= trip - 15 ? 'metric-warn' : 'metric-good');
      return n >= 60 ? 'metric-bad' : (n >= 50 ? 'metric-warn' : 'metric-good');
    }
    if(label === 'Endurance Used') return n >= 90 ? 'metric-bad' : (n >= 70 ? 'metric-warn' : 'metric-good');
    if(label === 'Nicht korrigierbare Fehler' || label === 'Gewachsene Defektliste') return n === 0 ? 'metric-good' : (n < 10 ? 'metric-warn' : 'metric-bad');
    if(label === 'smartctl-Exitcode') return n === 0 ? 'metric-good' : (n === 4 ? 'metric-warn' : 'metric-bad');
    return '';
  }
  function scsiAttributeData(d) {
    return value(d,['ata_smart_attributes','table']) || {
      scsi_error_counter_log: value(d,['scsi_error_counter_log']),
      scsi_grown_defect_list: value(d,['scsi_grown_defect_list']),
      scsi_pending_defects: value(d,['scsi_pending_defects']),
      scsi_percentage_used_endurance_indicator: value(d,['scsi_percentage_used_endurance_indicator']),
      scsi_environmental_reports: value(d,['scsi_environmental_reports'])
    };
  }
  function rawAttributeValue(attribute) {
    var raw=attribute ? attribute.raw : null;
    if(raw && typeof raw==='object') {
      if(raw.string !== undefined && raw.string !== '') return raw.string;
      if(raw.value !== undefined) return raw.value;
    }
    return raw;
  }
  function attributeClass(attribute, d) {
    var name=String(attribute && attribute.name || '').toLowerCase();
    if(attribute && attribute.when_failed) return 'metric-bad';
    var threshold=numberValue(attribute && (attribute.thresh !== undefined ? attribute.thresh : attribute.threshold));
    var normalized=numberValue(attribute && attribute.value);
    if(threshold !== null && threshold > 0 && normalized !== null && normalized <= threshold) return 'metric-bad';
    if(name.indexOf('percent_lifetime_remain') >= 0 || name.indexOf('ssd_life_left') >= 0 || name.indexOf('media_wearout') >= 0 || name.indexOf('remaining_life') >= 0) return metricClass('Endurance Used', normalized === null ? null : 100 - normalized, d, null);
    if(name.indexOf('temperature') >= 0) return metricClass('Temperatur', numberValue(value(d,['temperature','current'])), d, null);
    if(name.indexOf('uncorrect') >= 0 || name.indexOf('pending') >= 0 || name.indexOf('realloc') >= 0 || name.indexOf('defect') >= 0) {
      var raw=numberValue(value(attribute,['raw','value']));
      return raw === null ? '' : (raw === 0 ? 'metric-good' : 'metric-warn');
    }
    return '';
  }
  function scsiAttributeClass(key, val, d) {
    if(key === 'scsi_grown_defect_list') return metricClass('Gewachsene Defektliste', typeof val === 'object' ? value(val,['count']) : val, d, null);
    if(key === 'scsi_pending_defects') return metricClass('Nicht korrigierbare Fehler', typeof val === 'object' ? value(val,['count']) : val, d, null);
    if(key === 'scsi_percentage_used_endurance_indicator') return metricClass('Endurance Used', val, d, null);
    if(key === 'scsi_environmental_reports') return metricClass('Temperatur', value(val,['temperature_1','current']), d, null);
    return '';
  }
  function attributeTableHtml(attrs, d) {
    var html='<table class="attribute-table">';
    if(Array.isArray(attrs)) {
      html+='<thead><tr><th>ID</th><th>Attribut</th><th>Value</th><th>Worst</th><th>Threshold</th><th>Raw Value</th></tr></thead><tbody>';
      attrs.forEach(function(a){ html+='<tr class="'+attributeClass(a,d)+'"><td>'+esc(a.id)+'</td><td>'+esc(a.name)+'</td><td>'+esc(a.value)+'</td><td>'+esc(a.worst)+'</td><td>'+esc(a.thresh !== undefined ? a.thresh : a.threshold)+'</td><td><code>'+esc(rawAttributeValue(a))+'</code></td></tr>'; });
    } else {
      html+='<thead><tr><th>Parameter</th><th>Wert</th></tr></thead><tbody>';
      Object.keys(attrs || {}).forEach(function(key){
        var val=attrs[key];
        if(val === null || val === undefined) return;
        html+='<tr class="'+scsiAttributeClass(key,val,d)+'"><td><code>'+esc(key)+'</code></td><td><code>'+esc(typeof val === 'object' ? JSON.stringify(val) : val)+'</code></td></tr>';
      });
    }
    html+='</tbody></table>';
    return html;
  }
  function card(device, result, serverName) {
    var d=result && result.data ? result.data : {};
    var passed=value(d,['smart_status','passed']);
    var statusText = passed === true ? 'PASSED' : (passed === false ? 'FAILED' : (result && result.available ? 'ERKANNT' : 'FEHLER'));
    var cls = passed === true ? 'good' : (passed === false ? 'bad' : 'neutral');
    var temperature=value(d,['temperature','current']);
    if(temperature === null) temperature=value(d,['scsi_environmental_reports','temperature_1','current']);
    var uncorrected=sumScsiUncorrectedErrors(d);
    var grownDefects=value(d,['scsi_grown_defect_list']);
    if(grownDefects && typeof grownDefects==='object') grownDefects=value(grownDefects,['count']);
    var hours=value(d,['power_on_time','hours']);
    var errorLog=value(d,['scsi_error_counter_log']);
    var endurance=enduranceUsed(d);
    var lifetimeRead=formatGigabytes(value(errorLog,['read','gigabytes_processed'])) || nvmeLifetimeValue(d,'data_units_read');
    var lifetimeWritten=formatGigabytes(value(errorLog,['write','gigabytes_processed'])) || nvmeLifetimeValue(d,'data_units_written');
    var rows=[
      ['SMART-Status', statusText, statusText],
      ['Größe', formatCapacity(capacityBytes(d))],
      ['Hersteller', value(d,['scsi_vendor']) || value(d,['model_family']) || value(d,['device','model_family'])],
      ['Modell', value(d,['scsi_product']) || value(d,['scsi_model_name']) || value(d,['model_name']) || value(d,['device','name'])],
      ['Seriennummer', value(d,['serial_number'])],
      ['Firmware', value(d,['scsi_revision']) || value(d,['firmware_version'])],
      ['Schnittstelle', value(d,['device','protocol'])],
      ['Temperatur', temperature === null ? null : temperature + ' °C', temperature],
      ['Betriebsdauer', formatDays(hours)],
      ['Lifetime gelesen', lifetimeRead],
      ['Lifetime geschrieben', lifetimeWritten],
      ['Power Cycles', powerCycles(d)],
      ['Nicht korrigierbare Fehler', uncorrected],
      ['Gewachsene Defektliste', grownDefects],
      ['Endurance Used', endurance === null ? null : endurance + ' %', endurance],
      ['smartctl-Exitcode', result ? result.exit_code : null]
    ];
    var html='<article class="card disk-card" tabindex="0" role="button"><div class="card-head"><div><div class="server-source">'+esc(serverName || 'Unbekannter Server')+'</div><h2>'+esc(device.device)+'</h2><div class="device-path">'+esc(device.type || 'auto')+'</div></div><span class="badge '+cls+'">'+esc(statusText)+'</span></div><div class="rows">';
    rows.forEach(function(r){ html+='<div class="row '+metricClass(r[0], r.length > 2 ? r[2] : r[1], d, result)+'"><span class="label">'+esc(r[0])+'</span><span class="value">'+esc(r[1])+'</span></div>'; });
    html+='</div></article>'; return html;
  }
  function showModal(device, result) {
    var d=result && result.data ? result.data : {};
    var attrs=scsiAttributeData(d);
    modalTitle.textContent=device.device;
    modalSubtitle.textContent=(device.type || 'auto')+' · '+(value(d,['scsi_product']) || value(d,['model_name']) || 'Erweiterte SMART-Informationen');
    var passed=value(d,['smart_status','passed']);
    var modalStatusText=passed === true ? 'PASSED' : (passed === false ? 'FAILED' : 'ERKANNT');
    modalStatus.textContent=modalStatusText;
    modalStatus.className='badge '+(passed === true ? 'good' : (passed === false ? 'bad' : 'warn'));
    modalBody.innerHTML='<div class="modal-section"><h3>SMART-Attribute und ergänzende Gerätewerte</h3>'+attributeTableHtml(attrs,d)+'</div><div class="modal-section"><h3>Roh-JSON</h3><pre>'+esc(result && result.data ? JSON.stringify(result.data,null,2) : (result && result.raw ? result.raw : 'Keine Daten verfügbar'))+'</pre></div>';
    modal.classList.remove('hidden');
    modalClose.focus();
  }
  function closeModal() { modal.classList.add('hidden'); }

  function fetchLocalServer() {
    return api('scan').then(function(scan){
      return Promise.all(scan.devices.map(function(dev){ return api('device&device='+encodeURIComponent(dev.device)+(dev.type?'&type='+encodeURIComponent(dev.type):'')); })).then(function(results){
        return {name:scan.host || 'Lokaler Server', host:'lokal', port:null, devices:results.map(function(x){ return x.result; }), count:scan.count};
      });
    });
  }
  function summaryCard(servers) {
    var disks=[];
    servers.forEach(function(server){ server.devices.forEach(function(result){ disks.push(result); }); });
    var totalBytes=0, capacityKnown=false, passed=0, failed=0, other=0;
    disks.forEach(function(result){
      var d=result && result.data ? result.data : {};
      var bytes=capacityBytes(d);
      if(bytes !== null){ totalBytes += bytes; capacityKnown=true; }
      var smart=value(d,['smart_status','passed']);
      if(smart === true) passed++; else if(smart === false) failed++; else other++;
    });
    var rows=[
      ['Server', servers.length],
      ['Datenträger', disks.length],
      ['Gesamtspeicher', capacityKnown ? formatCapacity(totalBytes) : null],
      ['SMART PASSED', passed],
      ['SMART FAILED', failed],
      ['Weitere Status', other]
    ];
    var html='<article class="card summary-card"><div class="card-head"><div><div class="server-source">Alle Server</div><h2>Gesamtübersicht</h2><div class="device-path">Alle erkannten Datenträger</div></div><span class="badge neutral">ÜBERSICHT</span></div><div class="rows">';
    rows.forEach(function(row){
      var cls=row[0]==='SMART PASSED' ? 'metric-good' : (row[0]==='SMART FAILED' && failed > 0 ? 'metric-bad' : (row[0]==='Weitere Status' && other > 0 ? 'metric-warn' : ''));
      html+='<div class="row '+cls+'"><span class="label">'+esc(row[0])+'</span><span class="value">'+esc(row[1])+'</span></div>';
    });
    html+='</div></article>';
    return html;
  }
  function renderDashboard(servers) {
    var entries=[];
    var total=0;
    var cards=[summaryCard(servers)];
    servers.forEach(function(server){
      total += server.devices.length;
      server.devices.forEach(function(result){
        var device={device:result.device || 'unbekannt', type:result.type};
        entries.push({device:device,result:result});
        cards.push(card(device,result,server.name+' · '+server.host+(server.port ? ':'+server.port : '')));
      });
    });
    var serverNames=servers.map(function(server){ return server.name; }).join(' · ');
    content.innerHTML='<section class="server-overview"><div class="server-heading"><h2>Alle Datenträger</h2><span>'+esc(serverNames)+' · '+total+' Laufwerk(e)</span></div><div class="grid">'+(cards.length ? cards.join('') : '<div class="empty">Keine Laufwerke gefunden.</div>')+'</div></section>';
    Array.prototype.forEach.call(content.querySelectorAll('.disk-card'), function(el, i){
      el.addEventListener('click', function(){ showModal(entries[i].device, entries[i].result); });
      el.addEventListener('keydown', function(e){ if(e.key==='Enter' || e.key===' '){ e.preventDefault(); showModal(entries[i].device, entries[i].result); } });
    });
    status.textContent=total+' Laufwerk(e) · '+servers.length+' Server';
  }
  var remoteTargets=[];
  function load() {
    content.innerHTML='<div class="empty">Lese Laufwerke mit smartctl ein …</div>'; status.textContent='Lese Daten …';
    Promise.all([fetchLocalServer()].concat(remoteTargets.map(function(target){ return apiPost('remote',target).then(function(response){ return response.server; }); })))
      .then(function(servers){ renderDashboard(servers); })
      .catch(function(err){ content.innerHTML='<div class="error">'+esc(err.message)+'</div>'; status.textContent='Backend nicht verfügbar'; });
  }
  remoteForm.addEventListener('submit',function(e){
    e.preventDefault();
    var target={host:remoteHost.value.trim(), port:remotePort.value};
    remoteStatus.textContent='Verbinde …';
    apiPost('remote',target).then(function(response){
      var duplicate=remoteTargets.some(function(item){ return item.host===target.host && String(item.port)===String(target.port); });
      if(!duplicate) remoteTargets.push(target);
      remoteStatus.textContent='Server verbunden. Eingabe wird nur im Arbeitsspeicher gehalten.';
      remoteHost.value='';
      load();
    }).catch(function(err){ remoteStatus.textContent=err.message; });
  });
  modalClose.addEventListener('click',closeModal);
  modal.addEventListener('click',function(e){ if(e.target===modal) closeModal(); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape' && !modal.classList.contains('hidden')) closeModal(); });
  refresh.addEventListener('click',load); load();
}());
</script>
</body>
</html>
