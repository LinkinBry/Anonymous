/* ============================================================
   profile.js — Profile page interactions
   Used by: profile.php
   ============================================================ */

/* ── Sidebar toggle ───────────────────────────────────────── */
(function() {
    const STORAGE_KEY = 'ar_sidebar_collapsed';
    const toggle = document.getElementById('sidebarToggle');
    const body   = document.body;
    function applyState(collapsed) { body.classList.toggle('sidebar-collapsed', collapsed); }
    applyState(localStorage.getItem(STORAGE_KEY) === '1');
    if (toggle) {
        toggle.addEventListener('click', () => {
            const nowCollapsed = !body.classList.contains('sidebar-collapsed');
            applyState(nowCollapsed);
            localStorage.setItem(STORAGE_KEY, nowCollapsed ? '1' : '0');
        });
    }
})();

/* ── Avatar preview before upload ────────────────────────── */
function previewPic(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('avatarPreview').src = e.target.result;
            const note = document.getElementById('picNote');
            if (note) note.textContent = '📷 New photo selected — save to apply.';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

/* ── Password match validation ────────────────────────────── */
const newPw  = document.querySelector('input[name="new_password"]');
const confPw = document.getElementById('confirmPw');
const pwMsg  = document.getElementById('pwMatch');

function checkPw() {
    if (!confPw || !confPw.value) { if (pwMsg) pwMsg.textContent = ''; return; }
    if (newPw.value === confPw.value) {
        pwMsg.style.color = '#065f46';
        pwMsg.textContent = '✓ Passwords match';
    } else {
        pwMsg.style.color = '#991b1b';
        pwMsg.textContent = '✗ Passwords do not match';
    }
}

if (newPw)  newPw.addEventListener('input',  checkPw);
if (confPw) confPw.addEventListener('input', checkPw);

/* ── Prevent submit if passwords don't match ──────────────── */
const profileForm = document.getElementById('profileForm');
if (profileForm) {
    profileForm.addEventListener('submit', function(e) {
        if (newPw && newPw.value && newPw.value !== confPw.value) {
            e.preventDefault();
            pwMsg.style.color = '#991b1b';
            pwMsg.textContent = '✗ Passwords do not match';
            confPw.focus();
        }
    });
}