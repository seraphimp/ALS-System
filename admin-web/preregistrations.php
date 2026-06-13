<?php
// preregistrations.php — Smart Approval System (OCR DISABLED)
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0777, true);
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/dashboard_functions.php';

// ============================================================
// SMART APPROVAL SYSTEM — OCR DISABLED
// Uses simple image structure validation + form data checks
// Auto-approval based on basic conditions, manual review for others
// ============================================================

class SmartApprovalSystem {

    private $conn;
    private $security;
    private $uploadPath;
    private $uploadWebPath;
    private static $analysisTableChecked = false;

    // --- ID card standard aspect ratios ---------------------------------------
    private const ID_ASPECT_RATIOS = [
        ['ratio' => 1.586, 'name' => 'Standard ID / CR80'],
        ['ratio' => 1.417, 'name' => 'Philippine Passport inner page'],
        ['ratio' => 1.333, 'name' => '4:3 document scan'],
        ['ratio' => 0.707, 'name' => 'A4 portrait scan'],
        ['ratio' => 1.414, 'name' => 'A4 landscape scan'],
    ];

    private const ASPECT_TOLERANCE          = 0.10;

    // --- Skin tone HSV thresholds ---------------------------------------------
    private const SKIN_H_LOW1  =   0;
    private const SKIN_H_HIGH1 =  25;
    private const SKIN_H_LOW2  = 340;
    private const SKIN_H_HIGH2 = 360;
    private const SKIN_S_LOW   =  15;
    private const SKIN_S_HIGH  =  80;
    private const SKIN_V_LOW   =  40;
    private const SKIN_V_HIGH  = 100;

    // --- Quality thresholds ---------------------------------------------------
    private const MIN_SKIN_RATIO_SELFIE  = 0.08;
    private const MIN_SKIN_RATIO_ID_ZONE = 0.04;
    private const MAX_GLOBAL_SKIN_ID     = 0.35;
    private const MIN_EDGE_DENSITY_ID    = 0.09;
    private const MAX_BLUR_VARIANCE      = 120.0;
    private const MIN_BRIGHTNESS         =  40.0;
    private const MAX_BRIGHTNESS         = 230.0;
    private const MIN_CONTRAST           =  30.0;
    private const MIN_ID_MARGIN_BRIGHT   = 160.0;
    private const MIN_ID_SCORE           =  60;

    public function __construct($conn, $security) {
        $this->conn          = $conn;
        $this->security      = $security;
        $this->uploadPath    = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/uploads/preregistration/';
        $this->uploadWebPath = '/uploads/preregistration/';

        if (!is_dir($this->uploadPath)) {
            mkdir($this->uploadPath, 0777, true);
        }
    }

    // ========================================================================
    //  PUBLIC API
    // ========================================================================

