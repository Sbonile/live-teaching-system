<?php
require_once '../config/database.php';
require_once '../includes/header.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'admin') {
    header('Location: ../login.php');
    exit;
}

$connection = getDbConnection();

$totalUsers = $connection->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$totalStudents = $connection->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
$totalTeachers = $connection->query("SELECT COUNT(*) as count FROM users WHERE role = 'teacher'")->fetch_assoc()['count'];
$totalClasses = $connection->query("SELECT COUNT(*) as count FROM live_classes")->fetch_assoc()['count'];
$totalRevenue = $connection->query("SELECT SUM(amount_paid) as total FROM enrollments WHERE payment_status = 'paid'")->fetch_assoc()['total'];
$pendingPayments = $connection->query("SELECT COUNT(*) as count FROM enrollments WHERE payment_status = 'pending'")->fetch_assoc()['count'];
?>

<style>
/* ===== LiveTeach admin dashboard revamp — scoped to .lt-page ===== */
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
    min-height: 100vh;
}

.lt-page .lt-serif,
.lt-page h1, .lt-page h2, .lt-page h3, .lt-page h4 {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-weight: 500;
}

.lt-page a { text-decoration: none; color: inherit; }

.lt-container {
    max-width: 1180px;
    margin: 0 auto;
    padding: 0 24px;
}

/* ---------- Header ---------- */
.lt-dash-header {
    padding: 56px 0 44px;
    border-bottom: 1px solid var(--lt-line);
    position: relative;
    overflow: hidden;
}

.lt-dash-header::before {
    content: "";
    position: absolute;
    top: -220px;
    right: -180px;
    width: 560px;
    height: 560px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(200, 52, 30, 0.28), transparent 70%);
    pointer-events: none;
    animation: lt-glow-float 12s ease-in-out infinite;
}

@keyframes lt-glow-float {
    0%, 100% { transform: translate(0, 0) scale(1); opacity: 1; }
    50% { transform: translate(-24px, 18px) scale(1.08); opacity: 0.88; }
}

.lt-dash-header-inner {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 32px;
    flex-wrap: wrap;
}

.lt-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    font-size: 0.78rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--lt-parchment-dim);
    border: 1px solid var(--lt-line);
    padding: 6px 13px;
    border-radius: 999px;
    margin-bottom: 20px;
    font-weight: 600;
    opacity: 0;
    transform: translateY(-12px);
    animation: lt-fade-in-down 0.7s cubic-bezier(0.22, 1, 0.36, 1) 0.1s forwards;
}

@keyframes lt-fade-in-down {
    to { opacity: 1; transform: translateY(0); }
}

.lt-eyebrow-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--lt-flame-bright);
    animation: lt-pulse 1.8s ease-in-out infinite;
}

@keyframes lt-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(228, 78, 46, 0.55); }
    50% { box-shadow: 0 0 0 6px rgba(228, 78, 46, 0); }
}

.lt-dash-header h1 {
    font-size: clamp(1.9rem, 3.4vw, 2.4rem);
    line-height: 1.15;
    margin: 0 0 10px;
    color: var(--lt-parchment);
    opacity: 0;
    transform: translateY(20px);
    animation: lt-hero-title 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.25s forwards;
}

@keyframes lt-hero-title {
    to { opacity: 1; transform: translateY(0); }
}

.lt-dash-header p {
    color: var(--lt-parchment-dim);
    font-size: 0.95rem;
    margin: 0;
    line-height: 1.6;
    opacity: 0;
    transform: translateY(16px);
    animation: lt-hero-copy 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.45s forwards;
}

@keyframes lt-hero-copy {
    to { opacity: 1; transform: translateY(0); }
}

.lt-dash-header p strong {
    color: var(--lt-gold);
    font-weight: 500;
}

/* ---------- Buttons ---------- */
.lt-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 11px 20px;
    border-radius: 8px;
    font-size: 0.88rem;
    font-weight: 600;
    border: 1px solid transparent;
    transition: transform 0.25s cubic-bezier(0.22, 1, 0.36, 1),
                background 0.2s ease,
                border-color 0.2s ease,
                color 0.2s ease,
                box-shadow 0.25s ease;
    cursor: pointer;
    font-family: inherit;
    text-align: center;
    white-space: nowrap;
    position: relative;
    overflow: hidden;
}

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
    background: transparent;
    border-color: var(--lt-line);
    color: var(--lt-parchment-dim);
}
.lt-btn-outline:hover {
    border-color: var(--lt-gold);
    color: var(--lt-gold);
    box-shadow: 0 10px 30px -12px rgba(217, 164, 65, 0.4);
}

