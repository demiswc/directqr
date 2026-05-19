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
use Endroid\QrCode\Writer\SvgWriter;

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
    int    $centerBorder,  // 0-10
    int    $centerSizePct, // 0-25
    ?array $fg2 = null     // [r,g,b] for gradient end
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
            
            $dotColor = $fgColor;
            if ($fg2) {
                // Diagonal gradient factor 0.0 to 1.0
                $factor = ($i + $j) / (($N - 1) * 2);
                $r = (int)($fg[0] + ($fg2[0] - $fg[0]) * $factor);
                $g = (int)($fg[1] + ($fg2[1] - $fg[1]) * $factor);
                $b = (int)($fg[2] + ($fg2[2] - $fg[2]) * $factor);
                $dotColor = imagecolorallocate($img, $r, $g, $b);
            }
            imagefilledellipse($img, $cx, $cy, $dotR * 2, $dotR * 2, $dotColor);
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
    $centerBorderPx = 0;
    if ($centerShape !== 'none' && $centerSizePct > 0) {
        $cx = (int)($outSize / 2);
        $cy = (int)($outSize / 2);
        $punchR = (int)($outSize * $centerSizePct / 100);
        $centerBorderPx = (int)($centerBorder * ($modulePx / 2));
        $centerFill = $transparent ? $whiteSolid : imagecolorallocate($img, $bg[0], $bg[1], $bg[2]);

        if ($centerShape === 'circle') {
            if ($centerBorderPx > 0) {
                imagefilledellipse($img, $cx, $cy, $punchR * 2, $punchR * 2, $fgColor);
                $innerD = ($punchR * 2) - ($centerBorderPx * 2);
                imagefilledellipse($img, $cx, $cy, $innerD, $innerD, $centerFill);
            } else {
                imagefilledellipse($img, $cx, $cy, $punchR * 2, $punchR * 2, $centerFill);
            }
        } elseif ($centerShape === 'square') {
            $r = max(1, $modulePx * 2);
            if ($centerBorderPx > 0) {
                draw_rounded_rect($img, $cx - $punchR, $cy - $punchR, $punchR * 2, $punchR * 2, $r, $fgColor);
                $innerW = ($punchR * 2) - ($centerBorderPx * 2);
                draw_rounded_rect($img, $cx - $punchR + $centerBorderPx, $cy - $punchR + $centerBorderPx, 
                                  $innerW, $innerW, max(1, $r - $centerBorderPx), $centerFill);
            } else {
                draw_rounded_rect($img, $cx - $punchR, $cy - $punchR, $punchR * 2, $punchR * 2, $r, $centerFill);
            }
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

            // Size: fit inside the center area
            $logoSize = (int)(($outSize * $centerSizePct / 100 * 2) - ($centerBorderPx * 2)) - (int)($modulePx * 0.5);
            if ($centerShape === 'none') $logoSize = (int)($outSize * 0.15);

            $resized = imagecreatetruecolor($logoSize, $logoSize);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $tr = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefilledrectangle($resized, 0, 0, $logoSize, $logoSize, $tr);
            imagealphablending($resized, true);
            imagecopyresampled($resized, $logo, 0, 0, 0, 0, $logoSize, $logoSize, $lw, $lh);

            // Masking
            if ($centerShape === 'circle') {
                $maskR = $logoSize / 2;
                for ($x = 0; $x < $logoSize; $x++) {
                    for ($y = 0; $y < $logoSize; $y++) {
                        if ((($x - $maskR + 0.5)**2 + ($y - $maskR + 0.5)**2) > ($maskR**2)) {
                            imagesetpixel($resized, $x, $y, $tr);
                        }
                    }
                }
            } elseif ($centerShape === 'square') {
                $r = (int)($logoSize * 0.2);
                for ($x = 0; $x < $logoSize; $x++) {
                    for ($y = 0; $y < $logoSize; $y++) {
                        $isCorner = ($x < $r && $y < $r) || ($x > $logoSize - $r && $y < $r) || ($x < $r && $y > $logoSize - $r) || ($x > $logoSize - $r && $y > $logoSize - $r);
                        if ($isCorner) {
                            $dist = sqrt((($x - ($x < $r ? $r : $logoSize - $r))**2) + (($y - ($y < $r ? $r : $logoSize - $r))**2));
                            if ($dist > $r) imagesetpixel($resized, $x, $y, $tr);
                        }
                    }
                }
            }

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

    $contentType = $_POST['content_type'] ?? 'url';
    $qrData = '';

    if ($contentType === 'url') {
        $qrData = trim($_POST['url'] ?? '');
        if ($qrData === '' || !preg_match('~^[a-z][a-z0-9+\-.]*://~i', $qrData)) {
            http_response_code(400); header('Content-Type: text/plain');
            echo "Please provide a valid URL starting with http:// or https://"; exit;
        }
    } elseif ($contentType === 'wifi') {
        $ssid = trim($_POST['wifi_ssid'] ?? '');
        $pass = trim($_POST['wifi_pass'] ?? '');
        $enc  = $_POST['wifi_enc'] ?? 'WPA';
        $hid  = !empty($_POST['wifi_hidden']) ? 'true' : 'false';
        if ($ssid === '') {
            http_response_code(400); header('Content-Type: text/plain');
            echo "Please provide a Network Name (SSID) for the Wi-Fi."; exit;
        }
        $qrData = "WIFI:S:{$ssid};T:{$enc};P:{$pass};H:{$hid};;";
    } elseif ($contentType === 'vcard') {
        $fn = trim($_POST['vc_fname'] ?? '');
        $ln = trim($_POST['vc_lname'] ?? '');
        $org = trim($_POST['vc_org'] ?? '');
        $title = trim($_POST['vc_title'] ?? '');
        $tel = trim($_POST['vc_tel'] ?? '');
        $email = trim($_POST['vc_email'] ?? '');
        $vurl = trim($_POST['vc_url'] ?? '');
        $qrData = "BEGIN:VCARD\nVERSION:3.0\n";
        $qrData .= "N:{$ln};{$fn};;;\nFN:{$fn} {$ln}\n";
        if ($org) $qrData .= "ORG:{$org}\n";
        if ($title) $qrData .= "TITLE:{$title}\n";
        if ($tel) $qrData .= "TEL:{$tel}\n";
        if ($email) $qrData .= "EMAIL:{$email}\n";
        if ($vurl) $qrData .= "URL:{$vurl}\n";
        $qrData .= "END:VCARD";
    } elseif ($contentType === 'text') {
        $qrData = trim($_POST['text_content'] ?? '');
        if ($qrData === '') {
            http_response_code(400); header('Content-Type: text/plain');
            echo "Please provide some text."; exit;
        }
    } elseif ($contentType === 'email') {
        $to = trim($_POST['email_to'] ?? '');
        $sub = trim($_POST['email_sub'] ?? '');
        $body = trim($_POST['email_body'] ?? '');
        if ($to === '') {
            http_response_code(400); header('Content-Type: text/plain');
            echo "Please provide an email address."; exit;
        }
        $qrData = "MATMSG:TO:{$to};SUB:{$sub};BODY:{$body};;";
    }
    
    $url = $qrData; // Alias for backward compatibility with render functions

    $mode         = $_POST['mode'] ?? $_GET['mode'] ?? 'designer';
    $size         = (int)($_POST['size'] ?? $_GET['size'] ?? 1200);
    $size         = max(400, min($size, 3000));  // clamp
    $transparent  = !empty($_POST['transparent']);
    $centerShape  = $_POST['centershape'] ?? 'circle';
    if (!in_array($centerShape, ['circle', 'square', 'none'])) $centerShape = 'circle';
    $centerBorder = (int)($_POST['centerborder'] ?? 4);
    $centerBorder = max(0, min($centerBorder, 10));
    $centerSize   = (int)($_POST['centersize'] ?? 14);
    $centerSize   = max(0, min($centerSize, 25));
    $format       = $_POST['format'] ?? $_GET['format'] ?? 'png';

    $fgHex = preg_replace('/[^0-9a-fA-F]/', '', $_POST['fg'] ?? $_GET['fg'] ?? '000000') ?: '000000';
    $bgHex = preg_replace('/[^0-9a-fA-F]/', '', $_POST['bg'] ?? $_GET['bg'] ?? 'FFFFFF') ?: 'FFFFFF';
    $fg2Hex = preg_replace('/[^0-9a-fA-F]/', '', $_POST['fg2'] ?? $_GET['fg2'] ?? '');

    if (strlen($fgHex) !== 6) $fgHex = '000000';
    if (strlen($bgHex) !== 6) $bgHex = 'FFFFFF';
    
    $fg2Arr = null;
    if (strlen($fg2Hex) === 6) {
        $fg2Arr = [hexdec(substr($fg2Hex,0,2)), hexdec(substr($fg2Hex,2,2)), hexdec(substr($fg2Hex,4,2))];
    }
    
    [$fR,$fG,$fB] = sscanf($fgHex, "%02x%02x%02x");
    [$bR,$bG,$bB] = sscanf($bgHex, "%02x%02x%02x");

    if ($mode === 'designer') {
        $logoPath = null;
        if (!empty($_FILES['photo_designer']['name'])) {
            $err = validate_upload($_FILES['photo_designer'], $MAX_UPLOAD_BYTES, $MAX_DIMENSION);
            if ($err) { http_response_code(400); header('Content-Type:text/plain'); echo $err; exit; }
            $logoPath = $_FILES['photo_designer']['tmp_name'];
        }
        try {
            $halftonePath = (!empty($_FILES['photo_halftone']['name'])) ? $_FILES['photo_halftone']['tmp_name'] : null;
            if ($mode === 'halftone' && $halftonePath) {
                $png = render_halftone_qr($url, $halftonePath, $size);
            } else {
                $png = render_designer_qr($url, $logoPath, [$fR,$fG,$fB], [$bR,$bG,$bB], $size, $transparent, $centerShape, $centerBorder, $centerSize, $fg2Arr);
            }
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
        if (empty($_FILES['photo_halftone']['name'])) {
            http_response_code(400);
            header('Content-Type:text/plain');
            echo "Please upload a photograph for halftone mode.";
            exit;
        }
        $err = validate_upload($_FILES['photo_halftone'], $MAX_UPLOAD_BYTES, $MAX_DIMENSION);
        if ($err) { http_response_code(400); header('Content-Type:text/plain'); echo $err; exit; }
        try {
            $png = render_halftone_qr($url, $_FILES['photo_halftone']['tmp_name'], $size);
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
        $writer = ($format === 'svg') ? new SvgWriter() : new PngWriter();
        $args = [
            'writer' => $writer, 'writerOptions' => [], 'validateResult' => false,
            'data' => $url, 'encoding' => new Encoding('UTF-8'),
            'errorCorrectionLevel' => ErrorCorrectionLevel::High,
            'size' => $size, 'margin' => 20,
            'roundBlockSizeMode' => RoundBlockSizeMode::Margin,
            'foregroundColor' => new Color($fR, $fG, $fB),
            'backgroundColor' => new Color($bR, $bG, $bB),
        ];
        $builder = Builder::create();
        foreach ($args as $k => $v) {
            if (method_exists($builder, $k)) {
                $builder->$k($v);
            }
        }
        $res = $builder->build();
        $output = $res->getString();
        
        if ($format === 'svg') {
            header('Content-Type: image/svg+xml');
            header('Content-Disposition: inline; filename="qrcode.svg"');
        } else {
            header('Content-Type: image/png');
            header('Content-Disposition: inline; filename="qrcode.png"');
        }
        
        echo $output; exit;
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
<?php
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['REQUEST_URI']);
    $ogImageUrl = rtrim($baseUrl, '/') . '/og-image.png';
?>
<meta property="og:title" content="<?= APP_NAME ?> — <?= APP_TAGLINE ?>">
<meta property="og:description" content="Free QR code generator. Your URL goes directly in the QR — no redirects, no tracking, no expiry. Forever.">
<meta property="og:image" content="<?= $ogImageUrl ?>">
<meta property="og:type" content="website">
<meta name="twitter:card" content="summary_large_image">
<title><?= APP_NAME ?> — <?= APP_TAGLINE ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --pink: #E84C89; --pink-dark: #d23a77;
    --navy: #092370; --text: #1a1a1a; --muted: #666;
    --bg-mesh: radial-gradient(at 40% 20%, hsla(28,100%,74%,0.15) 0px, transparent 50%),
               radial-gradient(at 80% 0%, hsla(189,100%,56%,0.15) 0px, transparent 50%),
               radial-gradient(at 0% 50%, hsla(355,100%,93%,0.15) 0px, transparent 50%),
               radial-gradient(at 80% 50%, hsla(340,100%,76%,0.15) 0px, transparent 50%),
               radial-gradient(at 0% 100%, hsla(22,100%,77%,0.15) 0px, transparent 50%),
               radial-gradient(at 80% 100%, hsla(242,100%,70%,0.15) 0px, transparent 50%),
               radial-gradient(at 0% 0%, hsla(343,100%,76%,0.15) 0px, transparent 50%);
    --bg: #f8f9fa; --card: rgba(255, 255, 255, 0.75); --card-solid: #ffffff;
    --border: rgba(0, 0, 0, 0.08); --border-hover: rgba(0, 0, 0, 0.2);
    --focus: #E84C89; --shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.07);
    --glass-blur: blur(12px);
  }
  @media (prefers-color-scheme: dark) {
    :root { 
      --text: #f0f0f0; --muted: #aaa; 
      --bg: #0f1115; 
      --bg-mesh: radial-gradient(at 40% 20%, hsla(28,100%,74%,0.08) 0px, transparent 50%),
                 radial-gradient(at 80% 0%, hsla(189,100%,56%,0.08) 0px, transparent 50%),
                 radial-gradient(at 0% 50%, hsla(355,100%,93%,0.08) 0px, transparent 50%),
                 radial-gradient(at 80% 50%, hsla(340,100%,76%,0.08) 0px, transparent 50%),
                 radial-gradient(at 0% 100%, hsla(22,100%,77%,0.08) 0px, transparent 50%),
                 radial-gradient(at 80% 100%, hsla(242,100%,70%,0.08) 0px, transparent 50%),
                 radial-gradient(at 0% 0%, hsla(343,100%,76%,0.08) 0px, transparent 50%);
      --card: rgba(30, 32, 40, 0.6); --card-solid: #1e2028;
      --border: rgba(255, 255, 255, 0.08); --border-hover: rgba(255, 255, 255, 0.2);
      --shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
    }
  }
  * { box-sizing: border-box; }
  body {
    font-family: 'Outfit', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    line-height: 1.6; color: var(--text); background-color: var(--bg);
    background-image: var(--bg-mesh); background-attachment: fixed;
    margin: 0; padding: 1.5rem; min-height: 100vh;
  }
  .container { max-width: 1100px; margin: 0 auto; }
  header { text-align: center; padding: 1rem 0 2.5rem; }
  h1 { font-size: clamp(2rem, 5vw, 3rem); font-weight: 700; margin: 0 0 .5rem; letter-spacing: -0.02em; }
  h1 span { color: var(--pink); }
  .tagline { color: var(--muted); margin: 0; font-size: 1.15rem; font-weight: 300; }
  
  .main-wrapper {
    display: grid; grid-template-columns: 1.2fr 1fr; gap: 2rem; align-items: start;
  }
  @media (max-width: 850px) { .main-wrapper { grid-template-columns: 1fr; } }

  .glass-panel {
    background: var(--card); border: 1px solid var(--border); 
    border-radius: 16px; box-shadow: var(--shadow);
    backdrop-filter: var(--glass-blur); -webkit-backdrop-filter: var(--glass-blur);
    padding: 1.5rem;
  }

  .info-sections { display: grid; gap: 1rem; margin-bottom: 1.5rem; }
  details { 
    background: var(--card); border: 1px solid var(--border); border-radius: 12px;
    padding: 1rem 1.25rem; font-size: .95rem;
    backdrop-filter: var(--glass-blur); -webkit-backdrop-filter: var(--glass-blur);
  }
  details summary { font-weight: 600; cursor: pointer; padding: .25rem 0; outline-offset: 3px; }
  details[open] summary { margin-bottom: .5rem; color: var(--pink); }
  details ul { margin: .5rem 0 0; padding-left: 1.2rem; }
  details li { margin: .3rem 0; }
  details li::marker { content: "✓ "; color: var(--pink); font-weight: bold; }
  details ol { padding-left: 1.2rem; margin: .5rem 0; }
  details li { margin: .35rem 0; }

  form { display: grid; gap: 1.25rem; }
  label { display: grid; gap: .4rem; font-weight: 500; font-size: .95rem; }
  .hint { font-size: .85rem; color: var(--muted); font-weight: 300; }
  
  input[type=text], input[type=url], input[type=email], input[type=tel], input[type=number], input[type=file], select, textarea {
    padding: .75rem 1rem; border: 1px solid var(--border); border-radius: 8px;
    font-size: 1rem; background: var(--card-solid); color: var(--text); font-family: inherit;
    width: 100%; min-width: 0; transition: border-color 0.2s, box-shadow 0.2s;
  }
  textarea { resize: vertical; min-height: 80px; }
  
  input:focus, select:focus, textarea:focus, button:focus {
    outline: none; border-color: var(--focus); box-shadow: 0 0 0 3px rgba(232, 76, 137, 0.15);
  }

  .tabs { display: flex; gap: .5rem; border-bottom: 1px solid var(--border); padding-bottom: 1rem; flex-wrap: wrap; }
  .tab-btn {
    background: transparent; border: 1px solid var(--border); border-radius: 20px;
    padding: .5rem 1rem; cursor: pointer; font-family: inherit; font-size: .9rem;
    font-weight: 500; color: var(--muted); transition: all 0.2s;
  }
  .tab-btn.active { background: var(--pink); border-color: var(--pink); color: white; }
  .tab-pane { display: none; }
  .tab-pane.active { display: grid; gap: 1.25rem; }

  input[type=color] {
    width: 50px; height: 46px; border: 1px solid var(--border);
    border-radius: 8px; padding: 2px; cursor: pointer; flex-shrink: 0;
    background: var(--card-solid);
  }
  
  input[type=range] { width: 100%; touch-action: pan-y; accent-color: var(--pink); }
  
  .checkbox-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    font-size: 0.95rem;
    user-select: none;
  }
  .checkbox-row input {
    margin: 0;
    width: 1.2rem;
    height: 1.2rem;
    accent-color: var(--pink);
  }
  .color-picker-group {
    display: flex;
    gap: 0.75rem;
    align-items: center;
  }
  
  .row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  @media (max-width: 480px) { .row { grid-template-columns: 1fr; } }
  .colors { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  @media (max-width: 480px) { .colors { grid-template-columns: 1fr; } }
  
  .preset { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .4rem; }
  .preset button {
    border: 1px solid var(--border); background: var(--card-solid); padding: .5rem .8rem;
    border-radius: 8px; cursor: pointer; font-size: .85rem; color: var(--text);
    display: inline-flex; align-items: center; gap: .4rem; transition: border-color 0.2s, transform 0.1s;
    min-height: 44px; font-weight: 500;
  }
  .preset button:hover { border-color: var(--pink); transform: translateY(-1px); }
  .preset button span.swatch {
    display: inline-block; width: 16px; height: 16px; border-radius: 4px;
    border: 1px solid rgba(0,0,0,.15); box-shadow: inset 0 1px 2px rgba(255,255,255,0.2);
  }
  
  fieldset {
    border: 1px solid var(--border); border-radius: 12px; padding: 1.25rem;
    display: grid; gap: 1.25rem; margin: 0; background: rgba(255,255,255,0.02);
  }
  legend { padding: 0 .5rem; font-weight: 600; color: var(--pink); }
  
  button.go {
    background: linear-gradient(135deg, var(--pink), #ff6b6b); color: white; border: 0; padding: 1rem 1.5rem;
    border-radius: 10px; font-size: 1.1rem; cursor: pointer; font-weight: 600;
    transition: all 0.2s; width: 100%; box-shadow: 0 4px 15px rgba(232, 76, 137, 0.3);
    display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
  }
  button.go:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(232, 76, 137, 0.4); }
  button.go:active { transform: translateY(0); }
  
  .spinner {
    display: inline-block; width: 20px; height: 20px;
    border: 3px solid rgba(255,255,255,.3);
    border-top-color: #fff; border-radius: 50%;
    animation: spin .7s linear infinite;
  }
  @keyframes spin { to { transform: rotate(360deg); } }
  
  .preview {
    text-align: center;
    display: flex; flex-direction: column; align-items: center;
  }
  .preview-placeholder {
    color: var(--muted); font-size: 1.1rem; padding: 3rem 1rem;
    border: 2px dashed var(--border); border-radius: 12px; width: 100%;
  }
  .preview img {
    width: 100%; max-width: 450px; height: auto; border-radius: 10px;
    background: repeating-conic-gradient(#f0f0f0 0% 25%, #ffffff 0% 50%) 0 / 20px 20px;
  }
  
  .preview-error {
    background: #fdeaea; border: 1px solid #e74c3c; border-radius: 8px;
    padding: 1rem; color: #c0392b; font-size: .95rem; margin-top: 1rem; width: 100%;
  }
  
  .preview-actions {
    display: flex; gap: .75rem; justify-content: center; flex-wrap: wrap;
    margin-top: 1.5rem; width: 100%;
  }
  .preview-actions a, .preview-actions button {
    display: inline-flex; align-items: center; gap: .5rem;
    padding: .75rem 1.5rem; border-radius: 10px; font-size: 1rem;
    font-weight: 600; cursor: pointer; text-decoration: none;
    transition: all 0.2s; min-height: 48px;
  }
  .btn-download { background: var(--pink); color: #fff; border: 2px solid var(--pink); }
  .btn-download:hover { background: var(--pink-dark); border-color: var(--pink-dark); transform: translateY(-1px); }
  .btn-share { background: var(--card-solid); color: var(--text); border: 2px solid var(--border); }
  .btn-share:hover { border-color: var(--pink); color: var(--pink); transform: translateY(-1px); }
  
  .hidden { display: none !important; }
  footer {
    text-align: center; font-size: 0.9rem; color: var(--muted); padding: 2rem 1rem;
    margin-top: 3rem; border-top: 1px solid var(--border);
  }
  footer a { color: var(--pink); text-decoration: none; font-weight: 500; }
  footer a:hover { text-decoration: underline; }
  
  @media (max-width: 400px) {
    body { padding: 1rem; }
    .glass-panel { padding: 1.25rem; }
    .preview-actions a, .preview-actions button { padding: .7rem 1rem; font-size: .9rem; width: 100%; justify-content: center;}
  }
  footer {
    text-align: center; padding: 2rem 1rem; color: var(--muted); font-size: .9rem;
    margin-top: 3rem; border-top: 1px solid var(--border);
  }
</style>
</head>
<body>
<div class="container">

<header>
  <h1>Direct<span>QR</span></h1>
  <p class="tagline">Your URL → QR. Nothing in between.</p>
</header>

<div class="main-wrapper">
  
  <div class="left-col">
    <div class="info-sections">
      <details>
        <summary>What makes <?= APP_NAME ?> different?</summary>
        <ul>
          <li>Your URL is encoded <em>directly</em> in the QR — no redirect service in the middle</li>
          <li>No tracking, no analytics, no cookies</li>
          <li>No "free for the first 100 scans, then pay us" trap</li>
          <li>Works forever as long as your website does</li>
          <li>Nothing is saved on our server — your data is processed in memory and discarded</li>
        </ul>
      </details>

      <details>
        <summary>How to use it (30 seconds)</summary>
        <ol>
          <li><strong>Select a type</strong> — link, Wi-Fi, contact, text, or email</li>
          <li><strong>Fill in your details</strong></li>
          <li><strong>Pick a style</strong> — Designer for a stylish dotted QR with a logo, Plain for a classic square QR, or Halftone for a photograph QR</li>
          <li><strong>Choose colours</strong> and upload a logo/photo if needed</li>
          <li><strong>Click Generate</strong>, then tap the <strong>Download</strong> button</li>
        </ol>
        <p><strong>Important:</strong> always scan your finished QR with a real phone (or two) before printing it. Decorative styles are scannable but slightly less robust than plain QRs.</p>
      </details>
    </div>

    <form id="qr-form" class="glass-panel" method="post" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="generate" value="1">
      <input type="hidden" name="content_type" id="content_type" value="url" autocomplete="off">

      <div class="tabs" role="tablist">
        <button type="button" class="tab-btn active" data-target="pane-url">🔗 Link</button>
        <button type="button" class="tab-btn" data-target="pane-wifi">📶 Wi-Fi</button>
        <button type="button" class="tab-btn" data-target="pane-vcard">👤 Contact</button>
        <button type="button" class="tab-btn" data-target="pane-text">📝 Text</button>
        <button type="button" class="tab-btn" data-target="pane-email">✉️ Email</button>
      </div>

      <div id="pane-url" class="tab-pane active">
        <label for="url">Website URL <input type="url" id="url" name="url" placeholder="https://example.com"></label>
      </div>

      <div id="pane-wifi" class="tab-pane">
        <div class="row">
          <label for="wifi-ssid">SSID <input type="text" id="wifi-ssid" name="wifi_ssid"></label>
          <label for="wifi-pass">Pass <input type="text" id="wifi-pass" name="wifi_pass"></label>
        </div>
      </div>

      <div id="pane-vcard" class="tab-pane">
        <div class="row">
          <label for="vc-fname">First Name <input type="text" id="vc-fname" name="vc_fname"></label>
          <label for="vc-lname">Last Name <input type="text" id="vc-lname" name="vc_lname"></label>
        </div>
        <div class="row">
          <label for="vc-org">Company <input type="text" id="vc-org" name="vc_org"></label>
          <label for="vc-title">Job Title <input type="text" id="vc-title" name="vc_title"></label>
        </div>
        <div class="row">
          <label for="vc-tel">Phone <input type="tel" id="vc-tel" name="vc_tel"></label>
          <label for="vc-email">Email <input type="email" id="vc-email" name="vc_email"></label>
        </div>
        <label for="vc-url">Website <input type="url" id="vc-url" name="vc_url" placeholder="https://"></label>
      </div>

      <div id="pane-text" class="tab-pane">
        <label for="text-content">
          Message or Serial Number
          <textarea id="text-content" name="text_content" placeholder="Enter any text here..."></textarea>
        </label>
      </div>

      <div id="pane-email" class="tab-pane">
        <label for="email-to">Send To <input type="email" id="email-to" name="email_to" placeholder="hello@example.com"></label>
        <label for="email-sub">Subject <input type="text" id="email-sub" name="email_sub"></label>
        <label for="email-body">Body <textarea id="email-body" name="email_body"></textarea></label>
      </div>
      
      <label for="mode">Style
        <select name="mode" id="mode">
          <option value="designer" selected>✨ Designer</option>
          <option value="plain">Plain</option>
          <option value="halftone">Halftone</option>
        </select>
      </label>

      <fieldset>
        <legend>Colours</legend>
        <div class="row">
          <label for="fg">Foreground
            <div class="color-picker-group">
              <input type="color" id="fg" name="fg" value="#092370">
              <input type="text" id="fg-text" value="#092370" pattern="^#[0-9A-Fa-f]{6}$">
            </div>
            <label class="checkbox-row" style="margin-top: 0.5rem;">
              <input type="checkbox" id="enable-gradient"> Enable gradient (Designer mode)
            </label>
          </label>
          <label for="bg">Background
            <div class="color-picker-group">
              <input type="color" id="bg" name="bg" value="#FFFFFF">
              <input type="text" id="bg-text" value="#FFFFFF" pattern="^#[0-9A-Fa-f]{6}$">
            </div>
            <label class="checkbox-row" style="margin-top: 0.5rem;">
              <input type="checkbox" name="transparent" id="transparent" value="1">
              Transparent bg
            </label>
          </label>
        </div>

        <div id="gradient-group" class="row hidden">
          <label for="fg2">Gradient End
            <div class="color-picker-group">
              <input type="color" id="fg2" name="fg2" value="#d82b6b" disabled>
              <input type="text" id="fg2-text" value="#d82b6b" disabled>
            </div>
          </label>
          <div></div>
        </div>

        <div>
          <span class="hint">Quick presets:</span>
          <div class="preset" role="group" aria-label="Colour presets">
            <button type="button" class="preset-btn" data-fg="#000000" data-bg="#FFFFFF"><span class="swatch" style="background:#000"></span>Classic</button>
            <button type="button" class="preset-btn" data-fg="#E84C89" data-bg="#FFFFFF"><span class="swatch" style="background:#E84C89"></span>Pink</button>
            <button type="button" class="preset-btn" data-fg="#092370" data-bg="#FFFFFF"><span class="swatch" style="background:#092370"></span>Navy</button>
            <button type="button" class="preset-btn" data-fg="#4A2C4E" data-bg="#FFFFFF"><span class="swatch" style="background:#4A2C4E"></span>Plum</button>
            <button type="button" class="preset-btn" data-fg="#1B4332" data-bg="#FFFFFF"><span class="swatch" style="background:#1B4332"></span>Forest</button>
          </div>
          <span class="hint" style="margin-top:.5rem;display:block">Keep good contrast between foreground and background, or scanners will struggle.</span>
        </div>
      </fieldset>

      <fieldset id="designer-options">
        <legend>Designer</legend>

        <label for="centershape">Centre shape
          <select name="centershape" id="centershape" onchange="
            var hide = this.value === 'none';
            document.getElementById('centersize-label').style.display = hide ? 'none' : 'grid';
            document.getElementById('centerborder-label').style.display = hide ? 'none' : 'grid';
          ">
            <option value="circle" selected>Circle</option>
            <option value="square">Rounded square</option>
            <option value="none">None — logo only, no shape</option>
          </select>
        </label>

        <label for="centersize" id="centersize-label">
          Centre size: <span id="centersize-value">14</span>%
          <input type="range" name="centersize" id="centersize" min="8" max="22" value="14"
                 oninput="document.getElementById('centersize-value').textContent = this.value">
        </label>

        <label for="centerborder" id="centerborder-label">
          Border width: <span id="centerborder-value">4</span>
          <input type="range" name="centerborder" id="centerborder" min="0" max="10" value="4"
                 oninput="document.getElementById('centerborder-value').textContent = this.value">
        </label>

        <label for="photo-designer" style="margin-top:0.5rem">Logo <input type="file" name="photo_designer" id="photo-designer"></label>
      </fieldset>

      <fieldset id="halftone-options" class="hidden">
        <legend>Halftone</legend>
        <label for="photo-halftone">Photo <input type="file" name="photo_halftone" id="photo-halftone"></label>
      </fieldset>

      <details style="border: 1px solid var(--border); border-radius: 12px; padding: 1.25rem;">
        <summary style="font-weight: 600; cursor: pointer;">Advanced Settings</summary>
        <div class="row" style="margin-top: 1rem;">
          <label for="size">Size (px) <input type="number" name="size" id="size" value="1200" step="100"></label>
          <label for="format">Format
            <select name="format" id="format">
              <option value="png">PNG (Raster)</option>
              <option value="svg">SVG (Vector - Plain style only)</option>
            </select>
          </label>
        </div>
      </details>

      <button type="submit" class="go" id="generate-btn" style="margin-top:0.5rem">
        <span id="btn-label">Generate QR code →</span>
      </button>
    </form>
  </div>

  <div class="right-col">
    <div class="preview-wrapper">
      <div class="preview glass-panel" id="preview" aria-live="polite">
        <div id="preview-placeholder" class="preview-placeholder">
          <div style="font-size:3rem;margin-bottom:.5rem;opacity:0.5">🪄</div>
          Live Preview is active.<br><span style="font-size:0.9rem">Start typing or tweaking settings!</span>
        </div>
        <div class="preview-status hidden" id="preview-status"></div>
        <img id="qr-img" alt="Generated QR code" style="display:none">
        <div id="preview-error" class="preview-error hidden"></div>
        <div class="preview-actions" id="preview-actions" style="display:none">
          <a id="download-btn" class="btn-download" download="qrcode.png" href="#">⬇ Download</a>
          <button type="button" id="share-btn" class="btn-share">📤 Share</button>
        </div>
        <div class="download-hint hidden" id="dl-hint" style="margin-top:1rem; font-size:0.9rem">
          <strong>Before printing:</strong> Always test-scan with at least 2 different phones to ensure reliability.
        </div>
      </div>
    </div>
  </div>
</div>

</div>

<footer>
  <?= APP_NAME ?> is free, open-source, and ad-free. Built with care.<br>
  Self-host it on your own server — your data, your control. <a href="https://github.com/demiswc/directqr" target="_blank" rel="noopener">View source &amp; installation guide on GitHub</a>.
</footer>

<script>
const tabBtns = document.querySelectorAll('.tab-btn');
const tabPanes = document.querySelectorAll('.tab-pane');
const typeInput = document.getElementById('content_type');
const mode = document.getElementById('mode');
const fg = document.getElementById('fg'), fgText = document.getElementById('fg-text');
const bg = document.getElementById('bg'), bgText = document.getElementById('bg-text');
const enableGradient = document.getElementById('enable-gradient');
const gradientGroup = document.getElementById('gradient-group');
const fg2 = document.getElementById('fg2'), fg2Text = document.getElementById('fg2-text');
const designerOptions = document.getElementById('designer-options');
const halftoneOptions = document.getElementById('halftone-options');
const formatSelect = document.getElementById('format');

tabBtns.forEach(btn => {
  btn.addEventListener('click', () => {
    tabBtns.forEach(b => b.classList.remove('active'));
    tabPanes.forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(btn.getAttribute('data-target')).classList.add('active');
    typeInput.value = btn.getAttribute('data-target').replace('pane-', '');
    generateQR();
  });
});

const syncColors = (picker, text) => {
  picker.addEventListener('input', () => text.value = picker.value.toUpperCase());
  text.addEventListener('input', () => {
    if (/^#[0-9A-Fa-f]{6}$/.test(text.value)) picker.value = text.value;
  });
};
syncColors(fg, fgText); syncColors(bg, bgText); syncColors(fg2, fg2Text);

// Presets
document.querySelectorAll('.preset-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    fg.value = btn.dataset.fg;
    fgText.value = btn.dataset.fg;
    bg.value = btn.dataset.bg;
    bgText.value = btn.dataset.bg;
    
    // Auto-set a nice gradient if enabled
    if (enableGradient.checked) {
      if (btn.dataset.fg === '#000000') fg2.value = '#555555';
      else if (btn.dataset.fg === '#E84C89') fg2.value = '#8a2387';
      else if (btn.dataset.fg === '#092370') fg2.value = '#00c6ff';
      else if (btn.dataset.fg === '#4A2C4E') fg2.value = '#e96443';
      else if (btn.dataset.fg === '#1B4332') fg2.value = '#90d5ec';
      fg2Text.value = fg2.value.toUpperCase();
    }
    generateQR();
  });
});

enableGradient.addEventListener('change', () => {
  gradientGroup.classList.toggle('hidden', !enableGradient.checked);
  fg2.disabled = !enableGradient.checked;
  fg2Text.disabled = !enableGradient.checked;
});

mode.addEventListener('change', () => {
  designerOptions.classList.add('hidden');
  halftoneOptions.classList.add('hidden');
  
  if (mode.value !== 'designer') {
    enableGradient.disabled = true;
    enableGradient.parentElement.style.opacity = '0.5';
  } else {
    enableGradient.disabled = false;
    enableGradient.parentElement.style.opacity = '1';
  }
  
  if (mode.value === 'plain') {
    formatSelect.disabled = false;
  } else {
    formatSelect.value = 'png';
    formatSelect.disabled = true;
  }
  if (mode.value === 'designer') designerOptions.classList.remove('hidden');
  else if (mode.value === 'halftone') halftoneOptions.classList.remove('hidden');
});

let currentBlobUrl = null;

async function generateQR() {
  const form = document.getElementById('qr-form');
  const btn       = document.getElementById('generate-btn');
  const btnLabel  = document.getElementById('btn-label');
  const status    = document.getElementById('preview-status');
  const img       = document.getElementById('qr-img');
  const errorEl   = document.getElementById('preview-error');
  const actions   = document.getElementById('preview-actions');
  const dlBtn     = document.getElementById('download-btn');
  const shareBtn  = document.getElementById('share-btn');
  const placeholder = document.getElementById('preview-placeholder');
  const dlHint    = document.getElementById('dl-hint');

  // Disable button + show spinner
  btn.disabled = true;
  btnLabel.innerHTML = '<span class="spinner"></span> Generating…';

  // Reset preview state
  placeholder.classList.add('hidden');
  img.style.display = 'none';
  actions.style.display = 'none';
  dlHint.classList.add('hidden');
  errorEl.classList.add('hidden');
  
  status.innerHTML = '<span class="spinner" style="border-color:var(--border);border-top-color:var(--pink)"></span> Generating...';
  status.classList.remove('hidden');

  // Revoke previous blob
  if (currentBlobUrl) {
    URL.revokeObjectURL(currentBlobUrl);
    currentBlobUrl = null;
  }

  try {
    const formData = new FormData(form);
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
    status.innerHTML = '<span class="checkmark">✓</span> Ready';
    dlHint.classList.remove('hidden');

    // Wire up download button
    dlBtn.href = currentBlobUrl;
    let outFormat = document.getElementById('format').value;
    if (document.getElementById('mode').value !== 'plain') outFormat = 'png';
    dlBtn.download = 'qrcode.' + outFormat;
    actions.style.display = 'flex';

    // Show share button if Web Share API is available
    if (navigator.canShare) {
      shareBtn.style.display = 'inline-flex';
      shareBtn.onclick = async () => {
        try {
          const file = new File([blob], 'qrcode.' + outFormat, { type: contentType });
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
    if (err.message.startsWith('Please provide')) {
      status.classList.add('hidden');
      img.style.display = 'none';
      placeholder.innerHTML = `<div style="font-size:3rem;margin-bottom:.5rem;opacity:0.5">🪄</div>${err.message}`;
      placeholder.classList.remove('hidden');
    } else {
      status.innerHTML = '<span style="color:#e74c3c;font-size:1.3rem">✗ </span> Generation failed';
      errorEl.textContent = err.message;
      errorEl.classList.remove('hidden');
    }
  } finally {
    btn.disabled = false;
    btnLabel.textContent = 'Generate QR code →';
  }
}

let timeoutId;
function debounceGenerate() {
  clearTimeout(timeoutId);
  timeoutId = setTimeout(() => generateQR(), 800);
}

const form = document.getElementById('qr-form');
form.addEventListener('submit', function(e) {
  e.preventDefault();
  generateQR();
  // Smooth scroll to preview on mobile only on manual submit
  if (window.innerWidth <= 850) {
    setTimeout(() => document.querySelector('.right-col').scrollIntoView({ behavior: 'smooth', block: 'start' }), 100);
  }
});

// Sync type on load in case of browser bfcache/reload
typeInput.value = document.querySelector('.tab-btn.active').getAttribute('data-target').replace('pane-', '');

// Attach Live Preview events
form.querySelectorAll('input[type="text"], input[type="url"], input[type="email"], input[type="number"], textarea').forEach(el => {
  el.addEventListener('input', debounceGenerate);
});
form.querySelectorAll('input[type="color"], input[type="checkbox"], input[type="file"], select, input[type="range"]').forEach(el => {
  el.addEventListener('change', generateQR);
});
</script>
</body>
</html>
