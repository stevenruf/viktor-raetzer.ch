<?php
declare(strict_types=1);

namespace App\productimport;

use pFrame\Core\pFrameCore;
use Imagick;

final class ProductImportController
{
    private pFrameCore $pf;

    // --- Adjust these paths to your project conventions if needed
    private string $importDir;
    private string $trashDir;
    private string $jpgDir;
    private string $pdfDir;
    private string $originalsDir;
    private string $httpImportBase;

    private int $fileSizeLimitBytes = 1000 * 1024 * 1024; // 1000 MB

    /** @var string[] */
    private array $unallowedExtensions = ['html', 'php'];

    public function __construct(pFrameCore $pf)
    {
        $this->pf = $pf;

        $root = rtrim($pf->getProjectRoot(), '/');

        // Filesystem
        $this->importDir     = $root . '/import';
        $this->trashDir      = $this->importDir . '/trash';
        $this->jpgDir        = $this->importDir . '/jpg';
        $this->pdfDir        = $this->importDir . '/pdf';
        $this->originalsDir  = $this->importDir . '/Produktbilder';

        // Public URL base (falls du __DOMAIN__ nicht mehr brauchst)
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $this->httpImportBase = $scheme . '://' . $host . '/import/';

        $this->ensureDirectories();
    }

    public function run(array $params = []): void
    {
        // Render HTML page (similar to your existing script)
        // Comments are in English by request.

        header('Content-Type: text/html; charset=utf-8');

        echo <<<HTML
<!DOCTYPE html>
<html>
<head>
  <title>Produktdaten-Import – Dateien per Drag & Drop hochladen</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <style>
    p, a { font-size: 1.8em !important; }
    .file-drop { border: 4px dashed #ddd; min-height: 400px; padding: 40px; }
    .text-lg { text-align: center; }
    .file-drop.hover { border-color: #b8b8b8; }
    .file-list { margin-top: 1rem; }
    .file-list-item { margin-bottom: 0.5rem; }
    .file-link { color: #3182ce; }
    .spinner {
      width: 50px; height: 50px; border: 4px solid #ddd; border-top: 4px solid #3182ce;
      border-radius: 50%; margin: 30px auto 10px auto; animation: spin 1s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .hidden { display: none; }
    .upload-message { text-align: center; font-size: 1em; margin-top: 20px; }
    .upload-success { color: green; }
    .upload-error { color: red; }
  </style>
  <script>
    function handleDrop(e) {
      e.preventDefault();
      const files = e.dataTransfer.files;
      uploadFiles(files);
    }
    function handleDragOver(e) {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'copy';
      e.target.classList.add('hover');
    }
    function handleDragLeave(e) {
      e.target.classList.remove('hover');
    }
    function handleFileInputChange(e) {
      const files = e.target.files;
      uploadFiles(files);
    }

    let fileListRequestId = 0;

    function updateFileList() {
    const currentRequestId = ++fileListRequestId;

    const fileListContainer = document.getElementById('file-list-container');
    if (!fileListContainer) return;

    // Clear immediately
    fileListContainer.replaceChildren();

    fetch('/api/productimport/list/')
      .then(response => response.json())
      .then(data => {
        // Ignore stale responses (out-of-order)
        if (currentRequestId !== fileListRequestId) return;

        const files = (data && data.files) ? data.files : [];
        files.forEach(file => {
          // Client-side safety (in case server still returns dotfiles)
          if (file.name && file.name.startsWith('.')) return;

          const listItem = document.createElement('div');
          listItem.classList.add('file-list-item');

          const fileLink = document.createElement('a');
          fileLink.href = file.url;
          fileLink.classList.add('file-link');
          fileLink.textContent = file.name;
          fileLink.setAttribute('download', '');

          listItem.appendChild(fileLink);
          fileListContainer.appendChild(listItem);
        });
      })
      .catch(error => {
        // Ignore stale errors as well
        if (currentRequestId !== fileListRequestId) return;
        console.error('Fehler beim Abrufen der Dateiliste:', error);
      });
    }

    function showSpinner() { document.getElementById('spinner').classList.remove('hidden'); }
    function hideSpinner() { document.getElementById('spinner').classList.add('hidden'); }

    function uploadFiles(files) {
      showSpinner();
      let fileIndex = 0;
      let fileUploadCount = 0;

      function uploadNext() {
        if (!files || fileIndex >= files.length) {
          showUploadMessage(fileUploadCount + ' Dateien hochgeladen', 'upload-success');
          hideSpinner();
          updateFileList();
          return;
        }

        const file = files[fileIndex];
        const formData = new FormData();
        formData.append('file[]', file);

        fetch('/api/productimport/upload/', {
          method: 'POST',
          body: formData
        })
        .then(response => response.json())
        .then(data => {
          fileUploadCount++;
          showUploadMessage(file.name, 'upload-success');
          fileIndex++;
          updateFileList();
          uploadNext();
        })
        .catch(error => {
          console.error('Fehler beim Upload:', error);
          showUploadMessage('Fehler beim Upload von ' + file.name + ': ' + error.message, 'upload-error');
          fileIndex++;
          updateFileList();
          uploadNext();
        });
      }

      uploadNext();
    }

    function showUploadMessage(message, className) {
      const uploadMessage = document.getElementById('upload-message');
      const p = document.createElement("p");
      p.textContent = message;
      if (uploadMessage.firstChild) {
        uploadMessage.insertBefore(p, uploadMessage.firstChild);
      } else {
        uploadMessage.appendChild(p);
      }
      p.classList.remove("upload-error");
      p.classList.add(className);
    }
  </script>
</head>
<body>
  <div class="container mx-auto p-4">
    <div class="file-drop p-4"
         ondrop="handleDrop(event)"
         ondragover="handleDragOver(event)"
         ondragleave="handleDragLeave(event)"
         onclick="document.getElementById('file-input').click()">
      <p class="text-lg text-gray-700">Dateien hier ablegen</p>
      <input id="file-input" type="file" multiple class="hidden" onchange="handleFileInputChange(event)">
      <div id="spinner" class="spinner hidden"></div>
      <div id="upload-message" class="upload-message"></div>
    </div>
    <div id="file-list-container" class="file-list"></div>
  </div>
  <script>updateFileList();</script>
</body>
</html>
HTML;
    }

    public function apiList(array $params = []): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $response = [
            'success' => true,
            'files' => [],
        ];

        if (!is_dir($this->importDir)) {
            echo json_encode([
                'success' => false,
                'message' => 'Import-Verzeichnis existiert nicht.',
                'files' => [],
            ], JSON_PRETTY_PRINT);
            return;
        }

        $handle = opendir($this->importDir);
        if ($handle === false) {
            echo json_encode([
                'success' => false,
                'message' => 'Fehler beim Oeffnen des Upload-Verzeichnisses.',
                'files' => [],
            ], JSON_PRETTY_PRINT);
            return;
        }

        while (($file = readdir($handle)) !== false) {
            // Skip dotfiles like .DS_Store, .gitkeep, etc.
            if (str_starts_with($file, '.')) {
                continue;
            }

            if ($file === '.' || $file === '..') { // könnte man vermutlich löschen
                continue;
            }

            $fullPath = $this->importDir . '/' . $file;
            if (is_dir($fullPath)) {
                continue;
            }

            $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, $this->unallowedExtensions, true)) {
                continue;
            }

            $response['files'][] = [
                'name' => $file,
                'url'  => $this->httpImportBase . rawurlencode($file),
            ];
        }

        closedir($handle);

        usort($response['files'], fn($a, $b) => strcasecmp($a['name'], $b['name']));

        echo json_encode($response, JSON_PRETTY_PRINT);
    }

    public function apiUpload(array $params = []): void
    {
        header('Content-Type: application/json; charset=utf-8');

        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', '0');

        if (empty($_FILES['file']['name'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Keine Dateien zum Hochladen ausgewaehlt.',
            ], JSON_PRETTY_PRINT);
            return;
        }

        $totalFiles = count((array)$_FILES['file']['name']);
        $uploadedFiles = 0;

        for ($i = 0; $i < $totalFiles; $i++) {
            $fileName = $this->getFileNameWithExtension((string)$_FILES['file']['name'][$i]);
            $tmpName  = (string)$_FILES['file']['tmp_name'][$i];
            $size     = (int)$_FILES['file']['size'][$i];
            $err      = (int)$_FILES['file']['error'][$i];

            if ($err !== UPLOAD_ERR_OK) {
                continue;
            }

            if ($size > $this->fileSizeLimitBytes) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Die Datei "' . $fileName . '" ueberschreitet das Upload-Limit.',
                ], JSON_PRETTY_PRINT);
                return;
            }