    public function analyzePreregistration($preregId) {
        $stmt = $this->conn->prepare("
            SELECT p.*, b.name AS barangay_name
            FROM   preregistrations p
            LEFT JOIN barangays b ON p.current_barangay_id = b.barangay_id
            WHERE  p.preregistration_id = ?
        ");
        $stmt->bind_param("i", $preregId);
        $stmt->execute();
        $result = $stmt->get_result();
        $prereg = $result->fetch_assoc();
        $result->free_result();
        $stmt->close();

        if (!$prereg) return ['error' => 'Pre-registration not found'];

        $analysis = [
            'image_analysis'       => $this->analyzeImages($prereg),
            'data_validation'      => $this->validateData($prereg),
            'duplicate_check'      => $this->checkDuplicates($prereg),
            'fraud_detection'      => $this->detectFraud($prereg),
            'ocr_analysis'         => $this->runOCRAnalysis($prereg), // DISABLED
            'recommendation'       => null,
            'confidence_score'     => 0,
            'timestamp'            => date('Y-m-d H:i:s'),
            'engine'               => 'local_gd_only',
            'preregistration_data' => [
                'preregistration_id' => $prereg['preregistration_id'],
                'first_name'         => $prereg['first_name'],
                'last_name'          => $prereg['last_name'],
                'selfie_image'       => $prereg['selfie_image'],
                'valid_id_image'     => $prereg['valid_id_image'],
                'valid_id_back_image'=> $prereg['valid_id_back_image'] ?? null,
            ],
            'image_urls' => [
                'selfie'      => $prereg['selfie_image']
                    ? $this->uploadWebPath . $prereg['selfie_image'] : null,
                'valid_id'    => $prereg['valid_id_image']
                    ? $this->uploadWebPath . $prereg['valid_id_image'] : null,
                'valid_id_back' => !empty($prereg['valid_id_back_image'])
                    ? $this->uploadWebPath . $prereg['valid_id_back_image'] : null,
            ],
        ];

        $analysis['confidence_score'] = $this->calculateConfidenceScore($analysis);
        $analysis['recommendation']   = $this->generateRecommendation($analysis);
        $this->saveAnalysis($preregId, $analysis);

        return $analysis;
    }

    public function getAnalysis($preregId) {
        $this->ensureAnalysisTableExists();
        $stmt = $this->conn->prepare("
            SELECT analysis_data, confidence_score, recommendation, created_at, updated_at
            FROM   preregistration_analysis
            WHERE  preregistration_id = ?
        ");
        $stmt->bind_param("i", $preregId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row    = $result->fetch_assoc();
        $result->free_result();
        $stmt->close();

        if ($row && !empty($row['analysis_data'])) {
            $analysis = json_decode($row['analysis_data'], true);
            $analysis['saved_at']              = $row['created_at'];
            $analysis['stored_confidence']     = $row['confidence_score'];
            $analysis['stored_recommendation'] = $row['recommendation'];
            return $analysis;
        }
        return null;
    }

    // ========================================================================
    //  OCR ANALYSIS — DISABLED (returns empty result)
    // ========================================================================

    private function runOCRAnalysis(array $prereg): array {
        return [
            'enabled'        => false,
            'provider'       => 'DISABLED',
            'front_id' => [
                'status'          => 'disabled',
                'raw_text'        => '',
                'error'           => 'OCR is disabled - manual verification required',
                'name_match'      => null,
                'name_confidence' => 0,
                'name_detail'     => '',
                'dob_match'       => null,
                'dob_confidence'  => 0,
                'dob_detail'      => '',
                'notes'           => ['OCR functionality is turned off'],
            ],
            'back_id' => [
                'status'          => 'disabled',
                'raw_text'        => '',
                'error'           => 'OCR is disabled - manual verification required',
                'name_match'      => null,
                'name_confidence' => 0,
                'name_detail'     => '',
                'dob_match'       => null,
                'dob_confidence'  => 0,
                'dob_detail'      => '',
                'notes'           => ['OCR functionality is turned off'],
            ],
            'overall_name_match'  => null,
            'overall_dob_match'   => null,
            'overall_confidence'  => 0,
            'summary'             => 'OCR DISABLED - Manual verification required for all applications',
        ];
    }

    // ========================================================================
    //  IMAGE ANALYSIS — master dispatcher
    // ========================================================================

    private function analyzeImages($prereg) {
        $result = [
            'selfie' => [
                'status' => 'pending', 'issues' => [], 'quality' => 'unknown',
                'filename' => null, 'exists' => false, 'web_path' => null,
                'has_face' => null, 'brightness' => null, 'blur_score' => null,
                'contrast' => null, 'skin_ratio' => null, 'analysis_notes' => [],
            ],
            'valid_id' => [
                'status' => 'pending', 'issues' => [], 'quality' => 'unknown',
                'filename' => null, 'exists' => false, 'web_path' => null,
                'is_id_card' => null, 'id_type' => null,
                'name_match' => null, 'dob_match' => null,
                'has_photo_zone' => null, 'has_text_zones' => null,
                'edge_density' => null, 'aspect_ratio' => null,
                'global_skin_ratio' => null, 'margin_brightness' => null,
                'id_confidence' => 0, 'analysis_notes' => [],
            ],
            'valid_id_back' => [
                'status' => 'pending', 'issues' => [], 'quality' => 'unknown',
                'filename' => null, 'exists' => false, 'web_path' => null,
                'is_id_card' => null, 'edge_density' => null, 'aspect_ratio' => null,
                'id_confidence' => 0, 'analysis_notes' => [],
            ],
            'face_match' => [
                'status' => 'pending', 'confidence' => 0, 'issues' => [], 'notes' => '',
            ],
        ];

        // --- SELFIE -----------------------------------------------------------
        if (empty($prereg['selfie_image'])) {
            $result['selfie']['status']   = 'missing';
            $result['selfie']['issues'][] = 'No selfie uploaded';
        } else {
            $filename = basename($prereg['selfie_image']);
            $fullPath = $this->uploadPath . $filename;
            $result['selfie']['filename'] = $filename;
            $result['selfie']['web_path'] = $this->uploadWebPath . $filename;
            if (!file_exists($fullPath)) {
                $result['selfie']['status']   = 'missing';
                $result['selfie']['issues'][] = 'Selfie file not found on server';
            } else {
                $result['selfie']['exists'] = true;
                $result['selfie']['size']   = filesize($fullPath);
                $this->analyzeSelfie($fullPath, $result['selfie']);
            }
        }

        // --- VALID ID FRONT ---------------------------------------------------
        if (empty($prereg['valid_id_image'])) {
            $result['valid_id']['status']   = 'missing';
            $result['valid_id']['issues'][] = 'No front ID uploaded';
        } else {
            $filename = basename($prereg['valid_id_image']);
            $fullPath = $this->uploadPath . $filename;
            $result['valid_id']['filename'] = $filename;
            $result['valid_id']['web_path'] = $this->uploadWebPath . $filename;
            if (!file_exists($fullPath)) {
                $result['valid_id']['status']   = 'missing';
                $result['valid_id']['issues'][] = 'Front ID file not found on server';
            } else {
                $result['valid_id']['exists'] = true;
                $result['valid_id']['size']   = filesize($fullPath);
                $this->analyzeValidID($fullPath, $prereg, $result['valid_id']);
            }
        }

        // --- VALID ID BACK ----------------------------------------------------
        if (empty($prereg['valid_id_back_image'])) {
            $result['valid_id_back']['status'] = 'not_provided';
            $result['valid_id_back']['issues'] = [];
        } else {
            $filename = basename($prereg['valid_id_back_image']);
            $fullPath = $this->uploadPath . $filename;
            $result['valid_id_back']['filename'] = $filename;
            $result['valid_id_back']['web_path'] = $this->uploadWebPath . $filename;
            if (!file_exists($fullPath)) {
                $result['valid_id_back']['status']   = 'missing';
                $result['valid_id_back']['issues'][] = 'Back ID file not found on server';
            } else {
                $result['valid_id_back']['exists'] = true;
                $result['valid_id_back']['size']   = filesize($fullPath);
                $this->analyzeValidIDBack($fullPath, $result['valid_id_back']);
            }
        }

        // --- FACE MATCH -------------------------------------------------------
        if ($result['selfie']['status'] === 'present'
            && in_array($result['valid_id']['status'], ['present', 'acceptable'])) {
            $this->analyzeFaceMatch(
                $this->uploadPath . basename($prereg['selfie_image']),
                $this->uploadPath . basename($prereg['valid_id_image']),
                $result['face_match']
            );
        } else {
            $result['face_match']['status'] = 'skipped';
            $result['face_match']['notes']  = 'Face match skipped — one or both images unavailable or invalid';
        }

        return $result;
    }

    // ========================================================================
    //  SELFIE ANALYSIS
    // ========================================================================

    private function analyzeSelfie(string $path, array &$out): void {
        $img = $this->loadImage($path);
        if (!$img) { $out['status'] = 'error'; $out['issues'][] = 'Could not load selfie image'; return; }

        $w = imagesx($img); $h = imagesy($img);
        if ($w < 200 || $h < 200) {
            $out['status'] = 'poor_quality'; $out['quality'] = 'too_small';
            $out['issues'][] = "Selfie too small ({$w}x{$h} px)"; imagedestroy($img); return;
        }

        $brightness = $this->measureBrightness($img, $w, $h);
        $blur       = $this->measureBlur($img, $w, $h);
        $contrast   = $this->measureContrast($img, $w, $h);
        $out['brightness'] = round($brightness, 1);
        $out['blur_score'] = round($blur, 1);
        $out['contrast']   = round($contrast, 1);

        $qi = [];
        if ($brightness < self::MIN_BRIGHTNESS) $qi[] = 'Too dark';
        if ($brightness > self::MAX_BRIGHTNESS) $qi[] = 'Overexposed';
        if ($blur < self::MAX_BLUR_VARIANCE)    $qi[] = 'Blurry (sharpness: '.round($blur).')';
        if ($contrast < self::MIN_CONTRAST)     $qi[] = 'Poor contrast';

        $skinRatio = $this->measureSkinRatio($img, $w, $h);
        $out['skin_ratio'] = round($skinRatio, 3);
        $hasFace = ($skinRatio >= self::MIN_SKIN_RATIO_SELFIE);
        $out['has_face'] = $hasFace;
        if (!$hasFace) { $qi[] = 'No face detected'; }
        else { $out['analysis_notes'][] = 'Face detected — skin ratio: '.round($skinRatio*100,1).'%'; }

        if ($h < $w * 0.75) $qi[] = 'Landscape orientation — use portrait';
        $out['issues'] = array_merge($out['issues'], $qi);

        if (empty($qi) && $hasFace)          { $out['status'] = 'present'; $out['quality'] = 'good'; }
        elseif (count($qi) <= 1 && $hasFace) { $out['status'] = 'present'; $out['quality'] = 'acceptable'; }
        else                                  { $out['status'] = 'poor_quality'; $out['quality'] = 'poor'; }
        imagedestroy($img);
    }

    // ========================================================================
    //  VALID ID FRONT ANALYSIS
    // ========================================================================

    private function analyzeValidID(string $path, array $prereg, array &$out): void {
        $img = $this->loadImage($path);
        if (!$img) { $out['status'] = 'error'; $out['issues'][] = 'Could not load front ID image'; return; }

        $w = imagesx($img); $h = imagesy($img);
        if ($w < 300 || $h < 200) {
            $out['status'] = 'poor_quality'; $out['quality'] = 'too_small';
            $out['issues'][] = "ID image too small ({$w}x{$h} px)"; imagedestroy($img); return;
        }

        $brightness = $this->measureBrightness($img, $w, $h);
        $blur       = $this->measureBlur($img, $w, $h);
        $contrast   = $this->measureContrast($img, $w, $h);
        $out['brightness'] = round($brightness, 1);
        $out['blur_score'] = round($blur, 1);
        $out['contrast']   = round($contrast, 1);

        $qi = [];
        if ($brightness < self::MIN_BRIGHTNESS) $qi[] = 'ID image too dark';
        if ($brightness > self::MAX_BRIGHTNESS) $qi[] = 'ID image overexposed/glare';
        if ($blur < self::MAX_BLUR_VARIANCE)    $qi[] = 'ID image blurry (sharpness: '.round($blur).')';
        if ($contrast < self::MIN_CONTRAST)     $qi[] = 'Poor contrast on ID image';

        $aspect = $w / $h; $aspectFlip = $h / $w;
        $out['aspect_ratio'] = round($aspect, 3);
        $idMatchH = $this->matchesIDAspectRatio($aspect);
        $idMatchV = $this->matchesIDAspectRatio($aspectFlip);
        $looksLikeIDShape = ($idMatchH !== null || $idMatchV !== null);
        $out['id_type'] = $idMatchH['name'] ?? ($idMatchV['name'] ?? 'Non-standard');
        $out['analysis_notes'][] = 'Aspect ratio ' . round($aspect, 3)
            . ($looksLikeIDShape ? ' ✓ matches ID' : ' ✗ non-standard');

        $edgeDensity = $this->measureEdgeDensity($img, $w, $h);
        $out['edge_density']   = round($edgeDensity, 4);
        $hasTextStructure      = ($edgeDensity >= self::MIN_EDGE_DENSITY_ID);
        $out['has_text_zones'] = $hasTextStructure;

        $photoZoneSkin         = $this->measurePhotoZoneSkin($img, $w, $h);
        $out['has_photo_zone'] = ($photoZoneSkin >= self::MIN_SKIN_RATIO_ID_ZONE);

        $bgUniformity  = $this->measureBackgroundUniformity($img, $w, $h);
        $hasUniformBG  = ($bgUniformity > 0.40);
        $lineDensity   = $this->measureHorizontalLineDensity($img, $w, $h);
        $globalSkin    = $this->measureSkinRatio($img, $w, $h);
        $out['global_skin_ratio'] = round($globalSkin, 3);
        $isSelfiePhoto = ($globalSkin > self::MAX_GLOBAL_SKIN_ID);
        $marginBright  = $this->measureMarginBrightness($img, $w, $h);
        $out['margin_brightness'] = round($marginBright, 1);
        $hasIdMargin   = (!$isSelfiePhoto && $marginBright >= self::MIN_ID_MARGIN_BRIGHT);

        $idScore = 0;
        if ($looksLikeIDShape)      $idScore += 28;
        if ($hasTextStructure)      $idScore += 26;
        if ($out['has_photo_zone']) $idScore += 18;
        if ($hasUniformBG)          $idScore += 12;
        if ($lineDensity > 0.03)    $idScore +=  8;
        if ($hasIdMargin)           $idScore += 14;
        if ($isSelfiePhoto)         $idScore  =  0;

        $out['id_confidence'] = $idScore;
        $isIDCard             = ($idScore >= self::MIN_ID_SCORE);
        $out['is_id_card']    = $isIDCard;

        if ($isSelfiePhoto) {
            $out['issues'][] = 'Uploaded image appears to be a selfie — please upload a government-issued ID card';
        } elseif (!$isIDCard) {
            $out['issues'][] = 'Uploaded image does not appear to be a valid ID card (score: '.$idScore.'/'.(self::MIN_ID_SCORE).' required)';
        }

        // Form data consistency (not OCR)
        $nameCheck = $this->crossCheckName($prereg);
        $dobCheck  = $this->crossCheckDOB($prereg);
        $out['name_match']      = $nameCheck['result'];
        $out['dob_match']       = $dobCheck['result'];
        $out['name_match_note'] = $nameCheck['note'];
        $out['dob_match_note']  = $dobCheck['note'];
        $out['match_method']    = 'form_data_consistency';

        if ($nameCheck['result'] === false) $out['issues'][] = 'Name issue: ' . $nameCheck['note'];
        if ($dobCheck['result']  === false) $out['issues'][] = 'DOB issue: '  . $dobCheck['note'];

        $out['issues'] = array_merge($out['issues'], $qi);

        if ($isSelfiePhoto)                     { $out['status'] = 'invalid'; $out['quality'] = 'selfie_detected'; }
        elseif ($isIDCard && empty($qi))         { $out['status'] = 'present'; $out['quality'] = 'good'; }
        elseif ($isIDCard && count($qi) <= 1)   { $out['status'] = 'present'; $out['quality'] = 'acceptable'; }
        elseif ($isIDCard)                       { $out['status'] = 'poor_quality'; $out['quality'] = 'poor'; }
        else                                     { $out['status'] = 'invalid'; $out['quality'] = 'not_id'; }

        imagedestroy($img);
    }

    // ========================================================================
    //  VALID ID BACK ANALYSIS (structural check only — OCR handled separately)
    // ========================================================================

    private function analyzeValidIDBack(string $path, array &$out): void {
        $img = $this->loadImage($path);
        if (!$img) {
            $out['status']   = 'error';
            $out['issues'][] = 'Could not load back ID image';
            return;
        }

        $w = imagesx($img); $h = imagesy($img);
        if ($w < 200 || $h < 150) {
            $out['status']   = 'poor_quality';
            $out['quality']  = 'too_small';
            $out['issues'][] = "Back ID too small ({$w}x{$h} px)";
            imagedestroy($img);
            return;
        }

        $brightness = $this->measureBrightness($img, $w, $h);
        $blur       = $this->measureBlur($img, $w, $h);
        $contrast   = $this->measureContrast($img, $w, $h);
        $out['brightness'] = round($brightness, 1);
        $out['blur_score'] = round($blur, 1);
        $out['contrast']   = round($contrast, 1);

        $qi = [];
        if ($brightness < self::MIN_BRIGHTNESS) $qi[] = 'Back ID too dark';
        if ($brightness > self::MAX_BRIGHTNESS) $qi[] = 'Back ID overexposed';
        if ($blur < self::MAX_BLUR_VARIANCE)    $qi[] = 'Back ID blurry';

        $aspect = $w / $h; $aspectFlip = $h / $w;
        $out['aspect_ratio'] = round($aspect, 3);
        $looksLikeIDShape    = ($this->matchesIDAspectRatio($aspect) !== null || $this->matchesIDAspectRatio($aspectFlip) !== null);
        $out['analysis_notes'][] = 'Back aspect ratio ' . round($aspect, 3) . ($looksLikeIDShape ? ' ✓' : ' ✗');

        $edgeDensity = $this->measureEdgeDensity($img, $w, $h);
        $out['edge_density'] = round($edgeDensity, 4);
        $hasStructure        = ($edgeDensity >= self::MIN_EDGE_DENSITY_ID);
        $out['analysis_notes'][] = 'Back edge density ' . round($edgeDensity, 4) . ($hasStructure ? ' ✓' : ' ✗');

        $idScore = 0;
        if ($looksLikeIDShape) $idScore += 50;
        if ($hasStructure)     $idScore += 50;
        $out['id_confidence'] = $idScore;
        $out['is_id_card']    = ($idScore >= 60);

        $out['issues'] = array_merge($out['issues'], $qi);

        if ($idScore >= 80 && empty($qi))        { $out['status'] = 'present'; $out['quality'] = 'good'; }
        elseif ($idScore >= 50 && count($qi)<=1) { $out['status'] = 'present'; $out['quality'] = 'acceptable'; }
        elseif ($idScore >= 50)                  { $out['status'] = 'poor_quality'; $out['quality'] = 'poor'; }
        else                                     { $out['status'] = 'invalid'; $out['quality'] = 'not_id'; }

        imagedestroy($img);
    }

    

    private function analyzeFaceMatch(string $selfiePath, string $idPath, array &$out): void {
        $selfieImg = $this->loadImage($selfiePath);
        $idImg     = $this->loadImage($idPath);
        if (!$selfieImg || !$idImg) {
            $out['status'] = 'skipped'; $out['confidence'] = 0; $out['notes'] = 'Could not load one or both images';
            if ($selfieImg) imagedestroy($selfieImg);
            if ($idImg)     imagedestroy($idImg);
            return;
        }
        $sw = imagesx($selfieImg); $sh = imagesy($selfieImg);
        $iw = imagesx($idImg);     $ih = imagesy($idImg);

        $selfieZone = $this->cropRegion($selfieImg, $sw, $sh, (int)($sw*.20), (int)($sh*.10), (int)($sw*.60), (int)($sh*.70));
        $idZone     = $this->cropRegion($idImg,     $iw, $ih, (int)($iw*.02), (int)($ih*.05), (int)($iw*.28), (int)($ih*.85));

        if (!$selfieZone || !$idZone) {
            $out['status'] = 'pending_review'; $out['confidence'] = 40;
            $out['notes']  = 'Could not extract face zones — manual comparison recommended';
        } else {
            $selfieHist = $this->buildSkinHistogram($selfieZone, imagesx($selfieZone), imagesy($selfieZone));
            $idHist     = $this->buildSkinHistogram($idZone,     imagesx($idZone),     imagesy($idZone));
            $histSim    = $this->compareHistograms($selfieHist, $idHist);
            $selfieB    = $this->measureBrightness($selfieZone, imagesx($selfieZone), imagesy($selfieZone));
            $idB        = $this->measureBrightness($idZone,     imagesx($idZone),     imagesy($idZone));
            $bDiff      = abs($selfieB - $idB);
            $selfieS    = $this->measureSkinRatio($selfieZone, imagesx($selfieZone), imagesy($selfieZone));
            $idS        = $this->measureSkinRatio($idZone,     imagesx($idZone),     imagesy($idZone));
            $sDiff      = abs($selfieS - $idS);

            $confidence = (int)min(100, $histSim * 55 + max(0, 25 - $bDiff / 4) + max(0, 20 - $sDiff * 200));
            $out['confidence']      = $confidence;
            $out['hist_similarity'] = round($histSim * 100, 1);
            $out['notes']           = "Hist sim: " . round($histSim*100,1) . "%, B-diff: ".round($bDiff,1).", Skin-diff: ".round($sDiff*100,1)."%";

            if ($confidence >= 55)      { $out['status'] = 'match'; }
            elseif ($confidence >= 35)  { $out['status'] = 'pending_review'; $out['issues'][] = 'Inconclusive face match — manual check recommended'; }
            else                        { $out['status'] = 'mismatch'; $out['issues'][] = 'Low face similarity ('.$confidence.'%)'; }
        }

        imagedestroy($selfieImg); imagedestroy($idImg);
        if ($selfieZone) imagedestroy($selfieZone);
        if ($idZone)     imagedestroy($idZone);
    }

    // ========================================================================
    //  GD IMAGE METRIC HELPERS
    // ========================================================================

    private function loadImage(string $path) {
        if (!extension_loaded('gd')) return null;
        $img  = null; $mime = @mime_content_type($path);
        switch ($mime) {
            case 'image/jpeg': $img = @imagecreatefromjpeg($path); break;
            case 'image/png':  $img = @imagecreatefrompng($path);  break;
            case 'image/gif':  $img = @imagecreatefromgif($path);  break;
            case 'image/webp': $img = @imagecreatefromwebp($path); break;
            default:
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($ext,['jpg','jpeg'])) $img = @imagecreatefromjpeg($path);
                elseif ($ext==='png')  $img = @imagecreatefrompng($path);
                elseif ($ext==='gif')  $img = @imagecreatefromgif($path);
                elseif ($ext==='webp') $img = @imagecreatefromwebp($path);
        }
        return $img ?: null;
    }

    private function measureBrightness($img, int $w, int $h): float {
        $total = 0.0; $count = 0; $step = max(1, (int)(min($w,$h)/60));
        for ($y=0; $y<$h; $y+=$step) for ($x=0; $x<$w; $x+=$step) {
            $rgb = imagecolorat($img,$x,$y);
            $total += 0.299*(($rgb>>16)&0xFF)+0.587*(($rgb>>8)&0xFF)+0.114*($rgb&0xFF); $count++;
        }
        return $count>0 ? $total/$count : 0.0;
    }

    private function measureContrast($img, int $w, int $h): float {
        $vals=[]; $sum=0.0; $step=max(1,(int)(min($w,$h)/60));
        for ($y=0;$y<$h;$y+=$step) for ($x=0;$x<$w;$x+=$step) { $lum=$this->pixelLum($img,$x,$y); $vals[]=$lum; $sum+=$lum; }
        if (count($vals)<2) return 0.0; $mean=$sum/count($vals); $var=0.0;
        foreach ($vals as $v) $var+=($v-$mean)**2; return sqrt($var/count($vals));
    }

    private function measureBlur($img, int $w, int $h): float {
        $grads=[]; $sum=0.0; $step=max(1,(int)(min($w,$h)/80));
        for ($y=$step;$y<$h-$step;$y+=$step) for ($x=$step;$x<$w-$step;$x+=$step) {
            $g=abs($this->pixelLum($img,$x,$y)-$this->pixelLum($img,$x+$step,$y))+abs($this->pixelLum($img,$x,$y)-$this->pixelLum($img,$x,$y+$step));
            $grads[]=$g; $sum+=$g;
        }
        if (empty($grads)) return 0.0; $mean=$sum/count($grads); $var=0.0;
        foreach ($grads as $g) $var+=($g-$mean)**2; return sqrt($var/count($grads));
    }

    private function pixelLum($img, int $x, int $y): float {
        $rgb=imagecolorat($img,$x,$y);
        return 0.299*(($rgb>>16)&0xFF)+0.587*(($rgb>>8)&0xFF)+0.114*($rgb&0xFF);
    }

    private function measureSkinRatio($img, int $w, int $h): float {
        $skin=0; $total=0; $step=max(1,(int)(min($w,$h)/80));
        for ($y=0;$y<$h;$y+=$step) for ($x=0;$x<$w;$x+=$step) {
            $rgb=imagecolorat($img,$x,$y);
            if ($this->isSkinPixel(($rgb>>16)&0xFF,($rgb>>8)&0xFF,$rgb&0xFF)) $skin++;
            $total++;
        }
        return $total>0 ? $skin/$total : 0.0;
    }

    private function measurePhotoZoneSkin($img, int $w, int $h): float {
        $x1=(int)($w*.02); $x2=(int)($w*.28); $y1=(int)($h*.08); $y2=(int)($h*.92);
        $skin=0; $total=0; $step=max(1,(int)(($x2-$x1)/40));
        for ($y=$y1;$y<$y2&&$y<$h;$y+=$step) for ($x=$x1;$x<$x2&&$x<$w;$x+=$step) {
            $rgb=imagecolorat($img,$x,$y);
            if ($this->isSkinPixel(($rgb>>16)&0xFF,($rgb>>8)&0xFF,$rgb&0xFF)) $skin++;
            $total++;
        }
        return $total>0 ? $skin/$total : 0.0;
    }

    private function measureEdgeDensity($img, int $w, int $h): float {
        $step=max(1,(int)(min($w,$h)/100)); $edges=0; $total=0; $thresh=25;
        for ($y=$step;$y<$h-$step;$y+=$step) for ($x=$step;$x<$w-$step;$x+=$step) {
            $gx=abs($this->pixelLum($img,$x+$step,$y)-$this->pixelLum($img,$x-$step,$y));
            $gy=abs($this->pixelLum($img,$x,$y+$step)-$this->pixelLum($img,$x,$y-$step));
            if (($gx+$gy)>$thresh) $edges++; $total++;
        }
        return $total>0 ? $edges/$total : 0.0;
    }

    private function measureBackgroundUniformity($img, int $w, int $h): float {
        $x1=(int)($w*.35); $step=max(1,(int)(min($w-$x1,$h)/50));
        $vals=[]; $sum=0.0;
        for ($y=(int)($h*.05);$y<(int)($h*.95);$y+=$step) for ($x=$x1;$x<$w-$step;$x+=$step) { $lum=$this->pixelLum($img,$x,$y); $vals[]=$lum; $sum+=$lum; }
        if (count($vals)<2) return 0.0; $mean=$sum/count($vals); if ($mean<80) return 0.0;
        $var=0.0; foreach ($vals as $v) $var+=($v-$mean)**2; $std=sqrt($var/count($vals));
        return max(0.0,min(1.0,1.0-($std/80.0)));
    }

    private function measureHorizontalLineDensity($img, int $w, int $h): float {
        $x1=(int)($w*.30); $step=max(1,(int)($h/80)); $lines=0; $rows=0;
        for ($y=$step;$y<$h-$step;$y+=$step) {
            $re=0; $xs=max(1,(int)(($w-$x1)/30));
            for ($x=$x1;$x<$w-$xs;$x+=$xs) if (abs($this->pixelLum($img,$x,$y)-$this->pixelLum($img,$x+$xs,$y))>20) $re++;
            if ($re>=3) $lines++; $rows++;
        }
        return $rows>0 ? $lines/$rows : 0.0;
    }

    private function measureMarginBrightness($img, int $w, int $h): float {
        $sw=max(6,(int)($w*.06)); $sh=max(6,(int)($h*.06)); $step=max(1,(int)(min($sw,$sh)/8));
        $total=0.0; $count=0;
        for ($y=0;$y<$sh;$y+=$step) for ($x=$sw;$x<$w-$sw;$x+=$step) { $total+=$this->pixelLum($img,$x,$y); $count++; }
        for ($y=$h-$sh;$y<$h;$y+=$step) for ($x=$sw;$x<$w-$sw;$x+=$step) { $total+=$this->pixelLum($img,$x,$y); $count++; }
        for ($y=$sh;$y<$h-$sh;$y+=$step) for ($x=0;$x<$sw;$x+=$step) { $total+=$this->pixelLum($img,$x,$y); $count++; }
        for ($y=$sh;$y<$h-$sh;$y+=$step) for ($x=$w-$sw;$x<$w;$x+=$step) { $total+=$this->pixelLum($img,$x,$y); $count++; }
        return $count>0 ? $total/$count : 0.0;
    }

    private function isSkinPixel(int $r, int $g, int $b): bool {
        $rf=$r/255.0; $gf=$g/255.0; $bf=$b/255.0;
        $max=max($rf,$gf,$bf); $min=min($rf,$gf,$bf); $d=$max-$min;
        $v=$max*100; if ($v<self::SKIN_V_LOW||$v>self::SKIN_V_HIGH) return false;
        $s=$max>0?($d/$max)*100:0; if ($s<self::SKIN_S_LOW||$s>self::SKIN_S_HIGH) return false;
        if ($d==0) return false;
        if ($max==$rf)     $h=60*fmod((($gf-$bf)/$d),6);
        elseif ($max==$gf) $h=60*((($bf-$rf)/$d)+2);
        else               $h=60*((($rf-$gf)/$d)+4);
        if ($h<0) $h+=360;
        return ($h>=self::SKIN_H_LOW1&&$h<=self::SKIN_H_HIGH1)||($h>=self::SKIN_H_LOW2&&$h<=self::SKIN_H_HIGH2);
    }

    private function cropRegion($img, int $w, int $h, int $x, int $y, int $cw, int $ch) {
        $cw=min($cw,$w-$x); $ch=min($ch,$h-$y); if ($cw<=0||$ch<=0) return null;
        $dst=imagecreatetruecolor($cw,$ch); imagecopy($dst,$img,0,0,$x,$y,$cw,$ch); return $dst;
    }

    private function buildSkinHistogram($img, int $w, int $h): array {
        $hist=array_fill(0,16,0); $step=max(1,(int)(min($w,$h)/50));
        for ($y=0;$y<$h;$y+=$step) for ($x=0;$x<$w;$x+=$step) {
            $rgb=imagecolorat($img,$x,$y); $rf=(($rgb>>16)&0xFF)/255.0; $gf=(($rgb>>8)&0xFF)/255.0; $bf=($rgb&0xFF)/255.0;
            $max=max($rf,$gf,$bf); $min=min($rf,$gf,$bf); $d=$max-$min; if ($d==0||$max==0) continue;
            if ($max==$rf) $hue=60*fmod((($gf-$bf)/$d),6); elseif ($max==$gf) $hue=60*((($bf-$rf)/$d)+2); else $hue=60*((($rf-$gf)/$d)+4);
            if ($hue<0) $hue+=360; $hist[(int)($hue/22.5)]++;
        }
        $total=array_sum($hist); if ($total>0) foreach ($hist as &$v) $v/=$total; return $hist;
    }

    private function compareHistograms(array $a, array $b): float {
        $c=0.0; for ($i=0;$i<16;$i++) $c+=sqrt($a[$i]*$b[$i]); return min(1.0,$c);
    }

    private function matchesIDAspectRatio(float $ratio): ?array {
        foreach (self::ID_ASPECT_RATIOS as $k)
            if (abs($ratio-$k['ratio'])/$k['ratio']<=self::ASPECT_TOLERANCE) return $k;
        return null;
    }

    

    private function crossCheckName(array $prereg): array {
        $fn=trim($prereg['first_name']??''); $ln=trim($prereg['last_name']??''); $mn=trim($prereg['middle_name']??'');
        if (empty($fn)||empty($ln)) return ['result'=>false,'note'=>'First or last name is empty'];
        if (strlen($fn)<2||strlen($ln)<2) return ['result'=>false,'note'=>'Name fields too short'];
        $full=trim("$fn $mn $ln");
        if (preg_match('/\d/',$full)) return ['result'=>false,'note'=>'Name contains numbers'];
        if (preg_match('/(.)\1{3,}/u',$full)) return ['result'=>false,'note'=>'Suspicious repeated characters'];
        $pl=['test','asdf','john doe','juan dela cruz','sample','dummy','xxx','n/a','na','none'];
        if (in_array(strtolower("$fn $ln"),$pl)||in_array(strtolower($fn),$pl)) return ['result'=>false,'note'=>'Name appears to be a placeholder'];
        foreach (preg_split('/\s+/',$full) as $t)
            if (!empty($t)&&!preg_match("/^[a-záàâãéèêíìîóòôõúùûñüçA-ZÁÀÂÃÉÈÊÍÌÎÓÒÔÕÚÙÛÑÜÇ'\-\.Ññ]+$/u",$t))
                return ['result'=>false,'note'=>"Token '{$t}' contains invalid characters"];
        return ['result'=>true,'note'=>"Name valid ({$fn} {$ln})"];
    }

    private function crossCheckDOB(array $prereg): array {
        $dob=trim($prereg['birthdate']??''); $age=isset($prereg['age'])&&$prereg['age']!==''?(int)$prereg['age']:null;
        if (empty($dob)) return ['result'=>false,'note'=>'Birthdate empty'];
        $dt=@date_create($dob); if (!$dt) return ['result'=>false,'note'=>"'{$dob}' invalid date"];
        $now=new DateTime(); if ($dt>$now) return ['result'=>false,'note'=>'Birthdate in future'];
        $year=(int)$dt->format('Y'); if ($year<1920||$year>(int)date('Y')) return ['result'=>false,'note'=>"Year {$year} unrealistic"];
        if ($age!==null&&$age>0) { $calc=(int)$now->diff($dt)->y; if (abs($calc-$age)>1) return ['result'=>false,'note'=>"Age {$age} vs DOB gives {$calc} yrs"]; }
        return ['result'=>true,'note'=>"DOB {$dob}".($age?", age {$age}":'')." consistent"];
    }

   

    private function validateData($prereg) {
        $v=['passed'=>true,'issues'=>[],'warnings'=>[]];
        if (!empty($prereg['age'])) { $a=(int)$prereg['age']; if ($a<15) $v['warnings'][]="Age {$a} — guardian consent required"; if ($a>60) $v['warnings'][]="Age {$a} — verify ALS eligibility"; }
        if (!empty($prereg['email'])&&!filter_var($prereg['email'],FILTER_VALIDATE_EMAIL)) { $v['passed']=false; $v['issues'][]='Invalid email format'; }
        if (!empty($prereg['contact_number'])) { $d=preg_replace('/[^0-9]/','',$prereg['contact_number']); if (strlen($d)<10||strlen($d)>13) $v['warnings'][]='Contact number length invalid ('.strlen($d).' digits)'; }
        if (!empty($prereg['lrn'])&&!preg_match('/^\d{12}$/',$prereg['lrn'])) $v['warnings'][]='LRN must be 12 digits';
        return $v;
    }


    private function checkDuplicates($prereg) {
        $out=['found'=>false,'matches'=>[]]; $id=(int)$prereg['preregistration_id'];
        $fn=trim($prereg['first_name']??''); $ln=trim($prereg['last_name']??''); $mn=trim($prereg['middle_name']??'');
        if (!empty($fn)&&!empty($ln)) {
            $stmt=$this->conn->prepare("SELECT preregistration_id,first_name,middle_name,last_name,status,submitted_at,tracking_code FROM preregistrations WHERE first_name=? AND last_name=? AND middle_name=? AND preregistration_id!=? ORDER BY submitted_at DESC LIMIT 5");
            $stmt->bind_param("sssi",$fn,$ln,$mn,$id); $stmt->execute();
            $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
            if (!empty($rows)) { $out['found']=true; $out['matches'][]=['type'=>'full_name','value'=>trim("$fn $mn $ln"),'records'=>$rows]; }
        }
        return $out;
    }


    private function detectFraud($prereg) {
        $f=['risk_level'=>'low','indicators'=>[],'score'=>0]; $s=0;
        if (!empty($prereg['email'])) { $domain=strtolower(substr(strrchr($prereg['email'],"@"),1)); $bad=['tempmail.com','10minutemail.com','guerrillamail.com','mailinator.com','yopmail.com','throwam.com','dispostable.com','fakeinbox.com']; if (in_array($domain,$bad)) { $s+=30; $f['indicators'][]='Disposable email domain'; } }
        if (!empty($prereg['age'])) { $a=(int)$prereg['age']; if ($a<10) { $s+=40; $f['indicators'][]='Age too young'; } if ($a>100) { $s+=40; $f['indicators'][]='Age unrealistic'; } }
        $req=['first_name','last_name','birthdate','contact_number','email']; $missing=array_filter($req,fn($k)=>empty($prereg[$k])); if (!empty($missing)) { $s+=20; $f['indicators'][]='Missing: '.implode(', ',$missing); }
        if (!empty($prereg['ip_address'])) { $ip=$prereg['ip_address']; $stmt=$this->conn->prepare("SELECT COUNT(*) AS cnt FROM preregistrations WHERE ip_address=? AND submitted_at>DATE_SUB(NOW(),INTERVAL 1 HOUR)"); if ($stmt) { $stmt->bind_param("s",$ip); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close(); if ($row&&(int)$row['cnt']>3) { $s+=25; $f['indicators'][]="High IP rate ({$row['cnt']} in 1hr)"; } } }
        $f['score']=$s; $f['risk_level']=$s>=70?'high':($s>=40?'medium':'low');
        return $f;
    }

   

    private function calculateConfidenceScore($analysis): int {
        $score = 100;
        $img   = $analysis['image_analysis'];
        $manualReviewBlocker = false;

        // Selfie
        switch ($img['selfie']['status']) {
            case 'missing':      $score -= 20; $manualReviewBlocker = true; break;
            case 'error':        $score -= 20; $manualReviewBlocker = true; break;
            case 'poor_quality': $score -= 12; $manualReviewBlocker = true; break;
        }
        if ($img['selfie']['has_face'] === false) {
            $score -= 10;
            $manualReviewBlocker = true;
        }
        if ($img['selfie']['has_face'] === true) $score += 3;

        // Front ID
        switch ($img['valid_id']['status']) {
            case 'missing':      $score -= 30; $manualReviewBlocker = true; break;
            case 'invalid':      $score -= 35; $manualReviewBlocker = true; break;
            case 'error':        $score -= 25; $manualReviewBlocker = true; break;
            case 'poor_quality': $score -= 15; $manualReviewBlocker = true; break;
            default:
                if ($img['valid_id']['is_id_card'] === true)     $score += 5;
                else                                              $manualReviewBlocker = true;
                if ($img['valid_id']['has_text_zones'] === true) $score += 3;
                if ($img['valid_id']['has_photo_zone'] === true) $score += 3;
                if ($img['valid_id']['name_match'] === false)   { $score -= 10; $manualReviewBlocker = true; }
                elseif ($img['valid_id']['name_match'] === true) $score += 2;
                if ($img['valid_id']['dob_match'] === false)    { $score -= 10; $manualReviewBlocker = true; }
                elseif ($img['valid_id']['dob_match'] === true)  $score += 2;
        }

        if (isset($img['valid_id_back']) && in_array($img['valid_id_back']['status'], ['present','acceptable'])) {
            $score += 5;
        }

        // OCR is disabled - all OCR checks are neutral (not blocking)
        // No score adjustments for OCR

        $fm = $img['face_match']['status'] ?? 'skipped';
        if ($fm === 'mismatch') {
            $score -= 20;
            $manualReviewBlocker = true;
        } elseif ($fm === 'pending_review' || $fm === 'skipped') {
            $score -= 12;
            $manualReviewBlocker = true;
        } elseif ($fm === 'match') {
            $score += max(0, min(5, (int)(($img['face_match']['confidence'] - 55) / 5)));
        }

        if (!$analysis['data_validation']['passed']) {
            $score -= 25;
            $manualReviewBlocker = true;
        }
        $score -= count($analysis['data_validation']['warnings']) * 3;
        if ($analysis['duplicate_check']['found']) {
            $score -= 20;
            $manualReviewBlocker = true;
        }
        $score -= $analysis['fraud_detection']['score'];

        $score = max(0, min(100, $score));
        if ($manualReviewBlocker) {
            $score = min($score, 74);
        }

        return $score;
    }


    private function generateRecommendation($analysis): array {
        $score = $analysis['confidence_score'];
        $img   = $analysis['image_analysis'];

        $rec = ['action' => 'review', 'confidence' => $score, 'reason' => '', 'priority' => 'normal', 'verification_needed' => []];
        $needed = [];

        if ($img['selfie']['status'] === 'missing')      $needed[] = 'Selfie photo required';
        if ($img['selfie']['status'] === 'poor_quality') $needed[] = 'Selfie quality too poor';
        if ($img['selfie']['has_face'] === false)        $needed[] = 'No face detected in selfie';
        if ($img['valid_id']['status'] === 'missing')    $needed[] = 'Valid ID required';
        if ($img['valid_id']['status'] === 'invalid')    $needed[] = 'Uploaded document is not a valid ID';
        if ($img['valid_id']['status'] === 'poor_quality') $needed[] = 'ID image quality too poor';

        // OCR is disabled - add manual verification notice
        $needed[] = 'OCR verification is DISABLED - please manually verify the ID card text matches the applicant\'s name and birthdate';

        if ($img['valid_id']['name_match'] === false) $needed[] = 'Form name data issue: ' . ($img['valid_id']['name_match_note'] ?? '');
        if ($img['valid_id']['dob_match'] === false)  $needed[] = 'Form DOB/age issue: ' . ($img['valid_id']['dob_match_note'] ?? '');

        $fm = $img['face_match']['status'] ?? 'skipped';
        if ($fm === 'mismatch') $needed[] = 'Face in selfie may not match ID photo';
        elseif ($fm === 'pending_review') $needed[] = 'Face match is inconclusive — manual comparison required';
        elseif ($fm === 'skipped') $needed[] = 'Face match could not be completed — manual comparison required';
        if ($analysis['duplicate_check']['found']) $needed[] = 'Duplicate name found';
        if ($analysis['fraud_detection']['risk_level'] === 'high') $needed[] = 'Fraud risk indicators detected';
        if (!empty($analysis['data_validation']['issues'])) $needed[] = 'Data: ' . implode(', ', $analysis['data_validation']['issues']);

        $rec['verification_needed'] = $needed;

        $fraudLow         = $analysis['fraud_detection']['risk_level'] === 'low';
        $noDupes          = !$analysis['duplicate_check']['found'];
        $nameOk           = $img['valid_id']['name_match'] === true;
        $dobOk            = $img['valid_id']['dob_match'] === true;
        $idOk             = in_array($img['valid_id']['status'], ['present', 'acceptable']);
        $selfieOk         = $img['selfie']['status'] === 'present';
        $faceOk           = ($fm === 'match');
        $dataOk           = $analysis['data_validation']['passed'];

        // Auto-approve only if ALL conditions are met (simple, strict approval)
        if ($score >= 70 && $fraudLow && $noDupes && $nameOk && $dobOk && $idOk && $selfieOk && $faceOk && $dataOk) {
            $rec['action']   = 'approve';
            $rec['reason']   = 'All checks passed. OCR is disabled, but all other verifications are successful.';
            $rec['priority'] = 'high';
        } elseif ($score >= 55) {
            $rec['action'] = 'review';
            $rec['reason'] = 'Moderate confidence — minor issues detected. Manual review required.';
        } elseif ($score >= 35) {
            $rec['action'] = 'review';
            $rec['reason'] = 'Low confidence — multiple issues. Manual review required.';
            $rec['priority'] = 'high';
        } else {
            $rec['action'] = 'flag';
            $rec['reason'] = 'Very low confidence — significant red flags. Manual review required.';
            $rec['priority'] = 'high';
        }

        if (!empty($needed)) $rec['reason'] .= ' Needs: ' . implode('; ', $needed);
        return $rec;
    }

  

    private function ensureAnalysisTableExists(): void {
        if (self::$analysisTableChecked) return;
        self::$analysisTableChecked = true;
        $res = $this->conn->query("SHOW TABLES LIKE 'preregistration_analysis'");
        if ($res && $res->num_rows == 0) {
            $this->conn->query("CREATE TABLE IF NOT EXISTS `preregistration_analysis` (`id` INT(11) NOT NULL AUTO_INCREMENT,`preregistration_id` INT(11) NOT NULL,`analysis_data` LONGTEXT NOT NULL,`confidence_score` INT(3) DEFAULT NULL,`recommendation` VARCHAR(50) DEFAULT NULL,`created_at` DATETIME NOT NULL,`updated_at` DATETIME DEFAULT NULL,PRIMARY KEY (`id`),UNIQUE KEY `preregistration_id` (`preregistration_id`),KEY `idx_confidence` (`confidence_score`),KEY `idx_recommendation` (`recommendation`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if ($res) $res->free_result();
    }

    private function saveAnalysis(int $preregId, array $analysis): void {
        $this->ensureAnalysisTableExists();
        $json  = json_encode($analysis);
        $score = $analysis['confidence_score'];
        $rec   = $analysis['recommendation']['action'];
        $stmt  = $this->conn->prepare("INSERT INTO preregistration_analysis (preregistration_id,analysis_data,confidence_score,recommendation,created_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE analysis_data=VALUES(analysis_data),confidence_score=VALUES(confidence_score),recommendation=VALUES(recommendation),updated_at=NOW()");
        $stmt->bind_param("isis",$preregId,$json,$score,$rec);
        $stmt->execute(); $stmt->close();
    }
}

// ============================================================
// SECURITY, CACHE, NOTIFICATION MANAGERS (unchanged)
// ============================================================

class SecurityManager {
    private $conn;
    public function __construct($conn) { $this->conn=$conn; }
    public function validateCSRFToken($token) { if (!isset($_SESSION['csrf_token'])) return false; return hash_equals($_SESSION['csrf_token'],$token); }
    public function generateCSRFToken() { if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32)); return $_SESSION['csrf_token']; }
    public function checkRateLimit($action,$userId,$limit=50,$window=300) { $key="rate_limit_{$action}_{$userId}"; $current=time(); if (!isset($_SESSION[$key])) { $_SESSION[$key]=['count'=>1,'time'=>$current]; return true; } $data=$_SESSION[$key]; if ($current-$data['time']>$window) { $_SESSION[$key]=['count'=>1,'time'=>$current]; return true; } if ($data['count']>=$limit) return false; $_SESSION[$key]['count']++; return true; }
    public function sanitizeInput($data,$type='string') { if ($data===null) return null; switch ($type) { case 'int': return filter_var($data,FILTER_VALIDATE_INT); case 'email': return filter_var($data,FILTER_VALIDATE_EMAIL); default: return htmlspecialchars(strip_tags(trim((string)$data)),ENT_QUOTES,'UTF-8'); } }
}

class CacheManager {
    private $prefix='als_cache_';
    public function get($key) { $k=$this->prefix.$key; if (isset($_SESSION[$k])&&$_SESSION[$k]['expires']>time()) return $_SESSION[$k]['data']; return null; }
    public function set($key,$data,$ttl=300) { $_SESSION[$this->prefix.$key]=['data'=>$data,'expires'=>time()+$ttl]; }
    public function delete($key) { unset($_SESSION[$this->prefix.$key]); }
}

class NotificationManager {
    private $conn;
    public function __construct($conn) { $this->conn=$conn; }
    public function create($preregId,$status,$adminId) { $stmt=$this->conn->prepare("INSERT INTO preregistration_notifications (preregistration_id,notification_type,admin_id,is_read,created_at) VALUES (?,?,?,0,NOW())"); $stmt->bind_param("isi",$preregId,$status,$adminId); $r=$stmt->execute(); $stmt->close(); return $r; }
    public function sendEmail($to,$subject,$htmlBody) { $h="MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: ALS System <noreply@als-system.online>\r\n"; return mail($to,$subject,$htmlBody,$h); }
}

// ============================================================
// PRE-REGISTRATION MANAGER
// ============================================================

class PreregistrationManager {
    private $conn,$cache,$security,$notification,$smartApproval;

    public function __construct($conn,$cache,$security,$notification,$smartApproval) {
        $this->conn=$conn; $this->cache=$cache; $this->security=$security;
        $this->notification=$notification; $this->smartApproval=$smartApproval;
    }

    public function getCounts() {
        $c=$this->cache->get('prereg_counts'); if ($c!==null) return $c;
        $r=$this->conn->query("SELECT COUNT(*) AS total,SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending,SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved,SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) AS rejected FROM preregistrations");
        $counts=$r?$r->fetch_assoc():['total'=>0,'pending'=>0,'approved'=>0,'rejected'=>0]; if ($r) $r->free_result();
        $this->cache->set('prereg_counts',$counts,30); return $counts;
    }

    public function getUnreadCount($adminId) {
        $c=$this->cache->get("unread_count_{$adminId}"); if ($c!==null) return $c;
        $stmt=$this->conn->prepare("SELECT COUNT(DISTINCT preregistration_id) AS count FROM preregistration_notifications WHERE is_read=0"); $stmt->execute(); $r=$stmt->get_result(); $count=(int)$r->fetch_assoc()['count']; $r->free_result(); $stmt->close();
        $this->cache->set("unread_count_{$adminId}",$count,30); return $count;
    }

    public function updateStatus($preregId,$status,$adminId,$notes='') {
        if (!in_array($status,['approved','rejected'])) return ['success'=>false,'message'=>'Invalid status'];
        $this->conn->begin_transaction();
        try {
            $stmt=$this->conn->prepare("SELECT * FROM preregistrations WHERE preregistration_id=?"); $stmt->bind_param("i",$preregId); $stmt->execute(); $r=$stmt->get_result(); $prereg=$r->fetch_assoc(); $r->free_result(); $stmt->close();
            if (!$prereg) throw new Exception("Not found");
            $stmt=$this->conn->prepare("UPDATE preregistrations SET status=?,reviewed_by=?,reviewed_at=NOW(),review_notes=? WHERE preregistration_id=?"); $stmt->bind_param("sisi",$status,$adminId,$notes,$preregId); $stmt->execute(); $stmt->close();
            $this->notification->create($preregId,$status,$adminId);
            if ($status==='approved'&&!empty($prereg['email'])) $this->sendApprovalEmail($prereg);
            $this->cache->delete('prereg_counts'); $this->cache->delete("unread_count_{$adminId}");
            $this->conn->commit(); return ['success'=>true,'message'=>"Pre-registration {$status} successfully"];
        } catch (Exception $e) { $this->conn->rollback(); error_log("updateStatus: ".$e->getMessage()); return ['success'=>false,'message'=>$e->getMessage()]; }
    }

    public function bulkUpdate($ids,$action,$adminId) {
        if (empty($ids)||!is_array($ids)) return ['success'=>false,'message'=>'No items selected'];
        if (!in_array($action,['approve','reject','delete'])) return ['success'=>false,'message'=>'Invalid action'];
        $this->conn->begin_transaction();
        try {
            $ph=implode(',',array_fill(0,count($ids),'?')); $types=str_repeat('i',count($ids));
            if ($action==='delete') { $stmt=$this->conn->prepare("DELETE FROM preregistrations WHERE preregistration_id IN ({$ph})"); $stmt->bind_param($types,...$ids); }
            else { $status=$action==='approve'?'approved':'rejected'; $types='si'.str_repeat('i',count($ids)); $params=array_merge([$status,$adminId],$ids); $stmt=$this->conn->prepare("UPDATE preregistrations SET status=?,reviewed_by=?,reviewed_at=NOW() WHERE preregistration_id IN ({$ph})"); $stmt->bind_param($types,...$params); }
            $stmt->execute(); $stmt->close(); $this->conn->commit();
            $this->cache->delete('prereg_counts'); $this->cache->delete("unread_count_{$adminId}");
            return ['success'=>true,'message'=>ucfirst($action)." completed"];
        } catch (Exception $e) { $this->conn->rollback(); return ['success'=>false,'message'=>$e->getMessage()]; }
    }

    public function getList($filters=[]) {
        $query="SELECT p.*,b.name AS barangay_name,(SELECT COUNT(*) FROM preregistration_notifications WHERE preregistration_id=p.preregistration_id AND is_read=0) AS unread_notifications,(SELECT confidence_score FROM preregistration_analysis WHERE preregistration_id=p.preregistration_id ORDER BY created_at DESC LIMIT 1) AS ai_confidence,(SELECT recommendation FROM preregistration_analysis WHERE preregistration_id=p.preregistration_id ORDER BY created_at DESC LIMIT 1) AS ai_recommendation FROM preregistrations p LEFT JOIN barangays b ON p.current_barangay_id=b.barangay_id WHERE 1=1";
        $params=[]; $types='';
        if (!empty($filters['status'])&&$filters['status']!=='all') { $query.=" AND p.status=?"; $params[]=$filters['status']; $types.='s'; }
        if (!empty($filters['search'])) { $query.=" AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.tracking_code LIKE ? OR p.email LIKE ?)"; $s="%{$filters['search']}%"; $params=array_merge($params,[$s,$s,$s,$s]); $types.='ssss'; }
        $page=max(1,(int)($filters['page']??1)); $limit=min(100,(int)($filters['limit']??20)); $offset=($page-1)*$limit;
        $query.=" ORDER BY p.submitted_at DESC LIMIT ? OFFSET ?"; $params[]=$limit; $params[]=$offset; $types.='ii';
        $stmt=$this->conn->prepare($query); if (!empty($params)) $stmt->bind_param($types,...$params); $stmt->execute();
        $r=$stmt->get_result(); $data=$r->fetch_all(MYSQLI_ASSOC); $r->free_result(); $stmt->close();
        return ['data'=>$data,'total'=>$this->getTotalCount($filters),'page'=>$page,'limit'=>$limit];
    }

    private function getTotalCount($filters) {
        $query="SELECT COUNT(*) AS total FROM preregistrations p WHERE 1=1"; $params=[]; $types='';
        if (!empty($filters['status'])&&$filters['status']!=='all') { $query.=" AND p.status=?"; $params[]=$filters['status']; $types.='s'; }
        if (!empty($filters['search'])) { $query.=" AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.tracking_code LIKE ? OR p.email LIKE ?)"; $s="%{$filters['search']}%"; $params=array_merge($params,[$s,$s,$s,$s]); $types.='ssss'; }
        $stmt=$this->conn->prepare($query); if (!empty($params)) $stmt->bind_param($types,...$params); $stmt->execute();
        $r=$stmt->get_result(); $row=$r->fetch_assoc(); $total=$row?(int)$row['total']:0; $r->free_result(); $stmt->close(); return $total;
    }

    public function autoProcessPending($adminId,$batchSize=20) {
        $stmt=$this->conn->prepare("SELECT p.*,b.name AS barangay_name FROM preregistrations p LEFT JOIN barangays b ON p.current_barangay_id=b.barangay_id LEFT JOIN preregistration_analysis pa ON p.preregistration_id=pa.preregistration_id WHERE p.status='pending' AND pa.preregistration_id IS NULL ORDER BY p.submitted_at ASC LIMIT ?");
        $stmt->bind_param("i",$batchSize); $stmt->execute(); $r=$stmt->get_result(); $pending=$r->fetch_all(MYSQLI_ASSOC); $r->free_result(); $stmt->close();

        $processed=['auto_approved'=>[],'flagged'=>[],'needs_review'=>[],'total'=>0];

        foreach ($pending as $prereg) {
            $id       = $prereg['preregistration_id'];
            $analysis = $this->smartApproval->analyzePreregistration($id);

            $score     = $analysis['confidence_score'];
            $fraud     = $analysis['fraud_detection']['risk_level'];
            $dupes     = $analysis['duplicate_check']['found'];
            $nameMatch = $analysis['image_analysis']['valid_id']['name_match'] ?? null;
            $dobMatch  = $analysis['image_analysis']['valid_id']['dob_match']  ?? null;
            $faceMatch = $analysis['image_analysis']['face_match']['status']   ?? 'skipped';
            $idStatus  = $analysis['image_analysis']['valid_id']['status']     ?? 'missing';
            $selfieStatus = $analysis['image_analysis']['selfie']['status']    ?? 'missing';
            $hasFace   = $analysis['image_analysis']['selfie']['has_face']     ?? false;
            $dataOk    = $analysis['data_validation']['passed']                ?? false;
            $isIDCard  = $analysis['image_analysis']['valid_id']['is_id_card'] ?? false;

            $ocrSummary  = $analysis['ocr_analysis']['summary'] ?? 'OCR DISABLED';
            $idScore     = $analysis['image_analysis']['valid_id']['id_confidence'] ?? 0;
            $fraudScore  = $analysis['fraud_detection']['score'] ?? 0;

            error_log("AutoApproval ID#{$id}: score={$score}, fraud={$fraud}({$fraudScore}), dupes=".($dupes?'yes':'no')
                .", face={$faceMatch}, idStatus={$idStatus}");

            // ------------------------------------------------------------------
            // HARD BLOCKERS — any one of these prevents auto-approval entirely.
            // These are non-negotiable: the record must go to manual review.
            // ------------------------------------------------------------------
            $hardBlocked  = false;
            $flagReasons  = [];

            if ($fraud === 'high') {
                $hardBlocked = true;
                $flagReasons[] = "High fraud risk (score: {$fraudScore})";
            }
            if ($dupes) {
                $hardBlocked = true;
                $flagReasons[] = "Duplicate name found — possible double-registration";
            }
            if ($nameMatch === false) {
                $hardBlocked = true;
                $flagReasons[] = "Form name data failed validation";
            }
            if ($dobMatch === false) {
                $hardBlocked = true;
                $flagReasons[] = "Birthdate / age is inconsistent";
            }
            if (!in_array($idStatus, ['present', 'acceptable'])) {
                $hardBlocked = true;
                $flagReasons[] = "Front ID image is invalid (status: {$idStatus})";
            }
            if ($isIDCard === false) {
                $hardBlocked = true;
                $flagReasons[] = "Uploaded front image not recognised as a government ID (id_score: {$idScore}/100)";
            }
            if (!in_array($selfieStatus, ['present'])) {
                $hardBlocked = true;
                $flagReasons[] = "Selfie is missing or invalid (status: {$selfieStatus})";
            }
            if ($hasFace === false) {
                $hardBlocked = true;
                $flagReasons[] = "No face detected in the selfie photo";
            }
            if (!$dataOk) {
                $hardBlocked = true;
                $flagReasons[] = "Submitted form data failed validation";
            }
            if ($faceMatch === 'mismatch') {
                $hardBlocked = true;
                $flagReasons[] = "Face in selfie does not match the ID photo";
            }

            // Low overall score is also a hard blocker
            if ($score < 40) {
                $hardBlocked = true;
                $flagReasons[] = "Confidence score too low ({$score}%) — too many checks failed";
            }

            // ------------------------------------------------------------------
            // SOFT CONCERNS — logged but do NOT block auto-approval by themselves.
            // These are included in the review note so admins can see them.
            // ------------------------------------------------------------------
            $softConcerns = [];
            if ($fraud === 'medium')                              $softConcerns[] = "Medium fraud risk (score: {$fraudScore})";
            if ($faceMatch === 'pending_review')                  $softConcerns[] = "Face match inconclusive — manual comparison recommended";
            if ($faceMatch === 'skipped')                         $softConcerns[] = "Face match could not run — review photos manually";
            
            // OCR is disabled - add notice
            $softConcerns[] = "OCR verification is DISABLED — please manually verify ID card text";

            // ------------------------------------------------------------------
            // SIMPLE AUTO-APPROVAL (OCR DISABLED)
            // Only approves if ALL hard checks pass AND:
            //   - Score >= 70
            //   - No OCR required (always true since disabled)
            //   - Face match confirmed
            //
            // This is a stricter, simpler approval system that relies on
            // manual verification of the actual ID text content.
            // ------------------------------------------------------------------
            $canApprove = false;
            $approvalNote = '';

            if (!$hardBlocked && $score >= 70 && $faceMatch === 'match') {
                $canApprove   = true;
                $approvalNote = "Auto-approved (OCR DISABLED). "
                    . "Confidence: {$score}%. "
                    . "Face match: confirmed. "
                    . "ID structure: {$idScore}/100. "
                    . "No duplicates. Fraud risk: {$fraud}. "
                    . "⚠️ IMPORTANT: Manual verification of ID card text (name & birthdate) is required before enrollment.";
            }

            // ------------------------------------------------------------------
            // EXECUTE
            // ------------------------------------------------------------------
            if ($canApprove) {
                $this->updateStatus($id, 'approved', $adminId, $approvalNote);
                $processed['auto_approved'][] = [
                    'id'          => $id,
                    'score'       => $score,
                    'ocr_summary' => $ocrSummary,
                ];
            } elseif ($hardBlocked) {
                // Send to flagged / manual-review queue with clear reasons
                $processed['flagged'][] = [
                    'id'     => $id,
                    'score'  => $score,
                    'reason' => implode('; ', $flagReasons),
                ];
            } else {
                // Score or face match not strong enough — needs human review
                $reviewNote = !empty($softConcerns) ? implode('; ', $softConcerns) : 'Confidence below auto-approval threshold or face match inconclusive';
                $processed['needs_review'][] = [
                    'id'          => $id,
                    'score'       => $score,
                    'ocr_summary' => $ocrSummary,
                    'note'        => $reviewNote,
                ];
            }
            $processed['total']++;
        }
        return $processed;
    }

    public function analyzeWithAI($preregId) { return $this->smartApproval->analyzePreregistration($preregId); }
    public function getAIAnalysis($preregId) { return $this->smartApproval->getAnalysis($preregId); }

    private function sendApprovalEmail($prereg) {
        if (empty($prereg['email'])) return false;
        $link = "https://".$_SERVER['HTTP_HOST']."/access-enrollment.php?code=".urlencode($prereg['access_code']??'')."&tracking=".urlencode($prereg['tracking_code']??'');
        $name = htmlspecialchars($prereg['first_name'].' '.$prereg['last_name']);
        $track= htmlspecialchars($prereg['tracking_code']??'');
        $subj = "✓ ALS Pre-registration Approved";
        $html = "<!DOCTYPE html><html><head><style>body{font-family:'Segoe UI',Arial,sans-serif;line-height:1.6;color:#333}.container{max-width:600px;margin:0 auto;padding:20px}.header{background:linear-gradient(135deg,#1e4d9b,#1a3a6e);color:white;padding:30px;text-align:center;border-radius:10px 10px 0 0}.content{background:#f8fafc;padding:30px;border-radius:0 0 10px 10px;border:1px solid #e2e8f0}.btn{display:inline-block;background:linear-gradient(135deg,#10b981,#059669);color:white;padding:14px 35px;text-decoration:none;border-radius:8px;margin:20px 0;font-weight:bold}.tracking-box{background:#e2e8f0;padding:15px;text-align:center;border-radius:8px;margin:20px 0}.tracking-code{font-family:monospace;font-size:20px;font-weight:bold;color:#1e4d9b}</style></head><body><div class='container'><div class='header'><h2>🎉 Congratulations, {$name}!</h2><p>Your ALS Pre-registration has been <span style='color:#34d399;'>APPROVED</span></p></div><div class='content'><p>Dear <strong>{$name}</strong>,</p><p>Your pre-registration for the <strong>Alternative Learning System (ALS)</strong> has been approved.</p><div class='tracking-box'><strong>📋 Tracking Code:</strong><br><span class='tracking-code'>{$track}</span></div><p style='text-align:center;'><a href='{$link}' class='btn'>📝 Complete Enrollment →</a></p></div></div></body></html>";
        $h = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: ALS System <noreply@als-system.online>\r\nReply-To: als.lacarlota@deped.gov.ph\r\n";
        $r = mail($prereg['email'],$subj,$html,$h);
        error_log("Approval email to {$prereg['email']}: ".($r?"SENT":"FAILED ")); return $r;
    }
}

// ============================================================
// SESSION
// ============================================================
if (!function_exists('secure_session_start')) {
    function secure_session_start() {
        if (session_status()===PHP_SESSION_NONE) {
            if (!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on') ini_set('session.cookie_secure',1);
            ini_set('session.cookie_httponly',1); ini_set('session.use_only_cookies',1); ini_set('session.cookie_samesite','Strict'); session_start();
        }
        if (!isset($_SESSION['last_regeneration'])) { $_SESSION['last_regeneration']=time(); }
        elseif (time()-$_SESSION['last_regeneration']>1800) { session_regenerate_id(true); $_SESSION['last_regeneration']=time(); }
    }
}
secure_session_start();

if (!isset($_SESSION['admin_id'])||!function_exists('is_admin_logged_in')||!is_admin_logged_in()) {
    header('Location: index.php'); exit;
}

$security      = new SecurityManager($conn);
$cache         = new CacheManager();
$notification  = new NotificationManager($conn);
$smartApproval = new SmartApprovalSystem($conn,$security);
$preregManager = new PreregistrationManager($conn,$cache,$security,$notification,$smartApproval);
$csrf_token    = $security->generateCSRFToken();

$message = ''; $message_type = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (isset($_POST['ajax_action'])&&$_POST['ajax_action']==='auto_process') {
        header('Content-Type: application/json');
        echo json_encode(['success'=>true,'result'=>$preregManager->autoProcessPending($_SESSION['admin_id'],20)]); exit;
    }
    if (isset($_POST['ajax_action'])&&$_POST['ajax_action']==='smart_analyze') {
        header('Content-Type: application/json');
        $prereg_id=(int)($_POST['prereg_id']??0);
        if ($prereg_id<1) { echo json_encode(['error'=>'Invalid ID']); exit; }
        echo json_encode($preregManager->analyzeWithAI($prereg_id)); exit;
    }
    if (!isset($_POST['csrf_token'])||!$security->validateCSRFToken($_POST['csrf_token'])) {
        $message='Invalid security token.'; $message_type='error';
    } elseif (isset($_POST['action'])) {
        if (!$security->checkRateLimit('prereg_action',$_SESSION['admin_id'],50,300)) { $message='Too many actions.'; $message_type='error'; }
        elseif (isset($_POST['prereg_id'])) {
            $prereg_id=(int)$_POST['prereg_id']; $action=$security->sanitizeInput($_POST['action']); $notes=$security->sanitizeInput($_POST['notes']??'');
            $result=$preregManager->updateStatus($prereg_id,$action,$_SESSION['admin_id'],$notes);
            $message=$result['message']; $message_type=$result['success']?'success':'error';
        }
    } elseif (isset($_POST['bulk_action'])) {
        $ids=isset($_POST['selected_ids'])?array_map('intval',$_POST['selected_ids']):[];
        $ba=$security->sanitizeInput($_POST['bulk_action']);
        $result=$preregManager->bulkUpdate($ids,$ba,$_SESSION['admin_id']);
        $message=$result['message']; $message_type=$result['success']?'success':'error';
    }
}

$status_filter = isset($_GET['status'])?$security->sanitizeInput($_GET['status']):'pending';
$search        = isset($_GET['search'])?$security->sanitizeInput($_GET['search']):'';
$page          = max(1,(int)($_GET['page']??1));
$listResult    = $preregManager->getList(['status'=>$status_filter,'search'=>$search,'page'=>$page,'limit'=>20]);
$preregistrations = $listResult['data'];
$total_records = $listResult['total'];
$total_pages   = ceil($total_records/$listResult['limit']);
$counts        = $preregManager->getCounts();
$unread_count  = $preregManager->getUnreadCount($_SESSION['admin_id']);
$upload_web_path = '/uploads/preregistration/';

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pre-registrations - ALS Admin | Smart Approval (OCR DISABLED)</title>
    <meta name="csrf-token" content="<?php echo $csrf_token; ?>">
    <link rel="icon" type="image/png" href="/logo">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        :root{--primary:#1d4ed8;--primary-dark:#1e3a8a;--success:#059669;--warning:#d97706;--error:#dc2626;--ocr:#7c3aed;--bg:#f0f4ff;--border:#e2e8f0;--text:#0f172a;--text-light:#64748b}
        body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);display:flex}
        .sidebar{width:270px;background:white;height:100vh;position:fixed;border-right:1px solid var(--border);overflow-y:auto}
        .main{flex:1;margin-left:270px;padding:30px}
        .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:30px;flex-wrap:wrap;gap:15px}
        .header h1{font-size:1.8rem;font-weight:700;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
        #autoStatusBar{display:none;align-items:center;gap:10px;background:linear-gradient(135deg,#1d4ed8,#7c3aed);color:white;padding:10px 18px;border-radius:10px;margin-bottom:18px;font-size:.85rem;font-weight:600;animation:slideIn .3s ease}
        #autoStatusBar.show{display:flex}
        @keyframes slideIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
        .auto-spinner{width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top:2px solid white;border-radius:50%;animation:spin .8s linear infinite;flex-shrink:0}
        @keyframes spin{to{transform:rotate(360deg)}}
        .auto-result-pill{background:rgba(255,255,255,.2);border-radius:20px;padding:3px 10px;font-size:.78rem}
        .live-badge{display:inline-flex;align-items:center;gap:6px;font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--success);background:#d1fae5;padding:4px 10px;border-radius:20px;vertical-align:middle}
        .live-dot{width:7px;height:7px;background:var(--success);border-radius:50%;animation:pulse 1.6s ease-in-out infinite}
        @keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.7)}}
        .alert{padding:15px 20px;border-radius:8px;margin-bottom:20px}
        .alert-success{background:#d1fae5;border-left:4px solid var(--success);color:#065f46}
        .alert-error{background:#fee2e2;border-left:4px solid var(--error);color:#991b1b}

        /* OCR Disabled Notice */
        .ocr-notice{display:flex;align-items:flex-start;gap:10px;background:linear-gradient(135deg,#fff3e0,#fef3c7);border:1px solid #fde68a;border-left:4px solid var(--warning);border-radius:8px;padding:12px 16px;margin-bottom:18px;font-size:.82rem;color:#92400e}
        .ocr-badge-disabled{display:inline-flex;align-items:center;gap:4px;background:var(--warning);color:white;padding:2px 8px;border-radius:12px;font-size:.7rem;font-weight:700;white-space:nowrap}

        .tabs{display:flex;gap:10px;margin-bottom:20px;border-bottom:2px solid var(--border);padding-bottom:10px;flex-wrap:wrap}
        .tab{padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:600;color:var(--text-light);transition:all .2s}
        .tab:hover{background:#e2e8f0;color:var(--text)}
        .tab.active{background:var(--primary);color:white}
        .search-bar{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap}
        .search-bar input{flex:1;padding:12px 15px;border:1px solid var(--border);border-radius:8px;font-size:.9rem;min-width:200px}
        .search-bar button,.search-bar a{padding:12px 25px;background:var(--primary);color:white;border:none;border-radius:8px;cursor:pointer;text-decoration:none;display:inline-block}
        .search-bar a{background:var(--text-light)}
        .bulk-actions{display:flex;gap:10px;margin-bottom:20px;padding:15px;background:white;border-radius:8px;border:1px solid var(--border);flex-wrap:wrap;align-items:center}
        .bulk-actions select{padding:8px 12px;border:1px solid var(--border);border-radius:8px}
        .bulk-actions button{padding:8px 20px;background:var(--primary);color:white;border:none;border-radius:8px;cursor:pointer}
        .table-container{background:white;border-radius:8px;border:1px solid var(--border);overflow-x:auto}
        table{width:100%;border-collapse:collapse;min-width:600px}
        th{text-align:left;padding:15px;background:#f8fafc;border-bottom:2px solid var(--border);font-size:.8rem;font-weight:700;color:var(--text-light);text-transform:uppercase}
        td{padding:15px;border-bottom:1px solid var(--border);transition:background .15s}
        tr:hover td{background:#f8fafc}
        tr.auto-approved td{background:#d1fae5!important}
        tr.auto-flagged td{background:#fee2e2!important}
        tr.auto-review td{background:#fef3c7!important}
        .status-badge{padding:5px 10px;border-radius:20px;font-size:.75rem;font-weight:600;display:inline-block}
        .status-pending{background:#fef3c7;color:#92400e}
        .status-approved{background:#d1fae5;color:#065f46}
        .status-rejected{background:#fee2e2;color:#991b1b}
        .action-buttons{display:flex;gap:5px;flex-wrap:wrap}
        .btn{padding:6px 12px;border-radius:6px;border:none;cursor:pointer;font-size:.8rem;transition:all .2s}
        .btn-view{background:var(--primary);color:white}
        .btn-approve{background:var(--success);color:white}
        .btn-reject{background:var(--error);color:white}
        .btn-smart{background:linear-gradient(135deg,#667eea,#764ba2);color:white}
        .btn:hover{opacity:.8;transform:translateY(-1px)}
        .btn-export{background:#28a745;color:white;padding:8px 15px;border-radius:6px;border:none;cursor:pointer;font-size:.85rem;margin-left:10px}
        .confidence-score{display:inline-block;padding:2px 8px;border-radius:12px;font-size:.72rem;font-weight:700}
        .score-high{background:#d1fae5;color:#065f46}
        .score-medium{background:#fef3c7;color:#92400e}
        .score-low{background:#fee2e2;color:#991b1b}
        .score-none{background:#f1f5f9;color:#64748b}
        .rec-tag{display:inline-block;font-size:.65rem;font-weight:700;padding:1px 6px;border-radius:10px;margin-left:4px;vertical-align:middle}
        .rec-approve{background:#bbf7d0;color:#065f46}
        .rec-flag{background:#fee2e2;color:#991b1b}
        .rec-review{background:#fef3c7;color:#92400e}
        .ocr-disabled-tag{display:inline-block;font-size:.65rem;font-weight:700;padding:1px 6px;border-radius:10px;margin-left:2px;vertical-align:middle;background:#fef3c7;color:#92400e}

        .modal{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);align-items:center;justify-content:center;z-index:1000}
        .modal.active{display:flex}
        .modal-content{background:white;border-radius:16px;max-width:90%;width:960px;max-height:90vh;overflow-y:auto;box-shadow:0 24px 60px rgba(0,0,0,.25)}
        .modal-header{display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:1px solid var(--border);position:sticky;top:0;background:white;z-index:10}
        .modal-close{background:none;border:none;font-size:1.5rem;cursor:pointer;color:var(--text-light)}
        .modal-body{padding:24px}
        .modal-section-title{font-size:.85rem;font-weight:700;color:var(--primary);margin:20px 0 12px;padding-bottom:8px;border-bottom:2px solid #eff6ff}
        .info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px}
        .info-item{background:#f8fafc;border-radius:8px;padding:12px 14px}
        .info-label{font-size:.72rem;color:var(--text-light);font-weight:600;text-transform:uppercase;margin-bottom:4px}
        .info-value{font-weight:600;font-size:.9rem;color:var(--text)}
        .photo-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-top:4px}
        .photo-card{border:1px solid var(--border);border-radius:10px;overflow:hidden;background:#f8fafc}
        .photo-card-header{padding:10px 14px;background:#eff6ff;border-bottom:1px solid #dbeafe;font-weight:700;font-size:.85rem}
        .photo-card-body{padding:12px;text-align:center}
        .photo-card-body img{max-width:100%;max-height:200px;object-fit:contain;border-radius:6px;cursor:pointer}

        .pagination{display:flex;justify-content:center;gap:8px;margin-top:20px;padding:15px;flex-wrap:wrap}
        .pagination a,.pagination span{padding:8px 12px;border:1px solid var(--border);border-radius:6px;text-decoration:none;color:var(--text)}
        .pagination a:hover{background:var(--primary);color:white;border-color:var(--primary)}
        .pagination .active{background:var(--primary);color:white;border-color:var(--primary)}
        .loading-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);display:none;justify-content:center;align-items:center;z-index:10000}
        .loading-spinner{width:50px;height:50px;border:3px solid #f3f3f3;border-top:3px solid var(--primary);border-radius:50%;animation:spin 1s linear infinite}
        .notification-dot{width:8px;height:8px;background:var(--error);border-radius:50%;display:inline-block;margin-left:5px}
        .verified-badge{background:#d1fae5;color:#065f46;padding:2px 6px;border-radius:10px;font-size:.7rem;display:inline-block;margin-top:4px}
        .risk-indicator{display:inline-block;padding:4px 8px;border-radius:20px;font-size:.75rem;font-weight:600}
        .risk-low{background:#d1fae5;color:#065f46}
        .risk-medium{background:#fef3c7;color:#92400e}
        .risk-high{background:#fee2e2;color:#991b1b}

        #autoLogDrawer{position:fixed;bottom:0;right:20px;width:360px;background:white;border-radius:12px 12px 0 0;box-shadow:0 -4px 24px rgba(0,0,0,.12);transform:translateY(100%);transition:transform .35s ease;z-index:900}
        #autoLogDrawer.open{transform:translateY(0)}
        #autoLogDrawer .drawer-header{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:linear-gradient(135deg,#1d4ed8,#7c3aed);color:white;border-radius:12px 12px 0 0;cursor:pointer;font-weight:700;font-size:.85rem}
        #autoLogDrawer .drawer-body{max-height:220px;overflow-y:auto;padding:12px}
        .log-entry{padding:6px 0;border-bottom:1px solid var(--border);font-size:.78rem}
        .log-entry:last-child{border-bottom:none}
        .log-approve{color:var(--success)}
        .log-flag{color:var(--error)}
        .log-review{color:var(--warning)}

        @media(max-width:768px){.sidebar{display:none}.main{margin-left:0}.header h1{font-size:1.4rem}#autoLogDrawer{width:100%;right:0}}
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="loading-overlay" id="loadingOverlay"><div class="loading-spinner"></div></div>

    <!-- Lightbox -->
    <div id="lightbox" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:9999;align-items:center;justify-content:center;">
        <button onclick="closeLightbox()" style="position:absolute;top:20px;right:24px;background:rgba(255,255,255,.15);border:none;color:white;font-size:1.8rem;cursor:pointer;width:44px;height:44px;border-radius:50%;">&times;</button>
        <img id="lightbox-img" style="max-width:90vw;max-height:90vh;object-fit:contain;border-radius:8px;">
        <div id="lightbox-label" style="position:absolute;bottom:20px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.6);color:white;padding:6px 16px;border-radius:20px;"></div>
    </div>

    <!-- Auto Log Drawer -->
    <div id="autoLogDrawer">
        <div class="drawer-header" onclick="toggleDrawer()">
            <span><i class="fas fa-robot"></i> Smart Approval Log (OCR DISABLED)</span>
            <span id="drawerBadge" style="background:rgba(255,255,255,.25);padding:2px 8px;border-radius:12px;font-size:.75rem;"></span>
        </div>
        <div class="drawer-body" id="autoLogBody">
            <div style="color:var(--text-light);font-size:.8rem;text-align:center;padding:20px;">Waiting for auto-processing...</div>
        </div>
    </div>

    <div class="main">
        <div class="header">
            <h1>
                Pre-registrations
                <?php if ($unread_count>0): ?>
                <span style="background:var(--error);color:white;padding:5px 10px;border-radius:20px;font-size:.9rem;"><?php echo $unread_count; ?> new</span>
                <?php endif; ?>
                <span class="live-badge" id="live-indicator"><span class="live-dot"></span> Auto ON | OCR OFF</span>
            </h1>
            <div>
                <button onclick="runAutoProcess(true)" style="background:linear-gradient(135deg,#667eea,#764ba2);color:white;padding:8px 16px;border-radius:8px;border:none;cursor:pointer;font-size:.85rem;">
                    <i class="fas fa-robot"></i> Run Now
                </button>
                <button onclick="exportData('csv')" class="btn-export"><i class="fas fa-file-csv"></i> Export CSV</button>
                <a href="/AdminSettings#preregistration" class="btn btn-view"><i class="fas fa-cog"></i> Settings</a>
            </div>
        </div>

        <!-- OCR Disabled Notice -->
        <div class="ocr-notice">
            <i class="fas fa-eye-slash" style="margin-top:2px;flex-shrink:0;color:var(--warning);"></i>
            <div>
                <strong><span class="ocr-badge-disabled"><i class="fas fa-ban"></i> OCR DISABLED</span> &nbsp; Manual Verification Required:</strong>
                The OCR text extraction feature is currently <strong>DISABLED</strong>. 
                The system automatically checks image quality, face matching, and form data consistency.
                <strong>All ID card text (name, birthdate, ID numbers) must be verified manually</strong> before final approval.
                Auto-approval only happens when ALL checks pass and face match is confirmed.
            </div>
        </div>

        <div id="autoStatusBar">
            <div class="auto-spinner"></div>
            <span id="autoStatusText">Running Smart Approval System...</span>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <i class="fas fa-<?php echo $message_type==='success'?'check-circle':'exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>

        <div class="tabs">
            <a href="?status=pending&page=1"  class="tab <?php echo $status_filter==='pending' ?'active':''; ?>">Pending  (<span id="count-pending"><?php  echo $counts['pending'];  ?></span>)</a>
            <a href="?status=approved&page=1" class="tab <?php echo $status_filter==='approved'?'active':''; ?>">Approved (<span id="count-approved"><?php echo $counts['approved']; ?></span>)</a>
            <a href="?status=rejected&page=1" class="tab <?php echo $status_filter==='rejected'?'active':''; ?>">Rejected (<span id="count-rejected"><?php echo $counts['rejected']; ?></span>)</a>
            <a href="?status=all&page=1"      class="tab <?php echo $status_filter==='all'     ?'active':''; ?>">All      (<span id="count-all"><?php      echo $counts['total'];    ?></span>)</a>
        </div>

        <form method="GET" class="search-bar">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
            <input type="hidden" name="page"   value="1">
            <input type="text"   name="search" placeholder="Search by name, tracking code, or email..." value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit"><i class="fas fa-search"></i> Search</button>
            <?php if ($search): ?><a href="?status=<?php echo $status_filter; ?>&page=1"><i class="fas fa-times"></i> Clear</a><?php endif; ?>
        </form>

        <form method="POST" id="bulkForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <div class="bulk-actions">
                <select name="bulk_action" id="bulk_action">
                    <option value="">Bulk Actions</option>
                    <option value="approve">Approve Selected</option>
                    <option value="reject">Reject Selected</option>
                    <option value="delete">Delete Selected</option>
                </select>
                <button type="submit">Apply</button>
                <span id="selectedCount" style="margin-left:auto;font-size:.85rem;color:var(--text-light);"></span>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th width="30"><input type="checkbox" id="selectAll" onclick="toggleAll(this)"></th>
                            <th>Tracking Code</th>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Barangay</th>
                            <th>Submitted</th>
                            <th>Status</th>
                            <th>AI Score</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="preregTableBody">
                        <?php if (!empty($preregistrations)): ?>
                            <?php foreach ($preregistrations as $row): ?>
                            <?php
                                $aiScore    = $row['ai_confidence']     ?? null;
                                $aiRec      = $row['ai_recommendation'] ?? null;
                                $scoreClass = is_null($aiScore)?'score-none':($aiScore>=70?'score-high':($aiScore>=50?'score-medium':'score-low'));
                                $recClass   = $aiRec==='approve'?'rec-approve':($aiRec==='flag'?'rec-flag':'rec-review');
                            ?>
                            <tr data-id="<?php echo $row['preregistration_id']; ?>">
                                <td><input type="checkbox" name="selected_ids[]" value="<?php echo $row['preregistration_id']; ?>" class="rowCheckbox" onclick="updateSelectedCount()"></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['tracking_code']); ?></strong>
                                    <?php if (!empty($row['unread_notifications'])&&$row['unread_notifications']>0): ?><span class="notification-dot"></span><?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($row['last_name'].', '.$row['first_name']); ?>
                                    <?php if (!empty($row['middle_name'])): ?><br><small><?php echo htmlspecialchars($row['middle_name']); ?></small><?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($row['contact_number']); ?>
                                    <br><small><?php echo htmlspecialchars($row['email']); ?></small>
                                </td>
                                <td><?php
                                    if (!empty($row['barangay_name'])) echo htmlspecialchars($row['barangay_name']);
                                    elseif (!empty($row['current_custom_barangay'])) echo htmlspecialchars($row['current_custom_barangay']).' <small>(custom)</small>';
                                    else echo 'N/A';
                                ?></td>
                                <td>
                                    <?php echo date('M d, Y',strtotime($row['submitted_at'])); ?>
                                    <br><small><?php echo date('h:i A',strtotime($row['submitted_at'])); ?></small>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $row['status']; ?>"><?php echo ucfirst($row['status']); ?></span>
                                    <?php if (!empty($row['verified_at'])): ?><br><span class="verified-badge">✓ Verified</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!is_null($aiScore)): ?>
                                        <span class="confidence-score <?php echo $scoreClass; ?>"><?php echo $aiScore; ?>%</span>
                                        <?php if ($aiRec): ?><span class="rec-tag <?php echo $recClass; ?>"><?php echo strtoupper($aiRec); ?></span><?php endif; ?>
                                        <span class="ocr-disabled-tag" title="OCR is disabled - manual verification required"><i class="fas fa-ban"></i> OCR OFF</span>
                                    <?php else: ?>
                                        <span class="confidence-score score-none">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="btn btn-view" onclick='viewDetails(<?php echo json_encode($row,JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fas fa-eye"></i></button>
                                        <?php if ($row['status']==='pending'): ?>
                                        <button type="button" class="btn btn-smart" onclick="smartReview(<?php echo $row['preregistration_id']; ?>)" title="AI Analysis"><i class="fas fa-robot"></i></button>
                                        <button type="button" class="btn btn-approve" onclick="quickAction(<?php echo $row['preregistration_id']; ?>,'approved')"><i class="fas fa-check"></i></button>
                                        <button type="button" class="btn btn-reject"  onclick="quickAction(<?php echo $row['preregistration_id']; ?>,'rejected')"><i class="fas fa-times"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" style="text-align:center;padding:40px;"><i class="fas fa-inbox" style="font-size:2rem;color:var(--text-light);opacity:.3;margin-bottom:10px;display:block;"></i>No pre-registrations found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages>1): ?>
            <div class="pagination">
                <?php if ($page>1): ?><a href="?status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $page-1; ?>"><i class="fas fa-chevron-left"></i> Prev</a><?php endif; ?>
                <?php $sp=max(1,$page-2); $ep=min($total_pages,$page+2);
                if ($sp>1): ?><a href="?status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>&page=1">1</a><?php if ($sp>2): ?><span>...</span><?php endif; endif;
                for ($i=$sp;$i<=$ep;$i++): ?><a href="?status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $i; ?>" class="<?php echo $i==$page?'active':''; ?>"><?php echo $i; ?></a><?php endfor;
                if ($ep<$total_pages): if ($ep<$total_pages-1): ?><span>...</span><?php endif; ?><a href="?status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $total_pages; ?>"><?php echo $total_pages; ?></a><?php endif; ?>
                <?php if ($page<$total_pages): ?><a href="?status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $page+1; ?>">Next <i class="fas fa-chevron-right"></i></a><?php endif; ?>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modal-title">Pre-registration Details</h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalContent"></div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    const CSRF_TOKEN       = <?php echo json_encode($csrf_token); ?>;
    const UPLOAD_WEB_PATH  = <?php echo json_encode($upload_web_path); ?>;
    const AUTO_INTERVAL_MS = 2 * 60 * 1000;
    let autoTimer = null, drawerOpen = false, totalAutoApproved = 0;

  
    async function runAutoProcess(manual=false) {
        const bar=document.getElementById('autoStatusBar'), tx=document.getElementById('autoStatusText');
        bar.classList.add('show');
        tx.textContent=manual?'🤖 Running Smart Approval manually...':'🤖 Auto Smart Approval scanning...';
        try {
            const fd=new FormData(); fd.append('ajax_action','auto_process'); fd.append('csrf_token',CSRF_TOKEN);
            const resp=await fetch(window.location.href,{method:'POST',body:fd});
            const json=await resp.json(); const result=json.result;
            if (!result||result.total===0) { tx.textContent='✅ No new pending registrations to process.'; setTimeout(()=>bar.classList.remove('show'),3000); return; }
            const parts=[];
            if (result.auto_approved.length) parts.push(`<span class="auto-result-pill">${result.auto_approved.length} Auto-Approved</span>`);
            if (result.needs_review.length)  parts.push(`<span class="auto-result-pill"> ${result.needs_review.length} For Review</span>`);
            if (result.flagged.length)        parts.push(`<span class="auto-result-pill">${result.flagged.length} Flagged</span>`);
            bar.innerHTML=`<i class="fas fa-robot" style="font-size:1.1rem;"></i> Smart Approval Done: ${parts.join(' ')} <button onclick="document.getElementById('autoStatusBar').classList.remove('show')" style="margin-left:auto;background:rgba(255,255,255,.2);border:none;color:white;cursor:pointer;border-radius:6px;padding:3px 8px;">✕</button>`;
            appendToLog(result); highlightRows(result);
            if (result.auto_approved.length>0) setTimeout(()=>location.reload(),2000);
            else setTimeout(()=>bar.classList.remove('show'),6000);
        } catch(err) { bar.classList.remove('show'); if (manual) showToast('error','Auto-Process Failed',err.message); }
    }

    function appendToLog(result) {
        const body=document.getElementById('autoLogBody'), time=new Date().toLocaleTimeString();
        if (body.querySelector('[style*="padding:20px"]')) body.innerHTML='';
        result.auto_approved.forEach(r=>{ body.insertAdjacentHTML('afterbegin',`<div class="log-entry log-approve"><strong>✅ Auto-Approved</strong> ID #${r.id} — Score: ${r.score}% <span style="font-size:.7rem;color:#6b7280;">OCR OFF</span> <span style="float:right;color:var(--text-light);">${time}</span></div>`); totalAutoApproved++; });
        result.flagged.forEach(r=>{ body.insertAdjacentHTML('afterbegin',`<div class="log-entry log-flag"><strong>⚠️ Flagged</strong> ID #${r.id} — ${escapeHtml(r.reason)} <span style="float:right;color:var(--text-light);">${time}</span></div>`); });
        result.needs_review.forEach(r=>{ body.insertAdjacentHTML('afterbegin',`<div class="log-entry log-review"><strong>🔍 Review</strong> ID #${r.id} — ${escapeHtml(r.note||'')} <span style="float:right;color:var(--text-light);">${time}</span></div>`); });
        document.getElementById('drawerBadge').textContent=totalAutoApproved+' approved';
        if (!drawerOpen) { document.getElementById('autoLogDrawer').classList.add('open'); drawerOpen=true; }
    }

    function highlightRows(result) {
        result.auto_approved.forEach(r=>{ const row=document.querySelector(`tr[data-id="${r.id}"]`); if (row) row.classList.add('auto-approved'); });
        result.flagged.forEach(r=>{       const row=document.querySelector(`tr[data-id="${r.id}"]`); if (row) row.classList.add('auto-flagged'); });
        result.needs_review.forEach(r=>{ const row=document.querySelector(`tr[data-id="${r.id}"]`); if (row) row.classList.add('auto-review'); });
    }
    function toggleDrawer() { drawerOpen=!drawerOpen; document.getElementById('autoLogDrawer').classList.toggle('open',drawerOpen); }

    // --- Manual AI Analysis -------------------------------------------------
    async function smartReview(preregId) {
        Swal.fire({
            title:'🤖 AI Analysis (OCR Disabled)',
            html:`<div style="margin:20px auto;width:44px;height:44px;border:3px solid #e2e8f0;border-top:3px solid #7c3aed;border-radius:50%;animation:spin 0.8s linear infinite;"></div>
                  <p style="margin-top:12px;color:#64748b;">Analyzing images and validating data...</p>
                  <style>@keyframes spin{to{transform:rotate(360deg)}}</style>`,
            allowOutsideClick:false, showConfirmButton:false
        });
        try {
            const fd=new FormData(); fd.append('ajax_action','smart_analyze'); fd.append('prereg_id',preregId); fd.append('csrf_token',CSRF_TOKEN);
            const resp=await fetch(window.location.href,{method:'POST',body:fd});
            const analysis=await resp.json(); Swal.close();
            if (analysis.error) { showToast('error','Analysis Failed',analysis.error); return; }
            await showSmartAnalysisResults(analysis,preregId);
        } catch(err) { Swal.close(); showToast('error','Error','Failed: '+err.message); }
    }

    async function showSmartAnalysisResults(analysis, preregId) {
        const score=analysis.confidence_score;
        const scoreColor=score>=80?'#059669':score>=60?'#d97706':'#dc2626';
        const rec=analysis.recommendation;
        const fraud=analysis.fraud_detection;
        const riskClass=fraud.risk_level==='high'?'risk-high':fraud.risk_level==='medium'?'risk-medium':'risk-low';
        const imgStatus=analysis.image_analysis;
        const ocr=analysis.ocr_analysis||null;

        // Images
        const mkImg=(url,label,icon)=>url
            ?`<div style="text-align:center;"><strong style="font-size:.8rem;">${label}</strong><div style="margin-top:6px;"><img src="${url}" style="max-width:100%;max-height:140px;object-fit:contain;border-radius:8px;border:1px solid #e2e8f0;cursor:pointer;" onclick="window.open('${url}','_blank')"></div></div>`
            :`<div style="text-align:center;padding:20px;background:#f8fafc;border-radius:8px;font-size:.8rem;color:#94a3b8;"><i class="${icon}" style="font-size:1.8rem;display:block;margin-bottom:6px;"></i>No ${label}</div>`;

        const imagesHtml=`<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-top:12px;">
            ${mkImg(analysis.image_urls?.selfie,'Selfie','fas fa-user-slash')}
            ${mkImg(analysis.image_urls?.valid_id,'Front ID','fas fa-id-card-alt')}
            ${mkImg(analysis.image_urls?.valid_id_back,'Back ID','fas fa-id-card')}
        </div>`;

        // OCR Disabled notice
        const ocrHtml=`<div style="background:linear-gradient(135deg,#fff3e0,#fef3c7);border:1px solid #fde68a;border-left:4px solid #d97706;border-radius:10px;padding:14px;margin-top:12px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                <i class="fas fa-ban" style="color:#d97706;"></i>
                <strong style="color:#92400e;">OCR Verification DISABLED</strong>
                <span style="margin-left:auto;font-size:.75rem;color:#92400e;">Manual verification required</span>
            </div>
            <div style="padding:10px;background:white;border-radius:8px;font-size:.8rem;color:#64748b;">
                <i class="fas fa-exclamation-triangle" style="color:#d97706;"></i>
                The OCR text extraction feature is currently disabled. Please manually verify that:
                <ul style="margin:8px 0 0 20px;">
                    <li>The ID card contains the applicant's name</li>
                    <li>The birthdate on the ID matches the submitted birthdate</li>
                    <li>The ID card is valid and not expired</li>
                </ul>
            </div>
        </div>`;

        const fm=imgStatus.face_match?.status||'skipped';
        const fmConf=imgStatus.face_match?.confidence||0;
        const fmIcon=fm==='match'?'✅':fm==='mismatch'?'❌':'🔍';
        const fmColor=fm==='match'?'#059669':fm==='mismatch'?'#dc2626':'#d97706';

        const verHtml=rec.verification_needed?.length
            ?`<div style="margin-top:12px;padding:12px;background:#fff3e0;border-radius:8px;border-left:3px solid #ff9800;"><strong>📋 Needs Manual Verification:</strong><ul style="margin:8px 0 0 18px;">${rec.verification_needed.map(i=>`<li style="font-size:.82rem;">${escapeHtml(i)}</li>`).join('')}</ul></div>`:'';

        const warnHtml=analysis.data_validation.warnings?.length
            ?`<div style="margin-top:10px;padding:10px;background:#fef3c7;border-radius:8px;border-left:3px solid #f59e0b;font-size:.82rem;"><strong>⚠️ Warnings:</strong><ul style="margin:6px 0 0 16px;">${analysis.data_validation.warnings.map(w=>`<li>${escapeHtml(w)}</li>`).join('')}</ul></div>`:'';

        const dupHtml=analysis.duplicate_check.found
            ?`<div style="margin-top:10px;padding:10px;background:#fee2e2;border-radius:8px;border-left:3px solid #dc2626;font-size:.82rem;"><strong>⚠️ Duplicate Name</strong><ul style="margin:6px 0 0 16px;">${analysis.duplicate_check.matches.map(m=>`<li>${escapeHtml(m.value)}</li>`).join('')}</ul></div>`:'';

        const res=await Swal.fire({
            title:'🤖 AI Analysis Results (OCR Disabled)',
            html:`<div style="text-align:left;max-height:72vh;overflow-y:auto;padding-right:6px;">
                <div style="text-align:center;margin-bottom:14px;">
                    <div style="font-size:50px;font-weight:800;color:${scoreColor};">${score}%</div>
                    <div style="color:#64748b;font-size:.82rem;">Confidence Score</div>
                </div>
                <div style="padding:12px;background:linear-gradient(135deg,#667eea,#764ba2);color:white;border-radius:10px;margin-bottom:10px;font-size:.85rem;">
                    <strong>Recommendation:</strong> ${rec.action.toUpperCase()} — ${escapeHtml(rec.reason.substring(0,180))}${rec.reason.length>180?'...':''}
                </div>
                <div style="margin-bottom:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <span class="${riskClass} risk-indicator">Fraud: ${fraud.risk_level.toUpperCase()} (${fraud.score}pts)</span>
                </div>
                ${imagesHtml}
                ${ocrHtml}
                <div style="margin-top:12px;padding:10px;background:#f8fafc;border-radius:8px;font-size:.8rem;">
                    <strong>Face Match:</strong> ${fmIcon} <span style="color:${fmColor};">${fm} ${fmConf?'('+fmConf+'% sim)':''}</span>
                    ${imgStatus.face_match?.notes?`<span style="color:#64748b;"> — ${escapeHtml(imgStatus.face_match.notes)}</span>`:''}
                </div>
                ${verHtml}${warnHtml}${dupHtml}
                <div style="margin-top:14px;font-size:.72rem;color:#94a3b8;border-top:1px solid #e2e8f0;padding-top:8px;">
                    <i class="fas fa-ban" style="color:#d97706;"></i> OCR is DISABLED — Manual ID text verification required. Analysis: ${analysis.timestamp}
                </div>
            </div>`,
            width:'780px',
            showCancelButton:true,
            confirmButtonText:'✅ Approve',
            cancelButtonText:'❌ Reject',
            showDenyButton:true,
            denyButtonText:'🔍 Manual Review',
            confirmButtonColor:'#059669',
            cancelButtonColor:'#dc2626',
            denyButtonColor:'#1d4ed8'
        });
        if (res.isConfirmed) quickAction(preregId,'approved');
        else if (res.dismiss===Swal.DismissReason.cancel) quickAction(preregId,'rejected');
        else if (res.isDenied) { const row=document.querySelector(`tr[data-id="${preregId}"]`); if (row) { const vb=row.querySelector('.btn-view'); if (vb) vb.click(); } }
    }

    async function quickAction(id,action) {
        const label=action==='approved'?'approve':'reject';
        const res=await Swal.fire({title:'Confirm Action',text:`Are you sure you want to ${label} this pre-registration?`,icon:'question',showCancelButton:true,confirmButtonColor:action==='approved'?'#059669':'#dc2626',confirmButtonText:`Yes, ${label} it!`});
        if (!res.isConfirmed) return;
        showLoading(true);
        const fd=new FormData(); fd.append('csrf_token',CSRF_TOKEN); fd.append('prereg_id',id); fd.append('action',action);
        try { await fetch(window.location.href,{method:'POST',body:fd}); showToast('success','Done!',`Pre-registration ${action}`); setTimeout(()=>location.reload(),1500); }
        catch(e) { showToast('error','Error!','Failed'); }
        finally { showLoading(false); }
    }

    function confirmBulkAction(event) {
        event.preventDefault();
        const action=document.getElementById('bulk_action').value;
        const selected=document.querySelectorAll('.rowCheckbox:checked');
        if (!selected.length) { showToast('warning','No Selection','Select at least one item.'); return; }
        if (!action)          { showToast('warning','No Action','Select an action.'); return; }
        const map={approve:{title:'Approve Selected',text:`Approve ${selected.length} item(s)?`,btn:'Yes, approve!'},reject:{title:'Reject Selected',text:`Reject ${selected.length} item(s)?`,btn:'Yes, reject!'},delete:{title:'Delete Selected',text:`Delete ${selected.length} item(s)?`,btn:'Yes, delete!'}};
        const m=map[action]; if (!m) return;
        Swal.fire({title:m.title,text:m.text,icon:'warning',showCancelButton:true,confirmButtonColor:'#dc2626',confirmButtonText:m.btn}).then(r=>{ if (r.isConfirmed) document.getElementById('bulkForm').submit(); });
    }

    function viewDetails(data) {
        const modal=document.getElementById('detailsModal'), content=document.getElementById('modalContent');
        document.getElementById('modal-title').textContent=`Details — ${data.tracking_code}`;
        const bgy=data.barangay_name||data.current_custom_barangay||'N/A';
        const verifiedTag=data.verified_at?'<span class="verified-badge">✓ Email Verified</span>':'';
        const mkPhoto=(file,label,icon)=>{
            if (!file) return `<div style="padding:20px;text-align:center;color:var(--text-light);"><i class="${icon}"></i><br><small>No ${label}</small></div>`;
            const u=UPLOAD_WEB_PATH+file;
            if (file.toLowerCase().endsWith('.pdf')) return `<div style="text-align:center;padding:20px;"><i class="fas fa-file-pdf" style="font-size:3rem;color:#ef4444;"></i><p><a href="${u}" target="_blank" class="btn btn-view">Open PDF</a></p></div>`;
            return `<img src="${u}" style="max-width:100%;max-height:180px;object-fit:contain;cursor:pointer;" onclick="openLightbox('${u}','${label}')">`;
        };
        content.innerHTML=`
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
                <span class="status-badge status-${data.status}">${data.status.toUpperCase()}</span>${verifiedTag}
                <span style="margin-left:auto;font-size:.78rem;color:var(--text-light);"><i class="fas fa-clock"></i> ${new Date(data.submitted_at).toLocaleString()}</span>
            </div>
            <div class="modal-section-title"><i class="fas fa-images"></i> Photos</div>
            <div class="photo-grid">
                <div class="photo-card"><div class="photo-card-header"><i class="fas fa-camera"></i> Selfie</div><div class="photo-card-body">${mkPhoto(data.selfie_image,'Selfie','fas fa-user-slash')}</div></div>
                <div class="photo-card"><div class="photo-card-header"><i class="fas fa-id-card"></i> Front ID</div><div class="photo-card-body">${mkPhoto(data.valid_id_image,'Front ID','fas fa-id-card-alt')}</div></div>
                <div class="photo-card"><div class="photo-card-header"><i class="fas fa-id-card-alt"></i> Back ID</div><div class="photo-card-body">${mkPhoto(data.valid_id_back_image,'Back ID','fas fa-id-card')}</div></div>
            </div>
            <div class="modal-section-title"><i class="fas fa-user"></i> Personal Information</div>
            <div class="info-grid">
                <div class="info-item"><div class="info-label">Full Name</div><div class="info-value">${escapeHtml(data.last_name)}, ${escapeHtml(data.first_name)} ${escapeHtml(data.middle_name||'')}</div></div>
                <div class="info-item"><div class="info-label">Birth Date</div><div class="info-value">${escapeHtml(data.birthdate)} (${data.age} yrs)</div></div>
                <div class="info-item"><div class="info-label">Sex</div><div class="info-value">${escapeHtml(data.sex||'N/A')}</div></div>
                <div class="info-item"><div class="info-label">LRN</div><div class="info-value">${escapeHtml(data.lrn||'N/A')}</div></div>
            </div>
            <div class="modal-section-title"><i class="fas fa-phone"></i> Contact</div>
            <div class="info-grid">
                <div class="info-item"><div class="info-label">Contact Number</div><div class="info-value">${escapeHtml(data.contact_number)}</div></div>
                <div class="info-item"><div class="info-label">Email</div><div class="info-value">${escapeHtml(data.email)}</div></div>
            </div>
            <div class="modal-section-title"><i class="fas fa-map-marker-alt"></i> Address</div>
            <div class="info-grid">
                <div class="info-item"><div class="info-label">Barangay</div><div class="info-value">${escapeHtml(bgy)}</div></div>
                <div class="info-item"><div class="info-label">City</div><div class="info-value">${escapeHtml(data.current_city||'N/A')}</div></div>
            </div>
            <div class="modal-section-title"><i class="fas fa-robot"></i> AI Analysis</div>
            <div style="background:#fef3c7;padding:12px;border-radius:8px;margin-bottom:12px;font-size:.8rem;">
                <i class="fas fa-ban" style="color:#d97706;"></i> <strong>OCR is DISABLED</strong> — Please manually verify the ID card shows the correct name and birthdate.
            </div>
            <button class="btn btn-smart" onclick="smartReview(${data.preregistration_id})" style="width:100%;padding:12px;margin-top:8px;">
                <i class="fas fa-robot"></i> Run AI Analysis
            </button>
            ${data.status==='pending'?`
            <div style="display:flex;gap:12px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border);">
                <button onclick="quickAction(${data.preregistration_id},'approved')" class="btn btn-approve" style="flex:1;padding:12px;"><i class="fas fa-check"></i> Approve</button>
                <button onclick="quickAction(${data.preregistration_id},'rejected')" class="btn btn-reject"  style="flex:1;padding:12px;"><i class="fas fa-times"></i> Reject</button>
            </div>`:''}`;
        modal.classList.add('active');
    }

    function escapeHtml(t) { if (t===null||t===undefined) return ''; const d=document.createElement('div'); d.textContent=String(t); return d.innerHTML; }
    function showLoading(s) { document.getElementById('loadingOverlay').style.display=s?'flex':'none'; }
    function showToast(type,title,message) { Swal.fire({toast:true,position:'top-end',icon:type,title,text:message,showConfirmButton:false,timer:3000}); }
    function closeModal()   { document.getElementById('detailsModal').classList.remove('active'); }
    function openLightbox(src,label) { document.getElementById('lightbox-img').src=src; document.getElementById('lightbox-label').textContent=label||''; document.getElementById('lightbox').style.display='flex'; }
    function closeLightbox(){ document.getElementById('lightbox').style.display='none'; }
    function updateSelectedCount() { const n=document.querySelectorAll('.rowCheckbox:checked').length; document.getElementById('selectedCount').textContent=n>0?n+' item(s) selected':''; }
    function toggleAll(cb) { document.querySelectorAll('.rowCheckbox').forEach(c=>c.checked=cb.checked); updateSelectedCount(); }
    function exportData(format) { window.open(`export-preregistrations.php?format=${format}&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>`,'_blank'); showToast('success','Export Started','File will download shortly'); }

    let sse, sseAttempts=0;
    function connectSSE() {
        if (sse) sse.close();
        try {
            sse=new EventSource('/SSEPrereg');
            sse.onopen=()=>{ sseAttempts=0; };
            sse.onerror=()=>{ sse.close(); if (++sseAttempts<=5) setTimeout(connectSSE,5000*sseAttempts); else startPolling(); };
            const handleData=(e)=>{ try { const d=JSON.parse(e.data); if (d.counts) ['pending','approved','rejected','all','unread'].forEach(k=>{ const el=document.getElementById(`count-${k}`); if (el&&d.counts[k]!==undefined) el.textContent=d.counts[k]; }); if (d.new_rows?.length) runAutoProcess(false); } catch {} };
            sse.onmessage=handleData; sse.addEventListener('init',handleData); sse.addEventListener('update',handleData);
        } catch { startPolling(); }
    }
    let pollingTimer=null;
    function startPolling() {
        if (pollingTimer) return;
        pollingTimer=setInterval(async()=>{ try { const r=await fetch('/SSEPrereg',{headers:{Accept:'application/json'}}); const d=await r.json(); if (d.counts) ['pending','approved','rejected','all','unread'].forEach(k=>{ const el=document.getElementById(`count-${k}`); if (el&&d.counts[k]!==undefined) el.textContent=d.counts[k]; }); } catch {} },30000);
    }

    window.addEventListener('beforeunload',()=>{ if (sse) sse.close(); if (pollingTimer) clearInterval(pollingTimer); });
    window.addEventListener('click',e=>{ if (e.target===document.getElementById('detailsModal')) closeModal(); if (e.target===document.getElementById('lightbox')) closeLightbox(); });
    document.addEventListener('keydown',e=>{ if (e.key==='Escape') { closeModal(); closeLightbox(); } });
    document.getElementById('bulkForm').addEventListener('submit',confirmBulkAction);
    updateSelectedCount();

    setTimeout(()=>connectSSE(),500);
    setTimeout(()=>runAutoProcess(false),800);
    autoTimer=setInterval(()=>runAutoProcess(false),AUTO_INTERVAL_MS);
    </script>
</body>
</html>