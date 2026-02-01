<?php
declare(strict_types=1);

namespace App\cronimport;

use pFrame\Core\pFrameCore;

final class CronImportController
{
    private pFrameCore $pf;

    /** @var array<string,string> */
    private array $imports = [
        '19 Kategorieimport' => '19',
        '6 Produktimport Erdungszangen' => '6',
        '8 Produktimport Schweisszubehoer' => '8',
        '9 Produktimport Co2 Zangen' => '9',
        '10 Produktimport Auto- Motorradzubehoer' => '10',
        '13 Produktimport Klemmzangen' => '13',
        '14 Produktimport Kettenschuetzer' => '14',
        '15 Produktimport Gummiisolierhuellen' => '15',
        '16 Produktimport Fahrrad- Mofazubehoer' => '16',
        '17 Produktimport Erdkabelzangen' => '17',
        '21 Produktimport Zubehoer Kettenschuetzer' => '21',
        '22 Produktimport Diverse' => '22',
        '25 Kategorieimport en' => '25',
        '26 Kategoriezuweisung Erdungszangen en' => '26',
        '27 Kategoriezuweisung Schweisszubehoer en' => '27',
        '28 Kategoriezuweisung Co2-Zangen en' => '28',
        '29 Kategoriezuweisung Auto-Motorradzubehoer en' => '29',
        '30 Kategoriezuweisung Klemmzangen en' => '30',
        '31 Kategoriezuweisung Kettenschuetzer en' => '31',
        '32 Kategoriezuweisung Gummiisolierhuellen en' => '32',
        '33 Kategoriezuweisung Fahrrad-Mofazubehoer en' => '33',
        '34 Kategoriezuweisung Erdkabelzangen en' => '34',
        '35 Kategoriezuweisung Zubehoer Kettenschuetzer en' => '35',
        '36 Kategoriezuweisung Diverse en' => '36',
    ];

    public function __construct(pFrameCore $pf)
    {
        $this->pf = $pf;
    }

    // -------------------------
    // Web UI (optional)
    // -------------------------
    public function run(array $params = []): void
    {
        header('Content-Type: text/html; charset=utf-8');

        echo <<<HTML
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Cron Import</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50">
  <div class="max-w-3xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-4">Cron Import</h1>

    <div class="bg-white rounded shadow p-4 mb-4">
      <p class="mb-2">Dieser Job kann via CLI (Cron) laufen. Optional kannst du hier manuell triggern.</p>
      <button id="run" class="bg-blue-600 text-white rounded px-4 py-2">Run now</button>
      <pre id="out" class="mt-4 bg-gray-100 p-3 rounded overflow-auto" style="min-height: 140px;"></pre>
    </div>
  </div>

  <script>
    document.getElementById('run').addEventListener('click', async () => {
      document.getElementById('out').textContent = 'running...';
      const res = await fetch('/api/cronimport/run/?token=' + encodeURIComponent(prompt('token?') || ''), { method: 'POST' });
      const txt = await res.text();
      document.getElementById('out').textContent = txt;
    });
  </script>
</body>
</html>
HTML;
    }

    // -------------------------
    // API trigger (optional)
    // -------------------------
    public function apiRun(array $params = []): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $token = (string)($_GET['token'] ?? '');
        $expected = (string)($this->env('CRONIMPORT_WEB_TOKEN') ?? '');