.lt-btn-sm { padding: 8px 14px; font-size: 0.8rem; }

/* ---------- Animation utilities ---------- */
.lt-reveal {
    opacity: 0;
    transform: translateY(24px);
    transition:
        opacity 0.7s cubic-bezier(0.22, 1, 0.36, 1),
        transform 0.7s cubic-bezier(0.22, 1, 0.36, 1);
    will-change: opacity, transform;
}
.lt-reveal.lt-visible { opacity: 1; transform: translateY(0); }

.lt-reveal-left {
    opacity: 0;
    transform: translateX(-28px);
    transition:
        opacity 0.7s cubic-bezier(0.22, 1, 0.36, 1),
        transform 0.7s cubic-bezier(0.22, 1, 0.36, 1);
}
.lt-reveal-left.lt-visible { opacity: 1; transform: translateX(0); }

.lt-reveal-scale {
    opacity: 0;
    transform: scale(0.95);
    transition:
        opacity 0.6s cubic-bezier(0.22, 1, 0.36, 1),
        transform 0.6s cubic-bezier(0.22, 1, 0.36, 1);
}
.lt-reveal-scale.lt-visible { opacity: 1; transform: scale(1); }

.lt-delay-1 { transition-delay: 0.06s; }
.lt-delay-2 { transition-delay: 0.12s; }
.lt-delay-3 { transition-delay: 0.18s; }
.lt-delay-4 { transition-delay: 0.24s; }
.lt-delay-5 { transition-delay: 0.30s; }
.lt-delay-6 { transition-delay: 0.36s; }

/* ---------- Stats strip ---------- */
.lt-stats-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    border-top: 1px solid var(--lt-line);
    border-bottom: 1px solid var(--lt-line);
    margin-top: 32px;
}

.lt-stat {
    padding: 26px 20px;
    border-right: 1px solid var(--lt-line);
    position: relative;
    overflow: hidden;
    transition: background 0.3s ease;
}

.lt-stat:last-child { border-right: none; }

.lt-stat::before {
    content: "";
    position: absolute;
    top: 0;
    left: 20px;
    width: 26px;
    height: 2px;
    background: var(--lt-flame);
    transition: width 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-stat:hover::before {
    width: calc(100% - 40px);
}

.lt-stat:hover { background: var(--lt-charcoal); }

.lt-stat-number {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.75rem;
    color: var(--lt-parchment);
    line-height: 1;
    margin-bottom: 8px;
    transition: color 0.3s ease, transform 0.4s cubic-bezier(0.22, 1, 0.36, 1);
    display: inline-block;
}

.lt-stat:hover .lt-stat-number {
    color: var(--lt-gold);
    transform: scale(1.06);
}

.lt-stat.lt-accent .lt-stat-number { color: var(--lt-gold); }
.lt-stat.lt-warn .lt-stat-number { color: var(--lt-flame-bright); }

.lt-stat-label {
    font-size: 0.78rem;
    color: var(--lt-parchment-dim);
    transition: color 0.3s ease;
}

.lt-stat:hover .lt-stat-label { color: var(--lt-parchment); }

/* ---------- Section head ---------- */
.lt-section-head {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 48px 0 22px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--lt-line);
}

.lt-section-head h2 {
    font-size: 1.2rem;
    margin: 0;
    color: var(--lt-parchment);
}

.lt-section-head .lt-num {
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.72rem;
    color: var(--lt-gold);
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-weight: 600;
}

/* ---------- Action cards grid ---------- */
.lt-action-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    padding-bottom: 72px;
}

.lt-action-card {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 28px 26px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    position: relative;
    overflow: hidden;
    transition:
        border-color 0.3s ease,
        transform 0.4s cubic-bezier(0.22, 1, 0.36, 1),
        background 0.3s ease,
        box-shadow 0.4s ease;
}

/* Flame accent bar that expands on hover */
.lt-action-card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 40px;
    height: 2px;
    background: linear-gradient(90deg, var(--lt-flame-dark), var(--lt-flame-bright));
    transition: width 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-action-card:hover::before { width: 100%; }

.lt-action-card:hover {
    border-color: var(--lt-flame);
    transform: translateY(-5px);
    background: var(--lt-charcoal-raised);
    box-shadow: 0 20px 40px -20px rgba(200, 52, 30, 0.4);
}

