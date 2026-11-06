/**
 * QUIVER Teacher Grading Client (Day 15)
 *
 * Powers:
 *  - Question-by-question triage stack & Whole-paper mode toggle
 *  - Blind mode toggle (default ON: shows "ANONYMOUS RESPONSE · PAPER K7"; when OFF: shows student name & username)
 *  - Keyboard shortcuts:
 *      - ArrowLeft / ArrowRight, j / k (when not focused in an input) : Previous / Next response
 *      - Alt + ArrowLeft / ArrowRight : Previous / Next response (even inside inputs)
 *      - f : Full points (auto-saves & advances)
 *      - h : Half points (auto-saves & advances)
 *      - 0 or z : Zero points (auto-saves & advances)
 *      - p : Focus Points awarded input
 *      - n : Focus Feedback to student textarea
 *      - u : Undo last mark (calls /api/grade.php with action='undo', creating a new grade_events row)
 *      - Ctrl + Enter : Save & next
 *      - ? : Toggle keyboard shortcuts dialog
 */
(function () {
  'use strict';

  var quizSelect = document.querySelector('[data-autosubmit-quiz]');
  if (quizSelect) {
    quizSelect.addEventListener('change', function () {
      if (quizSelect.form) {
        quizSelect.form.submit();
      }
    });
  }

  var dataEl = document.getElementById('grading-initial-data');
  if (!dataEl) {
    return;
  }

  var parsed;
  try {
    parsed = JSON.parse(dataEl.textContent || '{}');
  } catch (e) {
    return;
  }

  var csrfToken = parsed.csrf_token || '';
  var workspace = parsed.workspace || null;
  if (!workspace || !workspace.quiz) {
    return;
  }

  // State
  var mode = 'stack'; // 'stack' | 'paper'
  var blindMode = true;
  var stackFilterQuestionId = 0; // 0 = all written questions
  var stackIndex = 0;
  var paperIndex = 0;
  var paperQuestionIndex = 0;
  var isSaving = false;

  // Undo history stack: stores { response_id, prev_points, prev_feedback, mode, stackIndex, paperIndex, paperQuestionIndex }
  var undoStack = [];

  // Index all response items by response_id so saving updates both stack_items and papers in memory
  var responsesById = {};
  var papersById = {};

  (workspace.papers || []).forEach(function (p) {
    papersById[p.attempt_id] = p;
    (p.items || []).forEach(function (it) {
      responsesById[it.response_id] = it;
    });
  });

  // Replace stack_items entries with references to the shared response objects in responsesById
  var allStackItems = (workspace.stack_items || []).map(function (it) {
    return responsesById[it.response_id] || it;
  });

  // DOM Elements
  var tabStack = document.getElementById('tab-stack');
  var tabPaper = document.getElementById('tab-paper');
  var btnBlindToggle = document.getElementById('btn-blind-toggle');
  var blindToggleLabel = document.getElementById('blind-toggle-label');
  var btnShortcutsHelp = document.getElementById('btn-shortcuts-help');
  var shortcutsModal = document.getElementById('shortcuts-modal');
  var btnCloseShortcuts = document.getElementById('btn-close-shortcuts');
  var btnDismissShortcuts = document.getElementById('btn-dismiss-shortcuts');

  var toolbarTitle = document.getElementById('toolbar-context-title');
  var toolbarSub = document.getElementById('toolbar-context-sub');
  var selectStackQuestion = document.getElementById('select-stack-question');
  var selectWholePaper = document.getElementById('select-whole-paper');
  var btnPrev = document.getElementById('btn-prev-item');
  var btnNext = document.getElementById('btn-next-item');
  var btnUndo = document.getElementById('btn-undo-mark');

  var hdrPendingCount = document.getElementById('hdr-pending-count');
  var hdrStatusMark = document.getElementById('hdr-status-mark');
  var navGradingBadge = document.getElementById('nav-grading-badge');
  var liveRegion = document.getElementById('grading-live-region');

  var noWrittenState = document.getElementById('grading-no-written-state');
  var btnSwitchToPaper = document.getElementById('btn-switch-to-paper');
  var mainGrid = document.getElementById('grading-main-grid');

  var paperStrip = document.getElementById('paper-question-strip');
  var paperStripStudent = document.getElementById('paper-strip-student');
  var paperStripScore = document.getElementById('paper-strip-score');
  var paperQuestionPills = document.getElementById('paper-question-pills');

  var cardPromptText = document.getElementById('card-prompt-text');
  var cardResponseEyebrow = document.getElementById('card-response-eyebrow');
  var cardResponseType = document.getElementById('card-response-type');
  var cardResponseBody = document.getElementById('card-response-body');
  var cardAutoBanner = document.getElementById('card-auto-banner');
  var cardResponseFoot = document.getElementById('card-response-foot');
  var cardRubricBody = document.getElementById('card-rubric-body');

  var gradingForm = document.getElementById('grading-form');
  var formResponseId = document.getElementById('form-response-id');
  var btnScoreFull = document.getElementById('btn-score-full');
  var btnScoreHalf = document.getElementById('btn-score-half');
  var btnScoreZero = document.getElementById('btn-score-zero');
  var lblPointsAwarded = document.getElementById('lbl-points-awarded');
  var inputPoints = document.getElementById('input-points-awarded');
  var hintPointsRange = document.getElementById('hint-points-range');
  var errPoints = document.getElementById('err-points-awarded');
  var inputFeedback = document.getElementById('input-feedback');
  var saveStateEl = document.getElementById('grading-save-state');
  var btnSaveNext = document.getElementById('btn-save-next');

  function fmtPoints(num) {
    if (num === null || num === undefined || isNaN(num)) {
      return '0';
    }
    var n = Math.round(Number(num) * 100) / 100;
    return String(n);
  }

  function halfPoints(maxPts) {
    return Math.round((Number(maxPts) / 2) * 100) / 100;
  }

  function getFilteredStackItems() {
    if (!stackFilterQuestionId || stackFilterQuestionId <= 0) {
      return allStackItems;
    }
    return allStackItems.filter(function (it) {
      return it.question_id === stackFilterQuestionId;
    });
  }

  function getCurrentItem() {
    if (mode === 'stack') {
      var list = getFilteredStackItems();
      if (list.length === 0) {
        return null;
      }
      if (stackIndex >= list.length) {
        stackIndex = list.length - 1;
      }
      if (stackIndex < 0) {
        stackIndex = 0;
      }
      return list[stackIndex] || null;
    } else {
      var papers = workspace.papers || [];
      if (papers.length === 0) {
        return null;
      }
      if (paperIndex >= papers.length) {
        paperIndex = papers.length - 1;
      }
      if (paperIndex < 0) {
        paperIndex = 0;
      }
      var paper = papers[paperIndex];
      var items = paper.items || [];
      if (items.length === 0) {
        return null;
      }
      if (paperQuestionIndex >= items.length) {
        paperQuestionIndex = items.length - 1;
      }
      if (paperQuestionIndex < 0) {
        paperQuestionIndex = 0;
      }
      return items[paperQuestionIndex] || null;
    }
  }

  function populateSelectors() {
    // Question filter for stack mode
    if (selectStackQuestion) {
      while (selectStackQuestion.options.length > 1) {
        selectStackQuestion.remove(1);
      }
      var writtenQs = (workspace.questions || []).filter(function (q) {
        return q.is_written;
      });
      if (writtenQs.length > 1) {
        writtenQs.forEach(function (q) {
          var opt = document.createElement('option');
          opt.value = String(q.id);
          opt.textContent = 'Question ' + q.position + ' · ' + q.type_label + ' (' + fmtPoints(q.points) + ' pts)';
          selectStackQuestion.appendChild(opt);
        });
      }
    }

    // Paper selector for whole-paper mode
    if (selectWholePaper) {
      selectWholePaper.textContent = '';
      (workspace.papers || []).forEach(function (p, idx) {
        var opt = document.createElement('option');
        opt.value = String(idx);
        var who = blindMode ? p.paper_code : (p.student_name + ' (' + p.student_username + ')');
        var statusTxt = p.pending_count > 0 ? (p.pending_count + ' pending') : (fmtPoints(p.score) + ' / ' + fmtPoints(workspace.quiz.total_points) + ' pts');
        opt.textContent = who + ' · ' + statusTxt;
        selectWholePaper.appendChild(opt);
      });
      selectWholePaper.value = String(paperIndex);
    }
  }

  function updateHeaderCounts(pendingWrittenCount) {
    workspace.quiz.pending_written_count = pendingWrittenCount;
    if (hdrPendingCount) {
      hdrPendingCount.textContent = pendingWrittenCount + ' written response' + (pendingWrittenCount === 1 ? '' : 's') + ' pending';
    }
    if (hdrStatusMark) {
      var markSpan = document.createElement('span');
      markSpan.className = 'mark';
      markSpan.setAttribute('data-state', pendingWrittenCount > 0 ? 'attention' : 'complete');
      var ring = document.createElement('span');
      ring.className = 'mark__ring';
      ring.setAttribute('aria-hidden', 'true');
      var lbl = document.createElement('span');
      lbl.className = 'mark__label';
      lbl.textContent = pendingWrittenCount > 0 ? 'Needs grading' : 'All graded';
      markSpan.appendChild(ring);
      markSpan.appendChild(lbl);
      hdrStatusMark.textContent = '';
      hdrStatusMark.appendChild(markSpan);
    }
    if (navGradingBadge) {
      var currentBadge = parseInt(navGradingBadge.textContent || '0', 10);
      if (!isNaN(currentBadge)) {
        // Recompute approximately or hide if 0
        if (pendingWrittenCount === 0 && allStackItems.length > 0) {
          navGradingBadge.textContent = String(Math.max(0, pendingWrittenCount));
          if (pendingWrittenCount === 0) {
            navGradingBadge.hidden = true;
          }
        }
      }
    }
  }

  function updateQuickPresetHighlights(item) {
    if (!item || !inputPoints) {
      return;
    }
    var raw = inputPoints.value.trim();
    var val = raw === '' ? null : Number(raw);
    var maxPts = Number(item.max_points);
    var halfPts = halfPoints(maxPts);

    btnScoreFull.classList.toggle('is-selected', val !== null && Math.abs(val - maxPts) < 0.001);
    btnScoreHalf.classList.toggle('is-selected', val !== null && Math.abs(val - halfPts) < 0.001);
    btnScoreZero.classList.toggle('is-selected', val !== null && Math.abs(val - 0) < 0.001);
  }

  function renderPaperStrip() {
    if (!paperStrip || mode !== 'paper') {
      if (paperStrip) {
        paperStrip.hidden = true;
      }
      return;
    }
    var papers = workspace.papers || [];
    if (papers.length === 0) {
      paperStrip.hidden = true;
      return;
    }
    paperStrip.hidden = false;
    var paper = papers[paperIndex];
    var who = blindMode ? ('ANONYMOUS · ' + paper.paper_code) : (paper.student_name + ' · ' + paper.student_username);
    paperStripStudent.textContent = who;
    paperStripScore.textContent = 'Score: ' + fmtPoints(paper.score) + ' / ' + fmtPoints(workspace.quiz.total_points) + ' pts (' + (paper.status === 'graded' ? 'Graded' : 'Needs grading') + ')';

    paperQuestionPills.textContent = '';
    (paper.items || []).forEach(function (it, qIdx) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'paper-q-pill';
      if (qIdx === paperQuestionIndex) {
        btn.classList.add('is-active');
      }
      if (it.is_pending) {
        btn.classList.add('is-pending');
      }
      var ptsStr = it.points_awarded !== null ? fmtPoints(it.points_awarded) : '—';
      btn.textContent = 'Q' + it.question_pos + ' (' + ptsStr + '/' + fmtPoints(it.max_points) + ')';
      btn.addEventListener('click', function () {
        paperQuestionIndex = qIdx;
        render();
      });
      paperQuestionPills.appendChild(btn);
    });
  }

  function render() {
    // Update tabs & blind button
    if (tabStack && tabPaper) {
      tabStack.classList.toggle('is-active', mode === 'stack');
      tabStack.setAttribute('aria-selected', mode === 'stack' ? 'true' : 'false');
      tabPaper.classList.toggle('is-active', mode === 'paper');
      tabPaper.setAttribute('aria-selected', mode === 'paper' ? 'true' : 'false');
    }

    if (btnBlindToggle && blindToggleLabel) {
      btnBlindToggle.classList.toggle('is-on', blindMode);
      btnBlindToggle.setAttribute('aria-pressed', blindMode ? 'true' : 'false');
      blindToggleLabel.textContent = blindMode ? 'Blind mode on' : 'Blind mode off';
    }

    populateSelectors();

    if (selectStackQuestion) {
      var writtenCount = (workspace.questions || []).filter(function (q) { return q.is_written; }).length;
      selectStackQuestion.hidden = !(mode === 'stack' && writtenCount > 1);
      selectStackQuestion.value = String(stackFilterQuestionId);
    }
    if (selectWholePaper) {
      selectWholePaper.hidden = !(mode === 'paper' && (workspace.papers || []).length > 0);
      selectWholePaper.value = String(paperIndex);
    }

    if (btnUndo) {
      btnUndo.disabled = undoStack.length === 0 || isSaving;
    }

    var item = getCurrentItem();

    if (!item) {
      if (mode === 'stack' && (workspace.papers || []).length > 0) {
        if (noWrittenState) noWrittenState.hidden = false;
        if (mainGrid) mainGrid.hidden = true;
        if (toolbarTitle) toolbarTitle.textContent = 'All written responses complete';
        if (toolbarSub) toolbarSub.textContent = '0 written responses in this view.';
        if (btnPrev) btnPrev.disabled = true;
        if (btnNext) btnNext.disabled = true;
      }
      return;
    }

    if (noWrittenState) noWrittenState.hidden = true;
    if (mainGrid) mainGrid.hidden = false;

    renderPaperStrip();

    // Update Toolbar context & Prev/Next buttons
    if (mode === 'stack') {
      var list = getFilteredStackItems();
      var qPendingCount = list.filter(function (x) { return x.is_pending; }).length;
      toolbarTitle.textContent = 'Question ' + item.question_pos + ' · ' + item.type_label + ' · ' + fmtPoints(item.max_points) + ' points';
      toolbarSub.textContent = 'Response ' + (stackIndex + 1) + ' of ' + list.length
        + (qPendingCount > 0 ? ' · ' + qPendingCount + ' pending' : ' · All graded')
        + ' · Oldest first';
      btnPrev.disabled = stackIndex <= 0 || isSaving;
      btnNext.disabled = stackIndex >= list.length - 1 || isSaving;
    } else {
      var papers = workspace.papers || [];
      var curPaper = papers[paperIndex];
      var paperItems = curPaper.items || [];
      var studentDisplay = blindMode ? curPaper.paper_code : curPaper.student_name;
      toolbarTitle.textContent = studentDisplay + ' · Question ' + item.question_pos + ' of ' + paperItems.length + ' (' + item.type_label + ' · ' + fmtPoints(item.max_points) + ' points)';
      toolbarSub.textContent = 'Paper ' + (paperIndex + 1) + ' of ' + papers.length + ' · Submitted ' + (curPaper.submitted_manila || '—');
      var atVeryStart = paperIndex === 0 && paperQuestionIndex === 0;
      var atVeryEnd = paperIndex === papers.length - 1 && paperQuestionIndex === paperItems.length - 1;
      btnPrev.disabled = atVeryStart || isSaving;
      btnNext.disabled = atVeryEnd || isSaving;
    }

    // Prompt Card
    cardPromptText.textContent = item.prompt;

    // Response Card Eyebrow & Type
    if (blindMode) {
      cardResponseEyebrow.textContent = 'ANONYMOUS RESPONSE · ' + item.paper_code;
      cardResponseFoot.textContent = 'Identity stays hidden while blind mode is on. Marks and feedback are not visible to students until the appropriate release.';
    } else {
      cardResponseEyebrow.textContent = item.student_name.toUpperCase() + ' (' + item.student_username + ') · ' + item.paper_code;
      cardResponseFoot.textContent = 'Blind mode is off (showing student identity). Marks and feedback are not visible to students until the appropriate release.';
    }
    cardResponseType.textContent = item.type_label;

    // Response Body
    cardResponseBody.classList.remove('grading-response-body--empty');
    if (item.is_written) {
      var trimmedAns = (item.answer_text || '').trim();
      if (trimmedAns === '') {
        cardResponseBody.textContent = 'No response submitted (auto-zeroed).';
        cardResponseBody.classList.add('grading-response-body--empty');
      } else {
        cardResponseBody.textContent = item.answer_text;
      }
    } else {
      var picked = item.picked_labels || [];
      if (picked.length === 0) {
        cardResponseBody.textContent = 'No option selected.';
        cardResponseBody.classList.add('grading-response-body--empty');
      } else {
        cardResponseBody.textContent = 'Selected: ' + picked.join(', ');
      }
    }

    // Auto-grade / Override info banner
    if (!item.is_written) {
      cardAutoBanner.hidden = false;
      var autoStr = 'Auto-graded objective item: ' + fmtPoints(item.auto_points) + ' / ' + fmtPoints(item.max_points) + ' points.';
      if (item.is_overridden) {
        autoStr += ' Teacher override active: ' + fmtPoints(item.points_awarded) + ' points awarded.';
      } else {
        autoStr += ' You can override the points below if needed.';
      }
      cardAutoBanner.textContent = autoStr;
    } else if (item.is_blank_written) {
      cardAutoBanner.hidden = false;
      cardAutoBanner.textContent = 'Blank written response was automatically scored 0 points.';
    } else {
      cardAutoBanner.hidden = true;
      cardAutoBanner.textContent = '';
    }

    // Key & Rubric Card
    var rubricParts = [];
    if (item.correct_labels && item.correct_labels.length > 0) {
      rubricParts.push('Accepted key: ' + item.correct_labels.join(' / '));
    }
    if (item.rubric && item.rubric.trim() !== '') {
      rubricParts.push(item.rubric.trim());
    }
    if (rubricParts.length === 0) {
      rubricParts.push('Full credit (' + fmtPoints(item.max_points) + ' pts) for a complete, accurate response.\nHalf credit (' + fmtPoints(halfPoints(item.max_points)) + ' pts) when the core idea is correct but incomplete.\nZero (0 pts) for an incorrect or irrelevant response.');
    }
    cardRubricBody.textContent = rubricParts.join('\n\n');

    // Scoring Form Card
    formResponseId.value = String(item.response_id);
    var maxP = item.max_points;
    var halfP = halfPoints(maxP);
    btnScoreFull.textContent = 'Full · ' + fmtPoints(maxP);
    btnScoreHalf.textContent = 'Half · ' + fmtPoints(halfP);
    btnScoreZero.textContent = 'Zero · 0';

    lblPointsAwarded.textContent = 'Points awarded · out of ' + fmtPoints(maxP);
    inputPoints.max = String(maxP);
    inputPoints.value = item.points_awarded !== null && item.points_awarded !== undefined ? fmtPoints(item.points_awarded) : '';
    hintPointsRange.textContent = 'Use any value from 0 to ' + fmtPoints(maxP) + '.';
    errPoints.hidden = true;
    errPoints.textContent = '';

    inputFeedback.value = item.feedback || '';

    if (item.is_pending) {
      saveStateEl.textContent = 'Draft mark · Not yet saved';
      saveStateEl.removeAttribute('data-status');
    } else if (item.is_overridden) {
      saveStateEl.textContent = 'Saved override · ' + fmtPoints(item.points_awarded) + ' / ' + fmtPoints(maxP) + ' pts (auto was ' + fmtPoints(item.auto_points) + ')';
      saveStateEl.setAttribute('data-status', 'saved');
    } else {
      saveStateEl.textContent = 'Saved mark · ' + fmtPoints(item.points_awarded) + ' / ' + fmtPoints(maxP) + ' pts'
        + (item.graded_at_manila ? ' · ' + item.graded_at_manila : '');
      saveStateEl.setAttribute('data-status', 'saved');
    }

    updateQuickPresetHighlights(item);
  }

  function stepItem(delta) {
    if (mode === 'stack') {
      var list = getFilteredStackItems();
      var nextIdx = stackIndex + delta;
      if (nextIdx >= 0 && nextIdx < list.length) {
        stackIndex = nextIdx;
        render();
      }
    } else {
      var papers = workspace.papers || [];
      if (papers.length === 0) return;
      var curPaper = papers[paperIndex];
      var items = curPaper.items || [];
      var nextQ = paperQuestionIndex + delta;
      if (nextQ >= 0 && nextQ < items.length) {
        paperQuestionIndex = nextQ;
        render();
      } else if (delta > 0 && paperIndex < papers.length - 1) {
        paperIndex++;
        paperQuestionIndex = 0;
        render();
      } else if (delta < 0 && paperIndex > 0) {
        paperIndex--;
        var prevItems = papers[paperIndex].items || [];
        paperQuestionIndex = Math.max(0, prevItems.length - 1);
        render();
      }
    }
  }

  function announce(msg) {
    if (liveRegion) {
      liveRegion.textContent = msg;
    }
  }

  function saveCurrentGrade(overridePts, advanceAfter) {
    var item = getCurrentItem();
    if (!item || isSaving) {
      return;
    }

    if (overridePts !== undefined && overridePts !== null) {
      inputPoints.value = fmtPoints(overridePts);
      updateQuickPresetHighlights(item);
    }

    var rawPts = inputPoints.value.trim();
    if (rawPts === '' || isNaN(Number(rawPts))) {
      errPoints.textContent = 'Enter a point value between 0 and ' + fmtPoints(item.max_points) + '.';
      errPoints.hidden = false;
      inputPoints.focus();
      return;
    }

    var numPts = Number(rawPts);
    if (numPts < 0 || numPts > Number(item.max_points) + 0.0001) {
      errPoints.textContent = 'Points must be between 0 and ' + fmtPoints(item.max_points) + '.';
      errPoints.hidden = false;
      inputPoints.focus();
      return;
    }

    errPoints.hidden = true;
    isSaving = true;
    btnSaveNext.disabled = true;
    saveStateEl.textContent = 'Saving mark…';
    saveStateEl.removeAttribute('data-status');

    var prevPoints = item.points_awarded;
    var prevFeedback = item.feedback || '';
    var savedMode = mode;
    var savedStackIndex = stackIndex;
    var savedPaperIndex = paperIndex;
    var savedPaperQIndex = paperQuestionIndex;

    fetch('/api/grade.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify({
        _csrf: csrfToken,
        action: 'grade',
        response_id: item.response_id,
        points: numPts,
        feedback: inputFeedback.value
      })
    })
      .then(function (res) {
        return res.json().then(function (body) {
          return { status: res.status, body: body };
        });
      })
      .then(function (result) {
        isSaving = false;
        btnSaveNext.disabled = false;

        if (result.status !== 200 || !result.body || !result.body.ok) {
          var errMsg = (result.body && result.body.error) ? result.body.error : 'Could not save mark. Please try again.';
          saveStateEl.textContent = errMsg;
          saveStateEl.setAttribute('data-status', 'error');
          return;
        }

        var d = result.body;
        item.points_awarded = d.points_awarded;
        item.is_overridden = !!d.is_overridden;
        item.is_pending = false;
        item.feedback = d.feedback || '';
        item.graded_at_manila = d.graded_at_manila || '';

        var parentPaper = papersById[item.attempt_id];
        if (parentPaper) {
          parentPaper.score = d.attempt_score;
          parentPaper.status = d.attempt_status;
          parentPaper.pending_count = (parentPaper.items || []).filter(function (x) { return x.is_pending; }).length;
        }

        undoStack.push({
          response_id: item.response_id,
          restore_points: prevPoints,
          restore_feedback: prevFeedback,
          mode: savedMode,
          stackIndex: savedStackIndex,
          paperIndex: savedPaperIndex,
          paperQuestionIndex: savedPaperQIndex
        });

        updateHeaderCounts(d.pending_written_count);
        announce('Saved ' + fmtPoints(d.points_awarded) + ' points.');

        if (advanceAfter) {
          var list = getFilteredStackItems();
          var canAdvance = (mode === 'stack' && stackIndex < list.length - 1)
            || (mode === 'paper' && (paperIndex < (workspace.papers || []).length - 1 || paperQuestionIndex < ((parentPaper && parentPaper.items) ? parentPaper.items.length - 1 : 0)));
          if (canAdvance) {
            stepItem(1);
            return;
          }
        }
        render();
      })
      .catch(function () {
        isSaving = false;
        btnSaveNext.disabled = false;
        saveStateEl.textContent = 'Connection error while saving. Please try again.';
        saveStateEl.setAttribute('data-status', 'error');
      });
  }

  function undoLastMark() {
    if (undoStack.length === 0 || isSaving) {
      return;
    }

    var last = undoStack.pop();
    var targetItem = responsesById[last.response_id];
    if (!targetItem) {
      return;
    }

    isSaving = true;
    if (btnUndo) btnUndo.disabled = true;
    saveStateEl.textContent = 'Undoing last mark…';

    fetch('/api/grade.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify({
        _csrf: csrfToken,
        action: 'undo',
        response_id: last.response_id,
        restore_points: last.restore_points,
        restore_feedback: last.restore_feedback
      })
    })
      .then(function (res) {
        return res.json().then(function (body) {
          return { status: res.status, body: body };
        });
      })
      .then(function (result) {
        isSaving = false;
        if (result.status !== 200 || !result.body || !result.body.ok) {
          saveStateEl.textContent = 'Could not undo mark.';
          saveStateEl.setAttribute('data-status', 'error');
          render();
          return;
        }

        var d = result.body;
        targetItem.points_awarded = d.points_awarded;
        targetItem.is_overridden = !!d.is_overridden;
        targetItem.is_pending = !!d.is_pending;
        targetItem.feedback = d.feedback || '';
        targetItem.graded_at_manila = d.graded_at_manila || '';

        var parentPaper = papersById[targetItem.attempt_id];
        if (parentPaper) {
          parentPaper.score = d.attempt_score;
          parentPaper.status = d.attempt_status;
          parentPaper.pending_count = (parentPaper.items || []).filter(function (x) { return x.is_pending; }).length;
        }

        updateHeaderCounts(d.pending_written_count);

        // Return focus to the response that was undone
        mode = last.mode;
        stackIndex = last.stackIndex;
        paperIndex = last.paperIndex;
        paperQuestionIndex = last.paperQuestionIndex;

        render();
        saveStateEl.textContent = 'Last mark undone';
        saveStateEl.removeAttribute('data-status');
        announce('Undid last mark.');
      })
      .catch(function () {
        isSaving = false;
        saveStateEl.textContent = 'Connection error while undoing.';
        saveStateEl.setAttribute('data-status', 'error');
        render();
      });
  }

  // Event Listeners
  if (tabStack) {
    tabStack.addEventListener('click', function () {
      mode = 'stack';
      render();
    });
  }
  if (tabPaper) {
    tabPaper.addEventListener('click', function () {
      mode = 'paper';
      render();
    });
  }
  if (btnSwitchToPaper) {
    btnSwitchToPaper.addEventListener('click', function () {
      mode = 'paper';
      render();
    });
  }

  if (btnBlindToggle) {
    btnBlindToggle.addEventListener('click', function () {
      blindMode = !blindMode;
      render();
    });
  }

  if (selectStackQuestion) {
    selectStackQuestion.addEventListener('change', function () {
      stackFilterQuestionId = parseInt(selectStackQuestion.value || '0', 10) || 0;
      stackIndex = 0;
      render();
    });
  }

  if (selectWholePaper) {
    selectWholePaper.addEventListener('change', function () {
      paperIndex = parseInt(selectWholePaper.value || '0', 10) || 0;
      paperQuestionIndex = 0;
      render();
    });
  }

  if (btnPrev) {
    btnPrev.addEventListener('click', function () {
      stepItem(-1);
    });
  }
  if (btnNext) {
    btnNext.addEventListener('click', function () {
      stepItem(1);
    });
  }
  if (btnUndo) {
    btnUndo.addEventListener('click', function () {
      undoLastMark();
    });
  }

  if (btnScoreFull) {
    btnScoreFull.addEventListener('click', function () {
      var item = getCurrentItem();
      if (item) {
        saveCurrentGrade(item.max_points, true);
      }
    });
  }
  if (btnScoreHalf) {
    btnScoreHalf.addEventListener('click', function () {
      var item = getCurrentItem();
      if (item) {
        saveCurrentGrade(halfPoints(item.max_points), true);
      }
    });
  }
  if (btnScoreZero) {
    btnScoreZero.addEventListener('click', function () {
      var item = getCurrentItem();
      if (item) {
        saveCurrentGrade(0, true);
      }
    });
  }

  if (inputPoints) {
    inputPoints.addEventListener('input', function () {
      var item = getCurrentItem();
      updateQuickPresetHighlights(item);
      saveStateEl.textContent = 'Draft mark · Not yet saved';
      saveStateEl.removeAttribute('data-status');
    });
  }
  if (inputFeedback) {
    inputFeedback.addEventListener('input', function () {
      saveStateEl.textContent = 'Draft mark · Not yet saved';
      saveStateEl.removeAttribute('data-status');
    });
  }

  if (gradingForm) {
    gradingForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      saveCurrentGrade(null, true);
    });
  }

  // Shortcuts help modal
  function toggleShortcutsModal(show) {
    if (!shortcutsModal) return;
    var shouldShow = show !== undefined ? show : shortcutsModal.hidden;
    shortcutsModal.hidden = !shouldShow;
    shortcutsModal.classList.toggle('is-hidden', !shouldShow);
  }

  if (btnShortcutsHelp) {
    btnShortcutsHelp.addEventListener('click', function () {
      toggleShortcutsModal();
    });
  }
  if (btnCloseShortcuts) {
    btnCloseShortcuts.addEventListener('click', function () {
      toggleShortcutsModal(false);
    });
  }
  if (btnDismissShortcuts) {
    btnDismissShortcuts.addEventListener('click', function () {
      toggleShortcutsModal(false);
    });
  }

  // Global keyboard shortcuts (guide §9.4 & Day 15 schedule)
  document.addEventListener('keydown', function (ev) {
    var key = ev.key;

    if (key === 'Escape' && shortcutsModal && !shortcutsModal.hidden) {
      ev.preventDefault();
      toggleShortcutsModal(false);
      return;
    }

    // Ctrl + Enter or Cmd + Enter saves & advances even from inside input/textarea
    if ((ev.ctrlKey || ev.metaKey) && key === 'Enter') {
      ev.preventDefault();
      saveCurrentGrade(null, true);
      return;
    }

    // Alt + ArrowLeft / ArrowRight navigates even from inside input/textarea
    if (ev.altKey && (key === 'ArrowLeft' || key === 'ArrowRight')) {
      ev.preventDefault();
      stepItem(key === 'ArrowLeft' ? -1 : 1);
      return;
    }

    var target = ev.target;
    var tag = target && target.tagName ? target.tagName.toUpperCase() : '';
    var isEditingText = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (target && target.isContentEditable);

    if (isEditingText || ev.ctrlKey || ev.metaKey || ev.altKey) {
      return;
    }

    var item = getCurrentItem();

    if (key === '?') {
      ev.preventDefault();
      toggleShortcutsModal();
      return;
    }

    if (key === 'ArrowRight' || key === 'j' || key === 'J') {
      ev.preventDefault();
      stepItem(1);
      return;
    }

    if (key === 'ArrowLeft' || key === 'k' || key === 'K') {
      ev.preventDefault();
      stepItem(-1);
      return;
    }

    if ((key === 'f' || key === 'F') && item) {
      ev.preventDefault();
      saveCurrentGrade(item.max_points, true);
      return;
    }

    if ((key === 'h' || key === 'H') && item) {
      ev.preventDefault();
      saveCurrentGrade(halfPoints(item.max_points), true);
      return;
    }

    if ((key === '0' || key === 'z' || key === 'Z') && item) {
      ev.preventDefault();
      saveCurrentGrade(0, true);
      return;
    }

    if ((key === 'p' || key === 'P') && inputPoints) {
      ev.preventDefault();
      inputPoints.focus();
      inputPoints.select();
      return;
    }

    if ((key === 'n' || key === 'N') && inputFeedback) {
      ev.preventDefault();
      inputFeedback.focus();
      return;
    }

    if (key === 'u' || key === 'U') {
      ev.preventDefault();
      undoLastMark();
    }
  });

  // Initial render: if stack has items, start in 'stack' mode; otherwise if papers exist, still render cleanly
  render();
})();
