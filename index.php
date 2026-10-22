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
main { max-width:1400px; margin:0 auto; padding:24px 32px 50px; }
.toolbar { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:18px; }
button { border:1px solid var(--line); border-radius:7px; color:var(--text); background:#21262d; padding:9px 14px; cursor:pointer; }
button:hover { border-color:var(--blue); } .status { color:var(--muted); font-size:14px; }
.grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:16px; }
.card { background:var(--panel); border:1px solid var(--line); border-radius:10px; overflow:hidden; }
.card-head { padding:16px 18px; display:flex; justify-content:space-between; gap:12px; border-bottom:1px solid var(--line); }
.card h2 { margin:0; font-size:17px; } .device-path { color:var(--muted); font-family:monospace; font-size:13px; margin-top:4px; }
.badge { align-self:flex-start; border-radius:999px; padding:4px 9px; font-size:12px; font-weight:700; white-space:nowrap; }
.good { color:#b7f0bd; background:#17351d; } .warn { color:#ffe1a1; background:#3a2c0d; } .bad { color:#ffb4ae; background:#4a1717; } .neutral { color:#c9d1d9; background:#30363d; }
.rows { padding:12px 18px 16px; } .row { display:flex; justify-content:space-between; gap:18px; border-bottom:1px solid #242b33; padding:8px 0; font-size:14px; } .row:last-child{border:0}.label{color:var(--muted)} .value{text-align:right; word-break:break-word}
.empty, .error { padding:22px; border:1px dashed var(--line); border-radius:10px; color:var(--muted); } .error { color:#ffb4ae; border-color:#6e2b2b; }
details { margin-top:12px; } summary { cursor:pointer; color:var(--blue); } pre { white-space:pre-wrap; overflow:auto; max-height:360px; font-size:11px; background:#0b0f14; padding:12px; border-radius:6px; }
@media(max-width:600px){ header,main{padding-left:16px;padding-right:16px;} }
</style>
</head>
<body>
<header><h1>Smart Storage Monitor</h1><div class="subtitle">Lokale SMART-Übersicht für diesen Linux-Server</div></header>
<main>
<div class="toolbar"><button id="refresh">Laufwerke neu einlesen</button><span id="status" class="status">Initialisiere …</span></div>
<div id="content" class="grid"></div>
</main>
<script>
(function () {
  'use strict';
  var content = document.getElementById('content');
  var status = document.getElementById('status');
  var refresh = document.getElementById('refresh');
  function esc(v) { var d=document.createElement('div'); d.textContent=(v===null||v===undefined?'—':String(v)); return d.innerHTML; }
  function api(path) { return fetch('?api=' + path, {headers:{'Accept':'application/json'}}).then(function(r){ return r.json().then(function(j){ if(!r.ok || j.ok===false) throw new Error(j.error || 'Backend-Fehler'); return j; }); }); }
  function value(obj, keys) { var x=obj; for(var i=0;i<keys.length;i++){ if(!x || typeof x!=='object' || !(keys[i] in x)) return null; x=x[keys[i]]; } return x; }
  function card(device, result) {
    var d=result && result.data ? result.data : {};
    var passed=value(d,['smart_status','passed']);
    var statusText = passed === true ? 'PASSED' : (passed === false ? 'FAILED' : (result && result.available ? 'ERKANNT' : 'FEHLER'));
    var cls = passed === true ? 'good' : (passed === false ? 'bad' : 'neutral');
    var rows=[
      ['Hersteller', value(d,['model_family']) || value(d,['device','model_family']) || value(d,['model_name'])],
      ['Modell', value(d,['model_name']) || value(d,['device','name'])],
      ['Seriennummer', value(d,['serial_number'])],
      ['Firmware', value(d,['firmware_version'])],
      ['Schnittstelle', value(d,['device','protocol'])],
      ['Temperatur', value(d,['temperature','current']) === null ? null : value(d,['temperature','current']) + ' °C'],
      ['Betriebsstunden', value(d,['power_on_time','hours'])],
      ['SMART-Exitcode', result ? result.exit_code : null]
    ];
    var html='<article class="card"><div class="card-head"><div><h2>'+esc(device.device)+'</h2><div class="device-path">'+esc(device.type || 'auto')+'</div></div><span class="badge '+cls+'">'+esc(statusText)+'</span></div><div class="rows">';
    rows.forEach(function(r){ html+='<div class="row"><span class="label">'+esc(r[0])+'</span><span class="value">'+esc(r[1])+'</span></div>'; });
    if(result && result.raw){ html+='<details><summary>Rohantwort anzeigen</summary><pre>'+esc(result.raw)+'</pre></details>'; }
    else if(result && result.data){ html+='<details><summary>SMART-JSON anzeigen</summary><pre>'+esc(JSON.stringify(result.data,null,2))+'</pre></details>'; }
    html+='</div></article>'; return html;
  }
  function load() {
    content.innerHTML='<div class="empty">Lese Laufwerke mit smartctl ein …</div>'; status.textContent='Lese Daten …';
    api('scan').then(function(scan){
      if(!scan.devices.length){ content.innerHTML='<div class="empty">Keine Laufwerke gefunden. Ist smartctl installiert und hat der Webserver Zugriff auf /dev?</div>'; status.textContent='0 Laufwerke'; return; }
      return Promise.all(scan.devices.map(function(dev){ return api('device&device='+encodeURIComponent(dev.device)+(dev.type?'&type='+encodeURIComponent(dev.type):'')); }))
        .then(function(results){ content.innerHTML=results.map(function(x,i){ return card(scan.devices[i],x.result); }).join(''); status.textContent=scan.count+' Laufwerk(e) · Host '+scan.host; });
    }).catch(function(err){ content.innerHTML='<div class="error">'+esc(err.message)+'</div>'; status.textContent='Backend nicht verfügbar'; });
  }
  refresh.addEventListener('click',load); load();
}());
</script>
</body>
</html>
