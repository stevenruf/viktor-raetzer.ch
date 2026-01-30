<?php
declare(strict_types=1);

set_time_limit(0);
ini_set('memory_limit', '1024M');

function performCpuTest(int $limit = 5000000): array
{
    $start = microtime(true);

    $count = 0;
    for ($i = 2; $i < $limit; $i++) {
        $isPrime = true;
        $root = (int) sqrt($i);
        for ($j = 2; $j <= $root; $j++) {
            if ($i % $j === 0) {
                $isPrime = false;
                break;
            }
        }
        if ($isPrime) {
            $count++;
        }
    }

    $end = microtime(true);

    return [
        'limit' => $limit,
        'primes_found' => $count,
        'seconds' => $end - $start,
    ];
}

function performDiskTest(int $sizeMB = 256, bool $cleanup = true): array
{
    $dir = sys_get_temp_dir();
    $file = $dir . DIRECTORY_SEPARATOR . 'perf_disk_test_' . bin2hex(random_bytes(6)) . '.bin';

    $chunkSize = 1024 * 1024;
    $chunk = random_bytes($chunkSize);

    $bytesToWrite = $sizeMB * 1024 * 1024;

    $writeStart = microtime(true);
    $fh = @fopen($file, 'wb');
    if ($fh === false) {
        return ['error' => "Cannot open temp file for writing: {$file}"];
    }

    $written = 0;
    while ($written < $bytesToWrite) {
        $toWrite = min($chunkSize, $bytesToWrite - $written);
        $res = fwrite($fh, ($toWrite === $chunkSize) ? $chunk : substr($chunk, 0, $toWrite));
        if ($res === false) {
            fclose($fh);
            return ['error' => "Write failed for file: {$file}"];
        }
        $written += $res;
    }
    fflush($fh);
    fclose($fh);

    $writeEnd = microtime(true);
    $writeSeconds = $writeEnd - $writeStart;

    $readStart = microtime(true);
    $fh = @fopen($file, 'rb');
    if ($fh === false) {
        return ['error' => "Cannot open temp file for reading: {$file}"];
    }

    $readBytes = 0;
    while (!feof($fh)) {
        $data = fread($fh, 4 * 1024 * 1024);
        if ($data === false) {
            fclose($fh);
            return ['error' => "Read failed for file: {$file}"];
        }
        $readBytes += strlen($data);
    }
    fclose($fh);

    $readEnd = microtime(true);
    $readSeconds = $readEnd - $readStart;

    if ($cleanup) {
        @unlink($file);
    }

    $writeMBs = $writeSeconds > 0 ? ($written / 1024 / 1024) / $writeSeconds : 0.0;
    $readMBs  = $readSeconds > 0 ? ($readBytes / 1024 / 1024) / $readSeconds : 0.0;

    return [
        'file' => $file,
        'size_mb' => $sizeMB,
        'write_seconds' => $writeSeconds,
        'write_mbps' => $writeMBs,
        'read_seconds' => $readSeconds,
        'read_mbps' => $readMBs,
        'cleanup' => $cleanup,
    ];
}

function getEnvInfo(): array
{
    $opcache = null;
    if (function_exists('opcache_get_status')) {
        $status = @opcache_get_status(false);
        $opcache = is_array($status) ? ($status['opcache_enabled'] ?? null) : null;
    }

    return [
        'php_version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '',
        'host' => gethostname() ?: '',
        'time_utc' => gmdate('c'),
        'opcache_enabled' => $opcache,
    ];
}

