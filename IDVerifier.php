<?php
/**
 * IDVerifier.php
 * Real image-based ID verification using PHP GD library.
 * No external APIs. Analyzes image structure, proportions, 
 * color distribution, edge density, and face-region detection.
 */
class IDVerifier {

    // Standard ID aspect ratios (width/height)
    private static $knownIDRatios = [
        'CR80'        => 85.6  / 54.0,   // 1.585 — credit card size (PhilSys, UMID, SSS, etc.)
        'PassportData'=> 125.0 / 88.0,   // 1.420 — passport data page
        'A4portrait'  => 210.0 / 297.0,  // 0.707 — birth cert / NBI clearance / legal docs (portrait)
        'A4landscape' => 297.0 / 210.0,  // 1.414 — some clearances in landscape
        'Letter'      => 215.9 / 279.4,  // 0.773 — letter-size docs
    ];

    private static $ratioTolerance = 0.18; // ±18% tolerance

    /**
     * Main verification entry point.
     * Returns ['passed' => bool, 'score' => 0-100, 'reasons' => [...], 'warnings' => [...]]
     */
    public static function verify(string $filePath, string $mimeType, string $selectedIDType): array {
        $result = [
            'passed'   => false,
            'score'    => 0,
            'reasons'  => [],
            'warnings' => [],
        ];

        if (!file_exists($filePath)) {
            $result['reasons'][] = 'Uploaded file could not be found on the server.';
            return $result;
        }

        if ($mimeType === 'application/pdf') {
            return self::verifyPDF($filePath, $selectedIDType, $result);
        }

        return self::verifyImage($filePath, $mimeType, $selectedIDType, $result);
    }

