<?php
/**
 * LiveTeach notification bell component.
 *
 * Include this once from includes/header.php:
 *     require_once __DIR__ . '/notification_bell.php';
 *
 * Self-contained: no dependency on ../ajax/*.php files.
 * Requires NotificationSystem to already be loaded (from notifications.php).
 */

if (!isset($_SESSION['user']['id'])) {
    return;   // not logged in — no bell
}

$__bellUserId = (int)$_SESSION['user']['id'];

/* Ensure the notification system is available */
if (!isset($notificationSystem) || !$notificationSystem) {
    require_once __DIR__ . '/notifications.php';
    if (!isset($connection) || !$connection) {
        require_once __DIR__ . '/../config/database.php';
        $connection = getDbConnection();
    }
    $notificationSystem = new NotificationSystem($connection);
}

$unreadCount         = $notificationSystem->getUnreadCount($__bellUserId);
$unreadNotifications = $notificationSystem->getUnread($__bellUserId, 8);

/* Where to send the bell's "view all" and mark-all actions.
   If we're being included from /notifications/index.php itself,
   we don't want to leave the page, so we detect and adjust. */
$__bellSelf   = $_SERVER['PHP_SELF'] ?? '';
$__bellOnPage = (strpos($__bellSelf, '/notifications/index.php') !== false);
$__bellAjax   = ($__bellOnPage ? '' : (function_exists('APP_BASE') ? APP_BASE : '/live-teaching-system') . '/notifications/index.php');

/* CSRF token for the bell's POSTs */
if (!function_exists('csrf_token')) {
    require_once __DIR__ . '/csrf.php';
}
$__bellCsrf = csrf_token();
?>
<style>
/* ===== Notification bell — scoped so it can't leak into page styles ===== */
.lt-bell-wrap {
    --lt-ink: #100D0A;
    --lt-charcoal: #1B1712;
    --lt-charcoal-raised: #241F18;
    --lt-flame: #C8341E;
    --lt-flame-bright: #E44E2E;
    --lt-gold: #D9A441;
    --lt-parchment: #F1E7D6;
    --lt-parchment-dim: #C9BEAC;
    --lt-line: rgba(241, 231, 214, 0.12);
    position: relative;
    display: inline-block;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}

