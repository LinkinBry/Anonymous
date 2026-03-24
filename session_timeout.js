const SESSION_DURATION = 300;
const WARNING_AT       = 10;

let inactivityTimer;
let warningTimer;
let countdownInterval;
let warningShown = false;

function createWarningModal() {
    if (document.getElementById('sessionWarningModal')) return;
    const modal = document.createElement('div');
    modal.id = 'sessionWarningModal';
    modal.style.cssText = `display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:99999;align-items:center;justify-content:center;backdrop-filter:blur(3px);`;
    modal.innerHTML = `
        <div style="background:white;border-radius:16px;padding:36px 32px;max-width:400px;width:90%;text-align:center;box-shadow:0 8px 40px rgba(0,0,0,0.2);">
            <div style="width:60px;height:60px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                <svg width="28" height="28" fill="none" stroke="#f59e0b" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <h3 style="font-size:18px;font-weight:700;color:#1f2937;margin-bottom:8px;">Session Expiring Soon</h3>
            <p style="font-size:14px;color:#6b7280;margin-bottom:6px;">You've been inactive. You will be logged out in:</p>
            <div id="sessionCountdown" style="font-size:40px;font-weight:800;color:#8B0000;margin:12px 0 20px;letter-spacing:2px;">${WARNING_AT}</div>
            <p style="font-size:13px;color:#9ca3af;margin-bottom:24px;">Click below to stay logged in.</p>
            <div style="display:flex;gap:10px;justify-content:center;">
                <button onclick="logoutNow()" style="padding:10px 22px;border-radius:20px;background:white;color:#6b7280;border:1px solid #e5e7eb;font-size:14px;cursor:pointer;font-family:inherit;">Logout</button>
                <button onclick="extendSession()" style="padding:10px 24px;border-radius:20px;background:#8B0000;color:white;border:none;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;">Stay Logged In</button>
            </div>
        </div>`;
    document.body.appendChild(modal);
}

function showWarning() {
    if (warningShown) return;
    warningShown = true;
    document.getElementById('sessionWarningModal').style.display = 'flex';
    let secs = WARNING_AT;
    document.getElementById('sessionCountdown').textContent = secs;
    countdownInterval = setInterval(() => {
        secs--;
        const el = document.getElementById('sessionCountdown');
        if (el) el.textContent = secs;
        if (secs <= 5 && el) el.style.color = '#ef4444';
        if (secs <= 0) { clearInterval(countdownInterval); logoutNow(); }
    }, 1000);
}

function hideWarning() {
    const modal = document.getElementById('sessionWarningModal');
    if (modal) modal.style.display = 'none';
    clearInterval(countdownInterval);
    warningShown = false;
    const el = document.getElementById('sessionCountdown');
    if (el) { el.textContent = WARNING_AT; el.style.color = '#8B0000'; }
}

function extendSession() {
    hideWarning();
    fetch('session_refresh.php', { method: 'POST' })
        .then(r => r.json())
        .then(data => { if (data.status === 'expired') logoutNow(); else resetTimers(); })
        .catch(() => {});
}

function logoutNow() {
    window.location.href = 'logout.php?timeout=1';
}

function resetTimers() {
    clearTimeout(inactivityTimer);
    clearTimeout(warningTimer);
    warningTimer    = setTimeout(showWarning, (SESSION_DURATION - WARNING_AT) * 1000);
    inactivityTimer = setTimeout(logoutNow,   SESSION_DURATION * 1000);
}


function checkServerSession() {
    fetch('session_refresh.php', { method: 'POST' })
        .then(r => r.json())
        .then(data => { if (data.status === 'expired') logoutNow(); })
        .catch(() => {});
}
setInterval(checkServerSession, 10000);


['mousemove','keydown','click','scroll','touchstart'].forEach(evt => {
    document.addEventListener(evt, () => { if (!warningShown) resetTimers(); }, { passive: true });
});


document.addEventListener('DOMContentLoaded', () => {
    createWarningModal();
    resetTimers();
});