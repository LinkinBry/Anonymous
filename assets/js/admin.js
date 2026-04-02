/* ============================================================
   admin.js — Admin dashboard interactions
   Used by: admin_dashboard.php
   ============================================================ */

/* ── Escape helper ────────────────────────────────────────── */
function esc(s) {
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── SVG ClipPath star renderer ───────────────────────────── */
let _starUid = 0;
function starsHtml(val, size) {
    size = size || 15;
    if (!val || parseFloat(val) <= 0) return '<span style="color:var(--gray-400);font-size:12px;">—</span>';
    const pct  = Math.min(100, Math.max(0, (parseFloat(val) / 5) * 100));
    const gap  = 2, w = size * 5 + gap * 4;
    const base = Math.round(size * 0.85);
    const uid  = 'sr' + (++_starUid);
    const clipW = (pct / 100 * w).toFixed(2);
    let empty = '', filled = '';
    for (let i = 0; i < 5; i++) {
        const x = i * (size + gap);
        empty  += `<text x="${x}" y="${base}" font-size="${size}" fill="#d1d5db">★</text>`;
        filled += `<text x="${x}" y="${base}" font-size="${size}" fill="#f59e0b">★</text>`;
    }
    return `<span class="star-svg-wrap"><svg width="${w}" height="${size}" viewBox="0 0 ${w} ${size}" xmlns="http://www.w3.org/2000/svg" style="display:block;overflow:hidden;"><defs><clipPath id="${uid}"><rect x="0" y="0" width="${clipW}" height="${size}"/></clipPath></defs>${empty}<g clip-path="url(#${uid})">${filled}</g></svg></span>`;
}

/* ── User modal ───────────────────────────────────────────── */
let _currentUserId = 0;
function openUserModal(id, fullname, username, email, avatar) {
    document.getElementById('userModalAvatar').src = avatar;
    document.getElementById('userModalName').textContent     = fullname;
    document.getElementById('userModalUsername').textContent = '@' + username;
    document.getElementById('userModalEmail').textContent    = email;
    _currentUserId = id;
    document.getElementById('userModal').classList.add('open');
    loadUserReviews(id);
}
function confirmDeleteUser(id, fullname) {
    document.getElementById('deleteUserIdInput').value    = id;
    document.getElementById('deleteUserName').textContent = fullname;
    document.getElementById('deleteUserModal').classList.add('open');
}
document.getElementById('userModal').addEventListener('click', e => {
    if (e.target === document.getElementById('userModal')) document.getElementById('userModal').classList.remove('open');
});
document.getElementById('deleteUserModal').addEventListener('click', e => {
    if (e.target === document.getElementById('deleteUserModal')) document.getElementById('deleteUserModal').classList.remove('open');
});

function loadUserReviews(userId, page = 1) {
    const list = document.getElementById('userReviewsList');
    list.innerHTML = '<div style="color:var(--gray-400);font-size:12px;padding:8px 0;">Loading...</div>';
    fetch('get_user_reviews.php?user_id=' + userId + '&page=' + page, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
        if (!data.reviews || data.reviews.length === 0) {
            list.innerHTML = '<div style="color:var(--gray-400);font-size:13px;padding:8px 0;">No reviews made yet.</div>';
            return;
        }
        let html = '';
        data.reviews.forEach(rev => {
            const sc = rev.sentiment === 'positive' ? 'badge-positive' : (rev.sentiment === 'negative' ? 'badge-negative' : 'badge-neutral');
            const sl = rev.sentiment ? rev.sentiment.charAt(0).toUpperCase() + rev.sentiment.slice(1) : 'Neutral';
            const stBg = rev.status === 'approved' ? '#d1fae5' : rev.status === 'rejected' ? '#fee2e2' : '#fef3c7';
            const stC  = rev.status === 'approved' ? '#065f46' : rev.status === 'rejected' ? '#991b1b' : '#92400e';
            const rd   = JSON.stringify({ text: rev.review_text, sentiment: rev.sentiment, date: rev.created_at, rt: rev.rating_teaching, rc: rev.rating_communication, rp: rev.rating_punctuality, rf: rev.rating_fairness, ro: rev.rating_overall, photos: rev.photos || [] });
            html += `<div style="padding:8px 0 10px;border-bottom:1px solid var(--gray-100);">
                <div style="display:flex;gap:8px;align-items:flex-start;">
                    <div class="fac-rev-card" style="flex:1;" onclick='openRevDetail(${rd})'>
                        <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;flex-wrap:wrap;">
                            <span style="font-size:12px;font-weight:600;color:var(--gray-700);">${esc(rev.faculty_name)}</span>
                            <span class="badge ${sc}" style="font-size:10px;">${sl}</span>
                            <span style="font-size:10px;padding:1px 7px;border-radius:20px;font-weight:600;background:${stBg};color:${stC};">${rev.status.charAt(0).toUpperCase() + rev.status.slice(1)}</span>
                            ${rev.photos && rev.photos.length ? '<svg width="11" height="11" fill="none" stroke="#9ca3af" stroke-width="2" viewBox="0 0 24 24"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>' + (rev.photos.length > 1 ? `<span style="font-size:10px;color:#9ca3af;">${rev.photos.length}</span>` : '') : ''}
                            <span style="font-size:10px;color:var(--gray-400);margin-left:auto;">${rev.created_at}</span>
                        </div>
                        ${rev.rating_overall ? `<div style="margin-bottom:4px;">${starsHtml(rev.rating_overall, 12)}</div>` : ''}
                        <div style="font-size:12px;color:var(--gray-600);line-height:1.5;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">${esc(rev.review_text)}</div>
                    </div>
                    <button onclick="deleteUserReview(${rev.id},${userId},${page})" style="flex-shrink:0;background:none;border:1px solid var(--gray-200);border-radius:6px;padding:5px 8px;cursor:pointer;color:var(--gray-400);font-size:11px;display:flex;align-items:center;gap:3px;transition:all 0.18s;" onmouseover="this.style.borderColor='#ef4444';this.style.color='#ef4444';" onmouseout="this.style.borderColor='var(--gray-200)';this.style.color='var(--gray-400)';"><svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg> Delete</button>
                </div>
            </div>`;
        });
        if (data.total_pages > 1) {
            html += '<div style="display:flex;align-items:center;justify-content:flex-end;gap:5px;padding-top:10px;flex-wrap:wrap;">';
            html += `<span style="font-size:11px;color:var(--gray-400);flex:1;">${(page-1)*5+1}–${Math.min(page*5,data.total)} of ${data.total}</span>`;
            if (page > 1) html += `<button onclick="loadUserReviews(${userId},${page-1})" class="btn btn-outline" style="padding:3px 9px;font-size:11px;">←</button>`;
            for (let i = 1; i <= data.total_pages; i++) html += `<button onclick="loadUserReviews(${userId},${i})" class="btn ${i===page?'btn-maroon':'btn-outline'}" style="padding:3px 9px;font-size:11px;">${i}</button>`;
            if (page < data.total_pages) html += `<button onclick="loadUserReviews(${userId},${page+1})" class="btn btn-outline" style="padding:3px 9px;font-size:11px;">→</button>`;
            html += '</div>';
        }
        list.innerHTML = html;
    })
    .catch(() => { list.innerHTML = '<div style="color:#ef4444;font-size:12px;">Failed to load.</div>'; });
}

