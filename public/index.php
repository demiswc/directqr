<?php
/**
 * DirectQR — your URL → QR. Nothing in between.
 *
 * A free, no-redirect, no-tracking QR code generator.
 * https://github.com/demiswc/directqr
 *
 * Single-file PHP app. Requires:
 *   - PHP 8.1+
 *   - GD extension
 *   - Composer dependencies (endroid/qr-code, bacon/bacon-qr-code)
 *
 * Configuration via environment variables (set in your web server config):
 *   DIRECTQR_RATE_LIMIT       — max generations per minute per IP (default: 10)
 *   DIRECTQR_MAX_UPLOAD_BYTES — max upload size in bytes (default: 5242880 = 5MB)
 *   DIRECTQR_MAX_DIMENSION    — max image dimension in pixels (default: 4000)
 *   DIRECTQR_DEBUG            — set to "1" to show errors (default: off in production)
 */

declare(strict_types=1);

// ----------------------------- Configuration -----------------------------
const APP_NAME    = 'DirectQR';
const APP_TAGLINE = 'Your URL → QR. Nothing in between.';

$DEBUG             = ($_ENV['DIRECTQR_DEBUG'] ?? '0') === '1';
$RATE_LIMIT        = (int)($_ENV['DIRECTQR_RATE_LIMIT'] ?? 10);
$MAX_UPLOAD_BYTES  = (int)($_ENV['DIRECTQR_MAX_UPLOAD_BYTES'] ?? 5_242_880);
$MAX_DIMENSION     = (int)($_ENV['DIRECTQR_MAX_DIMENSION'] ?? 4000);
$RATE_LIMIT_DIR    = sys_get_temp_dir() . '/directqr-ratelimit';

if ($DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

require_once __DIR__ . '/../vendor/autoload.php';

use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Common\ErrorCorrectionLevel as BaconECL;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

// ----------------------------- Helpers -----------------------------

function client_ip(): string {
    // Trust direct client IP only — no X-Forwarded-For unless behind known proxy
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function check_rate_limit(string $dir, int $limitPerMinute): bool {
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $ip   = client_ip();
    $hash = substr(hash('sha256', $ip), 0, 16);  // hashed for privacy
    $file = "$dir/$hash";
    $now  = time();
    $window = 60;
    $timestamps = [];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $timestamps = array_filter(
                array_map('intval', explode("\n", trim($raw))),
                fn($t) => $t > ($now - $window)
            );
        }
    }
    if (count($timestamps) >= $limitPerMinute) return false;
    $timestamps[] = $now;
    @file_put_contents($file, implode("\n", $timestamps), LOCK_EX);
    // Cleanup old files periodically (1% chance per request)
    if (mt_rand(1, 100) === 1) {
        foreach (glob("$dir/*") as $f) {
            if (filemtime($f) < $now - 600) @unlink($f);
        }
    }
    return true;
}

function validate_upload(array $file, int $maxBytes, int $maxDim): string|null {
    if ($file['error'] !== UPLOAD_ERR_OK) return "Upload failed (error code {$file['error']})";
    if ($file['size'] > $maxBytes) return "File too large (max " . ($maxBytes / 1024 / 1024) . "MB)";
    $info = @getimagesize($file['tmp_name']);
    if (!$info) return "Not a valid image";
    if ($info[0] > $maxDim || $info[1] > $maxDim) return "Image dimensions too large (max {$maxDim}px)";
    if (!in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP])) {
        return "Unsupported image type";
    }
    return null;
}

function load_image_any(string $path) {
    $info = @getimagesize($path);
    if (!$info) return null;
    return match ($info[2]) {
        IMAGETYPE_PNG  => imagecreatefrompng($path),
        IMAGETYPE_JPEG => imagecreatefromjpeg($path),
        IMAGETYPE_GIF  => imagecreatefromgif($path),
        IMAGETYPE_WEBP => imagecreatefromwebp($path),
        default        => null,
    };
}

function get_qr_matrix(string $url): array {
    $qrCode = Encoder::encode($url, BaconECL::H(), 'UTF-8');
    $matrix = $qrCode->getMatrix();
    $N      = $matrix->getWidth();
    $grid   = [];
    for ($y = 0; $y < $N; $y++) {
        for ($x = 0; $x < $N; $x++) {
            $grid[$y][$x] = ((int)$matrix->get($x, $y)) === 1;
        }
    }
    return [$grid, $N];
}

function draw_rounded_rect($img, int $x, int $y, int $w, int $h, int $r, int $color): void {
    $r = min($r, (int)($w / 2), (int)($h / 2));
    imagefilledrectangle($img, $x + $r, $y, $x + $w - $r - 1, $y + $h - 1, $color);
    imagefilledrectangle($img, $x, $y + $r, $x + $w - 1, $y + $h - $r - 1, $color);
    imagefilledellipse($img, $x + $r,          $y + $r,          $r * 2, $r * 2, $color);
    imagefilledellipse($img, $x + $w - $r - 1, $y + $r,          $r * 2, $r * 2, $color);
    imagefilledellipse($img, $x + $r,          $y + $h - $r - 1, $r * 2, $r * 2, $color);
    imagefilledellipse($img, $x + $w - $r - 1, $y + $h - $r - 1, $r * 2, $r * 2, $color);
}