        if ($expected === '' || !hash_equals($expected, $token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'forbidden'], JSON_PRETTY_PRINT);
            return;
        }

        $report = $this->execute();
        echo json_encode($report, JSON_PRETTY_PRINT);
    }

    // -------------------------
    // CLI entry
    // -------------------------
    public function cliRun(): int
    {
        $report = $this->execute();

        $errors = (int)($report['errors'] ?? 0);
        $summary = (string)($report['summary'] ?? '');

        $ok = ($errors === 0);

        // Print a compact summary for cron logs
        echo ($ok ? "[OK] " : "[ERROR] ") . $summary . PHP_EOL;

        // Optional: write details as JSON (avoid array-to-string warnings)
        // echo json_encode($report, JSON_PRETTY_PRINT) . PHP_EOL;

        return $ok ? 0 : 1;
    }

    // -------------------------
    // Core execution
    // -------------------------
    private function execute(): array
    {
        // Comments are in English by request.

        ini_set('max_execution_time', '900');
        set_time_limit(900);
        ini_set('memory_limit', '512M');
        ini_set('default_socket_timeout', '900');

        $baseUrl = (string)($this->env('CRONIMPORT_BASE_URL') ?? '');
        $importKey = (string)($this->env('CRONIMPORT_IMPORT_KEY') ?? '');
        $mailTo = (string)($this->env('CRONIMPORT_MAIL_TO') ?? '');
        $mailFrom = (string)($this->env('CRONIMPORT_MAIL_FROM') ?? '');
        $backupUrl = (string)($this->env('CRONIMPORT_BACKUP_URL') ?? '');

        if ($baseUrl === '' || $importKey === '') {
            return [
                'success' => false,
                'errors' => 1,
                'summary' => 'Missing CRONIMPORT_BASE_URL or CRONIMPORT_IMPORT_KEY',
            ];
        }

        // Optional: allow overriding import ids via env (comma list)
        $idsCsv = (string)($this->env('CRONIMPORT_IMPORT_IDS') ?? '');
        $imports = $this->imports;

        if ($idsCsv !== '') {
            $wanted = array_filter(array_map('trim', explode(',', $idsCsv)));
            if ($wanted) {
                $imports = array_filter(
                    $imports,
                    fn(string $id) => in_array($id, $wanted, true)
                );
            }
        }

        $errors = 0;
        $items = [];

        foreach ($imports as $name => $id) {
            $hook = $baseUrl . '?import_key=' . rawurlencode($importKey) . '&import_id=' . rawurlencode($id);

            $triggerRes = $this->httpGet($hook . '&action=trigger');
            $procRes    = $this->httpGet($hook . '&action=processing');

            $triggerBody = (string)($triggerRes['body'] ?? '');
            $procBody    = (string)($procRes['body'] ?? '');

            $status = json_decode($procBody, true);
            if (!is_array($status)) {
                $status = [];
            }

            $code = (int)($status['status'] ?? 0);
            $ok = ($code === 200);

            // Build base item
            $item = [
                'name' => $name,
                'id' => $id,
                'ok' => $ok,
                'status' => $code,
                'trigger_http' => (int)($triggerRes['http_code'] ?? 0),
                'processing_http' => (int)($procRes['http_code'] ?? 0),
                'curl_errno' => (int)($procRes['errno'] ?? 0),
                'curl_error' => (string)($procRes['error'] ?? ''),
                'processing_url' => (string)($procRes['effective_url'] ?? ''),
            ];

            // Only attach response body when something went wrong
            if (!$ok) {
                $item['processing_body'] = substr($procBody, 0, 500);
            }

            $items[] = $item;

            if (!$ok) {
                $errors++;

                $msg  = $name . " executed with errors.\n\n";
                $msg .= "- Trigger: " . $this->unicodeDecode($this->stringify($triggerBody)) . "\n";
                $msg .= "- Processing: " . $this->unicodeDecode($this->stringify($procBody)) . "\n";
                $msg .= "- Status: " . $this->stringify($status) . "\n";

                error_log('CRONIMPORT ERROR ' . $name . ' status=' . $code);

                if ($mailTo !== '' && $mailFrom !== '') {
                    $headers = [
                        "From: " . $mailFrom,
                        "MIME-Version: 1.0",
                        "Content-Type: text/plain;charset=utf-8",
                    ];
                    @mail($mailTo, 'Cron Job executed (errors)', $msg, implode("\r\n", $headers));
                }
            }

            usleep(150000);
        }





        // Backup call (optional)
        $backupResult = null;
        if ($backupUrl !== '') {
            $backupResult = $this->httpGet($backupUrl);
        }

        $summary = 'imports=' . count($imports) . ', errors=' . $errors;

        return [
            'success' => ($errors === 0),
            'errors' => $errors,
            'summary' => $summary,
            'items' => $items,
            'backup' => $backupResult !== null ? ['called' => true] : ['called' => false],
        ];
    }

    // -------------------------
    // Helpers
    // -------------------------

    private function env(string $key): ?string
    {
        // Reads from getenv. If you load .env elsewhere, keep it as-is.
        $v = getenv($key);
        return $v === false ? null : (string)$v;
    }

    private function httpGet(string $url): array
    {
        // Returns body + debug info.
        // Comments are in English by request.

        $result = [
            'url' => $url,
            'ok' => false,
            'http_code' => 0,
            'errno' => 0,
            'error' => '',
            'body' => '',
        ];

        $hostHeader = (string)($this->env('CRONIMPORT_HTTP_HOST') ?? '');
        $headers = [];
        if ($hostHeader !== '') {
            $headers[] = 'Host: ' . $hostHeader;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_SSL_VERIFYPEER => true, // local dev convenience; set true on prod if using https
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'pFrame-CronImport/1.0',
                CURLOPT_HTTPHEADER => $headers,
            ]);

            $body = curl_exec($ch);
            $result['errno'] = (int)curl_errno($ch);
            $result['error'] = (string)curl_error($ch);
            $result['http_code'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $result['effective_url'] = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

            if ($body !== false) {
                $result['body'] = (string)$body;
            }

            curl_close($ch);

            $result['ok'] = ($result['errno'] === 0 && $result['http_code'] > 0);
            return $result;
        }

        // Fallback
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 60,
                'header' => "User-Agent: pFrame-CronImport/1.0\r\n",
            ]
        ]);

        $body = @file_get_contents($url, false, $ctx);
        $result['body'] = $body === false ? '' : (string)$body;

        // No reliable http code in this fallback
        $result['ok'] = ($result['body'] !== '');
        return $result;
    }

    private function stringify(mixed $v): string
    {
        if (is_array($v) || is_object($v)) {
            return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($v === null) return '';
        if ($v === false) return 'false';
        if ($v === true) return 'true';
        return (string)$v;
    }

    private function unicodeDecode(string $str): string
    {
        return (string)preg_replace_callback('/\\\\u([0-9a-f]{4})/i', function(array $m): string {
            return mb_convert_encoding(pack('H*', $m[1]), 'UTF-8', 'UCS-2BE');
        }, $str);
    }
}