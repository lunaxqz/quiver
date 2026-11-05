// Day 6: "Copy code" and confirm-before-submit. Kept in a file so the strict security policy (no inline scripts) stays on.
document.addEventListener('click', function (ev) {
  var copy = ev.target.closest('[data-copy]');
  if (copy) {
    var text = copy.getAttribute('data-copy');
    var done = function () { var old = copy.textContent; copy.textContent = 'Copied'; setTimeout(function () { copy.textContent = old; }, 1500); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done); }
  }
});
document.addEventListener('submit', function (ev) {
  var msg = ev.target.getAttribute('data-confirm');
  if (msg && !window.confirm(msg)) { ev.preventDefault(); }
});
