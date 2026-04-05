/* ============================================================
   admin.js — Admin dashboard interactions
   ============================================================ */

/* ── Sidebar toggle ───────────────────────────────────────── */
(function () {
    const KEY    = 'ar_sidebar_collapsed';
    const toggle = document.getElementById('sidebarToggle');
    const body   = document.body;
    function apply(c) { body.classList.toggle('sidebar-collapsed', c); }
    apply(localStorage.getItem(KEY) === '1');
    if (toggle) {
        toggle.addEventListener('click', () => {
            const now = !body.classList.contains('sidebar-collapsed');
            apply(now);
            localStorage.setItem(KEY, now ? '1' : '0');
        });
    }
})();

/* ══════════════════════════════════════════════════════════════
   UNIVERSAL 5-ITEM SECTION PAGINATOR
   Works on any wrap with class .admin-pageable-row
   ══════════════════════════════════════════════════════════════ */
const SECTION_PER_PAGE = 5;
const sectionPages     = {};  // { sectionKey: currentPage }

/**
 * paginateSection(wrapId, paginationId, rows)
 * Shows 5 rows at a time, renders prev/page-nums/next in paginationId.
 * Only renders pagination if rows.length > SECTION_PER_PAGE.
 */
function paginateSection(wrapId, paginationId, rows) {
    const key  = wrapId;
    const page = sectionPages[key] || 1;
    const total = rows.length;
    const totalPages = Math.ceil(total / SECTION_PER_PAGE) || 1;
    const safeP = Math.min(page, totalPages);
    sectionPages[key] = safeP;

    const start = (safeP - 1) * SECTION_PER_PAGE;
    const end   = start + SECTION_PER_PAGE;

    // Hide all rows in wrap, show only current page
    rows.forEach((r, i) => { r.style.display = (i >= start && i < end) ? '' : 'none'; });

    const pag = document.getElementById(paginationId);
    if (!pag) return;
    pag.innerHTML = '';

    if (total <= SECTION_PER_PAGE) return;  // no pagination needed

    // Info
    const info = document.createElement('span');
    info.className = 'pag-info';
    info.textContent = `${Math.min(start + 1, total)}–${Math.min(end, total)} of ${total}`;
    pag.appendChild(info);

    // Prev
    const prev = document.createElement('button');
    prev.className = 'pag-btn'; prev.type = 'button'; prev.textContent = '←';
    prev.disabled = safeP <= 1;
    prev.onclick = () => { sectionPages[key] = safeP - 1; paginateSection(wrapId, paginationId, rows); };
    pag.appendChild(prev);

    // Page buttons (max 5 shown)
    const maxBtns = 5;
    let startBtn = Math.max(1, safeP - Math.floor(maxBtns / 2));
    let endBtn   = Math.min(totalPages, startBtn + maxBtns - 1);
    if (endBtn - startBtn < maxBtns - 1) startBtn = Math.max(1, endBtn - maxBtns + 1);
    for (let i = startBtn; i <= endBtn; i++) {
        const btn = document.createElement('button');
        btn.className = 'pag-btn' + (i === safeP ? ' active' : '');
        btn.type = 'button'; btn.textContent = i;
        const pg = i;
        btn.onclick = () => { sectionPages[key] = pg; paginateSection(wrapId, paginationId, rows); };
        pag.appendChild(btn);
    }

    // Next
    const next = document.createElement('button');
    next.className = 'pag-btn'; next.type = 'button'; next.textContent = '→';
    next.disabled = safeP >= totalPages;
    next.onclick = () => { sectionPages[key] = safeP + 1; paginateSection(wrapId, paginationId, rows); };
    pag.appendChild(next);
}

/** Collect visible rows from a wrap and re-paginate */
function repaginate(wrapId, paginationId, selector) {
    const wrap = document.getElementById(wrapId);
    if (!wrap) return;
    // All rows that haven't been hidden by filter
    const rows = [...wrap.querySelectorAll(selector || '.admin-pageable-row')].filter(r => !r.classList.contains('filter-hidden'));
    sectionPages[wrapId] = 1;
    paginateSection(wrapId, paginationId, rows);
}

