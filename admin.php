<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

/* The admin panel is two pages now, Users and Keys, so there is no Overview
   entry in the rail or the tabs. The numbers that used to live here sit at
   the top of the Users page instead.

   This file stays as a redirect rather than being deleted: old bookmarks and
   any link still pointing at admin.php should land somewhere useful instead
   of on a 404. */

/* Staff, not admins: a seller has a Users page too, and this is the address
   the breadcrumb on it points at. */
require_staff();
redirect('admin_users.php');
