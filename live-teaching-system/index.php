<?php
// Start session at the VERY beginning
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Redirect logged-in users to their dashboard BEFORE any output
if (isset($_SESSION['user'])) {
    $role = $_SESSION['user']['role'];
    if ($role == 'admin') {
        header('Location: admin/dashboard.php');
        exit;
    } elseif ($role == 'teacher') {
        header('Location: teacher/dashboard.php');
        exit;
    } else {
        header('Location: dashboard/index.php');
        exit;
    }
}

require_once 'config/database.php';
require_once 'includes/header.php';

$connection = getDbConnection();

// Get featured classes
$featuredClasses = [];
$result = $connection->query("
    SELECT c.*, u.fullname as teacher_name,
           (SELECT COUNT(*) FROM enrollments WHERE class_id = c.id AND payment_status = 'paid') as enrolled_count
    FROM live_classes c 
    JOIN users u ON c.teacher_id = u.id 
    WHERE c.status = 'upcoming' AND c.is_featured = 1
    ORDER BY c.start_date ASC 
    LIMIT 3
");
while ($row = $result->fetch_assoc()) {
    $featuredClasses[] = $row;
}

// Get upcoming classes
$upcomingClasses = [];
$result = $connection->query("
    SELECT c.*, u.fullname as teacher_name,
           (SELECT COUNT(*) FROM enrollments WHERE class_id = c.id AND payment_status = 'paid') as enrolled_count
    FROM live_classes c 
    JOIN users u ON c.teacher_id = u.id 
    WHERE c.status = 'upcoming'
    ORDER BY c.start_date ASC 
    LIMIT 6
");
while ($row = $result->fetch_assoc()) {
    $upcomingClasses[] = $row;
}

// Get stats
$totalStudents = $connection->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
$totalClasses = $connection->query("SELECT COUNT(*) as count FROM live_classes")->fetch_assoc()['count'];
$totalTeachers = $connection->query("SELECT COUNT(*) as count FROM users WHERE role = 'teacher'")->fetch_assoc()['count'];
?>

<style>
/* ===== LiveTeach homepage revamp — scoped to .lt-page, does not touch global styles ===== */
.lt-page {
    --lt-ink: #100D0A;
    --lt-charcoal: #1B1712;
    --lt-charcoal-raised: #241F18;
    --lt-flame: #C8341E;
    --lt-flame-dark: #8A2213;
    --lt-flame-bright: #E44E2E;
    --lt-gold: #D9A441;
    --lt-parchment: #F1E7D6;
    --lt-parchment-dim: #C9BEAC;
    --lt-line: rgba(241, 231, 214, 0.12);
    background: var(--lt-ink);
    color: var(--lt-parchment);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    overflow-x: hidden;
}

.lt-page .lt-serif {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
}

.lt-page a { text-decoration: none; color: inherit; }

.lt-container {
    max-width: 1180px;
    margin: 0 auto;
    padding: 0 24px;
}

/* ============================================================= */
/* ANIMATION SYSTEM                                              */
/* ============================================================= */

/* --- Base reveal state --- */
.lt-reveal {
    opacity: 0;
    transform: translateY(28px);
    transition:
        opacity 0.7s cubic-bezier(0.22, 1, 0.36, 1),
        transform 0.7s cubic-bezier(0.22, 1, 0.36, 1);
    will-change: opacity, transform;
}

.lt-reveal.lt-visible {
    opacity: 1;
    transform: translateY(0);
}

/* Stagger delay utilities */
.lt-delay-1 { transition-delay: 0.08s; }
.lt-delay-2 { transition-delay: 0.16s; }
.lt-delay-3 { transition-delay: 0.24s; }
.lt-delay-4 { transition-delay: 0.32s; }
.lt-delay-5 { transition-delay: 0.40s; }
.lt-delay-6 { transition-delay: 0.48s; }

/* --- Fade variants --- */
.lt-reveal-left {
    opacity: 0;
    transform: translateX(-32px);
    transition:
        opacity 0.7s cubic-bezier(0.22, 1, 0.36, 1),
        transform 0.7s cubic-bezier(0.22, 1, 0.36, 1);
}
.lt-reveal-left.lt-visible { opacity: 1; transform: translateX(0); }

.lt-reveal-right {
    opacity: 0;
    transform: translateX(32px);
    transition:
        opacity 0.7s cubic-bezier(0.22, 1, 0.36, 1),
        transform 0.7s cubic-bezier(0.22, 1, 0.36, 1);
}
.lt-reveal-right.lt-visible { opacity: 1; transform: translateX(0); }

.lt-reveal-scale {
    opacity: 0;
    transform: scale(0.94);
    transition:
        opacity 0.6s cubic-bezier(0.22, 1, 0.36, 1),
        transform 0.6s cubic-bezier(0.22, 1, 0.36, 1);
}
.lt-reveal-scale.lt-visible { opacity: 1; transform: scale(1); }

/* ---------- Hero ---------- */
.lt-hero {
    position: relative;
    padding: 96px 0 80px;
    border-bottom: 1px solid var(--lt-line);
    overflow: hidden;
}

/* Animated ambient glow */
.lt-hero::before {
    content: "";
    position: absolute;
    top: -200px;
    right: -160px;
    width: 520px;
    height: 520px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(200, 52, 30, 0.35), transparent 70%);
    pointer-events: none;
    animation: lt-glow-float 12s ease-in-out infinite;
}

@keyframes lt-glow-float {
    0%, 100% { transform: translate(0, 0) scale(1); opacity: 1; }
    50% { transform: translate(-30px, 20px) scale(1.08); opacity: 0.85; }
}

/* Second subtle glow for depth */
.lt-hero::after {
    content: "";
    position: absolute;
    bottom: -220px;
    left: -180px;
    width: 440px;
    height: 440px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(217, 164, 65, 0.10), transparent 70%);
    pointer-events: none;
    animation: lt-glow-float-2 15s ease-in-out infinite;
}

