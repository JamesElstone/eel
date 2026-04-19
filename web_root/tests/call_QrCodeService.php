<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$code = trim((string)($_GET['code'] ?? ''));
$format = strtolower(trim((string)($_GET['format'] ?? 'svg')));
$errorCorrectionLevel = strtoupper(trim((string)($_GET['error_correction_level'] ?? 'M')));
$moduleSize = max(1, (int)($_GET['module_size'] ?? 10));
$autoRequested = isset($_GET['auto']) && $_GET['auto'] === '1';
$output = '';
$error = '';
$service = new QrCodeService();
$resolvedConfig = null;

$options = [
    'format' => $format,
    'error_correction_level' => $errorCorrectionLevel,
    'module_size' => $moduleSize,
];

if ($code !== '') {
    try {
        if ($autoRequested) {
            $resolvedConfig = $service->resolveAutoOptions($code, $options);
            $output = $service->generateQRcode($code, $resolvedConfig);
            $format = $resolvedConfig['format'];
            $errorCorrectionLevel = $resolvedConfig['error_correction_level'];
            $moduleSize = $resolvedConfig['module_size'];
        } else {
            $resolvedConfig = $options;
            $output = $service->generateQRcode($code, $options);
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if ($output !== '' && isset($_GET['raw']) && $_GET['raw'] === '1') {
    header($format === 'png'
        ? 'Content-Type: image/png'
        : 'Content-Type: image/svg+xml; charset=utf-8');
    echo $output;
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Call QrCodeService</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 2rem;
            color: #111827;
            background: #f9fafb;
        }

        main {
            max-width: 48rem;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid #d1d5db;
            border-radius: 0.75rem;
            padding: 1.5rem;
        }

        form {
            display: grid;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        label {
            font-weight: 600;
        }

        input[type="text"] {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #9ca3af;
            border-radius: 0.5rem;
            font-size: 1rem;
            box-sizing: border-box;
        }

        select,
        input[type="number"] {
            width: fit-content;
            padding: 0.75rem;
            border: 1px solid #9ca3af;
            border-radius: 0.5rem;
            font-size: 1rem;
            box-sizing: border-box;
            background: #ffffff;
        }

        button {
            width: fit-content;
            padding: 0.75rem 1rem;
            border: 0;
            border-radius: 0.5rem;
            background: #111827;
            color: #ffffff;
            cursor: pointer;
        }

        .preview {
            display: grid;
            gap: 1rem;
            justify-items: start;
        }

        .preview svg {
            display: block;
            width: auto;
            height: auto;
            max-width: 100%;
            border: 1px solid #d1d5db;
            background: #ffffff;
            image-rendering: pixelated;
        }

        .preview img {
            display: block;
            width: auto;
            height: auto;
            max-width: 100%;
            border: 1px solid #d1d5db;
            background: #ffffff;
            image-rendering: pixelated;
        }

        .error {
            color: #b91c1c;
            font-weight: 600;
        }

        .note {
            color: #1f2937;
            background: #f3f4f6;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
        }

        .note ul {
            margin: 0.5rem 0 0 1.25rem;
            padding: 0;
        }

        code {
            background: #f3f4f6;
            padding: 0.15rem 0.35rem;
            border-radius: 0.25rem;
        }
    </style>
</head>
<body>
<main>
    <h1>Call QrCodeService</h1>
    <p>Pass a value in <code>?code=...</code> to render a QR code inline.</p>

    <form method="get">
        <label for="code">Code</label>
        <input
            id="code"
            name="code"
            type="text"
            value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="https://example.com"
        >
        <label for="format">Format</label>
        <select id="format" name="format">
            <option value="svg" <?= $format === 'svg' ? 'selected' : '' ?>>svg</option>
            <option value="png" <?= $format === 'png' ? 'selected' : '' ?>>png</option>
        </select>
        <label for="error_correction_level">Error correction level</label>
        <select id="error_correction_level" name="error_correction_level">
            <option value="L" <?= $errorCorrectionLevel === 'L' ? 'selected' : '' ?>>L</option>
            <option value="M" <?= $errorCorrectionLevel === 'M' ? 'selected' : '' ?>>M</option>
            <option value="Q" <?= $errorCorrectionLevel === 'Q' ? 'selected' : '' ?>>Q</option>
            <option value="H" <?= $errorCorrectionLevel === 'H' ? 'selected' : '' ?>>H</option>
        </select>
        <label for="module_size">Module size</label>
        <input
            id="module_size"
            name="module_size"
            type="number"
            min="1"
            step="1"
            value="<?= htmlspecialchars((string)$moduleSize, ENT_QUOTES, 'UTF-8') ?>"
        >
        <button type="submit">Generate QR code</button>
        <button type="submit" name="auto" value="1">Auto</button>
    </form>

    <?php if ($error !== ''): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php elseif ($output !== ''): ?>
        <section class="preview">
            <p>QR code for <code><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></code></p>
            <?php if ($autoRequested && is_array($resolvedConfig)): ?>
                <div class="note">
                    <p>Auto detected the following settings:</p>
                    <ul>
                        <li>Error Correction Level: <?= htmlspecialchars((string)$resolvedConfig['error_correction_level'], ENT_QUOTES, 'UTF-8') ?></li>
                        <li>Module Size: <?= htmlspecialchars((string)$resolvedConfig['module_size'], ENT_QUOTES, 'UTF-8') ?></li>
                        <li>QR Code Version: <?= htmlspecialchars((string)($resolvedConfig['version'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
                    </ul>
                </div>
            <?php endif; ?>
            <img
                src="?code=<?= rawurlencode($code) ?>&format=<?= rawurlencode($format) ?>&error_correction_level=<?= rawurlencode($errorCorrectionLevel) ?>&module_size=<?= rawurlencode((string)$moduleSize) ?>&raw=1"
                alt="QR code for <?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"
            >
            <p><a href="?code=<?= rawurlencode($code) ?>&format=<?= rawurlencode($format) ?>&error_correction_level=<?= rawurlencode($errorCorrectionLevel) ?>&module_size=<?= rawurlencode((string)$moduleSize) ?>&raw=1">Open raw <?= htmlspecialchars(strtoupper($format), ENT_QUOTES, 'UTF-8') ?> only</a></p>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