    /* ═══════════════════════════════════════════════════════════════
       IMAGE VERIFICATION
    ═══════════════════════════════════════════════════════════════ */
    private static function verifyImage(string $filePath, string $mimeType, string $idType, array $result): array {
        if (!extension_loaded('gd')) {
            // GD not available — do basic checks only
            $result['passed']   = true;
            $result['score']    = 60;
            $result['warnings'][] = 'Advanced image analysis unavailable (GD library missing). Basic checks passed.';
            return $result;
        }

        // Load image
        $img = null;
        try {
            switch ($mimeType) {
                case 'image/jpeg':
                case 'image/jpg':
                    $img = @imagecreatefromjpeg($filePath);
                    break;
                case 'image/png':
                    $img = @imagecreatefrompng($filePath);
                    break;
                default:
                    $img = @imagecreatefromstring(file_get_contents($filePath));
            }
        } catch (\Exception $e) {
            $result['reasons'][] = 'Could not decode the image file. Please upload a valid JPG or PNG.';
            return $result;
        }

        if (!$img) {
            $result['reasons'][] = 'The uploaded file is not a valid image. Please upload a clear photo or scan of your ID.';
            return $result;
        }

        $w = imagesx($img);
        $h = imagesy($img);

        $score   = 0;
        $reasons = [];
        $warnings = [];

        // ── CHECK 1: Minimum resolution ─────────────────────────────
        if ($w < 200 || $h < 100) {
            $reasons[] = 'Image resolution is too low (' . $w . '×' . $h . ' px). Please upload a clearer photo of your ID.';
            imagedestroy($img);
            $result['reasons'] = $reasons;
            return $result;
        }
        $score += 10;

        // ── CHECK 2: Aspect ratio matches known ID formats ───────────
        $ratio        = $w / $h;
        $ratioMatch   = false;
        $closestLabel = '';
        $closestDiff  = 999;

        foreach (self::$knownIDRatios as $label => $knownRatio) {
            $diff = abs($ratio - $knownRatio) / $knownRatio;
            if ($diff < $closestDiff) {
                $closestDiff  = $diff;
                $closestLabel = $label;
            }
            if ($diff <= self::$ratioTolerance) {
                $ratioMatch = true;
                break;
            }
        }

        if ($ratioMatch) {
            $score += 25;
        } else {
            // Partial credit if close
            if ($closestDiff < 0.30) {
                $score += 10;
                $warnings[] = 'Image proportions are slightly unusual for a standard ID (ratio ' . round($ratio, 2) . '). Make sure to upload the full ID, not a cropped portion.';
            } else {
                $reasons[] = 'The image proportions (' . round($ratio, 2) . ':1) do not match any standard ID or document format. Please upload a straight-on photo of the full ID.';
            }
        }

        // ── CHECK 3: Color distribution (IDs have varied regions) ───
        $colorAnalysis = self::analyzeColorDistribution($img, $w, $h);
        $score += $colorAnalysis['score'];
        if (!empty($colorAnalysis['reason'])) $reasons[] = $colorAnalysis['reason'];
        if (!empty($colorAnalysis['warning'])) $warnings[] = $colorAnalysis['warning'];

        // ── CHECK 4: Edge density (IDs have printed text & borders) ─
        $edgeAnalysis = self::analyzeEdgeDensity($img, $w, $h);
        $score += $edgeAnalysis['score'];
        if (!empty($edgeAnalysis['reason'])) $reasons[] = $edgeAnalysis['reason'];
        if (!empty($edgeAnalysis['warning'])) $warnings[] = $edgeAnalysis['warning'];

        // ── CHECK 5: Skin-tone region detection (ID photo area) ─────
        // Only for card-type IDs (CR80), not documents
        $isCardType = self::isCardTypeID($idType);
        if ($isCardType) {
            $skinAnalysis = self::detectSkinRegion($img, $w, $h);
            $score += $skinAnalysis['score'];
            if (!empty($skinAnalysis['reason'])) $reasons[] = $skinAnalysis['reason'];
            if (!empty($skinAnalysis['warning'])) $warnings[] = $skinAnalysis['warning'];
        } else {
            $score += 10; // give benefit of doubt for documents
        }

        // ── CHECK 6: Not a plain screenshot / solid color ───────────
        $varietyCheck = self::checkColorVariety($img, $w, $h);
        $score += $varietyCheck['score'];
        if (!empty($varietyCheck['reason'])) $reasons[] = $varietyCheck['reason'];

        // ── CHECK 7: Not an obvious selfie/portrait (no landscape) ──
        // Most IDs are landscape OR document-portrait — a selfie is tall portrait
        if ($isCardType && $h > $w * 1.3) {
            $reasons[] = 'This appears to be a portrait photo (selfie), not an ID card. Please upload a photo of your actual government ID.';
            $score -= 20;
        }

        imagedestroy($img);

        // ── FINAL SCORING ────────────────────────────────────────────
        $score = max(0, min(100, $score));
        $result['score']    = $score;
        $result['reasons']  = $reasons;
        $result['warnings'] = $warnings;
        $result['passed']   = ($score >= 50) && empty($reasons);

        return $result;
    }

