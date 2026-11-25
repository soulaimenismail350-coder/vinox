/* GG LEVEL PRO
   Five small jobs, no framework: the theme switch, the mobile rail, swapping
   credential fields with the login type, asking about a restart when a live
   process changes, and refreshing progress in place. */

(function () {
  'use strict';

  var STATES = ['idle', 'starting', 'running', 'stopping', 'done', 'error'];
  var LIVE = { running: 1, starting: 1 };

  function each(sel, root, fn) {
    Array.prototype.forEach.call((root || document).querySelectorAll(sel), fn);
  }

  /* ---------------- theme ----------------

     The inline script in layout.php has already applied the stored choice
     before paint. This only handles the click and the write-back. */

  var themeBtn = document.getElementById('themeBtn');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var root = document.documentElement;
      var current = root.getAttribute('data-theme');
      if (!current) {
        /* No explicit choice yet: flip away from whatever the system gives. */
        current = window.matchMedia('(prefers-color-scheme: dark)').matches
          ? 'dark' : 'light';
      }
      var next = current === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('gglp-theme', next); } catch (e) { /* private mode */ }
    });
  }

  /* ---------------- rail: drawer on a phone, hideable on a desktop ---------------- */

  var railBtn = document.getElementById('railBtn');
  var rail = document.getElementById('rail');
  var mobile = window.matchMedia('(max-width: 900px)');

  function isMobile() {
    return mobile.matches !== undefined ? mobile.matches : window.innerWidth <= 900;
  }

  /* The inline script marks <html> so a remembered hidden rail never paints
     open. Move that state onto <body>, where the rest of the CSS reads it. */
  if (document.documentElement.classList.contains('rail-off-boot')) {
    document.body.classList.add('rail-off');
    /* Dropping the boot class re-enables the slide transition for real
       clicks, now that the first paint is done. */
    requestAnimationFrame(function () {
      document.documentElement.classList.remove('rail-off-boot');
    });
  }

  function syncExpanded() {
    if (!railBtn) return;
    var open = isMobile()
      ? document.body.classList.contains('rail-open')
      : !document.body.classList.contains('rail-off');
    railBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  if (railBtn) {
    railBtn.addEventListener('click', function (ev) {
      ev.stopPropagation();
      if (isMobile()) {
        document.body.classList.toggle('rail-open');
      } else {
        var off = document.body.classList.toggle('rail-off');
        try { localStorage.setItem('gglp-rail', off ? 'off' : 'on'); } catch (e) { /* private mode */ }
      }
      syncExpanded();
    });

    /* Tapping the page behind an open drawer should close it, the way any
       drawer does; without this the only way out is the button. */
    document.addEventListener('click', function (ev) {
      if (!document.body.classList.contains('rail-open')) return;
      if (rail && !rail.contains(ev.target)) {
        document.body.classList.remove('rail-open');
        syncExpanded();
      }
    });

    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && document.body.classList.contains('rail-open')) {
        document.body.classList.remove('rail-open');
        syncExpanded();
      }
    });

    /* Rotating a phone into landscape can cross the breakpoint. Leaving the
       drawer class set would then hide the desktop rail for no reason. */
    var onBreak = function () {
      document.body.classList.remove('rail-open');
      syncExpanded();
    };
    if (mobile.addEventListener) { mobile.addEventListener('change', onBreak); }
    else if (mobile.addListener) { mobile.addListener(onBreak); }

    syncExpanded();
  }

  /* ---------------- radio cards ----------------

     CSS handles the picked state with :has() where it exists; mirroring it
     onto a class keeps the choice visible on older engines. This runs for
     every .choices group on the page, not just the add form. */

  function syncChoices(root) {
    each('.choice input[type=radio]', root || document, function (r) {
      var box = r.closest ? r.closest('.choice') : r.parentNode;
      if (box) box.classList.toggle('on', r.checked);
    });
  }
  each('.choice input[type=radio]', document, function (r) {
    r.addEventListener('change', function () { syncChoices(); });
  });
  syncChoices();

  /* ---------------- add form: credential fields ---------------- */

  var add = document.getElementById('addForm');
  if (add) {
    var guest = document.getElementById('guestFields');
    var token = document.getElementById('tokenFields');

    var syncType = function () {
      var picked = add.querySelector('input[name=auth_type]:checked');
      var isToken = picked && picked.value === 'access_token';
      guest.hidden = isToken;
      token.hidden = !isToken;
      /* required only on the visible half, or the browser refuses to submit
         over a hidden empty field it cannot scroll to */
      each('input', guest, function (i) { i.required = !isToken; });
      each('input', token, function (i) { i.required = isToken; });
    };

    each('input[name=auth_type]', add, function (r) {
      r.addEventListener('change', syncType);
    });
    syncType();
  }

  /* ---------------- ban form: the end date only when it is timed ---------------- */

  var banForm = document.getElementById('banForm');
  if (banForm) {
    var untilBox = document.getElementById('banUntil');
    var untilInput = document.getElementById('bu');

    var syncBan = function () {
      var picked = banForm.querySelector('input[name=kind]:checked');
      var timed = picked && picked.value === 'until';
      untilBox.hidden = !timed;
      if (untilInput) {
        untilInput.required = !!timed;
        /* Default to a week out: a timed suspension with today's date would
           expire the moment it is set. */
        if (timed && !untilInput.value) {
          var d = new Date(Date.now() + 7 * 864e5);
          untilInput.value = d.toISOString().slice(0, 10);
        }
      }
    };

    each('input[name=kind]', banForm, function (r) {
      r.addEventListener('change', syncBan);
    });
    syncBan();
  }

  /* ---------------- unlimited toggle ---------------- */

  each('.js-unlimited', document, function (box) {
    var form = box.form;
    if (!form) return;
    box.addEventListener('change', function () {
      var hint = form.querySelector('.js-ulhint');
      if (hint) {
        hint.textContent = box.checked
          ? 'Keeps playing until you press Stop or the login expires.'
          : 'Stops on its own once the count is reached.';
      }
      /* The count still matters when it is switched back on, so the field is
         dimmed rather than disabled - a disabled input submits nothing, and
         the target would be lost on the way through. */
      var target = form.querySelector('[name=target_matches]');
      if (target) target.style.opacity = box.checked ? '.5' : '';
    });
  });

  /* ---------------- settings: offer a restart ----------------

     A running session already holds its settings. Asking beats either silently
     ignoring the change or killing a match without warning. */

  each('.js-quick', document, function (form) {
    form.addEventListener('submit', function (ev) {
      if (form.dataset.live !== '1' || form.dataset.asked === '1') return;

      var matches = form.querySelector('[name=target_matches]');
      var endless = form.querySelector('[name=unlimited]');
      var uid = form.querySelector('[name=uid]');
      var pw = form.querySelector('[name=password]');
      var tok = form.querySelector('[name=access_token]');

      var changed =
        (matches && String(matches.value) !== form.dataset.matches) ||
        (endless && (endless.checked ? '1' : '0') !== form.dataset.endless) ||
        (uid && uid.value !== '') ||
        (pw && pw.value !== '') ||
        (tok && tok.value !== '');

      if (!changed) return;

      ev.preventDefault();
      if (window.confirm('This process is running.\n\n'
                       + 'Restart it now so the new settings take effect?\n'
                       + 'Cancel saves them for the next start instead.')) {
        var flag = form.querySelector('.js-restart');
        if (flag) { flag.disabled = false; flag.value = '1'; }
      }
      form.dataset.asked = '1';

      /* Through the sealer, never form.submit() directly. This form carries a
         uid, a password or a token, and form.submit() fires no submit event -
         going straight to it would post all three in the clear and quietly
         undo the encryption. seal.js loads after this file, so the lookup is
         done here at submit time rather than when the listener was attached. */
      if (window.GGSeal) {
        window.GGSeal.submit(form);
      } else {
        form.submit();
      }
    });
  });

  /* ---------------- live progress ---------------- */

  var board = document.getElementById('procs') || document.getElementById('live');
  if (!board) return;

  function setText(card, sel, value) {
    var el = card.querySelector(sel);
    if (el) el.textContent = value;
  }

  function paint(card, p) {
    var target = Math.max(1, p.target_matches);
    var endless = !!p.unlimited;
    var pct = endless ? 100 : Math.min(100, Math.round(p.matches_done / target * 100));
    var live = !!LIVE[p.status];

    var bar = card.querySelector('.js-bar');
    if (bar) bar.style.width = pct + '%';

    var wrap = card.querySelector('.js-barwrap');
    if (wrap) wrap.classList.toggle('live', live);

    var count = card.querySelector('.js-count');
    if (count) {
      count.innerHTML = '<b>' + p.matches_done + '</b> / '
        + (endless ? '\u221E' : target) + ' matches';
    }

    setText(card, '.js-count-plain', p.matches_done);
    /* An unlimited run has no remaining count and no percentage to report;
       the infinity sign is the honest answer, not a large number. */
    setText(card, '.js-remaining', endless ? '\u221E' : Math.max(0, target - p.matches_done));
    setText(card, '.js-pct', endless ? '\u221E' : pct + '%');
    setText(card, '.js-region', p.region || '\u2014');
    setText(card, '.js-level', p.level_current > 0 ? p.level_current : '\u2014');
    setText(card, '.js-gain',
      p.level_current > 0 ? '+' + (p.level_current - p.level_start) : '\u2014');
    setText(card, '.js-xpstart', p.xp_start > 0 ? p.xp_start : '\u2014');
    setText(card, '.js-xpnow', p.xp_current > 0 ? p.xp_current : '\u2014');
    setText(card, '.js-xpgain',
      p.xp_gained > 0 ? '+' + p.xp_gained : (p.xp_current ? '0' : '\u2014'));

    /* The placeholder the card was drawn with is replaced the moment the bot
       reads the real name off the account. */
    if (p.account_name) setText(card, '.js-name', p.account_name);
    if (p.message) setText(card, '.js-msg', p.message);

    ['.js-dot', '.js-state'].forEach(function (sel) {
      var el = card.querySelector(sel);
      if (!el) return;
      STATES.forEach(function (s) { el.classList.remove(s); });
      el.classList.add(p.status);
      if (sel === '.js-state') el.textContent = p.status;
    });
  }

  function tick() {
    fetch('api.php?do=progress', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.processes) return;
        data.processes.forEach(function (p) {
          var card = document.querySelector('[data-pid="' + p.id + '"]');
          if (card) paint(card, p);
        });
      })
      .catch(function () { /* a dropped poll is not worth reporting */ });
  }

  /* Polling a backgrounded tab is wasted work on both ends. One second while
     the tab is in front of you: the endpoint reads a local status file the bot
     writes, so this costs nothing on the game's side. */
  var timer = null;
  function start() { if (!timer) { tick(); timer = setInterval(tick, 1000); } }
  function stop() { if (timer) { clearInterval(timer); timer = null; } }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { stop(); } else { start(); }
  });
  start();
})();