/* ── Smooth scroll for sidebar nav anchors ────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.sidebar a[href^="#"]').forEach(link => {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            const el = document.getElementById(this.getAttribute('href').slice(1));
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
    const hash = window.location.hash;
    if (hash) {
        const el = document.getElementById(hash.slice(1));
        if (el) setTimeout(() => el.scrollIntoView({ behavior: 'auto', block: 'start' }), 80);
    }

    /* ── Init all section paginators ── */
    initAllPaginators();

    /* ── Chart ── */
    if (document.getElementById('weeklyChart')) {
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js';
        s.onload = () => renderChart('reviews');
        document.head.appendChild(s);
    }

    /* ── Edit users toggle ── */
    const editBtn = document.getElementById('edit_users_btn');
    if (editBtn) {
        editBtn.addEventListener('click', function () {
            const tds     = document.querySelectorAll('.checkbox_td');
            const showing = tds.length > 0 && tds[0].style.display !== 'none';
            tds.forEach(td => td.style.display = showing ? 'none' : 'inline-flex');
            this.innerHTML = showing
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Edit'
                : '✕ Cancel';
            if (showing) {
                document.querySelectorAll('.user_checkbox').forEach(c => c.checked = false);
                toggleUserBulk();
            }
        });
        document.querySelectorAll('.user_checkbox').forEach(c => c.addEventListener('change', toggleUserBulk));
    }
});

function initAllPaginators() {
    // Pending reviews
    const pendingRows = [...document.querySelectorAll('#pending-rows-wrap .admin-pageable-row')];
    if (pendingRows.length) paginateSection('pending-rows-wrap', 'pending-pagination', pendingRows);

    // Faculty
    const facRows = [...document.querySelectorAll('#fac-rows-wrap .admin-pageable-row')];
    if (facRows.length) paginateSection('fac-rows-wrap', 'faculty-pagination', facRows);

    // Users
    const userRows = [...document.querySelectorAll('#users-rows-wrap .admin-pageable-row')];
    if (userRows.length) paginateSection('users-rows-wrap', 'users-pagination', userRows);

    // Approved reviews
    const approvedRows = [...document.querySelectorAll('#approved-rows-wrap .admin-pageable-row')];
    if (approvedRows.length) paginateSection('approved-rows-wrap', 'approved-pagination', approvedRows);
}

/* ── Escape helper ────────────────────────────────────────── */
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

/* ── SVG star renderer ────────────────────────────────────── */
let _su = 0;
function starsHtml(val, size) {
    size = size || 15;
    if (!val || parseFloat(val) <= 0) return '<span style="color:var(--gray-400);font-size:12px;">—</span>';
    const pct  = Math.min(100, Math.max(0, (parseFloat(val) / 5) * 100));
    const gap  = 2, w = size * 5 + gap * 4, base = Math.round(size * 0.85);
    const uid  = 'sr' + (++_su), cW = (pct / 100 * w).toFixed(2);
    let e = '', f = '';
    for (let i = 0; i < 5; i++) {
        const x = i * (size + gap);
        e += `<text x="${x}" y="${base}" font-size="${size}" fill="#d1d5db">★</text>`;
        f += `<text x="${x}" y="${base}" font-size="${size}" fill="#f59e0b">★</text>`;
    }
    return `<span class="star-svg-wrap"><svg width="${w}" height="${size}" viewBox="0 0 ${w} ${size}" xmlns="http://www.w3.org/2000/svg" style="display:block;overflow:hidden;"><defs><clipPath id="${uid}"><rect x="0" y="0" width="${cW}" height="${size}"/></clipPath></defs>${e}<g clip-path="url(#${uid})">${f}</g></svg></span>`;
}

/* ── Client-side row filters ──────────────────────────────── */
function filterPendingRows() {
    const q   = document.getElementById('pending-search').value.toLowerCase().trim();
    const sen = document.getElementById('pending-sentiment-filter').value.toLowerCase();
    const rows = [...document.querySelectorAll('#pending-rows-wrap .admin-pageable-row')];
    let   any = false;
    rows.forEach(row => {
        const matchText = !q    || row.dataset.text.includes(q);
        const matchSen  = !sen  || row.dataset.sentiment === sen;
        const show = matchText && matchSen;
        row.classList.toggle('filter-hidden', !show);
        if (show) any = true;
    });
    document.getElementById('no-pending-results').style.display = any ? 'none' : '';
    // Re-paginate visible rows
    const visible = rows.filter(r => !r.classList.contains('filter-hidden'));
    sectionPages['pending-rows-wrap'] = 1;
    paginateSection('pending-rows-wrap', 'pending-pagination', visible);
}

function filterFacRows() {
    const q    = document.getElementById('faculties-search').value.toLowerCase().trim();
    const dept = document.getElementById('faculties-dept-filter').value.toLowerCase();
    const rows = [...document.querySelectorAll('#fac-rows-wrap .admin-pageable-row')];
    let   any  = false;
    rows.forEach(row => {
        const matchText = !q    || row.dataset.text.includes(q);
        const matchDept = !dept || row.dataset.dept === dept;
        const show = matchText && matchDept;
        row.classList.toggle('filter-hidden', !show);
        if (show) any = true;
    });
    document.getElementById('no-fac-results').style.display = any ? 'none' : '';
    const visible = rows.filter(r => !r.classList.contains('filter-hidden'));
    sectionPages['fac-rows-wrap'] = 1;
    paginateSection('fac-rows-wrap', 'faculty-pagination', visible);
}

