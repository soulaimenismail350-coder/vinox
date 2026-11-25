<?php
declare(strict_types=1);
const SITE_NAME = 'FREE FIRE LEVEL';
const SITE_ICON = '/fflevelpro.png';
const SITE_URL = 'https://ffgoatpro.online';
const CLEAN_URLS = true;
const TURNSTILE_SITE_KEY = '0x4AAAAAAEtRd4iY6SiJ06ED';
const TURNSTILE_SECRET   = '0x4AAAAAAEtRd9Qd9bSvJXa8TvSrP9FSdrU';
const AUTOSTART_ON_ADD = true;
function site_name(): string { return trim(SITE_NAME) !== '' ? trim(SITE_NAME) : 'Panel'; }
function site_icon(): string { return trim(SITE_ICON); }
function turnstile_enabled(): bool { return trim(TURNSTILE_SITE_KEY) !== '' && trim(TURNSTILE_SECRET) !== ''; }
