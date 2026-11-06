/**
 * QUIVER Student Attempt Screen (Day 12).
 *
 * Responsibilities:
 *  1. One question per screen, rendered into #att-fieldset with a serif <legend> and >=48px option rows.
 *  2. Progress dots (filled: answered, outline: unanswered, emphasised: current) + summary header.
 *  3. Keyboard support:
 *      - Number keys 1..9 (and letter keys A..I) select/toggle options on choice questions when not typing in an input.
 *      - Left/Right arrow keys move between questions when not focused inside a text input/radio group.
 *  4. Server-owned timer:
 *      - Starts from PHP's remaining_seconds and ticks via performance.now() (monotonic clock).
 *      - Changing the laptop wall clock (Date.now / system clock) has zero effect on the countdown.
 *      - Resyncs from the server's /api/timer.php on visibilitychange/focus and via window.quiverTimer.resync(seconds)
 *        so every API response (including Day 13 autosave) keeps the timer locked to server time.
 *      - Announces via polite live region at 10 minutes, 5 minutes, and 1 minute remaining.
 *      - Color shift & "Final minute" label at <= 60 seconds.
 */
(function () {
  'use strict';

  var dataEl = document.getElementById('attempt-data');
  if (!dataEl) return;

  var payload;
  try {
    payload = JSON.parse(dataEl.textContent || '{}');
  } catch (e) {
    return;
  }

  var attempt   = payload.attempt || {};
  var questions = Array.isArray(payload.questions) ? payload.questions : [];
  var total     = questions.length;
  if (total === 0) return;

  var STORAGE_KEY   = 'quiver_attempt_' + attempt.id + '_answers';
  var INDEX_KEY     = 'quiver_attempt_' + attempt.id + '_index';

  // Merge server-stored responses with any newer sessionStorage draft.
  var answers = {};
  var serverResponses = payload.responses || {};
  Object.keys(serverResponses).forEach(function (qid) {
    var r = serverResponses[qid];
    answers[qid] = {
      option_ids: Array.isArray(r.option_ids) ? r.option_ids.map(Number) : [],
      answer_text: typeof r.answer_text === 'string' ? r.answer_text : '',
      revision: Number(r.revision || 0)
    };
  });

  try {
    var cached = sessionStorage.getItem(STORAGE_KEY);
    if (cached) {
      var parsed = JSON.parse(cached);
      Object.keys(parsed).forEach(function (qid) {
        var c = parsed[qid];
        var s = answers[qid];
        if (!s || Number(c.revision || 0) >= Number(s.revision || 0)) {
          answers[qid] = {
            option_ids: Array.isArray(c.option_ids) ? c.option_ids.map(Number) : [],
            answer_text: typeof c.answer_text === 'string' ? c.answer_text : '',
            revision: Number(c.revision || 0)
          };
        }
      });
    }
  } catch (e) {
    // sessionStorage unavailable or blocked; continue in-memory.
  }

  function persistLocal() {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(answers));
      sessionStorage.setItem(INDEX_KEY, String(currentIndex));
    } catch (e) {}
  }

  function isQuestionAnswered(q) {
    var a = answers[q.id];
    if (!a) return false;
    if (q.type === 'single' || q.type === 'true_false' || q.type === 'multi') {
      return Array.isArray(a.option_ids) && a.option_ids.length > 0;
    }
    return typeof a.answer_text === 'string' && a.answer_text.trim() !== '';
  }

  function countAnswered() {
    var n = 0;
    for (var i = 0; i < total; i++) {
      if (isQuestionAnswered(questions[i])) n++;
    }
    return n;
  }

  // ---- DOM references ----
  var summaryEl   = document.getElementById('att-summary');
  var dotsEl      = document.getElementById('att-dots');
  var typeEl      = document.getElementById('att-type');
  var pointsEl    = document.getElementById('att-points');
  var promptEl    = document.getElementById('att-prompt');
  var optionsEl   = document.getElementById('att-options');
  var prevBtn     = document.getElementById('att-prev');
  var nextBtn     = document.getElementById('att-next');
  var reviewBtn   = document.getElementById('att-review');
  var timerBox    = document.getElementById('att-timer-box');
  var timerLabel  = document.getElementById('att-timer-label');
  var timerDigits = document.getElementById('att-timer-digits');
  var timerLive   = document.getElementById('att-timer-live');

  var currentIndex = 0;
  try {
    var savedIdx = parseInt(sessionStorage.getItem(INDEX_KEY) || '0', 10);
    if (!isNaN(savedIdx) && savedIdx >= 0 && savedIdx < total) {
      currentIndex = savedIdx;
    }
  } catch (e) {}

  var TYPE_LABELS = {
    single:     'SINGLE-SELECT · CHOOSE ONE',
    true_false: 'TRUE OR FALSE · CHOOSE ONE',
    multi:      'MULTI-SELECT · CHOOSE ALL THAT APPLY',
    short:      'SHORT ANSWER · TYPE YOUR RESPONSE',
    essay:      'WRITTEN RESPONSE · ESSAY'
  };

  var LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

  function formatPoints(pts) {
    var n = Number(pts);
    var s = (Math.round(n * 100) / 100).toString();
    return s + (n === 1 ? ' point' : ' points');
  }

  function ensureEntry(qid) {
    if (!answers[qid]) {
      answers[qid] = { option_ids: [], answer_text: '', revision: 0 };
    }
    return answers[qid];
  }

  function recordChoice(q, optionId) {
    var entry = ensureEntry(q.id);
    optionId = Number(optionId);
    if (q.type === 'multi') {
      var idx = entry.option_ids.indexOf(optionId);
      if (idx === -1) {
        entry.option_ids.push(optionId);
        entry.option_ids.sort(function (a, b) { return a - b; });
      } else {
        entry.option_ids.splice(idx, 1);
      }
    } else {
      entry.option_ids = [optionId];
    }
    entry.revision = (entry.revision || 0) + 1;
    persistLocal();
    updateOptionSelectionUI(q);
    updateProgressUI();
    dispatchAnswerChange(q.id);
  }

  function recordText(q, text) {
    var entry = ensureEntry(q.id);
    if (entry.answer_text === text) return;
    entry.answer_text = text;
    entry.revision = (entry.revision || 0) + 1;
    persistLocal();
    updateProgressUI();
    dispatchAnswerChange(q.id);
  }

  function dispatchAnswerChange(questionId) {
    try {
      window.dispatchEvent(new CustomEvent('quiver:answer-changed', {
        detail: {
          attempt_id: attempt.id,
          question_id: questionId,
          answer: answers[questionId]
        }
      }));
    } catch (e) {}
  }

  function updateOptionSelectionUI(q) {
    var entry = answers[q.id] || { option_ids: [] };
    var rows = optionsEl.querySelectorAll('.att-option');
    rows.forEach(function (row) {
      var optId = Number(row.getAttribute('data-option-id'));
      var selected = entry.option_ids.indexOf(optId) !== -1;
      row.classList.toggle('is-selected', selected);
      var input = row.querySelector('.att-option__input');
      if (input) input.checked = selected;
      var state = row.querySelector('.att-option__state');
      if (state) state.textContent = selected ? 'Selected' : '';
    });
  }

  function updateProgressUI() {
    var answered = countAnswered();
    var unanswered = total - answered;
    if (summaryEl) {
      summaryEl.textContent = 'Question ' + (currentIndex + 1) + ' of ' + total
        + ' · ' + answered + ' answered, ' + unanswered + ' unanswered';
    }
    if (dotsEl) {
      var buttons = dotsEl.querySelectorAll('.att-dot');
      buttons.forEach(function (btn, idx) {
        var q = questions[idx];
        var ans = q ? isQuestionAnswered(q) : false;
        var cur = idx === currentIndex;
        btn.classList.toggle('is-answered', ans);
        btn.classList.toggle('is-current', cur);
        if (cur) {
          btn.setAttribute('aria-current', 'step');
        } else {
          btn.removeAttribute('aria-current');
        }
        btn.setAttribute('aria-label',
          'Question ' + (idx + 1) + ', ' + (ans ? 'answered' : 'unanswered') + (cur ? ', current' : '')
        );
      });
    }
    if (prevBtn) {
      prevBtn.disabled = currentIndex <= 0;
    }
    if (nextBtn) {
      if (currentIndex >= total - 1) {
        nextBtn.textContent = 'Review answers \u2192';
      } else {
        nextBtn.textContent = 'Next question \u2192';
      }
    }
  }

  function renderQuestion(index) {
    if (index < 0 || index >= total) return;
    currentIndex = index;
    persistLocal();

    var q = questions[currentIndex];
    if (typeEl) {
      typeEl.textContent = TYPE_LABELS[q.type] || 'QUESTION';
    }
    if (pointsEl) {
      pointsEl.textContent = formatPoints(q.points);
    }
    if (promptEl) {
      promptEl.textContent = q.prompt;
    }

    while (optionsEl.firstChild) {
      optionsEl.removeChild(optionsEl.firstChild);
    }

    var entry = answers[q.id] || { option_ids: [], answer_text: '' };

    if (q.type === 'single' || q.type === 'true_false' || q.type === 'multi') {
      var isMulti = q.type === 'multi';
      var opts = Array.isArray(q.options) ? q.options : [];
      opts.forEach(function (opt, i) {
        var selected = entry.option_ids.indexOf(Number(opt.id)) !== -1;
        var label = document.createElement('label');
        label.className = 'att-option' + (isMulti ? ' att-option--multi' : '') + (selected ? ' is-selected' : '');
        label.setAttribute('data-option-id', String(opt.id));
        label.setAttribute('data-option-index', String(i));

        var input = document.createElement('input');
        input.className = 'att-option__input';
        input.type = isMulti ? 'checkbox' : 'radio';
        input.name = 'q_' + q.id;
        input.value = String(opt.id);
        input.checked = selected;
        input.addEventListener('change', function () {
          recordChoice(q, opt.id);
        });

        var control = document.createElement('span');
        control.className = 'att-option__control';
        control.setAttribute('aria-hidden', 'true');

        var keySpan = document.createElement('span');
        keySpan.className = 'att-option__key';
        keySpan.textContent = LETTERS[i] || String(i + 1);
        keySpan.title = 'Shortcut: press ' + (i + 1) + ' or ' + (LETTERS[i] || '');

        var textSpan = document.createElement('span');
        textSpan.className = 'att-option__label';
        textSpan.textContent = opt.label;

        var stateSpan = document.createElement('span');
        stateSpan.className = 'att-option__state';
        stateSpan.textContent = selected ? 'Selected' : '';

        label.appendChild(input);
        label.appendChild(control);
        label.appendChild(keySpan);
        label.appendChild(textSpan);
        label.appendChild(stateSpan);
        optionsEl.appendChild(label);
      });
    } else if (q.type === 'short') {
      var inputShort = document.createElement('input');
      inputShort.type = 'text';
      inputShort.className = 'att-written att-written--short';
      inputShort.placeholder = 'Type your answer…';
      inputShort.value = entry.answer_text || '';
      inputShort.setAttribute('aria-label', 'Your answer');
      inputShort.addEventListener('input', function () {
        recordText(q, inputShort.value);
      });
      optionsEl.appendChild(inputShort);
    } else {
      var area = document.createElement('textarea');
      area.className = 'att-written att-written--essay';
      area.rows = 6;
      area.placeholder = 'Write your response…';
      area.value = entry.answer_text || '';
      area.setAttribute('aria-label', 'Your response');
      area.addEventListener('input', function () {
        recordText(q, area.value);
      });
      optionsEl.appendChild(area);
    }

    updateProgressUI();
  }

  // ---- Navigation events ----
  if (dotsEl) {
    dotsEl.addEventListener('click', function (ev) {
      var btn = ev.target.closest('.att-dot');
      if (!btn) return;
      var idx = parseInt(btn.getAttribute('data-index') || '0', 10);
      if (!isNaN(idx)) renderQuestion(idx);
    });
  }

  if (prevBtn) {
    prevBtn.addEventListener('click', function () {
      if (currentIndex > 0) renderQuestion(currentIndex - 1);
    });
  }

  if (nextBtn) {
    nextBtn.addEventListener('click', function () {
      if (currentIndex < total - 1) {
        renderQuestion(currentIndex + 1);
      } else if (reviewBtn) {
        reviewBtn.click();
      }
    });
  }

  if (reviewBtn) {
    reviewBtn.addEventListener('click', function () {
      // Jump to the first unanswered question if any, or dispatch review event (Day 14).
      var firstUnanswered = -1;
      for (var i = 0; i < total; i++) {
        if (!isQuestionAnswered(questions[i])) {
          firstUnanswered = i;
          break;
        }
      }
      var ev = new CustomEvent('quiver:review-requested', {
        cancelable: true,
        detail: { answered: countAnswered(), total: total, first_unanswered: firstUnanswered }
      });
      if (window.dispatchEvent(ev) && firstUnanswered !== -1) {
        renderQuestion(firstUnanswered);
      }
    });
  }

  // ---- Keyboard shortcuts: 1..9 / A..I for options, Left/Right arrows for navigation ----
  document.addEventListener('keydown', function (ev) {
    if (ev.altKey || ev.ctrlKey || ev.metaKey) return;
    var tag = ev.target && ev.target.tagName ? ev.target.tagName.toUpperCase() : '';
    var inTextField = tag === 'INPUT' && ev.target.type === 'text' || tag === 'TEXTAREA' || ev.target.isContentEditable;
    if (inTextField) return;

    var q = questions[currentIndex];
    if (!q) return;

    // Number keys 1..9 select option 1..9 on choice questions
    if (q.type === 'single' || q.type === 'true_false' || q.type === 'multi') {
      var opts = Array.isArray(q.options) ? q.options : [];
      var optIndex = -1;
      if (ev.key >= '1' && ev.key <= '9') {
        optIndex = parseInt(ev.key, 10) - 1;
      } else if (ev.key && ev.key.length === 1) {
        var upper = ev.key.toUpperCase();
        var letterPos = LETTERS.indexOf(upper);
        if (letterPos >= 0 && letterPos < opts.length) {
          optIndex = letterPos;
        }
      }
      if (optIndex >= 0 && optIndex < opts.length) {
        ev.preventDefault();
        recordChoice(q, opts[optIndex].id);
        return;
      }
    }

    // Left / Right question navigation when not inside radio/checkbox arrow cycling
    if (ev.key === 'ArrowLeft' && currentIndex > 0) {
      ev.preventDefault();
      renderQuestion(currentIndex - 1);
    } else if (ev.key === 'ArrowRight' && currentIndex < total - 1) {
      ev.preventDefault();
      renderQuestion(currentIndex + 1);
    }
  });

  // =========================================================================
  // Server-owned countdown timer (monotonic via performance.now())
  // =========================================================================
  var baseRemaining = Math.max(0, Number(attempt.remaining_seconds || 0));
  var baseMonoMs    = performance.now();

  // Thresholds already announced so we don't repeat if initial time is already below them.
  var announced = {
    600: baseRemaining <= 600,
    300: baseRemaining <= 300,
    60:  baseRemaining <= 60,
    0:   baseRemaining <= 0
  };

  function getRemainingSeconds() {
    var elapsedSec = (performance.now() - baseMonoMs) / 1000;
    return Math.max(0, Math.ceil(baseRemaining - elapsedSec));
  }

  function formatCountdown(sec) {
    sec = Math.max(0, Math.floor(sec));
    var hours = Math.floor(sec / 3600);
    var mins  = Math.floor((sec % 3600) / 60);
    var secs  = sec % 60;
    var pad = function (n) { return n < 10 ? '0' + n : String(n); };
    if (hours > 0) {
      return hours + ' : ' + pad(mins) + ' : ' + pad(secs);
    }
    return pad(mins) + ' : ' + pad(secs);
  }

  function announce(msg) {
    if (!timerLive) return;
    timerLive.textContent = msg;
  }

  function renderTimer() {
    var rem = getRemainingSeconds();
    if (timerDigits) {
      timerDigits.textContent = formatCountdown(rem);
    }
    if (timerBox && timerLabel) {
      var isFinal   = rem > 0 && rem <= 60;
      var isWarning = rem > 60 && rem <= 300;
      timerBox.classList.toggle('is-final', isFinal || rem === 0);
      timerBox.classList.toggle('is-warning', isWarning);
      if (rem === 0) {
        timerLabel.textContent = 'TIME UP';
      } else if (isFinal) {
        timerLabel.textContent = 'FINAL MINUTE';
      } else {
        timerLabel.textContent = 'TIME LEFT';
      }
    }

    if (rem <= 600 && !announced[600]) {
      announced[600] = true;
      announce('10 minutes remaining.');
    }
    if (rem <= 300 && !announced[300]) {
      announced[300] = true;
      announce('5 minutes remaining.');
    }
    if (rem <= 60 && !announced[60]) {
      announced[60] = true;
      announce('Final minute. 1 minute remaining.');
    }
    if (rem <= 0 && !announced[0]) {
      announced[0] = true;
      announce('Time is up.');
      try {
        window.dispatchEvent(new CustomEvent('quiver:timer-expired', { detail: { attempt_id: attempt.id } }));
      } catch (e) {}
    }
  }

  function resyncFromServer(remainingSeconds) {
    var n = Number(remainingSeconds);
    if (isNaN(n) || n < 0) return;
    baseRemaining = n;
    baseMonoMs    = performance.now();
    renderTimer();
  }

  function fetchServerTimer() {
    if (!attempt.id) return;
    fetch('/api/timer.php?attempt_id=' + encodeURIComponent(String(attempt.id)), {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      cache: 'no-store'
    })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (data) {
        if (data && typeof data.remaining_seconds === 'number') {
          resyncFromServer(data.remaining_seconds);
        }
      })
      .catch(function () {
        // Offline or unreachable: keep monotonic countdown running locally.
      });
  }

  // Expose timer API so Day 13 autosave and Day 14 submit can resync on every server response.
  window.quiverTimer = {
    resync: resyncFromServer,
    syncNow: fetchServerTimer,
    getRemainingSeconds: getRemainingSeconds
  };

  window.addEventListener('focus', fetchServerTimer);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      fetchServerTimer();
    }
  });

  // Initial render and 250ms tick (smooth second boundary using performance.now()).
  renderQuestion(currentIndex);
  renderTimer();
  setInterval(renderTimer, 250);
})();