function filterUserRows() {
    const q = document.getElementById('users-search').value.toLowerCase().trim();
    const rows = [...document.querySelectorAll('#users-rows-wrap .admin-pageable-row')];
    rows.forEach(row => {
        row.classList.toggle('filter-hidden', q && !row.dataset.text.includes(q));
    });
    const visible = rows.filter(r => !r.classList.contains('filter-hidden'));
    sectionPages['users-rows-wrap'] = 1;
    paginateSection('users-rows-wrap', 'users-pagination', visible);
}

function filterApprovedRows() {
    const q   = document.getElementById('approved-search').value.toLowerCase().trim();
    const fac = document.getElementById('approved-faculty-filter').value.toLowerCase();
    const rows = [...document.querySelectorAll('#approved-rows-wrap .admin-pageable-row')];
    let   any = false;
    rows.forEach(row => {
        const matchText = !q   || row.dataset.text.includes(q);
        const matchFac  = !fac || row.dataset.text.includes(fac);
        const show = matchText && matchFac;
        row.classList.toggle('filter-hidden', !show);
        if (show) any = true;
    });
    const noRes = document.getElementById('no-approved-results');
    if (noRes) noRes.style.display = any ? 'none' : '';
    const visible = rows.filter(r => !r.classList.contains('filter-hidden'));
    sectionPages['approved-rows-wrap'] = 1;
    paginateSection('approved-rows-wrap', 'approved-pagination', visible);
}

/* ── Bulk helpers ─────────────────────────────────────────── */
function toggleUserBulk() {
    const c = document.querySelectorAll('.user_checkbox:checked');
    document.getElementById('bulk_actions').classList.toggle('show', c.length > 0);
    document.getElementById('selected_count').textContent = c.length + ' user' + (c.length !== 1 ? 's' : '') + ' selected';
}
function updateReviewBulk() {
    const c = document.querySelectorAll('.review_cb:checked');
    document.getElementById('review_bulk_bar').classList.toggle('show', c.length > 0);
    document.getElementById('review_selected_count').textContent = c.length + ' review' + (c.length !== 1 ? 's' : '') + ' selected';
}
function updateApprovedBulk() {
    const c = document.querySelectorAll('.approved_cb:checked');
    document.getElementById('approved_bulk_bar').classList.toggle('show', c.length > 0);
    document.getElementById('approved_selected_count').textContent = c.length + ' review' + (c.length !== 1 ? 's' : '') + ' selected';
}

/* ── User modal ───────────────────────────────────────────── */
let _cUid = 0;
function openUserModal(id, fn, un, em, av) {
    document.getElementById('userModalAvatar').src    = av;
    document.getElementById('userModalName').textContent     = fn;
    document.getElementById('userModalUsername').textContent = '@' + un;
    document.getElementById('userModalEmail').textContent    = em;
    _cUid = id;
    document.getElementById('userModal').classList.add('open');
    loadUserReviews(id);
}
function confirmDeleteUser(id, fn) {
    document.getElementById('deleteUserIdInput').value    = id;
    document.getElementById('deleteUserName').textContent = fn;
    document.getElementById('deleteUserModal').classList.add('open');
}
document.getElementById('userModal').addEventListener('click', e => { if (e.target === document.getElementById('userModal')) document.getElementById('userModal').classList.remove('open'); });
document.getElementById('deleteUserModal').addEventListener('click', e => { if (e.target === document.getElementById('deleteUserModal')) document.getElementById('deleteUserModal').classList.remove('open'); });

