/* ============================================================
   profile.css — Profile page styles (Modern Redesign)
   Used by: profile.php
   ============================================================ */

/* ── Page layout ──────────────────────────────────────────── */
.profile-page-wrap {
    max-width: 1100px;
    margin: 0 auto;
}

.page-header         { margin-bottom: 28px; }
.page-header h1      { font-family: 'Playfair Display', serif; font-size: 26px; color: var(--gray-800); }
.page-header p       { color: var(--gray-400); font-size: 14px; margin-top: 3px; }

/* ── Hero card (full-width banner) ────────────────────────── */
.profile-hero {
    background: linear-gradient(135deg, var(--maroon) 0%, #5a0000 100%);
    border-radius: var(--radius);
    padding: 36px 40px;
    display: flex;
    align-items: center;
    gap: 32px;
    margin-bottom: 24px;
    box-shadow: 0 8px 32px rgba(139,0,0,0.25);
    position: relative;
    overflow: hidden;
}
.profile-hero::before {
    content: '';
    position: absolute;
    top: -60px; right: -60px;
    width: 240px; height: 240px;
    border-radius: 50%;
    background: rgba(255,255,255,0.05);
    pointer-events: none;
}
.profile-hero::after {
    content: '';
    position: absolute;
    bottom: -80px; right: 120px;
    width: 200px; height: 200px;
    border-radius: 50%;
    background: rgba(255,255,255,0.04);
    pointer-events: none;
}

.hero-avatar-wrap { position: relative; flex-shrink: 0; }
.hero-avatar-wrap img {
    width: 110px; height: 110px; border-radius: 50%;
    object-fit: cover;
    border: 4px solid rgba(255,255,255,0.3);
    display: block;
}
.hero-avatar-edit {
    position: absolute; bottom: 4px; right: 4px;
    width: 32px; height: 32px; border-radius: 50%;
    background: white; border: 2px solid white;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all 0.2s;
    box-shadow: 0 2px 8px rgba(0,0,0,0.18);
    color: var(--maroon);
}
.hero-avatar-edit:hover { background: var(--maroon-pale); transform: scale(1.1); }
#picInput { display: none; }

.hero-info { flex: 1; min-width: 0; }
.hero-name { font-family: 'Playfair Display', serif; font-size: 26px; color: white; font-weight: 600; margin-bottom: 4px; }
.hero-username { font-size: 14px; color: rgba(255,255,255,0.65); margin-bottom: 18px; }
.avatar-preview-note { font-size: 12px; color: rgba(255,255,255,0.6); margin-top: 4px; }

.hero-stats { display: flex; gap: 28px; }
.hero-stat { text-align: center; }
.hero-stat strong { display: block; font-size: 26px; font-weight: 700; color: white; line-height: 1; }
.hero-stat span   { font-size: 11px; color: rgba(255,255,255,0.6); text-transform: uppercase; letter-spacing: 0.8px; margin-top: 2px; display: block; }

.hero-meta { margin-left: auto; flex-shrink: 0; display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
.hero-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 20px;
    background: rgba(255,255,255,0.12); color: rgba(255,255,255,0.9);
    font-size: 12px; font-weight: 500;
    border: 1px solid rgba(255,255,255,0.2);
}
.hero-since { font-size: 12px; color: rgba(255,255,255,0.5); }

/* ── Two-column layout ────────────────────────────────────── */
.profile-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 22px;
    align-items: start;
}

/* ── Form cards ───────────────────────────────────────────── */
.form-card {
    background: white; border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
    overflow: hidden;
}
.form-card-header {
    padding: 20px 24px 16px;
    border-bottom: 1px solid var(--gray-100);
    display: flex; align-items: center; gap: 12px;
}
.form-card-icon {
    width: 38px; height: 38px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.form-card-icon.blue   { background: #dbeafe; color: #1d4ed8; }
.form-card-icon.maroon { background: var(--maroon-pale); color: var(--maroon); }
.form-card-icon.green  { background: #d1fae5; color: #059669; }
.form-card-title { font-size: 15px; font-weight: 600; color: var(--gray-800); }
.form-card-sub   { font-size: 12px; color: var(--gray-400); margin-top: 1px; }
.form-card-body  { padding: 22px 24px; }

.form-section-title {
    font-size: 11px; text-transform: uppercase; letter-spacing: 1px;
    color: var(--gray-400); font-weight: 600; margin-bottom: 14px;
}
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; font-size: 13px; font-weight: 500; color: var(--gray-600); margin-bottom: 6px; }
.form-group input {
    width: 100%; padding: 10px 14px;
    border: 1.5px solid var(--gray-200); border-radius: var(--radius-sm);
    font-family: 'DM Sans', sans-serif; font-size: 14px;
    color: var(--gray-800); outline: none; transition: border-color 0.2s, box-shadow 0.2s;
}
.form-group input:focus    { border-color: var(--maroon); box-shadow: 0 0 0 3px rgba(139,0,0,0.08); }
.form-group input:disabled { background: var(--gray-100); color: var(--gray-400); cursor: not-allowed; }
.form-hint { font-size: 12px; color: var(--gray-400); margin-top: 4px; }

.save-btn {
    display: inline-flex; align-items: center; gap: 8px;
    background: var(--maroon); color: white; border: none;
    padding: 11px 24px; border-radius: 20px; font-size: 14px; font-weight: 500;
    cursor: pointer; font-family: 'DM Sans', sans-serif; transition: background 0.2s, transform 0.15s;
}
.save-btn:hover { background: var(--maroon-light); transform: translateY(-1px); }

/* ── Account info card ─────────────────────────────────────── */
.info-list { display: flex; flex-direction: column; gap: 0; }
.info-row {
    display: flex; align-items: center; padding: 13px 0;
    border-bottom: 1px solid var(--gray-100);
}
.info-row:last-child { border-bottom: none; }
.info-row-icon { width: 34px; height: 34px; border-radius: 8px; background: var(--gray-100); display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-right: 12px; color: var(--gray-600); }
.info-row-label { font-size: 12px; color: var(--gray-400); margin-bottom: 1px; }
.info-row-value { font-size: 14px; font-weight: 500; color: var(--gray-800); }

/* ── Recent activity on profile ───────────────────────────── */
.activity-item-p {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 12px 0; border-bottom: 1px solid var(--gray-100);
}
.activity-item-p:last-child { border-bottom: none; }
.activity-dot-p {
    width: 34px; height: 34px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.activity-title-p { font-size: 13px; font-weight: 500; color: var(--gray-800); margin-bottom: 2px; }
.activity-sub-p   { font-size: 12px; color: var(--gray-400); }