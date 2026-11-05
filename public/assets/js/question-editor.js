/* Question editor (Day 7): shows only the controls for the chosen type, manages choice rows and tag chips,
   and keeps the student preview live. Only textContent is used, so typed text can never run as HTML. */
(function () {
  var root = document.getElementById('editor');
  if (!root) { return; }

  var typeSel = document.getElementById('type');
  var optsBox = document.getElementById('opts');
  var addBtn = document.getElementById('add-opt');
  var maxOpts = optsBox ? parseInt(optsBox.getAttribute('data-max'), 10) : 6;
  var LETTERS = 'ABCDEF';
  var dirty = document.getElementById('dirty');
  var hints = {};
  try { hints = JSON.parse(document.getElementById('type-hint').getAttribute('data-hints')); } catch (e) {}

  function currentType() { return typeSel.value; }
  function rows() { return optsBox ? Array.prototype.slice.call(optsBox.querySelectorAll('.opt-row')) : []; }

  // ---- choice rows: keep letters, names and values in step after adding or removing ----
  function renumber() {
    var list = rows();
    list.forEach(function (row, i) {
      var L = LETTERS.charAt(i);
      row.querySelector('input[type=radio]').value = i;
      row.querySelector('input[type=checkbox]').value = i;
      var text = row.querySelector('.opt-input');
      text.name = 'option[' + i + ']';
      text.setAttribute('aria-label', 'Option ' + L);
      row.querySelector('.opt-letter').textContent = L;
      row.querySelector('.opt-remove').setAttribute('aria-label', 'Remove option ' + L);
      row.querySelector('input[type=radio]').setAttribute('aria-label', 'Option ' + L + ' is the correct key');
      row.querySelector('input[type=checkbox]').setAttribute('aria-label', 'Option ' + L + ' is a correct key');
      row.classList.toggle('is-key', (currentType() === 'multi' ? row.querySelector('input[type=checkbox]') : row.querySelector('input[type=radio]')).checked);
      row.querySelector('.opt-remove').hidden = list.length <= 2;
    });
    if (addBtn) { addBtn.hidden = list.length >= maxOpts; }
  }

  if (addBtn) {
    addBtn.addEventListener('click', function () {
      var list = rows();
      if (list.length >= maxOpts) { return; }
      var clone = list[list.length - 1].cloneNode(true);
      clone.querySelector('input[type=radio]').checked = false;
      clone.querySelector('input[type=checkbox]').checked = false;
      clone.querySelector('.opt-input').value = '';
      var box = clone.querySelector('.opt-box');
      box.removeAttribute('data-invalid');
      optsBox.appendChild(clone);
      renumber();
      clone.querySelector('.opt-input').focus();
      render();
      markDirty();
    });
    optsBox.addEventListener('click', function (ev) {
      var btn = ev.target.closest('.opt-remove');
      if (!btn || rows().length <= 2) { return; }
      var row = btn.closest('.opt-row');
      // Drop any error line that belongs to this row.
      var next = row.nextElementSibling;
      if (next && next.classList.contains('field__error')) { next.remove(); }
      row.remove();
      renumber();
      render();
      markDirty();
    });
  }

  // ---- tag chips: a hidden field holds the comma list that the server reads ----
  var tagBox = document.getElementById('tags');
  var tagValue = document.getElementById('tags-value');
  var tagInput = document.getElementById('tag-add');
  var tags = [];

  function loadTags() {
    tags = [];
    tagValue.value.split(',').forEach(function (t) { addTag(t, true); });
  }
  function addTag(raw, quiet) {
    var t = raw.replace(/\s+/g, ' ').trim();
    if (t === '' || tags.length >= 10) { return; }
    var low = t.toLowerCase();
    if (tags.some(function (x) { return x.toLowerCase() === low; })) { return; }
    tags.push(t);
    if (!quiet) { markDirty(); }
    drawTags();
  }
  function drawTags() {
    Array.prototype.slice.call(tagBox.querySelectorAll('.tag-chip')).forEach(function (n) { n.remove(); });
    tags.forEach(function (t, i) {
      var chip = document.createElement('span');
      chip.className = 'tag-chip';
      var label = document.createElement('span');
      label.textContent = t;
      var x = document.createElement('button');
      x.type = 'button';
      x.textContent = '×';
      x.setAttribute('aria-label', 'Remove tag ' + t);
      x.addEventListener('click', function () { tags.splice(i, 1); drawTags(); markDirty(); });
      chip.appendChild(label);
      chip.appendChild(x);
      tagBox.insertBefore(chip, tagInput);
    });
    tagValue.value = tags.join(', ');
  }
  tagInput.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' || ev.key === ',') {
      ev.preventDefault();
      addTag(tagInput.value);
      tagInput.value = '';
    } else if (ev.key === 'Backspace' && tagInput.value === '' && tags.length) {
      tags.pop(); drawTags(); markDirty();
    }
  });
  tagInput.addEventListener('blur', function () { addTag(tagInput.value); tagInput.value = ''; });
  // Whatever is typed but not yet turned into a chip still gets saved.
  root.addEventListener('submit', function () { addTag(tagInput.value); tagInput.value = ''; });

  // ---- preview and per-type controls ----
  function render() {
    var type = currentType();
    root.setAttribute('data-type', type);
    var hintEl = document.getElementById('type-hint');
    if (hints[type] && !typeSel.disabled) { hintEl.textContent = hints[type]; }

    var pts = (document.getElementById('points').value || '').trim() || '1';
    document.getElementById('pv-points').textContent = pts + (pts === '1' ? ' point' : ' points');
    var prompt = document.getElementById('prompt').value.trim();
    document.getElementById('pv-prompt').textContent = prompt || 'Your question appears here.';

    var list = document.getElementById('pv-opts');
    list.textContent = '';
    var items = [];
    if (type === 'true_false') {
      items = ['True', 'False'];
    } else {
      rows().forEach(function (row, i) {
        var v = row.querySelector('.opt-input').value.trim();
        row.classList.toggle('is-key', (type === 'multi' ? row.querySelector('input[type=checkbox]') : row.querySelector('input[type=radio]')).checked);
        if (v !== '') { items.push({ letter: LETTERS.charAt(i), text: v }); }
      });
    }
    if (items.length === 0) {
      var empty = document.createElement('li');
      empty.className = 'preview__empty';
      empty.textContent = 'Answer choices appear here.';
      list.appendChild(empty);
      return;
    }
    items.forEach(function (it) {
      var li = document.createElement('li');
      var mark = document.createElement('span');
      mark.className = 'preview__mark' + (type === 'multi' ? ' preview__mark--square' : '');
      mark.setAttribute('aria-hidden', 'true');
      li.appendChild(mark);
      if (typeof it === 'object') {
        var letter = document.createElement('span');
        letter.className = 'preview__letter';
        letter.textContent = it.letter;
        li.appendChild(letter);
      }
      var label = document.createElement('span');
      label.textContent = typeof it === 'object' ? it.text : it;
      li.appendChild(label);
      list.appendChild(li);
    });
  }

  function markDirty() { if (dirty) { dirty.hidden = false; } }

  root.addEventListener('input', function (ev) { if (ev.target !== tagInput) { render(); markDirty(); } });
  root.addEventListener('change', function () { render(); markDirty(); });

  loadTags();
  drawTags();
  renumber();
  render();
})();