.lt-bell-btn {
    position: relative;
    cursor: pointer;
    padding: 8px 10px;
    font-size: 1.25rem;
    line-height: 1;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 8px;
    color: inherit;
    transition: transform 0.2s ease, color 0.2s ease, background 0.2s ease, border-color 0.2s ease;
    font-family: inherit;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.lt-bell-btn:hover {
    color: var(--lt-gold);
    background: rgba(241, 231, 214, 0.06);
    border-color: var(--lt-line);
}

.lt-bell-btn.lt-has-unread {
    color: var(--lt-flame-bright);
}

.lt-bell-badge {
    position: absolute;
    top: 0;
    right: 0;
    background: var(--lt-flame);
    color: var(--lt-parchment);
    border-radius: 999px;
    font-size: 0.65rem;
    min-width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 5px;
    font-weight: 700;
    letter-spacing: 0.02em;
    box-shadow: 0 0 0 2px var(--lt-ink);
    transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-bell-badge.lt-bell-badge-pop {
    animation: lt-bell-pop 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}

@keyframes lt-bell-pop {
    0%   { transform: scale(1); }
    40%  { transform: scale(1.35); }
    100% { transform: scale(1); }
}

/* ---------- Dropdown ---------- */
.lt-bell-dropdown {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 380px;
    max-width: calc(100vw - 24px);
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    box-shadow: 0 24px 48px -20px rgba(0, 0, 0, 0.7);
    z-index: 1500;
    display: none;
    overflow: hidden;
}

.lt-bell-dropdown.lt-open {
    display: block;
    animation: lt-bell-fade-in 0.2s cubic-bezier(0.22, 1, 0.36, 1);
}

@keyframes lt-bell-fade-in {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}

.lt-bell-head {
    padding: 14px 18px;
    border-bottom: 1px solid var(--lt-line);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--lt-charcoal-raised);
}

.lt-bell-head h4 {
    margin: 0;
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-weight: 500;
    font-size: 1rem;
    color: var(--lt-parchment);
    display: flex;
    align-items: center;
    gap: 8px;
}

.lt-bell-mark-all {
    font-size: 0.72rem;
    color: var(--lt-gold);
    cursor: pointer;
    text-decoration: none;
    background: transparent;
    border: 0;
    font-family: inherit;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 6px;
    transition: background 0.15s ease, color 0.15s ease;
}
.lt-bell-mark-all:hover {
    background: rgba(217, 164, 65, 0.12);
    color: #E8B85A;
}

.lt-bell-list {
    max-height: 420px;
    overflow-y: auto;
}

.lt-bell-item {
    padding: 12px 18px;
    border-bottom: 1px solid var(--lt-line);
    transition: background 0.15s ease;
    cursor: pointer;
    display: flex;
    gap: 12px;
    align-items: flex-start;
    text-align: left;
}

.lt-bell-item:last-child { border-bottom: none; }
.lt-bell-item:hover { background: var(--lt-charcoal-raised); }

.lt-bell-item.lt-unread {
    background: linear-gradient(90deg, rgba(200, 52, 30, 0.10), transparent 60%);
    position: relative;
}

.lt-bell-item.lt-unread::before {
    content: "";
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 3px;
    background: var(--lt-flame);
}

.lt-bell-icon {
    font-size: 1.15rem;
    min-width: 28px;
    line-height: 1.2;
}

.lt-bell-icon.lt-live_stream {
    animation: lt-bell-pulse 1.5s ease-in-out infinite;
}
@keyframes lt-bell-pulse {
    0%, 100% { opacity: 1; }
    50%      { opacity: 0.5; }
}

.lt-bell-body { flex: 1; min-width: 0; }

.lt-bell-title {
    font-weight: 600;
    margin: 0 0 3px;
    font-size: 0.85rem;
    color: var(--lt-parchment);
    line-height: 1.35;
    word-break: break-word;
}

.lt-bell-msg {
    font-size: 0.76rem;
    color: var(--lt-parchment-dim);
    margin: 0 0 5px;
    line-height: 1.5;
    word-break: break-word;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.lt-bell-time {
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.68rem;
    color: var(--lt-parchment-dim);
    opacity: 0.75;
    letter-spacing: 0.03em;
}

.lt-bell-empty {
    padding: 44px 20px;
    text-align: center;
    color: var(--lt-parchment-dim);
    font-size: 0.88rem;
}

.lt-bell-empty-icon {
    font-size: 2.4rem;
    opacity: 0.55;
    display: block;
    margin-bottom: 10px;
}

.lt-bell-foot {
    padding: 12px 18px;
    border-top: 1px solid var(--lt-line);
    text-align: center;
    background: var(--lt-charcoal-raised);
}

.lt-bell-foot a {
    font-size: 0.78rem;
    color: var(--lt-gold);
    font-weight: 600;
    letter-spacing: 0.02em;
}
.lt-bell-foot a:hover { color: var(--lt-flame-bright); }

/* ---------- Responsive ---------- */
@media (max-width: 480px) {
    .lt-bell-dropdown {
        width: calc(100vw - 24px);
        right: -12px;
    }
}
</style>

<div class="lt-bell-wrap" id="ltBellWrap">
    <button type="button"
            class="lt-bell-btn <?php echo $unreadCount > 0 ? 'lt-has-unread' : ''; ?>"
            id="ltBellBtn"
            onclick="ltBellToggle(event)"
            aria-label="Notifications"
            aria-haspopup="true"
            aria-expanded="false">
        🔔
        <span class="lt-bell-badge"
              id="ltBellBadge"
              style="<?php echo $unreadCount > 0 ? '' : 'display:none;'; ?>">
            <?php echo $unreadCount > 99 ? '99+' : (int)$unreadCount; ?>
        </span>
    </button>

    <div class="lt-bell-dropdown" id="ltBellDropdown" role="menu">
        <div class="lt-bell-head">
            <h4>🔔 Notifications</h4>
            <?php if ($unreadCount > 0): ?>
                <button type="button" class="lt-bell-mark-all" onclick="ltBellMarkAll(event)">
                    Mark all as read
                </button>
            <?php endif; ?>
        </div>

        <div class="lt-bell-list" id="ltBellList">
            <?php if (!empty($unreadNotifications)): ?>
                <?php foreach ($unreadNotifications as $notif):
                    $icon = '🔔';
                    if ($notif['type'] === 'enrollment')       $icon = '📚';
                    elseif ($notif['type'] === 'live_stream')  $icon = '🔴';
                    elseif ($notif['type'] === 'certificate')  $icon = '🏆';
                    elseif ($notif['type'] === 'class_update') $icon = '📢';
                    elseif ($notif['type'] === 'payment')      $icon = '💰';

                    $link     = $notif['link'] ?? '';
                    $hasLink  = $link !== '' && $link !== '#';
                ?>
                    <div class="lt-bell-item lt-unread"
                         data-id="<?php echo (int)$notif['id']; ?>"
                         data-link="<?php echo htmlspecialchars($link, ENT_QUOTES); ?>"
                         onclick="ltBellItemClick(this, event)">
                        <div class="lt-bell-icon lt-<?php echo htmlspecialchars($notif['type']); ?>">
                            <?php echo $icon; ?>
                        </div>
                        <div class="lt-bell-body">
                            <div class="lt-bell-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                            <div class="lt-bell-msg"><?php echo htmlspecialchars($notif['message']); ?></div>
                            <div class="lt-bell-time"><?php echo htmlspecialchars(lt_bell_time_ago(strtotime($notif['created_at']))); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="lt-bell-empty">
                    <span class="lt-bell-empty-icon">📭</span>
                    You're all caught up.
                </div>
            <?php endif; ?>
        </div>

        <div class="lt-bell-foot">
            <a href="<?php echo htmlspecialchars($__bellAjax ?: 'index.php'); ?>">
                View all notifications →
            </a>
        </div>
    </div>
</div>

<script>
/* ===== Notification bell — self-contained, no external endpoints ===== */
(function () {
    'use strict';

    const CSRF = <?php echo json_encode($__bellCsrf); ?>;
    const POST_URL = <?php echo json_encode($__bellAjax ?: 'index.php'); ?>;
    const POLL_MS = 30000;   // poll every 30 seconds

    /* ---- Dropdown open/close ---- */
    window.ltBellToggle = function (event) {
        if (event) event.stopPropagation();
        const dd  = document.getElementById('ltBellDropdown');
        const btn = document.getElementById('ltBellBtn');
        if (!dd) return;
        const isOpen = dd.classList.contains('lt-open');
        if (isOpen) {
            dd.classList.remove('lt-open');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        } else {
            dd.classList.add('lt-open');
            if (btn) btn.setAttribute('aria-expanded', 'true');
        }
    };

    /* ---- Close on outside click ---- */
    document.addEventListener('click', function (e) {
        const wrap = document.getElementById('ltBellWrap');
        const dd   = document.getElementById('ltBellDropdown');
        if (!wrap || !dd) return;
        if (!wrap.contains(e.target)) {
            dd.classList.remove('lt-open');
            const btn = document.getElementById('ltBellBtn');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        }
    });

    /* ---- Escape closes ---- */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const dd = document.getElementById('ltBellDropdown');
            if (dd) dd.classList.remove('lt-open');
        }
    });

    /* ---- Mark one as read, then navigate ---- */
    window.ltBellItemClick = function (el, event) {
        if (event) event.stopPropagation();

        const id   = el.dataset.id;
        const link = el.dataset.link || '';
        if (!id) return;

        /* Post the mark-read in the background, then navigate */
        fetch(POST_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'csrf_token=' + encodeURIComponent(CSRF) + '&mark_read=' + encodeURIComponent(id),
            credentials: 'same-origin',
            redirect: 'manual'   // swallow the 302 so we don't navigate twice
        }).catch(function () {}).finally(function () {
            if (link && link !== '#') {
                window.location.href = link;
            } else {
                /* No link — just remove the unread style locally */
                el.classList.remove('lt-unread');
                ltBellRefreshCount();
            }
        });
    };

    /* ---- Mark all as read ---- */
    window.ltBellMarkAll = function (event) {
        if (event) event.stopPropagation();

        fetch(POST_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'csrf_token=' + encodeURIComponent(CSRF) + '&mark_all_read=1',
            credentials: 'same-origin',
            redirect: 'manual'
        }).then(function () {
            /* Update the UI immediately without waiting for a reload */
            document.querySelectorAll('#ltBellList .lt-bell-item.lt-unread')
                .forEach(el => el.classList.remove('lt-unread'));
            ltBellSetCount(0);
        }).catch(function () {});
    };

    /* ---- Badge helpers ---- */
    function ltBellSetCount(count) {
        const badge = document.getElementById('ltBellBadge');
        const btn   = document.getElementById('ltBellBtn');
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = 'flex';
            if (btn) btn.classList.add('lt-has-unread');
            /* Pop animation */
            badge.classList.remove('lt-bell-badge-pop');
            void badge.offsetWidth;
            badge.classList.add('lt-bell-badge-pop');
        } else {
            badge.style.display = 'none';
            if (btn) btn.classList.remove('lt-has-unread');
        }
    }

    /* ---- Poll for the current unread count ----
       We reuse the notifications page but request JSON by setting the
       Accept header. The page returns the same HTML, but we only need
       the count — so we hit a lightweight inline endpoint instead. */
    function ltBellRefreshCount() {
        /* Notifications page already exposes the count in the DOM.
           To avoid another file, we do a HEAD-ish fetch to the same page
           and parse the badge. Simpler: keep a tiny inline script tag
           that PHP renders as `ltBellCount` — this is set below. */
        const next = window.__ltBellServerCount;
        if (typeof next === 'number') {
            ltBellSetCount(next);
        }
    }

    /* Poll every 30 s */
    let pollTimer = null;
    function startPolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(function () {
            /* Re-fetch the current page's DOM in the background and read
               the badge value out of it — cheap and needs no new endpoint. */
            fetch(window.location.href, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.text())
            .then(function (html) {
                /* Extract the value from a hidden meta we render below */
                const match = html.match(/data-lt-bell-count="(\d+)"/);
                if (!match) return;
                const count = parseInt(match[1], 10);
                if (!isNaN(count)) ltBellSetCount(count);
            })
            .catch(function () {});
        }, POLL_MS);
    }

    /* Only poll when the tab is visible */
    function maybeStart() {
        if (document.visibilityState === 'visible') {
            ltBellRefreshCount();
            startPolling();
        }
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            maybeStart();
        } else if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    });

    maybeStart();
})();
</script>

<?php
/* Render a hidden marker so the poll JS can read the current count
   without needing an extra endpoint. */
echo '<span data-lt-bell-count="' . (int)$unreadCount . '" style="display:none;"></span>';

/* Also expose it as a JS global. */
echo '<script>window.__ltBellServerCount = ' . (int)$unreadCount . ';</script>';

/* -------- helper -------- */
if (!function_exists('lt_bell_time_ago')) {
    function lt_bell_time_ago(int $ts): string {
        $diff = time() - $ts;
        if ($diff < 60)      return 'Just now';
        if ($diff < 3600)    return floor($diff / 60) . ' min ago';
        if ($diff < 86400)   return floor($diff / 3600) . ' hours ago';
        if ($diff < 604800)  return floor($diff / 86400) . ' days ago';
        return date('M d, Y', $ts);
    }
}
?>