// ----------------------------- Designer QR renderer -----------------------------

function render_designer_qr(
    string $url,
    ?string $logoPath,
    array  $fg,            // [r,g,b]
    array  $bg,            // [r,g,b]
    int    $finalSize,
    bool   $transparent,
    string $centerShape,   // 'circle' | 'square' | 'none'
    int    $centerSizePct  // 0-25
): string {
    [$grid, $N] = get_qr_matrix($url);

    $isFinder = fn($j, $i) =>
        ($j < 7 && $i < 7) ||
        ($j < 7 && $i >= $N - 7) ||
        ($j >= $N - 7 && $i < 7);

    $modulePx = 30;
    $quiet    = 4;
    $outSize  = ($N + $quiet * 2) * $modulePx;

    $img = imagecreatetruecolor($outSize, $outSize);
    imagesavealpha($img, true);
    imagealphablending($img, false);

    if ($transparent) {
        $bgColor = imagecolorallocatealpha($img, 0, 0, 0, 127);
    } else {
        $bgColor = imagecolorallocate($img, $bg[0], $bg[1], $bg[2]);
    }
    imagefilledrectangle($img, 0, 0, $outSize - 1, $outSize - 1, $bgColor);

    imagealphablending($img, true);
    $fgColor    = imagecolorallocate($img, $fg[0], $fg[1], $fg[2]);
    $whiteSolid = imagecolorallocate($img, 255, 255, 255);

    // Solid colour for areas inside finder/centre even in transparent mode
    // (these need a real surface or the QR isn't scannable)
    $finderInner = $transparent ? $whiteSolid : imagecolorallocate($img, $bg[0], $bg[1], $bg[2]);

    // 1. Data dots
    $dotR = (int)($modulePx * 0.4);
    for ($j = 0; $j < $N; $j++) {
        for ($i = 0; $i < $N; $i++) {
            if (!$grid[$j][$i]) continue;
            if ($isFinder($j, $i)) continue;
            $cx = ($quiet + $i) * $modulePx + (int)($modulePx / 2);
            $cy = ($quiet + $j) * $modulePx + (int)($modulePx / 2);
            imagefilledellipse($img, $cx, $cy, $dotR * 2, $dotR * 2, $fgColor);
        }
    }

    // 2. Rounded finder patterns
    foreach ([[0, 0], [0, $N - 7], [$N - 7, 0]] as [$tj, $ti]) {
        $x0 = ($quiet + $ti) * $modulePx;
        $y0 = ($quiet + $tj) * $modulePx;
        $sz = 7 * $modulePx;
        draw_rounded_rect($img, $x0, $y0, $sz, $sz, $modulePx * 2, $fgColor);
        draw_rounded_rect($img, $x0 + $modulePx, $y0 + $modulePx,
                          $sz - 2*$modulePx, $sz - 2*$modulePx, (int)($modulePx * 1.5), $finderInner);
        draw_rounded_rect($img, $x0 + 2*$modulePx, $y0 + 2*$modulePx,
                          $sz - 4*$modulePx, $sz - 4*$modulePx, $modulePx, $fgColor);
    }

    // 3. Centre shape
    if ($centerShape !== 'none' && $centerSizePct > 0) {
        $cx = (int)($outSize / 2);
        $cy = (int)($outSize / 2);
        $punchR = (int)($outSize * $centerSizePct / 100);
        $centerFill = $transparent ? $whiteSolid : imagecolorallocate($img, $bg[0], $bg[1], $bg[2]);

        if ($centerShape === 'circle') {
            imagefilledellipse($img, $cx, $cy, $punchR * 2, $punchR * 2, $centerFill);
            $ringW = max(4, (int)($modulePx / 6));
            imagesetthickness($img, $ringW);
            imageellipse($img, $cx, $cy, $punchR * 2, $punchR * 2, $fgColor);
            imagesetthickness($img, 1);
        } elseif ($centerShape === 'square') {
            $r = $modulePx * 2;
            draw_rounded_rect($img, $cx - $punchR, $cy - $punchR, $punchR * 2, $punchR * 2, $r, $centerFill);
            // (no ring for square — looks cleaner)
        }
    }

    // 4. Logo
    if ($logoPath !== null && is_file($logoPath)) {
        $logo = load_image_any($logoPath);
        if ($logo !== null) {
            imagealphablending($logo, false);
            imagesavealpha($logo, true);
            $lw = imagesx($logo); $lh = imagesy($logo);

            // Make near-white pixels transparent
            for ($yy = 0; $yy < $lh; $yy++) {
                for ($xx = 0; $xx < $lw; $xx++) {
                    $c = imagecolorat($logo, $xx, $yy);
                    $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
                    if ($r > 240 && $g > 240 && $b > 240) {
                        imagesetpixel($logo, $xx, $yy, imagecolorallocatealpha($logo, 255, 255, 255, 127));
                    }
                }
            }

            // Size: if there's a centre shape, fit inside it; if not, use a sensible default
            if ($centerShape !== 'none' && $centerSizePct > 0) {
                $logoSize = (int)($outSize * $centerSizePct / 100 * 1.4);
            } else {
                $logoSize = (int)($outSize * 0.15);
            }

            $resized = imagecreatetruecolor($logoSize, $logoSize);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $tr = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefilledrectangle($resized, 0, 0, $logoSize, $logoSize, $tr);
            imagealphablending($resized, true);
            imagecopyresampled($resized, $logo, 0, 0, 0, 0, $logoSize, $logoSize, $lw, $lh);

            $cx = (int)($outSize / 2);
            $cy = (int)($outSize / 2);
            imagealphablending($img, true);
            imagecopy($img, $resized, $cx - (int)($logoSize / 2), $cy - (int)($logoSize / 2),
                      0, 0, $logoSize, $logoSize);
        }
    }

    // 5. Resize to final size, preserving alpha
    $finalImg = imagecreatetruecolor($finalSize, $finalSize);
    imagesavealpha($finalImg, true);
    imagealphablending($finalImg, false);
    $tr = imagecolorallocatealpha($finalImg, 0, 0, 0, 127);
    imagefilledrectangle($finalImg, 0, 0, $finalSize, $finalSize, $tr);
    imagealphablending($finalImg, true);
    imagecopyresampled($finalImg, $img, 0, 0, 0, 0, $finalSize, $finalSize, $outSize, $outSize);

    ob_start();
    imagepng($finalImg);
    return ob_get_clean();
}

