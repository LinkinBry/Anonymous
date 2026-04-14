/* ============================================================
   dashboard.js — Student dashboard interactions
   Used by: dashboard.php
   ============================================================ */

/* ── Sidebar toggle ───────────────────────────────────────── */
(function() {
    const STORAGE_KEY = 'ar_sidebar_collapsed';
    const toggle = document.getElementById('sidebarToggle');
    const body   = document.body;

    function applyState(collapsed) {
        body.classList.toggle('sidebar-collapsed', collapsed);
    }

    applyState(localStorage.getItem(STORAGE_KEY) === '1');

    if (toggle) {
        toggle.addEventListener('click', () => {
            const nowCollapsed = !body.classList.contains('sidebar-collapsed');
            applyState(nowCollapsed);
            localStorage.setItem(STORAGE_KEY, nowCollapsed ? '1' : '0');
        });
    }
})();

/* ── Notification bell ────────────────────────────────────── */
const notifWrap     = document.getElementById('notifWrap');
const notifDropdown = document.getElementById('notifDropdown');

if (notifWrap) {
    notifWrap.addEventListener('click', () => {
        const isOpen = notifDropdown.style.display === 'block';
        notifDropdown.style.display = isOpen ? 'none' : 'block';
        if (!isOpen) {
            fetch('mark_notifications_read.php', { method: 'POST' })
                .then(() => {
                    const badge = notifWrap.querySelector('.notif-badge');
                    if (badge) badge.remove();
                });
        }
    });
    document.addEventListener('click', (e) => {
        if (!notifWrap.contains(e.target)) notifDropdown.style.display = 'none';
    });
}

function clearNotifications(e) {
    e.stopPropagation();
    fetch('clear_notifications.php', { method: 'POST' })
        .then(() => {
            const dropdown = document.getElementById('notifDropdown');
            dropdown.querySelector('.notif-dropdown-header').innerHTML = `
                <span style="display:flex;align-items:center;gap:7px;">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
                    Notifications
                </span>`;
            dropdown.querySelectorAll('.notif-item').forEach(i => i.remove());
            const existing = dropdown.querySelector('.notif-empty');
            if (!existing) {
                const empty = document.createElement('div');
                empty.className = 'notif-empty';
                empty.innerHTML = `<svg width="28" height="28" fill="none" stroke="#9ca3af" stroke-width="1.5" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg><p>No notifications yet</p>`;
                dropdown.appendChild(empty);
            }
            const badge = document.getElementById('notifWrap').querySelector('.notif-badge');
            if (badge) badge.remove();
        });
}

/* ── Search bar clear button ──────────────────────────────── */
const searchInput = document.getElementById('searchInput');
const clearSearch = document.getElementById('clearSearch');

function toggleClear() {
    if (clearSearch) clearSearch.style.display = searchInput.value.length > 0 ? 'inline' : 'none';
}
if (searchInput) {
    toggleClear();
    searchInput.addEventListener('input', toggleClear);
}
if (clearSearch) {
    clearSearch.addEventListener('click', () => {
        searchInput.value = '';
        toggleClear();
        window.location.href = 'dashboard.php';
    });
}

/* ── Department filter (client-side) ──────────────────────── */
function filterFaculty() {
    const dept = document.getElementById('deptFilter').value;

    // First un-hide all hidden cards so we filter from full set
    document.querySelectorAll('.faculty-card.hidden-card').forEach(card => {
        card.classList.remove('hidden-card');
    });

    const allCards = document.querySelectorAll('.faculty-card');
    allCards.forEach(card => {
        card.style.display = (dept === 'all' || card.dataset.dept === dept) ? '' : 'none';
    });

    // Always hide the show-more card when filtering
    const showMoreCard = document.getElementById('showMoreCard');
    if (showMoreCard) showMoreCard.style.display = 'none';

    const visible = [...allCards].filter(c => c.style.display !== 'none');
    _currentPage = 1;
    paginateCards(visible);
}