function loadUserReviews(uid, page = 1) {
    const list = document.getElementById('userReviewsList');
    list.innerHTML = '<div style="color:var(--gray-400);font-size:13px;padding:8px 0;">Loading...</div>';
    fetch('get_user_reviews.php?user_id=' + uid + '&page=' + page, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (!data.reviews || data.reviews.length === 0) {
                list.innerHTML = '<div style="color:var(--gray-400);font-size:13px;padding:8px 0;">No reviews made yet.</div>';
                return;
            }
            let html = '';
            data.reviews.forEach(rev => {
                const sc  = rev.sentiment === 'positive' ? 'badge-positive' : (rev.sentiment === 'negative' ? 'badge-negative' : 'badge-neutral');
                const sl  = rev.sentiment ? rev.sentiment.charAt(0).toUpperCase() + rev.sentiment.slice(1) : 'Neutral';
                const stBg = rev.status === 'approved' ? '#d1fae5' : rev.status === 'rejected' ? '#fee2e2' : '#fef3c7';
                const stC  = rev.status === 'approved' ? '#065f46' : rev.status === 'rejected' ? '#991b1b' : '#92400e';
                const rd   = JSON.stringify({ text: rev.review_text, sentiment: rev.sentiment, date: rev.created_at, rt: rev.rating_teaching, rc: rev.rating_communication, rp: rev.rating_punctuality, rf: rev.rating_fairness, ro: rev.rating_overall, photos: rev.photos || [] });
                html += `<div style="padding:8px 0 10px;border-bottom:1px solid var(--gray-100);">
                    <div style="display:flex;gap:8px;align-items:flex-start;">
                        <div class="fac-rev-card" style="flex:1;" onclick='openRevDetail(${rd})'>
                            <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;flex-wrap:wrap;">
                                <span style="font-size:12px;font-weight:600;color:var(--gray-700);">${esc(rev.faculty_name)}</span>
                                <span class="badge ${sc}" style="font-size:10px;">${sl}</span>
                                <span style="font-size:10px;padding:1px 6px;border-radius:20px;font-weight:600;background:${stBg};color:${stC};">${rev.status.charAt(0).toUpperCase() + rev.status.slice(1)}</span>
                                <span style="font-size:10px;color:var(--gray-400);margin-left:auto;">${rev.created_at}</span>
                            </div>
                            ${rev.rating_overall ? `<div style="margin-bottom:3px;">${starsHtml(rev.rating_overall, 11)}</div>` : ''}
                            <div style="font-size:12px;color:var(--gray-600);line-height:1.5;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">${esc(rev.review_text)}</div>
                        </div>
                        <button type="button" onclick="deleteUserReview(${rev.id},${uid},${page})" style="flex-shrink:0;background:none;border:1px solid var(--gray-200);border-radius:6px;padding:5px 8px;cursor:pointer;color:var(--gray-400);font-size:11px;display:flex;align-items:center;gap:3px;transition:all 0.18s;" onmouseover="this.style.borderColor='#ef4444';this.style.color='#ef4444';" onmouseout="this.style.borderColor='var(--gray-200)';this.style.color='var(--gray-400)';">
                            <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg> Del
                        </button>
                    </div>
                </div>`;
            });
            if (data.total_pages > 1) {
                html += '<div style="display:flex;align-items:center;gap:5px;padding-top:8px;flex-wrap:wrap;">';
                html += `<span style="font-size:11px;color:var(--gray-400);flex:1;">${(page-1)*5+1}–${Math.min(page*5,data.total)} of ${data.total}</span>`;
                if (page > 1) html += `<button type="button" onclick="loadUserReviews(${uid},${page-1})" class="btn btn-outline" style="padding:3px 8px;font-size:11px;">←</button>`;
                for (let i = 1; i <= data.total_pages; i++) html += `<button type="button" onclick="loadUserReviews(${uid},${i})" class="btn ${i===page?'btn-maroon':'btn-outline'}" style="padding:3px 8px;font-size:11px;">${i}</button>`;
                if (page < data.total_pages) html += `<button type="button" onclick="loadUserReviews(${uid},${page+1})" class="btn btn-outline" style="padding:3px 8px;font-size:11px;">→</button>`;
                html += '</div>';
            }
            list.innerHTML = html;
        })
        .catch(() => { list.innerHTML = '<div style="color:#ef4444;font-size:13px;">Failed to load.</div>'; });
}
function deleteUserReview(rid, uid, pg) {
    if (!confirm('Delete this review permanently?')) return;
    fetch('get_faculty_reviews.php?faculty_id=0&action=delete_any&review_id=' + rid, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(d => { if (d.success) loadUserReviews(uid, pg); else alert('Failed to delete.'); })
        .catch(() => alert('Network error.'));
}

/* ── Review detail popup ──────────────────────────────────── */
function openRevDetail(rd) {
    let m = document.getElementById('facRevDetail');
    if (!m) {
        m = document.createElement('div');
        m.id = 'facRevDetail';
        m.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:2000;align-items:center;justify-content:center;backdrop-filter:blur(2px);';
        m.innerHTML = `<div style="background:white;border-radius:14px;width:100%;max-width:500px;max-height:82vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.18);animation:slideUp 0.22s ease;">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--gray-100);position:sticky;top:0;background:white;z-index:2;">
                <div id="frd-title" style="font-size:15px;font-weight:600;color:var(--gray-800);display:flex;align-items:center;gap:8px;"></div>
                <button type="button" onclick="document.getElementById('facRevDetail').style.display='none'" style="width:28px;height:28px;border-radius:50%;background:var(--gray-100);border:none;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;color:var(--gray-600);">&times;</button>
            </div>
            <div id="frd-body" style="padding:20px;"></div>
        </div>`;
        m.addEventListener('click', e => { if (e.target === m) m.style.display = 'none'; });
        document.body.appendChild(m);
    }
    const cats = [['Teaching Effectiveness', rd.rt], ['Communication Skills', rd.rc], ['Punctuality & Availability', rd.rp], ['Fairness in Grading', rd.rf], ['Overall Satisfaction', rd.ro]];
    const sc = rd.sentiment === 'positive' ? 'badge-positive' : (rd.sentiment === 'negative' ? 'badge-negative' : 'badge-neutral');
    const sl = rd.sentiment ? rd.sentiment.charAt(0).toUpperCase() + rd.sentiment.slice(1) : 'Neutral';
    document.getElementById('frd-title').innerHTML = `Detailed Review <span class="badge ${sc}" style="font-size:11px;">${sl}</span>`;
    let body = `<div style="font-size:11px;color:var(--gray-400);margin-bottom:14px;">${esc(rd.date || '')}</div>`;
    body += '<div style="margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid var(--gray-100);"><div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--gray-400);margin-bottom:8px;">Ratings</div>';
    cats.forEach(([l, v]) => { body += `<div style="display:flex;align-items:center;padding:4px 0;font-size:13px;gap:10px;"><span style="min-width:170px;flex-shrink:0;color:var(--gray-600);">${l}</span>${starsHtml(v, 14)}<span style="font-size:11px;color:var(--gray-400);margin-left:4px;">${v || '—'}/5</span></div>`; });
    body += '</div>';
    body += `<div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--gray-400);margin-bottom:8px;">Review</div><div style="font-size:13px;color:var(--gray-700);line-height:1.7;white-space:pre-wrap;">${esc(rd.text)}</div>`;
    document.getElementById('frd-body').innerHTML = body;
    m.style.display = 'flex';
}

/* ── Pending/approved review view modal ───────────────────── */
function openModal(text, faculty, user, rt, rc, rp, rf, ro, photo) {
    document.getElementById('modalBody').textContent     = text;
    document.getElementById('modalFaculty').textContent  = faculty;
    document.getElementById('modalUser').textContent     = user;
    const cats    = [['Teaching Effectiveness', rt], ['Communication Skills', rc], ['Punctuality & Availability', rp], ['Fairness in Grading', rf], ['Overall Satisfaction', ro]];
    const hasR    = cats.some(c => c[1] > 0);
    const rEl     = document.getElementById('modalRatings');
    if (hasR) {
        rEl.style.display = '';
        rEl.innerHTML = cats.map(([l, v]) => `<div style="display:flex;align-items:center;padding:5px 0;font-size:13px;gap:10px;"><span style="min-width:170px;flex-shrink:0;color:var(--gray-600);">${l}</span>${starsHtml(v, 14)}<span style="font-size:11px;color:var(--gray-400);margin-left:4px;">${v}/5</span></div>`).join('') + '<div style="height:10px;"></div>';
    } else { rEl.style.display = 'none'; }
    const pW = document.getElementById('modalPhotoWrap'), pI = document.getElementById('modalPhotoImg');
    if (photo && photo.trim()) { pI.src = photo; pW.style.display = ''; }
    else { pW.style.display = 'none'; }
    document.getElementById('reviewModal').classList.add('open');
}
function closeModal() { document.getElementById('reviewModal').classList.remove('open'); }
document.getElementById('reviewModal').addEventListener('click', e => { if (e.target === document.getElementById('reviewModal')) closeModal(); });

/* ── Faculty modal ────────────────────────────────────────── */
let _cFid = null;
function openFacultyModal(fid, name, dept) {
    _cFid = fid;
    document.getElementById('facModalName').textContent = name;
    document.getElementById('facModalDept').textContent = dept || '—';
    document.getElementById('facModalContent').innerHTML = '<div style="text-align:center;padding:40px;color:var(--gray-400);">Loading...</div>';
    document.getElementById('facultyModal').classList.add('open');
    setFacTab('reviews');
    loadFacultyReviews(fid);
}
function setFacTab(tab) {
    document.querySelectorAll('.fac-tab-btn').forEach(t => {
        const a = t.dataset.tab === tab;
        t.style.background  = a ? 'var(--maroon)' : 'white';
        t.style.color       = a ? 'white' : 'var(--gray-600)';
        t.style.borderColor = a ? 'var(--maroon)' : 'var(--gray-200)';
    });
}
document.getElementById('facultyModal').addEventListener('click', function (e) { if (e.target === this) this.classList.remove('open'); });

function loadFacultyReviews(fid) {
    document.getElementById('facModalContent').innerHTML = '<div style="text-align:center;padding:40px;color:var(--gray-400);">Loading reviews...</div>';
    fetch('get_faculty_reviews.php?faculty_id=' + fid, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.error === 'session_expired') { window.location.href = 'index.php?timeout=1'; return; }
            if (data.error) { document.getElementById('facModalContent').innerHTML = '<div style="text-align:center;padding:30px;color:#ef4444;">' + esc(data.error) + '</div>'; return; }
            renderFacultyReviews(data);
        })
        .catch(() => { document.getElementById('facModalContent').innerHTML = '<div style="text-align:center;padding:30px;color:#ef4444;">Failed to load.</div>'; });
}