.lt-action-icon {
    width: 46px;
    height: 46px;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--lt-flame-dark), var(--lt-flame));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    flex-shrink: 0;
    transition: transform 0.4s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.3s ease;
}

.lt-action-card:hover .lt-action-icon {
    transform: scale(1.08) rotate(-4deg);
    box-shadow: 0 8px 16px -8px rgba(200, 52, 30, 0.7);
}

.lt-action-card h3 {
    font-size: 1.15rem;
    margin: 0;
    color: var(--lt-parchment);
    line-height: 1.3;
    transition: color 0.3s ease;
}

.lt-action-card:hover h3 { color: var(--lt-gold); }

.lt-action-card p {
    color: var(--lt-parchment-dim);
    font-size: 0.9rem;
    line-height: 1.6;
    margin: 0;
}

.lt-action-card .lt-btn {
    margin-top: auto;
    align-self: flex-start;
}

/* Arrow nudge on hover */
.lt-action-card .lt-btn span.lt-arrow {
    display: inline-block;
    transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-action-card .lt-btn:hover span.lt-arrow {
    transform: translateX(3px);
}

/* ---------- Responsive ---------- */
@media (max-width: 1024px) {
    .lt-stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
    .lt-stat:nth-child(3n) { border-right: none; }
    .lt-stat:nth-child(1),
    .lt-stat:nth-child(2),
    .lt-stat:nth-child(3) {
        border-bottom: 1px solid var(--lt-line);
    }
}

@media (max-width: 720px) {
    .lt-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .lt-stat { border-right: 1px solid var(--lt-line); }
    .lt-stat:nth-child(2n) { border-right: none; }
    .lt-stat:nth-child(1),
    .lt-stat:nth-child(2),
    .lt-stat:nth-child(3),
    .lt-stat:nth-child(4) {
        border-bottom: 1px solid var(--lt-line);
    }
    .lt-stat:nth-child(5),
    .lt-stat:nth-child(6) {
        border-bottom: none;
    }
    .lt-action-grid {
        grid-template-columns: 1fr;
    }
    .lt-dash-header-inner {
        flex-direction: column;
        align-items: flex-start;
    }
}

@media (max-width: 480px) {
    .lt-dash-header { padding: 40px 0 32px; }
    .lt-action-card { padding: 24px 22px; }
    .lt-stats-grid { grid-template-columns: 1fr; }
    .lt-stat {
        border-right: none;
        border-bottom: 1px solid var(--lt-line);
    }
    .lt-stat:last-child { border-bottom: none; }
}

@media (prefers-reduced-motion: reduce) {
    .lt-eyebrow-dot,
    .lt-dash-header::before { animation: none; }

    .lt-eyebrow,
    .lt-dash-header h1,
    .lt-dash-header p {
        opacity: 1;
        transform: none;
        animation: none;
    }

    .lt-reveal,
    .lt-reveal-left,
    .lt-reveal-scale { opacity: 1; transform: none; transition: none; }

    .lt-action-card:hover,
    .lt-btn:hover { transform: none; }
}
</style>

<main class="lt-page">
    <!-- Header -->
    <div class="lt-dash-header">
        <div class="lt-container lt-dash-header-inner">
            <div>
                <span class="lt-eyebrow">
                    <span class="lt-eyebrow-dot"></span>
                    Admin control
                </span>
                <h1>Welcome back, <?php echo htmlspecialchars(explode(' ', $_SESSION['user']['fullname'])[0]); ?>.</h1>
                <p>Here's the state of the platform today.</p>
            </div>
        </div>
    </div>

    <div class="lt-container">
        <!-- Stats strip -->
        <div class="lt-stats-grid">
            <div class="lt-stat lt-reveal lt-delay-1">
                <div class="lt-stat-number" data-count="<?php echo $totalUsers; ?>">0</div>
                <div class="lt-stat-label">Total users</div>
            </div>
            <div class="lt-stat lt-reveal lt-delay-2">
                <div class="lt-stat-number" data-count="<?php echo $totalStudents; ?>">0</div>
                <div class="lt-stat-label">Students</div>
            </div>
            <div class="lt-stat lt-reveal lt-delay-3">
                <div class="lt-stat-number" data-count="<?php echo $totalTeachers; ?>">0</div>
                <div class="lt-stat-label">Teachers</div>
            </div>
            <div class="lt-stat lt-reveal lt-delay-4">
                <div class="lt-stat-number" data-count="<?php echo $totalClasses; ?>">0</div>
                <div class="lt-stat-label">Live classes</div>
            </div>
            <div class="lt-stat lt-accent lt-reveal lt-delay-5">
                <div class="lt-stat-number" data-count="<?php echo (int)($totalRevenue ?? 0); ?>" data-prefix="R ">0</div>
                <div class="lt-stat-label">Total revenue</div>
            </div>
            <div class="lt-stat <?php echo $pendingPayments > 0 ? 'lt-warn' : ''; ?> lt-reveal lt-delay-6">
                <div class="lt-stat-number" data-count="<?php echo $pendingPayments; ?>">0</div>
                <div class="lt-stat-label">Pending payments</div>
            </div>
        </div>

        <!-- Section: manage -->
        <div class="lt-section-head lt-reveal">
            <span class="lt-num">01</span>
            <h2>Manage the platform</h2>
        </div>

        <div class="lt-action-grid">
            <a href="users.php" class="lt-action-card lt-reveal-left lt-delay-1">
                <div class="lt-action-icon">👥</div>
                <h3>Manage users</h3>
                <p>View and manage all users, students, and teachers.</p>
                <span class="lt-btn lt-btn-outline">Go to users <span class="lt-arrow">→</span></span>
            </a>

            <a href="classes.php" class="lt-action-card lt-reveal-left lt-delay-2">
                <div class="lt-action-icon">📚</div>
                <h3>Manage classes</h3>
                <p>Review and manage all live classes across the platform.</p>
                <span class="lt-btn lt-btn-outline">Go to classes <span class="lt-arrow">→</span></span>
            </a>

            <a href="payments.php" class="lt-action-card lt-reveal-left lt-delay-3">
                <div class="lt-action-icon">💰</div>
                <h3>Payments</h3>
                <p>View all transactions and payment history.</p>
                <span class="lt-btn lt-btn-outline">View payments <span class="lt-arrow">→</span></span>
            </a>

            <a href="reports.php" class="lt-action-card lt-reveal-left lt-delay-4">
                <div class="lt-action-icon">📊</div>
                <h3>Reports</h3>
                <p>Generate reports and analytics on platform usage.</p>
                <span class="lt-btn lt-btn-outline">View reports <span class="lt-arrow">→</span></span>
            </a>
        </div>
    </div>
</main>

<script>
/* ============================================================
   Admin dashboard animation controller
   - Scroll reveals
   - Animated stat counters (with optional "R " prefix)
   - Respects prefers-reduced-motion
   ============================================================ */
(function () {
    'use strict';

    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (prefersReduced) {
        document.querySelectorAll('.lt-reveal, .lt-reveal-left, .lt-reveal-scale')
            .forEach(el => el.classList.add('lt-visible'));
        document.querySelectorAll('.lt-stat-number[data-count]').forEach(function (el) {
            const target = parseInt(el.getAttribute('data-count'), 10) || 0;
            const prefix = el.getAttribute('data-prefix') || '';
            el.textContent = prefix + target.toLocaleString();
        });
        return;
    }

    /* Reveal on scroll */
    const revealTargets = document.querySelectorAll('.lt-reveal, .lt-reveal-left, .lt-reveal-scale');
    if ('IntersectionObserver' in window) {
        const revealObserver = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('lt-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
        revealTargets.forEach(el => revealObserver.observe(el));
    } else {
        revealTargets.forEach(el => el.classList.add('lt-visible'));
    }

    /* Animated counters */
    function animateCount(el, target, duration, prefix) {
        const startTime = performance.now();
        function tick(now) {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            el.textContent = prefix + Math.floor(target * eased).toLocaleString();
            if (progress < 1) {
                requestAnimationFrame(tick);
            } else {
                el.textContent = prefix + target.toLocaleString();
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
                    const prefix = el.getAttribute('data-prefix') || '';
                    if (!isNaN(target) && target > 0) {
                        animateCount(el, target, 1300, prefix);
                    } else {
                        el.textContent = prefix + '0';
                    }
                    observer.unobserve(el);
                }
            });
        }, { threshold: 0.4 });
        counters.forEach(el => counterObserver.observe(el));
    } else {
        counters.forEach(function (el) {
            const target = parseInt(el.getAttribute('data-count'), 10) || 0;
            const prefix = el.getAttribute('data-prefix') || '';
            el.textContent = prefix + target.toLocaleString();
        });
    }
})();
</script>

<?php require_once '../includes/footer.php'; ?>