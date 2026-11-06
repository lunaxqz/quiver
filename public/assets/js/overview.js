(function () {
  'use strict';

  var POLL_INTERVAL_MS = 45000;
  var heroRemainingSec = 0;
  var heroAnchorPerf   = 0;
  var heroTimerInterval = null;

  var rootEl       = document.getElementById('overview-root');
  var subtitleEl   = document.querySelector('.page-head__sub');
  var heroMount    = document.getElementById('today-hero-mount');
  var listMount    = document.getElementById('today-list-mount');
  var summaryEl    = document.getElementById('overview-summary-line');
  var weekMount    = document.getElementById('this-week-mount');

  function formatMMSS(totalSeconds) {
    var sec = Math.max(0, Math.floor(totalSeconds));
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return (m < 10 ? '0' + m : String(m)) + ':' + (s < 10 ? '0' + s : String(s));
  }

  function numberWord(n, capitalize) {
    var words = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten'];
    var w = n >= 0 && n <= 10 ? words[n] : String(n);
    return capitalize ? w.charAt(0).toUpperCase() + w.slice(1) : w;
  }

  function subtitleForCount(count, classesCount) {
    if (classesCount === 0) {
      return 'Nothing needs you right now. Everything is on track.';
    }
    if (count === 0) {
      return 'Nothing needs you right now. Everything else is on track.';
    }
    if (count === 1) {
      return 'One thing needs you today. Everything else is on track.';
    }
    return numberWord(count, true) + ' things need you today. Everything else is on track.';
  }

  function syncHeroCountdown(seconds) {
    heroRemainingSec = Math.max(0, Number(seconds) || 0);
    heroAnchorPerf = performance.now();
    renderHeroTick();
    if (!heroTimerInterval) {
      heroTimerInterval = window.setInterval(renderHeroTick, 500);
    }
  }

  function renderHeroTick() {
    var timerEl = document.getElementById('today-hero-timer');
    if (!timerEl) {
      return;
    }
    var elapsed = (performance.now() - heroAnchorPerf) / 1000;
    var left = Math.max(0, Math.round(heroRemainingSec - elapsed));
    timerEl.textContent = formatMMSS(left);
  }

  function buildStatusMark(state, label) {
    var span = document.createElement('span');
    span.className = 'mark';
    span.setAttribute('data-state', state || 'neutral');

    var ring = document.createElement('span');
    ring.className = 'mark__ring';
    ring.setAttribute('aria-hidden', 'true');

    var lbl = document.createElement('span');
    lbl.className = 'mark__label';
    lbl.textContent = label || '';

    span.appendChild(ring);
    span.appendChild(lbl);
    return span;
  }

  function renderHero(heroItem) {
    if (!heroMount) {
      return;
    }
    heroMount.textContent = '';
    if (!heroItem) {
      heroMount.hidden = true;
      return;
    }
    heroMount.hidden = false;

    var sec = document.createElement('section');
    sec.className = 'today-hero';
    sec.setAttribute('aria-label', 'Closing soon');

    var left = document.createElement('div');
    left.className = 'today-hero__left';

    var badge = document.createElement('div');
    badge.className = 'today-hero__badge';
    var ring = document.createElement('span');
    ring.className = 'today-hero__ring';
    ring.setAttribute('aria-hidden', 'true');
    var badgeTxt = document.createElement('span');
    badgeTxt.textContent = 'Closing soon';
    badge.appendChild(ring);
    badge.appendChild(badgeTxt);

    var h2 = document.createElement('h2');
    h2.className = 'today-hero__headline';
    h2.textContent = heroItem.headline || heroItem.detail || '';

    var meta = document.createElement('p');
    meta.className = 'today-hero__meta';
    meta.textContent = heroItem.submeta || '';

    var btn = document.createElement('a');
    btn.className = 'today-hero__btn';
    btn.href = heroItem.action_href || '#';
    btn.textContent = heroItem.action_label || 'Open live view';

    left.appendChild(badge);
    left.appendChild(h2);
    left.appendChild(meta);
    left.appendChild(btn);

    var right = document.createElement('div');
    right.className = 'today-hero__right';

    var tLabel = document.createElement('div');
    tLabel.className = 'today-hero__timer-label';
    tLabel.textContent = 'Time remaining';

    var tVal = document.createElement('div');
    tVal.className = 'today-hero__timer';
    tVal.id = 'today-hero-timer';
    tVal.textContent = formatMMSS(heroItem.remaining_seconds || 0);

    var grid = document.createElement('div');
    grid.className = 'today-hero__grid';
    grid.setAttribute('aria-hidden', 'true');
    var cells = Array.isArray(heroItem.cells) ? heroItem.cells : [];
    for (var i = 0; i < cells.length; i++) {
      var cell = document.createElement('span');
      cell.className = 'hero-cell hero-cell--' + cells[i];
      grid.appendChild(cell);
    }

    var legend = document.createElement('div');
    legend.className = 'today-hero__legend';
    legend.textContent = 'Solid: submitted · Outlined: working · Dashed: disconnected';

    right.appendChild(tLabel);
    right.appendChild(tVal);
    right.appendChild(grid);
    right.appendChild(legend);

    sec.appendChild(left);
    sec.appendChild(right);
    heroMount.appendChild(sec);

    syncHeroCountdown(heroItem.remaining_seconds || 0);
  }

  function renderAlsoToday(alsoToday, hasHero, summary) {
    if (!listMount) {
      return;
    }
    listMount.textContent = '';

    if ((!Array.isArray(alsoToday) || alsoToday.length === 0) && !hasHero) {
      // Calm empty state when nothing needs the teacher right now
      if (summary && summary.classes_count === 0) {
        var emptyDiv = document.createElement('div');
        emptyDiv.className = 'state';
        var eh3 = document.createElement('h3');
        eh3.textContent = 'Nothing needs you right now';
        var ep = document.createElement('p');
        ep.textContent = 'Create a class to get started. Your Today rail will surface quizzes closing soon, grading, and release items here.';
        var ea = document.createElement('a');
        ea.className = 'btn btn--primary';
        ea.href = '/teacher/classes.php';
        ea.textContent = 'Create a class';
        emptyDiv.appendChild(eh3);
        emptyDiv.appendChild(ep);
        emptyDiv.appendChild(ea);
        listMount.appendChild(emptyDiv);
        return;
      }

      var calm = document.createElement('section');
      calm.className = 'today-calm-card';
      var cLeft = document.createElement('div');
      cLeft.className = 'today-calm-card__left';
      cLeft.appendChild(buildStatusMark('complete', ''));
      var cText = document.createElement('div');
      var cTitle = document.createElement('p');
      cTitle.className = 'today-calm-card__title';
      cTitle.textContent = 'Nothing needs you right now';
      var cSub = document.createElement('p');
      cSub.className = 'today-calm-card__sub';
      cSub.textContent = 'All active quizzes, grading queues, and result releases are up to date.';
      cText.appendChild(cTitle);
      cText.appendChild(cSub);
      cLeft.appendChild(cText);

      var cBtn = document.createElement('a');
      cBtn.className = 'today-row__btn';
      cBtn.href = '/teacher/quizzes.php';
      cBtn.textContent = 'View quizzes';

      calm.appendChild(cLeft);
      calm.appendChild(cBtn);
      listMount.appendChild(calm);
      return;
    }

    if (!Array.isArray(alsoToday) || alsoToday.length === 0) {
      return;
    }

    var card = document.createElement('section');
    card.className = 'today-card';
    card.setAttribute('aria-label', hasHero ? 'Also today' : 'Today');

    var head = document.createElement('div');
    head.className = 'today-card__head';
    head.textContent = hasHero ? 'Also today' : 'Today';

    var ul = document.createElement('ul');
    ul.className = 'today-list';

    for (var i = 0; i < alsoToday.length; i++) {
      var item = alsoToday[i];
      var li = document.createElement('li');
      li.className = 'today-row';
      li.setAttribute('data-today-type', item.type || '');

      var markCell = document.createElement('div');
      markCell.className = 'today-row__mark';
      markCell.appendChild(buildStatusMark(item.state || 'neutral', item.badge_label || ''));

      var detailCell = document.createElement('div');
      detailCell.className = 'today-row__detail';
      detailCell.textContent = item.detail || '';

      var actCell = document.createElement('div');
      actCell.className = 'today-row__action';
      var btn = document.createElement('a');
      btn.className = 'today-row__btn';
      btn.href = item.action_href || '#';
      btn.textContent = item.action_label || 'Open';
      actCell.appendChild(btn);

      li.appendChild(markCell);
      li.appendChild(detailCell);
      li.appendChild(actCell);
      ul.appendChild(li);
    }

    card.appendChild(head);
    card.appendChild(ul);
    listMount.appendChild(card);
  }

  function renderSummary(summary) {
    if (!summaryEl || !summary) {
      return;
    }
    var c = Number(summary.classes_count) || 0;
    var s = Number(summary.students_count) || 0;
    var q = Number(summary.active_quizzes_count) || 0;
    summaryEl.textContent =
      c + ' ' + (c === 1 ? 'class' : 'classes') + ' · ' +
      s + ' ' + (s === 1 ? 'student' : 'students') + ' · ' +
      q + ' active ' + (q === 1 ? 'quiz' : 'quizzes');
  }

  function renderThisWeek(thisWeek) {
    if (!weekMount) {
      return;
    }
    var tbody = weekMount.querySelector('tbody');
    if (!tbody || !Array.isArray(thisWeek)) {
      return;
    }
    if (thisWeek.length === 0) {
      return;
    }
    tbody.textContent = '';
    for (var i = 0; i < thisWeek.length; i++) {
      var r = thisWeek[i];
      var tr = document.createElement('tr');

      var tdQuiz = document.createElement('td');
      var a = document.createElement('a');
      a.className = 'week-quiz-title';
      a.href = r.href || ('/teacher/quiz.php?id=' + r.id);
      a.textContent = r.title || '';
      var meta = document.createElement('span');
      meta.className = 'week-quiz-meta';
      meta.textContent = r.class_meta || '';
      tdQuiz.appendChild(a);
      tdQuiz.appendChild(meta);

      var tdStatus = document.createElement('td');
      tdStatus.appendChild(buildStatusMark(r.status_state || 'neutral', r.status_label || ''));

      var tdWin = document.createElement('td');
      tdWin.textContent = r.window_label || '';

      var tdSub = document.createElement('td');
      var wrap = document.createElement('div');
      wrap.className = 'week-sub-wrap';
      var countSpan = document.createElement('span');
      countSpan.textContent = (r.submitted_count || 0) + ' / ' + (r.roster_count || 0);
      var bar = document.createElement('span');
      bar.className = 'week-bar';
      bar.setAttribute('aria-hidden', 'true');
      var fill = document.createElement('span');
      fill.className = 'week-bar__fill';
      fill.style.width = Math.max(0, Math.min(100, Number(r.progress_pct) || 0)) + '%';
      bar.appendChild(fill);
      wrap.appendChild(countSpan);
      wrap.appendChild(bar);
      tdSub.appendChild(wrap);

      tr.appendChild(tdQuiz);
      tr.appendChild(tdStatus);
      tr.appendChild(tdWin);
      tr.appendChild(tdSub);
      tbody.appendChild(tr);
    }
  }

  function updateGradingBadge(count) {
    var badge = document.getElementById('nav-grading-badge');
    var n = Number(count) || 0;
    if (badge) {
      if (n > 0) {
        badge.textContent = String(n);
        badge.hidden = false;
      } else {
        badge.hidden = true;
      }
    }
  }

  function pollToday() {
    fetch('/api/today.php', {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
      .then(function (res) {
        if (!res.ok) {
          return null;
        }
        return res.json();
      })
      .then(function (data) {
        if (!data || !data.ok) {
          return;
        }
        var totalItems = Array.isArray(data.today_items) ? data.today_items.length : 0;
        var classesCnt = data.summary ? Number(data.summary.classes_count) || 0 : 0;
        if (subtitleEl) {
          subtitleEl.textContent = subtitleForCount(totalItems, classesCnt);
        }
        renderHero(data.hero_item || null);
        renderAlsoToday(data.also_today || [], Boolean(data.hero_item), data.summary || null);
        renderSummary(data.summary || null);
        renderThisWeek(data.this_week || []);
        if (typeof data.grading_badge_count !== 'undefined') {
          updateGradingBadge(data.grading_badge_count);
        }
      })
      .catch(function () {
        // Silent background poll failure; next 45s cycle will retry
      });
  }

  // Initialize hero countdown if server rendered a Closing Soon hero card
  if (rootEl) {
    var initSec = Number(rootEl.getAttribute('data-hero-remaining') || '0');
    if (initSec > 0) {
      syncHeroCountdown(initSec);
    }
    window.setInterval(pollToday, POLL_INTERVAL_MS);
  }
})();