function loadFacultySummary(fid) {
    const el = document.getElementById('facModalContent');
    el.innerHTML = '<div style="text-align:center;padding:40px;color:var(--gray-400);">Generating AI report...</div>';
    fetch('get_faculty_reviews.php?faculty_id=' + fid + '&action=summary', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.text())
        .then(text => {
            try {
                const d = JSON.parse(text);
                if (d.error === 'session_expired') { window.location.href = 'index.php?timeout=1'; return; }
                if (d.error) { el.innerHTML = '<div style="padding:20px;color:#ef4444;">Error: ' + esc(d.error) + '</div>'; return; }
                el.innerHTML = `<div style="padding:4px 0 16px;"><div style="background:var(--gray-100);border-radius:10px;padding:18px;font-size:13px;line-height:1.85;color:var(--gray-700);white-space:pre-wrap;border:1px solid var(--gray-200);">${esc(d.summary)}</div></div>`;
            } catch (e) { el.innerHTML = '<div style="padding:20px;color:#ef4444;">Server error.</div>'; }
        })
        .catch(() => { el.innerHTML = '<div style="padding:20px;color:#ef4444;">Network error.</div>'; });
}

function deleteFacultyReview(rid, fid) {
    if (!confirm('Delete this review permanently?')) return;
    fetch('get_faculty_reviews.php?faculty_id=' + fid + '&action=delete&review_id=' + rid, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                const card = document.getElementById('fac-rev-' + rid);
                if (card) { card.style.transition = 'opacity 0.3s'; card.style.opacity = '0'; setTimeout(() => card.remove(), 300); }
                const c = document.getElementById('facTotalCount');
                if (c) c.textContent = Math.max(0, parseInt(c.textContent) - 1);
            } else alert('Failed to delete.');
        })
        .catch(() => alert('Network error.'));
}