/* ══════════════════════════════════════════════════════════
   FACULTY PAGINATION
   ══════════════════════════════════════════════════════════ */
const CARDS_PER_PAGE = 9;
const FACULTY_INITIAL = 5;   // must match PHP define('FACULTY_INITIAL', 5)

let _currentPage = 1;
let _paginatedCards = [];    // the authoritative card list for the current view

/**
 * Entry point: set the card list and render page 1.
 * Only called by showMoreFaculty() and filterFaculty().
 */
function paginateCards(cards) {
    _paginatedCards = cards;
    _currentPage    = 1;
    _renderPage();
}

/**
 * Render the current page from _paginatedCards / _currentPage.
 */
function _renderPage() {
    const total      = _paginatedCards.length;
    const totalPages = Math.ceil(total / CARDS_PER_PAGE);

    const start = (_currentPage - 1) * CARDS_PER_PAGE;
    const end   = start + CARDS_PER_PAGE;

    // Hide all faculty cards first, then show only the current slice
    document.querySelectorAll('.faculty-card').forEach(card => {
        card.style.display = 'none';
    });
    _paginatedCards.slice(start, end).forEach(card => {
        card.style.display = '';
    });

    _renderPaginationControls(total, totalPages);
}

/**
 * Build prev / page-number buttons / next into #pagination.
 * Hides the bar entirely when only 1 page.
 */
function _renderPaginationControls(total, totalPages) {
    const pag = document.getElementById('pagination');
    if (!pag) return;

    pag.innerHTML = '';

    if (totalPages <= 1) {
        pag.style.display = 'none';
        return;
    }

    pag.style.display = 'flex';

    // ← Prev
    const prev = document.createElement('button');
    prev.className = 'page-btn';
    prev.innerHTML = '← Prev';
    prev.disabled  = _currentPage === 1;
    prev.onclick   = () => { _currentPage--; _renderPage(); };
    pag.appendChild(prev);

    // Page-number buttons
    for (let i = 1; i <= totalPages; i++) {
        const btn = document.createElement('button');
        btn.className  = 'page-btn' + (i === _currentPage ? ' active' : '');
        btn.textContent = i;
        const pg = i;
        btn.onclick = () => { _currentPage = pg; _renderPage(); };
        pag.appendChild(btn);
    }

    // Info
    const info = document.createElement('span');
    info.className   = 'page-info';
    info.textContent = `Page ${_currentPage} of ${totalPages}`;
    pag.appendChild(info);

    // Next →
    const next = document.createElement('button');
    next.className = 'page-btn';
    next.innerHTML = 'Next →';
    next.disabled  = _currentPage === totalPages;
    next.onclick   = () => { _currentPage++; _renderPage(); };
    pag.appendChild(next);
}

/* ── Show More faculty ────────────────────────────────────── */
function showMoreFaculty() {
    // Reveal all hidden cards
    document.querySelectorAll('.faculty-card.hidden-card').forEach(card => {
        card.classList.remove('hidden-card');
        card.style.display = '';
    });

    // Hide the Show More card itself
    const showMoreCard = document.getElementById('showMoreCard');
    if (showMoreCard) showMoreCard.style.display = 'none';

    // Collect every faculty card (Show More card is NOT .faculty-card so it won't be counted)
    const allCards = [...document.querySelectorAll('.faculty-card')];

    paginateCards(allCards);
}

/* ── Review modal ─────────────────────────────────────────── */
let selectedFacultyId = null;