// ----------------------------- Halftone renderer (unchanged) -----------------------------

function render_halftone_qr(string $url, string $photoPath, int $finalSize = 1200): string {
    [$grid, $N] = get_qr_matrix($url);

    $fixed = [];
    for ($y = 0; $y < $N; $y++) for ($x = 0; $x < $N; $x++) $fixed[$y][$x] = false;
    for ($y = 0; $y < 8; $y++) for ($x = 0; $x < 8; $x++)     $fixed[$y][$x] = true;
    for ($y = 0; $y < 8; $y++) for ($x = $N-8; $x < $N; $x++) $fixed[$y][$x] = true;
    for ($y = $N-8; $y < $N; $y++) for ($x = 0; $x < 8; $x++) $fixed[$y][$x] = true;
    for ($x = 0; $x < $N; $x++) $fixed[6][$x] = true;
    for ($y = 0; $y < $N; $y++) $fixed[$y][6] = true;

    $scale   = 5;
    $outSize = $N * $scale;

    $srcImg = load_image_any($photoPath);
    if ($srcImg === null) throw new RuntimeException("Cannot read photo");

    $photoResized = imagecreatetruecolor($outSize, $outSize);
    imagecopyresampled($photoResized, $srcImg, 0, 0, 0, 0, $outSize, $outSize, imagesx($srcImg), imagesy($srcImg));
    imagefilter($photoResized, IMG_FILTER_GRAYSCALE);
    imagefilter($photoResized, IMG_FILTER_CONTRAST, -15);

    $photoArr = [];
    for ($y = 0; $y < $outSize; $y++) {
        $row = [];
        for ($x = 0; $x < $outSize; $x++) $row[] = imagecolorat($photoResized, $x, $y) & 0xFF;
        $photoArr[] = $row;
    }
    for ($y = 0; $y < $outSize; $y++) {
        for ($x = 0; $x < $outSize; $x++) {
            $old = $photoArr[$y][$x];
            $new = $old < 128 ? 0 : 255;
            $photoArr[$y][$x] = $new;
            $err = $old - $new;
            if ($x + 1 < $outSize)                      $photoArr[$y][$x+1]   += $err * 7 / 16;
            if ($y + 1 < $outSize && $x > 0)            $photoArr[$y+1][$x-1] += $err * 3 / 16;
            if ($y + 1 < $outSize)                      $photoArr[$y+1][$x]   += $err * 5 / 16;
            if ($y + 1 < $outSize && $x + 1 < $outSize) $photoArr[$y+1][$x+1] += $err * 1 / 16;
        }
    }

    $out = imagecreatetruecolor($outSize, $outSize);
    $white = imagecolorallocate($out, 255, 255, 255);
    $black = imagecolorallocate($out, 0, 0, 0);
    imagefilledrectangle($out, 0, 0, $outSize - 1, $outSize - 1, $white);

    for ($j = 0; $j < $N; $j++) {
        for ($i = 0; $i < $N; $i++) {
            $moduleBlack = $grid[$j][$i];
            $inFixed = $fixed[$j][$i];
            for ($dy = 0; $dy < $scale; $dy++) {
                for ($dx = 0; $dx < $scale; $dx++) {
                    $px = $i * $scale + $dx;
                    $py = $j * $scale + $dy;
                    $inCore = ($dx >= 1 && $dx <= 3 && $dy >= 1 && $dy <= 3);
                    if ($inFixed || $inCore) {
                        if ($moduleBlack) imagesetpixel($out, $px, $py, $black);
                    } else {
                        $v = max(0, min(255, (int)$photoArr[$py][$px]));
                        if ($v < 128) imagesetpixel($out, $px, $py, $black);
                    }
                }
            }
        }
    }

    $q = 4 * $scale;
    $wq = $outSize + $q * 2;
    $quietImg = imagecreatetruecolor($wq, $wq);
    $qw = imagecolorallocate($quietImg, 255, 255, 255);
    imagefilledrectangle($quietImg, 0, 0, $wq - 1, $wq - 1, $qw);
    imagecopy($quietImg, $out, $q, $q, 0, 0, $outSize, $outSize);

    $finalImg = imagecreatetruecolor($finalSize, $finalSize);
    imagecopyresized($finalImg, $quietImg, 0, 0, 0, 0, $finalSize, $finalSize, $wq, $wq);

    ob_start(); imagepng($finalImg); return ob_get_clean();
}

