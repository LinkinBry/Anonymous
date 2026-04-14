<?php
session_start();
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: admin_dashboard.php");
    } else {
        header("Location: dashboard.php");
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OLSHCO — Faculty Evaluation Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
:root{
    --maroon:#8B0000;
    --maroon-dark:#6B0000;
    --gold:#F5A623;
    --gold-light:#FFD700;
    --white:#ffffff;
}
html{scroll-behavior:smooth;}
body{font-family:'Inter',sans-serif;overflow-x:hidden;}

/* ── NAVBAR ─────────────────────────────────────────────────── */
.navbar{
    position:fixed;top:0;left:0;right:0;z-index:1000;
    display:flex;align-items:center;justify-content:space-between;
    padding:14px 60px;
    background:rgba(0,0,0,0.35);
    backdrop-filter:blur(10px);
    -webkit-backdrop-filter:blur(10px);
    border-bottom:1px solid rgba(255,255,255,0.1);
    transition:background 0.3s;
}
.navbar.scrolled{background:rgba(90,0,0,0.95);}
.nav-brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.nav-brand img{width:42px;height:42px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.4);}
.nav-brand-name{color:#fff;font-size:18px;font-weight:700;letter-spacing:0.5px;}
.nav-links{display:flex;align-items:center;gap:36px;}
.nav-links a{color:rgba(255,255,255,0.88);text-decoration:none;font-size:14px;font-weight:500;transition:color 0.2s;}
.nav-links a:hover{color:#FFD700;}
.nav-actions{display:flex;align-items:center;gap:12px;}
.btn-signin{
    background:#8B0000;color:#fff;border:none;padding:9px 22px;
    border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;
    text-decoration:none;transition:background 0.2s;
}
.btn-signin:hover{background:#6B0000;}
.btn-register{
    background:transparent;color:#fff;border:2px solid rgba(255,255,255,0.6);
    padding:7px 22px;border-radius:6px;font-size:14px;font-weight:600;
    cursor:pointer;text-decoration:none;transition:all 0.2s;
}
.btn-register:hover{border-color:#fff;background:rgba(255,255,255,0.1);}

/* ── HERO ────────────────────────────────────────────────────── */
.hero{
    min-height:100vh;
    background:url('image/school_bg.jpg') center/cover no-repeat;
    position:relative;
    display:flex;align-items:center;
    padding:0 60px;
    overflow:hidden;
}
.hero::before{
    content:'';position:absolute;inset:0;
    background:linear-gradient(150deg,rgba(0,0,0,0.62) -60%,rgba(10,0,0,0.45) 30%,rgba(0,0,0,0.5) 100%);
}
.hero-content{position:relative;z-index:1;max-width:680px;margin-top:60px;margin-left:-25px;}
.hero h1{
    font-family:'Inter',sans-serif;
    font-size:clamp(36px,5vw,62px);
    font-weight:900;color:#fff;line-height:1.12;
    margin-bottom:6px;margin-left:40px;
}
.hero h1 .highlight{color:#F5A623;}
.hero-subtitle{
    font-size:clamp(14px,2vw,17px);color:rgba(255,255,255,0.78);
    line-height:1.7;margin-bottom:36px;max-width:540px;margin-left:40px;
}
.hero-actions{display:flex;align-items:center;gap:16px;flex-wrap:wrap;}
.hero-btn-primary{
    display:inline-flex;align-items:center;gap:9px;
    background:#8B0000;color:#fff;text-decoration:none;
    padding:14px 30px;border-radius:8px;font-size:15px;font-weight:700;
    transition:all 0.22s;box-shadow:0 4px 20px rgba(139,0,0,0.45);margin-left:40px;
}
.hero-btn-primary:hover{background:#a30000;transform:translateY(-2px);box-shadow:0 8px 28px rgba(139,0,0,0.5);}
.hero-btn-secondary{
    display:inline-flex;align-items:center;gap:9px;
    background:rgba(255,255,255,0.12);color:#fff;text-decoration:none;
    padding:14px 30px;border-radius:8px;font-size:15px;font-weight:600;
    border:2px solid rgba(255,255,255,0.35);transition:all 0.22s;backdrop-filter:blur(4px);
}
.hero-btn-secondary:hover{background:rgba(255,255,255,0.22);border-color:#fff;}
.hero-right{position:absolute;right:-130px;top:68%;transform:translateY(-45%);z-index:1;}
.hero-logo-container{
    position:relative;width:650px;height:600px;border-radius:50%;
    border:8px solid rgba(255,255,255,0.18);background:transparent;
    backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);
    box-shadow:0 24px 64px rgba(0,0,0,0.65),inset 0 0 40px rgba(255,255,255,0.08);
    display:flex;align-items:center;justify-content:center;overflow:hidden;
}
.hero-logo-container::before{
    content:'';position:absolute;inset:0;
    background:linear-gradient(180deg,rgba(255,255,255,0.06) 0%,rgba(255,255,255,0.01) 50%,rgba(255,255,255,0.06) 100%);
    pointer-events:none;
}
.hero-logo-img{
    position:relative;width:450px;height:450px;border-radius:50%;
    object-fit:cover;margin-right:90px;margin-bottom:35px;
    box-shadow:0 0 0 1px rgba(255,255,255,0.05),0 18px 40px rgba(0,0,0,0.32);
}

/* ── STATS BAR ───────────────────────────────────────────────── */
.stats-bar{
    background:linear-gradient(135deg,#8B0000 0%,#6B0000 100%);
    padding:32px 60px;
    display:grid;grid-template-columns:repeat(4,1fr);gap:20px;
}
.stat-item{text-align:center;}
.stat-num{font-size:38px;font-weight:800;color:#FFD700;line-height:1;}
.stat-lbl{font-size:13px;color:rgba(255,255,255,0.75);margin-top:4px;font-weight:500;}

/* ── SECTION COMMONS ─────────────────────────────────────────── */
.section{padding:90px 60px;}
.section-tag{
    display:inline-block;background:#fff0f0;color:#8B0000;
    font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;
    padding:5px 14px;border-radius:20px;margin-bottom:12px;border:1px solid rgba(139,0,0,0.15);
}
.section-title{
    font-family:'Playfair Display',serif;font-size:clamp(28px,4vw,42px);
    color:#1a1a2e;font-weight:700;margin-bottom:14px;line-height:1.2;
}
.section-title span{color:#8B0000;}
.section-desc{font-size:16px;color:#555;line-height:1.75;max-width:600px;}

/* ── ABOUT SYSTEM ────────────────────────────────────────────── */
.about-section{background:#fff;}
.about-grid{display:grid;grid-template-columns:1fr 1fr;gap:70px;align-items:center;}
.about-visual{position:relative;}
.about-img-card{
    background:linear-gradient(135deg,#8B0000 0%,#5a0000 100%);
    border-radius:20px;padding:36px;color:#fff;
    box-shadow:0 20px 60px rgba(139,0,0,0.28);
    display:flex;flex-direction:column;align-items:center;text-align:center;
}
.about-logo-img{
    width:120px;height:120px;border-radius:50%;object-fit:cover;
    border:3px solid rgba(255,255,255,0.35);margin-bottom:20px;
    box-shadow:0 8px 24px rgba(0,0,0,0.3);
}
.about-img-card h3{font-size:20px;font-weight:700;margin-bottom:8px;}
.about-img-card p{font-size:14px;opacity:0.82;line-height:1.65;}
.about-badge{
    position:absolute;bottom:-18px;right:-18px;
    background:#FFD700;color:#1a1a2e;
    width:90px;height:90px;border-radius:50%;
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    font-weight:800;font-size:13px;text-align:center;line-height:1.2;
    box-shadow:0 8px 24px rgba(245,166,35,0.4);
}
.about-badge strong{font-size:22px;display:block;}
.features-list{display:flex;flex-direction:column;gap:20px;margin-top:28px;}
.feature-item{display:flex;gap:16px;align-items:flex-start;}
.feature-icon{
    width:46px;height:46px;border-radius:12px;
    background:#fff0f0;display:flex;align-items:center;justify-content:center;
    flex-shrink:0;color:#8B0000;
}
.feature-text h4{font-size:15px;font-weight:700;color:#1a1a2e;margin-bottom:4px;}
.feature-text p{font-size:13px;color:#666;line-height:1.6;}

/* ── HOW IT WORKS ────────────────────────────────────────────── */
.how-section{background:#fafafa;}
.steps-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:28px;margin-top:52px;}
.step-card{
    background:#fff;border-radius:16px;padding:28px 22px;text-align:center;
    border:1px solid #eee;box-shadow:0 2px 16px rgba(0,0,0,0.05);
    transition:all 0.25s;position:relative;overflow:hidden;
}
.step-card:hover{transform:translateY(-6px);box-shadow:0 12px 40px rgba(139,0,0,0.12);border-color:rgba(139,0,0,0.2);}
.step-card::before{
    content:'';position:absolute;top:0;left:0;right:0;height:4px;
    background:linear-gradient(90deg,#8B0000,#F5A623);
}
.step-num{
    width:48px;height:48px;border-radius:50%;
    background:linear-gradient(135deg,#8B0000,#6B0000);
    color:#fff;font-size:18px;font-weight:800;
    display:flex;align-items:center;justify-content:center;
    margin:0 auto 16px;box-shadow:0 4px 14px rgba(139,0,0,0.35);
}
.step-icon-svg{display:flex;justify-content:center;margin-bottom:14px;color:#8B0000;}
.step-card h4{font-size:15px;font-weight:700;color:#1a1a2e;margin-bottom:8px;}
.step-card p{font-size:13px;color:#666;line-height:1.6;}

/* ── FEATURES HIGHLIGHT ──────────────────────────────────────── */
.features-section{
    background:linear-gradient(135deg,#8B0000 0%,#5a0000 100%);
    position:relative;overflow:hidden;
}
/* Modern diagonal line pattern instead of polkadots */
.features-section::before{
    content:'';position:absolute;inset:0;
    background-image:repeating-linear-gradient(
        -45deg,
        transparent,
        transparent 18px,
        rgba(255,255,255,0.03) 18px,
        rgba(255,255,255,0.03) 19px
    );
    pointer-events:none;
}
.features-inner{position:relative;z-index:1;}
.features-section .section-title{color:#fff;}
.features-section .section-desc{color:rgba(255,255,255,0.75);}
.features-section .section-tag{background:rgba(255,215,0,0.15);color:#FFD700;border-color:rgba(255,215,0,0.3);}
.feat-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;margin-top:52px;}
.feat-card{
    background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);
    border-radius:16px;padding:28px;backdrop-filter:blur(6px);transition:all 0.25s;
}
.feat-card:hover{background:rgba(255,255,255,0.12);transform:translateY(-4px);}
.feat-card-icon{
    width:52px;height:52px;border-radius:12px;
    background:rgba(245,166,35,0.2);display:flex;align-items:center;justify-content:center;
    margin-bottom:16px;color:#FFD700;
}
.feat-card h4{font-size:16px;font-weight:700;color:#fff;margin-bottom:8px;}
.feat-card p{font-size:13px;color:rgba(255,255,255,0.68);line-height:1.65;}

/* ── TEAM ────────────────────────────────────────────────────── */
.team-section{background:#fff;}
.team-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:28px;margin-top:52px;}
.team-card{text-align:center;}
.team-avatar-wrap{
    width:100px;height:100px;border-radius:50%;margin:0 auto 14px;
    overflow:hidden;
    border:3px solid #f0f0f0;
    box-shadow:0 4px 16px rgba(139,0,0,0.2);
    background:#f9f0f0;
}
.team-avatar-wrap img{
    width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;
}
/* Fallback avatar shown when no image provided */
.team-avatar-fallback{
    width:100%;height:100%;
    background:linear-gradient(135deg,#8B0000,#6B0000);
    display:flex;align-items:center;justify-content:center;
}
.team-name{font-size:15px;font-weight:700;color:#1a1a2e;margin-bottom:3px;}
.team-role{font-size:12px;color:#8B0000;font-weight:600;margin-bottom:6px;}
.team-desc{font-size:12px;color:#777;line-height:1.55;}

/* ── CONTACT ─────────────────────────────────────────────────── */
.contact-section{background:#fafafa;}
.contact-grid{display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:start;margin-top:52px;}
.contact-info{display:flex;flex-direction:column;gap:22px;}
.contact-item{display:flex;gap:16px;align-items:flex-start;}
.contact-icon{
    width:46px;height:46px;border-radius:12px;
    background:#fff0f0;display:flex;align-items:center;justify-content:center;
    flex-shrink:0;color:#8B0000;
}
.contact-item-text h4{font-size:14px;font-weight:700;color:#1a1a2e;margin-bottom:3px;}
.contact-item-text p{font-size:13px;color:#666;line-height:1.6;}
.contact-form{background:#fff;border-radius:16px;padding:32px;box-shadow:0 4px 24px rgba(0,0,0,0.07);}
.cf-group{margin-bottom:16px;}
.cf-group label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;}
.cf-group input,.cf-group textarea{
    width:100%;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:9px;
    font-family:'Inter',sans-serif;font-size:14px;color:#1a1a2e;outline:none;
    transition:border-color 0.2s;background:#fafafa;
}
.cf-group input:focus,.cf-group textarea:focus{border-color:#8B0000;background:#fff;box-shadow:0 0 0 3px rgba(139,0,0,0.08);}
.cf-group textarea{min-height:110px;resize:vertical;}
.cf-submit{
    width:100%;padding:12px;background:#8B0000;color:#fff;border:none;
    border-radius:9px;font-size:15px;font-weight:700;cursor:pointer;
    font-family:'Inter',sans-serif;transition:background 0.2s;
}
.cf-submit:hover{background:#a30000;}

/* ── FOOTER ─────────────────────────────────────────────────── */
footer{
    background:#1a1a2e;padding:40px 60px;
    display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;
}
.footer-brand{display:flex;align-items:center;gap:12px;}
.footer-brand img{width:38px;height:38px;border-radius:50%;border:2px solid rgba(255,255,255,0.2);}
.footer-brand-name{color:#fff;font-size:15px;font-weight:700;}
.footer-brand-sub{color:rgba(255,255,255,0.45);font-size:11px;}
.footer-copy{color:rgba(255,255,255,0.45);font-size:12px;}
.footer-links{display:flex;gap:20px;}
.footer-links a{color:rgba(255,255,255,0.55);font-size:12px;text-decoration:none;transition:color 0.2s;}
.footer-links a:hover{color:#FFD700;}

/* ── SCROLL REVEAL ───────────────────────────────────────────── */
.reveal{opacity:0;transform:translateY(32px);transition:opacity 0.65s ease,transform 0.65s ease;}
.reveal.visible{opacity:1;transform:translateY(0);}

/* ── RESPONSIVE ──────────────────────────────────────────────── */
@media(max-width:900px){
    .navbar{padding:12px 24px;}
    .hero{padding:0 24px;min-height:100vh;}
    .hero-right{display:none;}
    .stats-bar{grid-template-columns:repeat(2,1fr);padding:28px 24px;}
    .section{padding:60px 24px;}
    .about-grid,.contact-grid{grid-template-columns:1fr;}
    .steps-grid,.feat-cards{grid-template-columns:repeat(2,1fr);}
    footer{flex-direction:column;text-align:center;padding:28px 24px;}
}
@media(max-width:560px){
    .nav-links{display:none;}
    .steps-grid,.feat-cards,.team-grid{grid-template-columns:1fr;}
}
</style>
</head>
<body>

<!-- ══ NAVBAR ════════════════════════════════════════════════════════════ -->
<nav class="navbar" id="navbar">
    <a href="#" class="nav-brand">
        <img src="image/logo.png" alt="OLSHCO Logo" onerror="this.style.display='none'">
        <span class="nav-brand-name">OLSHCO</span>
    </a>
    <div class="nav-links">
        <a href="#home">Home</a>
        <a href="#about">About us</a>
        <a href="#how">How it Works</a>
        <a href="#team">Team</a>
        <a href="#contact">Contact</a>
    </div>
    <div class="nav-actions">
        <a href="login.php" class="btn-signin">SIGN IN</a>
        <a href="register.php" class="btn-register">REGISTER</a>
    </div>
</nav>

<!-- ══ HERO ══════════════════════════════════════════════════════════════ -->
<section class="hero" id="home">
    <div class="hero-content">
        <h1>
            Welcome to <span class="highlight">OLSHCO</span><br>
            <span class="highlight">Faculty Evaluation</span><br>
            Portal.
        </h1>
        <p class="hero-subtitle">
            Bridging the gap between feedback and academic growth.
            Share honest, anonymous evaluations to help our faculty deliver better education.
        </p>
        <div class="hero-actions">
            <a href="login.php" class="hero-btn-primary">
                <!-- Login / arrow-right SVG -->
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>
                Get Started
            </a>
            <a href="#about" class="hero-btn-secondary">
                <!-- Clock / learn more SVG -->
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Learn More
            </a>
        </div>
    </div>
    <div class="hero-right">
        <div class="hero-logo-container">
            <img src="image/logo.png" alt="OLSHCO Seal" class="hero-logo-img" onerror="this.style.display='none'">
        </div>
    </div>
</section>

<!-- ══ STATS BAR ══════════════════════════════════════════════════════════ -->
<div class="stats-bar">
    <div class="stat-item">
        <div class="stat-num">500+</div>
        <div class="stat-lbl">Student Reviews</div>
    </div>
    <div class="stat-item">
        <div class="stat-num">50+</div>
        <div class="stat-lbl">Faculty Members</div>
    </div>
    <div class="stat-item">
        <div class="stat-num">100%</div>
        <div class="stat-lbl">Anonymous</div>
    </div>
    <div class="stat-item">
        <div class="stat-num">1947</div>
        <div class="stat-lbl">Established</div>
    </div>
</div>

<!-- ══ ABOUT SECTION ══════════════════════════════════════════════════════ -->
<section class="section about-section" id="about">
    <div class="about-grid">
        <div class="about-visual reveal">
            <div class="about-img-card">
                <!-- School logo instead of graduation hat -->
                <img
                    src="image/logo.png"
                    alt="OLSHCO Logo"
                    class="about-logo-img"
                    onerror="this.style.display='none'"
                >
                <h3>Our Lady of the Sacred Heart College of Guimba, Inc.</h3>
                <p>Established in 1947, OLSHCO has been a pillar of academic excellence in Guimba, Nueva Ecija. Our Faculty Evaluation Portal empowers students to contribute meaningfully to the institution's continuous improvement.</p>
            </div>
            <div class="about-badge"><strong>1947</strong>Est.</div>
        </div>
        <div class="about-text reveal">
            <div class="section-tag">About the System</div>
            <h2 class="section-title">Anonymous Online<br><span>Faculty Performance</span><br>Evaluation System</h2>
            <p class="section-desc">A secure, AI-powered platform where students can submit anonymous, honest evaluations of their faculty members — fostering accountability and driving academic excellence.</p>
            <div class="features-list">
                <div class="feature-item">
                    <div class="feature-icon">
                        <!-- Shield check -->
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </div>
                    <div class="feature-text">
                        <h4>Complete Anonymity</h4>
                        <p>Your identity is never revealed. Submit reviews with full confidence and honesty.</p>
                    </div>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">
                        <!-- CPU / AI -->
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 2v2M15 2v2M9 20v2M15 20v2M2 9h2M2 15h2M20 9h2M20 15h2"/></svg>
                    </div>
                    <div class="feature-text">
                        <h4>AI-Powered Moderation</h4>
                        <p>Groq AI ensures all reviews are constructive, respectful, and free from toxic content.</p>
                    </div>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">
                        <!-- Bar chart -->
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    </div>
                    <div class="feature-text">
                        <h4>Insightful Analytics</h4>
                        <p>Administrators access detailed performance reports to support data-driven academic decisions.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══ HOW IT WORKS ════════════════════════════════════════════════════════ -->
<section class="section how-section" id="how">
    <div style="text-align:center;" class="reveal">
        <div class="section-tag">How It Works</div>
        <h2 class="section-title">Simple. Secure. <span>Impactful.</span></h2>
        <p class="section-desc" style="margin:0 auto;">Four easy steps to make your voice heard and help shape the future of education at OLSHCO.</p>
    </div>
    <div class="steps-grid">
        <div class="step-card reveal">
            <div class="step-num">1</div>
            <div class="step-icon-svg">
                <!-- User plus -->
                <svg width="36" height="36" fill="none" stroke="#8B0000" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="16" y1="11" x2="22" y2="11"/></svg>
            </div>
            <h4>Create Account</h4>
            <p>Register with a pseudonym to keep your identity completely anonymous throughout the process.</p>
        </div>
        <div class="step-card reveal">
            <div class="step-num">2</div>
            <div class="step-icon-svg">
                <!-- Search / magnify -->
                <svg width="36" height="36" fill="none" stroke="#8B0000" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </div>
            <h4>Select Faculty</h4>
            <p>Browse the list of faculty members and choose the ones you want to evaluate based on your experience.</p>
        </div>
        <div class="step-card reveal">
            <div class="step-num">3</div>
            <div class="step-icon-svg">
                <!-- Star -->
                <svg width="36" height="36" fill="none" stroke="#8B0000" stroke-width="1.8" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            </div>
            <h4>Rate &amp; Review</h4>
            <p>Give star ratings across 5 categories and write your honest, constructive feedback.</p>
        </div>
        <div class="step-card reveal">
            <div class="step-num">4</div>
            <div class="step-icon-svg">
                <!-- Check circle -->
                <svg width="36" height="36" fill="none" stroke="#8B0000" stroke-width="1.8" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <h4>Admin Reviews</h4>
            <p>Admins moderate submissions for quality, then publish approved reviews to the platform.</p>
        </div>
    </div>
</section>

<!-- ══ FEATURES ════════════════════════════════════════════════════════════ -->
<section class="section features-section">
    <div class="features-inner">
        <div class="reveal" style="text-align:center;">
            <div class="section-tag">Key Features</div>
            <h2 class="section-title">Everything You Need for <span style="color:#FFD700;">Better Evaluation</span></h2>
            <p class="section-desc" style="margin:0 auto;">Built with modern technology to ensure a seamless, secure, and meaningful evaluation experience for every student and administrator.</p>
        </div>
        <div class="feat-cards">
            <div class="feat-card reveal">
                <div class="feat-card-icon">
                    <!-- Lock / anonymity -->
                    <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                </div>
                <h4>100% Anonymous Reviews</h4>
                <p>Pseudonym-based system ensures your real identity is never linked to your feedback — ever.</p>
            </div>
            <div class="feat-card reveal">
                <div class="feat-card-icon">
                    <!-- Sparkle / AI -->
                    <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z"/></svg>
                </div>
                <h4>AI Sentiment Analysis</h4>
                <p>Groq AI automatically analyzes sentiment and flags inappropriate content before it reaches admins.</p>
            </div>
            <div class="feat-card reveal">
                <div class="feat-card-icon">
                    <!-- Sliders / multi-dimension -->
                    <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="8" cy="6" r="2" fill="currentColor" stroke="none"/><circle cx="16" cy="12" r="2" fill="currentColor" stroke="none"/><circle cx="10" cy="18" r="2" fill="currentColor" stroke="none"/></svg>
                </div>
                <h4>Multi-Dimension Ratings</h4>
                <p>Rate faculty across Teaching, Communication, Punctuality, Fairness, and Overall Satisfaction.</p>
            </div>
            <div class="feat-card reveal">
                <div class="feat-card-icon">
                    <!-- Mail / notification -->
                    <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                </div>
                <h4>Instant Email Notifications</h4>
                <p>Get notified by email the moment your review is approved or rejected by the administrator.</p>
            </div>
            <div class="feat-card reveal">
                <div class="feat-card-icon">
                    <!-- Layout / dashboard -->
                    <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                </div>
                <h4>Admin Dashboard</h4>
                <p>Comprehensive admin panel with faculty leaderboards, weekly charts, and AI-generated monthly reports.</p>
            </div>
            <div class="feat-card reveal">
                <div class="feat-card-icon">
                    <!-- Chat bubble -->
                    <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                </div>
                <h4>FAQ AI Chatbot</h4>
                <p>Built-in Groq-powered chatbot answers student questions about the system 24/7.</p>
            </div>
        </div>
    </div>
</section>

<!-- ══ TEAM SECTION ════════════════════════════════════════════════════════ -->
<section class="section team-section" id="team">
    <div style="text-align:center;" class="reveal">
        <div class="section-tag">Meet the Team</div>
        <h2 class="section-title">The People Behind <span>OlshcoReview</span></h2>
        <p class="section-desc" style="margin:0 auto;">A dedicated group of students and developers who built this platform to improve academic quality at OLSHCO.</p>
    </div>
    <div class="team-grid">
        <!--
            INSTRUCTIONS: Replace src="" with the actual image path for each member.
            Example: src="image/team/member1.jpg"
            If no image is available, a maroon fallback silhouette will be displayed.
        -->
        <div class="team-card reveal">
            <div class="team-avatar-wrap">
                <img
                    src="image/member1.jpg"
                    alt="Bryan Jay Domingo"
                    onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                >
                <div class="team-avatar-fallback" style="display:none;">
                    <svg width="44" height="44" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="1.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
            </div>
            <div class="team-name">Bryan Jay Domingo</div>
            <div class="team-role">Lead Developer</div>
            <div class="team-desc">Architected and built the entire evaluation platform from the ground up.</div>
        </div>
 
        <div class="team-card reveal">
            <div class="team-avatar-wrap">
                <img
                    src="image/member2.jpg"
                    alt="Jamaica Estrada"
                    onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                >
                <div class="team-avatar-fallback" style="display:none;">
                    <svg width="44" height="44" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="1.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
            </div>
            <div class="team-name">Jamaica Estrada</div>
            <div class="team-role">UI/UX Designer</div>
            <div class="team-desc">Crafted the visual identity and user experience for a seamless journey.</div>
        </div>
 
        <div class="team-card reveal">
            <div class="team-avatar-wrap">
                <img
                    src="image/member3.jpg"
                    alt="Jan Poul Domingo"
                    onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                >
                <div class="team-avatar-fallback" style="display:none;">
                    <svg width="44" height="44" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="1.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
            </div>
            <div class="team-name">Jan Poul Domingo</div>
            <div class="team-role">AI Integration</div>
            <div class="team-desc">Integrated Groq AI for sentiment analysis, moderation, and chatbot features.</div>
        </div>
 
        <div class="team-card reveal">
            <div class="team-avatar-wrap">
                <img
                    src="image/member4.jpg"
                    alt="John Patrick Del Campo"
                    onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                >
                <div class="team-avatar-fallback" style="display:none;">
                    <svg width="44" height="44" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="1.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
            </div>
            <div class="team-name">John Patrick Del Campo</div>
            <div class="team-role">Data Analyst</div>
            <div class="team-desc">Designed the database schema and reporting analytics pipeline.</div>
        </div>
 
        <div class="team-card reveal">
            <div class="team-avatar-wrap">
                <img
                    src="image/member5.jpg"
                    alt="Prince Verlaine Busano"
                    onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                >
                <div class="team-avatar-fallback" style="display:none;">
                    <svg width="44" height="44" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="1.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
            </div>
            <div class="team-name">Prince Verlaine Busano</div>
            <div class="team-role">Security Lead</div>
            <div class="team-desc">Ensured platform security, anonymity protocols, and quality assurance.</div>
        </div>
    </div>
    
</section>

<!-- ══ CONTACT SECTION ════════════════════════════════════════════════════ -->
<section class="section contact-section" id="contact">
    <div class="reveal" style="text-align:center;">
        <div class="section-tag">Contact Us</div>
        <h2 class="section-title">Get in <span>Touch</span></h2>
        <p class="section-desc" style="margin:0 auto;">Have questions about the evaluation system? Reach out to the OlshcoReview team or the school administration.</p>
    </div>
    <div class="contact-grid">
        <div class="contact-info reveal">
            <div class="contact-item">
                <div class="contact-icon">
                    <!-- Map pin -->
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                </div>
                <div class="contact-item-text">
                    <h4>Address</h4>
                    <p>Our Lady of the Sacred Heart College of Guimba, Inc.<br>Guimba, Nueva Ecija, Philippines</p>
                </div>
            </div>
            <div class="contact-item">
                <div class="contact-icon">
                    <!-- Mail -->
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                </div>
                <div class="contact-item-text">
                    <h4>Email</h4>
                    <p>olshco@example.com<br>support@olshcoreview.edu.ph</p>
                </div>
            </div>
            <div class="contact-item">
                <div class="contact-icon">
                    <!-- Phone -->
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81 19.79 19.79 0 01.022 1.18 2 2 0 012 .022h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 14.92z"/></svg>
                </div>
                <div class="contact-item-text">
                    <h4>Phone</h4>
                    <p>+63 (044) 000-0000<br>Monday – Friday, 8AM – 5PM</p>
                </div>
            </div>
            <div class="contact-item">
                <div class="contact-icon">
                    <!-- Info circle -->
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                <div class="contact-item-text">
                    <h4>About the System</h4>
                    <p>For technical issues or feedback about the evaluation portal, use the form or contact the IT department directly.</p>
                </div>
            </div>
        </div>
        <div class="contact-form reveal">
            <h3 style="font-size:18px;font-weight:700;color:#1a1a2e;margin-bottom:20px;">Send a Message</h3>
            <form onsubmit="event.preventDefault(); alert('Message sent! We\'ll get back to you soon.');">
                <div class="cf-group">
                    <label>Full Name</label>
                    <input type="text" placeholder="Your full name" required>
                </div>
                <div class="cf-group">
                    <label>Email Address</label>
                    <input type="email" placeholder="your@email.com" required>
                </div>
                <div class="cf-group">
                    <label>Subject</label>
                    <input type="text" placeholder="How can we help?">
                </div>
                <div class="cf-group">
                    <label>Message</label>
                    <textarea placeholder="Write your message here..."></textarea>
                </div>
                <button type="submit" class="cf-submit">
                    Send Message
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="display:inline;margin-left:8px;vertical-align:middle;"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                </button>
            </form>
        </div>
    </div>
</section>

<!-- ══ FOOTER ═════════════════════════════════════════════════════════════ -->
<footer>
    <div class="footer-brand">
        <img src="image/logo.png" alt="OLSHCO" onerror="this.style.display='none'">
        <div>
            <div class="footer-brand-name">OlshcoReview</div>
            <div class="footer-brand-sub">Anonymous Faculty Evaluation System</div>
        </div>
    </div>
    <div class="footer-copy">© <?php echo date('Y'); ?> OLSHCO — All rights reserved.</div>
    <div class="footer-links">
        <a href="login.php">Sign In</a>
        <a href="register.php">Register</a>
        <a href="#about">About</a>
        <a href="#contact">Contact</a>
    </div>
</footer>

<script>
// Navbar scroll effect
window.addEventListener('scroll', () => {
    document.getElementById('navbar').classList.toggle('scrolled', window.scrollY > 60);
});

// Scroll reveal
const reveals = document.querySelectorAll('.reveal');
const io = new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); io.unobserve(e.target); } });
}, { threshold: 0.12 });
reveals.forEach(r => io.observe(r));

// Smooth scroll for nav links
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        e.preventDefault();
        const t = document.querySelector(a.getAttribute('href'));
        if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});
</script>
</body>
</html>