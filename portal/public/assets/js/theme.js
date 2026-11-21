/*
 * Applies the saved theme before the page paints, so there is no flash.
 * Loaded in <head>; the buttons are wired up by offline.js.
 */
(function () {
  'use strict';
  var themes = ['light', 'dark', 'vt100'];
  var theme = null;
  try {
    theme = window.localStorage.getItem('offline-theme');
  } catch (e) {
    theme = null;
  }
  if (themes.indexOf(theme) === -1) {
    theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  var root = document.documentElement;
  root.setAttribute('data-theme', theme);
  root.setAttribute('data-bs-theme', theme === 'light' ? 'light' : 'dark');
}());