function renderFacultyReviews(data) {
    const cats = [{ key: 'avg_teaching', label: 'Teaching Effectiveness' }, { key: 'avg_communication', label: 'Communication Skills' }, { key: 'avg_punctuality', label: 'Punctuality & Availability' }, { key: 'avg_fairness', label: 'Fairness in Grading' }, { key: 'avg_overall', label: 'Overall Satisfaction' }];
    const fid  = data.faculty_id;
    let h = `<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px;">
        <div style="background:var(--gray-100);border-radius:10px;padding:14px;text-align:center;border:1px solid var(--gray-200);"><strong style="display:block;font-size:22px;font-weight:700;color:var(--maroon);" id="facTotalCount">${data.total_approved}</strong><span style="font-size:10px;color:var(--gray-400);text-transform:uppercase;">Approved</span></div>
        <div style="background:var(--gray-100);border-radius:10px;padding:14px;text-align:center;border:1px solid var(--gray-200);"><strong style="font-size:22px;font-weight:700;color:var(--maroon);">${data.avg_overall || '—'}</strong><span style="font-size:10px;color:var(--gray-400);text-transform:uppercase;display:block;">Avg Overall</span></div>
        <div style="background:var(--gray-100);border-radius:10px;padding:14px;text-align:center;border:1px solid var(--gray-200);"><strong style="display:block;font-size:22px;font-weight:700;color:var(--maroon);">${data.positive_pct}%</strong><span style="font-size:10px;color:var(--gray-400);text-transform:uppercase;">Positive</span></div>
    </div>`;
    if (data.total_approved > 0) {
        h += '<div style="margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid var(--gray-100);"><div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--gray-400);margin-bottom:10px;">Rating Breakdown</div>';
        cats.forEach(c => {
            const val = parseFloat(data[c.key]) || 0, pct = (val / 5) * 100;
            h += `<div style="display:flex;align-items:center;padding:4px 0;font-size:13px;"><span style="min-width:180px;color:var(--gray-600);flex-shrink:0;">${c.label}</span><div style="flex:1;height:6px;background:var(--gray-200);border-radius:4px;margin:0 12px;overflow:hidden;"><div style="height:6px;border-radius:4px;background:#f59e0b;width:${pct}%;transition:width 0.6s;"></div></div><span style="min-width:45px;text-align:right;font-weight:600;font-size:12px;color:var(--gray-800);">${val ? val.toFixed(1) + '/5' : 'N/A'}</span></div>`;
        });
        h += '</div>';
    }
    h += '<div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--gray-400);margin-bottom:8px;">Approved Reviews</div>';
    if (!data.reviews || data.reviews.length === 0) {
        h += '<div style="text-align:center;padding:24px;color:var(--gray-400);">No approved reviews yet.</div>';
    } else {
        data.reviews.forEach(rev => {
            const sc = rev.sentiment === 'positive' ? 'badge-positive' : (rev.sentiment === 'negative' ? 'badge-negative' : 'badge-neutral');
            const sl = rev.sentiment ? rev.sentiment.charAt(0).toUpperCase() + rev.sentiment.slice(1) : 'Neutral';
            const rd = JSON.stringify({ text: rev.review_text, sentiment: rev.sentiment, date: rev.created_at, rt: rev.rating_teaching, rc: rev.rating_communication, rp: rev.rating_punctuality, rf: rev.rating_fairness, ro: rev.rating_overall, photos: rev.photos || [] });
            h += `<div id="fac-rev-${rev.id}" style="padding:8px 0 10px;border-bottom:1px solid var(--gray-100);">
                <div style="display:flex;gap:8px;align-items:flex-start;">
                    <div class="fac-rev-card" style="flex:1;" onclick='openRevDetail(${rd})'>
                        <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;flex-wrap:wrap;">
                            <span style="font-size:12px;font-weight:600;color:var(--gray-700);">Anonymous Reviewer</span>
                            <span class="badge ${sc}" style="font-size:10px;">${sl}</span>
                            <span style="font-size:10px;color:var(--gray-400);margin-left:auto;">${esc(rev.created_at)}</span>
                        </div>
                        ${rev.rating_overall ? `<div style="margin-bottom:3px;">${starsHtml(rev.rating_overall, 12)}</div>` : ''}
                        <div style="font-size:12px;color:var(--gray-600);line-height:1.5;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">${esc(rev.review_text)}</div>
                    </div>
                    <button type="button" onclick="deleteFacultyReview(${rev.id},${fid})" style="flex-shrink:0;background:none;border:1px solid var(--gray-200);border-radius:6px;padding:5px 8px;cursor:pointer;color:var(--gray-400);font-size:11px;display:flex;align-items:center;gap:3px;transition:all 0.18s;" onmouseover="this.style.borderColor='#ef4444';this.style.color='#ef4444';" onmouseout="this.style.borderColor='var(--gray-200)';this.style.color='var(--gray-400)';">
                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                    </button>
                </div>
            </div>`;
        });
    }
    document.getElementById('facModalContent').innerHTML = h;
}