function deleteUserReview(reviewId, userId, page) {
    if (!confirm('Delete this review permanently?')) return;
    fetch('get_faculty_reviews.php?faculty_id=0&action=delete_any&review_id=' + reviewId, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(d => { if (d.success) loadUserReviews(userId, page); else alert('Failed to delete review.'); })
    .catch(() => alert('Network error.'));
}

/* ── Review detail popup ──────────────────────────────────── */
function openRevDetail(revData) {
    let m = document.getElementById('facRevDetail');
    if (!m) {
        m = document.createElement('div');
        m.id = 'facRevDetail';
        m.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:2000;align-items:center;justify-content:center;backdrop-filter:blur(2px);';
        m.innerHTML = `<div style="background:white;border-radius:14px;width:100%;max-width:500px;max-height:82vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.18);animation:slideUp 0.22s ease;">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--gray-100);position:sticky;top:0;background:white;z-index:2;">
                <div id="frd-title" style="font-size:15px;font-weight:600;color:var(--gray-800);"></div>
                <button onclick="document.getElementById('facRevDetail').style.display='none'" style="width:28px;height:28px;border-radius:50%;background:var(--gray-100);border:none;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;color:var(--gray-600);">&times;</button>
            </div>
            <div id="frd-body" style="padding:20px;"></div>
        </div>`;
        m.addEventListener('click', function(e) { if (e.target === m) m.style.display = 'none'; });
        document.body.appendChild(m);
    }
    const cats = [['Teaching Effectiveness', revData.rt], ['Communication Skills', revData.rc], ['Punctuality & Availability', revData.rp], ['Fairness in Grading', revData.rf], ['Overall Satisfaction', revData.ro]];
    const sc = revData.sentiment === 'positive' ? 'badge-positive' : (revData.sentiment === 'negative' ? 'badge-negative' : 'badge-neutral');
    const sl = revData.sentiment ? revData.sentiment.charAt(0).toUpperCase() + revData.sentiment.slice(1) : 'Neutral';
    document.getElementById('frd-title').innerHTML = `Detailed Review <span class="badge ${sc}" style="margin-left:8px;font-size:11px;">${sl}</span>`;
    let body = `<div style="font-size:11px;color:var(--gray-400);margin-bottom:14px;">${esc(revData.date || '')}</div>`;
    body += '<div style="margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid var(--gray-100);"><div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--gray-400);margin-bottom:8px;">Ratings</div>';
    cats.forEach(([label, val]) => {
        body += `<div style="display:flex;align-items:center;padding:4px 0;font-size:13px;gap:10px;"><span style="min-width:170px;flex-shrink:0;color:var(--gray-600);">${label}</span>${starsHtml(val, 14)}<span style="font-size:11px;color:var(--gray-400);margin-left:4px;">${val || '—'}/5</span></div>`;
    });
    body += '</div>';
    const photos = Array.isArray(revData.photos) ? revData.photos.filter(p => p && p.trim() && p !== 'null') :
                   (revData.photo && revData.photo.trim() && revData.photo !== 'null' ? [revData.photo] : []);
    if (photos.length > 0) {
        body += `<div style="margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid var(--gray-100);"><div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--gray-400);margin-bottom:8px;">Photos (${photos.length})</div>`;
        if (photos.length === 1) {
            body += `<img src="${esc(photos[0])}" alt="Review photo" style="width:100%;max-height:240px;border-radius:10px;object-fit:cover;border:1px solid var(--gray-200);">`;
        } else {
            body += `<div style="display:grid;grid-template-columns:repeat(${Math.min(photos.length, 3)},1fr);gap:6px;">`;
            photos.forEach(p => {
                body += `<div style="border-radius:8px;overflow:hidden;aspect-ratio:1;border:1px solid var(--gray-200);cursor:pointer;" onclick="window.open('${esc(p)}','_blank')"><img src="${esc(p)}" style="width:100%;height:100%;object-fit:cover;display:block;"></div>`;
            });
            body += `</div><div style="font-size:10px;color:var(--gray-400);margin-top:4px;">Click any photo to view full size</div>`;
        }
        body += `</div>`;
    }
    body += `<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--gray-400);margin-bottom:8px;">Review</div><div style="font-size:13px;color:var(--gray-700);line-height:1.7;white-space:pre-wrap;">${esc(revData.text)}</div>`;
    document.getElementById('frd-body').innerHTML = body;
    m.style.display = 'flex';
}

/* ── Pending/approved review view modal ───────────────────── */
function openModal(text, faculty, user, rt, rc, rp, rf, ro, photo) {
    document.getElementById('modalBody').textContent     = text;
    document.getElementById('modalFaculty').textContent = faculty;
    document.getElementById('modalUser').textContent     = user;
    const cats = [['Teaching Effectiveness', rt], ['Communication Skills', rc], ['Punctuality & Availability', rp], ['Fairness in Grading', rf], ['Overall Satisfaction', ro]];
    const hasRatings  = cats.some(c => c[1] > 0);
    const ratingsEl   = document.getElementById('modalRatings');
    if (hasRatings) {
        ratingsEl.style.display = '';
        ratingsEl.innerHTML = cats.map(([label, val]) =>
            `<div style="display:flex;align-items:center;padding:5px 0;font-size:13px;gap:10px;">
                <span style="min-width:170px;flex-shrink:0;color:var(--gray-600);">${label}</span>
                ${starsHtml(val, 14)}
                <span style="font-size:11px;color:var(--gray-400);margin-left:4px;">${val}/5</span>
            </div>`
        ).join('') + '<div style="height:10px;"></div>';
    } else { ratingsEl.style.display = 'none'; }
    const photoWrap = document.getElementById('modalPhotoWrap');
    const photoImg  = document.getElementById('modalPhotoImg');
    if (photo && photo.trim()) { photoImg.src = photo; photoWrap.style.display = ''; }
    else { photoWrap.style.display = 'none'; }
    document.getElementById('reviewModal').classList.add('open');
}
function closeModal() { document.getElementById('reviewModal').classList.remove('open'); }
document.getElementById('reviewModal').addEventListener('click', e => { if (e.target === document.getElementById('reviewModal')) closeModal(); });

/* ── Faculty modal ────────────────────────────────────────── */
let _currentFacultyId = null;
function openFacultyModal(fid, name, dept) {
    _currentFacultyId = fid;
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
        t.style.background   = a ? 'var(--maroon)' : 'white';
        t.style.color        = a ? 'white' : 'var(--gray-600)';
        t.style.borderColor  = a ? 'var(--maroon)' : 'var(--gray-200)';
    });
}
document.getElementById('facultyModal').addEventListener('click', function(e) { if (e.target === this) this.classList.remove('open'); });