function openReviewModal() {
    document.getElementById('reviewModal').classList.add('open');
    goStep(1);
}
function closeReviewModal() {
    document.getElementById('reviewModal').classList.remove('open');
    selectedFacultyId = null;
    document.getElementById('reviewText').value = '';
    document.querySelectorAll('.faculty-option').forEach(o => o.classList.remove('selected'));
    document.getElementById('nextBtn').disabled = true;
    clearReviewPhotos();
}
function goStep(n) {
    document.querySelectorAll('.step').forEach(s => s.classList.remove('active'));
    document.getElementById('step' + n).classList.add('active');
    document.getElementById('modalStepTitle').textContent = n === 1 ? 'Select a Faculty Member' : 'Write Your Review';
    document.getElementById('backBtn').style.display   = n === 2 ? 'inline-flex' : 'none';
    document.getElementById('nextBtn').style.display   = n === 1 ? 'inline-flex' : 'none';
    document.getElementById('submitBtn').style.display = n === 2 ? 'inline-flex' : 'none';
}
function selectFaculty(id, name, dept, avatar) {
    selectedFacultyId = id;
    document.getElementById('facultyIdInput').value = id;
    document.getElementById('previewName').textContent = name;
    document.getElementById('previewDept').textContent = dept;
    document.getElementById('previewImg').src = avatar || ('https://ui-avatars.com/api/?name=' + encodeURIComponent(name) + '&background=8B0000&color=fff&size=42');
    document.querySelectorAll('.faculty-option').forEach(o => o.classList.remove('selected'));
    event.currentTarget.classList.add('selected');
    document.getElementById('nextBtn').disabled = false;
}
function toggleDept(header) {
    header.classList.toggle('open');
    header.nextElementSibling.classList.toggle('open');
}

function openModalForFaculty(id, name, dept, avatar) {
    openReviewModal();
    const opts = document.querySelectorAll('.faculty-option');
    for (const opt of opts) {
        if (opt.getAttribute('onclick') && opt.getAttribute('onclick').includes('selectFaculty(' + id + ',')) {
            selectedFacultyId = id;
            document.getElementById('facultyIdInput').value = id;
            document.getElementById('previewName').textContent = name;
            document.getElementById('previewDept').textContent = dept;
            document.getElementById('previewImg').src = avatar || ('https://ui-avatars.com/api/?name=' + encodeURIComponent(name) + '&background=8B0000&color=fff&size=42');
            document.querySelectorAll('.faculty-option').forEach(o => o.classList.remove('selected'));
            opt.classList.add('selected');
            document.getElementById('nextBtn').disabled = false;
            break;
        }
    }
    setTimeout(() => goStep(2), 100);
}

document.getElementById('reviewModal').addEventListener('click', function(e) {
    if (e.target === this) closeReviewModal();
});

/* ── Edit modal ───────────────────────────────────────────── */
function openEditModal(reviewId, reviewText, facultyName, dept, facultyId, rt, rc, rp, rf, ro) {
    document.getElementById('editReviewId').value = reviewId;
    document.getElementById('editReviewText').value = reviewText;
    document.getElementById('editPreviewName').textContent = facultyName;
    document.getElementById('editPreviewDept').textContent = dept;
    document.getElementById('editPreviewImg').src = 'https://ui-avatars.com/api/?name=' + encodeURIComponent(facultyName) + '&background=8B0000&color=fff&size=42';
    const ratings = { teaching: rt, communication: rc, punctuality: rp, fairness: rf, overall: ro };
    Object.entries(ratings).forEach(([cat, val]) => {
        const input = document.getElementById('edit_star_' + cat + val);
        if (input) input.checked = true;
    });
    document.getElementById('editModal').classList.add('open');
}
function closeEditModal() {
    document.getElementById('editModal').classList.remove('open');
    clearEditReviewPhotos();
}
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});

/* ── Delete modal ─────────────────────────────────────────── */
function openDeleteModal(reviewId, facultyName) {
    document.getElementById('deleteReviewId').value = reviewId;
    document.getElementById('deleteFacultyName').textContent = facultyName;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() { document.getElementById('deleteModal').classList.remove('open'); }
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});

