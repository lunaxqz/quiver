/**
 * QUIVER Student Attempt Screen (Day 12, Day 13 & Day 14).
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
 *      - Color shift & "Final minute" label at <= 60 seconds; auto-submits at 00:00.
 *  5. Offline-resilient Autosave & 20s Heartbeat (Day 13):
 *      - Answers mirrored in sessionStorage with monotonic revision counter per question.
 *      - Queue sends any answer whose local revision > ackedRevision to /api/autosave.php.
 *      - Exponential backoff retry on network/5xx failure: 1s, 2s, 4s, 8s, capped at 15s; immediate flush on `online`.
 *      - 20-second heartbeat keeps `last_seen_at` fresh even while the student is reading a long prompt.
 *      - Honest save indicator (#att-saved & #rev-saved) never reports "Saved" until confirmed by the server.
 *  6. Review & Submit (Day 14):
 *      - Single "Review answers" action on the last question (center button hidden on last question so there are never two Review answers buttons).
 *      - Full "Review your attempt" summary screen listing every question's Answered / Unanswered status, jump-to-question links,
 *        unanswered warning copy, confirmation checkbox, and idempotent POST to /api/submit.php redirecting to /student/receipt.php.
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

  var attRoot   = document.querySelector('.att');
  var csrfToken = attRoot ? (attRoot.getAttribute('data-csrf') || '') : '';

  var STORAGE_KEY = 'quiver_attempt_' + attempt.id + '_answers';
  var INDEX_KEY   = 'quiver_attempt_' + attempt.id + '_index';

  // Merge server-stored responses with any newer sessionStorage draft.
  // ackedRevisions tracks the highest revision confirmed saved by the server.
  var answers        = {};
  var ackedRevisions = {};
  var serverResponses = payload.responses || {};

  Object.keys(serverResponses).forEach(function (qid) {
    var r = serverResponses[qid];
    var rev = Number(r.revision || 0);
    ackedRevisions[qid] = rev;
    answers[qid] = {
      option_ids: Array.isArray(r.option_ids) ? r.option_ids.map(Number) : [],
      answer_text: typeof r.answer_text === 'string' ? r.answer_text : '',
      revision: rev
    };
  });

  try {
    var cached = sessionStorage.getItem(STORAGE_KEY);
    if (cached) {
      var parsed = JSON.parse(cached);
      Object.keys(parsed).forEach(function (qid) {
        var c = parsed[qid];
        if (!c || typeof c !== 'object') return;
        var cRev = Number(c.revision || 0);
        var s = answers[qid];
        var sRev = s ? Number(s.revision || 0) : 0;
        if (!s || cRev > sRev) {
          answers[qid] = {
            option_ids: Array.isArray(c.option_ids) ? c.option_ids.map(Number) : [],
            answer_text: typeof c.answer_text === 'string' ? c.answer_text : '',
            revision: cRev
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

  function clearLocalDraft() {
    try {
      sessionStorage.removeItem(STORAGE_KEY);
      sessionStorage.removeItem(INDEX_KEY);
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

  function getUnansweredNumbers() {
    var nums = [];
    for (var i = 0; i < total; i++) {
      if (!isQuestionAnswered(questions[i])) {
        nums.push(i + 1);
      }
    }
    return nums;
  }

  // ---- DOM references ----
  var questionViewEl     = document.getElementById('att-question-view');
  var reviewViewEl       = document.getElementById('att-review-view');
  var summaryEl          = document.getElementById('att-summary');
  var dotsEl             = document.getElementById('att-dots');
  var typeEl             = document.getElementById('att-type');
  var pointsEl           = document.getElementById('att-points');
  var promptEl           = document.getElementById('att-prompt');
  var optionsEl          = document.getElementById('att-options');
  var prevBtn            = document.getElementById('att-prev');
  var nextBtn            = document.getElementById('att-next');
  var reviewBtn          = document.getElementById('att-review');
  var timerBox           = document.getElementById('att-timer-box');
  var timerLabel         = document.getElementById('att-timer-label');
  var timerDigits        = document.getElementById('att-timer-digits');
  var timerLive          = document.getElementById('att-timer-live');
  var savedBox           = document.getElementById('att-saved');
  var savedText          = document.getElementById('att-saved-text');
  var savedCheck         = savedBox ? savedBox.querySelector('.att-saved__check') : null;

  // Review view DOM references
  var revAnsweredCount   = document.getElementById('rev-answered-count');
  var revUnansweredCount = document.getElementById('rev-unanswered-count');
  var revTimerDigits     = document.getElementById('rev-timer-digits');
  var revGrid            = document.getElementById('rev-grid');
  var revSavedBox        = document.getElementById('rev-saved');
  var revSavedText       = document.getElementById('rev-saved-text');
  var revSavedCheck      = revSavedBox ? revSavedBox.querySelector('.att-saved__check') : null;
  var revConfirmBody     = document.getElementById('rev-confirm-body');
  var revConfirmCheck    = document.getElementById('rev-confirm-checkbox');
  var revConfirmLabel    = document.getElementById('rev-confirm-label');
  var revReturnBtn       = document.getElementById('rev-return-btn');
  var revSubmitBtn       = document.getElementById('rev-submit-btn');

  var inReviewMode = false;
  var isSubmitting = false;

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
  var NUMBER_WORDS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten'];

  function numberWord(n) {
    return n >= 0 && n < NUMBER_WORDS.length ? NUMBER_WORDS[n] : String(n);
  }

  function formatQuestionList(nums) {
    if (nums.length === 0) return '';
    if (nums.length === 1) return 'Question ' + nums[0];
    if (nums.length === 2) return 'Questions ' + nums[0] + ' and ' + nums[1];
    var head = nums.slice(0, nums.length - 1).join(', ');
    return 'Questions ' + head + ' and ' + nums[nums.length - 1];
  }

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
    dispatchAnswerChange(q.id, false);
  }

  function recordText(q, text) {
    var entry = ensureEntry(q.id);
    if (entry.answer_text === text) return;
    entry.answer_text = text;
    entry.revision = (entry.revision || 0) + 1;
    persistLocal();
    updateProgressUI();
    dispatchAnswerChange(q.id, true);
  }

  function dispatchAnswerChange(questionId, debounce) {
    scheduleAutosave(debounce ? 350 : 0);
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
    var isLastQuestion = currentIndex >= total - 1;
    // Hide the middle "Review answers" button on the last question because the primary right-hand button
    // already becomes "Review answers ->", preventing duplicate Review buttons.
    if (reviewBtn) {
      reviewBtn.hidden = isLastQuestion;
    }
    if (nextBtn) {
      if (isLastQuestion) {
        nextBtn.textContent = 'Review answers \u2192';
      } else {
        nextBtn.textContent = 'Next question \u2192';
      }
    }
    if (inReviewMode) {
      renderReviewSummary();
    }
  }

  function renderQuestion(index) {
    if (index < 0 || index >= total) return;
    currentIndex = index;
    persistLocal();

    if (inReviewMode) {
      inReviewMode = false;
      if (reviewViewEl) reviewViewEl.hidden = true;
      if (questionViewEl) questionViewEl.hidden = false;
    }

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

  // =========================================================================
  // Day 14: Review & Submit View
  // =========================================================================
  function createStatusMarkNode(state, labelText) {
    var wrap = document.createElement('span');
    wrap.className = 'mark';
    wrap.setAttribute('data-state', state);

    var ring = document.createElement('span');
    ring.className = 'mark__ring';
    ring.setAttribute('aria-hidden', 'true');

    var lbl = document.createElement('span');
    lbl.className = 'mark__label';
    lbl.textContent = labelText;

    wrap.appendChild(ring);
    wrap.appendChild(lbl);
    return wrap;
  }

  function renderReviewSummary() {
    var answered   = countAnswered();
    var unanswered = total - answered;
    var unansNums  = getUnansweredNumbers();

    if (revAnsweredCount) {
      revAnsweredCount.textContent = answered + ' answered';
    }
    if (revUnansweredCount) {
      revUnansweredCount.textContent = unanswered + ' unanswered';
    }

    // Build two-column question status list (first half in left column, second half in right column)
    if (revGrid) {
      while (revGrid.firstChild) {
        revGrid.removeChild(revGrid.firstChild);
      }
      var half = Math.ceil(total / 2);
      var leftCol = document.createElement('div');
      leftCol.className = 'rev-col';
      var rightCol = document.createElement('div');
      rightCol.className = 'rev-col';

      questions.forEach(function (q, idx) {
        var isAns = isQuestionAnswered(q);
        var row = document.createElement('button');
        row.type = 'button';
        row.className = 'rev-row';
        row.setAttribute('data-index', String(idx));

        var qLabel = document.createElement('span');
        qLabel.className = 'rev-row__label';
        qLabel.textContent = 'Question ' + (idx + 1) + ' \u2197';

        var mark = createStatusMarkNode(isAns ? 'complete' : 'neutral', isAns ? 'Answered' : 'Unanswered');

        row.appendChild(qLabel);
        row.appendChild(mark);
        row.addEventListener('click', function () {
          renderQuestion(idx);
          window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        if (idx < half) {
          leftCol.appendChild(row);
        } else {
          rightCol.appendChild(row);
        }
      });

      revGrid.appendChild(leftCol);
      if (total > 1) {
        revGrid.appendChild(rightCol);
      }
    }

    var maxAtt = Number(attempt.max_attempts || 1);
    var attemptPhrase = maxAtt === 1 ? 'your only attempt' : ('Attempt ' + attempt.attempt_no + ' of ' + maxAtt);

    if (revConfirmBody) {
      if (unansNums.length === 0) {
        revConfirmBody.textContent = 'All ' + total + ' questions are answered. Submitting ends '
          + attemptPhrase + '. You cannot change your answers or restart afterward.';
      } else if (unansNums.length === 1) {
        revConfirmBody.textContent = formatQuestionList(unansNums) + ' is unanswered and will receive zero points. Submitting ends '
          + attemptPhrase + '. You cannot change your answers or restart afterward.';
      } else {
        revConfirmBody.textContent = formatQuestionList(unansNums) + ' are unanswered and will receive zero points. Submitting ends '
          + attemptPhrase + '. You cannot change your answers or restart afterward.';
      }
    }

    if (revConfirmLabel) {
      if (unansNums.length === 0) {
        revConfirmLabel.textContent = 'I understand that my attempt will be final.';
      } else if (unansNums.length === 1) {
        revConfirmLabel.textContent = 'I understand that my attempt will be final, including the unanswered question.';
      } else {
        revConfirmLabel.textContent = 'I understand that my attempt will be final, including the '
          + numberWord(unansNums.length) + ' unanswered questions.';
      }
    }

    if (revReturnBtn) {
      var targetQ = unansNums.length > 0 ? unansNums[0] : (currentIndex + 1);
      revReturnBtn.textContent = 'Return to question ' + targetQ;
      revReturnBtn.setAttribute('data-return-index', String(targetQ - 1));
    }

    if (revSubmitBtn && revConfirmCheck) {
      revSubmitBtn.disabled = !revConfirmCheck.checked || isSubmitting;
    }
  }

  function openReviewScreen() {
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
    if (!window.dispatchEvent(ev)) {
      return;
    }

    inReviewMode = true;
    if (revConfirmCheck) {
      revConfirmCheck.checked = false;
    }
    renderReviewSummary();
    if (questionViewEl) questionViewEl.hidden = true;
    if (reviewViewEl) reviewViewEl.hidden = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });

    // Flush any pending unsaved changes when entering the review screen.
    if (hasDirtyAnswers()) {
      flushAutosave(false);
    }
  }

  function submitFinalAttempt(reason) {
    if (isSubmitting || !attempt.id) return;
    isSubmitting = true;
    if (revSubmitBtn) {
      revSubmitBtn.disabled = true;
      revSubmitBtn.textContent = 'Submitting…';
    }

    // Send all current answers with the final submit so nothing is lost even if an autosave was still queued.
    var allAnswersPayload = {};
    Object.keys(answers).forEach(function (qid) {
      var a = answers[qid];
      if (!a) return;
      allAnswersPayload[qid] = {
        question_id: Number(qid),
        option_ids: Array.isArray(a.option_ids) ? a.option_ids.slice() : [],
        answer_text: typeof a.answer_text === 'string' ? a.answer_text : '',
        revision: Math.max(1, Number(a.revision || 1))
      };
    });

    fetch('/api/submit.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify({
        attempt_id: attempt.id,
        answers: allAnswersPayload,
        reason: reason || 'student',
        _csrf: csrfToken
      }),
      cache: 'no-store'
    })
      .then(function (res) {
        return res.json().then(function (data) {
          return { status: res.status, ok: res.ok, data: data };
        });
      })
      .then(function (result) {
        var data = result.data || {};
        if (result.ok && data.ok) {
          attemptClosed = true;
          clearRetryTimer();
          clearLocalDraft();
          window.location.href = data.redirect_url || ('/student/receipt.php?id=' + encodeURIComponent(String(attempt.id)));
          return;
        }
        isSubmitting = false;
        if (revSubmitBtn) {
          revSubmitBtn.disabled = !(revConfirmCheck && revConfirmCheck.checked);
          revSubmitBtn.textContent = 'Submit final attempt';
        }
      })
      .catch(function () {
        isSubmitting = false;
        if (revSubmitBtn) {
          revSubmitBtn.disabled = !(revConfirmCheck && revConfirmCheck.checked);
          revSubmitBtn.textContent = 'Submit final attempt';
        }
        setSaveIndicator('offline', 'Offline · connection needed to submit final attempt');
      });
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
      } else {
        openReviewScreen();
      }
    });
  }

  if (reviewBtn) {
    reviewBtn.addEventListener('click', function () {
      openReviewScreen();
    });
  }

  if (revConfirmCheck) {
    revConfirmCheck.addEventListener('change', function () {
      if (revSubmitBtn) {
        revSubmitBtn.disabled = !revConfirmCheck.checked || isSubmitting;
      }
    });
  }

  if (revReturnBtn) {
    revReturnBtn.addEventListener('click', function () {
      var idx = parseInt(revReturnBtn.getAttribute('data-return-index') || '0', 10);
      renderQuestion(isNaN(idx) ? 0 : idx);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  if (revSubmitBtn) {
    revSubmitBtn.addEventListener('click', function () {
      if (revConfirmCheck && !revConfirmCheck.checked) return;
      submitFinalAttempt('student');
    });
  }

  // ---- Keyboard shortcuts: 1..9 / A..I for options, Left/Right arrows for navigation ----
  document.addEventListener('keydown', function (ev) {
    if (inReviewMode) return;
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
    0:   false
  };

  function getRemainingSeconds() {
    var elapsedSec = (performance.now() - baseMonoMs) / 1000;
    return Math.max(0, Math.ceil(baseRemaining - elapsedSec));
  }

  function formatCountdown(sec, compact) {
    sec = Math.max(0, Math.floor(sec));
    var hours = Math.floor(sec / 3600);
    var mins  = Math.floor((sec % 3600) / 60);
    var secs  = sec % 60;
    var pad = function (n) { return n < 10 ? '0' + n : String(n); };
    var sep = compact ? ':' : ' : ';
    if (hours > 0) {
      return hours + sep + pad(mins) + sep + pad(secs);
    }
    return pad(mins) + sep + pad(secs);
  }

  function announce(msg) {
    if (!timerLive) return;
    timerLive.textContent = msg;
  }

  function renderTimer() {
    var rem = getRemainingSeconds();
    if (timerDigits) {
      timerDigits.textContent = formatCountdown(rem, false);
    }
    if (revTimerDigits) {
      revTimerDigits.textContent = formatCountdown(rem, true);
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
      announce('Time is up. Submitting your saved work.');
      try {
        window.dispatchEvent(new CustomEvent('quiver:timer-expired', { detail: { attempt_id: attempt.id } }));
      } catch (e) {}
      submitFinalAttempt('timeout');
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
    if (!attempt.id || attemptClosed) return;
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

  // =========================================================================
  // Day 13: Offline-Resilient Autosave Queue & 20s Heartbeat
  // =========================================================================
  var BACKOFF_DELAYS_MS = [1000, 2000, 4000, 8000, 15000];
  var HEARTBEAT_MS      = 20000;

  var inFlight        = false;
  var retryAttempt    = 0;
  var retryTimer      = null;
  var debounceTimer   = null;
  var lastSavedManila = typeof attempt.saved_manila === 'string' ? attempt.saved_manila : '';
  var attemptClosed   = attempt.status && attempt.status !== 'in_progress';

  function getDirtyAnswers() {
    var dirty = {};
    var count = 0;
    Object.keys(answers).forEach(function (qid) {
      var a = answers[qid];
      if (!a) return;
      var rev = Number(a.revision || 0);
      var ack = Number(ackedRevisions[qid] || 0);
      if (rev > ack) {
        dirty[qid] = {
          question_id: Number(qid),
          option_ids: Array.isArray(a.option_ids) ? a.option_ids.slice() : [],
          answer_text: typeof a.answer_text === 'string' ? a.answer_text : '',
          revision: rev
        };
        count++;
      }
    });
    return { map: dirty, count: count };
  }

  function hasDirtyAnswers() {
    return getDirtyAnswers().count > 0;
  }

  function applySaveBoxState(box, checkEl, textEl, state, detailText) {
    if (!box || !textEl) return;
    box.classList.toggle('is-saving', state === 'saving');
    box.classList.toggle('is-offline', state === 'offline');
    if (checkEl) {
      if (state === 'offline') {
        checkEl.textContent = '!';
      } else if (state === 'saving') {
        checkEl.textContent = '…';
      } else {
        checkEl.textContent = '✓';
      }
    }
    textEl.textContent = detailText;
  }

  function setSaveIndicator(state, detailText) {
    applySaveBoxState(savedBox, savedCheck, savedText, state, detailText);
    var revText = detailText.indexOf('Saved · ') === 0
      ? 'Last saved · ' + detailText.slice('Saved · '.length)
      : detailText;
    applySaveBoxState(revSavedBox, revSavedCheck, revSavedText, state, revText);
  }

  function updateSaveIndicatorDefault() {
    if (hasDirtyAnswers()) {
      if (typeof navigator !== 'undefined' && navigator.onLine === false) {
        setSaveIndicator('offline', 'Offline · saved on this device, retrying…');
      } else {
        setSaveIndicator('saving', 'Saving…');
      }
      return;
    }
    if (lastSavedManila !== '') {
      setSaveIndicator('saved', 'Saved · ' + lastSavedManila + ' Asia/Manila');
    } else {
      setSaveIndicator('idle', 'Not yet saved');
    }
  }

  function clearRetryTimer() {
    if (retryTimer !== null) {
      clearTimeout(retryTimer);
      retryTimer = null;
    }
  }

  function scheduleRetry() {
    clearRetryTimer();
    if (attemptClosed) return;
    var idx = Math.min(retryAttempt, BACKOFF_DELAYS_MS.length - 1);
    var delayMs = BACKOFF_DELAYS_MS[idx];
    retryAttempt++;
    var delaySec = Math.round(delayMs / 1000);
    if (hasDirtyAnswers()) {
      setSaveIndicator('offline', 'Offline · saved on this device, retrying in ' + delaySec + 's…');
    } else if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      setSaveIndicator('offline', 'Offline · answers kept on this device');
    }
    retryTimer = setTimeout(function () {
      retryTimer = null;
      flushAutosave(true);
    }, delayMs);
  }

  function scheduleAutosave(delayMs) {
    if (attemptClosed) return;
    updateSaveIndicatorDefault();
    if (debounceTimer !== null) {
      clearTimeout(debounceTimer);
      debounceTimer = null;
    }
    if (!delayMs || delayMs <= 0) {
      flushAutosave(false);
    } else {
      debounceTimer = setTimeout(function () {
        debounceTimer = null;
        flushAutosave(false);
      }, delayMs);
    }
  }

  function flushAutosave(isHeartbeatOrRetry) {
    if (!attempt.id || attemptClosed) {
      return Promise.resolve(false);
    }
    if (inFlight) {
      return Promise.resolve(false);
    }

    var dirty = getDirtyAnswers();
    if (dirty.count === 0 && !isHeartbeatOrRetry) {
      updateSaveIndicatorDefault();
      return Promise.resolve(true);
    }

    clearRetryTimer();
    inFlight = true;
    if (dirty.count > 0) {
      setSaveIndicator('saving', 'Saving…');
    }

    var bodyPayload = {
      attempt_id: attempt.id,
      answers: dirty.map,
      _csrf: csrfToken
    };

    return fetch('/api/autosave.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify(bodyPayload),
      cache: 'no-store'
    })
      .then(function (res) {
        return res.json().then(function (data) {
          return { status: res.status, ok: res.ok, data: data };
        });
      })
      .then(function (result) {
        inFlight = false;
        var data = result.data || {};

        if (typeof data.remaining_seconds === 'number') {
          resyncFromServer(data.remaining_seconds);
        }

        if (result.status === 409 || data.status === 'submitted' || data.status === 'graded' || data.status === 'voided') {
          attemptClosed = true;
          clearRetryTimer();
          window.location.href = '/student/receipt.php?id=' + encodeURIComponent(String(attempt.id));
          return false;
        }

        if (!result.ok || !data.ok) {
          scheduleRetry();
          return false;
        }

        // Success: reset backoff counter and record acknowledged revisions.
        retryAttempt = 0;
        var savedRevs = data.saved_revisions || {};
        Object.keys(dirty.map).forEach(function (qid) {
          var sentRev = dirty.map[qid].revision;
          var serverRev = Number(savedRevs[qid] || sentRev);
          var prevAck = Number(ackedRevisions[qid] || 0);
          ackedRevisions[qid] = Math.max(prevAck, sentRev, serverRev);
        });
        Object.keys(savedRevs).forEach(function (qid) {
          var sr = Number(savedRevs[qid] || 0);
          var prevAck = Number(ackedRevisions[qid] || 0);
          if (sr > prevAck) {
            ackedRevisions[qid] = sr;
          }
        });

        if (typeof data.saved_manila === 'string' && data.saved_manila !== '') {
          lastSavedManila = data.saved_manila;
        }

        updateSaveIndicatorDefault();

        try {
          window.dispatchEvent(new CustomEvent('quiver:autosaved', {
            detail: {
              attempt_id: attempt.id,
              saved_manila: lastSavedManila,
              saved_revisions: ackedRevisions
            }
          }));
        } catch (e) {}

        // If the student changed another answer while the request was in flight, flush immediately.
        if (hasDirtyAnswers()) {
          flushAutosave(false);
        }
        return true;
      })
      .catch(function () {
        inFlight = false;
        scheduleRetry();
        return false;
      });
  }

  // Expose autosave & review API for Day 14 review/submit and testing.
  window.quiverAutosave = {
    flush: function () { return flushAutosave(true); },
    hasUnsaved: hasDirtyAnswers,
    getAnswers: function () { return answers; },
    getAckedRevisions: function () { return ackedRevisions; },
    openReview: openReviewScreen,
    submitFinal: submitFinalAttempt
  };

  // Reconnect & visibility listeners
  window.addEventListener('online', function () {
    retryAttempt = 0;
    flushAutosave(true);
  });

  window.addEventListener('offline', function () {
    if (hasDirtyAnswers()) {
      setSaveIndicator('offline', 'Offline · saved on this device, retrying…');
    } else {
      setSaveIndicator('offline', 'Offline · answers kept on this device');
    }
  });

  window.addEventListener('focus', function () {
    fetchServerTimer();
    if (hasDirtyAnswers()) {
      retryAttempt = 0;
      flushAutosave(false);
    }
  });

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      fetchServerTimer();
      if (hasDirtyAnswers()) {
        retryAttempt = 0;
        flushAutosave(false);
      }
    }
  });

  // 20-second heartbeat (also flushes any pending dirty answers if not currently backing off).
  setInterval(function () {
    if (attemptClosed) return;
    if (retryTimer !== null && retryAttempt > 0) {
      // Backoff timer is already handling retries while offline.
      return;
    }
    flushAutosave(true);
  }, HEARTBEAT_MS);

  // Initial render and 250ms tick (smooth second boundary using performance.now()).
  renderQuestion(currentIndex);
  renderTimer();
  setInterval(renderTimer, 250);

  // If sessionStorage held unsaved answers from before a reload/offline drop, flush them immediately on load!
  if (hasDirtyAnswers()) {
    flushAutosave(false);
  } else {
    updateSaveIndicatorDefault();
  }
})();
