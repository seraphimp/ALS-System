<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/db.php';
require_once 'includes/functions.php';

secure_session_start();

$error = '';
$username_value = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['username'], $_POST['password'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $username_value = htmlspecialchars($username);
    
    if ($admin = verify_admin_login($username, $password, $conn)) {
        ob_end_clean();
        
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['username'] = $admin['username'];
        $_SESSION['full_name'] = $admin['full_name'];
        
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $_SESSION['login_string'] = hash('sha256', $admin['id'] . $user_agent);
        $_SESSION['last_activity'] = time();
        
        header('Location: /AdminDashboard');
        exit;
    } else {
        $error = 'Invalid username or password. Please try again.';
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ALS Admin Portal | Login</title>
    <link rel="icon" type="image/png" href="als/logo/als-logo-removebg-preview.png">
    
    <!-- Inter Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <script>
        // Client-side validation and UX improvements
        document.addEventListener('DOMContentLoaded', function() {
            const loginForm = document.querySelector('form');
            const usernameInput = document.getElementById('username');
            const passwordInput = document.getElementById('password');
            const submitButton = document.querySelector('button[type="submit"]');
            
            // Create overlay element
            const overlay = document.createElement('div');
            overlay.id = 'signingInOverlay';
            overlay.className = 'fixed inset-0 z-[100] hidden items-center justify-center';
            overlay.innerHTML = `
                <div class="absolute inset-0 bg-black/70 backdrop-blur-sm"></div>
                <div class="relative z-10 bg-gradient-to-br from-gray-900/90 to-black/90 rounded-2xl p-10 shadow-2xl border border-white/10 min-w-[300px] max-w-md mx-4 transform transition-all duration-500 scale-95 opacity-0">
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-24 h-24 mb-6 relative">
                            <div class="absolute inset-0 rounded-full bg-gradient-to-r from-blue-500/20 to-purple-500/20 animate-spin-slow"></div>
                            <div class="absolute inset-4 rounded-full bg-gradient-to-r from-blue-600/30 to-purple-600/30 animate-spin-reverse"></div>
                            <div class="relative bg-gray-900/80 rounded-full w-20 h-20 flex items-center justify-center border border-white/10">
                                <i class="fas fa-lock text-3xl text-white"></i>
                            </div>
                        </div>
                        <h2 class="text-2xl font-bold text-white mb-4">Signing In...</h2>
                        <p class="text-gray-300 mb-6">Please wait while we verify your credentials</p>
                        <div class="w-full bg-gray-800 rounded-full h-2 overflow-hidden">
                            <div class="h-full bg-gradient-to-r from-blue-500 to-purple-500 animate-progress-infinite"></div>
                        </div>
                        <p class="text-sm text-gray-400 mt-4 flex items-center justify-center gap-2">
                            <i class="fas fa-shield-alt"></i>
                            <span>Secure authentication in progress</span>
                        </p>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);
            
            // Focus username field on load
            if (usernameInput && !usernameInput.value) {
                usernameInput.focus();
            }
            
            // Function to show signing in overlay
            function showSigningInOverlay() {
                const overlay = document.getElementById('signingInOverlay');
                overlay.classList.remove('hidden');
                overlay.classList.add('flex');
                
                // Trigger animation after a small delay
                setTimeout(() => {
                    const content = overlay.querySelector('.relative');
                    content.classList.remove('scale-95', 'opacity-0');
                    content.classList.add('scale-100', 'opacity-100');
                }, 50);
            }
            
            // Function to hide signing in overlay
            function hideSigningInOverlay() {
                const overlay = document.getElementById('signingInOverlay');
                const content = overlay.querySelector('.relative');
                content.classList.remove('scale-100', 'opacity-100');
                content.classList.add('scale-95', 'opacity-0');
                
                setTimeout(() => {
                    overlay.classList.remove('flex');
                    overlay.classList.add('hidden');
                }, 300);
            }
            
            // Animated error notification system
            function showErrorNotification(message) {
                // Hide signing in overlay if shown
                hideSigningInOverlay();
                
                // Remove any existing notification
                const existingNotification = document.querySelector('.error-notification');
                if (existingNotification) {
                    existingNotification.remove();
                }
                
                // Create notification element
                const notification = document.createElement('div');
                notification.className = 'error-notification fixed top-6 right-6 z-50 max-w-sm w-full transform translate-x-full opacity-0 transition-all duration-500 ease-out';
                
                notification.innerHTML = `
                    <div class="bg-gradient-to-r from-red-500 to-red-600 text-white rounded-2xl shadow-2xl overflow-hidden border border-red-400">
                        <div class="flex items-start p-5">
                            <div class="flex-shrink-0">
                                <div class="animate-ping-once bg-white/30 rounded-full p-3">
                                    <i class="fas fa-exclamation-triangle text-xl"></i>
                                </div>
                            </div>
                            <div class="ml-4 flex-1">
                                <h3 class="text-lg font-semibold">Authentication Failed</h3>
                                <p class="mt-1 text-red-100">${message}</p>
                                <div class="mt-3 flex items-center gap-2 text-sm">
                                    <i class="fas fa-lightbulb"></i>
                                    <span>Check your credentials and try again</span>
                                </div>
                            </div>
                            <button type="button" onclick="this.closest('.error-notification').remove()" 
                                    class="flex-shrink-0 ml-4 text-white/80 hover:text-white transition">
                                <i class="fas fa-times text-lg"></i>
                            </button>
                        </div>
                        <div class="h-1 bg-red-300 overflow-hidden">
                            <div class="h-full bg-white/40 progress-bar animate-progress"></div>
                        </div>
                    </div>
                `;
                
                document.body.appendChild(notification);
                
                // Trigger animation
                requestAnimationFrame(() => {
                    notification.classList.remove('translate-x-full', 'opacity-0');
                    notification.classList.add('translate-x-0', 'opacity-100');
                });
                
                // Auto-remove after 8 seconds
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.classList.add('translate-x-full', 'opacity-0');
                        setTimeout(() => {
                            if (notification.parentNode) {
                                notification.remove();
                            }
                        }, 500);
                    }
                }, 8000);
                
                // Also show form field error effects
                if (usernameInput) {
                    usernameInput.classList.add('animate-shake', 'border-red-500');
                    setTimeout(() => {
                        usernameInput.classList.remove('animate-shake');
                    }, 820);
                }
                if (passwordInput) {
                    passwordInput.classList.add('animate-shake', 'border-red-500');
                    setTimeout(() => {
                        passwordInput.classList.remove('animate-shake');
                    }, 820);
                }
            }
            
            // Check for PHP error and show notification
            <?php if (!empty($error)): ?>
                setTimeout(() => {
                    showErrorNotification('<?php echo addslashes($error); ?>');
                }, 300);
            <?php endif; ?>
            
            // Form validation
            if (loginForm) {
                loginForm.addEventListener('submit', function(e) {
                    const username = usernameInput.value.trim();
                    const password = passwordInput.value.trim();
                    
                    if (!username || !password) {
                        e.preventDefault();
                        
                        // Show client-side validation error
                        const errorMsg = !username && !password 
                            ? 'Please enter both username and password' 
                            : !username 
                                ? 'Please enter your username' 
                                : 'Please enter your password';
                        
                        showErrorNotification(errorMsg);
                        
                        // Add visual feedback for empty fields
                        if (!username) {
                            usernameInput.classList.add('border-red-500', 'ring-2', 'ring-red-200');
                        }
                        if (!password) {
                            passwordInput.classList.add('border-red-500', 'ring-2', 'ring-red-200');
                        }
                        
                        // Remove error styles after 3 seconds
                        setTimeout(() => {
                            usernameInput.classList.remove('border-red-500', 'ring-2', 'ring-red-200');
                            passwordInput.classList.remove('border-red-500', 'ring-2', 'ring-red-200');
                        }, 3000);
                    } else {
                        // Show signing in overlay
                        showSigningInOverlay();
                        
                        // Show loading state on button
                        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing In...';
                        submitButton.disabled = true;
                        submitButton.classList.add('opacity-75', 'cursor-not-allowed');
                        
                        // Add form shake effect if login fails (simulated)
                        setTimeout(() => {
                            if (document.querySelector('.error-notification')) {
                                loginForm.classList.add('animate-shake');
                                setTimeout(() => {
                                    loginForm.classList.remove('animate-shake');
                                }, 820);
                            }
                        }, 100);
                    }
                });
            }
            
            // Real-time validation feedback
            [usernameInput, passwordInput].forEach(input => {
                if (input) {
                    input.addEventListener('input', function() {
                        if (this.value.trim()) {
                            this.classList.remove('border-red-500', 'ring-2', 'ring-red-200');
                            this.classList.add('border-blue-300');
                        }
                    });
                    
                    input.addEventListener('focus', function() {
                        this.classList.add('ring-2', 'ring-blue-100', 'animate-pulse-gentle');
                    });
                    
                    input.addEventListener('blur', function() {
                        this.classList.remove('ring-2', 'ring-blue-100', 'animate-pulse-gentle');
                    });
                }
            });
            
            // Toggle password visibility
            const togglePasswordBtn = document.createElement('button');
            togglePasswordBtn.type = 'button';
            togglePasswordBtn.className = 'absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-blue-600 transition';
            togglePasswordBtn.innerHTML = '<i class="fas fa-eye"></i>';
            
            if (passwordInput && passwordInput.parentNode) {
                passwordInput.parentNode.appendChild(togglePasswordBtn);
                
                togglePasswordBtn.addEventListener('click', function() {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
                    
                    // Add animation on toggle
                    this.classList.add('scale-125');
                    setTimeout(() => {
                        this.classList.remove('scale-125');
                    }, 300);
                });
            }
            
            // Add enter key navigation
            usernameInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    passwordInput.focus();
                }
            });
            
            // Check for saved username in localStorage
            const savedUsername = localStorage.getItem('als_admin_username');
            if (savedUsername && !usernameInput.value) {
                usernameInput.value = savedUsername;
            }
            
            // Remember username checkbox
            const rememberCheckbox = document.querySelector('input[name="remember"]');
            if (rememberCheckbox) {
                rememberCheckbox.addEventListener('change', function() {
                    if (this.checked && usernameInput.value.trim()) {
                        localStorage.setItem('als_admin_username', usernameInput.value.trim());
                    } else {
                        localStorage.removeItem('als_admin_username');
                    }
                });
            }
        });
    </script>
    
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }
        .bg-gradient-primary {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 50%, #60a5fa 100%);
        }
        .glass-effect {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .login-card {
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1), 0 0 0 1px rgba(255, 255, 255, 0.9);
        }
        .floating-element {
            animation: float 6s ease-in-out infinite;
        }
        .floating-element-delay {
            animation: float 6s ease-in-out infinite 2s;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }
        @media (max-width: 1024px) {
            .left-panel { display: none; }
            .right-panel { min-height: 100vh; border-radius: 0 !important; }
        }
        .input-focus-effect:focus {
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
            border-color: #3b82f6;
        }
        
        /* New Animation Classes */
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        
        @keyframes pingOnce {
            0% { transform: scale(1); opacity: 0.8; }
            50% { transform: scale(1.1); opacity: 1; }
            100% { transform: scale(1); opacity: 0.8; }
        }
        
        @keyframes progressBar {
            0% { width: 100%; }
            100% { width: 0%; }
        }
        
        @keyframes pulseGentle {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.9; }
        }
        
        /* New animations for signing in overlay */
        @keyframes spinSlow {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @keyframes spinReverse {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(-360deg); }
        }
        
        @keyframes progressInfinite {
            0% { width: 0%; transform: translateX(0); }
            50% { width: 100%; transform: translateX(0); }
            100% { width: 0%; transform: translateX(200%); }
        }
        
        .animate-slide-in-right {
            animation: slideInRight 0.5s ease-out forwards;
        }
        
        .animate-slide-out-right {
            animation: slideOutRight 0.5s ease-in forwards;
        }
        
        .animate-shake {
            animation: shake 0.82s cubic-bezier(.36,.07,.19,.97) both;
        }
        
        .animate-ping-once {
            animation: pingOnce 1s ease-in-out;
        }
        
        .animate-progress {
            animation: progressBar 8s linear forwards;
        }
        
        .animate-progress-infinite {
            animation: progressInfinite 2s ease-in-out infinite;
        }
        
        .animate-pulse-gentle {
            animation: pulseGentle 2s ease-in-out infinite;
        }
        
        .animate-spin-slow {
            animation: spinSlow 3s linear infinite;
        }
        
        .animate-spin-reverse {
            animation: spinReverse 2s linear infinite;
        }
        
        /* Enhanced error state for inputs */
        .input-error {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23dc2626'%3e%3cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.998-.833-2.732 0L4.732 16.5c-.77.833.192 2.5 1.732 2.5z' /%3e%3c/svg%3e");
            background-position: right 1rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            padding-right: 2.5rem;
        }
        
        /* Error notification specific styles */
        .error-notification {
            filter: drop-shadow(0 10px 20px rgba(220, 38, 38, 0.3));
        }
        
        /* Dark mode support for notification */
        @media (prefers-color-scheme: dark) {
            .error-notification > div {
                background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
            }
        }
        
        /* Mobile optimizations */
        @media (max-width: 640px) {
            .error-notification {
                top: 4;
                right: 4;
                left: 4;
                max-width: none;
            }
            #signingInOverlay .relative {
                padding: 1.5rem;
                min-width: auto;
            }
        }
        
        /* Signing in overlay styles */
        #signingInOverlay {
            transition: opacity 0.3s ease;
        }
        
        #signingInOverlay .relative {
            transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
    </style>
</head>
<body class="h-full">
    <div class="min-h-screen flex">
        <!-- Left Panel - Branding -->
        <div class="left-panel relative hidden lg:flex lg:w-1/2 xl:w-3/5 bg-gradient-primary text-white overflow-hidden">
            <!-- Animated background elements -->
            <div class="absolute inset-0">
                <div class="floating-element absolute top-1/4 left-1/4 w-32 h-32 rounded-full bg-white opacity-10"></div>
                <div class="floating-element-delay absolute bottom-1/4 right-1/4 w-40 h-40 rounded-full bg-white opacity-5"></div>
            </div>
            
            <div class="relative z-10 flex flex-col justify-center items-start px-16 max-w-2xl mx-auto">
                <div class="flex items-center mb-10">
                    <div class="glass-effect p-8 rounded-2xl mr-12">
                        <img src="als/logo/als-logo-removebg-preview.png" alt="ALS Logo" class="w-24 h-24">
                    </div>
                    <div>
                        <h1 class="text-6xl font-extrabold leading-tight tracking-tight">
                            <span class="text-red-500" style="text-shadow: 0 0 20px rgba(239,68,68,0.6);">A</span>lternative<br>
                            <span class="text-green-600" style="text-shadow: 0 0 20px rgba(74,222,128,0.6);">L</span>earning<br>
                            <span class="text-blue-900" style="text-shadow: 0 0 20px rgba(10, 71, 146, 0.6);">S</span>ystem
                        </h1>
                        <div class="h-1 w-32 bg-white opacity-30 mt-4 rounded-full"></div>
                    </div>
                </div>
                
                <p class="text-2xl font-light opacity-90 mb-8">
                    Administrator Portal
                </p>
                <p class="text-lg opacity-80 leading-relaxed mb-10">
                    Secure access to manage students, teachers, classes, and learning materials across all ALS centers.
                </p>

                <!-- Features list -->
                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="glass-effect p-3 rounded-full">
                            <i class="fas fa-users text-xl"></i>
                        </div>
                        <span>Manage student & teacher accounts</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="glass-effect p-3 rounded-full">
                            <i class="fas fa-chart-bar text-xl"></i>
                        </div>
                        <span>View analytics and reports</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="glass-effect p-3 rounded-full">
                            <i class="fas fa-shield-alt text-xl"></i>
                        </div>
                        <span>Enterprise-grade security</span>
                    </div>
                </div>

                <div class="mt-12 flex items-center gap-4 text-sm glass-effect px-6 py-4 rounded-2xl">
                    <i class="fas fa-lock text-2xl"></i>
                    <div>
                        <div class="font-semibold">End-to-end encrypted</div>
                        <span class="opacity-80">Your data is protected with AES-256 encryption</span>
                    </div>
                </div>
            </div>

            <!-- Decorative icons -->
            <div class="absolute bottom-16 left-10 text-white opacity-10">
                <i class="fas fa-graduation-cap text-9xl"></i>
            </div>
            <div class="absolute top-20 right-20 text-white opacity-10">
                <i class="fas fa-book-open text-8xl"></i>
            </div>
        </div>

        <!-- Right Panel - Login Form -->
        <div class="right-panel w-full lg:w-1/2 xl:w-2/5 flex items-center justify-center px-4 md:px-8 lg:px-12">
            <div class="w-full max-w-md">
                <!-- Mobile Header -->
                <div class="flex justify-center mb-8 lg:hidden">
                    <div class="text-center">
                        <div class="inline-block p-4 bg-blue-50 rounded-2xl mb-4">
                            <img src="../logo/als-logo-removebg-preview.png" alt="ALS Logo" class="h-16">
                        </div>
                        <h1 class="text-2xl font-bold text-gray-800">ALS Admin Portal</h1>
                        <p class="text-gray-600 mt-2">Sign in to continue</p>
                    </div>
                </div>

                <div class="login-card bg-white rounded-3xl p-8 md:p-10 relative">
                    <!-- Inline error indicator (subtle) -->
                    <?php if (!empty($error)): ?>
                        <div class="absolute -top-2 left-1/2 transform -translate-x-1/2">
                            <div class="bg-red-500 text-white text-xs font-bold px-3 py-1 rounded-full shadow-lg animate-pulse-gentle">
                                <i class="fas fa-exclamation-circle mr-1"></i> Login Failed
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="text-center mb-10">
                        <h2 class="text-3xl font-bold text-gray-800">Welcome Back</h2>
                        <p class="text-gray-600 mt-2 flex items-center justify-center gap-2">
                            <span>Enter your credentials to continue</span>
                            <i class="fas fa-arrow-right text-blue-500 text-sm"></i>
                        </p>
                    </div>

                    <form method="POST" action="" class="space-y-8" id="loginForm" novalidate>
                        <div class="space-y-6">
                            <div>
                                <label for="username" class="block text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
                                    <i class="fas fa-user-circle text-blue-500"></i>
                                    <span>Username</span>
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <i class="fas fa-user text-gray-400"></i>
                                    </div>
                                    <input type="text" name="username" id="username" required 
                                           value="<?php echo $username_value; ?>"
                                           class="block w-full pl-12 pr-4 py-4 border border-gray-300 rounded-xl input-focus-effect transition-all duration-200 placeholder-gray-400 hover:border-blue-300"
                                           placeholder="admin.username">
                                </div>
                                <div class="text-xs text-gray-500 mt-2 flex items-center gap-1">
                                    <i class="fas fa-info-circle"></i>
                                    <span>Enter your registered username</span>
                                </div>
                            </div>

                            <div>
                                <label for="password" class="block text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
                                    <i class="fas fa-key text-blue-500"></i>
                                    <span>Password</span>
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <i class="fas fa-lock text-gray-400"></i>
                                    </div>
                                    <input type="password" name="password" id="password" required
                                           class="block w-full pl-12 pr-12 py-4 border border-gray-300 rounded-xl input-focus-effect transition-all duration-200 placeholder-gray-400 hover:border-blue-300"
                                           placeholder="••••••••">
                                </div>
                                <div class="text-xs text-gray-500 mt-2 flex items-center gap-1">
                                    <i class="fas fa-shield-alt"></i>
                                    <span>Your password is encrypted and secure</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="remember" class="h-4 w-4 text-blue-600 rounded focus:ring-blue-500 hover:scale-110 transition">
                                <span class="text-sm text-gray-600">Remember username</span>
                            </label>
                            <a href="#" class="text-sm text-blue-600 hover:text-blue-800 font-medium flex items-center gap-1 transition hover:gap-2">
                                <i class="fas fa-question-circle"></i>
                                <span>Need help?</span>
                            </a>
                        </div>

                        <button type="submit" class="group w-full bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white font-semibold py-4 rounded-xl shadow-lg hover:shadow-xl transform transition-all duration-300 hover:-translate-y-1 flex items-center justify-center gap-3 text-lg">
                            <span>Sign In</span>
                            <i class="fas fa-arrow-right group-hover:translate-x-1 transition-transform"></i>
                        </button>
                    </form>

                    <div class="mt-12 pt-8 border-t border-gray-100">
                        <div class="flex items-center justify-center gap-4 mb-6">
                            <div class="h-px flex-1 bg-gray-200"></div>
                            <span class="text-sm text-gray-500">Security Features</span>
                            <div class="h-px flex-1 bg-gray-200"></div>
                        </div>
                        <div class="grid grid-cols-3 gap-4 text-center">
                            <div class="p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                                <i class="fas fa-lock text-green-500 mb-2"></i>
                                <div class="text-xs font-medium">SSL Secured</div>
                            </div>
                            <div class="p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                                <i class="fas fa-history text-blue-500 mb-2"></i>
                                <div class="text-xs font-medium">Activity Log</div>
                            </div>
                            <div class="p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                                <i class="fas fa-user-check text-purple-500 mb-2"></i>
                                <div class="text-xs font-medium">2FA Ready</div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-10 text-center text-sm text-gray-500">
                        <p>&copy; <?php echo date('Y'); ?> Alternative Learning System. All rights reserved.</p>
                        <p class="mt-2 flex items-center justify-center gap-2">
                            <i class="fas fa-shield-check text-green-500"></i>
                            <span>DEPED</span>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>