function respondJson(array $payload, float $reqStart, string $desc): void
{
    $now = microtime(true);
    $serverElapsedSeconds = $now - $reqStart;

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    header('X-Server-Elapsed: ' . number_format($serverElapsedSeconds, 6, '.', ''));
    header('Server-Timing: app;desc="' . $desc . '";dur=' . number_format($serverElapsedSeconds * 1000, 2, '.', ''));

    $payload['_meta'] = [
        'server_elapsed_seconds' => $serverElapsedSeconds,
        'time_utc' => gmdate('c'),
    ];

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

// API
$reqStart = microtime(true);
$action = $_GET['action'] ?? '';

if ($action !== '') {
    switch ($action) {
        case 'env':
            respondJson(['env' => getEnvInfo()], $reqStart, 'env');

        case 'ping':
            respondJson(['ok' => true], $reqStart, 'ping');

        case 'cpu':
            $limit = isset($_GET['limit']) ? max(1000, (int)$_GET['limit']) : 5000000;
            respondJson(['cpu' => performCpuTest($limit)], $reqStart, 'cpu');

        case 'disk':
            $sizeMB = isset($_GET['sizeMB']) ? max(1, (int)$_GET['sizeMB']) : 256;
            $cleanup = !isset($_GET['cleanup']) || $_GET['cleanup'] !== '0';
            respondJson(['disk' => performDiskTest($sizeMB, $cleanup)], $reqStart, 'disk');

        case 'heavy':
            // Simulate a realistic backend request (CPU + many small file reads)
            $startHeavy = microtime(true);

            $cpuLoops = isset($_GET['cpuLoops']) ? max(1000, (int)$_GET['cpuLoops']) : 30000;
            $fileCount = isset($_GET['fileCount']) ? max(20, (int)$_GET['fileCount']) : 200;
            $readCount = isset($_GET['readCount']) ? max(10, (int)$_GET['readCount']) : 100;
            $fileKB = isset($_GET['fileKB']) ? max(1, (int)$_GET['fileKB']) : 1;

            // ---- CPU work ----
            $x = 0.0;
            for ($i = 0; $i < $cpuLoops; $i++) {
                $x += sqrt($i);
            }

            // ---- Disk work (many small reads) ----
            $baseDir = sys_get_temp_dir() . '/perf_small_files_' . $fileCount . '_' . $fileKB . 'kb';
            if (!is_dir($baseDir)) {
                @mkdir($baseDir, 0777, true);
                for ($i = 0; $i < $fileCount; $i++) {
                    @file_put_contents(
                        $baseDir . "/f{$i}.txt",
                        random_bytes($fileKB * 1024)
                    );
                }
            }

            for ($i = 0; $i < $readCount; $i++) {
                $idx = $i % $fileCount;
                @file_get_contents($baseDir . "/f{$idx}.txt");
            }

            $endHeavy = microtime(true);

            respondJson([
                'ok' => true,
                'heavy_seconds' => $endHeavy - $startHeavy,
                'params' => [
                    'cpuLoops' => $cpuLoops,
                    'fileCount' => $fileCount,
                    'readCount' => $readCount,
                    'fileKB' => $fileKB,
                ],
            ], $reqStart, 'heavy');

        default:
            respondJson(['error' => 'Unknown action'], $reqStart, 'error');
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Server Performance Test</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; padding: 20px; line-height: 1.4; }
        .grid { display: grid; gap: 16px; grid-template-columns: 1fr; }
        @media (min-width: 1000px) { .grid { grid-template-columns: 1fr 1fr; } }

        .card { border: 1px solid #ddd; border-radius: 14px; padding: 16px; background: #fff; }
        h1 { margin-top: 0; }
        h2 { margin: 0 0 10px 0; }

        .muted { color: #666; font-size: 0.95em; }
        .err { color: #c00; }

        .row { display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
        button { padding: 10px 14px; border-radius: 12px; border: 1px solid #ccc; background: #fff; cursor: pointer; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }
        input { padding: 8px 10px; border-radius: 12px; border: 1px solid #ccc; width: 110px; }

        .progress { height: 10px; background:#eee; border-radius: 999px; overflow:hidden; }
        .bar { height: 100%; width:0%; background:#999; }

        .kpi { display:grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        @media (min-width: 1000px) { .kpi { grid-template-columns: repeat(4, 1fr); } }
        .kpi .box { background:#fafafa; border:1px solid #eee; border-radius:14px; padding:12px; }
        .kpi .label { color:#666; font-size:0.9em; }
        .kpi .value { font-size:1.2em; font-weight:700; margin-top:4px; }

        table { border-collapse: collapse; width: 100%; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eee; vertical-align: top; }
        th { background: #fafafa; font-weight: 650; }

        .twoCols { display:grid; grid-template-columns: 1fr; gap: 10px; }
        @media (min-width: 1000px) { .twoCols { grid-template-columns: 1fr 1fr; } }

        .scoreBig { font-size: 2.2em; font-weight: 800; }
        .pill { display:inline-block; padding: 2px 10px; border-radius: 999px; border: 1px solid #ddd; background:#fafafa; font-size:0.9em; }

        .subtleHr { border: 0; border-top: 1px solid #eee; margin: 12px 0; }
    </style>
</head>
<body>
<h1>Server Performance Test</h1>
<p class="muted">Ping + CPU + Disk + Heavy (parallel) fuer realistische Backend-Last. Umgebung + Browsermetriken sind unten.</p>

<div class="card" style="margin-bottom:16px;">
    <h2>Summiertes Endergebnis</h2>
    <div class="twoCols">
        <div>
            <div class="muted">Gesamtscore (nach oben offen)</div>
            <div class="scoreBig" id="overallScore">–</div>
            <div class="muted" id="scoreHint">Noch nicht gestartet.</div>
            <div style="margin-top:10px;">
                <span class="pill">Gewichtung: Ping 20% / CPU 15% / Disk 10% / Heavy 55%</span>
            </div>
        </div>
        <div>
            <div class="kpi" id="sumKpi"></div>
        </div>
    </div>

    <div style="margin-top:12px;">
        <div class="progress"><div class="bar" id="progressBar"></div></div>
        <div class="muted" id="statusLine" style="margin-top:8px;">Bereit.</div>
    </div>

    <div style="margin-top:14px;" id="sumHtml"><div class="muted">Noch keine Resultate.</div></div>
</div>

<div class="card" style="margin-bottom:16px;">
    <h2>Tests</h2>
    <div class="row" style="margin-bottom:10px;">
        <button id="runBtn">Tests starten</button>

        <label class="muted">Ping-Modus
            <select id="pingMode" style="padding:8px 10px; border-radius:12px; border:1px solid #ccc;">
                <option value="count" selected>Anzahl</option>
                <option value="time">Zeit</option>
            </select>
        </label>

        <label class="muted">Ping (Sek.)
            <input id="durationSec" type="number" min="5" max="300" value="30">
        </label>

        <label class="muted">Ping (Anzahl)
            <input id="pingCount" type="number" min="50" max="5000" value="500">
        </label>

        <label class="muted">CPU Limit
            <input id="cpuLimit" type="number" min="100000" max="20000000" value="5000000">
        </label>

        <label class="muted">Disk MB
            <input id="diskMB" type="number" min="16" max="2048" value="256">
        </label>
    </div>

    <hr class="subtleHr">

    <div class="row">
        <div class="muted" style="font-weight:650;">Heavy (parallel)</div>

        <label class="muted">Dauer (Sek.)
            <input id="heavyDurationSec" type="number" min="10" max="120" value="30">
        </label>

        <label class="muted">Concurrency
            <input id="heavyConcurrency" type="number" min="1" max="50" value="20">
        </label>

        <label class="muted">CPU Loops
            <input id="heavyCpuLoops" type="number" min="1000" max="300000" value="30000">
        </label>

        <label class="muted">File Reads
            <input id="heavyReadCount" type="number" min="10" max="1000" value="100">
        </label>
    </div>
</div>

<div class="grid">
    <div class="card">
        <h2>Ping/Ajax</h2>
        <div class="kpi" id="pingKpi"></div>
        <div id="pingHtml" class="muted">Noch keine Messwerte.</div>
    </div>

    <div class="card">
        <h2>CPU-Test</h2>
        <div id="cpuHtml" class="muted">Noch nicht ausgefuehrt.</div>
    </div>

    <div class="card">
        <h2>Disk I/O-Test</h2>
        <div id="diskHtml" class="muted">Noch nicht ausgefuehrt.</div>
    </div>

    <div class="card">
        <h2>Heavy (parallel, realistisch)</h2>
        <div class="kpi" id="heavyKpi"></div>
        <div id="heavyHtml" class="muted">Noch nicht ausgefuehrt.</div>
    </div>

    <div class="card">
        <h2>Umgebung</h2>
        <div id="envHtml" class="muted">Noch nicht geladen.</div>
    </div>

    <div class="card">
        <h2>Browser-Metriken (Navigation Timing)</h2>
        <div id="browserMetrics" class="muted">Messe …</div>
    </div>
</div>

<script>
(() => {
    // ---------- Helpers ----------
    function clamp(n, a, b) { return Math.max(a, Math.min(b, n)); }

    function fmtMs(n) {
        if (!isFinite(n)) return 'n/a';
        return (Math.round(n * 100) / 100).toFixed(2) + ' ms';
    }

    function fmtSec(n) {
        if (!isFinite(n)) return 'n/a';
        return (Math.round(n * 1000) / 1000).toFixed(3) + ' s';
    }

    function fmtNum(n, digits = 2) {
        if (!isFinite(n)) return 'n/a';
        const p = Math.pow(10, digits);
        return String(Math.round(n * p) / p);
    }

    function percentile(sortedArr, p) {
        if (!sortedArr.length) return NaN;
        const idx = (sortedArr.length - 1) * p;
        const lo = Math.floor(idx);
        const hi = Math.ceil(idx);
        if (lo === hi) return sortedArr[lo];
        return sortedArr[lo] + (sortedArr[hi] - sortedArr[lo]) * (idx - lo);
    }

    function median(sortedArr) { return percentile(sortedArr, 0.5); }

    function mean(arr) {
        if (!arr.length) return NaN;
        let s = 0;
        for (const v of arr) s += v;
        return s / arr.length;
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, (m) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        }[m]));
    }

    function renderTableFromPairs(title, pairs) {
        const rows = pairs.map(([k, v]) => `
            <tr>
                <th style="width:38%">${escapeHtml(k)}</th>
                <td>${v}</td>
            </tr>
        `).join('');

        return `
            <div style="margin-top:10px;">
                ${title ? `<div class="muted" style="margin-bottom:6px;"><strong>${escapeHtml(title)}</strong></div>` : ''}
                <table><tbody>${rows}</tbody></table>
            </div>
        `;
    }

    function renderKpi(el, items) {
        el.innerHTML = items.map(({label, value}) => `
            <div class="box">
                <div class="label">${escapeHtml(label)}</div>
                <div class="value">${escapeHtml(value)}</div>
            </div>
        `).join('');
    }

    // ---------- Open-ended score model ----------
    function pingComponent(medianMs, p95Ms) {
        if (!isFinite(medianMs) || !isFinite(p95Ms)) return NaN;
        const denom = (medianMs + 0.30 * p95Ms);
        return 2200 / Math.max(1, denom);
    }

    function cpuComponent(cpuSeconds) {
        if (!isFinite(cpuSeconds)) return NaN;
        return 1000 / Math.max(0.001, cpuSeconds);
    }

    function diskComponent(writeMBps, readMBps) {
        if (!isFinite(writeMBps) || !isFinite(readMBps)) return NaN;
        return (writeMBps + readMBps) / 30;
    }

    // Heavy: use p95/p99 to reward stability. Lower tail latency => higher score.
    function heavyComponent(p95Ms, p99Ms) {
        if (!isFinite(p95Ms) || !isFinite(p99Ms)) return NaN;
        const denom = (p95Ms + 0.50 * p99Ms);
        return 2600 / Math.max(1, denom);
    }

    function overallScore(components) {
        const weights = { ping: 0.20, cpu: 0.15, disk: 0.10, heavy: 0.55 };
        let wSum = 0;
        let sSum = 0;

        for (const key of ['ping', 'cpu', 'disk', 'heavy']) {
            const s = components[key];
            if (isFinite(s)) {
                wSum += weights[key];
                sSum += weights[key] * s;
            }
        }
        if (wSum === 0) return NaN;
        return sSum / wSum;
    }

    function setLiveScore(overallScoreEl, scoreHintEl, components, hint) {
        const score = overallScore(components);
        overallScoreEl.textContent = isFinite(score) ? String(Math.round(score)) : '–';
        scoreHintEl.textContent = hint || '';
    }

    // ---------- API ----------
    async function api(action, params = {}) {
        const url = new URL(window.location.href);
        url.searchParams.set('action', action);
        url.searchParams.set('_', String(Date.now() + Math.random()));
        for (const [k, v] of Object.entries(params)) url.searchParams.set(k, String(v));

        const t0 = performance.now();
        const res = await fetch(url.toString(), { cache: 'no-store' });
        const t1 = performance.now();
        const json = await res.json();
        return { json, clientMs: (t1 - t0), ok: res.ok };
    }

    // ---------- UI ----------
    const runBtn = document.getElementById('runBtn');

    const durationSecEl = document.getElementById('durationSec');
    const pingModeEl = document.getElementById('pingMode');
    const pingCountEl = document.getElementById('pingCount');
    const cpuLimitEl = document.getElementById('cpuLimit');
    const diskMBEl = document.getElementById('diskMB');

    const heavyDurationSecEl = document.getElementById('heavyDurationSec');
    const heavyConcurrencyEl = document.getElementById('heavyConcurrency');
    const heavyCpuLoopsEl = document.getElementById('heavyCpuLoops');
    const heavyReadCountEl = document.getElementById('heavyReadCount');

    const statusLine = document.getElementById('statusLine');
    const progressBar = document.getElementById('progressBar');

    const overallScoreEl = document.getElementById('overallScore');
    const scoreHintEl = document.getElementById('scoreHint');

    const sumKpi = document.getElementById('sumKpi');
    const sumHtml = document.getElementById('sumHtml');

    const pingKpi = document.getElementById('pingKpi');
    const pingHtml = document.getElementById('pingHtml');

    const cpuHtml = document.getElementById('cpuHtml');
    const diskHtml = document.getElementById('diskHtml');

    const heavyKpi = document.getElementById('heavyKpi');
    const heavyHtml = document.getElementById('heavyHtml');

    const envHtml = document.getElementById('envHtml');
    const browserMetrics = document.getElementById('browserMetrics');

    function setStatus(text, pct = null) {
        statusLine.textContent = text;
        if (pct !== null) progressBar.style.width = clamp(pct, 0, 100) + '%';
    }

    // ---------- Browser navigation timing ----------
    try {
        const navEntries = performance.getEntriesByType('navigation');
        const nav = navEntries && navEntries.length ? navEntries[0] : null;

        if (!nav) {
            browserMetrics.textContent = 'Navigation Timing nicht verfuegbar.';
        } else {
            const dns = nav.domainLookupEnd - nav.domainLookupStart;
            const tcp = nav.connectEnd - nav.connectStart;
            const tls = (nav.secureConnectionStart > 0) ? (nav.connectEnd - nav.secureConnectionStart) : 0;
            const ttfb = nav.responseStart - nav.requestStart;
            const download = nav.responseEnd - nav.responseStart;
            const total = nav.duration;

            browserMetrics.innerHTML = renderTableFromPairs('', [
                ['DNS', escapeHtml(fmtMs(dns))],
                ['TCP', escapeHtml(fmtMs(tcp))],
                ['TLS', escapeHtml(fmtMs(tls))],
                ['TTFB', escapeHtml(fmtMs(ttfb))],
                ['Download', escapeHtml(fmtMs(download))],
                ['Total', escapeHtml(fmtMs(total))],
            ]);
        }
    } catch (e) {
        browserMetrics.textContent = 'Fehler beim Auslesen der Browser-Metriken.';
    }

    // ---------- Load env ----------
    (async () => {
        try {
            const { json } = await api('env');
            const env = json.env || {};
            envHtml.innerHTML = renderTableFromPairs('', [
                ['Host', escapeHtml(env.host ?? '')],
                ['PHP', escapeHtml(env.php_version ?? '')],
                ['SAPI', escapeHtml(env.sapi ?? '')],
                ['Server', escapeHtml(env.server_software ?? '')],
                ['OPcache', escapeHtml(String(env.opcache_enabled))],
                ['UTC', escapeHtml(env.time_utc ?? '')],
            ]);
        } catch (e) {
            envHtml.innerHTML = `<span class="err">Fehler beim Laden.</span>`;
        }
    })();

    // ---------- Heavy parallel runner ----------
    async function runParallelHeavyTest(durationSec, concurrency, heavyParams, onTick) {
        const results = [];
        let failures = 0;

        const endAt = performance.now() + durationSec * 1000;

        async function worker() {
            while (performance.now() < endAt) {
                const t0 = performance.now();
                try {
                    const res = await api('heavy', heavyParams);
                    const t1 = performance.now();
                    const serverMs = (res.json && typeof res.json.heavy_seconds === 'number')
                        ? res.json.heavy_seconds * 1000
                        : NaN;

                    results.push({ clientMs: (t1 - t0), serverMs });
                } catch (e) {
                    failures++;
                }

                // Small yield helps UI responsiveness
                await new Promise(r => setTimeout(r, 0));
            }
        }

        // UI ticker
        let tickerRunning = true;
        const ticker = (async () => {
            while (tickerRunning) {
                if (typeof onTick === 'function') onTick(results, failures, endAt);
                await new Promise(r => setTimeout(r, 500));
            }
        })();

        const workers = [];
        for (let i = 0; i < concurrency; i++) workers.push(worker());
        await Promise.all(workers);

        tickerRunning = false;
        await ticker;

        return { results, failures };
    }

    async function calibrateHeavyParams(initialParams, targetServerMs = 40, samples = 12) {
        // English comments only (as requested)
        // Goal: adjust cpuLoops so that server median is ~targetServerMs (e.g. 40ms)
        let params = { ...initialParams };
        let best = { params: { ...params }, medianServerMs: NaN };

        for (let round = 0; round < 3; round++) {
            const serverMsSamples = [];

            for (let i = 0; i < samples; i++) {
                const res = await api('heavy', params);
                const serverMs = (res.json && typeof res.json.heavy_seconds === 'number')
                    ? res.json.heavy_seconds * 1000
                    : NaN;

                if (isFinite(serverMs)) serverMsSamples.push(serverMs);

                // Small yield for UI responsiveness
                await new Promise(r => setTimeout(r, 0));
            }

            const sorted = serverMsSamples.sort((a, b) => a - b);
            const med = median(sorted);

            if (isFinite(med) && (!isFinite(best.medianServerMs) || Math.abs(med - targetServerMs) < Math.abs(best.medianServerMs - targetServerMs))) {
                best = { params: { ...params }, medianServerMs: med };
            }

            if (!isFinite(med) || med <= 0) break;

            // Compute scaling factor; keep changes bounded to avoid overshoot
            const factorRaw = targetServerMs / med;
            const factor = clamp(factorRaw, 0.5, 2.5);

            const newCpuLoops = Math.round(params.cpuLoops * factor);
            params.cpuLoops = clamp(newCpuLoops, 1000, 300000);

            // If we're already within target band, stop early
            if (med >= 30 && med <= 50) break;
        }

        return best; // { params, medianServerMs }
    }
    

    function analyzeMsArray(arr) {
        const sorted = [...arr].filter(v => isFinite(v)).sort((a,b)=>a-b);
        return {
            count: sorted.length,
            avg: mean(sorted),
            median: median(sorted),
            p95: percentile(sorted, 0.95),
            p99: percentile(sorted, 0.99),
            min: sorted.length ? sorted[0] : NaN,
            max: sorted.length ? sorted[sorted.length-1] : NaN,
        };
    }

    // ---------- Run ----------
    runBtn.addEventListener('click', async () => {
        runBtn.disabled = true;

        setStatus('Starte …', 0);
        renderKpi(sumKpi, []);
        renderKpi(pingKpi, []);
        renderKpi(heavyKpi, []);

        sumHtml.innerHTML = `<div class="muted">Laeuft …</div>`;
        pingHtml.innerHTML = `<div class="muted">Laeuft …</div>`;
        cpuHtml.innerHTML = `<div class="muted">Warte …</div>`;
        diskHtml.innerHTML = `<div class="muted">Warte …</div>`;
        heavyHtml.innerHTML = `<div class="muted">Warte …</div>`;

        const components = { ping: NaN, cpu: NaN, disk: NaN, heavy: NaN };
        setLiveScore(overallScoreEl, scoreHintEl, components, 'Laeuft …');

        const pingMode = (pingModeEl?.value || 'time');
        const durationSec = clamp(Number(durationSecEl.value || 30), 5, 300);
        const pingCount = clamp(Number(pingCountEl.value || 500), 50, 5000);
        const cpuLimit = clamp(Number(cpuLimitEl.value || 5000000), 100000, 20000000);
        const diskMB = clamp(Number(diskMBEl.value || 256), 16, 2048);

        const heavyDurationSec = clamp(Number(heavyDurationSecEl.value || 30), 10, 120);
        const heavyConcurrency = clamp(Number(heavyConcurrencyEl.value || 20), 1, 50);
        const heavyCpuLoops = clamp(Number(heavyCpuLoopsEl.value || 30000), 1000, 300000);
        const heavyReadCount = clamp(Number(heavyReadCountEl.value || 100), 10, 1000);

        // ---- 1) Ping ----
        const pingClient = [];
        const pingServer = [];
        let pingOk = 0;
        let pingFail = 0;

        const startedAt = performance.now();
        const endAt = startedAt + durationSec * 1000;

        const targetCount = (pingMode === 'count') ? pingCount : null;
        setStatus(
            pingMode === 'count'
                ? `Ping-Phase laeuft (${pingCount} Requests) …`
                : `Ping-Phase laeuft (${durationSec}s) …`,
            5
        );

        let iter = 0;
        while (true) {
            // Stop condition: time or count
            if (pingMode === 'time') {
                if (performance.now() >= endAt) break;
            } else {
                if (iter >= pingCount) break;
            }

            iter++;

            try {
                const { json, clientMs } = await api('ping');
                pingClient.push(clientMs);

                const serverMs = (json && json._meta && typeof json._meta.server_elapsed_seconds === 'number')
                    ? json._meta.server_elapsed_seconds * 1000
                    : NaN;
                if (isFinite(serverMs)) pingServer.push(serverMs);

                pingOk++;
            } catch (e) {
                pingFail++;
            }

            // Update UI every 10 requests (keeps it smooth)
            if (iter % 10 === 0) {
                const sorted = [...pingClient].sort((a,b) => a-b);
                const avg = mean(pingClient);
                const med = median(sorted);
                const p95 = percentile(sorted, 0.95);
                const p99 = percentile(sorted, 0.99);

                components.ping = pingComponent(med, p95);
                setLiveScore(overallScoreEl, scoreHintEl, components, 'Live: Ping ist eingerechnet.');

                renderKpi(pingKpi, [
                    { label: 'Requests ok', value: String(pingOk) },
                    { label: 'Requests fail', value: String(pingFail) },
                    { label: 'Client median', value: fmtMs(med) },
                    { label: 'Client p95', value: fmtMs(p95) },
                ]);

                pingHtml.innerHTML = renderTableFromPairs('Client', [
                    ['avg', escapeHtml(fmtMs(avg))],
                    ['median', escapeHtml(fmtMs(med))],
                    ['p95', escapeHtml(fmtMs(p95))],
                    ['p99', escapeHtml(fmtMs(p99))],
                    ['min', escapeHtml(fmtMs(sorted.length ? sorted[0] : NaN))],
                    ['max', escapeHtml(fmtMs(sorted.length ? sorted[sorted.length-1] : NaN))],
                    ['score', escapeHtml(fmtNum(components.ping, 2))],
                ]);

                // Progress calculation
                let pct;
                if (pingMode === 'time') {
                    const elapsed = performance.now() - startedAt;
                    pct = 5 + (elapsed / (durationSec * 1000)) * 40; // 5..45
                    const remaining = Math.max(0, Math.round((endAt - performance.now()) / 1000));
                    setStatus(`Ping-Phase laeuft (${remaining}s verbleibend) …`, pct);
                } else {
                    const done = iter;
                    pct = 5 + (done / pingCount) * 40; // 5..45
                    setStatus(`Ping-Phase laeuft (${done}/${pingCount}) …`, pct);
                }
            }
        }

        const pingClientSorted = [...pingClient].sort((a,b) => a-b);
        const pingStats = {
            requests_ok: pingOk,
            requests_fail: pingFail,
            client_ms: {
                median: median(pingClientSorted),
                p95: percentile(pingClientSorted, 0.95),
                p99: percentile(pingClientSorted, 0.99),
            }
        };

        components.ping = pingComponent(pingStats.client_ms.median, pingStats.client_ms.p95);
        setLiveScore(overallScoreEl, scoreHintEl, components, 'Ping abgeschlossen. CPU folgt.');
        setStatus('CPU-Test laeuft …', 50);

        // ---- 2) CPU ----
        let cpuRes = null;
        try {
            const { json } = await api('cpu', { limit: cpuLimit });
            cpuRes = json.cpu;

            cpuHtml.innerHTML = renderTableFromPairs('', [
                ['limit', escapeHtml(String(cpuRes.limit))],
                ['primes_found', escapeHtml(String(cpuRes.primes_found))],
                ['seconds', escapeHtml(fmtSec(cpuRes.seconds))],
            ]);

            components.cpu = cpuComponent(cpuRes.seconds);
            setLiveScore(overallScoreEl, scoreHintEl, components, 'Ping + CPU eingerechnet. Disk folgt.');
        } catch (e) {
            cpuHtml.innerHTML = `<span class="err">CPU-Test fehlgeschlagen.</span>`;
        }

        setStatus('Disk I/O-Test laeuft …', 60);

        // ---- 3) Disk ----
        let diskRes = null;
        try {
            const { json } = await api('disk', { sizeMB: diskMB, cleanup: 1 });
            diskRes = json.disk;

            if (diskRes && diskRes.error) {
                diskHtml.innerHTML = `<span class="err">${escapeHtml(diskRes.error)}</span>`;
            } else {
                diskHtml.innerHTML = renderTableFromPairs('', [
                    ['size_mb', escapeHtml(String(diskRes.size_mb))],
                    ['write_seconds', escapeHtml(fmtSec(diskRes.write_seconds))],
                    ['write_mbps', escapeHtml(fmtNum(diskRes.write_mbps, 1) + ' MB/s')],
                    ['read_seconds', escapeHtml(fmtSec(diskRes.read_seconds))],
                    ['read_mbps', escapeHtml(fmtNum(diskRes.read_mbps, 1) + ' MB/s')],
                ]);

                components.disk = diskComponent(diskRes.write_mbps, diskRes.read_mbps);
                setLiveScore(overallScoreEl, scoreHintEl, components, 'Ping + CPU + Disk eingerechnet. Heavy folgt.');
            }
        } catch (e) {
            diskHtml.innerHTML = `<span class="err">Disk-Test fehlgeschlagen.</span>`;
        }

        // ---- 4) Heavy (parallel) ----
        setStatus(`Heavy wird kalibriert (Ziel Server median ~40 ms) …`, 68);
        heavyHtml.innerHTML = `<div class="muted">Kalibriere Heavy-Last …</div>`;
        renderKpi(heavyKpi, [
            { label: 'Status', value: 'Kalibriere' },
            { label: 'Ziel', value: '40 ms' },
            { label: 'Concurrency', value: String(heavyConcurrency) },
            { label: 'Dauer', value: String(heavyDurationSec) + ' s' },
        ]);

        const heavyParamsInitial = {
            cpuLoops: heavyCpuLoops,
            readCount: heavyReadCount,
        };

        // Calibrate cpuLoops to reach ~40ms server median (30..50ms band)
        const calibration = await calibrateHeavyParams(heavyParamsInitial, 40, 12);
        const heavyParams = calibration.params;

        heavyHtml.innerHTML =
            renderTableFromPairs('Kalibrierung', [
                ['server_median_ms', escapeHtml(fmtMs(calibration.medianServerMs))],
                ['cpuLoops (final)', escapeHtml(String(heavyParams.cpuLoops))],
                ['readCount', escapeHtml(String(heavyParams.readCount))],
                ['target', escapeHtml('~40 ms (30–50 ms)')],
            ]) +
            `<div class="muted" style="margin-top:8px;">Starte Parallel-Test …</div>`;

        setStatus(`Heavy laeuft (${heavyDurationSec}s, ${heavyConcurrency} parallel) …`, 70);

        const { results: heavyResults, failures: heavyFail } = await runParallelHeavyTest(
            heavyDurationSec,
            heavyConcurrency,
            heavyParams,
            (results, failures, endAtMs) => {
                const clientStats = analyzeMsArray(results.map(r => r.clientMs));
                const serverStats = analyzeMsArray(results.map(r => r.serverMs));

                if (clientStats.count > 20) {
                    components.heavy = heavyComponent(clientStats.p95, clientStats.p99);
                    setLiveScore(overallScoreEl, scoreHintEl, components, 'Live: Heavy ist eingerechnet.');
                }

                renderKpi(heavyKpi, [
                    { label: 'Requests ok', value: String(results.length) },
                    { label: 'Requests fail', value: String(failures) },
                    { label: 'Client p95', value: fmtMs(clientStats.p95) },
                    { label: 'Client p99', value: fmtMs(clientStats.p99) },
                ]);

                heavyHtml.innerHTML =
                    renderTableFromPairs('Kalibrierung', [
                        ['server_median_ms', escapeHtml(fmtMs(calibration.medianServerMs))],
                        ['cpuLoops (final)', escapeHtml(String(heavyParams.cpuLoops))],
                        ['readCount', escapeHtml(String(heavyParams.readCount))],
                        ['target', escapeHtml('~40 ms (30–50 ms)')],
                    ]) +
                    renderTableFromPairs('Client (realistisch)', [
                        ['median', escapeHtml(fmtMs(clientStats.median))],
                        ['p95', escapeHtml(fmtMs(clientStats.p95))],
                        ['p99', escapeHtml(fmtMs(clientStats.p99))],
                        ['min', escapeHtml(fmtMs(clientStats.min))],
                        ['max', escapeHtml(fmtMs(clientStats.max))],
                    ]) +
                    renderTableFromPairs('Server (heavy_seconds)', [
                        ['median', escapeHtml(fmtMs(serverStats.median))],
                        ['p95', escapeHtml(fmtMs(serverStats.p95))],
                        ['p99', escapeHtml(fmtMs(serverStats.p99))],
                        ['samples', escapeHtml(String(serverStats.count))],
                    ]);

                const remaining = Math.max(0, Math.round((endAtMs - performance.now()) / 1000));
                setStatus(`Heavy laeuft (${remaining}s verbleibend) …`, 70 + (1 - (remaining / heavyDurationSec)) * 25);
            }
        );

        const heavyClientStats = analyzeMsArray(heavyResults.map(r => r.clientMs));
        const heavyServerStats = analyzeMsArray(heavyResults.map(r => r.serverMs));

        components.heavy = heavyComponent(heavyClientStats.p95, heavyClientStats.p99);
        setLiveScore(overallScoreEl, scoreHintEl, components, 'Heavy abgeschlossen. Summary folgt.');


        // ---- 5) Summary ----
        setStatus('Ergebnis wird zusammengefasst …', 98);

        const finalScore = Math.round(overallScore(components));

        renderKpi(sumKpi, [
            { label: 'Gesamtscore', value: String(finalScore) },
            { label: 'Ping', value: isFinite(components.ping) ? fmtNum(components.ping, 1) : 'n/a' },
            { label: 'CPU', value: isFinite(components.cpu) ? fmtNum(components.cpu, 1) : 'n/a' },
            { label: 'Disk', value: isFinite(components.disk) ? fmtNum(components.disk, 1) : 'n/a' },
        ]);

        const sumParts = [];
        sumParts.push(renderTableFromPairs('Score', [
            ['overall', escapeHtml(String(finalScore))],
            ['ping', escapeHtml(fmtNum(components.ping, 2))],
            ['cpu', escapeHtml(fmtNum(components.cpu, 2))],
            ['disk', escapeHtml(fmtNum(components.disk, 2))],
            ['heavy', escapeHtml(fmtNum(components.heavy, 2))],
        ]));

        sumParts.push(renderTableFromPairs('Heavy (Client)', [
            ['requests_ok', escapeHtml(String(heavyResults.length))],
            ['requests_fail', escapeHtml(String(heavyFail))],
            ['median', escapeHtml(fmtMs(heavyClientStats.median))],
            ['p95', escapeHtml(fmtMs(heavyClientStats.p95))],
            ['p99', escapeHtml(fmtMs(heavyClientStats.p99))],
        ]));

        sumParts.push(renderTableFromPairs('Heavy (Server)', [
            ['median', escapeHtml(fmtMs(heavyServerStats.median))],
            ['p95', escapeHtml(fmtMs(heavyServerStats.p95))],
            ['p99', escapeHtml(fmtMs(heavyServerStats.p99))],
        ]));

        if (cpuRes) {
            sumParts.push(renderTableFromPairs('CPU', [
                ['seconds', escapeHtml(fmtSec(cpuRes.seconds))],
                ['primes_found', escapeHtml(String(cpuRes.primes_found))],
            ]));
        }

        if (diskRes && !diskRes.error) {
            sumParts.push(renderTableFromPairs('Disk', [
                ['write', escapeHtml(fmtNum(diskRes.write_mbps, 1) + ' MB/s')],
                ['read', escapeHtml(fmtNum(diskRes.read_mbps, 1) + ' MB/s')],
            ]));
        }

        sumParts.push(`
            <div class="muted" style="margin-top:10px;">
                Tipps:<br>
                - Heavy p95/p99 sind die wichtigsten Werte fuer "fuehlt sich schnell an".<br>
                - Wenn du 429/503 siehst: Concurrency reduzieren oder Heavy-Dauer erhoehen, das zeigt Limits im Shared Hosting.<br>
                - Fuer faire Vergleiche: 3 Durchlaeufe pro Server, Median vergleichen.
            </div>
        `);

        sumHtml.innerHTML = sumParts.join('');

        overallScoreEl.textContent = String(finalScore);
        scoreHintEl.textContent = 'Fertig.';

        setStatus('Fertig.', 100);
        runBtn.disabled = false;
    });
})();
</script>
</body>
</html>