function loadFacultyReviews(fid) {
    document.getElementById('facModalContent').innerHTML = '<div style="text-align:center;padding:40px;color:var(--gray-400);">Loading reviews...</div>';
    fetch('get_faculty_reviews.php?faculty_id=' + fid, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => { if (!r.headers.get('content-type').includes('application/json')) throw new Error('Session expired.'); return r.json(); })
    .then(data => { if (data.error === 'session_expired') { window.location.href = 'index.php?timeout=1'; return; } if (data.error) { document.getElementById('facModalContent').innerHTML = '<div style="text-align:center;padding:30px;color:#ef4444;">' + esc(data.error) + '</div>'; return; } renderFacultyReviews(data); })
    .catch(() => { document.getElementById('facModalContent').innerHTML = '<div style="text-align:center;padding:30px;color:#ef4444;">Failed to load. Please try again.</div>'; });
}

function loadFacultySummary(fid) {
    const el = document.getElementById('facModalContent');
    el.innerHTML = '<div style="text-align:center;padding:40px;color:var(--gray-400);">Generating AI report...</div>';
    fetch('get_faculty_reviews.php?faculty_id=' + fid + '&action=summary', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.text()).then(text => {
        try {
            const d = JSON.parse(text);
            if (d.error === 'session_expired') { window.location.href = 'index.php?timeout=1'; return; }
            if (d.error) { el.innerHTML = '<div style="padding:20px;color:#ef4444;">Error: ' + esc(d.error) + '</div>'; return; }
            el.innerHTML = `<div style="padding:4px 0 16px;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;">
                    <div style="width:32px;height:32px;border-radius:50%;background:var(--maroon-pale);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg width="16" height="16" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg></div>
                    <div><div style="font-size:13px;font-weight:600;color:var(--gray-800);">Individual Faculty Report</div><div style="font-size:11px;color:var(--gray-400);">AI-generated · Powered by Groq</div></div>
                </div>
                <div style="background:var(--gray-100);border-radius:10px;padding:18px;font-size:13px;line-height:1.85;color:var(--gray-700);white-space:pre-wrap;border:1px solid var(--gray-200);">${esc(d.summary)}</div>
            </div>`;
        } catch(e) { el.innerHTML = '<div style="padding:20px;color:#ef4444;">Server error. Please try again.</div>'; }
    }).catch(() => { el.innerHTML = '<div style="padding:20px;color:#ef4444;">Network error. Please try again.</div>'; });
}

