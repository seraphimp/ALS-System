<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DepEd ALS La Carlota City - Alternative Learning System</title>
<link rel="icon" type="image/png" href="als-enrollment-elearning-system/logo/als-logo-removebg-preview.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --secondary: #9333ea;
            --success: #16a34a;
            --orange: #ea580c;
            --pink: #db2777;
            --cyan: #0891b2;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-900: #111827;
            --white: #ffffff;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: var(--gray-900);
            background: var(--white);
        }

        h1 {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1.2;
        }

        h2 {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1.3;
        }

        h3 {
            font-size: 1.5rem;
            font-weight: 600;
            line-height: 1.4;
        }

        h4 {
            font-size: 1.125rem;
            font-weight: 600;
        }

        p {
            margin-bottom: 1rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        /* Header */
        header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--gray-200);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 4rem;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            color: var(--gray-900);
        }

        .logo-icon {
            width: 2rem;
            height: 2rem;
            color: var(--primary);
        }

        .logo-text {
            font-size: 1.125rem;
            color: var(--primary-dark);
            font-weight: 600;
        }

        .logo-subtext {
            font-size: 0.75rem;
            color: var(--gray-600);
        }

        nav {
            display: none;
            gap: 2rem;
            align-items: center;
        }

        nav a {
            color: var(--gray-700);
            text-decoration: none;
            transition: color 0.3s;
        }

        nav a:hover {
            color: var(--primary);
        }

        .nav-buttons {
            display: none;
            gap: 0.75rem;
        }


        a.btn {
            text-decoration: none;
        }

        a.btn:hover {
            text-decoration: none;
        }

        .btn {
            padding: 0.5rem 1.5rem;
            border-radius: 0.5rem;
            border: none;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--gray-300);
            color: var(--gray-700);
        }

        .btn-outline:hover {
            background: var(--gray-50);
        }

        .btn-secondary {
            background: white;
            color: var(--primary);
        }

        .btn-secondary:hover {
            background: var(--gray-100);
        }

        .btn-lg {
            padding: 0.75rem 2rem;
            font-size: 1rem;
        }

        .menu-toggle {
            display: block;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.5rem;
        }

        .mobile-menu {
            display: none;
            border-top: 1px solid var(--gray-200);
            background: white;
            padding: 1rem;
        }

        .mobile-menu.active {
            display: block;
        }

        .mobile-menu nav {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .mobile-menu .nav-buttons {
            display: flex;
            flex-direction: column;
            margin-top: 1rem;
        }

        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, #eff6ff 0%, #ffffff 50%, #faf5ff 100%);
            padding: 1rem 0;
        }

        .hero-grid {
            display: grid;
            gap: 3rem;
            align-items: center;
        }

        .hero-content {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: #dbeafe;
            color: #1e40af;
            border-radius: 9999px;
            font-size: 0.875rem;
            width: fit-content;
        }

        .hero-title {
            color: var(--gray-900);
        }

        .text-primary {
            color: var(--primary);
        }

        .hero-description {
            font-size: 1.125rem;
            color: var(--gray-600);
        }

        .hero-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .hero-stats {
            display: flex;
            gap: 2rem;
            padding-top: 1rem;
        }

        .stat {
            text-align: left;
        }

        .stat-number {
            font-size: 1.875rem;
            color: var(--primary);
            font-weight: 700;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--gray-600);
        }

        .hero-image {
            position: relative;
        }

        .image-container {
            aspect-ratio: 4/3;
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .image-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .accreditation-badge {
            position: absolute;
            bottom: -1.5rem;
            left: -1.5rem;
            background: white;
            padding: 1.5rem;
            border-radius: 0.75rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            display: none;
        }

        .accreditation-content {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .check-icon {
            width: 3rem;
            height: 3rem;
            background: #dcfce7;
            border-radius: 9999px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        /* About Section */
        .section {
            padding: 5rem 0;
        }

        .section-grid {
            display: grid;
            gap: 3rem;
            align-items: center;
            margin-bottom: 4rem;
        }

        .section-image {
            aspect-ratio: 4/3;
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        .section-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .section-header {
            text-align: center;
            max-width: 48rem;
            margin: 0 auto 4rem;
        }

        .section-title {
            margin-bottom: 1rem;
        }

        .cards-grid {
            display: grid;
            gap: 1.5rem;
        }

        .card {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 0.5rem;
            padding: 1.5rem;
            transition: all 0.3s;
        }

        .card:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        .card-icon {
            width: 4rem;
            height: 4rem;
            border-radius: 9999px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
        }

        .icon {
            width: 2rem;
            height: 2rem;
        }

        .icon-sm {
            width: 1.5rem;
            height: 1.5rem;
        }

        .icon-lg {
            width: 1.25rem;
            height: 1.25rem;
        }

        .bg-blue { background: #dbeafe; }
        .text-blue { color: var(--primary); }
        .bg-green { background: #dcfce7; }
        .text-green { color: var(--success); }
        .bg-purple { background: #f3e8ff; }
        .text-purple { color: var(--secondary); }
        .bg-orange { background: #ffedd5; }
        .text-orange { color: var(--orange); }
        .bg-pink { background: #fce7f3; }
        .text-pink { color: var(--pink); }
        .bg-cyan { background: #cffafe; }
        .text-cyan { color: var(--cyan); }
        .bg-red { background: #f68a8a; }
        .text-red { color: red ;}

        .levels-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .level-card {
            padding: 1rem;
            border-radius: 0.5rem;
        }

        .level-title {
            font-size: 1.5rem;
            margin-bottom: 0.25rem;
        }

        .level-subtitle {
            font-size: 0.875rem;
            color: var(--gray-600);
        }

        /* E-Learning Section */
        .elearning-section {
            background: linear-gradient(180deg, #ffffff 0%, #eff6ff 100%);
        }

        .feature-grid {
            display: grid;
            gap: 3rem;
            align-items: center;
            margin-bottom: 4rem;
        }

        .feature-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .feature-item {
            display: flex;
            gap: 1rem;
        }

        .feature-icon {
            width: 3rem;
            height: 3rem;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .gradient-card {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 1rem;
            padding: 3rem;
            color: white;
        }

        .gradient-card-grid {
            display: grid;
            gap: 2rem;
            align-items: center;
        }

        .checklist {
            list-style: none;
        }

        .checklist li {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .check-circle {
            width: 1.5rem;
            height: 1.5rem;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 9999px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 0.125rem;
        }

        /* Footer */
        footer {
            background: var(--gray-900);
            color: var(--gray-300);
            padding: 3rem 0;
        }

        .footer-grid {
            display: grid;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .footer h3 {
            color: white;
            margin-bottom: 1rem;
            font-size: 1.125rem;
        }

        .footer ul {
            list-style: none;
        }

        .footer ul li {
            margin-bottom: 0.5rem;
        }

        .footer a {
            color: var(--gray-300);
            text-decoration: none;
            font-size: 0.875rem;
            transition: color 0.3s;
        }

        .footer a:hover {
            color: var(--primary);
        }

        .social-links {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .social-link {
            width: 2.5rem;
            height: 2.5rem;
            background: #1f2937;
            border-radius: 9999px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s;
        }

        .social-link:hover {
            background: var(--primary);
        }

        .contact-item {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            font-size: 0.875rem;
        }

        .footer-bottom {
            border-top: 1px solid #374151;
            padding-top: 2rem;
            text-align: center;
            font-size: 0.875rem;
        }

        .footer-bottom p {
            margin-bottom: 0.5rem;
        }

        /* Responsive */
        @media (min-width: 768px) {
            h1 { font-size: 3rem; }
            h2 { font-size: 2.5rem; }
            
            nav {
                display: flex;
            }

            .nav-buttons {
                display: flex;
            }

            .menu-toggle {
                display: none;
            }

            .hero-grid,
            .section-grid,
            .feature-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .cards-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .footer-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .accreditation-badge {
                display: block;
            }

            .gradient-card-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            h1 { font-size: 3.75rem; }
            
            .cards-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .footer-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .programs-grid {
            display: grid;
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        @media (min-width: 768px) {
            .programs-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .programs-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .card-header {
            margin-bottom: 1rem;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-900);
        }

        .card-icon-small {
            width: 3rem;
            height: 3rem;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.75rem;
        }

        .card-text {
            font-size: 0.875rem;
            color: var(--gray-600);
            margin-bottom: 1rem;
        }

        .btn-link {
            background: none;
            border: none;
            color: var(--primary);
            padding: 0;
            font-size: 0.875rem;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-link:hover {
            text-decoration: underline;
        }

        .cta-card {
            background: linear-gradient(90deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 1rem;
            padding: 3rem;
            text-align: center;
            color: white;
        }

        .cta-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: center;
        }

        .btn-white-outline {
            background: transparent;
            border: 1px solid white;
            color: white;
        }

        .btn-white-outline:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .opacity-90 {
            opacity: 0.9;
        }

        .mb-1 { margin-bottom: 0.25rem; }
        .mb-2 { margin-bottom: 0.5rem; }
        .mb-4 { margin-bottom: 1rem; }
        .mb-6 { margin-bottom: 1.5rem; }
        .mt-2 { margin-top: 0.5rem; }
        .pt-4 { padding-top: 1rem; }

        .max-w-2xl {
            max-width: 42rem;
            margin-left: auto;
            margin-right: auto;
        }

        svg {
            display: inline-block;
            vertical-align: middle;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="container">
            <div class="header-content">
                <a href="#home" class="logo">
               <img src="als-enrollment-elearning-system/logo/als-logo-removebg-preview.png" alt="ALS Logo" class="logo-icon" style="width:60px; height:60px;">
                <div>
                    <div class="logo-text">DepEd ALS</div>
                    <div class="logo-subtext">La Carlota City</div>
                </div>
            </a>

                <nav id="desktop-nav">
                    <a href="#home">Home</a>
                    <a href="#about">About ALS</a>
                    <a href="#elearning">E-Learning</a>
                    <a href="#programs">Programs</a>
                    <a href="#contact">Contact</a>
                </nav>

               <div class="nav-buttons">
                <a href="als-enrollment-elearning-system/admin-web/index.php" target="_blank" rel="noopener noreferrer" class="btn btn-outline">Log In</a>
                <a href="als-enrollment-elearning-system/enrollment/enrollment.php" target="_blank" rel="noopener noreferrer" class="btn btn-primary">Get Started</a>
            </div>
                <button class="menu-toggle" onclick="toggleMenu()">
                    <svg width="24" height="24" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="4" x2="20" y1="12" y2="12"/>
                        <line x1="4" x2="20" y1="6" y2="6"/>
                        <line x1="4" x2="20" y1="18" y2="18"/>
                    </svg>
                </button>
            </div>

            <div class="mobile-menu" id="mobile-menu">
                <nav>
                    <a href="#home" onclick="toggleMenu()">Home</a>
                    <a href="#about" onclick="toggleMenu()">About ALS</a>
                    <a href="#elearning" onclick="toggleMenu()">E-Learning</a>
                    <a href="#programs" onclick="toggleMenu()">Programs</a>
                    <a href="#contact" onclick="toggleMenu()">Contact</a>
                </nav>
                <div class="nav-buttons">
                    <button class="btn btn-outline">Log In</button>
                    <button class="btn btn-primary">Get Started</button>
                </div>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section id="home" class="hero">
        <div class="container">
            <div class="hero-grid">
                <div class="hero-content">
                    <a href="https://www.deped.gov.ph/alternative-learning-system/" target="_blank" style="text-decoration: none;">
    <span class="badge">DepEd Alternative Learning System</span>
</a>

                    <h1 class="hero-title">
                        Education for Everyone in <span class="text-primary">La Carlota City</span>
                    </h1>
                    <p class="hero-description">
                        Empowering out-of-school youth and adults with flexible, quality education through 
                        the Alternative Learning System. Learn at your own pace, anywhere, anytime.
                    </p>
                    <div class="hero-buttons">
                        <a href="als-enrollment-elearning-system/enrollment/enrollment.php" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-lg">
                            Enroll Now
                            <svg class="icon-lg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
                            </svg>
                        </a>
                        <button class="btn btn-outline btn-lg">
                            <svg class="icon-lg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polygon points="6 3 20 12 6 21 6 3"/>
                            </svg>
                            Watch Video
                        </button>
                    </div>
                    <div class="hero-stats">
                        <div class="stat">
                            <div class="stat-number">100+</div>
                            <div class="stat-label">Learners</div>
                        </div>
                        <div class="stat">
                            <div class="stat-number">30+</div>
                            <div class="stat-label">Facilitators</div>
                        </div>
                        <div class="stat">
                            <div class="stat-number">95%</div>
                            <div class="stat-label">Success Rate</div>
                        </div>
                    </div>
                </div>
                <div class="hero-image">
                    <div class="image-container">
                        <img src="https://images.unsplash.com/photo-1758874573116-2bc02232eef1?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3w3Nzg4Nzd8MHwxfHNlYXJjaHwxfHxzdHVkZW50cyUyMGxlYXJuaW5nJTIwb25saW5lfGVufDF8fHx8MTc2MTI0NDM2N3ww&ixlib=rb-4.1.0&q=80&w=1080" alt="Students learning online">
                    </div>
                    <div class="accreditation-badge">
                        <div class="accreditation-content">
                            <div class="check-icon">✓</div>
                            <div>
                                <div style="font-size: 0.875rem; color: var(--gray-600);">Accredited by</div>
                                <div style="color: var(--gray-900); font-weight: 500;">DepEd Philippines</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="about" class="section">
        <div class="container">
            <div class="section-grid">
                <div class="section-image">
                    <img src="https://images.unsplash.com/photo-1611581719398-08fe2eb020c7?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3w3Nzg4Nzd8MHwxfHNlYXJjaHwxfHxmaWxpcGlubyUyMHN0dWRlbnRzJTIwY2xhc3Nyb29tfGVufDF8fHx8MTc2MTI3MDEwMHww&ixlib=rb-4.1.0&q=80&w=1080" alt="ALS classroom">
                </div>
                <div>
                    <span class="badge" style="background: #dcfce7; color: var(--success);">About ALS La Carlota</span>
                    <h2 class="section-title" style="margin-top: 1rem;">
                        Empowering Lives Through Education
                    </h2>
                    <p style="color: var(--gray-600); margin-bottom: 1rem;">
                        The Alternative Learning System (ALS) is a parallel learning system in the Philippines 
                        that provides opportunities for out-of-school youth and adults to develop basic and 
                        functional literacy skills, and to access equivalent pathways to complete basic education.
                    </p>
                    <p style="color: var(--gray-600); margin-bottom: 1rem;">
                        In La Carlota City, we are committed to making quality education accessible to everyone, 
                        regardless of age or circumstance. Our program is designed to be flexible, inclusive, 
                        and responsive to the needs of our learners.
                    </p>
                    <div class="levels-grid">
                        <div class="level-card bg-blue">
                            <div class="level-title text-blue">Elementary</div>
                            <p class="level-subtitle">Basic Education Level</p>
                        </div>
                        <div class="level-card bg-purple">
                            <div class="level-title text-purple">Junior HS</div>
                            <p class="level-subtitle">Secondary Level</p>
                        </div>
                        <div class="level-card bg-red">
                            <div class="level-title text-red">Senior HS</div>
                            <p class="level-subtitle">Upper Secondary Level</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="cards-grid">
                <div class="card" style="text-align: center;">
                    <div class="card-icon bg-blue">
                        <svg class="icon text-blue" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>
                        </svg>
                    </div>
                    <h3 class="mb-2">Quality Education</h3>
                    <p class="card-text">
                        Standards-based curriculum aligned with DepEd requirements
                    </p>
                </div>

                <div class="card" style="text-align: center;">
                    <div class="card-icon bg-green">
                        <svg class="icon text-green" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>
                        </svg>
                    </div>
                    <h3 class="mb-2">Inclusive Learning</h3>
                    <p class="card-text">
                        Education for all, regardless of age or background
                    </p>
                </div>

                <div class="card" style="text-align: center;">
                    <div class="card-icon bg-purple">
                        <svg class="icon text-purple" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                        </svg>
                    </div>
                    <h3 class="mb-2">Flexible Approach</h3>
                    <p class="card-text">
                        Learn at your own pace and schedule
                    </p>
                </div>

                <div class="card" style="text-align: center;">
                    <div class="card-icon bg-orange">
                        <svg class="icon text-orange" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
                        </svg>
                    </div>
                    <h3 class="mb-2">Life-long Growth</h3>
                    <p class="card-text">
                        Skills and knowledge for personal development
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- E-Learning Section -->
    <section id="elearning" class="section elearning-section">
        <div class="container">
            <div class="section-header">
                <span class="badge" style="background: #f3e8ff; color: var(--secondary);">Digital Learning Platform</span>
                <h2 class="section-title" style="margin-top: 1rem;">
                    Introducing the ALS E-Learning App & Website
                </h2>
                <p style="font-size: 1.125rem; color: var(--gray-600);">
                    Access quality education anytime, anywhere with our comprehensive digital learning platform 
                    designed specifically for ALS learners in La Carlota City.
                </p>
            </div>

            <div class="feature-grid">
                <div class="section-image" style="order: 2;">
                    <img src="https://images.unsplash.com/photo-1633250391894-397930e3f5f2?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3w3Nzg4Nzd8MHwxfHNlYXJjaHwxfHxtb2JpbGUlMjBsZWFybmluZyUyMGFwcHxlbnwxfHx8fDE3NjEyNzAxMDB8MA&ixlib=rb-4.1.0&q=80&w=1080" alt="ALS E-Learning Mobile App">
                </div>
                <div style="order: 1;">
                    <h3 class="mb-4" style="font-size: 1.875rem;">Learn on Your Terms</h3>
                    <p style="color: var(--gray-600); margin-bottom: 1.5rem;">
                        Our E-Learning platform brings quality education directly to your device. Whether you're 
                        using a smartphone, tablet, or computer, access interactive lessons, video tutorials, 
                        and practice exercises designed to help you succeed.
                    </p>
                    <div class="feature-list">
                        <div class="feature-item">
                            <div class="feature-icon bg-blue">
                                <svg class="icon-sm text-blue" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect width="7" height="13" x="6" y="4" rx="1"/><path d="M10 7h1"/><path d="M6 16h7"/>
                                </svg>
                            </div>
                            <div>
                                <h4 class="mb-1">Mobile App Available</h4>
                                <p class="card-text" style="margin-bottom: 0;">
                                    Download our mobile app for iOS and Android devices
                                </p>
                            </div>
                        </div>
                        <div class="feature-item">
                            <div class="feature-icon bg-purple">
                                <svg class="icon-sm text-purple" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="8" y1="21" y2="17"/><line x1="16" x2="16" y1="21" y2="17"/><line x1="12" x2="12" y1="17" y2="17"/>
                                </svg>
                            </div>
                            <div>
                                <h4 class="mb-1">Web Platform Access</h4>
                                <p class="card-text" style="margin-bottom: 0;">
                                    Full-featured website for desktop and laptop learning
                                </p>
                            </div>
                        </div>
                        <div class="feature-item">
                            <div class="feature-icon bg-green">
                                <svg class="icon-sm text-green" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>
                                </svg>
                            </div>
                            <div>
                                <h4 class="mb-1">Offline Mode</h4>
                                <p class="card-text" style="margin-bottom: 0;">
                                    Download lessons and study without internet connection
                                </p>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 1.5rem;">
                        <button class="btn btn-primary btn-lg">
                            <svg class="icon-lg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>
                            </svg>
                            Download App
                        </button>
                        <a href="../e-learning-web/login.php" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-lg">Access Web Portal</a>
                    </div>
                </div>
            </div>

            <div class="cards-grid" style="margin-bottom: 3rem;">
                <div class="card" style="border-width: 2px;">
                    <div class="card-icon-small bg-blue">
                        <svg class="icon-sm text-blue" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>
                        </svg>
                    </div>
                    <h4 class="mb-2">Interactive Modules</h4>
                    <p class="card-text">
                        Engaging lessons across all ALS learning strands with multimedia content
                    </p>
                </div>

                <div class="card" style="border-width: 2px;">
                    <div class="card-icon-small bg-purple">
                        <svg class="icon-sm text-purple" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/>
                        </svg>
                    </div>
                    <h4 class="mb-2">Video Tutorials</h4>
                    <p class="card-text">
                        Step-by-step video lessons from experienced ALS facilitators
                    </p>
                </div>

                <div class="card" style="border-width: 2px;">
                    <div class="card-icon-small bg-green">
                        <svg class="icon-sm text-green" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                    </div>
                    <h4 class="mb-2">Live Classes</h4>
                    <p class="card-text">
                        Join virtual classrooms and interact with teachers in real-time
                    </p>
                </div>

                <div class="card" style="border-width: 2px;">
                    <div class="card-icon-small bg-orange">
                        <svg class="icon-sm text-orange" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/>
                        </svg>
                    </div>
                    <h4 class="mb-2">Progress Tracking</h4>
                    <p class="card-text">
                        Monitor your learning journey and celebrate achievements
                    </p>
                </div>
            </div>

            <div class="gradient-card">
                <div class="gradient-card-grid">
                    <div>
                        <h3 class="mb-4" style="font-size: 1.875rem;">
                            Everything You Need to Succeed
                        </h3>
                        <ul class="checklist mb-6">
                            <li>
                                <div class="check-circle">✓</div>
                                <span>Complete ALS curriculum aligned with DepEd standards</span>
                            </li>
                            <li>
                                <div class="check-circle">✓</div>
                                <span>Practice tests and A&E exam preparation</span>
                            </li>
                            <li>
                                <div class="check-circle">✓</div>
                                <span>Personalized learning paths based on your level</span>
                            </li>
                            <li>
                                <div class="check-circle">✓</div>
                                <span>24/7 access to learning materials and resources</span>
                            </li>
                        </ul>
                        <button class="btn btn-secondary btn-lg">
                            Explore Platform Features
                        </button>
                    </div>
                    <div style="aspect-ratio: 16/9; border-radius: 0.5rem; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
                        <img src="https://images.unsplash.com/photo-1519389950473-47ba0277781c?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3w3Nzg4Nzd8MHwxfHNlYXJjaHwxfHxlZHVjYXRpb24lMjB0ZWNobm9sb2d5fGVufDF8fHx8MTc2MTI2ODIwMnww&ixlib=rb-4.1.0&q=80&w=1080" alt="E-Learning Platform" style="width: 100%; height: 100%; object-fit: cover;">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Programs Section -->
    <section id="programs" class="section">
        <div class="container">
            <div class="section-header">
                <span class="badge">Learning Strands</span>
                <h2 class="section-title" style="margin-top: 1rem;">
                    ALS Learning Programs
                </h2>
                <p style="color: var(--gray-600);">
                    Our comprehensive curriculum covers six learning strands designed to provide 
                    holistic education and prepare learners for the A&E Test.
                </p>
            </div>

            <div class="programs-grid">
                <div class="card">
                    <div class="card-header">
                        <div class="card-icon-small bg-blue">
                            <svg class="icon-sm text-blue" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="m5 8 6 6"/><path d="m4 14 6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="m22 22-5-10-5 10"/><path d="M14 18h6"/>
                            </svg>
                        </div>
                        <h3 class="card-title">Communication Skills</h3>
                    </div>
                    <p class="card-text">
                        English and Filipino language proficiency, reading, writing, and oral communication
                    </p>
                    <a href="#" class="btn-link">Learn More →</a>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-icon-small bg-purple">
                            <svg class="icon-sm text-purple" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect width="4" height="12" x="2" y="10"/><path d="M9 2v20"/><path d="M15 3v18"/><path d="m19 5 3 3-3 3"/>
                            </svg>
                        </div>
                        <h3 class="card-title">Scientific Literacy</h3>
                    </div>
                    <p class="card-text">
                        Understanding of scientific concepts, mathematics, and critical thinking skills
                    </p>
                    <a href="#" class="btn-link">Learn More →</a>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-icon-small bg-green">
                            <svg class="icon-sm text-green" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>
                            </svg>
                        </div>
                        <h3 class="card-title">Critical Thinking</h3>
                    </div>
                    <p class="card-text">
                        Problem-solving, decision-making, and analytical skills development
                    </p>
                    <a href="#" class="btn-link">Learn More →</a>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-icon-small bg-orange">
                            <svg class="icon-sm text-orange" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>
                            </svg>
                        </div>
                        <h3 class="card-title">Life & Career Skills</h3>
                    </div>
                    <p class="card-text">
                        Practical life skills, career development, and livelihood opportunities
                    </p>
                    <a href="#" class="btn-link">Learn More →</a>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-icon-small bg-pink">
                            <svg class="icon-sm text-pink" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>
                                <path d="M8 7h6"/><path d="M8 11h8"/>
                            </svg>
                        </div>
                        <h3 class="card-title">Understanding the Self</h3>
                    </div>
                    <p class="card-text">
                        Personal development, self-awareness, and social responsibility
                    </p>
                    <a href="#" class="btn-link">Learn More →</a>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-icon-small bg-cyan">
                            <svg class="icon-sm text-cyan" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>
                            </svg>
                        </div>
                        <h3 class="card-title">Digital Citizenship</h3>
                    </div>
                    <p class="card-text">
                        Technology literacy, digital skills, and responsible online behavior
                    </p>
                    <a href="#" class="btn-link">Learn More →</a>
                </div>
            </div>

            <div class="cta-card">
                <h3 class="mb-4" style="font-size: 1.875rem;">
                    Ready to Start Your Learning Journey?
                </h3>
                <p class="mb-6 opacity-90 max-w-2xl" style="font-size: 1.125rem;">
                    Join thousands of learners in La Carlota City who are transforming their lives through education.
                </p>
                <div class="cta-buttons">
                    <a href="als-enrollment-elearning-system/enrollment/enrollment.php" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-lg">
                        Enroll in ALS Program
                    </a>
                    <button class="btn btn-white-outline btn-lg">
                        Contact Us
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer id="contact">
        <div class="container">
            <div class="footer-grid">
                <div>
                    <a href="#home" class="logo" style="margin-bottom: 1rem;">
                        <svg style="width: 2rem; height: 2rem; color: #60a5fa;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>
                        </svg>
                        <div>
                            <div style="font-size: 1.125rem; color: white;">DepEd ALS</div>
                            <div style="font-size: 0.75rem;">La Carlota City</div>
                        </div>
                    </a>
                    <p style="font-size: 0.875rem; margin-bottom: 1rem;">
                        Empowering lives through accessible, flexible, and quality alternative learning.
                    </p>
                    <div class="social-links">
                        <a href="#" class="social-link">
                            <svg style="width: 1.25rem; height: 1.25rem;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>
                            </svg>
                        </a>
                        <a href="#" class="social-link">
                            <svg style="width: 1.25rem; height: 1.25rem;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="m22.54 6.42-.83 2.77-2.77.83 2.77.83.83 2.77.83-2.77 2.77-.83-2.77-.83-.83-2.77zm-5.83 5.83-.83 2.77-2.77.83 2.77.83.83 2.77.83-2.77 2.77-.83-2.77-.83-.83-2.77z"/><path d="m12 17.27-5.18 3.73 1.64-6.81-5.46-4.73 6.9-.59L12 2l2.1 6.87 6.9.59-5.46 4.73 1.64 6.81L12 17.27z"/>
                            </svg>
                        </a>
                        <a href="#" class="social-link">
                            <svg style="width: 1.25rem; height: 1.25rem;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/>
                            </svg>
                        </a>
                    </div>
                </div>

                <div>
                    <h3>Quick Links</h3>
                    <ul>
                        <li><a href="#home">Home</a></li>
                        <li><a href="#about">About ALS</a></li>
                        <li><a href="#elearning">E-Learning Platform</a></li>
                        <li><a href="#programs">Programs</a></li>
                        <li><a href="#">Enrollment</a></li>
                    </ul>
                </div>

                <div>
                    <h3>Resources</h3>
                    <ul>
                        <li><a href="#">A&E Test Guide</a></li>
                        <li><a href="#">Learning Materials</a></li>
                        <li><a href="#">FAQs</a></li>
                        <li><a href="#">Student Portal</a></li>
                        <li><a href="#">Facilitator Login</a></li>
                    </ul>
                </div>

                <div>
                    <h3>Contact Us</h3>
                    <div class="contact-item">
                        <svg style="width: 1.25rem; height: 1.25rem; color: #60a5fa; flex-shrink: 0;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>
                        </svg>
                        <span>La Carlota City Division Office, Negros Occidental</span>
                    </div>
                    <div class="contact-item">
                        <svg style="width: 1.25rem; height: 1.25rem; color: #60a5fa; flex-shrink: 0;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                        </svg>
                        <span>(034) 460-XXXX</span>
                    </div>
                    <div class="contact-item">
                        <svg style="width: 1.25rem; height: 1.25rem; color: #60a5fa; flex-shrink: 0;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>
                        </svg>
                        <span>als.lacarlota@deped.gov.ph</span>
                    </div>
                </div>
            </div>

            <div class="footer-bottom">
                <p>© 2025 DepEd Alternative Learning System - La Carlota City. All rights reserved.</p>
                <p style="margin-top: 0.5rem; font-size: 0.75rem; color: #6b7280;">
                    Part of the Department of Education, Philippines
                </p>
            </div>
        </div>
    </footer>

    <script>
        function toggleMenu() {
            const menu = document.getElementById('mobile-menu');
            menu.classList.toggle('active');
        }

        // Smooth scroll
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Close mobile menu when clicking outside
        document.addEventListener('click', function(event) {
            const menu = document.getElementById('mobile-menu');
            const toggle = document.querySelector('.menu-toggle');
            
            if (!menu.contains(event.target) && !toggle.contains(event.target)) {
                menu.classList.remove('active');
            }
        });

        // Add this to your existing JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            // Track enrollment button clicks
            const enrollButtons = document.querySelectorAll('a[href*="enrollment.php"]');
            enrollButtons.forEach(button => {
                button.addEventListener('click', function() {
                    // You can add analytics tracking here
                    console.log('Enrollment button clicked');
                });
            });
        });
    </script>
</body>
</html>
