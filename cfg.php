<?php
declare(strict_types=1);
const SITE_NAME = 'FREE FIRE LEVEL';
const SITE_ICON = '/fflevelpro.png';
const SITE_URL = 'https://ffgoatpro.online';
const CLEAN_URLS = true;
/* Cloudflare Turnstile is switched off.
 *
 * The widget these keys belonged to did not have ffgoatpro.online in its
 * hostname list, so visitors got error 110200 ("Domain not authorized") and
 * could not sign in, register or add a process. Empty keys make
 * turnstile_enabled() false: no script is loaded, no widget is rendered, and
 * no token is demanded by the three forms that used to check one.
 *
 * To bring it back, add the real hostname to the widget in Cloudflare and put
 * that widget's own site key and secret here. */
const TURNSTILE_SITE_KEY = '';
const TURNSTILE_SECRET   = '';
const AUTOSTART_ON_ADD = true;
function site_name(): string { return trim(SITE_NAME) !== '' ? trim(SITE_NAME) : 'Panel'; }
function site_icon(): string { return trim(SITE_ICON); }
function turnstile_enabled(): bool { return trim(TURNSTILE_SITE_KEY) !== '' && trim(TURNSTILE_SECRET) !== ''; }
