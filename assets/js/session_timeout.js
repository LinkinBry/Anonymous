/* ============================================================
   session_timeout.js — Session inactivity warning & auto-logout
   AnonymousReview System
   ============================================================

   Timeout values are passed from PHP via window.SESSION_* so
   they stay in sync with the server-side constants in config.php.

   PHP pages should emit (just before including this script):
     <script>
       window.SESSION_IDLE_TIMEOUT = <?= SESSION_IDLE_TIMEOUT ?>;
       window.SESSION_WARN_BEFORE  = <?= SESSION_WARN_BEFORE  ?>;
     </script>

   If those globals are absent, safe fallback values are used.
============================================================ */

const SESSION_DURATION = (window.SESSION_IDLE_TIMEOUT || 300);
const WARNING_AT       = (window.SESSION_WARN_BEFORE  ||  10);

let inactivityTimer;
let warningTimer;
let countdownInterval;
let warningShown = false;

// ── Build the warning modal once ─────────────────────────────────────────────
function createWarningModal() {
    if (document.getElementById('sessionWarningModal')) return;
    const modal = document.createElement('div');
    modal.id = 'sessionWarningModal';
    modal.style.cssText = [
        'display:none',
        'position:fixed',
        'inset:0',
        'background:rgba(0,0,0,0.6)',
        'z-index:99999',
        'align-items:center',
        'justify-content:center',
        'backdrop-filter:blur(3px)',
    ].join(';');

    modal.innerHTML = `
        <div style="background:white;border-radius:16px;padding:36px 32px;
                    max-width:400px;width:90%;text-align:center;
                    box-shadow:0 8px 40px rgba(0,0,0,0.2);">
            <div style="width:60px;height:60px;border-radius:50%;background:#fef3c7;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                <svg width="28" height="28" fill="none" stroke="#f59e0b" stroke-width="2"
                     viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8"  x2="12"   y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
            </div>
            <h3 style="font-size:18px;font-weight:700;color:#1f2937;margin-bottom:8px;">
                Session Expiring Soon
            </h3>
            <p style="font-size:14px;color:#6b7280;margin-bottom:6px;">
                You've been inactive. You will be logged out in:
            </p>
            <div id="sessionCountdown"
                 style="font-size:40px;font-weight:800;color:#8B0000;
                        margin:12px 0 20px;letter-spacing:2px;">
                ${WARNING_AT}
            </div>
            <p style="font-size:13px;color:#9ca3af;margin-bottom:24px;">
                Click below to stay logged in.
            </p>
            <div style="display:flex;gap:10px;justify-content:center;">
                <button onclick="logoutManual()"
                        style="padding:10px 22px;border-radius:20px;background:white;
                               color:#6b7280;border:1px solid #e5e7eb;
                               font-size:14px;cursor:pointer;font-family:inherit;">
                    Logout
                </button>
                <button onclick="extendSession()"
                        style="padding:10px 24px;border-radius:20px;background:#8B0000;
                               color:white;border:none;font-size:14px;font-weight:600;
                               cursor:pointer;font-family:inherit;">
                    Stay Logged In
                </button>
            </div>
        </div>`;
    document.body.appendChild(modal);
}

// ── Show / hide warning ───────────────────────────────────────────────────────
function showWarning() {
    if (warningShown) return;
    warningShown = true;

    const modal = document.getElementById('sessionWarningModal');
    modal.style.display = 'flex';

    let secs = WARNING_AT;
    const cd = document.getElementById('sessionCountdown');
    if (cd) cd.textContent = secs;

    countdownInterval = setInterval(() => {
        secs--;
        if (cd) {
            cd.textContent  = secs;
            cd.style.color  = secs <= 5 ? '#ef4444' : '#8B0000';
        }
        if (secs <= 0) {
            clearInterval(countdownInterval);
            logoutTimeout();
        }
    }, 1000);
}

function hideWarning() {
    const modal = document.getElementById('sessionWarningModal');
    if (modal) modal.style.display = 'none';
    clearInterval(countdownInterval);
    warningShown = false;

    const cd = document.getElementById('sessionCountdown');
    if (cd) { cd.textContent = WARNING_AT; cd.style.color = '#8B0000'; }
}

// ── Extend / logout actions ───────────────────────────────────────────────────

/** User clicked "Stay Logged In" — ping the server then reset timers. */
function extendSession() {
    hideWarning();
    fetch('session_refresh.php', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'expired') {
                logoutTimeout();
            } else {
                resetTimers();
            }
        })
        .catch(() => {
            // Network hiccup — reset timers optimistically
            resetTimers();
        });
}

/** Called when the countdown reaches 0 (genuine idle expiry). */
function logoutTimeout() {
    window.location.href = 'logout.php?timeout=1';
}

/** Called when the user explicitly clicks "Logout" in the modal. */
function logoutManual() {
    window.location.href = 'logout.php';
}

// ── Timer management ──────────────────────────────────────────────────────────
function resetTimers() {
    clearTimeout(inactivityTimer);
    clearTimeout(warningTimer);

    // Show warning WARNING_AT seconds before the idle limit
    warningTimer    = setTimeout(showWarning,    (SESSION_DURATION - WARNING_AT) * 1000);
    // Force logout at idle limit (safety net if warning is dismissed somehow)
    inactivityTimer = setTimeout(logoutTimeout,   SESSION_DURATION * 1000);
}

// ── Periodic server-side session check ───────────────────────────────────────
// Runs every 20 s so that a tab left open in background detects server expiry.
function checkServerSession() {
    fetch('session_refresh.php', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'expired') logoutTimeout();
        })
        .catch(() => { /* ignore network errors silently */ });
}
setInterval(checkServerSession, 20_000);

// ── Activity listeners — reset timer on any user interaction ──────────────────
['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(evt => {
    document.addEventListener(evt, () => {
        if (!warningShown) resetTimers();
    }, { passive: true });
});

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    createWarningModal();
    resetTimers();
});