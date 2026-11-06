/* Synchronous theme initializer in <head> (external file to satisfy strict CSP default-src 'self'). */
(function () {
  'use strict';
  try {
    var theme = localStorage.getItem('quiver_theme');
    if (theme !== 'dark' && theme !== 'light') {
      var m = document.cookie.match(/(?:^|;\s*)quiver_theme=(dark|light)/);
      if (m) {
        theme = m[1];
      } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        theme = 'dark';
      } else {
        theme = 'light';
      }
    }
    document.documentElement.setAttribute('data-theme', theme);
    document.cookie = 'quiver_theme=' + theme + '; path=/; max-age=31536000; SameSite=Lax';
  } catch (e) {}
})();