@keyframes lt-glow-float-2 {
    0%, 100% { transform: translate(0, 0) scale(1); }
    50% { transform: translate(40px, -30px) scale(1.1); }
}

/* Hero grid: copy left, AI stage right */
.lt-hero-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 60px;
    align-items: center;
    position: relative;
    z-index: 1;
}

.lt-hero-copy { position: relative; z-index: 2; }

.lt-on-air {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    font-size: 0.8rem;
    letter-spacing: 0.03em;
    color: var(--lt-parchment-dim);
    border: 1px solid var(--lt-line);
    padding: 7px 14px 7px 12px;
    border-radius: 999px;
    margin-bottom: 28px;
    opacity: 0;
    transform: translateY(-12px);
    animation: lt-fade-in-down 0.7s cubic-bezier(0.22, 1, 0.36, 1) 0.1s forwards;
}

@keyframes lt-fade-in-down {
    to { opacity: 1; transform: translateY(0); }
}

.lt-on-air-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--lt-flame-bright);
    animation: lt-pulse 1.8s ease-in-out infinite;
}

@keyframes lt-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(228, 78, 46, 0.55); }
    50% { box-shadow: 0 0 0 6px rgba(228, 78, 46, 0); }
}

.lt-hero h1 {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-weight: 500;
    font-size: clamp(2.4rem, 5vw, 3.6rem);
    line-height: 1.08;
    max-width: 14ch;
    margin: 0 0 22px;
    color: var(--lt-parchment);
    opacity: 0;
    transform: translateY(24px);
    animation: lt-hero-title 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.25s forwards;
}

@keyframes lt-hero-title {
    to { opacity: 1; transform: translateY(0); }
}

.lt-hero p {
    max-width: 46ch;
    font-size: 1.1rem;
    line-height: 1.6;
    color: var(--lt-parchment-dim);
    margin: 0 0 36px;
    opacity: 0;
    transform: translateY(20px);
    animation: lt-hero-copy 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.45s forwards;
}

@keyframes lt-hero-copy {
    to { opacity: 1; transform: translateY(0); }
}

.lt-hero .lt-btn {
    opacity: 0;
    transform: translateY(16px);
    animation: lt-hero-btn 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.65s forwards;
}

@keyframes lt-hero-btn {
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================ */
/* AI TEACHER STAGE                                             */
/* ============================================================ */

.lt-ai-stage {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    perspective: 1400px;
    opacity: 0;
    transform: translateY(30px);
    animation: lt-ai-enter 1.1s cubic-bezier(0.22, 1, 0.36, 1) 0.4s forwards;
}

@keyframes lt-ai-enter {
    to { opacity: 1; transform: translateY(0); }
}

/* Soft glow behind the board */
.lt-ai-halo {
    position: absolute;
    inset: 8% -12% 6% -12%;
    border-radius: 50%;
    background: radial-gradient(circle at 50% 55%, rgba(200, 52, 30, 0.35), transparent 65%);
    filter: blur(20px);
    pointer-events: none;
    z-index: 0;
    animation: lt-ai-halo-breathe 6s ease-in-out infinite;
}

@keyframes lt-ai-halo-breathe {
    0%, 100% { opacity: 0.85; transform: scale(1); }
    50% { opacity: 1; transform: scale(1.06); }
}

/* The board */
.lt-ai-board {
    position: relative;
    width: 100%;
    max-width: 560px;
    aspect-ratio: 5 / 4;
    border-radius: 16px;
    background: linear-gradient(180deg, #241F18 0%, #1B1712 100%);
    border: 1px solid rgba(241, 231, 214, 0.14);
    box-shadow:
        0 30px 60px -30px rgba(0, 0, 0, 0.8),
        0 0 0 1px rgba(217, 164, 65, 0.08) inset;
    transform: rotateY(-8deg) rotateX(2deg);
    transform-style: preserve-3d;
    animation: lt-ai-board-sway 9s ease-in-out infinite;
    z-index: 1;
    overflow: hidden;
}

@keyframes lt-ai-board-sway {
    0%, 100% { transform: rotateY(-8deg) rotateX(2deg) translateY(0); }
    50% { transform: rotateY(-4deg) rotateX(1deg) translateY(-6px); }
}

/* Frame glow on top edge */
.lt-ai-frame {
    position: absolute;
    inset: 0;
    border-radius: 16px;
    pointer-events: none;
    background:
        linear-gradient(180deg, rgba(217, 164, 65, 0.35), transparent 8%),
        linear-gradient(90deg, rgba(200, 52, 30, 0.25), transparent 15%, transparent 85%, rgba(200, 52, 30, 0.25));
    mix-blend-mode: screen;
    z-index: 5;
}

/* Screen content */
.lt-ai-screen {
    position: absolute;
    inset: 14px;
    border-radius: 10px;
    background:
        linear-gradient(180deg, rgba(16, 13, 10, 0.92), rgba(16, 13, 10, 0.86));
    border: 1px solid rgba(241, 231, 214, 0.08);
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    overflow: hidden;
}

/* Screen header */
.lt-ai-screen-head {
    display: flex;
    align-items: center;
    gap: 8px;
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.68rem;
    letter-spacing: 0.04em;
    color: var(--lt-parchment-dim);
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(241, 231, 214, 0.08);
}

.lt-ai-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: rgba(228, 78, 46, 0.6);
    flex-shrink: 0;
}
.lt-ai-dot-mid { background: rgba(217, 164, 65, 0.6); }
.lt-ai-dot-end { background: rgba(241, 231, 214, 0.25); }

.lt-ai-screen-title {
    margin-left: 8px;
    color: var(--lt-parchment);
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.lt-ai-screen-live {
    margin-left: auto;
    color: #E44E2E;
    font-weight: 700;
    font-size: 0.64rem;
    letter-spacing: 0.08em;
    animation: lt-ai-blink 2s ease-in-out infinite;
}

@keyframes lt-ai-blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.45; }
}

