<?php
/**
 * Photo Review — multi-photographer conflict resolution dashboard.
 *
 * Lists every candidate photo flagged `decision_pending=1` (different
 * operators uploaded photos for the same member). Admin picks Accept
 * (set as current) or Reject (archive) for each. Designed to feel like
 * the on-brand v2 member profile (maroon/gold).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/access_control.php';
ensureAdminAccess();

$title = 'Photo Review';
include __DIR__ . '/includes/header.php';
?>
<style>
:root {
  --pr-maroon: #600000;
  --pr-gold: #F0C000;
  --pr-ink: #1e1b19;
  --pr-muted: #6b6360;
  --pr-surface: #fff;
  --pr-surface-2: #faf6f2;
  --pr-border: rgba(96,0,0,.12);
}
.photo-review-wrap { max-width: 1200px; margin: 24px auto; padding: 0 20px; }
.photo-review-hero {
  background: linear-gradient(135deg, var(--pr-maroon) 0%, #7b1414 100%);
  color: #fff; border-radius: 14px; padding: 24px 28px;
  display: flex; justify-content: space-between; align-items: center;
  box-shadow: 0 8px 24px rgba(96,0,0,.15); margin-bottom: 20px;
}
.photo-review-hero h1 { margin: 0; font-size: 22px; }
.photo-review-hero p { margin: 6px 0 0; opacity: .85; font-size: 13px; }
.photo-review-empty {
  background: var(--pr-surface); border: 1px solid var(--pr-border);
  border-radius: 12px; padding: 60px 24px; text-align: center; color: var(--pr-muted);
}
.photo-card {
  background: var(--pr-surface); border: 1px solid var(--pr-border);
  border-radius: 12px; padding: 20px; margin-bottom: 16px;
  display: grid; grid-template-columns: 1fr 280px 280px 200px; gap: 20px;
  align-items: center;
}
.photo-card__member h3 { margin: 0 0 4px; color: var(--pr-ink); font-size: 17px; }
.photo-card__meta { font-size: 12px; color: var(--pr-muted); }
.photo-card__meta .code { background: var(--pr-gold); color: var(--pr-ink);
  padding: 1px 8px; border-radius: 10px; font-weight: 600; margin-right: 6px; }
.photo-thumb { background: #000; border-radius: 8px; overflow: hidden;
  aspect-ratio: 4/5; display: flex; align-items: center; justify-content: center; }
.photo-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.photo-thumb .photo-empty { color: rgba(255,255,255,.5); font-size: 12px; }
.photo-thumb-label {
  text-align: center; font-size: 11px; color: var(--pr-muted); margin-top: 4px;
  text-transform: uppercase; letter-spacing: .06em;
}
.photo-thumb.new  .photo-thumb-label { color: var(--pr-maroon); font-weight: 700; }
.photo-actions { display: flex; flex-direction: column; gap: 8px; }
.btn-pr { padding: 10px 14px; border-radius: 8px; font-weight: 600;
  font-size: 13px; border: 0; cursor: pointer; text-decoration: none;
  display: inline-block; text-align: center; }
.btn-pr.accept { background: var(--pr-maroon); color: #fff; }
.btn-pr.reject { background: #fff; color: var(--pr-maroon);
  border: 1px solid var(--pr-border); }
.btn-pr:hover { filter: brightness(.95); }
.photo-meta-row { font-size: 11px; color: var(--pr-muted); margin-top: 6px; }
.photo-badge-current { background: rgba(240,192,0,.18); color: #7a5a00;
  font-size: 10px; padding: 2px 8px; border-radius: 8px; font-weight: 700;
  display: inline-block; margin-top: 4px; }
.photo-badge-new { background: rgba(96,0,0,.08); color: var(--pr-maroon);
  font-size: 10px; padding: 2px 8px; border-radius: 8px; font-weight: 700;
  display: inline-block; margin-top: 4px; }
@media (max-width: 900px) {
  .photo-card { grid-template-columns: 1fr; }
  .photo-thumb { max-width: 220px; }
}
</style>

<div class="photo-review-wrap">
  <div class="photo-review-hero">
    <div>
      <h1>📸 Photo Review</h1>
      <p>Resolve photos where more than one photographer captured a member. Pick the better shot.</p>
    </div>
    <button class="btn-pr accept" onclick="location.reload()" style="background:var(--pr-gold);color:var(--pr-ink)">Refresh</button>
  </div>

  <div id="pr-list">Loading…</div>
</div>

<script>
async function loadList() {
  try {
    const r = await fetch('/admin/api_photo_candidates.php?action=list-pending');
    const j = await r.json();
    if (j.status !== 'success') {
      document.getElementById('pr-list').innerHTML =
        '<div class="photo-review-empty">Error loading pending reviews.</div>';
      return;
    }
    render(j.items || []);
  } catch (e) {
    document.getElementById('pr-list').innerHTML =
      '<div class="photo-review-empty">Could not reach the server.</div>';
  }
}

function humanBytes(n) {
  if (n < 1024) return n + ' B';
  if (n < 1024*1024) return (n/1024).toFixed(1) + ' KB';
  return (n/1024/1024).toFixed(2) + ' MB';
}

function render(items) {
  const root = document.getElementById('pr-list');
  if (!items.length) {
    root.innerHTML = '<div class="photo-review-empty">' +
      '✅ No photos pending review. All caught up.</div>';
    return;
  }
  root.innerHTML = items.map(it => {
    const newThumb = '<div class="photo-thumb new">' +
      '<img src="'+escapeAttr(it.path)+'?r='+it.id+'" alt="" ' +
      'onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'<div class=photo-empty>Unavailable</div>\'">' +
      '</div>' +
      '<div class="photo-thumb-label">NEW candidate</div>' +
      '<div class="photo-badge-new">'+escapeHtml(it.operator_name)+' · '+humanBytes(it.byte_size)+'</div>';
    const curThumb = it.has_current
      ? '<div class="photo-thumb">' +
          '<img src="'+escapeAttr(it.current_path)+'?r=c'+it.id+'" alt="" ' +
          'onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'<div class=photo-empty>None</div>\'">' +
        '</div>' +
        '<div class="photo-thumb-label">CURRENT photo</div>' +
        '<div class="photo-badge-current">Existing</div>'
      : '<div class="photo-thumb"><div class="photo-empty">No photo yet</div></div>' +
        '<div class="photo-thumb-label">CURRENT</div>' +
        '<div class="photo-badge-current">Member has no photo</div>';
    return '' +
      '<div class="photo-card" data-id="'+it.id+'">' +
        '<div class="photo-card__member">' +
          '<h3>'+escapeHtml(it.member_name)+' '+escapeHtml(it.father_name||'')+'</h3>' +
          '<div class="photo-card__meta"><span class="code">'+escapeHtml(it.member_code||'—')+'</span> '+(it.field==='guardian_photo'?'Guardian photo':'Student photo')+'</div>' +
          '<div class="photo-meta-row">New shot: '+(it.taken_at||'unknown')+'</div>' +
        '</div>' +
        '<div>'+curThumb+'</div>' +
        '<div>'+newThumb+'</div>' +
        '<div class="photo-actions">' +
          '<button class="btn-pr accept" onclick="decide('+it.id+',\'accept\',this)">✓ Use new photo</button>' +
          '<button class="btn-pr reject" onclick="decide('+it.id+',\'reject\',this)">✗ Keep current</button>' +
        '</div>' +
      '</div>';
  }).join('');
}

async function decide(id, decision, btn) {
  btn.disabled = true;
  btn.textContent = 'Saving…';
  try {
    const r = await fetch('/admin/api_photo_candidates.php?action=decide', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({candidate_id: id, decision: decision}),
    });
    const j = await r.json();
    if (j.status !== 'success') {
      alert(j.message || 'Could not save decision.');
      btn.disabled = false;
      btn.textContent = decision==='accept'?'✓ Use new photo':'✗ Keep current';
      return;
    }
    const card = btn.closest('.photo-card');
    if (card) card.style.opacity = '.4';
    setTimeout(() => card && card.remove(), 400);
  } catch (e) {
    alert('Network error.');
    btn.disabled = false;
    btn.textContent = decision==='accept'?'✓ Use new photo':'✗ Keep current';
  }
}

function escapeHtml(s) {
  return (s||'').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));
}
function escapeAttr(s) { return escapeHtml(s); }

loadList();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
