<?php
/**
 * token_helpers.php
 * Include this once (e.g. in functions.php or at the top of each page).
 * Provides opaque session-based tokens for teacher and student IDs.
 *
 * URL format examples:
 *   /AdminTeacherDetails?a3f9c2e1d4b7...    (no key name)
 *   /AdminTeacherReports?a3f9c2e1d4b7...
 *   /AdminViewTeachers?a3f9c2e1d4b7...
 */

// ── TEACHER TOKENS  (session key: _tt) ────────────────────────────────────────

if (!function_exists('issue_teacher_token')) {
    function issue_teacher_token(int $id): string {
        if (!isset($_SESSION['_tt']) || !is_array($_SESSION['_tt'])) $_SESSION['_tt'] = [];
        $sid = (string)$id;
        $ex  = array_search($sid, $_SESSION['_tt'], true);
        if ($ex !== false) return $ex;
        $tok = bin2hex(random_bytes(20));
        $_SESSION['_tt'][$tok] = $sid;
        if (count($_SESSION['_tt']) > 500) $_SESSION['_tt'] = array_slice($_SESSION['_tt'], -500, null, true);
        return $tok;
    }
}

if (!function_exists('resolve_teacher_token')) {
    function resolve_teacher_token(string $token): int {
        $token = preg_replace('/[^a-f0-9]/', '', strtolower(trim($token)));
        if (strlen($token) !== 40) return 0;
        return (int)($_SESSION['_tt'][$token] ?? 0);
    }
}

// ── STUDENT TOKENS  (session key: _st) ────────────────────────────────────────

if (!function_exists('issue_student_token')) {
    function issue_student_token(string $student_id): string {
        if (!isset($_SESSION['_st']) || !is_array($_SESSION['_st'])) $_SESSION['_st'] = [];
        $ex = array_search($student_id, $_SESSION['_st'], true);
        if ($ex !== false) return $ex;
        $tok = bin2hex(random_bytes(20));
        $_SESSION['_st'][$tok] = $student_id;
        if (count($_SESSION['_st']) > 500) $_SESSION['_st'] = array_slice($_SESSION['_st'], -500, null, true);
        return $tok;
    }
}

if (!function_exists('resolve_student_token')) {
    function resolve_student_token(string $token): string {
        $token = preg_replace('/[^a-f0-9]/', '', strtolower(trim($token)));
        if (strlen($token) !== 40) return '';
        return $_SESSION['_st'][$token] ?? '';
    }
}

// ── HELPERS ────────────────────────────────────────────────────────────────────

/**
 * Read the raw token from ?<token> (no key) or ?_t=<token> fallback.
 * Strips any trailing &param=value.
 */
if (!function_exists('get_raw_token')) {
    function get_raw_token(): string {
        $raw = trim($_SERVER['QUERY_STRING'] ?? '');
        if (strpos($raw, '&') !== false) $raw = substr($raw, 0, strpos($raw, '&'));
        if (empty($raw) && isset($_GET['_t'])) $raw = trim($_GET['_t']);
        return $raw;
    }
}