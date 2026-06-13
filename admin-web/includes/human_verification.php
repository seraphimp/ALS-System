<?php
/**
 * Human Verification Helper
 * Provides CAPTCHA and math problem verification
 */

class HumanVerification {
    
    /**
     * Generate a math problem for human verification
     * @return array Problem data
     */
    public static function generateMathProblem() {
        $num1 = rand(1, 10);
        $num2 = rand(1, 10);
        $operator = rand(0, 1) ? '+' : '-';
        
        // Ensure positive result for subtraction
        if ($operator == '-' && $num2 > $num1) {
            $temp = $num1;
            $num1 = $num2;
            $num2 = $temp;
        }
        
        $problem = "$num1 $operator $num2";
        $answer = $operator == '+' ? $num1 + $num2 : $num1 - $num2;
        
        // Store in session with timestamp
        $_SESSION['human_verification'] = [
            'problem' => $problem,
            'answer' => $answer,
            'created_at' => time()
        ];
        
        return [
            'problem' => $problem,
            'hint' => 'Solve the math problem'
        ];
    }
    
    /**
     * Verify math problem answer
     * @param mixed $user_answer The user's answer
     * @return bool True if correct
     */
    public static function verifyMathProblem($user_answer) {
        if (!isset($_SESSION['human_verification'])) {
            return false;
        }
        
        $data = $_SESSION['human_verification'];
        
        // Check if expired (5 minutes)
        if (time() - $data['created_at'] > 300) {
            unset($_SESSION['human_verification']);
            return false;
        }
        
        // Clean the user answer
        $user_answer = trim($user_answer);
        
        if ($user_answer === '') {
            return false;
        }
        
        $user_answer   = intval($user_answer);
        $correct_answer = intval($data['answer']);
        
        $correct = ($user_answer === $correct_answer);
        
        // Clear after use
        unset($_SESSION['human_verification']);
        
        return $correct;
    }
    
    /**
     * Check if IP has too many failed attempts
     * @param mysqli $conn Database connection
     * @param string $ip IP address
     * @param int $max_attempts Maximum allowed attempts
     * @param int $time_window Time window in minutes
     * @return bool True if rate limited
     */
    public static function isRateLimited($conn, $ip, $max_attempts = 5, $time_window = 15) {
        $time_limit = date('Y-m-d H:i:s', strtotime("-$time_window minutes"));
        
        $stmt = $conn->prepare("
            SELECT COUNT(*) as attempts 
            FROM human_verification_attempts 
            WHERE ip_address = ? 
            AND created_at > ? 
            AND success = 0
        ");
        $stmt->bind_param("ss", $ip, $time_limit);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        return ($result['attempts'] >= $max_attempts);
    }
}