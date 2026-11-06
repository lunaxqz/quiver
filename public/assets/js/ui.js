(function () {
  'use strict';

  /* ---- 1. Light / Dark Theme Persistence (localStorage + Cookie) ---- */
  function persistTheme(theme) {
    var clean = theme === 'dark' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', clean);
    try { localStorage.setItem('quiver_theme', clean); } catch (err) {}
    try { document.cookie = 'quiver_theme=' + clean + '; path=/; max-age=31536000; SameSite=Lax'; } catch (err2) {}
  }

  function currentTheme() {
    return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
  }

  function initSavedTheme() {
    try {
      var stored = localStorage.getItem('quiver_theme');
      if (stored === 'dark' || stored === 'light') {
        persistTheme(stored);
      }
    } catch (err) {}
  }

  function syncThemeButtons() {
    var isDark = currentTheme() === 'dark';
    var btns = document.querySelectorAll('[data-theme-toggle]');
    for (var i = 0; i < btns.length; i++) {
      btns[i].setAttribute('aria-pressed', isDark ? 'true' : 'false');
      btns[i].textContent = isDark ? '☀ Light' : '☾ Dark';
      btns[i].title = isDark ? 'Switch to light theme' : 'Switch to dark theme';
    }
  }

  document.addEventListener('click', function (e) {
    var toggle = e.target.closest('[data-theme-toggle]');
    if (!toggle) return;
    var next = currentTheme() === 'dark' ? 'light' : 'dark';
    persistTheme(next);
    syncThemeButtons();
  });

  /* ---- 2. Sliding Clay Pill Tabs + Frame Swap ---- */
  function syncPillGroup(group) {
    if (!group) return;
    var slider = group.querySelector('.pill-tabs__slider');
    var active = group.querySelector('.pill-tab[aria-selected="true"], .pill-tab[aria-current="page"]');
    if (!slider || !active) return;
    slider.style.width = active.offsetWidth + 'px';
    slider.style.transform = 'translateX(' + (active.offsetLeft - 5) + 'px)';
  }

  function syncAllPills() {
    var groups = document.querySelectorAll('[data-pill-group]');
    for (var i = 0; i < groups.length; i++) {
      syncPillGroup(groups[i]);
    }
  }

  document.addEventListener('click', function (e) {
    var tab = e.target.closest('.pill-tab[data-tab-target]');
    if (!tab) return;
    var group = tab.closest('[data-pill-group]');
    var targetId = tab.getAttribute('data-tab-target');
    if (!group || !targetId) return;

    var tabs = group.querySelectorAll('.pill-tab[data-tab-target]');
    for (var i = 0; i < tabs.length; i++) {
      var isMatch = tabs[i] === tab;
      tabs[i].setAttribute('aria-selected', isMatch ? 'true' : 'false');
      var panelId = tabs[i].getAttribute('data-tab-target');
      var panel = panelId ? document.getElementById(panelId) : null;
      if (panel) {
        panel.hidden = !isMatch;
        if (isMatch) {
          panel.style.animation = 'none';
          void panel.offsetWidth;
          panel.style.animation = 'qFrameIn 0.32s cubic-bezier(0.22, 1, 0.36, 1) backwards';
        }
      }
    }
    syncPillGroup(group);
  });

  function activateTabForTarget(id) {
    if (!id) return;
    var btn = document.querySelector('.pill-tab[data-tab-target="' + id + '"]');
    if (btn) btn.click();
  }

  /* ---- 3. Password Show / Hide Button ---- */
  document.addEventListener('click', function (e) {
    var pwBtn = e.target.closest('[data-toggle-pw]');
    if (!pwBtn) return;
    var fieldId = pwBtn.getAttribute('data-toggle-pw');
    var input = fieldId ? document.getElementById(fieldId) : null;
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    pwBtn.textContent = show ? 'Hide' : 'Show';
    pwBtn.setAttribute('aria-pressed', show ? 'true' : 'false');
  });

  /* ---- 4. Multi-Step Form Wizard (Progressive Enhancement) ---- */
  function initSteppedForms() {
    var wizards = document.querySelectorAll('[data-step-form]');
    for (var i = 0; i < wizards.length; i++) {
      (function (wiz) {
        wiz.classList.add('js-stepped');
        var steps = wiz.querySelectorAll('.form-step');
        var total = steps.length;
        var labelEl = wiz.querySelector('[data-step-label]');
        var pctEl = wiz.querySelector('[data-step-pct]');
        var fillEl = wiz.querySelector('[data-step-fill]');

        function showStep(num, focusFirst) {
          for (var s = 0; s < steps.length; s++) {
            var idx = Number(steps[s].getAttribute('data-step') || (s + 1));
            var active = idx === num;
            steps[s].classList.toggle('is-active', active);
            if (active) {
              var title = steps[s].getAttribute('data-step-title') || '';
              var pct = Math.round((num / total) * 100) + '%';
              if (labelEl) labelEl.textContent = 'Step ' + num + ' of ' + total + (title ? ' · ' + title : '');
              if (pctEl) pctEl.textContent = pct;
              if (fillEl) fillEl.style.width = pct;
              if (focusFirst) {
                var firstInput = steps[s].querySelector('input, select, textarea');
                if (firstInput) firstInput.focus();
              }
            }
          }
        }

        var initialStep = Number(wiz.getAttribute('data-initial-step') || '1');
        showStep(initialStep, false);

        wiz.addEventListener('click', function (ev) {
          var navBtn = ev.target.closest('[data-goto-step]');
          if (!navBtn) return;
          var targetStep = Number(navBtn.getAttribute('data-goto-step'));
          var currentEl = wiz.querySelector('.form-step.is-active');
          var currentNum = currentEl ? Number(currentEl.getAttribute('data-step') || '1') : 1;

          if (targetStep > currentNum && currentEl) {
            var reqs = currentEl.querySelectorAll('input[required], select[required], textarea[required]');
            for (var r = 0; r < reqs.length; r++) {
              if (!reqs[r].checkValidity()) {
                reqs[r].reportValidity();
                return;
              }
            }
          }
          showStep(targetStep, true);
        });
      })(wizards[i]);
    }
  }

  /* ---- 5. Live Password Rule Chips ---- */
  function initPasswordHints() {
    var regPw = document.getElementById('reg_password');
    var regCf = document.getElementById('reg_confirm');
    var chipLen = document.getElementById('pw-chip-len');
    var chipMatch = document.getElementById('pw-chip-match');
    if (!regPw || !chipLen) return;

    function updateChips() {
      var lenOk = regPw.value.length >= 10;
      chipLen.classList.toggle('is-met', lenOk);
      chipLen.textContent = lenOk
        ? '✓ 10+ characters'
        : '○ At least 10 characters (' + regPw.value.length + '/10)';

      if (regCf && chipMatch) {
        var matchOk = regCf.value.length > 0 && regPw.value === regCf.value;
        chipMatch.classList.toggle('is-met', matchOk);
        chipMatch.textContent = matchOk ? '✓ Passwords match' : '○ Both passwords match';
      }
    }
    regPw.addEventListener('input', updateChips);
    if (regCf) regCf.addEventListener('input', updateChips);
  }

  /* ---- 6. Copy Starter Password Button ---- */
  document.addEventListener('click', function (e) {
    var copyBtn = e.target.closest('[data-copy-text]');
    if (!copyBtn) return;
    var val = copyBtn.getAttribute('data-copy-text') || '';
    if (!val || !navigator.clipboard) return;
    var orig = copyBtn.textContent;
    navigator.clipboard.writeText(val).then(function () {
      copyBtn.textContent = '✓ Copied!';
      window.setTimeout(function () { copyBtn.textContent = orig; }, 2000);
    });
  });

  /* ---- 7. Close <details class="row-more"> on outside click or Escape ---- */
  document.addEventListener('click', function (e) {
    var openMenus = document.querySelectorAll('details.row-more[open]');
    for (var i = 0; i < openMenus.length; i++) {
      if (!openMenus[i].contains(e.target)) {
        openMenus[i].removeAttribute('open');
      }
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      var openMenus = document.querySelectorAll('details.row-more[open]');
      for (var i = 0; i < openMenus.length; i++) {
        openMenus[i].removeAttribute('open');
      }
    }
  });

  /* ---- 8. Animate Progress Bars on Entry ---- */
  function animateProgressBars() {
    var bars = document.querySelectorAll('.progress-fill[data-fill-pct]');
    for (var i = 0; i < bars.length; i++) {
      (function (bar) {
        var target = bar.getAttribute('data-fill-pct') + '%';
        bar.style.width = '0%';
        requestAnimationFrame(function () {
          requestAnimationFrame(function () {
            bar.style.width = target;
          });
        });
      })(bars[i]);
    }
  }

  initSavedTheme();
  syncThemeButtons();
  initSteppedForms();
  initPasswordHints();
  syncAllPills();
  animateProgressBars();

  if (window.location.hash) {
    var h = window.location.hash.slice(1);
    if (h === 'register') activateTabForTarget('panel-register');
    if (h === 'reset') activateTabForTarget('panel-reset');
  }

  window.addEventListener('resize', syncAllPills);
  window.addEventListener('load', syncAllPills);
})();