/* Graph */
.lt-ai-graph {
    width: 100%;
    height: auto;
    max-height: 150px;
    margin-top: 4px;
}

.lt-ai-curve {
    stroke-dasharray: 500;
    stroke-dashoffset: 500;
    animation: lt-ai-draw 3s cubic-bezier(0.22, 1, 0.36, 1) 0.6s infinite;
}

@keyframes lt-ai-draw {
    0%   { stroke-dashoffset: 500; }
    45%  { stroke-dashoffset: 0; }
    55%  { stroke-dashoffset: 0; }
    100% { stroke-dashoffset: -500; }
}

.lt-ai-vertex {
    animation: lt-ai-vertex-pop 3s ease-in-out 0.6s infinite;
    transform-origin: 160px 140px;
}

@keyframes lt-ai-vertex-pop {
    0%, 40% { opacity: 0; transform: scale(0.4); }
    50%, 90% { opacity: 1; transform: scale(1); }
    100% { opacity: 0; transform: scale(0.4); }
}

.lt-ai-vertex-pulse {
    animation: lt-ai-vertex-ring 3s ease-out 0.6s infinite;
    transform-origin: 160px 140px;
}

@keyframes lt-ai-vertex-ring {
    0%, 45% { opacity: 0; transform: scale(1); }
    55% { opacity: 0.8; transform: scale(1); }
    90% { opacity: 0; transform: scale(3); }
    100% { opacity: 0; transform: scale(3); }
}

.lt-ai-root {
    opacity: 0;
    animation: lt-ai-root-show 3s ease-out 0.9s infinite;
    transform-origin: center;
}
.lt-ai-root-2 { animation-delay: 1.15s; }

@keyframes lt-ai-root-show {
    0%, 55% { opacity: 0; transform: scale(0.5); }
    65%, 90% { opacity: 1; transform: scale(1); }
    100% { opacity: 0; transform: scale(0.5); }
}

/* Equation typewriter */
.lt-ai-equation {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.85rem;
    color: var(--lt-parchment);
    margin-top: 6px;
    min-height: 60px;
}

.lt-ai-eq {
    display: inline-block;
    width: 0;
    overflow: hidden;
    white-space: nowrap;
    border-right: 2px solid transparent;
    opacity: 0;
    animation: lt-ai-type 9s steps(20) infinite;
}

.lt-ai-eq em {
    color: var(--lt-flame-bright);
    font-style: normal;
    font-weight: 700;
}

.lt-ai-eq-1 { animation-delay: 0s; }
.lt-ai-eq-2 { animation-delay: 2.4s; }
.lt-ai-eq-3 { animation-delay: 4.8s; }

@keyframes lt-ai-type {
    0%   { width: 0; opacity: 0; border-color: var(--lt-gold); }
    2%   { width: 0; opacity: 1; border-color: var(--lt-gold); }
    12%  { width: 12ch; opacity: 1; border-color: var(--lt-gold); }
    20%  { width: 12ch; opacity: 1; border-color: transparent; }
    21%  { width: 12ch; opacity: 1; }
    30%  { width: 12ch; opacity: 1; }
    31%  { width: 0; opacity: 0; }
    100% { width: 0; opacity: 0; }
}

/* Cursor */
.lt-ai-cursor {
    position: absolute;
    left: 30px;
    bottom: 40px;
    width: 12px;
    height: 18px;
    background: var(--lt-gold);
    clip-path: polygon(0 0, 0 100%, 35% 75%, 55% 100%, 70% 92%, 50% 68%, 100% 68%);
    filter: drop-shadow(0 0 6px rgba(217, 164, 65, 0.6));
    animation: lt-ai-cursor-move 9s cubic-bezier(0.5, 0, 0.5, 1) infinite;
}

@keyframes lt-ai-cursor-move {
    0%, 5%   { transform: translate(20px, 30px) rotate(-10deg); }
    12%      { transform: translate(140px, 10px) rotate(0deg); }
    20%      { transform: translate(180px, 40px) rotate(-5deg); }
    30%      { transform: translate(90px, 50px) rotate(0deg); }
    40%      { transform: translate(220px, 20px) rotate(-8deg); }
    55%      { transform: translate(60px, 30px) rotate(0deg); }
    70%      { transform: translate(180px, 45px) rotate(-6deg); }
    85%      { transform: translate(100px, 25px) rotate(0deg); }
    100%     { transform: translate(20px, 30px) rotate(-10deg); }
}

/* Progress bar */
.lt-ai-progress {
    margin-top: auto;
    height: 3px;
    border-radius: 999px;
    background: rgba(241, 231, 214, 0.08);
    overflow: hidden;
}

.lt-ai-progress-fill {
    height: 100%;
    width: 0%;
    border-radius: 999px;
    background: linear-gradient(90deg, var(--lt-flame-dark), var(--lt-gold));
    animation: lt-ai-progress 9s ease-in-out infinite;
}

