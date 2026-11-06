/**
 * QUIVER Live View Client Script (Day 18)
 *
 * Capabilities:
 *  1. Polling every 15s to `/api/live.php?quiz_id=N`.
 *  2. 1-second tick timer for countdowns (hero close timer and per-attempt deadlines).
 *  3. Instant client-side search and status filtering without layout shifts.
 *  4. Accessible Time Extension dialog (capped at quiz close).
 *  5. Accessible Void Attempt confirmation dialog.
 */

(function () {
  'use strict';

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  const root = document.getElementById('live-root');
  if (!root) return;

  const quizId = parseInt(root.dataset.quizId, 10);
  const csrfToken = root.dataset.csrf || '';
  const pollIntervalMs = 15000;

  // DOM Elements
  const heroBadge = document.getElementById('live-hero-badge');
  const heroHeadline = document.getElementById('live-hero-headline');
  const heroMeta = document.getElementById('live-hero-meta');
  const heroTimer = document.getElementById('live-hero-timer');
  const bannerMount = document.getElementById('live-alert-mount');
  const tableBody = document.getElementById('live-table-body');
  const searchInput = document.getElementById('live-search-input');
  const statusFilter = document.getElementById('live-status-filter');
  const footerSummary = document.getElementById('live-footer-summary');
  const auditList = document.getElementById('live-audit-list');

  // Modals
  const extendModal = document.getElementById('modal-extend');
  const voidModal = document.getElementById('modal-void');
  let currentExtendAttemptId = null;
  let currentVoidAttemptId = null;

  // State
  let liveData = null;
  let untilCloseSeconds = parseInt(heroTimer ? heroTimer.dataset.seconds : 0, 10) || 0;
  let participantTimers = {}; // attemptId -> remainingSeconds

  // ---- 1. Timers (1-second tick) ----
  function formatMmSs(sec) {
    const s = Math.max(0, sec);
    const m = Math.floor(s / 60);
    const rem = s % 60;
    return (m < 10 ? '0' : '') + m + ':' + (rem < 10 ? '0' : '') + rem;
  }

  setInterval(function () {
    // Tick hero timer
    if (untilCloseSeconds > 0) {
      untilCloseSeconds--;
      if (heroTimer) {
        heroTimer.textContent = formatMmSs(untilCloseSeconds);
      }
    }

    // Tick student timers
    for (const [attId, sec] of Object.entries(participantTimers)) {
      if (sec > 0) {
        participantTimers[attId] = sec - 1;
        const cell = document.querySelector(`.live-timer-val[data-attempt-id="${attId}"]`);
        if (cell) {
          cell.textContent = formatMmSs(participantTimers[attId]);
          if (participantTimers[attId] <= 300) {
            cell.classList.add('live-tbl__time-urgent');
          }
        }
      } else {
        const cell = document.querySelector(`.live-timer-val[data-attempt-id="${attId}"]`);
        if (cell && cell.textContent !== '00:00' && cell.textContent !== '—') {
          cell.textContent = '00:00';
          cell.classList.add('live-tbl__time-urgent');
        }
      }
    }
  }, 1000);

  // ---- 2. Polling (15 seconds) ----
  let isPolling = false;

  async function pollLive() {
    if (isPolling) return;
    isPolling = true;

    try {
      const res = await fetch(`/api/live.php?quiz_id=${quizId}`, {
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) return;
      const json = await res.json();
      if (!json.ok || !json.data) return;

      updateView(json.data);
    } catch (e) {
      // Quiet fail on network hiccups; next poll retries
    } finally {
      isPolling = false;
    }
  }

  setInterval(pollLive, pollIntervalMs);

  // ---- 3. View Updater ----
  function updateView(data) {
    liveData = data;

    // 1. Update Hero
    if (data.hero) {
      if (heroBadge) heroBadge.textContent = data.hero.badge;
      if (heroHeadline) heroHeadline.textContent = data.hero.headline;
      if (heroMeta) heroMeta.textContent = data.hero.submeta;
      untilCloseSeconds = data.hero.until_close_seconds || 0;
      if (heroTimer) heroTimer.textContent = data.hero.until_close_formatted;
    }

    // 2. Update Attention Banner
    if (bannerMount) {
      if (data.attention_banner && data.attention_banner.has_attention && data.attention_banner.first_student) {
        const first = data.attention_banner.first_student;
        const extra = data.attention_banner.total_disconnected > 1
          ? ` (+${data.attention_banner.total_disconnected - 1} others)`
          : '';
        bannerMount.innerHTML = `
          <div class="live-alert" role="alert">
            <div class="live-alert__badge">
              <span class="mark" data-state="attention">
                <span class="mark__ring" aria-hidden="true"></span>
                <span class="mark__label">Disconnected</span>
              </span>
            </div>
            <div class="live-alert__content">
              <p class="live-alert__title">
                <strong>${escapeHtml(first.student_name)}${extra}</strong> · Last seen ${escapeHtml(first.idle_ago)} · ${first.answered} of ${first.total} answered
              </p>
              <p class="live-alert__note">${escapeHtml(data.attention_banner.note)}</p>
            </div>
          </div>
        `;
        bannerMount.hidden = false;
      } else {
        bannerMount.innerHTML = '';
        bannerMount.hidden = true;
      }
    }

    // 3. Update Participant Timers map & Table Rows
    participantTimers = {};
    if (Array.isArray(data.participants) && tableBody) {
      tableBody.innerHTML = '';
      data.participants.forEach((p) => {
        if (p.can_extend && p.attempt_id) {
          participantTimers[p.attempt_id] = p.time_left_seconds || 0;
        }
        tableBody.appendChild(createRow(p));
      });
      filterTable();
    }

    // 4. Update Audit Log List if present
    if (auditList && Array.isArray(data.events)) {
      if (data.events.length === 0) {
        auditList.innerHTML = '<li class="muted-sm">No recorded extensions or voided attempts yet.</li>';
      } else {
        auditList.innerHTML = data.events.map((ev) => `
          <li class="live-audit-item">
            <div>
              <strong>${escapeHtml(ev.student_name)}</strong> —
              ${ev.kind === 'time_extended' ? 'Time extended' : 'Attempt voided'}
              ${ev.note ? `<span class="muted-sm">(${escapeHtml(ev.note)})</span>` : ''}
              <span class="muted-sm">by ${escapeHtml(ev.actor_name)}</span>
            </div>
            <div class="muted-sm font-tabular">${escapeHtml(ev.created_at_manila)}</div>
          </li>
        `).join('');
      }
    }
  }

  function createRow(p) {
    const tr = document.createElement('tr');
    tr.className = 'live-row';
    tr.dataset.state = p.state_code;
    tr.dataset.name = (p.full_name + ' ' + p.username).toLowerCase();

    // Student column
    const stuTd = document.createElement('td');
    stuTd.innerHTML = `
      <div class="live-tbl__student-col">
        <span class="live-tbl__student-name">${escapeHtml(p.short_name || p.full_name)}</span>
        <span class="live-tbl__student-user">@${escapeHtml(p.username)}</span>
      </div>
    `;

    // Status column
    const statTd = document.createElement('td');
    statTd.innerHTML = `
      <span class="mark" data-state="${escapeHtml(p.status_mark_state)}">
        <span class="mark__ring" aria-hidden="true"></span>
        <span class="mark__label">${escapeHtml(p.status_label)}</span>
      </span>
    `;

    // Answered column
    const ansTd = document.createElement('td');
    ansTd.className = 'live-tbl__num';
    ansTd.textContent = p.answered_display || '—';

    // Last seen column
    const seenTd = document.createElement('td');
    seenTd.className = 'live-tbl__num';
    seenTd.textContent = p.last_seen_manila || '—';

    // Time left column
    const timeTd = document.createElement('td');
    if (p.state_code === 'working' || p.state_code === 'disconnected') {
      const isUrgent = (p.time_left_seconds || 0) <= 300;
      timeTd.innerHTML = `<span class="live-timer-val live-tbl__time-active ${isUrgent ? 'live-tbl__time-urgent' : ''}" data-attempt-id="${p.attempt_id}">${escapeHtml(p.time_left_display)}</span>`;
    } else {
      timeTd.className = 'live-tbl__num';
      timeTd.textContent = '—';
    }

    // Actions column
    const actTd = document.createElement('td');
    actTd.className = 'live-actions';

    if (p.can_extend && p.attempt_id) {
      const extBtn = document.createElement('button');
      extBtn.type = 'button';
      extBtn.className = 'live-btn-action';
      extBtn.dataset.action = 'extend';
      extBtn.dataset.attemptId = p.attempt_id;
      extBtn.dataset.studentName = p.full_name;
      extBtn.dataset.deadline = p.deadline_manila || '';
      extBtn.textContent = 'Extend time...';
      actTd.appendChild(extBtn);
    }

    if (p.can_void && p.attempt_id) {
      const voidBtn = document.createElement('button');
      voidBtn.type = 'button';
      voidBtn.className = 'live-btn-action live-btn-action--danger';
      voidBtn.dataset.action = 'void';
      voidBtn.dataset.attemptId = p.attempt_id;
      voidBtn.dataset.studentName = p.full_name;
      voidBtn.dataset.attemptNo = p.attempt_no || 1;
      voidBtn.textContent = 'Void attempt...';
      actTd.appendChild(voidBtn);
    }

    if (p.can_view && p.view_href) {
      const viewLink = document.createElement('a');
      viewLink.className = 'live-btn-link';
      viewLink.href = p.view_href;
      viewLink.textContent = 'View attempt →';
      actTd.appendChild(viewLink);
    } else if (p.state_code === 'voided') {
      const voidSpan = document.createElement('span');
      voidSpan.className = 'muted-sm';
      voidSpan.textContent = 'Voided';
      actTd.appendChild(voidSpan);
    } else if (p.state_code === 'not_started') {
      const nsSpan = document.createElement('span');
      nsSpan.className = 'muted-sm';
      nsSpan.textContent = '—';
      actTd.appendChild(nsSpan);
    }

    tr.appendChild(stuTd);
    tr.appendChild(statTd);
    tr.appendChild(ansTd);
    tr.appendChild(seenTd);
    tr.appendChild(timeTd);
    tr.appendChild(actTd);

    return tr;
  }

  // ---- 4. Client Search & Status Filter ----
  function filterTable() {
    if (!tableBody) return;
    const query = (searchInput ? searchInput.value : '').trim().toLowerCase();
    const statusVal = statusFilter ? statusFilter.value : 'all';

    const rows = tableBody.querySelectorAll('.live-row');
    let visibleCount = 0;

    rows.forEach((row) => {
      const rowName = row.dataset.name || '';
      const rowState = row.dataset.state || '';

      const matchesQuery = query === '' || rowName.includes(query);
      let matchesStatus = true;

      if (statusVal === 'working') {
        matchesStatus = (rowState === 'working');
      } else if (statusVal === 'disconnected') {
        matchesStatus = (rowState === 'disconnected');
      } else if (statusVal === 'submitted') {
        matchesStatus = (rowState === 'submitted' || rowState === 'auto_submitted');
      } else if (statusVal === 'voided') {
        matchesStatus = (rowState === 'voided');
      } else if (statusVal === 'not_started') {
        matchesStatus = (rowState === 'not_started');
      }

      if (matchesQuery && matchesStatus) {
        row.hidden = false;
        visibleCount++;
      } else {
        row.hidden = true;
      }
    });

    if (footerSummary) {
      footerSummary.textContent = `Showing ${visibleCount} of ${rows.length} participants`;
    }
  }

  if (searchInput) {
    searchInput.addEventListener('input', filterTable);
  }
  if (statusFilter) {
    statusFilter.addEventListener('change', filterTable);
  }

  // ---- 5. Time Extension Modal ----
  function openExtendModal(opts) {
    if (!extendModal) return;
    currentExtendAttemptId = parseInt(opts.attempt_id, 10);

    const nameEl = document.getElementById('modal-extend-name');
    const deadlineEl = document.getElementById('modal-extend-deadline');
    const minInput = document.getElementById('modal-extend-minutes');
    const noteInput = document.getElementById('modal-extend-note');
    const errorEl = document.getElementById('modal-extend-error');

    if (nameEl) nameEl.textContent = opts.full_name || 'Student';
    if (deadlineEl) deadlineEl.textContent = opts.deadline_manila || 'Current deadline';
    if (minInput) minInput.value = '5';
    if (noteInput) noteInput.value = '';
    if (errorEl) {
      errorEl.hidden = true;
      errorEl.textContent = '';
    }

    // Reset chips selection
    document.querySelectorAll('#modal-extend .live-chip-btn').forEach((btn) => {
      btn.classList.toggle('is-selected', btn.dataset.min === '5');
    });

    extendModal.hidden = false;
    if (minInput) minInput.focus();
  }

  function closeExtendModal() {
    if (extendModal) extendModal.hidden = true;
    currentExtendAttemptId = null;
  }

  const extendForm = document.getElementById('modal-extend-form');
  if (extendForm) {
    extendForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      if (!currentExtendAttemptId) return;

      const minInput = document.getElementById('modal-extend-minutes');
      const noteInput = document.getElementById('modal-extend-note');
      const errorEl = document.getElementById('modal-extend-error');
      const submitBtn = extendForm.querySelector('button[type="submit"]');

      const minutes = parseInt(minInput ? minInput.value : 0, 10);
      const note = noteInput ? noteInput.value.trim() : '';

      if (submitBtn) submitBtn.disabled = true;

      try {
        const res = await fetch('/api/live.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
          },
          body: JSON.stringify({
            action: 'extend_time',
            attempt_id: currentExtendAttemptId,
            minutes: minutes,
            note: note,
            _csrf: csrfToken,
          }),
        });

        const json = await res.json();
        if (!res.ok || !json.ok) {
          throw new Error(json.error || 'Failed to extend time.');
        }

        closeExtendModal();
        showToast(json.message || 'Time extended successfully.');
        pollLive();
      } catch (err) {
        if (errorEl) {
          errorEl.textContent = err.message;
          errorEl.hidden = false;
        }
      } finally {
        if (submitBtn) submitBtn.disabled = false;
      }
    });
  }

  // ---- 6. Void Attempt Modal ----
  function openVoidModal(opts) {
    if (!voidModal) return;
    currentVoidAttemptId = parseInt(opts.attempt_id, 10);

    const nameEl = document.getElementById('modal-void-name');
    const reasonInput = document.getElementById('modal-void-reason');
    const errorEl = document.getElementById('modal-void-error');

    const label = opts.full_name ? `${opts.full_name} (Attempt #${opts.attempt_no || 1})` : 'Attempt';
    if (nameEl) nameEl.textContent = label;
    if (reasonInput) reasonInput.value = '';
    if (errorEl) {
      errorEl.hidden = true;
      errorEl.textContent = '';
    }

    voidModal.hidden = false;
    if (reasonInput) reasonInput.focus();
  }

  function closeVoidModal() {
    if (voidModal) voidModal.hidden = true;
    currentVoidAttemptId = null;
  }

  const voidForm = document.getElementById('modal-void-form');
  if (voidForm) {
    voidForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      if (!currentVoidAttemptId) return;

      const reasonInput = document.getElementById('modal-void-reason');
      const errorEl = document.getElementById('modal-void-error');
      const submitBtn = voidForm.querySelector('button[type="submit"]');

      const reason = reasonInput ? reasonInput.value.trim() : '';
      if (!reason) {
        if (errorEl) {
          errorEl.textContent = 'Please provide a reason.';
          errorEl.hidden = false;
        }
        return;
      }

      if (submitBtn) submitBtn.disabled = true;

      try {
        const res = await fetch('/api/live.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
          },
          body: JSON.stringify({
            action: 'void_attempt',
            attempt_id: currentVoidAttemptId,
            reason: reason,
            _csrf: csrfToken,
          }),
        });

        const json = await res.json();
        if (!res.ok || !json.ok) {
          throw new Error(json.error || 'Failed to void attempt.');
        }

        closeVoidModal();
        showToast(json.message || 'Attempt voided.');
        pollLive();
      } catch (err) {
        if (errorEl) {
          errorEl.textContent = err.message;
          errorEl.hidden = false;
        }
      } finally {
        if (submitBtn) submitBtn.disabled = false;
      }
    });
  }

  // Delegated click handler for buttons across the document
  document.addEventListener('click', function (e) {
    // 1. Extend time action button
    const extBtn = e.target.closest('[data-action="extend"]');
    if (extBtn) {
      e.preventDefault();
      openExtendModal({
        attempt_id: extBtn.dataset.attemptId,
        full_name: extBtn.dataset.studentName,
        deadline_manila: extBtn.dataset.deadline,
      });
      return;
    }

    // 2. Void attempt action button
    const voidBtn = e.target.closest('[data-action="void"]');
    if (voidBtn) {
      e.preventDefault();
      openVoidModal({
        attempt_id: voidBtn.dataset.attemptId,
        full_name: voidBtn.dataset.studentName,
        attempt_no: voidBtn.dataset.attemptNo,
      });
      return;
    }

    // 3. Modal close buttons (cancel / cross)
    if (e.target.matches('[data-close-modal]') || e.target.closest('[data-close-modal]')) {
      e.preventDefault();
      closeExtendModal();
      closeVoidModal();
      return;
    }

    // 4. Backdrop click
    if (e.target.classList.contains('live-modal-backdrop')) {
      closeExtendModal();
      closeVoidModal();
      return;
    }

    // 5. Minute chips
    const chipBtn = e.target.closest('#modal-extend .live-chip-btn');
    if (chipBtn) {
      e.preventDefault();
      document.querySelectorAll('#modal-extend .live-chip-btn').forEach((b) => b.classList.remove('is-selected'));
      chipBtn.classList.add('is-selected');
      const minInput = document.getElementById('modal-extend-minutes');
      if (minInput) minInput.value = chipBtn.dataset.min;
      return;
    }
  });

  // Escape key listener to close modals
  window.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeExtendModal();
      closeVoidModal();
    }
  });

  // ---- 7. Toast Notification ----
  function showToast(msg) {
    const existing = document.querySelector('.live-toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.className = 'live-toast';
    toast.textContent = msg;
    document.body.appendChild(toast);

    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transition = 'opacity 0.3s ease';
      setTimeout(() => toast.remove(), 300);
    }, 3500);
  }

  // Initial filter run
  filterTable();
})();
