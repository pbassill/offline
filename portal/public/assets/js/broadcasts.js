/*
 * Family broadcasts. The page arrives with the current ones already in it;
 * this asks the portal every twenty seconds whether there is anything new and
 * redraws the banner if so. Text is only ever placed with textContent.
 * "Seen" hides a broadcast in this browser; nothing is sent anywhere.
 */
(function () {
  'use strict';
  var holder = document.getElementById('broadcasts');
  if (!holder || !window.fetch) {
    return;
  }
  var KEY = 'offline-broadcasts-seen';
  var title = document.title;

  function seen() {
    try {
      var list = JSON.parse(window.localStorage.getItem(KEY) || '[]');
      return Array.isArray(list) ? list : [];
    } catch (e) {
      return [];
    }
  }

  function remember(id) {
    var list = seen();
    if (list.indexOf(id) === -1) {
      list.push(id);
    }
    try {
      window.localStorage.setItem(KEY, JSON.stringify(list.slice(-100)));
    } catch (e) {
      /* Private browsing: the broadcast simply returns on the next page. */
    }
  }

  function element(tag, className, text) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text !== undefined) {
      node.textContent = text;
    }
    return node;
  }

  function build(b) {
    var box = element('div', 'broadcast' + (b.urgent ? ' broadcast-urgent' : ''));
    box.setAttribute('role', b.urgent ? 'alert' : 'status');
    box.setAttribute('data-broadcast', b.id);
    var icon = element('i', 'fa-solid fa-fw ' + (b.urgent ? 'fa-triangle-exclamation' : 'fa-bullhorn'));
    icon.setAttribute('aria-hidden', 'true');
    var body = element('div', 'broadcast-body');
    body.appendChild(element('p', 'broadcast-text', b.text));
    body.appendChild(element('p', 'broadcast-meta', (b.urgent ? 'Urgent family broadcast' : 'Family broadcast') + ' from ' + b.from + ', ' + b.sent));
    var button = element('button', 'btn btn-sm broadcast-dismiss');
    button.type = 'button';
    button.setAttribute('data-dismiss-broadcast', b.id);
    var tick = element('i', 'fa-solid fa-check');
    tick.setAttribute('aria-hidden', 'true');
    button.appendChild(tick);
    button.appendChild(document.createTextNode(' Seen'));
    box.appendChild(icon);
    box.appendChild(body);
    box.appendChild(button);
    return box;
  }

  function hideSeen() {
    var hidden = seen();
    var showing = 0;
    holder.querySelectorAll('[data-broadcast]').forEach(function (box) {
      var gone = hidden.indexOf(box.getAttribute('data-broadcast')) !== -1;
      box.hidden = gone;
      showing += gone ? 0 : 1;
    });
    document.title = showing > 0 ? '(' + showing + ') ' + title : title;
  }

  function draw(list) {
    var have = [];
    holder.querySelectorAll('[data-broadcast]').forEach(function (box) {
      have.push(box.getAttribute('data-broadcast'));
    });
    var want = list.map(function (b) { return b.id; });
    if (have.join() === want.join()) {
      return;
    }
    while (holder.firstChild) {
      holder.removeChild(holder.firstChild);
    }
    list.forEach(function (b) {
      holder.appendChild(build(b));
    });
    hideSeen();
  }

  holder.addEventListener('click', function (event) {
    var button = event.target.closest ? event.target.closest('[data-dismiss-broadcast]') : null;
    if (button) {
      remember(button.getAttribute('data-dismiss-broadcast'));
      hideSeen();
    }
  });

  function ask() {
    window.fetch('/alerts.php', { credentials: 'omit', cache: 'no-store' })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (data) {
        if (data && Array.isArray(data.broadcasts)) {
          draw(data.broadcasts.filter(function (b) {
            return b && typeof b.id === 'string' && typeof b.text === 'string' && typeof b.from === 'string' && typeof b.sent === 'string';
          }));
        }
      })
      .catch(function () { /* The portal is restarting or the network dropped; try again next time. */ });
  }

  hideSeen();
  window.setInterval(ask, 20000);
}());