@keyframes lt-ai-progress {
    0%   { width: 6%; }
    60%  { width: 82%; }
    90%  { width: 100%; }
    100% { width: 6%; }
}

/* Teacher figure */
.lt-ai-teacher {
    position: absolute;
    bottom: -4%;
    right: -6%;
    width: 34%;
    max-width: 200px;
    pointer-events: none;
    z-index: 3;
    filter: drop-shadow(0 20px 30px rgba(0, 0, 0, 0.55));
    transform: translateZ(30px);
}

.lt-ai-teacher svg {
    width: 100%;
    height: auto;
    overflow: visible;
}

.lt-ai-arm {
    transform-origin: 76px 84px;
    animation: lt-ai-arm-wave 3.6s ease-in-out infinite;
}

@keyframes lt-ai-arm-wave {
    0%, 100% { transform: rotate(0deg); }
    50%      { transform: rotate(-8deg); }
}

/* Floating chips */
.lt-ai-chip {
    position: absolute;
    padding: 8px 14px;
    border-radius: 999px;
    background: rgba(36, 31, 24, 0.92);
    border: 1px solid rgba(241, 231, 214, 0.14);
    color: var(--lt-parchment);
    font-size: 0.76rem;
    font-weight: 600;
    letter-spacing: 0.02em;
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    box-shadow: 0 10px 24px -12px rgba(0, 0, 0, 0.7);
    z-index: 4;
    white-space: nowrap;
}

.lt-ai-chip-1 {
    top: 8%;
    left: -8%;
    animation: lt-ai-chip-float 7s ease-in-out infinite;
}

.lt-ai-chip-2 {
    bottom: 16%;
    left: -12%;
    animation: lt-ai-chip-float 7s ease-in-out 1.2s infinite;
}

.lt-ai-chip-3 {
    top: 40%;
    right: -8%;
    animation: lt-ai-chip-float 7s ease-in-out 2.4s infinite;
}

@keyframes lt-ai-chip-float {
    0%, 100% { transform: translateY(0); }
    50%      { transform: translateY(-8px); }
}

/* --- Button with shimmer + arrow nudge --- */
.lt-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 14px 28px;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 600;
    border: 1px solid transparent;
    transition: transform 0.25s cubic-bezier(0.22, 1, 0.36, 1),
                background 0.2s ease,
                box-shadow 0.25s ease,
                border-color 0.25s ease;
    position: relative;
    overflow: hidden;
    cursor: pointer;
}

.lt-btn::after {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(115deg, transparent 30%, rgba(255, 255, 255, 0.18) 50%, transparent 70%);
    transform: translateX(-100%);
    transition: transform 0.6s ease;
    pointer-events: none;
}

.lt-btn:hover::after { transform: translateX(100%); }

.lt-btn:hover { transform: translateY(-2px); }

.lt-btn-primary {
    background: var(--lt-flame);
    color: var(--lt-parchment);
}
.lt-btn-primary:hover {
    background: var(--lt-flame-bright);
    box-shadow: 0 10px 30px -10px rgba(228, 78, 46, 0.55);
}

.lt-btn-outline {
    border-color: var(--lt-line);
    color: var(--lt-parchment);
}
.lt-btn-outline:hover {
    border-color: var(--lt-gold);
    color: var(--lt-gold);
    box-shadow: 0 10px 30px -12px rgba(217, 164, 65, 0.4);
}

/* ---------- Stats strip ---------- */
.lt-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    border-top: 1px solid var(--lt-line);
    border-bottom: 1px solid var(--lt-line);
}

.lt-stat {
    padding: 30px 20px;
    text-align: center;
    border-right: 1px solid var(--lt-line);
    position: relative;
    overflow: hidden;
    transition: background 0.3s ease;
}

.lt-stat::before {
    content: "";
    position: absolute;
    top: 0;
    left: 50%;
    width: 0;
    height: 2px;
    background: linear-gradient(90deg, var(--lt-flame-dark), var(--lt-flame-bright));
    transform: translateX(-50%);
    transition: width 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-stat:hover::before { width: 70%; }
.lt-stat:hover { background: var(--lt-charcoal); }

.lt-stat:last-child { border-right: none; }

.lt-stat-number {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 2.1rem;
    color: var(--lt-gold);
    line-height: 1;
    margin-bottom: 8px;
    transition: transform 0.4s cubic-bezier(0.22, 1, 0.36, 1), color 0.3s ease;
}

.lt-stat:hover .lt-stat-number {
    transform: scale(1.08);
    color: var(--lt-flame-bright);
}

.lt-stat-label {
    font-size: 0.82rem;
    color: var(--lt-parchment-dim);
    transition: color 0.3s ease;
}

.lt-stat:hover .lt-stat-label { color: var(--lt-parchment); }

/* ---------- Section headers ---------- */
.lt-section {
    padding: 72px 0;
    position: relative;
}
.lt-section + .lt-section {
    border-top: 1px solid var(--lt-line);
}

.lt-section-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    margin-bottom: 34px;
    gap: 20px;
    flex-wrap: wrap;
}

.lt-section-head h2 {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-weight: 500;
    font-size: 1.7rem;
    margin: 0;
    color: var(--lt-parchment);
    position: relative;
}

/* Underline swoosh on section h2 */
.lt-section-head h2::after {
    content: "";
    position: absolute;
    bottom: -8px;
    left: 0;
    width: 40px;
    height: 2px;
    background: var(--lt-flame);
    transform: scaleX(0);
    transform-origin: left;
    transition: transform 0.6s cubic-bezier(0.22, 1, 0.36, 1) 0.3s;
}