// ----------------------------- Request handler -----------------------------

if (isset($_POST['generate']) || isset($_GET['generate'])) {

    if (!check_rate_limit($RATE_LIMIT_DIR, $RATE_LIMIT)) {
        http_response_code(429);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Too many requests. Please wait a minute before generating more codes.\n\n"
           . "(This rate limit exists so the tool stays free and fast for everyone.)";
        exit;
    }

    $url       = trim($_POST['url']       ?? $_GET['url']       ?? '');
    $mode      = $_POST['mode']      ?? $_GET['mode']      ?? 'designer';
    $size      = (int)($_POST['size']      ?? $_GET['size']      ?? 1200);
    $size      = max(400, min($size, 3000));  // clamp
    $transparent  = !empty($_POST['transparent']);
    $centerShape  = $_POST['centershape']  ?? 'circle';
    if (!in_array($centerShape, ['circle', 'square', 'none'])) $centerShape = 'circle';
    $centerSize   = (int)($_POST['centersize'] ?? 14);
    $centerSize   = max(0, min($centerSize, 25));

    if ($url === '' || !preg_match('~^[a-z][a-z0-9+\-.]*://~i', $url)) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo "Please provide a valid URL starting with http:// or https://";
        exit;
    }

    $fgHex = preg_replace('/[^0-9a-fA-F]/', '', $_POST['fg'] ?? $_GET['fg'] ?? '000000') ?: '000000';
    $bgHex = preg_replace('/[^0-9a-fA-F]/', '', $_POST['bg'] ?? $_GET['bg'] ?? 'FFFFFF') ?: 'FFFFFF';
    if (strlen($fgHex) !== 6) $fgHex = '000000';
    if (strlen($bgHex) !== 6) $bgHex = 'FFFFFF';
    [$fR,$fG,$fB] = sscanf($fgHex, "%02x%02x%02x");
    [$bR,$bG,$bB] = sscanf($bgHex, "%02x%02x%02x");

    if ($mode === 'designer') {
        $logoPath = null;
        if (!empty($_FILES['photo']['name'])) {
            $err = validate_upload($_FILES['photo'], $MAX_UPLOAD_BYTES, $MAX_DIMENSION);
            if ($err) { http_response_code(400); header('Content-Type:text/plain'); echo $err; exit; }
            $logoPath = $_FILES['photo']['tmp_name'];
        }
        try {
            $png = render_designer_qr($url, $logoPath, [$fR,$fG,$fB], [$bR,$bG,$bB], $size, $transparent, $centerShape, $centerSize);
            header('Content-Type: image/png');
            header('Content-Disposition: inline; filename="qrcode.png"');
            echo $png; exit;
        } catch (Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain');
            echo "Sorry, generation failed.";
            if ($DEBUG) echo "\n\n" . $e->getMessage() . "\n" . $e->getTraceAsString();
            exit;
        }
    }

    if ($mode === 'halftone') {
        if (empty($_FILES['photo']['name'])) {
            http_response_code(400);
            header('Content-Type:text/plain');
            echo "Please upload a photograph for halftone mode.";
            exit;
        }
        $err = validate_upload($_FILES['photo'], $MAX_UPLOAD_BYTES, $MAX_DIMENSION);
        if ($err) { http_response_code(400); header('Content-Type:text/plain'); echo $err; exit; }
        try {
            $png = render_halftone_qr($url, $_FILES['photo']['tmp_name'], $size);
            header('Content-Type: image/png');
            header('Content-Disposition: inline; filename="qrcode.png"');
            echo $png; exit;
        } catch (Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain');
            echo "Sorry, generation failed.";
            if ($DEBUG) echo "\n\n" . $e->getMessage();
            exit;
        }
    }

    // Plain mode
    try {
        $args = [
            'writer' => new PngWriter(), 'writerOptions' => [], 'validateResult' => false,
            'data' => $url, 'encoding' => new Encoding('UTF-8'),
            'errorCorrectionLevel' => ErrorCorrectionLevel::High,
            'size' => $size, 'margin' => 20,
            'roundBlockSizeMode' => RoundBlockSizeMode::Margin,
            'foregroundColor' => new Color($fR, $fG, $fB),
            'backgroundColor' => new Color($bR, $bG, $bB),
        ];
        $result = (new Builder(...$args))->build();
        header('Content-Type: ' . $result->getMimeType());
        header('Content-Disposition: inline; filename="qrcode.png"');
        echo $result->getString();
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain');
        echo "Sorry, generation failed.";
        if ($DEBUG) echo "\n\n" . $e->getMessage();
        exit;
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Free QR code generator. Your URL goes directly in the QR — no redirects, no tracking, no expiry. Forever.">
<meta name="robots" content="index, follow">
<title><?= APP_NAME ?> — <?= APP_TAGLINE ?></title>
<style>
  :root {
    --pink: #E84C89; --pink-dark: #d23a77;
    --navy: #092370; --text: #1a1a1a; --muted: #666;
    --bg: #fafafa; --card: #ffffff; --border: #e5e5e5;
    --focus: #4A90E2;
  }
  @media (prefers-color-scheme: dark) {
    :root { --text: #f0f0f0; --muted: #aaa; --bg: #1a1a1a; --card: #2a2a2a; --border: #3a3a3a; }
  }
  * { box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    line-height: 1.5; color: var(--text); background: var(--bg);
    margin: 0; padding: 1rem;
  }
  .container { max-width: 780px; margin: 0 auto; }
  header { text-align: center; padding: 1.5rem 0 1rem; }
  h1 { font-size: clamp(1.8rem, 5vw, 2.4rem); margin: 0 0 .5rem; }
  h1 span { color: var(--pink); }
  .tagline { color: var(--muted); margin: 0; font-size: 1.05rem; }
  .promise {
    background: var(--card); border: 1px solid var(--border); border-radius: 12px;
    padding: 1rem 1.25rem; margin: 1.5rem 0; display: grid; gap: .4rem; font-size: .9rem;
  }
  .promise strong { color: var(--text); font-size: .95rem; }
  .promise ul { margin: .25rem 0 0; padding-left: 1.2rem; }
  .promise li { margin: .2rem 0; }
  .promise li::marker { content: "✓ "; color: var(--pink); }
  details {
    background: var(--card); border: 1px solid var(--border); border-radius: 12px;
    padding: .75rem 1.25rem; margin: 1rem 0;
  }
  details summary {
    font-weight: 600; cursor: pointer; padding: .5rem 0;
    outline-offset: 3px;
  }
  details[open] summary { margin-bottom: .5rem; }
  details ol { padding-left: 1.2rem; margin: .5rem 0; }
  details li { margin: .35rem 0; }
  form {
    background: var(--card); border: 1px solid var(--border); border-radius: 12px;
    padding: 1.5rem; display: grid; gap: 1.25rem;
  }
  label { display: grid; gap: .3rem; font-weight: 500; font-size: .92rem; }
  .hint { font-size: .82rem; color: var(--muted); font-weight: 400; }
  input[type=text], input[type=url], input[type=number], input[type=file], select {
    padding: .65rem .8rem; border: 1px solid var(--border); border-radius: 7px;
    font-size: 1rem; background: var(--bg); color: var(--text); font-family: inherit;
    width: 100%; min-width: 0;
  }
  input[type=file] { padding: .5rem .8rem; }
  input:focus, select:focus, button:focus {
    outline: 2px solid var(--focus); outline-offset: 2px;
  }
  input[type=color] {
    width: 48px; height: 44px; border: 1px solid var(--border);
    border-radius: 7px; padding: 2px; cursor: pointer; flex-shrink: 0;
  }
  input[type=range] { width: 100%; touch-action: pan-y; }
  .checkbox-row { display: flex; align-items: center; gap: .5rem; font-weight: 500; }
  .checkbox-row input { width: 20px; height: 20px; cursor: pointer; flex-shrink: 0; }
  .row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  @media (max-width: 480px) { .row { grid-template-columns: 1fr; } }
  /* Colours grid — stack on narrow screens */
  .colors { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  @media (max-width: 480px) { .colors { grid-template-columns: 1fr; } }
  .color-pair { display: flex; gap: .5rem; align-items: center; min-width: 0; }
  .color-pair input[type=text] { flex: 1; min-width: 0; font-family: ui-monospace, monospace; text-transform: uppercase; }
  .preset { display: flex; gap: .4rem; flex-wrap: wrap; }
  .preset button {
    border: 1px solid var(--border); background: var(--bg); padding: .5rem .75rem;
    border-radius: 6px; cursor: pointer; font-size: .85rem; color: var(--text);
    display: inline-flex; align-items: center; gap: .35rem;
    min-height: 44px; /* touch-friendly tap target */
  }
  .preset button:hover { border-color: var(--pink); }
  .preset button span.swatch {
    display: inline-block; width: 14px; height: 14px; border-radius: 3px;
    border: 1px solid rgba(0,0,0,.15);
  }
  fieldset {
    border: 1px solid var(--border); border-radius: 10px; padding: 1rem 1.25rem;
    display: grid; gap: 1rem; margin: 0;
  }
  legend { padding: 0 .5rem; font-weight: 600; }
  /* Generate button — full width on mobile */
  button.go {
    background: var(--pink); color: white; border: 0; padding: .9rem 1.5rem;
    border-radius: 8px; font-size: 1.05rem; cursor: pointer; font-weight: 600;
    transition: background .15s, opacity .15s; width: 100%;
    display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
  }
  @media (min-width: 481px) { button.go { width: auto; } }
  button.go:hover:not(:disabled) { background: var(--pink-dark); }
  button.go:focus { outline: 3px solid var(--focus); outline-offset: 2px; }
  button.go:disabled { opacity: .7; cursor: not-allowed; }
  /* Spinner */
  .spinner {
    display: inline-block; width: 18px; height: 18px;
    border: 2.5px solid rgba(255,255,255,.35);
    border-top-color: #fff; border-radius: 50%;
    animation: spin .6s linear infinite;
  }
  @keyframes spin { to { transform: rotate(360deg); } }
  /* Preview section */
  .preview {
    background: var(--card); border: 1px solid var(--border); border-radius: 12px;
    padding: 1.5rem; margin-top: 1.5rem; text-align: center;
  }
  .preview-status {
    display: flex; align-items: center; justify-content: center; gap: .5rem;
    margin-bottom: 1rem; font-weight: 600; font-size: 1.05rem;
  }
  .preview-status .checkmark { color: #27ae60; font-size: 1.3rem; }
  .preview img {
    width: 100%; max-width: 500px; height: auto; border-radius: 8px;
    background: repeating-conic-gradient(#f0f0f0 0% 25%, #ffffff 0% 50%) 0 / 20px 20px;
    image-rendering: pixelated;
  }
  /* Error state */
  .preview-error {
    background: #fdeaea; border: 1px solid #e74c3c; border-radius: 8px;
    padding: .75rem 1rem; color: #c0392b; font-size: .9rem; margin-top: .75rem;
  }
  @media (prefers-color-scheme: dark) {
    .preview-error { background: #3a1f1f; border-color: #6b2a2a; color: #ff8080; }
  }
  /* Action buttons under the QR */
  .preview-actions {
    display: flex; gap: .75rem; justify-content: center; flex-wrap: wrap;
    margin-top: 1rem;
  }
  .preview-actions a, .preview-actions button {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .7rem 1.25rem; border-radius: 8px; font-size: .95rem;
    font-weight: 600; cursor: pointer; text-decoration: none;
    transition: background .15s, border-color .15s;
    min-height: 44px;
  }
  .btn-download {
    background: var(--pink); color: #fff; border: 2px solid var(--pink);
  }
  .btn-download:hover { background: var(--pink-dark); border-color: var(--pink-dark); }
  .btn-share {
    background: transparent; color: var(--text); border: 2px solid var(--border);
  }
  .btn-share:hover { border-color: var(--pink); color: var(--pink); }
  .download-hint {
    background: #fef9e7; border: 1px solid #f4d03f; border-radius: 8px;
    padding: .75rem 1rem; margin-top: 1rem; font-size: .9rem; color: #6b5800;
    text-align: left;
  }
  @media (prefers-color-scheme: dark) {
    .download-hint { background: #3a3a1f; border-color: #5a5a2a; color: #ffe680; }
  }
  .hidden { display: none; }
  footer {
    text-align: center; padding: 2rem 1rem; color: var(--muted); font-size: .85rem;
    margin-top: 2rem; border-top: 1px solid var(--border);
  }
  footer a { color: var(--pink); }
  .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
  /* Tighten form padding on very small screens */
  @media (max-width: 400px) {
    body { padding: .75rem; }
    form { padding: 1rem; }
    fieldset { padding: .75rem 1rem; }
    .promise, details { padding: .75rem 1rem; }
    .preview { padding: 1rem; }
    .preview-actions a, .preview-actions button { padding: .6rem 1rem; font-size: .88rem; }
  }
</style>
</head>
<body>
<div class="container">

<header>
  <h1>Direct<span>QR</span></h1>
  <p class="tagline">Your URL → QR. Nothing in between.</p>
</header>

<section class="promise" aria-label="What makes this tool different">
  <strong>What makes <?= APP_NAME ?> different:</strong>
  <ul>
    <li>Your URL is encoded <em>directly</em> in the QR — no redirect service in the middle</li>
    <li>No tracking, no analytics, no cookies</li>
    <li>No "free for the first 100 scans, then pay us" trap</li>
    <li>Works forever as long as your website does</li>
    <li>Nothing is saved on our server — your image and URL are processed in memory and discarded</li>
  </ul>
</section>

<details>
  <summary>How to use it (30 seconds)</summary>
  <ol>
    <li><strong>Type or paste your URL</strong> — the full address starting with <code>https://</code></li>
    <li><strong>Pick a style</strong> — Designer for a stylish dotted QR with a logo, Plain for a classic black-and-white QR, or Halftone for a QR rendered from a photograph</li>
    <li><strong>Choose colours</strong> — or pick a preset</li>
    <li><strong>Upload a logo or photo</strong> if your chosen style uses one</li>
    <li><strong>Click Generate</strong>, then tap the <strong>Download</strong> button to save your QR code</li>
  </ol>
  <p><strong>Important:</strong> always scan your finished QR with a real phone (or two) before printing it on anything important. Decorative styles are scannable but slightly less robust than plain QRs.</p>
</details>

<form id="qr-form" method="post" enctype="multipart/form-data"
      aria-label="QR code generator">
  <input type="hidden" name="generate" value="1">

  <label for="url">
    Website URL
    <input type="url" id="url" name="url" required
           placeholder="https://example.com"
           value="https://"
           aria-describedby="url-hint">
    <span id="url-hint" class="hint">The full address. Must start with <code>http://</code> or <code>https://</code>.</span>
  </label>

  <label for="mode">
    Style
    <select name="mode" id="mode" onchange="toggleMode()" aria-describedby="mode-hint">
      <option value="designer" selected>✨ Designer — dotted, rounded, with a centred logo</option>
      <option value="plain">Plain — classic square QR with optional logo</option>
      <option value="halftone">Halftone — QR rendered from a photograph</option>
    </select>
    <span id="mode-hint" class="hint">Designer is the most stylish. Plain is the most universally compatible.</span>
  </label>

  <fieldset>
    <legend>Colours</legend>
    <div class="colors">
      <label for="fg-hex">Foreground (dots/dark parts)
        <div class="color-pair">
          <input type="color" id="fg-picker" value="#092370" aria-label="Foreground colour picker">
          <input type="text" name="fg" id="fg-hex" value="#092370" maxlength="7" aria-label="Foreground hex code">
        </div>
      </label>
      <label for="bg-hex">Background
        <div class="color-pair">
          <input type="color" id="bg-picker" value="#FFFFFF" aria-label="Background colour picker">
          <input type="text" name="bg" id="bg-hex" value="#FFFFFF" maxlength="7" aria-label="Background hex code">
        </div>
      </label>
    </div>
    <div>
      <span class="hint">Quick presets:</span>
      <div class="preset" role="group" aria-label="Colour presets">
        <button type="button" onclick="setColors('#000000','#FFFFFF')"><span class="swatch" style="background:#000"></span>Classic</button>
        <button type="button" onclick="setColors('#E84C89','#FFFFFF')"><span class="swatch" style="background:#E84C89"></span>Pink</button>
        <button type="button" onclick="setColors('#092370','#FFFFFF')"><span class="swatch" style="background:#092370"></span>Navy</button>
        <button type="button" onclick="setColors('#4A2C4E','#FFFFFF')"><span class="swatch" style="background:#4A2C4E"></span>Plum</button>
        <button type="button" onclick="setColors('#1B4332','#FFFFFF')"><span class="swatch" style="background:#1B4332"></span>Forest</button>
      </div>
      <span class="hint" style="margin-top:.5rem;display:block">Keep good contrast between foreground and background, or scanners will struggle.</span>
    </div>
  </fieldset>

  <fieldset id="designer-options">
    <legend>Designer QR options</legend>

    <label for="centershape">
      Centre shape
      <select name="centershape" id="centershape" onchange="toggleCenterSize()">
        <option value="circle" selected>Circle (with ring)</option>
        <option value="square">Rounded square</option>
        <option value="none">None — logo only, no shape</option>
      </select>
      <span class="hint">The clear area in the middle where your logo sits.</span>
    </label>

    <label for="centersize" id="centersize-label">
      Centre size: <span id="centersize-value">14</span>%
      <input type="range" name="centersize" id="centersize" min="8" max="22" value="14"
             oninput="document.getElementById('centersize-value').textContent = this.value">
      <span class="hint">How big the centre shape is. Bigger = more space for logo, but slightly less robust scanning.</span>
    </label>

    <label class="checkbox-row" for="transparent">
      <input type="checkbox" name="transparent" id="transparent" value="1">
      Transparent background (PNG)
    </label>
    <span class="hint" style="margin-top:-.75rem">Outputs a PNG with transparency outside the QR pattern. Great for placing on coloured cards or invitations. Centre shape and finder squares stay solid for scannability.</span>

    <label for="photo-designer">
      Centre logo (optional)
      <input type="file" name="photo" id="photo-designer" accept="image/png,image/jpeg,image/webp">
      <span class="hint">A simple icon or silhouette works best. Square aspect, light background.</span>
    </label>
  </fieldset>

  <fieldset id="halftone-options" class="hidden">
    <legend>Halftone QR — photograph</legend>
    <label for="photo-halftone">
      Photograph (required)
      <input type="file" name="photo" id="photo-halftone" accept="image/png,image/jpeg,image/webp">
      <span class="hint">High-contrast portraits work best. Square photos. The subject becomes a textured pattern made from QR data.</span>
    </label>
  </fieldset>

  <fieldset id="plain-options" class="hidden">
    <legend>Plain QR — nothing else to configure</legend>
    <p class="hint" style="margin:0">A simple, classic QR. No logo. Maximum scanning reliability.</p>
  </fieldset>

  <label for="size">
    Output size (px)
    <input type="number" name="size" id="size" value="1200" min="400" max="3000" step="50">
    <span class="hint">Bigger = sharper when printed. 1200px is great for most uses; bump to 2000+ for large posters.</span>
  </label>

  <div>
    <button type="submit" class="go" id="generate-btn">
      <span id="btn-label">Generate QR code →</span>
    </button>
  </div>
</form>

<div class="preview hidden" id="preview" aria-live="polite">
  <div class="preview-status" id="preview-status">
    <span class="checkmark">✓</span> Your QR code is ready
  </div>
  <img id="qr-img" alt="Generated QR code" style="display:none">
  <div id="preview-error" class="preview-error hidden"></div>
  <div class="preview-actions" id="preview-actions" style="display:none">
    <a id="download-btn" class="btn-download" download="qrcode.png" href="#">⬇ Download PNG</a>
    <button type="button" id="share-btn" class="btn-share" style="display:none">↗ Share</button>
  </div>
  <div class="download-hint">
    <strong>Before printing anything important:</strong> test-scan with at least 2 different phones.
  </div>
</div>

<footer>
  <?= APP_NAME ?> is free, open-source, and ad-free. Built with care.<br>
  Self-host it on your own server — your data, your control. <a href="https://github.com/demiswc/directqr" target="_blank" rel="noopener">View source &amp; installation guide on GitHub</a>.
</footer>

</div>

<script>
// --- Colour picker binding ---
function bindPair(p, h) {
  const picker = document.getElementById(p), hex = document.getElementById(h);
  picker.addEventListener('input', () => hex.value = picker.value.toUpperCase());
  hex.addEventListener('input', () => {
    let v = hex.value.trim();
    if (v[0] !== '#') v = '#' + v;
    if (/^#[0-9A-Fa-f]{6}$/.test(v)) picker.value = v.toLowerCase();
  });
  hex.addEventListener('blur', () => hex.value = hex.value.toUpperCase());
}
bindPair('fg-picker', 'fg-hex');
bindPair('bg-picker', 'bg-hex');

function setColors(fg, bg) {
  document.getElementById('fg-picker').value = fg.toLowerCase();
  document.getElementById('bg-picker').value = bg.toLowerCase();
  document.getElementById('fg-hex').value = fg.toUpperCase();
  document.getElementById('bg-hex').value = bg.toUpperCase();
}

function toggleMode() {
  const m = document.getElementById('mode').value;
  document.getElementById('plain-options').classList.toggle('hidden', m !== 'plain');
  document.getElementById('designer-options').classList.toggle('hidden', m !== 'designer');
  document.getElementById('halftone-options').classList.toggle('hidden', m !== 'halftone');
}

function toggleCenterSize() {
  const shape = document.getElementById('centershape').value;
  document.getElementById('centersize-label').style.display = shape === 'none' ? 'none' : 'grid';
}

toggleMode();
toggleCenterSize();

// --- QR generation via fetch + Blob ---
let currentBlobUrl = null;

document.getElementById('qr-form').addEventListener('submit', async function(e) {
  e.preventDefault();

  const btn       = document.getElementById('generate-btn');
  const btnLabel  = document.getElementById('btn-label');
  const preview   = document.getElementById('preview');
  const status    = document.getElementById('preview-status');
  const img       = document.getElementById('qr-img');
  const errorEl   = document.getElementById('preview-error');
  const actions   = document.getElementById('preview-actions');
  const dlBtn     = document.getElementById('download-btn');
  const shareBtn  = document.getElementById('share-btn');

  // Disable button + show spinner
  btn.disabled = true;
  btnLabel.innerHTML = '<span class="spinner"></span> Generating…';

  // Reset preview state
  img.style.display = 'none';
  actions.style.display = 'none';
  errorEl.classList.add('hidden');
  status.innerHTML = '<span class="spinner" style="border-color:var(--border);border-top-color:var(--pink)"></span> Generating your QR code…';
  preview.classList.remove('hidden');

  // Smooth scroll to preview
  setTimeout(() => preview.scrollIntoView({ behavior: 'smooth', block: 'start' }), 100);

  // Revoke previous blob
  if (currentBlobUrl) {
    URL.revokeObjectURL(currentBlobUrl);
    currentBlobUrl = null;
  }

  try {
    const formData = new FormData(this);
    const resp = await fetch(window.location.href, {
      method: 'POST',
      body: formData
    });

    if (!resp.ok) {
      const errText = await resp.text();
      throw new Error(errText || `Server error (${resp.status})`);
    }

    const contentType = resp.headers.get('Content-Type') || '';
    if (!contentType.startsWith('image/')) {
      const errText = await resp.text();
      throw new Error(errText || 'Unexpected response from server');
    }

    const blob = await resp.blob();
    currentBlobUrl = URL.createObjectURL(blob);

    // Show the image
    img.src = currentBlobUrl;
    img.style.display = 'block';

    // Update status
    status.innerHTML = '<span class="checkmark">✓</span> Your QR code is ready';

    // Wire up download button
    dlBtn.href = currentBlobUrl;
    dlBtn.download = 'qrcode.png';
    actions.style.display = 'flex';

    // Show share button if Web Share API is available
    if (navigator.canShare) {
      shareBtn.style.display = 'inline-flex';
      shareBtn.onclick = async () => {
        try {
          const file = new File([blob], 'qrcode.png', { type: 'image/png' });
          if (navigator.canShare({ files: [file] })) {
            await navigator.share({ files: [file], title: 'QR Code', text: 'My QR code from DirectQR' });
          }
        } catch (shareErr) {
          if (shareErr.name !== 'AbortError') console.warn('Share failed:', shareErr);
        }
      };
    } else {
      shareBtn.style.display = 'none';
    }

  } catch (err) {
    status.innerHTML = '<span style="color:#e74c3c;font-size:1.3rem">✕</span> Generation failed';
    errorEl.textContent = err.message;
    errorEl.classList.remove('hidden');
  } finally {
    btn.disabled = false;
    btnLabel.textContent = 'Generate QR code →';
  }
});
</script>
</body>
</html>