/* ── Resubmit modal ───────────────────────────────────────── */
function openDeleteAndResubmitModal(reviewId, facultyName, facultyId, name, dept) {
    document.getElementById('resubmitReviewId').value = reviewId;
    document.getElementById('resubmitFacultyId').value = facultyId;
    document.getElementById('resubmitPreviewName').textContent = facultyName;
    document.getElementById('resubmitPreviewImg').src = 'https://ui-avatars.com/api/?name=' + encodeURIComponent(name) + '&background=8B0000&color=fff&size=42';
    document.getElementById('resubmitText').value = '';
    document.getElementById('resubmitModal').classList.add('open');
}
function closeResubmitModal() { document.getElementById('resubmitModal').classList.remove('open'); }
document.getElementById('resubmitModal').addEventListener('click', function(e) {
    if (e.target === this) closeResubmitModal();
});

/* ── My Reviews anchor scroll ─────────────────────────────── */
function scrollToReviews(e) {
    if (e) e.preventDefault();
    const el = document.getElementById('reviews-section');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/* ── Client-side review filter tabs ──────────────────────── */
function setReviewFilter(filter, e) {
    if (e) e.preventDefault();
    document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
    if (e && e.currentTarget) e.currentTarget.classList.add('active');

    const rows = document.querySelectorAll('.review-row');
    rows.forEach(row => {
        row.dataset.filtered = (filter === 'all' || row.dataset.status === filter) ? 'show' : 'hide';
    });

    const visibleCount = [...rows].filter(r => r.dataset.filtered === 'show').length;
    const emptyState   = document.getElementById('reviewEmptyState');
    if (emptyState) emptyState.style.display = visibleCount === 0 ? 'flex' : 'none';

    updateReviewBulkBar();
    reviewPage = 1;
    paginateReviews();
}

/* ── Delete mode toggle for reviews ──────────────────────── */
let _deleteMode = false;

function toggleDeleteMode() {
    _deleteMode = !_deleteMode;
    document.body.classList.toggle('review-delete-mode', _deleteMode);
    const btn = document.getElementById('deleteModeBtn');
    const bar = document.getElementById('reviewsBulkBar');
    if (_deleteMode) {
        btn.innerHTML = '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Cancel';
        btn.style.background = '#6b7280';
        btn.style.color = 'white';
    } else {
        btn.innerHTML = '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg> Delete';
        btn.style.background = '';
        btn.style.color = '';
        document.querySelectorAll('.review-row-cb').forEach(cb => cb.checked = false);
        bar.classList.remove('show');
        updateReviewBulkBar();
    }
}

/* ── Bulk delete for reviews ──────────────────────────────── */
function updateReviewBulkBar() {
    const checked = document.querySelectorAll('.review-row-cb:checked');
    const bar     = document.getElementById('reviewsBulkBar');
    const cnt     = document.getElementById('reviewsBulkCount');
    if (bar) bar.classList.toggle('show', checked.length > 0);
    if (cnt) cnt.textContent = checked.length + ' review' + (checked.length !== 1 ? 's' : '') + ' selected';
}

function selectAllReviews(checked) {
    document.querySelectorAll('.review-row:not([style*="none"]) .review-row-cb').forEach(cb => {
        cb.checked = checked;
    });
    updateReviewBulkBar();
}

/* ── Review rows pagination ───────────────────────────────── */
const REVIEWS_PER_PAGE = 5;
let reviewPage = 1;

function paginateReviews() {
    const allRows     = [...document.querySelectorAll('.review-row')];
    const visibleRows = allRows.filter(r => r.dataset.filtered !== 'hide');
    const total       = visibleRows.length;
    const totalPages  = Math.ceil(total / REVIEWS_PER_PAGE);
    const start       = (reviewPage - 1) * REVIEWS_PER_PAGE;
    const end         = start + REVIEWS_PER_PAGE;

    allRows.forEach(r => r.style.display = 'none');
    visibleRows.slice(start, end).forEach(r => r.style.display = '');

    let pag = document.getElementById('reviewPagination');
    if (!pag) {
        pag = document.createElement('div');
        pag.id = 'reviewPagination';
        pag.className = 'review-pagination';
        document.querySelector('.review-card').appendChild(pag);
    }
    pag.innerHTML = '';
    if (totalPages <= 1) return;

    const prev = document.createElement('button');
    prev.className = 'page-btn'; prev.textContent = '← Prev';
    prev.disabled = reviewPage === 1;
    prev.onclick = () => { reviewPage--; paginateReviews(); };
    pag.appendChild(prev);

    const info = document.createElement('span');
    info.className = 'page-info';
    info.textContent = `${reviewPage} / ${totalPages}`;
    pag.appendChild(info);

    for (let i = 1; i <= totalPages; i++) {
        const btn = document.createElement('button');
        btn.className = 'page-btn' + (i === reviewPage ? ' active' : '');
        btn.textContent = i;
        btn.onclick = () => { reviewPage = i; paginateReviews(); };
        pag.appendChild(btn);
    }

    const next = document.createElement('button');
    next.className = 'page-btn'; next.textContent = 'Next →';
    next.disabled = reviewPage === totalPages;
    next.onclick = () => { reviewPage++; paginateReviews(); };
    pag.appendChild(next);
}

/* ── Multi-photo preview helpers ──────────────────────────── */
let _reviewPhotoFiles = [];
let _editPhotoFiles   = [];

function buildPhotoGrid(files, gridId, dropzoneId, addMoreId, countId, clearFn) {
    const grid = document.getElementById(gridId);
    const dz   = document.getElementById(dropzoneId);
    const more = document.getElementById(addMoreId);
    const cnt  = document.getElementById(countId);
    if (!files.length) { grid.style.display = 'none'; dz.style.display = ''; more.style.display = 'none'; return; }
    grid.style.display = 'grid';
    dz.style.display   = 'none';
    more.style.display = '';
    if (cnt) cnt.textContent = files.length + ' photo' + (files.length !== 1 ? 's' : '') + ' selected';
    grid.innerHTML = '';
    files.forEach((file, idx) => {
        const reader = new FileReader();
        reader.onload = e => {
            const wrap = document.createElement('div');
            wrap.style.cssText = 'position:relative;border-radius:8px;overflow:hidden;aspect-ratio:1;border:1px solid var(--gray-200);';
            wrap.innerHTML = `<img src="${e.target.result}" style="width:100%;height:100%;object-fit:cover;display:block;">
                <button type="button" onclick="${clearFn}(${idx})" style="position:absolute;top:3px;right:3px;width:20px;height:20px;border-radius:50%;background:rgba(0,0,0,0.6);border:none;cursor:pointer;color:white;font-size:12px;display:flex;align-items:center;justify-content:center;line-height:1;">×</button>`;
            grid.appendChild(wrap);
        };
        reader.readAsDataURL(file);
    });
}

function rebuildFileInput(inputId, filesArr) {
    try {
        const dt = new DataTransfer();
        filesArr.forEach(f => dt.items.add(f));
        const inp = document.getElementById(inputId);
        if (inp) inp.files = dt.files;
    } catch(e) {}
}

function previewReviewPhotos(input) {
    const MAX = 5;
    _reviewPhotoFiles = [..._reviewPhotoFiles, ...Array.from(input.files)].slice(0, MAX);
    rebuildFileInput('reviewPhotosInput', _reviewPhotoFiles);
    buildPhotoGrid(_reviewPhotoFiles, 'reviewPhotosPreviewGrid', 'reviewPhotoDropzone', 'reviewPhotosAddMore', 'reviewPhotosCount', 'removeReviewPhoto');
}
function removeReviewPhoto(idx) {
    _reviewPhotoFiles.splice(idx, 1);
    rebuildFileInput('reviewPhotosInput', _reviewPhotoFiles);
    buildPhotoGrid(_reviewPhotoFiles, 'reviewPhotosPreviewGrid', 'reviewPhotoDropzone', 'reviewPhotosAddMore', 'reviewPhotosCount', 'removeReviewPhoto');
}
function clearReviewPhotos() {
    _reviewPhotoFiles = [];
    const inp = document.getElementById('reviewPhotosInput');
    if (inp) inp.value = '';
    buildPhotoGrid([], 'reviewPhotosPreviewGrid', 'reviewPhotoDropzone', 'reviewPhotosAddMore', 'reviewPhotosCount', 'removeReviewPhoto');
}

function previewEditReviewPhotos(input) {
    const MAX = 5;
    _editPhotoFiles = [..._editPhotoFiles, ...Array.from(input.files)].slice(0, MAX);
    rebuildFileInput('editReviewPhotosInput', _editPhotoFiles);
    buildPhotoGrid(_editPhotoFiles, 'editReviewPhotosPreviewGrid', 'editPhotoDropzone', 'editPhotosAddMore', 'editPhotosCount', 'removeEditPhoto');
}
function removeEditPhoto(idx) {
    _editPhotoFiles.splice(idx, 1);
    rebuildFileInput('editReviewPhotosInput', _editPhotoFiles);
    buildPhotoGrid(_editPhotoFiles, 'editReviewPhotosPreviewGrid', 'editPhotoDropzone', 'editPhotosAddMore', 'editPhotosCount', 'removeEditPhoto');
}
function clearEditReviewPhotos() {
    _editPhotoFiles = [];
    const inp = document.getElementById('editReviewPhotosInput');
    if (inp) inp.value = '';
    buildPhotoGrid([], 'editReviewPhotosPreviewGrid', 'editPhotoDropzone', 'editPhotosAddMore', 'editPhotosCount', 'removeEditPhoto');
}

/* ══════════════════════════════════════════════════════════
   CHATBOT
   ══════════════════════════════════════════════════════════ */

const GROQ_MODEL = 'llama-3.3-70b-versatile';

const CHATBOT_SYSTEM = `You are a helpful FAQ assistant for OlshcoReview, an Anonymous Online Faculty Performance Evaluation and Feedback System. Be concise and friendly.

SUBMITTING A REVIEW: Go to Dashboard → find faculty → click "Evaluate" → Step 1 select faculty, Step 2 rate on 5 star categories + write review → Submit. Review goes pending until admin approves.

STAR RATING CATEGORIES (all required, 1-5 stars each):
1. Teaching Effectiveness
2. Communication Skills
3. Punctuality & Availability
4. Fairness in Grading
5. Overall Satisfaction

EDITING: Only approved reviews can be edited. After editing, review goes back to pending for re-approval.

REVIEW STATUSES: Pending = awaiting admin approval. Approved = published. Rejected = not accepted, can resubmit via "Resubmit" button on the faculty card.

ONE REVIEW PER FACULTY: Cannot submit a second review for same faculty. Edit or resubmit existing one.

ANONYMITY: All reviews are 100% anonymous. Only username visible to admins, never real identity.

NOTIFICATIONS: In-app + email notifications when approved or rejected.

TOXIC CONTENT: AI scans reviews before submission. Flagged reviews are blocked. Keep feedback respectful.

PROFILE: Update pseudonym, username, email, password, profile photo from the Profile page (click avatar in sidebar).

Keep answers short (3-5 sentences max). Use line breaks for steps. Do not invent features.`;

function toggleChat() {
    const w = document.getElementById('chat-window');
    const isOpen = w.style.display === 'flex';
    w.style.display = isOpen ? 'none' : 'flex';
    if (!isOpen) {
        w.style.flexDirection = 'column';
        document.getElementById('chat-input').focus();
    }
}

document.getElementById('chat-input').addEventListener('input', function () {
    if (this.value.trim()) {
        document.getElementById('faqSuggestions').style.display = 'none';
    }
});

function askFaq(question) {
    document.getElementById('faqSuggestions').style.display = 'none';
    document.getElementById('chat-input').value = question;
    sendChat();
}

async function sendChat() {
    const input = document.getElementById('chat-input');
    const msg   = input.value.trim();
    if (!msg) return;

    addBubble(msg, 'user');
    input.value = '';

    const typingEl = addBubble('Typing…', 'bot', 'typing-indicator');

    try {
        const reply = await callGroqDirect(msg);
        typingEl.remove();
        addBubble(reply, 'bot');
    } catch (groqErr) {
        console.warn('Groq direct call failed:', groqErr.message, '— falling back to PHP FAQ');
        try {
            const res  = await fetch('chatbot.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'message=' + encodeURIComponent(msg)
            });
            const data = await res.json();
            typingEl.remove();
            addBubble(data.reply || "Sorry, I couldn't get a response right now.", 'bot');
        } catch (phpErr) {
            typingEl.remove();
            addBubble("I'm having trouble connecting right now. Please try again in a moment.", 'bot');
        }
    }
}