.lt-reveal.lt-visible .lt-section-head h2::after,
.lt-section-head.lt-visible h2::after {
    transform: scaleX(1);
}

.lt-section-head p {
    margin: 0;
    color: var(--lt-parchment-dim);
    font-size: 0.95rem;
}

/* ---------- Featured class cards ---------- */
.lt-featured-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 22px;
}

.lt-fclass {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    transition:
        border-color 0.3s ease,
        transform 0.4s cubic-bezier(0.22, 1, 0.36, 1),
        box-shadow 0.4s ease,
        background 0.3s ease;
    position: relative;
    overflow: hidden;
}

.lt-fclass::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 40px;
    height: 2px;
    background: linear-gradient(90deg, var(--lt-flame-dark), var(--lt-flame-bright));
    transition: width 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-fclass:hover::before { width: 100%; }

.lt-fclass:hover {
    border-color: var(--lt-flame);
    transform: translateY(-6px);
    box-shadow: 0 20px 40px -20px rgba(200, 52, 30, 0.4);
    background: var(--lt-charcoal-raised);
}

.lt-fclass-time {
    font-size: 0.78rem;
    color: var(--lt-gold);
    transition: color 0.3s ease;
}

.lt-fclass:hover .lt-fclass-time { color: var(--lt-flame-bright); }

.lt-fclass h3 {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.2rem;
    margin: 0;
    color: var(--lt-parchment);
    transition: color 0.3s ease;
}

.lt-fclass-desc {
    font-size: 0.9rem;
    color: var(--lt-parchment-dim);
    line-height: 1.55;
    margin: 0;
}

.lt-fclass-teacher {
    font-size: 0.82rem;
    color: var(--lt-parchment-dim);
}

