<?php
session_start();

// Allow access if logged in as teacher OR student
if (!isset($_SESSION['user_id']) && !isset($_SESSION['student_id'])) {
    http_response_code(403);
    exit('Access denied.');
}

$file = isset($_GET['file']) ? $_GET['file'] : '';
$file = urldecode($file);
$file = ltrim($file, '/');
$file = str_replace(['../', './', '\\'], '/', $file);
$file = preg_replace('#/+#', '/', $file);
$filename = basename($file);
$doc_root = $_SERVER['DOCUMENT_ROOT'];

// Resolve actual file path
$file_path = null;
foreach ([
    $doc_root . '/' . $file,
    $doc_root . '/als/e-learning-web/' . $file,
    $doc_root . '/als/e-learning-web/uploads/learning_materials/' . $filename,
    $doc_root . '/als/e-learning-web/Uploads/learning_materials/' . $filename,
    $doc_root . '/' . str_replace('/Uploads/', '/uploads/', $file),
    $doc_root . '/' . str_replace('/uploads/', '/Uploads/', $file),
] as $loc) {
    if (file_exists($loc)) {
        $file_path = $loc;
        break;
    }
}

// ── THUMBNAIL REQUEST ─────────────────────────────────────────────────────────
if (isset($_GET['thumb']) && $_GET['thumb'] == 1) {

    // Cache thumbnail next to the PDF so we only render once
    $cache_dir  = sys_get_temp_dir() . '/pdf_thumbs';
    $cache_file = null;

    if ($file_path) {
        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0755, true);
        }
        $cache_file = $cache_dir . '/' . md5($file_path) . '_thumb.jpg';
    }

    // Serve from cache if fresh (mtime newer than PDF)
    if ($cache_file && file_exists($cache_file) && $file_path && filemtime($cache_file) >= filemtime($file_path)) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=86400');
        readfile($cache_file);
        exit;
    }

    // ── Attempt 1: ImageMagick PHP extension ──
    if ($file_path && extension_loaded('imagick')) {
        try {
            $im = new Imagick();
            $im->setResolution(150, 150);          // Higher DPI → sharper preview
            $im->readImage($file_path . '[0]');     // [0] = first page only
            $im->setImageColorspace(Imagick::COLORSPACE_SRGB);
            $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $im->setImageBackgroundColor('white');
            $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            $im->thumbnailImage(600, 0);            // 600px wide, auto height
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(85);
            $blob = $im->getImageBlob();
            $im->clear();

            if ($cache_file) file_put_contents($cache_file, $blob);

            header('Content-Type: image/jpeg');
            header('Cache-Control: public, max-age=86400');
            echo $blob;
            exit;
        } catch (Exception $e) {
            // fall through
        }
    }

    // ── Attempt 2: GhostScript CLI ──
    if ($file_path && function_exists('shell_exec')) {
        $gs = trim(shell_exec('which gs 2>/dev/null') ?? '');
        if ($gs) {
            $tmp_out = tempnam(sys_get_temp_dir(), 'pdfthumb_') . '.jpg';
            $cmd = escapeshellcmd($gs)
                 . ' -dNOPAUSE -dBATCH -dSAFER'
                 . ' -sDEVICE=jpeg'
                 . ' -r150'
                 . ' -dFirstPage=1 -dLastPage=1'
                 . ' -dJPEGQ=85'
                 . ' -sOutputFile=' . escapeshellarg($tmp_out)
                 . ' ' . escapeshellarg($file_path)
                 . ' 2>/dev/null';
            shell_exec($cmd);
            if (file_exists($tmp_out) && filesize($tmp_out) > 0) {
                // Resize to max 600px wide using GD
                $src = imagecreatefromjpeg($tmp_out);
                $sw  = imagesx($src);
                $sh  = imagesy($src);
                $tw  = min($sw, 600);
                $th  = (int)($sh * ($tw / $sw));
                $dst = imagecreatetruecolor($tw, $th);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);
                ob_start();
                imagejpeg($dst, null, 85);
                $blob = ob_get_clean();
                imagedestroy($src);
                imagedestroy($dst);
                unlink($tmp_out);

                if ($cache_file) file_put_contents($cache_file, $blob);

                header('Content-Type: image/jpeg');
                header('Cache-Control: public, max-age=86400');
                echo $blob;
                exit;
            }
            if (file_exists($tmp_out)) unlink($tmp_out);
        }
    }

    // ── Attempt 3: pdftoppm CLI (poppler-utils) ──
    if ($file_path && function_exists('shell_exec')) {
        $pdftoppm = trim(shell_exec('which pdftoppm 2>/dev/null') ?? '');
        if ($pdftoppm) {
            $tmp_base = tempnam(sys_get_temp_dir(), 'pdfppm_');
            $cmd = escapeshellcmd($pdftoppm)
                 . ' -jpeg -r 150 -f 1 -l 1'
                 . ' ' . escapeshellarg($file_path)
                 . ' ' . escapeshellarg($tmp_base)
                 . ' 2>/dev/null';
            shell_exec($cmd);
            // pdftoppm appends -000001.jpg (or similar)
            $matches = glob($tmp_base . '*.jpg') ?: glob($tmp_base . '-*.jpg') ?: [];
            if (!empty($matches) && filesize($matches[0]) > 0) {
                $src  = imagecreatefromjpeg($matches[0]);
                $sw   = imagesx($src);
                $sh   = imagesy($src);
                $tw   = min($sw, 600);
                $th   = (int)($sh * ($tw / $sw));
                $dst  = imagecreatetruecolor($tw, $th);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);
                ob_start();
                imagejpeg($dst, null, 85);
                $blob = ob_get_clean();
                imagedestroy($src);
                imagedestroy($dst);
                foreach ($matches as $f) unlink($f);
                @unlink($tmp_base);

                if ($cache_file) file_put_contents($cache_file, $blob);

                header('Content-Type: image/jpeg');
                header('Cache-Control: public, max-age=86400');
                echo $blob;
                exit;
            }
            foreach (glob($tmp_base . '*') ?: [] as $f) @unlink($f);
            @unlink($tmp_base);
        }
    }

    // ── Fallback: styled placeholder image via GD ──
    // Draws a clean PDF page preview placeholder with filename
    $w = 300; $h = 400;
    $img = imagecreatetruecolor($w, $h);

    // White page background
    $white  = imagecolorallocate($img, 255, 255, 255);
    $red    = imagecolorallocate($img, 220, 38, 38);
    $gray   = imagecolorallocate($img, 229, 231, 235);
    $dgray  = imagecolorallocate($img, 107, 114, 128);
    $lgray  = imagecolorallocate($img, 243, 244, 246);

    imagefilledrectangle($img, 0, 0, $w, $h, $white);

    // Page border
    imagerectangle($img, 0, 0, $w-1, $h-1, $gray);

    // Top red header bar
    imagefilledrectangle($img, 0, 0, $w, 70, $red);

    // "PDF" text
    imagestring($img, 5, 125, 25, 'PDF', $white);

    // Page lines (simulate text content)
    for ($i = 0; $i < 8; $i++) {
        $y = 100 + ($i * 30);
        $lw = ($i % 3 === 2) ? 160 : 240;
        imagefilledrectangle($img, 20, $y, 20 + $lw, $y + 10, $lgray);
    }

    // Short filename label at bottom
    $label = strlen($filename) > 28 ? substr($filename, 0, 25) . '...' : $filename;
    imagestring($img, 2, 10, $h - 24, $label, $dgray);

    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=3600');
    imagepng($img);
    imagedestroy($img);
    exit;
}

// ── SERVE PDF FILE ────────────────────────────────────────────────────────────
if (!$file_path) {
    http_response_code(404);
    exit('File not found.');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file_path);
finfo_close($finfo);

if ($mime !== 'application/pdf') {
    http_response_code(400);
    exit('Invalid file type.');
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($file_path));
header('Content-Disposition: inline; filename="' . basename($file_path) . '"');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($file_path);
exit;
?>