async function callGroqDirect(userMessage) {
    const GROQ_API_KEY = window.GROQ_API_KEY;
    const response = await fetch('https://api.groq.com/openai/v1/chat/completions', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + GROQ_API_KEY
        },
        body: JSON.stringify({
            model: GROQ_MODEL,
            max_tokens: 400,
            messages: [
                { role: 'system', content: CHATBOT_SYSTEM },
                { role: 'user',   content: userMessage }
            ]
        })
    });

    if (!response.ok) {
        const errText = await response.text();
        throw new Error('Groq API ' + response.status + ': ' + errText.slice(0, 120));
    }

    const data  = await response.json();
    const reply = data?.choices?.[0]?.message?.content;
    if (!reply) throw new Error('Empty Groq response');
    return reply;
}

function addBubble(text, from, id) {
    const box = document.getElementById('chat-messages');
    const d   = document.createElement('div');
    d.className = 'chat-msg ' + from;
    if (id) d.id = id;
    d.innerHTML = String(text).replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
    box.appendChild(d);
    box.scrollTop = box.scrollHeight;
    return d;
}

/* ── DOMContentLoaded init ────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {

    // ── Faculty grid initial state ─────────────────────────
    // Cards beyond FACULTY_INITIAL are already marked hidden-card by PHP.
    // We only show the first FACULTY_INITIAL cards; no pagination yet —
    // pagination kicks in only after Show More is clicked.
    const showMoreCard = document.getElementById('showMoreCard');
    const allCards     = [...document.querySelectorAll('.faculty-card')];

    if (allCards.length <= FACULTY_INITIAL) {
        // Fewer cards than initial threshold → hide Show More, show all cards normally
        if (showMoreCard) showMoreCard.style.display = 'none';
    }
    // hidden-card CSS class already hides the extra cards via PHP markup;
    // pagination bar starts hidden and only appears after showMoreFaculty().

    // ── Init review filter ────────────────────────────────
    document.querySelectorAll('.review-row').forEach(r => r.dataset.filtered = 'show');
    if (document.querySelectorAll('.review-row').length > REVIEWS_PER_PAGE) paginateReviews();

    // Open first dept group in review modal
    const first = document.querySelector('.dept-header');
    if (first) { first.classList.add('open'); first.nextElementSibling.classList.add('open'); }

    // Auto-dismiss banners
    document.querySelectorAll('.success-banner').forEach(banner => {
        setTimeout(() => {
            banner.style.transition = 'opacity 0.6s ease';
            banner.style.opacity = '0';
            setTimeout(() => banner.style.display = 'none', 600);
        }, 8000);
    });

    // Review checkbox bulk bar
    document.querySelectorAll('.review-row-cb').forEach(cb => {
        cb.addEventListener('change', updateReviewBulkBar);
    });

    // My Reviews nav anchor
    const myReviewsLink = document.getElementById('myReviewsNavLink');
    if (myReviewsLink) {
        myReviewsLink.addEventListener('click', scrollToReviews);
    }
});