function deleteFacultyReview(rid, fid) {
    if (!confirm('Delete this review permanently?')) return;
    fetch('get_faculty_reviews.php?faculty_id=' + fid + '&action=delete&review_id=' + rid, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json()).then(d => {
        if (d.success) {
            const card = document.getElementById('fac-rev-' + rid);
            if (card) { card.style.transition = 'opacity 0.3s'; card.style.opacity = '0'; setTimeout(() => card.remove(), 300); }
            const c = document.getElementById('facTotalCount');
            if (c) c.textContent = Math.max(0, parseInt(c.textContent) - 1);
        } else alert('Failed to delete review.');
    }).catch(() => alert('Network error.'));
}

function renderFacultyReviews(data) {
    const cats = [{ key: 'avg_teaching', label: 'Teaching Effectiveness' }, { key: 'avg_communication', label: 'Communication Skills' }, { key: 'avg_punctuality', label: 'Punctuality & Availability' }, { key: 'avg_fairness', label: 'Fairness in Grading' }, { key: 'avg_overall', label: 'Overall Satisfaction' }];
    const fid  = data.faculty_id;
    let h = `<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px;">
        <div style="background:var(--gray-100);border-radius:10px;padding:14px;text-align:center;border:1px solid var(--gray-200);"><strong style="display:block;font-size:22px;font-weight:700;color:var(--maroon);" id="facTotalCount">${data.total_approved}</strong><span style="font-size:11px;color:var(--gray-400);text-transform:uppercase;">Approved Reviews</span></div>
        <div style="background:var(--gray-100);border-radius:10px;padding:14px;text-align:center;border:1px solid var(--gray-200);"><div style="display:flex;align-items:center;justify-content:center;gap:4px;margin-bottom:2px;"><strong style="font-size:22px;font-weight:700;color:var(--maroon);">${data.avg_overall || '—'}</strong>${data.avg_overall ? starsHtml(data.avg_overall, 12) : ''}</div><span style="font-size:11px;color:var(--gray-400);text-transform:uppercase;">Avg Overall</span></div>
        <div style="background:var(--gray-100);border-radius:10px;padding:14px;text-align:center;border:1px solid var(--gray-200);"><strong style="display:block;font-size:22px;font-weight:700;color:var(--maroon);">${data.positive_pct}%</strong><span style="font-size:11px;color:var(--gray-400);text-transform:uppercase;">Positive Sentiment</span></div>
    </div>`;
    if (data.total_approved > 0) {
        h += '<div style="margin-bottom:18px;padding-bottom:18px;border-bottom:1px solid var(--gray-100);"><div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--gray-400);margin-bottom:10px;">Rating Breakdown</div>';
        cats.forEach(c => {
            const val = parseFloat(data[c.key]) || 0;
            const pct = (val / 5) * 100;
            h += `<div style="display:flex;align-items:center;padding:5px 0;font-size:13px;"><span style="min-width:180px;color:var(--gray-600);flex-shrink:0;">${c.label}</span><div style="flex:1;height:7px;background:var(--gray-200);border-radius:4px;margin:0 12px;overflow:hidden;"><div style="height:7px;border-radius:4px;background:#f59e0b;width:${pct}%;transition:width 0.6s ease;"></div></div><span style="min-width:50px;text-align:right;font-weight:600;font-size:13px;color:var(--gray-800);">${val ? val.toFixed(1) + '/5' : 'N/A'}</span></div>`;
        });
        h += '</div>';
    }
    h += '<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--gray-400);margin-bottom:8px;">Approved Reviews <span style="font-weight:400;text-transform:none;font-size:11px;">(click to expand)</span></div>';
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
                            ${rev.photos && rev.photos.length ? '<svg width="11" height="11" fill="none" stroke="#9ca3af" stroke-width="2" viewBox="0 0 24 24"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>' + (rev.photos.length > 1 ? `<span style="font-size:10px;color:#9ca3af;">${rev.photos.length}</span>` : '') : ''}
                            <span style="font-size:10px;color:var(--gray-400);margin-left:auto;">${esc(rev.created_at)}</span>
                        </div>
                        ${rev.rating_overall ? `<div style="margin-bottom:4px;">${starsHtml(rev.rating_overall, 13)}</div>` : ''}
                        <div style="font-size:12px;color:var(--gray-600);line-height:1.5;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">${esc(rev.review_text)}</div>
                    </div>
                    <button onclick="deleteFacultyReview(${rev.id},${fid})" style="flex-shrink:0;background:none;border:1px solid var(--gray-200);border-radius:6px;padding:5px 8px;cursor:pointer;color:var(--gray-400);font-size:11px;display:flex;align-items:center;gap:3px;transition:all 0.18s;" onmouseover="this.style.borderColor='#ef4444';this.style.color='#ef4444';" onmouseout="this.style.borderColor='var(--gray-200)';this.style.color='var(--gray-400)';"><svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg> Delete</button>
                </div>
            </div>`;
        });
    }
    document.getElementById('facModalContent').innerHTML = h;
}

/* ── Add/Edit faculty modal ───────────────────────────────── */
function previewFacultyPhoto(input, previewId) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { document.getElementById(previewId).src = e.target.result; };
        reader.readAsDataURL(input.files[0]);
    }
}
function afDeptChange(sel) {
    const hidden   = document.getElementById('afDept');
    const newWrap  = document.getElementById('afNewDeptWrap');
    const newInput = document.getElementById('afNewDeptInput');
    if (sel.value === '__new__') {
        newWrap.style.display = ''; newInput.value = ''; hidden.value = '';
        newInput.focus(); sel.value = '';
    } else {
        newWrap.style.display = 'none'; hidden.value = sel.value;
        if (newInput) newInput.value = '';
    }
}
function afCancelNewDept() {
    document.getElementById('afNewDeptWrap').style.display = 'none';
    document.getElementById('afNewDeptInput').value = '';
    document.getElementById('afDept').value = '';
    const sel = document.getElementById('afDeptSelect');
    if (sel) sel.value = '';
}
function closeAddFacultyModal() {
    document.getElementById('addFacultyModal').classList.remove('open');
    document.getElementById('afName').value = '';
    document.getElementById('afDept').value = '';
    const sel = document.getElementById('afDeptSelect'); if (sel) sel.value = '';
    const ni  = document.getElementById('afNewDeptInput'); if (ni) ni.value = '';
    const nw  = document.getElementById('afNewDeptWrap'); if (nw) nw.style.display = 'none';
    document.getElementById('afPhoto').value = '';
    document.getElementById('afPhotoPreview').src = 'https://ui-avatars.com/api/?name=Faculty&background=8B0000&color=fff&size=64';
    document.getElementById('afAlert').style.display = 'none';
}
function validateAddFaculty() {
    const name    = document.getElementById('afName').value.trim();
    const newWrap = document.getElementById('afNewDeptWrap');
    const newInput= document.getElementById('afNewDeptInput');
    if (newWrap && newWrap.style.display !== 'none' && newInput && newInput.value.trim()) {
        document.getElementById('afDept').value = newInput.value.trim();
    }
    const dept = document.getElementById('afDept').value.trim();
    if (!name || !dept) {
        document.getElementById('afAlertText').textContent = !name ? 'Faculty name is required.' : 'Department is required.';
        document.getElementById('afAlert').style.display = 'flex';
        return false;
    }
    document.getElementById('afAlert').style.display = 'none';
    return true;
}
function openEditFacultyModal(id, name, dept, photo) {
    document.getElementById('efFacultyId').value = id;
    document.getElementById('efName').value      = name;
    document.getElementById('efDept').value      = dept;
    document.getElementById('efPhotoPreview').src = photo || ('https://ui-avatars.com/api/?name=' + encodeURIComponent(name) + '&background=8B0000&color=fff&size=64');
    document.getElementById('efAlert').style.display = 'none';
    document.getElementById('editFacultyModal').classList.add('open');
}
function closeEditFacultyModal() {
    document.getElementById('editFacultyModal').classList.remove('open');
    document.getElementById('efAlert').style.display = 'none';
}
function validateEditFaculty() {
    const name = document.getElementById('efName').value.trim();
    const dept = document.getElementById('efDept').value.trim();
    if (!name || !dept) {
        document.getElementById('efAlertText').textContent = !name ? 'Faculty name is required.' : 'Department is required.';
        document.getElementById('efAlert').style.display = 'flex';
        return false;
    }
    document.getElementById('efAlert').style.display = 'none';
    return true;
}
document.getElementById('addFacultyModal').addEventListener('click', function(e) { if (e.target === this) closeAddFacultyModal(); });
document.getElementById('editFacultyModal').addEventListener('click', function(e) { if (e.target === this) closeEditFacultyModal(); });

/* ── Bulk helpers ─────────────────────────────────────────── */
function toggleUserBulk() { const c = document.querySelectorAll('.user_checkbox:checked'); document.getElementById('bulk_actions').classList.toggle('show', c.length > 0); document.getElementById('selected_count').textContent = c.length + ' user' + (c.length !== 1 ? 's' : '') + ' selected'; }
function updateReviewBulk() { const c = document.querySelectorAll('.review_cb:checked'); document.getElementById('review_bulk_bar').classList.toggle('show', c.length > 0); document.getElementById('review_selected_count').textContent = c.length + ' review' + (c.length !== 1 ? 's' : '') + ' selected'; }
function updateApprovedBulk() { const c = document.querySelectorAll('.approved_cb:checked'); document.getElementById('approved_bulk_bar').classList.toggle('show', c.length > 0); document.getElementById('approved_selected_count').textContent = c.length + ' review' + (c.length !== 1 ? 's' : '') + ' selected'; }

/* ── Table filter + pagination ────────────────────────────── */
const PER_PAGE = 5;
const tableState = {};

function filterTable(tbodyId, searchId, filterId) {
    const tbody  = document.getElementById(tbodyId); if (!tbody) return;
    const search = searchId ? document.getElementById(searchId).value.toLowerCase() : '';
    const filter = filterId ? document.getElementById(filterId).value.toLowerCase() : '';
    [...tbody.querySelectorAll('tr:not(.empty-state-row):not(.no-results-row)')].forEach(row => {
        const text = row.textContent.toLowerCase();
        row.dataset.visible = ((!search || text.includes(search)) && (!filter || text.includes(filter))) ? 'true' : 'false';
    });
    tableState[tbodyId] = 1;
    renderTablePage(tbodyId);
}

function renderTablePage(tbodyId) {
    const tbody    = document.getElementById(tbodyId); if (!tbody) return;
    const rows     = [...tbody.querySelectorAll('tr:not(.empty-state-row):not(.no-results-row)')];
    const visible  = rows.filter(r => r.dataset.visible !== 'false');
    const page     = tableState[tbodyId] || 1;
    const total    = visible.length;
    const totalPages = Math.ceil(total / PER_PAGE);
    const start    = (page - 1) * PER_PAGE;
    const end      = start + PER_PAGE;

    rows.forEach(r => r.style.display = 'none');
    visible.slice(start, end).forEach(r => r.style.display = '');

    ['select_all_reviews','select_all_approved','select_all_users'].forEach(id => { const el = document.getElementById(id); if (el) el.checked = false; });

    let noRes = tbody.querySelector('.no-results-row');
    if (total === 0) {
        if (!noRes) { noRes = document.createElement('tr'); noRes.className = 'no-results-row'; const cols = tbody.closest('table').querySelector('thead tr').children.length; noRes.innerHTML = `<td colspan="${cols}" style="text-align:center;padding:24px;color:var(--gray-400);font-size:13px;">No results found.</td>`; tbody.appendChild(noRes); }
        noRes.style.display = '';
    } else { if (noRes) noRes.style.display = 'none'; }

    const pagId = tbodyId.replace('-tbody', '-pag');
    let pag = document.getElementById(pagId);
    if (!pag) { pag = document.createElement('div'); pag.id = pagId; pag.style.cssText = 'display:flex;align-items:center;justify-content:flex-end;gap:6px;padding:12px 20px;border-top:1px solid var(--gray-100);flex-wrap:wrap;'; tbody.closest('table').after(pag); }
    pag.innerHTML = '';
    if (totalPages <= 1 && total > 0) return; if (total === 0) return;

    const info = document.createElement('span'); info.style.cssText = 'font-size:12px;color:var(--gray-400);margin-right:6px;flex:1;'; info.textContent = `${start+1}–${Math.min(end,total)} of ${total}`; pag.appendChild(info);
    const prev = document.createElement('button'); prev.className = 'btn btn-outline'; prev.style.padding = '4px 10px'; prev.innerHTML = '←'; prev.disabled = page === 1; prev.onclick = () => { tableState[tbodyId] = page - 1; renderTablePage(tbodyId); }; pag.appendChild(prev);
    for (let i = 1; i <= totalPages; i++) { const btn = document.createElement('button'); btn.className = 'btn ' + (i === page ? 'btn-maroon' : 'btn-outline'); btn.style.padding = '4px 10px'; btn.textContent = i; btn.onclick = () => { tableState[tbodyId] = i; renderTablePage(tbodyId); }; pag.appendChild(btn); }
    const next = document.createElement('button'); next.className = 'btn btn-outline'; next.style.padding = '4px 10px'; next.innerHTML = '→'; next.disabled = page === totalPages; next.onclick = () => { tableState[tbodyId] = page + 1; renderTablePage(tbodyId); }; pag.appendChild(next);
}

/* ── Reports chart ────────────────────────────────────────── */
let weeklyChart = null;

function switchChart(mode) {
    document.getElementById('chartBtnReviews').className = mode === 'reviews' ? 'btn btn-maroon' : 'btn btn-outline';
    document.getElementById('chartBtnUsers').className   = mode === 'users'   ? 'btn btn-maroon' : 'btn btn-outline';
    ['chartBtnReviews','chartBtnUsers'].forEach(id => { document.getElementById(id).style.fontSize = '11px'; document.getElementById(id).style.padding = '4px 12px'; });
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
    weeklyChart = new Chart(ctx, {
        type: 'bar',
        data: { labels: weeklyData.map(d => d.label), datasets },
        options: { responsive: true, plugins: { legend: { position: 'top', labels: { font: { size: 11 }, boxWidth: 12 } } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 11 } } }, y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 11 } }, grid: { color: '#f3f4f6' } } } }
    });
}

/* ── AI Monthly summary ───────────────────────────────────── */
function generateFacultySummary() {
    const btn  = document.getElementById('summaryBtn');
    const el   = document.getElementById('aiSummaryText');
    const month = document.getElementById('summaryMonth').value;
    const year  = document.getElementById('summaryYear').value;
    btn.disabled = true; btn.innerHTML = 'Generating...';
    el.innerHTML = '<span style="color:var(--gray-400);">Analyzing...</span>';
    fetch('faculty_summary.php?month=' + month + '&year=' + year, { credentials: 'include', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.text()).then(text => {
        try {
            const d = JSON.parse(text);
            if (d.error === 'session_expired') { window.location.href = 'index.php?timeout=1'; return; }
            if (d.error) { el.innerHTML = '<span style="color:#ef4444;">Error: ' + esc(d.error) + '</span>'; }
            else { el.style.whiteSpace = 'pre-wrap'; el.textContent = d.summary || 'No summary.'; }
        } catch(e) { el.innerHTML = '<span style="color:#ef4444;">Server error — check faculty_summary.php is deployed.</span>'; }
        btn.innerHTML = '<svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg> Regenerate';
        btn.disabled = false;
    }).catch(err => { el.innerHTML = '<span style="color:#ef4444;">Network error: ' + esc(err.message) + '</span>'; btn.innerHTML = 'Generate'; btn.disabled = false; });
}

/* ── DOMContentLoaded init ────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    // Init Chart.js
    if (document.getElementById('weeklyChart')) {
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js';
        s.onload = () => renderChart('reviews');
        document.head.appendChild(s);
    }

    // Mark all rows visible, init table pagination
    document.querySelectorAll('.admin-table tbody tr:not(.empty-state-row)').forEach(r => r.dataset.visible = 'true');
    ['users-tbody','faculties-tbody','pending-tbody','approved-tbody'].forEach(id => { tableState[id] = 1; renderTablePage(id); });

    // Sync pending badge count
    const realPending = document.querySelectorAll('#pending-tbody tr:not(.empty-state-row)').length;
    const nb = document.getElementById('pendingNavBadge'); if (nb) { if (realPending === 0) nb.style.display = 'none'; else nb.textContent = realPending; }
    const sb = document.getElementById('pendingSecBadge'); if (sb) { if (realPending === 0) sb.style.display = 'none'; else sb.textContent = realPending; }
    const sv = document.querySelector('.stat-card.s-pending .stat-value'); if (sv) sv.textContent = realPending;

    // Edit users toggle
    const editBtn = document.getElementById('edit_users_btn');
    if (editBtn) {
        editBtn.addEventListener('click', function() {
            const th = document.getElementById('select_all_th');
            const tds = document.querySelectorAll('.checkbox_td');
            const showing = th.style.display !== 'none';
            th.style.display = showing ? 'none' : 'table-cell';
            tds.forEach(td => td.style.display = showing ? 'none' : 'table-cell');
            this.innerHTML = showing
                ? '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Edit'
                : '✕ Cancel';
            if (showing) { document.querySelectorAll('.user_checkbox').forEach(c => c.checked = false); document.getElementById('select_all_users').checked = false; toggleUserBulk(); }
        });
        document.getElementById('select_all_users').addEventListener('change', function() { document.querySelectorAll('.user_checkbox').forEach(c => c.checked = this.checked); toggleUserBulk(); });
        document.querySelectorAll('.user_checkbox').forEach(c => c.addEventListener('change', toggleUserBulk));
    }

    // Sidebar smooth scroll
    document.querySelectorAll('.sidebar a[href^="#"]').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const el = document.getElementById(this.getAttribute('href').slice(1));
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    // Hash scroll on load
    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
    const hash = window.location.hash;
    if (hash) { const el = document.getElementById(hash.slice(1)); if (el) setTimeout(() => el.scrollIntoView({ behavior: 'auto', block: 'start' }), 60); }
});