.lt-fclass-foot {
    margin-top: auto;
    padding-top: 14px;
    border-top: 1px solid var(--lt-line);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.lt-price {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.25rem;
    color: var(--lt-parchment);
    transition: color 0.3s ease, transform 0.3s ease;
    display: inline-block;
}

.lt-fclass:hover .lt-price {
    color: var(--lt-gold);
    transform: scale(1.05);
}

.lt-price-currency {
    font-size: 0.72rem;
    color: var(--lt-parchment-dim);
    margin-left: 4px;
}

.lt-fclass .lt-btn { padding: 10px 18px; font-size: 0.85rem; }

/* ---------- Upcoming schedule list ---------- */
.lt-schedule {
    border-top: 1px solid var(--lt-line);
}

.lt-schedule-row {
    display: grid;
    grid-template-columns: 130px 1fr auto auto;
    align-items: center;
    gap: 20px;
    padding: 20px 0;
    border-bottom: 1px solid var(--lt-line);
    transition:
        padding-left 0.4s cubic-bezier(0.22, 1, 0.36, 1),
        background 0.3s ease;
    position: relative;
}

.lt-schedule-row::before {
    content: "";
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 2px;
    background: var(--lt-flame);
    transform: scaleY(0);
    transform-origin: center;
    transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-schedule-row:hover::before { transform: scaleY(1); }

.lt-schedule-row:hover {
    padding-left: 16px;
    background: linear-gradient(90deg, rgba(200, 52, 30, 0.06), transparent 60%);
}

.lt-schedule-date {
    font-size: 0.85rem;
    color: var(--lt-gold);
    transition: color 0.3s ease;
}

.lt-schedule-row:hover .lt-schedule-date { color: var(--lt-flame-bright); }

.lt-schedule-main h3 {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.05rem;
    margin: 0 0 4px;
    color: var(--lt-parchment);
    transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-schedule-row:hover .lt-schedule-main h3 {
    transform: translateX(2px);
}

.lt-schedule-main p {
    margin: 0;
    font-size: 0.85rem;
    color: var(--lt-parchment-dim);
}

.lt-schedule-teacher {
    font-size: 0.82rem;
    color: var(--lt-parchment-dim);
    white-space: nowrap;
}

.lt-empty {
    text-align: center;
    padding: 48px 20px;
    color: var(--lt-parchment-dim);
    border: 1px dashed var(--lt-line);
    border-radius: 10px;
}

/* ---------- How it works ---------- */
.lt-steps {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 30px;
}

.lt-step {
    position: relative;
    padding-top: 8px;
    transition: transform 0.4s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-step:hover { transform: translateY(-4px); }

.lt-step-num {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 3.2rem;
    color: var(--lt-flame-dark);
    line-height: 1;
    margin-bottom: 6px;
    transition:
        color 0.4s ease,
        transform 0.5s cubic-bezier(0.22, 1, 0.36, 1),
        letter-spacing 0.4s ease;
    display: inline-block;
}

.lt-step:hover .lt-step-num {
    color: var(--lt-flame);
    transform: translateY(-4px) scale(1.08);
    letter-spacing: -0.02em;
}

.lt-step h3 {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.1rem;
    margin: 0 0 8px;
    color: var(--lt-parchment);
    transition: color 0.3s ease;
}

.lt-step:hover h3 { color: var(--lt-gold); }

.lt-step p {
    margin: 0;
    font-size: 0.9rem;
    color: var(--lt-parchment-dim);
    line-height: 1.55;
}

/* ---------- CTA ---------- */
.lt-cta {
    background: linear-gradient(135deg, var(--lt-flame-dark), var(--lt-flame));
    border-radius: 14px;
    padding: 56px 40px;
    text-align: center;
    margin-top: 24px;
    position: relative;
    overflow: hidden;
    transition: transform 0.4s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.4s ease;
}

.lt-cta::before {
    content: "";
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle at 50% 50%, rgba(255, 255, 255, 0.15), transparent 60%);
    opacity: 0;
    transition: opacity 0.6s ease;
    pointer-events: none;
}

.lt-cta:hover::before { opacity: 1; }

.lt-cta:hover {
    transform: translateY(-4px);
    box-shadow: 0 24px 48px -24px rgba(200, 52, 30, 0.6);
}

.lt-cta h2 {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.7rem;
    color: var(--lt-parchment);
    margin: 0 0 10px;
    position: relative;
    z-index: 1;
}

.lt-cta p {
    color: rgba(241, 231, 214, 0.85);
    margin: 0 0 26px;
    position: relative;
    z-index: 1;
}

.lt-cta .lt-btn {
    background: var(--lt-parchment);
    color: var(--lt-flame-dark);
    position: relative;
    z-index: 1;
}
.lt-cta .lt-btn:hover { background: #fff; }

/* ---------- Responsive ---------- */
@media (max-width: 960px) {
    .lt-stats { grid-template-columns: repeat(2, 1fr); }
    .lt-stat:nth-child(2) { border-right: none; }
    .lt-stat:nth-child(1),
    .lt-stat:nth-child(2) { border-bottom: 1px solid var(--lt-line); }
    .lt-featured-grid { grid-template-columns: 1fr; }
    .lt-steps { grid-template-columns: 1fr; }
    .lt-schedule-row { grid-template-columns: 1fr; gap: 6px; }
    .lt-schedule-teacher { text-align: left; }
}

@media (max-width: 900px) {
    .lt-hero-grid {
        grid-template-columns: 1fr;
        gap: 40px;
    }
    .lt-ai-stage { display: none; }
}

/* ---------- Reduced motion ---------- */
@media (prefers-reduced-motion: reduce) {
    .lt-on-air-dot { animation: none; }
    .lt-hero::before,
    .lt-hero::after { animation: none; }
    .lt-on-air,
    .lt-hero h1,
    .lt-hero p,
    .lt-hero .lt-btn {
        opacity: 1;
        transform: none;
        animation: none;
    }
    .lt-reveal,
    .lt-reveal-left,
    .lt-reveal-right,
    .lt-reveal-scale {
        opacity: 1;
        transform: none;
        transition: none;
    }
    .lt-btn::after { display: none; }
    .lt-stat::before,
    .lt-fclass::before,
    .lt-schedule-row::before,
    .lt-section-head h2::after {
        transition: none;
    }

    /* Freeze AI teacher stage */
    .lt-ai-stage,
    .lt-ai-board,
    .lt-ai-halo,
    .lt-ai-curve,
    .lt-ai-vertex,
    .lt-ai-vertex-pulse,
    .lt-ai-root,
    .lt-ai-eq,
    .lt-ai-cursor,
    .lt-ai-progress-fill,
    .lt-ai-arm,
    .lt-ai-chip-1,
    .lt-ai-chip-2,
    .lt-ai-chip-3 {
        animation: none;
    }
    .lt-ai-eq {
        width: 12ch;
        opacity: 1;
        border-color: transparent;
    }
    .lt-ai-root { opacity: 1; }
    .lt-ai-vertex { opacity: 1; }
    .lt-ai-cursor { display: none; }
    .lt-ai-progress-fill { width: 70%; }
}
</style>

<main class="lt-page">
    <!-- Hero Section -->
    <section class="lt-hero">
        <div class="lt-container lt-hero-grid">
            <!-- Left: copy -->
            <div class="lt-hero-copy">
                <span class="lt-on-air"><span class="lt-on-air-dot"></span> Live classes, South Africa</span>
                <h1>Learn with real teachers, in real time.</h1>
                <p>Join interactive online classes with South African educators. Every session is recorded, every completed class comes with a certificate.</p>
                <a href="classes/index.php" class="lt-btn lt-btn-primary">Explore classes →</a>
            </div>

            <!-- Right: AI teacher on projection board -->
            <div class="lt-ai-stage" aria-hidden="true">
                <div class="lt-ai-board">
                    <div class="lt-ai-frame"></div>

                    <div class="lt-ai-screen">
                        <!-- Screen header -->
                        <div class="lt-ai-screen-head">
                            <span class="lt-ai-dot"></span>
                            <span class="lt-ai-dot lt-ai-dot-mid"></span>
                            <span class="lt-ai-dot lt-ai-dot-end"></span>
                            <span class="lt-ai-screen-title">lesson_04 / quadratic equations</span>
                            <span class="lt-ai-screen-live">● recording</span>
                        </div>

                        <!-- Animated graph -->
                        <svg class="lt-ai-graph" viewBox="0 0 320 180" preserveAspectRatio="xMidYMid meet">
                            <defs>
                                <pattern id="ltGrid" width="20" height="20" patternUnits="userSpaceOnUse">
                                    <path d="M 20 0 L 0 0 0 20" fill="none" stroke="rgba(241,231,214,0.06)" stroke-width="1"/>
                                </pattern>
                            </defs>
                            <rect width="320" height="180" fill="url(#ltGrid)"/>

                            <line x1="0" y1="90" x2="320" y2="90" stroke="rgba(241,231,214,0.25)" stroke-width="1"/>
                            <line x1="160" y1="0" x2="160" y2="180" stroke="rgba(241,231,214,0.25)" stroke-width="1"/>

                            <path class="lt-ai-curve"
                                  d="M 20 20 Q 160 260 300 20"
                                  fill="none"
                                  stroke="#D9A441"
                                  stroke-width="2.5"
                                  stroke-linecap="round"/>

                            <circle class="lt-ai-vertex" cx="160" cy="140" r="4" fill="#E44E2E"/>
                            <circle class="lt-ai-vertex-pulse" cx="160" cy="140" r="4" fill="none" stroke="#E44E2E" stroke-width="1.5"/>

                            <circle class="lt-ai-root" cx="60" cy="90" r="3.5" fill="#F1E7D6"/>
                            <circle class="lt-ai-root lt-ai-root-2" cx="260" cy="90" r="3.5" fill="#F1E7D6"/>
                        </svg>

                        <!-- Typewriter equations -->
                        <div class="lt-ai-equation">
                            <span class="lt-ai-eq lt-ai-eq-1">x² − 4 = 0</span>
                            <span class="lt-ai-eq lt-ai-eq-2">x = ±√4</span>
                            <span class="lt-ai-eq lt-ai-eq-3">x = <em>+2</em> , <em>−2</em></span>
                        </div>

                        <!-- Floating cursor -->
                        <div class="lt-ai-cursor"></div>

                        <!-- Progress bar -->
                        <div class="lt-ai-progress">
                            <div class="lt-ai-progress-fill"></div>
                        </div>
                    </div>

                    <!-- Teacher figure -->
                    <div class="lt-ai-teacher">
                        <svg viewBox="0 0 100 200" preserveAspectRatio="xMidYMax meet">
                            <circle class="lt-ai-head" cx="50" cy="34" r="18" fill="#F1E7D6"/>
                            <path d="M 32 28 Q 50 8 68 28 L 68 24 Q 50 4 32 24 Z" fill="#1B1712"/>
                            <rect x="38" y="30" width="10" height="6" rx="1.5" fill="none" stroke="#100D0A" stroke-width="1.5"/>
                            <rect x="52" y="30" width="10" height="6" rx="1.5" fill="none" stroke="#100D0A" stroke-width="1.5"/>
                            <line x1="48" y1="33" x2="52" y2="33" stroke="#100D0A" stroke-width="1.5"/>
                            <path d="M 43 42 Q 50 47 57 42" fill="none" stroke="#100D0A" stroke-width="1.5" stroke-linecap="round"/>
                            <path d="M 24 78 Q 50 62 76 78 L 82 160 Q 50 170 18 160 Z" fill="#8A2213"/>
                            <path d="M 42 78 L 50 96 L 58 78 Z" fill="#F1E7D6"/>
                            <path d="M 48 82 L 52 82 L 54 130 L 50 138 L 46 130 Z" fill="#C8341E"/>
                            <path class="lt-ai-arm" d="M 76 84 Q 92 78 98 60" fill="none" stroke="#8A2213" stroke-width="9" stroke-linecap="round"/>
                            <circle cx="98" cy="58" r="5" fill="#F1E7D6"/>
                            <path d="M 24 84 Q 14 100 16 120" fill="none" stroke="#8A2213" stroke-width="9" stroke-linecap="round"/>
                            <circle cx="16" cy="122" r="5" fill="#F1E7D6"/>
                        </svg>
                    </div>

                    <!-- Floating chips -->
                    <div class="lt-ai-chip lt-ai-chip-1">✦ AI teacher</div>
                    <div class="lt-ai-chip lt-ai-chip-2">🎓 Live · 12 learners</div>
                    <div class="lt-ai-chip lt-ai-chip-3">📝 Auto notes</div>

                    <!-- Halo -->
                    <div class="lt-ai-halo"></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats -->
    <div class="lt-stats">
        <div class="lt-stat lt-reveal lt-delay-1">
            <div class="lt-stat-number" data-count="<?php echo $totalStudents; ?>">0</div>
            <div class="lt-stat-label">Active students</div>
        </div>
        <div class="lt-stat lt-reveal lt-delay-2">
            <div class="lt-stat-number" data-count="<?php echo $totalClasses; ?>">0</div>
            <div class="lt-stat-label">Live classes</div>
        </div>
        <div class="lt-stat lt-reveal lt-delay-3">
            <div class="lt-stat-number" data-count="<?php echo $totalTeachers; ?>">0</div>
            <div class="lt-stat-label">Expert teachers</div>
        </div>
        <div class="lt-stat lt-reveal lt-delay-4">
            <div class="lt-stat-number">4.8</div>
            <div class="lt-stat-label">Student rating</div>
        </div>
    </div>

    <div class="lt-container">
        <!-- Featured Classes -->
        <section class="lt-section">
            <div class="lt-section-head lt-reveal">
                <h2>Featured this week</h2>
                <p>The classes filling up fastest</p>
            </div>
            <div class="lt-featured-grid">
                <?php foreach ($featuredClasses as $class): ?>
                    <div class="lt-fclass lt-reveal lt-delay-1">
                        <span class="lt-fclass-time"><?php echo date('M d, H:i', strtotime($class['start_date'])); ?></span>
                        <h3><?php echo htmlspecialchars($class['title']); ?></h3>
                        <p class="lt-fclass-desc"><?php echo htmlspecialchars(substr($class['short_description'] ?? $class['description'], 0, 100)); ?>...</p>
                        <p class="lt-fclass-teacher">Taught by <?php echo htmlspecialchars($class['teacher_name']); ?></p>
                        <div class="lt-fclass-foot">
                            <div>
                                <span class="lt-price">R <?php echo number_format($class['price'], 2); ?></span>
                                <span class="lt-price-currency">ZAR</span>
                            </div>
                            <a href="classes/class.php?id=<?php echo $class['id']; ?>" class="lt-btn lt-btn-primary">View class →</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Upcoming Classes -->
        <section class="lt-section">
            <div class="lt-section-head lt-reveal">
                <h2>Coming up</h2>
                <p>Everything scheduled next</p>
            </div>
            <?php if (empty($upcomingClasses)): ?>
                <div class="lt-empty lt-reveal-scale lt-delay-1">
                    <p>New classes coming soon. Check back later!</p>
                </div>
            <?php else: ?>
                <div class="lt-schedule">
                    <?php foreach ($upcomingClasses as $class): ?>
                        <div class="lt-schedule-row lt-reveal lt-delay-1">
                            <span class="lt-schedule-date"><?php echo date('M d, Y', strtotime($class['start_date'])); ?></span>
                            <div class="lt-schedule-main">
                                <h3><?php echo htmlspecialchars($class['title']); ?></h3>
                                <p><?php echo htmlspecialchars(substr($class['short_description'] ?? $class['description'], 0, 80)); ?>...</p>
                            </div>
                            <span class="lt-schedule-teacher"><?php echo htmlspecialchars($class['teacher_name']); ?></span>
                            <div>
                                <span class="lt-price"><?php echo number_format($class['price'], 2); ?></span>
                                <span class="lt-price-currency">ZAR</span>
                                <a href="classes/class.php?id=<?php echo $class['id']; ?>" class="lt-btn lt-btn-outline" style="margin-left:14px;">Details</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- How It Works -->
        <section class="lt-section">
            <div class="lt-section-head lt-reveal">
                <h2>How it works</h2>
            </div>
            <div class="lt-steps">
                <div class="lt-step lt-reveal-left lt-delay-1">
                    <div class="lt-step-num">1</div>
                    <h3>Find a class</h3>
                    <p>Browse our catalogue of live classes and pick a time that suits you.</p>
                </div>
                <div class="lt-step lt-reveal-left lt-delay-2">
                    <div class="lt-step-num">2</div>
                    <h3>Enroll and pay</h3>
                    <p>Secure your spot with instant, secure enrollment.</p>
                </div>
                <div class="lt-step lt-reveal-left lt-delay-3">
                    <div class="lt-step-num">3</div>
                    <h3>Join the class</h3>
                    <p>Attend live, ask questions, and walk away with a certificate.</p>
                </div>
            </div>
        </section>

        <!-- Call to Action -->
        <?php if (!isset($_SESSION['user'])): ?>
            <div class="lt-cta lt-reveal-scale">
                <h2>Ready to start learning?</h2>
                <p>Join thousands of South African students learning online.</p>
                <a href="register.php" class="lt-btn">Sign up free →</a>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
/* ============================================================
   LiveTeach homepage animation controller
   - IntersectionObserver reveals elements as they scroll in
   - Animated stat counters (counts up when stats strip enters view)
   - Respects prefers-reduced-motion
   ============================================================ */
(function () {
    'use strict';

    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // If user prefers reduced motion, reveal everything immediately and bail
    if (prefersReduced) {
        document.querySelectorAll('.lt-reveal, .lt-reveal-left, .lt-reveal-right, .lt-reveal-scale')
            .forEach(el => el.classList.add('lt-visible'));
        return;
    }

    /* ---------- Reveal on scroll ---------- */
    const revealTargets = document.querySelectorAll(
        '.lt-reveal, .lt-reveal-left, .lt-reveal-right, .lt-reveal-scale'
    );

    if ('IntersectionObserver' in window) {
        const revealObserver = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('lt-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.12,
            rootMargin: '0px 0px -60px 0px'
        });

        revealTargets.forEach(el => revealObserver.observe(el));
    } else {
        // No IO support — reveal all
        revealTargets.forEach(el => el.classList.add('lt-visible'));
    }

    /* ---------- Animated stat counters ---------- */
    function animateCount(el, target, duration) {
        const start = 0;
        const startTime = performance.now();

        function tick(now) {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            // easeOutCubic
            const eased = 1 - Math.pow(1 - progress, 3);
            const value = Math.floor(start + (target - start) * eased);
            el.textContent = value.toLocaleString();
            if (progress < 1) {
                requestAnimationFrame(tick);
            } else {
                el.textContent = target.toLocaleString();
            }
        }

        requestAnimationFrame(tick);
    }

    const counters = document.querySelectorAll('.lt-stat-number[data-count]');

    if ('IntersectionObserver' in window && counters.length) {
        const counterObserver = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    const el = entry.target;
                    const target = parseInt(el.getAttribute('data-count'), 10);
                    if (!isNaN(target) && target > 0) {
                        animateCount(el, target, 1400);
                    } else {
                        el.textContent = '0';
                    }
                    observer.unobserve(el);
                }
            });
        }, { threshold: 0.4 });

        counters.forEach(el => counterObserver.observe(el));
    } else {
        // Fallback — just render final values
        counters.forEach(function (el) {
            const target = parseInt(el.getAttribute('data-count'), 10);
            el.textContent = isNaN(target) ? '0' : target.toLocaleString();
        });
    }

    /* ---------- Subtle hero parallax on the ambient glow ---------- */
    const hero = document.querySelector('.lt-hero');
    if (hero) {
        let rafId = null;
        window.addEventListener('scroll', function () {
            if (rafId) return;
            rafId = requestAnimationFrame(function () {
                const scrolled = window.scrollY;
                if (scrolled < 800) {
                    hero.style.setProperty('--lt-parallax', (scrolled * 0.15) + 'px');
                    hero.style.backgroundPosition = 'center ' + (scrolled * 0.05) + 'px';
                }
                rafId = null;
            });
        }, { passive: true });
    }
})();
</script>

<?php require_once 'includes/footer.php'; ?>