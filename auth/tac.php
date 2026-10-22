<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $_SESSION['theme']; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms & Conditions - CodeSphere</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        /* Hide scrollbars but keep scrolling functionality */
html, body {
    overflow: auto;
    scrollbar-width: none; /* Firefox */
    -ms-overflow-style: none; /* IE and Edge */
}

/* Webkit browsers (Chrome, Safari, Opera) */
html::-webkit-scrollbar,
body::-webkit-scrollbar,
.main-content::-webkit-scrollbar,
.profile::-webkit-scrollbar,
.sidebar-nav::-webkit-scrollbar,
.modal-body::-webkit-scrollbar {
    display: none;
    width: 0;
    height: 0;
}

/* Ensure proper sizing */
body {
    margin: 0;
    padding: 0;
}

.main-content {
    height: 100vh;
    overflow: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.main-content::-webkit-scrollbar {
    display: none;
}
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --primary-light: #c7d2fe;
            --secondary: #8b5cf6;
            --accent: #f59e0b;
            --accent-hover: #eab308;
            --success: #10b981;
            --error: #ef4444;
            --warning: #f59e0b;
            --dark: #0f172a;
            --darker: #020617;
            --light: #f8fafc;
            --gray: #64748b;
            --light-gray: #e2e8f0;
            --card-bg: #ffffff;
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: all 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 25px 50px rgba(0, 0, 0, 0.15);
            --shadow-xl: 0 35px 60px rgba(0, 0, 0, 0.2);
            --gradient: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            --gradient-hover: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            --gradient-light: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
            --neon-glow: 0 0 20px rgba(99, 102, 241, 0.4), 0 0 40px rgba(99, 102, 241, 0.2), 0 0 60px rgba(99, 102, 241, 0.1);
            --neon-glow-accent: 0 0 20px rgba(245, 158, 11, 0.4), 0 0 40px rgba(245, 158, 11, 0.2), 0 0 60px rgba(245, 158, 11, 0.1);
        }

        .dark-mode {
            --primary: #818cf8;
            --primary-dark: #6366f1;
            --primary-light: #3730a3;
            --secondary: #a78bfa;
            --accent: #fbbf24;
            --accent-hover: #f59e0b;
            --dark: #f1f5f9;
            --darker: #0f172a;
            --light: #0f172a;
            --gray: #94a3b8;
            --light-gray: #1e293b;
            --card-bg: #1e293b;
            --glass-bg: rgba(30, 41, 59, 0.6);
            --glass-border: rgba(255, 255, 255, 0.1);
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
            --shadow-lg: 0 25px 50px rgba(0, 0, 0, 0.35);
            --shadow-xl: 0 35px 60px rgba(0, 0, 0, 0.4);
            --neon-glow: 0 0 20px rgba(129, 140, 248, 0.4), 0 0 40px rgba(129, 140, 248, 0.2), 0 0 60px rgba(129, 140, 248, 0.1);
            --neon-glow-accent: 0 0 20px rgba(251, 191, 36, 0.4), 0 0 40px rgba(251, 191, 36, 0.2), 0 0 60px rgba(251, 191, 36, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--light);
            color: var(--dark);
            line-height: 1.7;
            min-height: 100vh;
            transition: var(--transition-slow);
            overflow-x: hidden;
        }

        /* Floating Background Elements */
        .floating-shapes {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: -1;
            pointer-events: none;
        }

        .shape {
            position: absolute;
            opacity: 0.03;
            border-radius: 50%;
            animation: float 25s infinite linear;
            filter: blur(40px);
        }

        .shape-1 {
            width: 200px;
            height: 200px;
            background: var(--primary);
            top: 10%;
            left: 5%;
            animation-delay: 0s;
        }

        .shape-2 {
            width: 150px;
            height: 150px;
            background: var(--secondary);
            top: 70%;
            left: 80%;
            animation-delay: -8s;
        }

        .shape-3 {
            width: 250px;
            height: 250px;
            background: var(--accent);
            top: 40%;
            left: 70%;
            animation-delay: -16s;
        }

        .shape-4 {
            width: 100px;
            height: 100px;
            background: var(--success);
            top: 20%;
            left: 85%;
            animation-delay: -12s;
        }

        .shape-5 {
            width: 180px;
            height: 180px;
            background: var(--error);
            top: 80%;
            left: 10%;
            animation-delay: -4s;
        }

        @keyframes float {
            0%, 100% {
                transform: translate(0, 0) rotate(0deg);
            }
            25% {
                transform: translate(20px, 50px) rotate(90deg);
            }
            50% {
                transform: translate(0, 100px) rotate(180deg);
            }
            75% {
                transform: translate(-20px, 50px) rotate(270deg);
            }
        }

        /* Navigation */
        .navbar {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--light-gray);
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: var(--shadow);
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--dark);
            text-decoration: none;
            transition: var(--transition);
        }

        .logo:hover {
            transform: translateY(-2px);
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: var(--gradient);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            box-shadow: var(--neon-glow);
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .theme-toggle {
            background: var(--gradient);
            border: none;
            color: white;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: var(--shadow);
        }

        .theme-toggle:hover {
            transform: scale(1.1) rotate(180deg);
            box-shadow: var(--neon-glow);
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--gradient-light);
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
        }

        .back-btn:hover {
            transform: translateX(-5px);
            box-shadow: var(--shadow);
        }

        /* Main Content */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 3rem 2rem;
        }

        /* Hero Section */
        .hero-section {
            text-align: center;
            margin-bottom: 4rem;
            position: relative;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--gradient-light);
            color: var(--primary);
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            margin-bottom: 2rem;
            border: 1px solid var(--light-gray);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .hero-title {
            font-size: 4rem;
            font-weight: 900;
            background: var(--gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 1.5rem;
            line-height: 1.1;
            animation: titleGlow 3s ease-in-out infinite alternate;
        }

        @keyframes titleGlow {
            from {
                filter: drop-shadow(0 0 20px rgba(99, 102, 241, 0.3));
            }
            to {
                filter: drop-shadow(0 0 30px rgba(139, 92, 246, 0.5));
            }
        }

        .hero-subtitle {
            font-size: 1.3rem;
            color: var(--gray);
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.6;
        }

        /* Benefits Section */
        .benefits-section {
            margin: 5rem 0;
            text-align: center;
        }

        .section-title {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .section-subtitle {
            font-size: 1.2rem;
            color: var(--gray);
            margin-bottom: 3rem;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .benefits-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-top: 3rem;
        }

        .benefit-card {
            background: var(--card-bg);
            padding: 2.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--light-gray);
            transition: var(--transition);
            text-align: left;
            position: relative;
            overflow: hidden;
        }

        .benefit-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--gradient);
        }

        .benefit-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-xl);
        }

        .benefit-icon {
            width: 70px;
            height: 70px;
            background: var(--gradient-light);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            color: var(--primary);
            font-size: 1.8rem;
        }

        .benefit-title {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .benefit-description {
            color: var(--gray);
            line-height: 1.6;
        }

        /* Use Cases Section */
        .use-cases-section {
            margin: 5rem 0;
            background: var(--gradient-light);
            padding: 4rem 2rem;
            border-radius: var(--radius-lg);
            position: relative;
            overflow: hidden;
        }

        .use-cases-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: var(--gradient);
            opacity: 0.05;
            transform: rotate(-45deg);
        }

        .use-cases-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            position: relative;
            z-index: 1;
        }

        .use-case-card {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            text-align: center;
            transition: var(--transition);
        }

        .use-case-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .use-case-icon {
            width: 60px;
            height: 60px;
            background: var(--gradient);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            color: white;
            font-size: 1.5rem;
        }

        .use-case-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .use-case-description {
            color: var(--gray);
            font-size: 0.95rem;
        }

        /* Testimonials Section */
        .testimonials-section {
            margin: 5rem 0;
            text-align: center;
        }

        .testimonials-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
            margin-top: 3rem;
        }

        .testimonial-card {
            background: var(--card-bg);
            padding: 2.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--light-gray);
            text-align: left;
            position: relative;
        }

        .testimonial-card::before {
            content: '"';
            position: absolute;
            top: 1rem;
            left: 1.5rem;
            font-size: 4rem;
            color: var(--primary-light);
            font-family: serif;
            line-height: 1;
        }

        .testimonial-content {
            font-style: italic;
            color: var(--gray);
            margin-bottom: 1.5rem;
            line-height: 1.6;
            position: relative;
            z-index: 1;
        }

        .testimonial-author {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .author-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.2rem;
        }

        .author-info h4 {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .author-info p {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Terms Content */
        .terms-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 3rem;
            align-items: start;
        }

        /* Table of Contents */
        .toc-sidebar {
            position: sticky;
            top: 120px;
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            padding: 2rem;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--light-gray);
        }

        .toc-title {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .toc-list {
            list-style: none;
        }

        .toc-item {
            margin-bottom: 0.75rem;
        }

        .toc-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            color: var(--gray);
            text-decoration: none;
            border-radius: var(--radius);
            transition: var(--transition);
            border-left: 3px solid transparent;
        }

        .toc-link:hover,
        .toc-link.active {
            background: var(--gradient-light);
            color: var(--primary);
            border-left-color: var(--primary);
            transform: translateX(5px);
        }

        .toc-number {
            font-weight: 600;
            font-size: 0.9rem;
            min-width: 20px;
        }

        /* Terms Content */
        .terms-content {
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            padding: 3rem;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--light-gray);
        }

        .last-updated {
            background: var(--gradient-light);
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-left: 4px solid var(--primary);
        }

        .last-updated i {
            color: var(--primary);
            font-size: 1.2rem;
        }

        .terms-section {
            margin-bottom: 3rem;
            opacity: 0;
            transform: translateY(30px);
            animation: fadeInUp 0.6s ease forwards;
        }

        @keyframes fadeInUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .section-number {
            width: 50px;
            height: 50px;
            background: var(--gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.2rem;
            box-shadow: var(--neon-glow);
        }

        .section-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
        }

        .terms-text {
            color: var(--gray);
            line-height: 1.8;
        }

        .terms-text p {
            margin-bottom: 1.5rem;
        }

        .terms-text ul, .terms-text ol {
            margin: 1.5rem 0;
            padding-left: 2rem;
        }

        .terms-text li {
            margin-bottom: 0.75rem;
            position: relative;
        }

        .terms-text li::before {
            content: '';
            position: absolute;
            left: -1rem;
            top: 0.75rem;
            width: 6px;
            height: 6px;
            background: var(--primary);
            border-radius: 50%;
        }

        .highlight-box {
            background: var(--gradient-light);
            padding: 2rem;
            border-radius: var(--radius);
            border-left: 4px solid var(--primary);
            margin: 2rem 0;
            position: relative;
            overflow: hidden;
        }

        .highlight-box::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: var(--gradient);
            opacity: 0.05;
            transform: rotate(-45deg);
        }

        .highlight-title {
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid var(--light-gray);
        }

        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            font-size: 1rem;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: var(--transition);
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: var(--gradient);
            color: white;
            box-shadow: var(--shadow);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg), var(--neon-glow);
        }

        .btn-secondary {
            background: var(--light-gray);
            color: var(--dark);
            border: 1px solid var(--light-gray);
        }

        .btn-secondary:hover {
            background: var(--gray);
            color: white;
            transform: translateY(-3px);
        }

        /* Stats Section */
        .stats-section {
            margin: 5rem 0;
            text-align: center;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
            margin-top: 3rem;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--light-gray);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .stat-number {
            font-size: 3rem;
            font-weight: 800;
            background: var(--gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--gray);
            font-weight: 600;
        }

        /* FAQ Section */
        .faq-section {
            margin: 5rem 0;
        }

        .faq-grid {
            display: grid;
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .faq-item {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--light-gray);
            overflow: hidden;
        }

        .faq-question {
            padding: 1.5rem;
            background: var(--gradient-light);
            border: none;
            width: 100%;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: var(--transition);
        }

        .faq-question:hover {
            background: var(--primary-light);
            color: var(--primary);
        }

        .faq-answer {
            padding: 0 1.5rem;
            max-height: 0;
            overflow: hidden;
            transition: var(--transition);
        }

        .faq-item.active .faq-answer {
            padding: 1.5rem;
            max-height: 500px;
        }

        .faq-item.active .faq-toggle {
            transform: rotate(180deg);
        }

        /* Footer */
        .footer {
            background: var(--card-bg);
            border-top: 1px solid var(--light-gray);
            margin-top: 4rem;
            padding: 3rem 0;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
            text-align: center;
            color: var(--gray);
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin-bottom: 1.5rem;
        }

        .footer-link {
            color: var(--gray);
            text-decoration: none;
            transition: var(--transition);
        }

        .footer-link:hover {
            color: var(--primary);
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .terms-container {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
            
            .toc-sidebar {
                position: static;
            }
            
            .hero-title {
                font-size: 3rem;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 2rem 1rem;
            }
            
            .hero-title {
                font-size: 2.5rem;
            }
            
            .terms-content {
                padding: 2rem 1.5rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .nav-container {
                padding: 1rem;
            }
            
            .section-title {
                font-size: 1.5rem;
            }
            
            .benefits-grid,
            .testimonials-grid,
            .use-cases-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Scroll Progress */
        .scroll-progress {
            position: fixed;
            top: 0;
            left: 0;
            width: 0%;
            height: 3px;
            background: var(--gradient);
            z-index: 1001;
            transition: width 0.3s ease;
        }

        /* Print Styles */
        @media print {
            .navbar, .toc-sidebar, .action-buttons, .footer {
                display: none;
            }
            
            body {
                background: white;
                color: black;
            }
        }
    </style>
</head>
<body>
    <!-- Floating Background -->
    <div class="floating-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
        <div class="shape shape-4"></div>
        <div class="shape shape-5"></div>
    </div>

    <!-- Scroll Progress -->
    <div class="scroll-progress" id="scrollProgress"></div>

    <!-- Navigation -->
    <nav class="navbar">
        <div class="nav-container">
            <a href="dashboard.php" class="logo">
                <div class="logo-icon">
                    <i class="fas fa-code"></i>
                </div>
                CodeSphere
            </a>
            <div class="nav-actions">
                <a href="dashboard.php" class="back-btn">
                    <ion-icon name="arrow-back-outline"></ion-icon>
                    Back to Dashboard
                </a>
                <button class="theme-toggle" id="themeToggle">
                    <i class="fas fa-moon"></i>
                </button>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <!-- Hero Section -->
        <section class="hero-section">
            <div class="hero-badge">
                <i class="fas fa-shield-alt"></i>
                Legal Documentation
            </div>
            <h1 class="hero-title">Terms & Conditions</h1>
            <p class="hero-subtitle">
                Please read these terms carefully before using CodeSphere. By accessing our platform, 
                you agree to be bound by these terms and our Privacy Policy.
            </p>
        </section>

        <!-- Benefits Section -->
        <section class="benefits-section">
            <h2 class="section-title">Why Developers Trust CodeSphere</h2>
            <p class="section-subtitle">Join thousands of developers who rely on our platform for secure, collaborative coding</p>
            
            <div class="benefits-grid">
                <div class="benefit-card">
                    <div class="benefit-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h3 class="benefit-title">Enterprise-Grade Security</h3>
                    <p class="benefit-description">Your code is protected with military-grade encryption, regular security audits, and advanced access controls to ensure complete data protection.</p>
                </div>
                
                <div class="benefit-card">
                    <div class="benefit-icon">
                        <ion-icon name="people-outline"></ion-icon>
                    </div>
                    <h3 class="benefit-title">Seamless Collaboration</h3>
                    <p class="benefit-description">Work together effortlessly with real-time editing, version control, and integrated communication tools designed for team productivity.</p>
                </div>
                
                <div class="benefit-card">
                    <div class="benefit-icon">
                        <i class="fas fa-rocket"></i>
                    </div>
                    <h3 class="benefit-title">Lightning Fast Performance</h3>
                    <p class="benefit-description">Experience blazing-fast code compilation, instant search, and responsive interfaces optimized for developer workflow efficiency.</p>
                </div>
            </div>
        </section>

        <!-- Use Cases Section -->
        <section class="use-cases-section">
            <h2 class="section-title" style="text-align: center; color: var(--dark);">Powerful Use Cases</h2>
            <p class="section-subtitle" style="text-align: center;">CodeSphere adapts to your development needs</p>
            
            <div class="use-cases-grid">
                <div class="use-case-card">
                    <div class="use-case-icon">
                        <ion-icon name="school-outline"></ion-icon>
                    </div>
                    <h3 class="use-case-title">Education & Learning</h3>
                    <p class="use-case-description">Perfect for coding bootcamps, university courses, and self-paced learning with collaborative features.</p>
                </div>
                
                <div class="use-case-card">
                    <div class="use-case-icon">
                        <i class="fas fa-briefcase"></i>
                    </div>
                    <h3 class="use-case-title">Enterprise Teams</h3>
                    <p class="use-case-description">Scale your development with robust project management and advanced security features.</p>
                </div>
                
                <div class="use-case-card">
                    <div class="use-case-icon">
                        <i class="fas fa-code-branch"></i>
                    </div>
                    <h3 class="use-case-title">Open Source Projects</h3>
                    <p class="use-case-description">Build and maintain open source software with community collaboration tools.</p>
                </div>
                
                <div class="use-case-card">
                    <div class="use-case-icon">
                        <i class="fas fa-mobile-alt"></i>
                    </div>
                    <h3 class="use-case-title">Startups & Prototyping</h3>
                    <p class="use-case-description">Rapidly prototype and iterate with agile development tools and flexible workflows.</p>
                </div>
            </div>
        </section>

        <!-- Stats Section -->
        <section class="stats-section">
            <h2 class="section-title">Trusted by Developers Worldwide</h2>
            <p class="section-subtitle">Join our growing community of innovative developers</p>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number">50K+</div>
                    <div class="stat-label">Active Developers</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">100K+</div>
                    <div class="stat-label">Projects Created</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">99.9%</div>
                    <div class="stat-label">Uptime Reliability</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">24/7</div>
                    <div class="stat-label">Support Available</div>
                </div>
            </div>
        </section>

        <!-- Testimonials Section -->
        <section class="testimonials-section">
            <h2 class="section-title">What Our Community Says</h2>
            <p class="section-subtitle">Hear from developers who transformed their workflow with CodeSphere</p>
            
            <div class="testimonials-grid">
                <div class="testimonial-card">
                    <p class="testimonial-content">
                        "CodeSphere revolutionized how our remote team collaborates. The real-time editing and version control features eliminated countless hours of merge conflicts and communication overhead."
                    </p>
                    <div class="testimonial-author">
                        <div class="author-avatar">SD</div>
                        <div class="author-info">
                            <h4>Sarah Johnson</h4>
                            <p>Lead Developer at TechCorp</p>
                        </div>
                    </div>
                </div>
                
                <div class="testimonial-card">
                    <p class="testimonial-content">
                        "As a freelance developer, CodeSphere's intuitive interface and powerful features help me deliver projects faster. The client collaboration tools are particularly impressive."
                    </p>
                    <div class="testimonial-author">
                        <div class="author-avatar">MD</div>
                        <div class="author-info">
                            <h4>Michael Chen</h4>
                            <p>Full-Stack Developer</p>
                        </div>
                    </div>
                </div>
                
                <div class="testimonial-card">
                    <p class="testimonial-content">
                        "The security features give us peace of mind for our enterprise applications. CodeSphere's compliance with industry standards makes it perfect for regulated environments."
                    </p>
                    <div class="testimonial-author">
                        <div class="author-avatar">EJ</div>
                        <div class="author-info">
                            <h4>Emily Rodriguez</h4>
                            <p>CTO at SecureBank</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- FAQ Section -->
        <section class="faq-section">
            <h2 class="section-title">Frequently Asked Questions</h2>
            <p class="section-subtitle">Quick answers to common questions about our terms and services</p>
            
            <div class="faq-grid">
                <div class="faq-item">
                    <button class="faq-question">
                        Can I export my code if I decide to leave CodeSphere?
                        <i class="fas fa-chevron-down faq-toggle"></i>
                    </button>
                    <div class="faq-answer">
                        <p>Yes, you can export all your code, projects, and data at any time. We believe in data portability and provide easy export tools in your account settings.</p>
                    </div>
                </div>
                
                <div class="faq-item">
                    <button class="faq-question">
                        How does CodeSphere protect my intellectual property?
                        <i class="fas fa-chevron-down faq-toggle"></i>
                    </button>
                    <div class="faq-answer">
                        <p>You retain full ownership of your code. Our terms explicitly state that we don't claim any ownership rights to your intellectual property. We only require limited licenses to provide our services.</p>
                    </div>
                </div>
                
                <div class="faq-item">
                    <button class="faq-question">
                        What happens to my data if my account is terminated?
                        <i class="fas fa-chevron-down faq-toggle"></i>
                    </button>
                    <div class="faq-answer">
                        <p>We provide a 30-day grace period for data recovery after account termination. You'll receive multiple notifications and can export your data during this period.</p>
                    </div>
                </div>
                
                <div class="faq-item">
                    <button class="faq-question">
                        Are there any restrictions on the types of projects I can host?
                        <i class="fas fa-chevron-down faq-toggle"></i>
                    </button>
                    <div class="faq-answer">
                        <p>We prohibit illegal content, malware, and projects that violate our acceptable use policy. Beyond that, you're free to host any legitimate software project.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Terms Content -->
        <div class="terms-container">
            <!-- Table of Contents -->
            <aside class="toc-sidebar">
                <h3 class="toc-title">
                    <i class="fas fa-list-ol"></i>
                    Contents
                </h3>
                <ul class="toc-list">
                    <li class="toc-item">
                        <a href="#acceptance" class="toc-link active">
                            <span class="toc-number">1</span>
                            Acceptance of Terms
                        </a>
                    </li>
                    <li class="toc-item">
                        <a href="#accounts" class="toc-link">
                            <span class="toc-number">2</span>
                            User Accounts
                        </a>
                    </li>
                    <li class="toc-item">
                        <a href="#conduct" class="toc-link">
                            <span class="toc-number">3</span>
                            Code of Conduct
                        </a>
                    </li>
                    <li class="toc-item">
                        <a href="#intellectual" class="toc-link">
                            <span class="toc-number">4</span>
                            Intellectual Property
                        </a>
                    </li>
                    <li class="toc-item">
                        <a href="#collaboration" class="toc-link">
                            <span class="toc-number">5</span>
                            Collaboration
                        </a>
                    </li>
                    <li class="toc-item">
                        <a href="#privacy" class="toc-link">
                            <span class="toc-number">6</span>
                            Privacy & Data
                        </a>
                    </li>
                    <li class="toc-item">
                        <a href="#termination" class="toc-link">
                            <span class="toc-number">7</span>
                            Termination
                        </a>
                    </li>
                    <li class="toc-item">
                        <a href="#liability" class="toc-link">
                            <span class="toc-number">8</span>
                            Liability
                        </a>
                    </li>
                </ul>
            </aside>

            <!-- Main Terms -->
            <main class="terms-content">
                <div class="last-updated">
                    <ion-icon name="time-outline"></ion-icon>
                    <div>
                        <strong>Last Updated:</strong> December 1, 2026
                    </div>
                </div>

                <!-- Section 1 -->
                <section id="acceptance" class="terms-section">
                    <div class="section-header">
                        <div class="section-number">1</div>
                        <h2 class="section-title">Acceptance of Terms</h2>
                    </div>
                    <div class="terms-text">
                        <p>
                            Welcome to CodeSphere. By accessing and using our platform, you acknowledge that you have read, 
                            understood, and agree to be bound by these Terms and Conditions.
                        </p>
                        
                        <div class="highlight-box">
                            <h4 class="highlight-title">
                                <ion-icon name="warning-outline"></ion-icon>
                                Important Notice
                            </h4>
                            <p>
                                These terms constitute a legally binding agreement between you and CodeSphere. 
                                If you do not agree with any part of these terms, you must immediately discontinue 
                                using our services.
                            </p>
                        </div>

                        <p>
                            We reserve the right to modify these terms at any time. Continued use of the platform 
                            after changes constitutes acceptance of the modified terms.
                        </p>
                    </div>
                </section>

                <!-- Section 2 -->
                <section id="accounts" class="terms-section">
                    <div class="section-header">
                        <div class="section-number">2</div>
                        <h2 class="section-title">User Accounts</h2>
                    </div>
                    <div class="terms-text">
                        <p>
                            To access certain features of CodeSphere, you must create an account. You are responsible for:
                        </p>
                        <ul>
                            <li>Maintaining the confidentiality of your account credentials</li>
                            <li>All activities that occur under your account</li>
                            <li>Providing accurate and complete information during registration</li>
                            <li>Promptly updating your information to keep it current</li>
                        </ul>
                        
                        <p>
                            You must be at least 13 years old to use CodeSphere. If you are under 18, you must have 
                            parental consent to use our services.
                        </p>
                    </div>
                </section>

                <!-- Section 3 -->
                <section id="conduct" class="terms-section">
                    <div class="section-header">
                        <div class="section-number">3</div>
                        <h2 class="section-title">Code of Conduct</h2>
                    </div>
                    <div class="terms-text">
                        <p>
                            CodeSphere is built on principles of respect, collaboration, and innovation. You agree not to:
                        </p>
                        <ul>
                            <li>Upload or share malicious code, viruses, or harmful software</li>
                            <li>Harass, threaten, or discriminate against other users</li>
                            <li>Violate intellectual property rights of others</li>
                            <li>Use the platform for illegal activities or purposes</li>
                            <li>Attempt to gain unauthorized access to other users' repositories</li>
                            <li>Spam or engage in disruptive behavior</li>
                        </ul>

                        <div class="highlight-box">
                            <h4 class="highlight-title">
                                <ion-icon name="people-outline"></ion-icon>
                                Community Guidelines
                            </h4>
                            <p>
                                Our community thrives on mutual respect and constructive collaboration. 
                                We encourage positive interactions and innovative problem-solving while 
                                maintaining a safe environment for all users.
                            </p>
                        </div>
                    </div>
                </section>

                <!-- Section 4 -->
                <section id="intellectual" class="terms-section">
                    <div class="section-header">
                        <div class="section-number">4</div>
                        <h2 class="section-title">Intellectual Property</h2>
                    </div>
                    <div class="terms-text">
                        <p>
                            You retain all ownership rights to the code you create and upload to CodeSphere. 
                            However, by using our platform, you grant us a license to:
                        </p>
                        <ul>
                            <li>Store, display, and distribute your code as part of our services</li>
                            <li>Make backup copies and ensure data redundancy</li>
                            <li>Allow collaboration with users you explicitly authorize</li>
                        </ul>
                        
                        <p>
                            CodeSphere's platform, branding, and proprietary technology remain our intellectual 
                            property and are protected by copyright and trademark laws.
                        </p>
                    </div>
                </section>

                <!-- Section 5 -->
                <section id="collaboration" class="terms-section">
                    <div class="section-header">
                        <div class="section-number">5</div>
                        <h2 class="section-title">Collaboration Features</h2>
                    </div>
                    <div class="terms-text">
                        <p>
                            CodeSphere provides powerful collaboration tools. When using these features:
                        </p>
                        <ul>
                            <li>You control who can access your repositories</li>
                            <li>Collaborators must respect your specified access levels</li>
                            <li>You are responsible for managing collaborator permissions</li>
                            <li>We are not liable for actions taken by your collaborators</li>
                        </ul>

                        <div class="highlight-box">
                            <h4 class="highlight-title">
                                <i class="fas fa-handshake"></i>
                                Collaboration Best Practices
                            </h4>
                            <p>
                                Establish clear guidelines with your collaborators regarding code ownership, 
                                contribution expectations, and project goals. Use our permission system to 
                                maintain appropriate access controls.
                            </p>
                        </div>
                    </div>
                </section>

                <!-- Section 6 -->
                <section id="privacy" class="terms-section">
                    <div class="section-header">
                        <div class="section-number">6</div>
                        <h2 class="section-title">Privacy & Data Protection</h2>
                    </div>
                    <div class="terms-text">
                        <p>
                            We are committed to protecting your privacy and personal data. Our Privacy Policy 
                            explains how we collect, use, and protect your information.
                        </p>
                        <ul>
                            <li>We implement industry-standard security measures</li>
                            <li>Your code and data are stored securely</li>
                            <li>We do not sell your personal information to third parties</li>
                            <li>You can export your data at any time</li>
                        </ul>
                    </div>
                </section>

                <!-- Section 7 -->
                <section id="termination" class="terms-section">
                    <div class="section-header">
                        <div class="section-number">7</div>
                        <h2 class="section-title">Termination</h2>
                    </div>
                    <div class="terms-text">
                        <p>
                            You may terminate your account at any time by contacting support or using the 
                            account deletion feature in your settings.
                        </p>
                        <p>
                            We reserve the right to suspend or terminate accounts that violate these terms 
                            or engage in harmful activities. In such cases:
                        </p>
                        <ul>
                            <li>We will provide notice unless immediate action is required</li>
                            <li>You may appeal the decision through our support system</li>
                            <li>Legal violations will be reported to appropriate authorities</li>
                        </ul>
                    </div>
                </section>

                <!-- Section 8 -->
                <section id="liability" class="terms-section">
                    <div class="section-header">
                        <div class="section-number">8</div>
                        <h2 class="section-title">Limitation of Liability</h2>
                    </div>
                    <div class="terms-text">
                        <p>
                            CodeSphere is provided "as is" without warranties of any kind. To the extent 
                            permitted by law, we are not liable for:
                        </p>
                        <ul>
                            <li>Indirect, incidental, or consequential damages</li>
                            <li>Loss of data or code due to circumstances beyond our control</li>
                            <li>Actions of other users on the platform</li>
                            <li>Service interruptions or downtime</li>
                        </ul>

                        <div class="highlight-box">
                            <h4 class="highlight-title">
                                <i class="fas fa-balance-scale"></i>
                                Legal Jurisdiction
                            </h4>
                            <p>
                                These terms are governed by the laws of [Your Jurisdiction]. Any disputes 
                                shall be resolved through arbitration in accordance with our dispute 
                                resolution policy.
                            </p>
                        </div>
                    </div>
                </section>

                <!-- Action Buttons -->
                <div class="action-buttons">
                    <a href="dashboard.php" class="btn btn-primary">
                        <i class="fas fa-check-circle"></i>
                        I Accept & Continue
                    </a>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-home"></i>
                        Back to Dashboard
                    </a>
                </div>
            </main>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <div class="footer-links">
                <a href="privacy.php" class="footer-link">Privacy Policy</a>
                <a href="terms.php" class="footer-link">Terms & Conditions</a>
                <a href="contact.php" class="footer-link">Contact Us</a>
                <a href="support.php" class="footer-link">Support</a>
            </div>
            <p>&copy; 2026 CodeSphere. All rights reserved. Empowering developers worldwide.</p>
        </div>
    </footer>

    <script>
        // Theme Toggle
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = themeToggle.querySelector('i');

        if (localStorage.getItem('theme') === 'dark' || 
            (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.body.classList.add('dark-mode');
            themeIcon.classList.remove('fa-moon');
            themeIcon.classList.add('fa-sun');
        }

        themeToggle.addEventListener('click', () => {
            document.body.classList.toggle('dark-mode');
            if (document.body.classList.contains('dark-mode')) {
                themeIcon.classList.remove('fa-moon');
                themeIcon.classList.add('fa-sun');
                localStorage.setItem('theme', 'dark');
            } else {
                themeIcon.classList.remove('fa-sun');
                themeIcon.classList.add('fa-moon');
                localStorage.setItem('theme', 'light');
            }
        });

        // Scroll Progress
        window.addEventListener('scroll', () => {
            const winScroll = document.body.scrollTop || document.documentElement.scrollTop;
            const height = document.documentElement.scrollHeight - document.documentElement.clientHeight;
            const scrolled = (winScroll / height) * 100;
            document.getElementById('scrollProgress').style.width = scrolled + '%';
        });

        // Table of Contents Navigation
        const tocLinks = document.querySelectorAll('.toc-link');
        const sections = document.querySelectorAll('.terms-section');

        // Update active TOC link on scroll
        window.addEventListener('scroll', () => {
            let current = '';
            
            sections.forEach(section => {
                const sectionTop = section.offsetTop - 100;
                const sectionHeight = section.clientHeight;
                if (scrollY >= sectionTop && scrollY < sectionTop + sectionHeight) {
                    current = section.getAttribute('id');
                }
            });

            tocLinks.forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('href').substring(1) === current) {
                    link.classList.add('active');
                }
            });
        });

        // Smooth scrolling for TOC links
        tocLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const targetId = link.getAttribute('href').substring(1);
                const targetSection = document.getElementById(targetId);
                
                if (targetSection) {
                    window.scrollTo({
                        top: targetSection.offsetTop - 80,
                        behavior: 'smooth'
                    });
                }
            });
        });

        // FAQ Accordion
        const faqItems = document.querySelectorAll('.faq-item');
        faqItems.forEach(item => {
            const question = item.querySelector('.faq-question');
            question.addEventListener('click', () => {
                // Close all other items
                faqItems.forEach(otherItem => {
                    if (otherItem !== item) {
                        otherItem.classList.remove('active');
                    }
                });
                // Toggle current item
                item.classList.toggle('active');
            });
        });

        // Stagger animations for sections
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.animationDelay = `${entry.target.dataset.delay || '0s'}`;
                    entry.target.classList.add('animate-in');
                }
            });
        }, observerOptions);

        // Observe all animated sections
        const animatedSections = document.querySelectorAll('.terms-section, .benefit-card, .use-case-card, .testimonial-card, .stat-card');
        animatedSections.forEach((section, index) => {
            section.dataset.delay = `${index * 0.1}s`;
            observer.observe(section);
        });

        // Add parallax effect to floating shapes
        document.addEventListener('mousemove', (e) => {
            const shapes = document.querySelectorAll('.shape');
            const mouseX = e.clientX / window.innerWidth;
            const mouseY = e.clientY / window.innerHeight;
            
            shapes.forEach((shape, index) => {
                const speed = (index + 1) * 0.5;
                const x = (mouseX - 0.5) * speed * 20;
                const y = (mouseY - 0.5) * speed * 20;
                
                shape.style.transform = `translate(${x}px, ${y}px) rotate(${x}deg)`;
            });
        });

        // Animate stats counting
        const statNumbers = document.querySelectorAll('.stat-number');
        const observerStats = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const stat = entry.target;
                    const target = parseInt(stat.textContent);
                    let current = 0;
                    const increment = target / 50;
                    
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= target) {
                            stat.textContent = target + (stat.textContent.includes('%') ? '%' : '+');
                            clearInterval(timer);
                        } else {
                            stat.textContent = Math.floor(current) + (stat.textContent.includes('%') ? '%' : '+');
                        }
                    }, 30);
                    
                    observerStats.unobserve(stat);
                }
            });
        }, { threshold: 0.5 });

        statNumbers.forEach(stat => observerStats.observe(stat));
    </script>
</body>
</html>