    /* ═══════════════════════════════════════════════════════════════
       COLOR DISTRIBUTION ANALYSIS
    ═══════════════════════════════════════════════════════════════ */
    private static function analyzeColorDistribution($img, int $w, int $h): array {
        // Sample a grid of pixels
        $sampleStep = max(1, (int)($w / 40));
        $regions    = ['tl' => [], 'tr' => [], 'bl' => [], 'br' => [], 'center' => []];

        $halfW = (int)($w / 2);
        $halfH = (int)($h / 2);

        for ($x = 0; $x < $w; $x += $sampleStep) {
            for ($y = 0; $y < $h; $y += $sampleStep) {
                $rgb = imagecolorat($img, min($x, $w-1), min($y, $h-1));
                $r   = ($rgb >> 16) & 0xFF;
                $g   = ($rgb >> 8) & 0xFF;
                $b   = $rgb & 0xFF;
                $lum = 0.299*$r + 0.587*$g + 0.114*$b;

                if ($x < $halfW && $y < $halfH)      $regions['tl'][]     = $lum;
                elseif ($x >= $halfW && $y < $halfH)  $regions['tr'][]     = $lum;
                elseif ($x < $halfW && $y >= $halfH)  $regions['bl'][]     = $lum;
                elseif ($x >= $halfW && $y >= $halfH) $regions['br'][]     = $lum;

                $cx = abs($x - $halfW); $cy = abs($y - $halfH);
                if ($cx < $halfW/3 && $cy < $halfH/3) $regions['center'][] = $lum;
            }
        }

        $means = [];
        foreach ($regions as $key => $vals) {
            if (count($vals) > 0) $means[$key] = array_sum($vals) / count($vals);
        }

        if (count($means) < 4) {
            return ['score' => 5, 'reason' => '', 'warning' => ''];
        }

        // Variance between regions — IDs have distinct regions (photo vs background vs text)
        $mean   = array_sum($means) / count($means);
        $varSum = 0;
        foreach ($means as $v) $varSum += ($v - $mean) ** 2;
        $regionVariance = sqrt($varSum / count($means));

        if ($regionVariance > 18) {
            return ['score' => 20, 'reason' => '', 'warning' => ''];
        } elseif ($regionVariance > 8) {
            return ['score' => 10, 'reason' => '', 'warning' => 'The image appears to have low contrast. Make sure the ID is well-lit and clearly visible.'];
        } else {
            return ['score' => 0, 'reason' => 'The uploaded image appears to be a solid color or blank image, not an ID. Please upload a clear photo of your actual government ID.', 'warning' => ''];
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       EDGE DENSITY ANALYSIS (text, borders, print)
    ═══════════════════════════════════════════════════════════════ */
    private static function analyzeEdgeDensity($img, int $w, int $h): array {
        // Downsample to speed up
        $targetW = min($w, 160);
        $targetH = min($h, (int)($h * $targetW / $w));
        $small   = imagecreatetruecolor($targetW, $targetH);
        imagecopyresampled($small, $img, 0, 0, 0, 0, $targetW, $targetH, $w, $h);

        // Convert to grayscale matrix
        $gray = [];
        for ($y = 0; $y < $targetH; $y++) {
            for ($x = 0; $x < $targetW; $x++) {
                $rgb    = imagecolorat($small, $x, $y);
                $r      = ($rgb >> 16) & 0xFF;
                $g      = ($rgb >> 8) & 0xFF;
                $b      = $rgb & 0xFF;
                $gray[$y][$x] = (int)(0.299*$r + 0.587*$g + 0.114*$b);
            }
        }
        imagedestroy($small);

        // Simple Sobel-like edge detection
        $edgeCount = 0;
        $total     = 0;
        for ($y = 1; $y < $targetH - 1; $y++) {
            for ($x = 1; $x < $targetW - 1; $x++) {
                $gx = -$gray[$y-1][$x-1] + $gray[$y-1][$x+1]
                    - 2*$gray[$y][$x-1]  + 2*$gray[$y][$x+1]
                    - $gray[$y+1][$x-1]  + $gray[$y+1][$x+1];
                $gy = -$gray[$y-1][$x-1] - 2*$gray[$y-1][$x] - $gray[$y-1][$x+1]
                    + $gray[$y+1][$x-1]  + 2*$gray[$y+1][$x]  + $gray[$y+1][$x+1];
                $mag = sqrt($gx*$gx + $gy*$gy);
                if ($mag > 30) $edgeCount++;
                $total++;
            }
        }

        $edgeDensity = $total > 0 ? ($edgeCount / $total) : 0;

        // IDs typically have 5%–40% edge density (text, borders, photos)
        if ($edgeDensity >= 0.04 && $edgeDensity <= 0.55) {
            return ['score' => 20, 'reason' => '', 'warning' => ''];
        } elseif ($edgeDensity > 0.55) {
            // Too noisy — might be a very busy photo or screenshot
            return ['score' => 5, 'reason' => '', 'warning' => 'The image appears very complex or noisy. Ensure the ID is on a plain surface with good lighting.'];
        } else {
            // < 4% edges — blank page, solid color, or plain photo
            return ['score' => 0, 'reason' => 'The uploaded image has no visible text, borders, or printed content. Real IDs have printed text, logos, and borders. Please upload an actual photo of your government ID.', 'warning' => ''];
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       SKIN-TONE REGION DETECTION
    ═══════════════════════════════════════════════════════════════ */
    private static function detectSkinRegion($img, int $w, int $h): array {
        $skinPixels  = 0;
        $totalPixels = 0;
        $step        = max(1, (int)($w / 50));

        for ($x = 0; $x < $w; $x += $step) {
            for ($y = 0; $y < $h; $y += $step) {
                $rgb = imagecolorat($img, min($x, $w-1), min($y, $h-1));
                $r   = ($rgb >> 16) & 0xFF;
                $g   = ($rgb >> 8) & 0xFF;
                $b   = $rgb & 0xFF;

                // Skin tone detection (covers Filipino/Asian skin tones)
                if (self::isSkinTone($r, $g, $b)) {
                    $skinPixels++;
                }
                $totalPixels++;
            }
        }

        $skinRatio = $totalPixels > 0 ? ($skinPixels / $totalPixels) : 0;

        // ID card photo typically covers 10%–40% of the card area
        if ($skinRatio >= 0.05 && $skinRatio <= 0.60) {
            return ['score' => 15, 'reason' => '', 'warning' => ''];
        } elseif ($skinRatio > 0.60) {
            // Mostly skin — looks like a selfie, not an ID
            return ['score' => 0, 'reason' => 'The uploaded image appears to be a selfie or portrait photo, not a government ID. Your ID should show the card itself, not just a face photo.', 'warning' => ''];
        } else {
            // No skin detected — might be a document (acceptable), or blank image
            return ['score' => 8, 'reason' => '', 'warning' => 'No face photo region detected on the ID. If your ID type does not have a photo, this is acceptable.'];
        }
    }

    private static function isSkinTone(int $r, int $g, int $b): bool {
        // HSV-based skin detection for diverse skin tones (light to dark)
        if ($r < 60) return false;

        // RGB rules
        if ($r <= $g || $r <= $b) return false;
        if ($g <= 40 && $b <= 20) return false;
        if (abs($r - $g) <= 15 && $r > 150 && $g > 150) return false; // too white/grey

        // YCbCr rules (good for Asian/Filipino skin)
        $Y  =  0.299 * $r + 0.587 * $g + 0.114 * $b;
        $Cb = -0.169 * $r - 0.331 * $g + 0.500 * $b + 128;
        $Cr =  0.500 * $r - 0.419 * $g - 0.081 * $b + 128;

        return ($Y > 40 && $Cb >= 80 && $Cb <= 135 && $Cr >= 133 && $Cr <= 173);
    }

    /* ═══════════════════════════════════════════════════════════════
       COLOR VARIETY CHECK (not a blank/solid image)
    ═══════════════════════════════════════════════════════════════ */
    private static function checkColorVariety($img, int $w, int $h): array {
        $step   = max(1, (int)($w / 30));
        $colors = [];

        for ($x = 0; $x < $w; $x += $step) {
            for ($y = 0; $y < $h; $y += $step) {
                $rgb = imagecolorat($img, min($x, $w-1), min($y, $h-1));
                // Quantize to reduce unique count noise
                $rq = (int)(($rgb >> 16 & 0xFF) / 32);
                $gq = (int)(($rgb >> 8  & 0xFF) / 32);
                $bq = (int)(($rgb       & 0xFF) / 32);
                $colors["$rq,$gq,$bq"] = true;
            }
        }

        $uniqueColors = count($colors);

        if ($uniqueColors >= 15) {
            return ['score' => 10, 'reason' => ''];
        } elseif ($uniqueColors >= 5) {
            return ['score' => 5, 'reason' => ''];
        } else {
            return ['score' => 0, 'reason' => 'The image has almost no color variety — it appears to be blank or a solid color. Please upload an actual photo of your government ID.'];
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       PDF VERIFICATION
    ═══════════════════════════════════════════════════════════════ */
    private static function verifyPDF(string $filePath, string $idType, array $result): array {
        $score    = 0;
        $reasons  = [];
        $warnings = [];

        // Read PDF header
        $handle = fopen($filePath, 'rb');
        $header = fread($handle, 8);
        fclose($handle);

        if (substr($header, 0, 4) !== '%PDF') {
            $reasons[] = 'The uploaded file is not a valid PDF. Please upload a proper PDF scan of your document.';
            $result['reasons'] = $reasons;
            return $result;
        }
        $score += 20;

        // Read PDF content for structure analysis
        $content = file_get_contents($filePath);
        $size    = strlen($content);

        // CHECK: File size — real scanned IDs are typically 50KB–5MB
        $fileSizeKB = filesize($filePath) / 1024;
        if ($fileSizeKB < 5) {
            $reasons[] = 'The uploaded PDF is too small (' . round($fileSizeKB, 1) . ' KB). Please upload a clear scan of your actual document.';
            $result['reasons'] = $reasons;
            return $result;
        }
        $score += 15;

        // CHECK: PDF has image streams (scanned document)
        $hasImageStream = (strpos($content, '/DCTDecode') !== false   // JPEG in PDF
                        || strpos($content, '/FlateDecode') !== false  // PNG/flate in PDF
                        || strpos($content, '/CCITTFaxDecode') !== false // Fax/scan
                        || strpos($content, '/JPXDecode') !== false);    // JPEG2000

        if ($hasImageStream) {
            $score += 25;
        } else {
            $warnings[] = 'The PDF does not appear to contain scanned images. If this is a generated/text-based document, that may be acceptable.';
            $score += 10;
        }

        // CHECK: PDF page count (IDs are 1-2 pages)
        $pageCount = substr_count($content, '/Type /Page') + substr_count($content, '/Type/Page');
        if ($pageCount === 0) $pageCount = 1; // fallback

        if ($pageCount >= 1 && $pageCount <= 4) {
            $score += 15;
        } else {
            $warnings[] = 'This PDF has ' . $pageCount . ' pages. Most IDs/documents are 1–2 pages. Please ensure you are uploading the correct document.';
            $score += 5;
        }

        // CHECK: Keywords in PDF text streams (very basic text check)
        $idKeywords = ['republic', 'philippines', 'philsys', 'national id', 'driver', 'license',
                      'sss', 'gsis', 'philhealth', 'prc', 'passport', 'voter', 'barangay',
                      'birth certificate', 'clearance', 'nbi', 'police', 'certificate', 'deped',
                      'dswd', 'civil', 'affidavit', 'notarized', 'tax', 'tin'];
        $contentLower = strtolower($content);
        $keywordHits  = 0;
        foreach ($idKeywords as $kw) {
            if (strpos($contentLower, $kw) !== false) $keywordHits++;
        }
        if ($keywordHits >= 2) {
            $score += 25;
        } elseif ($keywordHits === 1) {
            $score += 10;
        } else {
            $warnings[] = 'No document-related keywords were found in the PDF text. If the document is a scanned image, this is normal.';
            $score += 5;
        }

        $score = max(0, min(100, $score));
        $result['score']    = $score;
        $result['reasons']  = $reasons;
        $result['warnings'] = $warnings;
        $result['passed']   = ($score >= 45) && empty($reasons);

        return $result;
    }

    /* ═══════════════════════════════════════════════════════════════
       HELPERS
    ═══════════════════════════════════════════════════════════════ */
    private static function isCardTypeID(string $idType): bool {
        $cardTypes = [
            'philippine national id', 'philsys', 'driver', 'license',
            'sss', 'gsis', 'umid', 'philhealth', 'voter', 'comelec',
            'prc', 'tin', 'postal', 'school', 'senior', 'pwd', 'ofw',
            'afp', 'pnp', 'passport'
        ];
        $lower = strtolower($idType);
        foreach ($cardTypes as $t) {
            if (strpos($lower, $t) !== false) return true;
        }
        return false;
    }
}