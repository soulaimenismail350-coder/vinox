/* Encrypt the sensitive fields of a form before the browser posts it.
 *
 * A form marked data-seal="uid,password" has those two inputs collected into a
 * JSON object, encrypted to the server's public key, and sent as one opaque
 * "sealed" field. The originals are emptied and disabled, so they are not part
 * of the request body at all.
 *
 * ECDH on P-256, once per submit: a throwaway keypair, one derivation against
 * the server's fixed public key, SHA-256 of the shared secret as an AES-256-GCM
 * key. Sub-millisecond, no round trip, nothing reused between submits. The PHP
 * side is unseal_request() in includes/crypto.php and does the same three steps
 * in reverse.
 *
 * What it is for: TLS already stops anyone on the wire reading the body, but
 * everything that legitimately terminates TLS on the way past - a CDN, a WAF,
 * a proxy logging POST bodies, an error tracker - sees the plaintext. This
 * closes that middle. It is not a replacement for HTTPS and does not pretend
 * to be one.
 *
 * Every failure path falls back to a normal form post. No key in the page, no
 * WebCrypto, an old browser, a page served over plain http where crypto.subtle
 * does not exist - the form submits as it always did rather than trapping
 * somebody on a page whose button does nothing.
 */
(function () {
  'use strict';

  var meta = document.querySelector('meta[name="seal-key"]');
  var SERVER_KEY = meta ? (meta.getAttribute('content') || '') : '';
  var subtle = (window.crypto && window.crypto.subtle) ? window.crypto.subtle : null;
  var able = !!(SERVER_KEY && subtle && window.TextEncoder);

  function toBytes(b64) {
    var bin = atob(b64);
    var out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
  }

  function toB64(buf) {
    var bytes = new Uint8Array(buf);
    var bin = '';
    /* In chunks: String.fromCharCode.apply on a long token blows the argument
       limit in some browsers, and a token is exactly the long case here. */
    for (var i = 0; i < bytes.length; i += 0x8000) {
      bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    }
    return btoa(bin);
  }

  function fieldsOf(form) {
    var names = (form.getAttribute('data-seal') || '').split(',');
    var found = [];
    for (var i = 0; i < names.length; i++) {
      var name = names[i].trim();
      if (!name) continue;
      var el = form.querySelector('[name="' + name + '"]');
      /* A disabled input is one this page deliberately left out of the body -
         see the read-only role select - and must stay out of it. */
      if (el && !el.disabled) found.push(el);
    }
    return found;
  }

  function seal(form) {
    var fields = fieldsOf(form);
    if (!able || !fields.length) {
      return Promise.resolve(false);
    }

    var payload = {};
    for (var i = 0; i < fields.length; i++) {
      payload[fields[i].name] = fields[i].value;
    }

    var iv = window.crypto.getRandomValues(new Uint8Array(12));
    var ephemeral;

    return subtle.importKey(
      'spki', toBytes(SERVER_KEY), { name: 'ECDH', namedCurve: 'P-256' }, false, []
    ).then(function (serverKey) {
      return subtle.generateKey(
        { name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']
      ).then(function (pair) {
        ephemeral = pair;
        return subtle.deriveBits(
          { name: 'ECDH', public: serverKey }, pair.privateKey, 256
        );
      });
    }).then(function (shared) {
      return subtle.digest('SHA-256', shared);
    }).then(function (raw) {
      return subtle.importKey('raw', raw, { name: 'AES-GCM' }, false, ['encrypt']);
    }).then(function (aes) {
      return subtle.encrypt(
        { name: 'AES-GCM', iv: iv },
        aes,
        new TextEncoder().encode(JSON.stringify(payload))
      );
    }).then(function (cipher) {
      return subtle.exportKey('spki', ephemeral.publicKey).then(function (pub) {
        var box = form.querySelector('input[name="sealed"]');
        if (!box) {
          box = document.createElement('input');
          box.type = 'hidden';
          box.name = 'sealed';
          form.appendChild(box);
        }
        box.value = [toB64(pub), toB64(iv), toB64(cipher)].join('.');

        /* Emptied and disabled. Disabling is what actually keeps them out of
           the body; emptying is so nothing is left in the DOM for a script or
           a screenshot to pick up afterwards. */
        for (var j = 0; j < fields.length; j++) {
          fields[j].value = '';
          fields[j].disabled = true;
        }
        return true;
      });
    });
  }

  /* The single way anything in this codebase submits a form that might carry a
     credential. app.js calls it too, because form.submit() fires no submit
     event and would sail straight past the listener below. */
  function submit(form) {
    if (form.dataset.sealDone === '1') {
      form.submit();
      return;
    }
    form.dataset.sealDone = '1';
    seal(form).then(function () {
      form.submit();
    }, function () {
      /* Encryption failed on a browser that claimed to support it. Posting in
         the clear is worse than posting sealed and better than a button that
         silently does nothing, which is the only third option. */
      form.submit();
    });
  }

  window.GGSeal = { submit: submit, enabled: able };

  var forms = document.querySelectorAll('form[data-seal]');
  for (var i = 0; i < forms.length; i++) {
    (function (form) {
      form.addEventListener('submit', function (ev) {
        if (!able || form.dataset.sealDone === '1') return;
        ev.preventDefault();
        submit(form);
      });
    })(forms[i]);
  }
})();