/* ── Add/Edit faculty helpers ─────────────────────────────── */
function previewFacultyPhoto(inp, pid) { if (inp.files && inp.files[0]) { const r = new FileReader(); r.onload = e => document.getElementById(pid).src = e.target.result; r.readAsDataURL(inp.files[0]); } }
function afDeptChange(sel) { const h = document.getElementById('afDept'), nw = document.getElementById('afNewDeptWrap'), ni = document.getElementById('afNewDeptInput'); if (sel.value === '__new__') { nw.style.display = ''; ni.value = ''; h.value = ''; ni.focus(); sel.value = ''; } else { nw.style.display = 'none'; h.value = sel.value; if (ni) ni.value = ''; } }
function afCancelNewDept() { document.getElementById('afNewDeptWrap').style.display = 'none'; document.getElementById('afNewDeptInput').value = ''; document.getElementById('afDept').value = ''; const s = document.getElementById('afDeptSelect'); if (s) s.value = ''; }
function closeAddFacultyModal() { document.getElementById('addFacultyModal').classList.remove('open'); document.getElementById('afName').value = ''; document.getElementById('afDept').value = ''; const s = document.getElementById('afDeptSelect'); if (s) s.value = ''; const ni = document.getElementById('afNewDeptInput'); if (ni) ni.value = ''; const nw = document.getElementById('afNewDeptWrap'); if (nw) nw.style.display = 'none'; document.getElementById('afPhoto').value = ''; document.getElementById('afPhotoPreview').src = 'https://ui-avatars.com/api/?name=Faculty&background=8B0000&color=fff&size=64'; document.getElementById('afAlert').style.display = 'none'; }
function validateAddFaculty() { const name = document.getElementById('afName').value.trim(), nw = document.getElementById('afNewDeptWrap'), ni = document.getElementById('afNewDeptInput'); if (nw && nw.style.display !== 'none' && ni && ni.value.trim()) document.getElementById('afDept').value = ni.value.trim(); const dept = document.getElementById('afDept').value.trim(); if (!name || !dept) { document.getElementById('afAlertText').textContent = !name ? 'Faculty name is required.' : 'Department is required.'; document.getElementById('afAlert').style.display = 'flex'; return false; } document.getElementById('afAlert').style.display = 'none'; return true; }
function openEditFacultyModal(id, name, dept, photo) { document.getElementById('efFacultyId').value = id; document.getElementById('efName').value = name; document.getElementById('efDept').value = dept; document.getElementById('efPhotoPreview').src = photo || ('https://ui-avatars.com/api/?name=' + encodeURIComponent(name) + '&background=8B0000&color=fff&size=64'); document.getElementById('efAlert').style.display = 'none'; document.getElementById('editFacultyModal').classList.add('open'); }
function closeEditFacultyModal() { document.getElementById('editFacultyModal').classList.remove('open'); document.getElementById('efAlert').style.display = 'none'; }
function validateEditFaculty() { const name = document.getElementById('efName').value.trim(), dept = document.getElementById('efDept').value.trim(); if (!name || !dept) { document.getElementById('efAlertText').textContent = !name ? 'Faculty name is required.' : 'Department is required.'; document.getElementById('efAlert').style.display = 'flex'; return false; } document.getElementById('efAlert').style.display = 'none'; return true; }
document.getElementById('addFacultyModal').addEventListener('click', e => { if (e.target === document.getElementById('addFacultyModal')) closeAddFacultyModal(); });
document.getElementById('editFacultyModal').addEventListener('click', e => { if (e.target === document.getElementById('editFacultyModal')) closeEditFacultyModal(); });

