<?php
declare(strict_types=1);
const SITE_NAME = 'FREE FIRE LEVEL';
const SITE_ICON = '/fflevelpro.png';
const SITE_URL = 'https://ffgoatpro.online';
const CLEAN_URLS = true;
const AUTOSTART_ON_ADD = true;
function site_name(): string { return trim(SITE_NAME) !== '' ? trim(SITE_NAME) : 'Panel'; }
function site_icon(): string { return trim(SITE_ICON); }
