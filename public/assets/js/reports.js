(() => {
  'use strict';

  const switcher = document.querySelector('[data-autosubmit-report]');
  if (switcher && switcher.form) {
    switcher.addEventListener('change', () => {
      switcher.form.submit();
    });
  }
})();