/* ── Chart ────────────────────────────────────────────────── */
let weeklyChart = null;
function switchChart(mode) {
    document.getElementById('chartBtnReviews').className = mode === 'reviews' ? 'btn btn-maroon' : 'btn btn-outline';
    document.getElementById('chartBtnUsers').className   = mode === 'users'   ? 'btn btn-maroon' : 'btn btn-outline';
    ['chartBtnReviews', 'chartBtnUsers'].forEach(id => { document.getElementById(id).style.fontSize = '10px'; document.getElementById(id).style.padding = '3px 10px'; });
    renderChart(mode);
}
function renderChart(mode) {
    if (weeklyChart) weeklyChart.destroy();
    const ctx = document.getElementById('weeklyChart').getContext('2d');
    const datasets = mode === 'reviews' ? [
        { label: 'Created',  data: weeklyData.map(d => d.reviews),  backgroundColor: 'rgba(249,115,22,0.7)',  borderRadius: 4 },
        { label: 'Approved', data: weeklyData.map(d => d.approved), backgroundColor: 'rgba(16,185,129,0.7)',  borderRadius: 4 },
        { label: 'Rejected', data: weeklyData.map(d => d.rejected), backgroundColor: 'rgba(239,68,68,0.7)',   borderRadius: 4 }
    ] : [
        { label: 'New Users', data: weeklyData.map(d => d.users), backgroundColor: 'rgba(99,102,241,0.7)', borderRadius: 4 }
    ];
    weeklyChart = new Chart(ctx, { type: 'bar', data: { labels: weeklyData.map(d => d.label), datasets }, options: { responsive: true, plugins: { legend: { position: 'top', labels: { font: { size: 11 }, boxWidth: 11 } } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 11 } } }, y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 11 } }, grid: { color: '#f3f4f6' } } } } });
}

/* ── AI summary ───────────────────────────────────────────── */
function generateFacultySummary() {
    const btn = document.getElementById('summaryBtn'), el = document.getElementById('aiSummaryText');
    const month = document.getElementById('summaryMonth').value, year = document.getElementById('summaryYear').value;
    btn.disabled = true; btn.innerHTML = 'Generating...';
    el.innerHTML = '<span style="color:var(--gray-400);">Analyzing data...</span>';
    fetch('faculty_summary.php?month=' + month + '&year=' + year, { credentials: 'include', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.text())
        .then(text => {
            try {
                const d = JSON.parse(text);
                if (d.error === 'session_expired') { window.location.href = 'index.php?timeout=1'; return; }
                if (d.error) { el.innerHTML = '<span style="color:#ef4444;">Error: ' + esc(d.error) + '</span>'; }
                else { el.style.whiteSpace = 'pre-wrap'; el.textContent = d.summary || 'No summary available.'; }
            } catch (e) { el.innerHTML = '<span style="color:#ef4444;">Server error. Please try again.</span>'; }
            btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg> Regenerate';
            btn.disabled = false;
        })
        .catch(err => { el.innerHTML = '<span style="color:#ef4444;">Network error.</span>'; btn.innerHTML = 'Generate'; btn.disabled = false; });
}