            $ext = $this->getFileExtension($fileName);

            $finalPath = match ($ext) {
                'xlsx' => $this->importDir . '/' . $fileName,
                'pdf'  => $this->pdfDir . '/' . $fileName,
                'psd', 'jpg', 'jpeg', 'png' => $this->jpgDir . '/' . $fileName,
                default => $this->trashDir . '/' . $fileName,
            };

            if (!@move_uploaded_file($tmpName, $finalPath)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Konnte Datei nicht speichern: ' . $fileName,
                ], JSON_PRETTY_PRINT);
                return;
            }

            // Image processing
            if (in_array($ext, ['psd', 'jpg', 'jpeg', 'png'], true) && class_exists(\Imagick::class)) {
                try {
                    // Optional: quick sanity read - if this fails, skip processing (prevents hard fails)
                    $probe = new \Imagick($finalPath);
                    $probe->clear();
                    $probe->destroy();

                    // Convert to JPG and resize (max 4000px)
                    $this->convertImage($finalPath, 'jpg', 72, null, 2400);

                } catch (\Throwable $e) {
                    // Keep the uploaded file; just skip processing and log once
                    error_log('IMG PROCESS SKIPPED ' . $fileName . ': ' . $e->getMessage());
                }
            }

            $uploadedFiles++;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Upload erfolgreich.',
            'uploadedFiles' => $uploadedFiles,
            'totalFiles' => $totalFiles,
            'fileCount' => $totalFiles,
        ], JSON_PRETTY_PRINT);
    }

    public function apiZip(array $params = []): void
    {
        header('Content-Type: application/json; charset=utf-8');

        ini_set('memory_limit', '5512M');
        ini_set('max_execution_time', '0');

        // Uses your existing helper if available
        if (function_exists('pFrame_zip')) {
            pFrame_zip($this->originalsDir);
            echo json_encode(['success' => true], JSON_PRETTY_PRINT);
            return;
        }

        echo json_encode([
            'success' => false,
            'message' => 'pFrame_zip() ist nicht verfuegbar.',
        ], JSON_PRETTY_PRINT);
    }

    // -------------------------
    // Helpers
    // -------------------------

    private function ensureDirectories(): void
    {
        // Create directories if missing
        foreach ([$this->importDir, $this->trashDir, $this->jpgDir, $this->pdfDir, $this->originalsDir] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }

    private function getFileExtension(string $filename): string
    {
        $ext = (string)pathinfo($filename, PATHINFO_EXTENSION);
        return strtolower($ext);
    }

    private function getFileNameWithExtension(string $filePath): string
    {
        $info = pathinfo($filePath);
        $name = (string)($info['filename'] ?? '');
        $ext  = strtolower((string)($info['extension'] ?? ''));
        return $ext !== '' ? ($name . '.' . $ext) : $name;
    }

    private function replaceFileExtension(string $filePath, string $newExtension): string
    {
        $info = pathinfo($filePath);
        $dir = (string)($info['dirname'] ?? '');
        $name = (string)($info['filename'] ?? '');
        return rtrim($dir, '/') . '/' . $name . '.' . $newExtension;
    }

    private function convertImage(
        string $path,
        string $type = 'jpg',
        int $quality = 72,
        ?float $cropRatio = null,
        ?int $maxSide = 2400
    ): string {
        // Convert + optionally crop + resize. Always writes to a temp file to ensure re-encoding.

        $image = new \Imagick();
        $image->readImage($path);

        // Ensure we're working on the first frame/layer (important for PSD and some formats)
        if (method_exists($image, 'setIteratorIndex')) {
            $image->setIteratorIndex(0);
        }

        // Normalize orientation based on EXIF (if supported)
        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();
        }

        // Crop to ratio if requested
        if ($cropRatio !== null) {
            $w = $image->getImageWidth();
            $h = $image->getImageHeight();

            if ($w > 0 && $h > 0) {
                $currentRatio = $w / $h;

                if (abs($currentRatio - $cropRatio) > 0.0001) {
                    if ($currentRatio > $cropRatio) {
                        // Too wide -> crop width
                        $newW = (int)round($h * $cropRatio);
                        $newH = $h;
                    } else {
                        // Too tall -> crop height
                        $newW = $w;
                        $newH = (int)round($w / $cropRatio);
                    }

                    $x = (int)max(0, round(($w - $newW) / 2));
                    $y = (int)max(0, round(($h - $newH) / 2));

                    $image->cropImage($newW, $newH, $x, $y);
                    $image->setImagePage(0, 0, 0, 0);
                }
            }
        }

        // Resize to maxSide (width OR height)
        if ($maxSide !== null && $maxSide > 0) {
            $w = $image->getImageWidth();
            $h = $image->getImageHeight();

            if ($w > 0 && $h > 0) {
                $longSide = max($w, $h);

                if ($longSide > $maxSide) {
                    // thumbnailImage keeps aspect ratio when one dimension is 0
                    if ($w >= $h) {
                        $image->thumbnailImage($maxSide, 0);
                    } else {
                        $image->thumbnailImage(0, $maxSide);
                    }
                }
            }
        }

        // Strip metadata (big size win)
        if (method_exists($image, 'stripImage')) {
            $image->stripImage();
        }

        // Always write as JPEG (as requested), flatten transparency onto white
        $targetPath = $this->replaceFileExtension($path, 'jpg');
        $tmpPath = $targetPath . '.tmp';

        $image->setImageFormat('jpeg');
        $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
        $image->setImageCompressionQuality($quality);

        // Progressive JPEG
        if (defined('\Imagick::INTERLACE_PLANE')) {
            $image->setInterlaceScheme(\Imagick::INTERLACE_PLANE);
        }

        // Ensure no alpha channel in final JPEG
        if ($image->getImageAlphaChannel()) {
            $image->setImageBackgroundColor('white');
            $flattened = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            // mergeImageLayers returns a new Imagick object -> must assign
            $image->clear();
            $image->destroy();
            $image = $flattened;
            $image->setImageFormat('jpeg');
            $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
            $image->setImageCompressionQuality($quality);
        }

        // More reliable size reduction
        $image->setOption('jpeg:optimize-coding', 'true');
        $image->setOption('jpeg:sampling-factor', '4:2:0');

        // Write to temp, then replace atomically
        $image->writeImage($tmpPath);

        $image->clear();
        $image->destroy();

        @unlink($targetPath);
        rename($tmpPath, $targetPath);

        // If extension changed, remove original
        if ($targetPath !== $path && file_exists($path)) {
            @unlink($path);
        }

        return $targetPath;
    }
}