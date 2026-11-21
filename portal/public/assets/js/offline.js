/* Theme buttons. The choice is kept in this browser only; nothing is sent anywhere. */
(function () {
  'use strict';
  var root = document.documentElement;
  var buttons = document.querySelectorAll('[data-set-theme]');

  function mark() {
    var current = root.getAttribute('data-theme');
    buttons.forEach(function (button) {
      button.setAttribute('aria-pressed', button.getAttribute('data-set-theme') === current ? 'true' : 'false');
    });
  }

  buttons.forEach(function (button) {
    button.addEventListener('click', function () {
      var theme = button.getAttribute('data-set-theme');
      root.setAttribute('data-theme', theme);
      root.setAttribute('data-bs-theme', theme === 'light' ? 'light' : 'dark');
      try {
        window.localStorage.setItem('offline-theme', theme);
      } catch (e) {
        /* Private browsing: the theme simply lasts for this page. */
      }
      mark();
    });
  });

  mark();
}());
