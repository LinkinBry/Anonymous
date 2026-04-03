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

    // Restore saved state
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
    const allCards = document.querySelectorAll('.faculty-card');
    allCards.forEach(card => {
        card.style.display = (dept === 'all' || card.dataset.dept === dept) ? '' : 'none';
    });
    const visible = [...allCards].filter(c => c.style.display !== 'none');
    paginateCards(visible);
}

/* ── Faculty pagination — 8 per page ──────────────────────── */
const CARDS_PER_PAGE = 8;
let currentPage = 1;
const pagination = document.getElementById('pagination');

function paginateCards(cards) {
    const totalPages = Math.ceil(cards.length / CARDS_PER_PAGE);
    currentPage = 1;
    renderPage(cards, currentPage, totalPages);
}

function renderPage(cards, page, totalPages) {
    const start = (page - 1) * CARDS_PER_PAGE;
    const end   = start + CARDS_PER_PAGE;
    document.querySelectorAll('.faculty-card').forEach(card => card.style.display = 'none');
    cards.slice(start, end).forEach(card => card.style.display = '');
    renderPagination(cards, page, totalPages);
}

function renderPagination(cards, page, totalPages) {
    if (!pagination) return;
    pagination.innerHTML = '';
    if (totalPages <= 1) return;

    const prev = document.createElement('button');
    prev.className = 'page-btn';
    prev.innerHTML = '← Prev';
    prev.disabled = page === 1;
    prev.onclick = () => { currentPage--; renderPage(cards, currentPage, totalPages); };
    pagination.appendChild(prev);

    const info = document.createElement('span');
    info.className = 'page-info';
    info.textContent = `Page ${page} of ${totalPages}`;
    pagination.appendChild(info);

    for (let i = 1; i <= totalPages; i++) {
        const btn = document.createElement('button');
        btn.className = 'page-btn' + (i === page ? ' active' : '');
        btn.textContent = i;
        btn.onclick = () => { currentPage = i; renderPage(cards, currentPage, totalPages); };
        pagination.appendChild(btn);
    }

    const next = document.createElement('button');
    next.className = 'page-btn';
    next.innerHTML = 'Next →';
    next.disabled = page === totalPages;
    next.onclick = () => { currentPage++; renderPage(cards, currentPage, totalPages); };
    pagination.appendChild(next);
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

    // Update bulk select state
    updateReviewBulkBar();

    reviewPage = 1;
    paginateReviews();
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

/* ── Chatbot ──────────────────────────────────────────────── */
function toggleChat() {
    const w = document.getElementById('chat-window');
    w.style.display = w.style.display === 'flex' ? 'none' : 'flex';
    if (w.style.display === 'flex') {
        w.style.flexDirection = 'column';
        document.getElementById('chat-input').focus();
    }
}

document.getElementById('chat-input').addEventListener('input', function() {
    if (this.value.trim()) document.getElementById('faqSuggestions').style.display = 'none';
});

function askFaq(question) {
    document.getElementById('faqSuggestions').style.display = 'none';
    document.getElementById('chat-input').value = question;
    sendChat();
}

function sendChat() {
    const input = document.getElementById('chat-input');
    const msg = input.value.trim();
    if (!msg) return;
    addBubble(msg, 'user');
    input.value = '';
    addBubble('Typing...', 'bot', 'typing-indicator');
    fetch('chatbot.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'message=' + encodeURIComponent(msg)
    })
    .then(r => r.json())
    .then(data => {
        const t = document.getElementById('typing-indicator');
        if (t) t.remove();
        addBubble(data.reply || 'Sorry, try again.', 'bot');
    })
    .catch(() => {
        const t = document.getElementById('typing-indicator');
        if (t) t.remove();
        addBubble('Connection error. Please try again.', 'bot');
    });
}

function addBubble(text, from, id) {
    const box = document.getElementById('chat-messages');
    const d = document.createElement('div');
    d.className = 'chat-msg ' + from;
    if (id) d.id = id;
    d.textContent = text;
    box.appendChild(d);
    box.scrollTop = box.scrollHeight;
    return d;
}

/* ── DOMContentLoaded init ────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    // Init faculty pagination
    const allFacultyCards = [...document.querySelectorAll('.faculty-card')];
    if (allFacultyCards.length > 0) paginateCards(allFacultyCards);

    // Init review filter
    document.querySelectorAll('.review-row').forEach(r => r.dataset.filtered = 'show');
    if (document.querySelectorAll('.review-row').length > REVIEWS_PER_PAGE) paginateReviews();

    // Open first dept group
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