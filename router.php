router.php has been removed.

It only existed to route PHP's built-in development server, and a bug in it
broke every clean URL - /login and everything else fell through to
index.php instead of reaching the right page.

Removing the file does not fix that by itself: PHP's built-in server falls
back to index.php for any path that isn't a real file, with or without a
router. Confirmed by testing - requesting /login with no router.php present
still returns the landing page, not the sign-in page.

Test by hand with the real filename instead:

    php -S 127.0.0.1:8080
    curl http://127.0.0.1:8080/login.php      # correct
    curl http://127.0.0.1:8080/login          # returns index.php, not login

Clean addresses (/login instead of /login.php) only exist under a real web
server such as nginx with a rewrite rule, which is how the site is meant to
run in production. Delete this file:

    rm router.php
