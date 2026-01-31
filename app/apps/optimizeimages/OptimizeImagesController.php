<?php
declare(strict_types=1);

namespace App\optimizeimages;

use pFrame\Core\pFrameCore;

final class OptimizeImagesController
{
    private pFrameCore $pf;

    private string $srcDir;     // public/import/jpg
    private string $destDir;    // public/import/jpg_small

    private int $maxSide = 1200;     // Change to your preferred size
    private int $quality = 78;       // JPEG quality (0-100)
    private bool $stripMetadata = true;

    public function __construct(pFrameCore $pf)
    {
        $this->pf = $pf;

        $root = rtrim($pf->getProjectRoot(), '/');

        $this->srcDir  = $root . '/public/import/jpg';

        $this->destDir = $root . '/public/import/jpg_small';

        $this->ensureDirectories();
    }

    public function run(array $params = []): void
    {
        // Simple UI to trigger batch processing.
        header('Content-Type: text/html; charset=utf-8');

        echo <<<HTML
<!DOCTYPE html>
<html>
<head>
  <title>Optimize Images</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <style>
    p, a, button, input, label { font-size: 1.1em; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  </style>
  <script>
    async function refreshStatus() {
      const res = await fetch('/api/optimizeimages/status/');
      const data = await res.json();

      document.getElementById('src').textContent = data.srcDir || '-';
      document.getElementById('dest').textContent = data.destDir || '-';
      document.getElementById('srcCount').textContent = data.srcCount ?? '-';
      document.getElementById('destCount').textContent = data.destCount ?? '-';
      document.getElementById('imagick').textContent = data.imagick ? 'yes' : 'no';

      const last = document.getElementById('last');
      last.textContent = data.lastMessage || '';
    }

    async function runBatch() {
      const limit = document.getElementById('limit').value || '50';
      const force = document.getElementById('force').checked ? '1' : '0';

      document.getElementById('last').textContent = 'processing...';

      const res = await fetch('/api/optimizeimages/run/?limit=' + encodeURIComponent(limit) + '&force=' + force, {
        method: 'POST'
      });

      const data = await res.json();
      document.getElementById('last').textContent = JSON.stringify(data, null, 2);

      await refreshStatus();
    }

    window.addEventListener('load', refreshStatus);
  </script>
</head>
<body class="bg-gray-50">
  <div class="container mx-auto p-6">
    <h1 class="text-2xl font-bold mb-4">Optimize Images</h1>

    <div class="bg-white rounded shadow p-4 mb-4">
      <p><strong>Imagick:</strong> <span id="imagick" class="mono">-</span></p>
      <p><strong>Source:</strong> <span id="src" class="mono">-</span></p>
      <p><strong>Destination:</strong> <span id="dest" class="mono">-</span></p>
      <p><strong>Source count:</strong> <span id="srcCount" class="mono">-</span></p>
      <p><strong>Destination count:</strong> <span id="destCount" class="mono">-</span></p>
    </div>

    <div class="bg-white rounded shadow p-4 mb-4">
      <div class="flex items-center gap-4 mb-3">
        <label class="flex items-center gap-2">
          <span>Batch size</span>
          <input id="limit" type="number" min="1" max="500" value="50" class="border rounded px-2 py-1 w-28">
        </label>

        <label class="flex items-center gap-2">
          <input id="force" type="checkbox">
          <span>force overwrite</span>
        </label>

        <button onclick="runBatch()" class="bg-blue-600 text-white rounded px-4 py-2">
          Run batch
        </button>

        <button onclick="refreshStatus()" class="bg-gray-200 rounded px-4 py-2">
          Refresh
        </button>
      </div>

      <pre id="last" class="mono bg-gray-100 p-3 rounded overflow-auto" style="min-height: 120px;"></pre>
    </div>

    <div class="text-sm text-gray-600">
      <p>Tip: click “Run batch” repeatedly until done (keeps execution time low on shared hosting).</p>
    </div>
  </div>
</body>
</html>
HTML;
    }

    public function apiStatus(array $params = []): void
    {
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'success' => true,
            'imagick' => class_exists(\Imagick::class),
            'srcDir' => $this->srcDir,
            'destDir' => $this->destDir,
            'srcCount' => $this->countImages($this->srcDir),
            'destCount' => $this->countImages($this->destDir),
            'lastMessage' => '',
        ], JSON_PRETTY_PRINT);
    }

    public function apiRun(array $params = []): void
    {
        header('Content-Type: application/json; charset=utf-8');

        // Keep it robust on shared hosting.
        ini_set('memory_limit', '1024M');
        ini_set('max_execution_time', '0');

        if (!class_exists(\Imagick::class)) {
            echo json_encode([
                'success' => false,
                'message' => 'Imagick ist nicht verfuegbar.',
            ], JSON_PRETTY_PRINT);
            return;
        }

        $limit = (int)($_GET['limit'] ?? 50);
        if ($limit < 1) $limit = 1;
        if ($limit > 500) $limit = 500;

        $force = (string)($_GET['force'] ?? '0') === '1';

        $files = $this->listSourceImages($this->srcDir);

        $processed = 0;
        $skipped = 0;
        $errors = 0;
        $items = [];

        foreach ($files as $file) {
            if ($processed >= $limit) {
                break;
            }

            $srcPath = $this->srcDir . '/' . $file;
            $destPath = $this->destDir . '/' . $this->normalizeOutputName($file);

            if (!$force && file_exists($destPath)) {
                // Skip if destination is newer or same timestamp.
                if (filemtime($destPath) !== false && filemtime($srcPath) !== false && filemtime($destPath) >= filemtime($srcPath)) {
                    $skipped++;
                    continue;
                }
            }

            try {
                $this->createSmallJpeg($srcPath, $destPath);
                $processed++;
                $items[] = [
                    'file' => $file,
                    'out' => basename($destPath),
                ];
            } catch (\Throwable $e) {
                $errors++;
                error_log('OPTIMIZE IMG ERROR ' . $file . ': ' . $e->getMessage());
                $items[] = [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'limit' => $limit,
            'force' => $force,
            'srcCount' => count($files),
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => $errors,
            'items' => $items,
        ], JSON_PRETTY_PRINT);
    }

    // -------------------------
    // Helpers
    // -------------------------

    private function ensureDirectories(): void
    {
        if (!is_dir($this->srcDir)) {
            @mkdir($this->srcDir, 0775, true);
        }
        if (!is_dir($this->destDir)) {
            @mkdir($this->destDir, 0775, true);
        }
    }

    private function listSourceImages(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $out = [];
        $dh = opendir($dir);
        if ($dh === false) {
            return [];
        }

        while (($f = readdir($dh)) !== false) {
            // Skip dotfiles
            if ($f === '.' || $f === '..' || str_starts_with($f, '.')) {
                continue;
            }

            $path = $dir . '/' . $f;
            if (is_dir($path)) {
                continue;
            }

            $ext = strtolower((string)pathinfo($f, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                continue;
            }

            $out[] = $f;
        }

        closedir($dh);

        // Deterministic order
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    private function normalizeOutputName(string $srcFile): string
    {
        $name = (string)pathinfo($srcFile, PATHINFO_FILENAME);
        return $name . '.jpg';
    }

    private function countImages(string $dir): int
    {
        return count($this->listSourceImages($dir));
    }

    private function createSmallJpeg(string $srcPath, string $destPath): void
    {
        $img = new \Imagick();
        $img->readImage($srcPath);

        // Always work on first frame/layer (important for some formats)
        if (method_exists($img, 'setIteratorIndex')) {
            $img->setIteratorIndex(0);
        }

        // Normalize orientation if possible
        if (method_exists($img, 'autoOrient')) {
            $img->autoOrient();
        }

        // Resize to maxSide
        $w = $img->getImageWidth();
        $h = $img->getImageHeight();
        if ($w > 0 && $h > 0) {
            $longSide = max($w, $h);
            if ($longSide > $this->maxSide) {
                if ($w >= $h) {
                    $img->thumbnailImage($this->maxSide, 0);
                } else {
                    $img->thumbnailImage(0, $this->maxSide);
                }
            }
        }

        if ($this->stripMetadata && method_exists($img, 'stripImage')) {
            $img->stripImage();
        }

        // Flatten alpha channel onto white for JPEG output
        if ($img->getImageAlphaChannel()) {
            $img->setImageBackgroundColor('white');
            $flattened = $img->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $img->clear();
            $img->destroy();
            $img = $flattened;
        }

        $img->setImageFormat('jpeg');
        $img->setImageCompression(\Imagick::COMPRESSION_JPEG);
        $img->setImageCompressionQuality($this->quality);

        // Progressive JPEG
        if (defined('\Imagick::INTERLACE_PLANE')) {
            $img->setInterlaceScheme(\Imagick::INTERLACE_PLANE);
        }

        // Better size reduction
        $img->setOption('jpeg:optimize-coding', 'true');
        $img->setOption('jpeg:sampling-factor', '4:2:0');

        // Write atomically
        $tmp = $destPath . '.tmp';
        $img->writeImage($tmp);

        $img->clear();
        $img->destroy();

        @unlink($destPath);
        rename($tmp, $destPath);
    }
}