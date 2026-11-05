/* Quizzes list & Quiz Builder (Day 9 & 10).
   Kept in an external file so Content-Security-Policy (default-src 'self', no inline scripts/styles) stays enforced. */
(function () {
  function openModal(modal) {
    if (!modal) { return; }
    modal.hidden = false;
    modal.classList.remove('is-hidden');
    var firstInput = modal.querySelector('input[type="text"], textarea, select');
    if (firstInput) { firstInput.focus(); }
  }

  function closeModal(modal) {
    if (!modal) { return; }
    modal.hidden = true;
    modal.classList.add('is-hidden');
  }

  function closeAllModals() {
    document.querySelectorAll('.modal-overlay').forEach(function (m) {
      closeModal(m);
    });
  }

  // ---- Global click delegation for modals & edit triggers ----
  document.addEventListener('click', function (ev) {
    var openBtn = ev.target.closest('[data-open-modal]');
    if (openBtn) {
      ev.preventDefault();
      var targetId = openBtn.getAttribute('data-open-modal');
      openModal(document.getElementById(targetId));
      return;
    }

    var closeBtn = ev.target.closest('[data-close-modal]');
    if (closeBtn) {
      ev.preventDefault();
      var parentModal = closeBtn.closest('.modal-overlay');
      if (parentModal) {
        closeModal(parentModal);
      } else {
        closeAllModals();
      }
      return;
    }

    if (ev.target.classList && ev.target.classList.contains('modal-overlay')) {
      closeModal(ev.target);
      return;
    }

    var editBtn = ev.target.closest('.btn-edit-trigger');
    if (editBtn) {
      ev.preventDefault();
      openEditQuestionModal(editBtn);
    }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') {
      closeAllModals();
    }
  });

  // ---- Auto-submit filter selects & confirm forms ----
  document.addEventListener('change', function (ev) {
    if (ev.target.matches('[data-autosubmit]') && ev.target.form) {
      ev.target.form.submit();
    }
  });

  document.addEventListener('submit', function (ev) {
    var msg = ev.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      ev.preventDefault();
    }
  });

  // ---- Bank Modal Filtering ----
  var filterText = document.getElementById('bank-filter-text');
  var filterType = document.getElementById('bank-filter-type');

  function applyBankFilter() {
    var q = (filterText ? filterText.value : '').toLowerCase().trim();
    var t = (filterType ? filterType.value : '').trim();
    document.querySelectorAll('.bank-item-row').forEach(function (row) {
      var rowPrompt = row.getAttribute('data-prompt') || '';
      var rowType = row.getAttribute('data-type') || '';
      var show = (!q || rowPrompt.indexOf(q) !== -1) && (!t || rowType === t);
      row.hidden = !show;
      row.classList.toggle('is-hidden', !show);
    });
  }

  if (filterText) { filterText.addEventListener('input', applyBankFilter); }
  if (filterType) { filterType.addEventListener('change', applyBankFilter); }

  // ---- Edit Question Copy Modal ----
  function openEditQuestionModal(btn) {
    var modal = document.getElementById('modal-edit-copy');
    if (!modal) { return; }

    var qData = {};
    try {
      qData = JSON.parse(btn.getAttribute('data-q') || '{}');
    } catch (e) {
      return;
    }

    var editQId = document.getElementById('edit-q-id');
    var editPrompt = document.getElementById('edit-prompt');
    var editPoints = document.getElementById('edit-points');
    var editRubric = document.getElementById('edit-rubric');
    var editOptsContainer = document.getElementById('edit-options-container');

    if (editQId) { editQId.value = qData.id || ''; }
    if (editPrompt) { editPrompt.value = qData.prompt || ''; }
    if (editPoints) { editPoints.value = qData.points !== undefined ? qData.points : '1'; }
    if (editRubric) { editRubric.value = qData.rubric || ''; }

    if (editOptsContainer) {
      editOptsContainer.textContent = '';
      var isObjective = ['single', 'true_false', 'multi'].indexOf(qData.type) !== -1 && Array.isArray(qData.options);
      editOptsContainer.hidden = !isObjective;

      if (isObjective) {
        var optTitle = document.createElement('h4');
        optTitle.className = 'edit-options-title';
        optTitle.textContent = 'Options & Answer Key';
        editOptsContainer.appendChild(optTitle);

        qData.options.forEach(function (opt, idx) {
          var row = document.createElement('div');
          row.className = 'edit-option-row';

          var check = document.createElement('input');
          check.type = qData.type === 'multi' ? 'checkbox' : 'radio';
          check.name = qData.type === 'multi' ? 'options[' + idx + '][is_correct]' : 'correct_choice';
          check.value = qData.type === 'multi' ? '1' : String(idx);
          if (Number(opt.is_correct) === 1) {
            check.checked = true;
          }

          var text = document.createElement('input');
          text.type = 'text';
          text.className = 'input';
          text.name = 'options[' + idx + '][label]';
          text.value = opt.label || '';
          text.required = true;

          row.appendChild(check);
          row.appendChild(text);
          editOptsContainer.appendChild(row);
        });
      }
    }

    openModal(modal);
  }

  var editForm = document.getElementById('form-edit-copy');
  if (editForm) {
    editForm.addEventListener('submit', function () {
      var radios = editForm.querySelectorAll('input[name="correct_choice"]');
      if (radios.length > 0) {
        radios.forEach(function (r, idx) {
          var hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = 'options[' + idx + '][is_correct]';
          hidden.value = r.checked ? '1' : '0';
          editForm.appendChild(hidden);
        });
      }
    });
  }

  // ---- Quiz Builder Inline Points & Drag-and-Drop ----
  var builderForm = document.getElementById('quiz-form');
  if (!builderForm) { return; }

  var isDraft = builderForm.getAttribute('data-is-draft') === '1';
  var quizId = builderForm.getAttribute('data-quiz-id') || '';
  var csrfInput = builderForm.querySelector('input[name="csrf_token"]');
  var csrfToken = csrfInput ? csrfInput.value : '';

  var pointsInputs = document.querySelectorAll('.q-points-input');
  var totalDisplay = document.getElementById('total-points-display');

  function updateTotalPoints() {
    var sum = 0;
    pointsInputs.forEach(function (inp) {
      sum += parseFloat(inp.value) || 0;
    });
    if (totalDisplay) {
      totalDisplay.textContent = sum % 1 === 0 ? String(sum) : sum.toFixed(2);
    }
  }

  pointsInputs.forEach(function (inp) {
    inp.addEventListener('input', updateTotalPoints);
    inp.addEventListener('change', function () {
      updateTotalPoints();
      if (!isDraft || !quizId) { return; }

      var qid = inp.getAttribute('data-qid');
      var val = parseFloat(inp.value) || 0;

      var data = new FormData();
      data.append('csrf_token', csrfToken);
      data.append('id', quizId);
      data.append('action', 'update_points');
      data.append('quiz_question_id', qid);
      data.append('points', String(val));

      fetch('/teacher/quiz.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: data
      }).catch(function () {});
    });
  });

  var list = document.getElementById('questions-list');
  if (list && isDraft) {
    var draggedItem = null;

    list.querySelectorAll('.q-row').forEach(function (row) {
      row.addEventListener('dragstart', function (ev) {
        draggedItem = row;
        row.classList.add('is-dragging');
        if (ev.dataTransfer) {
          ev.dataTransfer.effectAllowed = 'move';
        }
      });

      row.addEventListener('dragend', function () {
        row.classList.remove('is-dragging');
        list.querySelectorAll('.q-row').forEach(function (r) {
          r.classList.remove('drag-over');
        });

        var rows = list.querySelectorAll('.q-row');
        var orderedIds = [];
        rows.forEach(function (r, idx) {
          orderedIds.push(parseInt(r.getAttribute('data-id'), 10));
          var numSpan = r.querySelector('.q-num');
          if (numSpan) {
            numSpan.textContent = String(idx + 1).padStart(2, '0');
          }
          var upBtn = r.querySelector('[data-move-dir="up"]');
          var downBtn = r.querySelector('[data-move-dir="down"]');
          if (upBtn) { upBtn.disabled = idx === 0; }
          if (downBtn) { downBtn.disabled = idx === rows.length - 1; }
        });

        var data = new FormData();
        data.append('csrf_token', csrfToken);
        data.append('id', quizId);
        data.append('action', 'reorder');
        data.append('ordered_ids', JSON.stringify(orderedIds));

        fetch('/teacher/quiz.php', {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: data
        }).catch(function () {});
      });

      row.addEventListener('dragover', function (ev) {
        ev.preventDefault();
        if (!draggedItem || draggedItem === row) { return; }
        if (ev.dataTransfer) {
          ev.dataTransfer.dropEffect = 'move';
        }
        var rect = row.getBoundingClientRect();
        var midY = rect.top + rect.height / 2;
        if (ev.clientY < midY) {
          list.insertBefore(draggedItem, row);
        } else {
          list.insertBefore(draggedItem, row.nextSibling);
        }
      });
    });
  }
})();
