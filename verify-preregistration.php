<?php
require_once __DIR__ . '/als/admin-web/includes/db.php';
require_once __DIR__ . '/als/admin-web/includes/functions.php';

secure_session_start();

$message = '';
$message_type = 'error';

if (isset($_GET['code']) && isset($_GET['tracking'])) {
    $code = $_GET['code'];
    $tracking = $_GET['tracking'];
    
    // Verify the code
    $stmt = $conn->prepare("
        SELECT preregistration_id, email, verification_expires, first_name, last_name, access_code
        FROM preregistrations 
        WHERE tracking_code = ? AND verification_code = ? AND verified_at IS NULL
    ");
    $stmt->bind_param("ss", $tracking, $code);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        // Check if verification code is expired
        if (strtotime($row['verification_expires']) > time()) {
            // Update verification status
            $update = $conn->prepare("
                UPDATE preregistrations 
                SET verified_at = NOW(), verification_code = NULL 
                WHERE preregistration_id = ?
            ");
            $update->bind_param("i", $row['preregistration_id']);
            
            if ($update->execute()) {
                // Store in session for enrollment form
                $_SESSION['verified_preregistration'] = [
                    'id' => $row['preregistration_id'],
                    'tracking_code' => $tracking,
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'email' => $row['email']
                ];
                
                // Redirect to enrollment form
                header("Location: /als/enrollment/enrollment.php?verified=1&tracking=" . urlencode($tracking));
                exit();
            } else {
                $message = "Error updating verification status.";
            }
            $update->close();
        } else {
            $message = "Verification link has expired. Please contact ALS office.";
        }
    } else {
        $message = "Invalid verification link or email already verified.";
    }
    $stmt->close();
} else {
    $message = "Invalid verification link.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - ALS</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 40px;
            max-width: 500px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
        }
        
        .icon {
            font-size: 4rem;
            margin-bottom: 20px;
        }
        
        .success { color: #059669; }
        .error { color: #dc2626; }
        
        h1 {
            font-size: 1.8rem;
            margin-bottom: 15px;
            color: #1f2937;
        }
        
        p {
            color: #6b7280;
            margin-bottom: 25px;
            line-height: 1.6;
        }
        
        .btn {
            display: inline-block;
            background: #1d4ed8;
            color: white;
            text-decoration: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn:hover {
            background: #1e3a8a;
            transform: translateY(-1px);
        }
        
        .btn-success {
            background: #059669;
        }
        
        .btn-success:hover {
            background: #047857;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon <?php echo $message_type; ?>">
            <?php if ($message_type === 'success'): ?>
                ✓
            <?php else: ?>
                ✗
            <?php endif; ?>
        </div>
        <h1><?php echo $message_type === 'success' ? 'Verified!' : 'Verification Failed'; ?></h1>
        <p><?php echo htmlspecialchars($message); ?></p>
        
        <?php if ($message_type === 'success'): ?>
            <p style="margin-bottom: 20px;">You will be redirected to the enrollment form automatically...</p>
            <a href="/Verified?verified=1&tracking=<?php echo urlencode($tracking ?? ''); ?>" class="btn btn-success">
                Proceed to Enrollment Form
            </a>
            
            <script>
                // Auto redirect after 3 seconds
                setTimeout(function() {
                    window.location.href = '/Verified?verified=1&tracking=<?php echo urlencode($tracking ?? ''); ?>';
                }, 3000);
            </script>
        <?php else: ?>
            <a href="/" class="btn">Return to Homepage</a>
        <?php endif; ?>
    </div>
</body>
</html>