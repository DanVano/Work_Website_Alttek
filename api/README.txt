Contact Form Backend (PHP)
----------------------------

This site uses a self-hosted PHP endpoint:

  /api/contact.php

It uses PHP's built-in mail() function.
- On IONOS shared hosting, mail() often works out-of-the-box.
- If you later want SMTP (IONOS mail or Microsoft 365), we can swap this handler to SMTP.

If emails don't arrive:
1) Confirm your hosting plan supports mail() / sendmail.
2) Ensure FROM_EMAIL (inside contact.php) uses your domain (alttek.ca).
3) Check spam/junk folder.

Rate limiting data is stored in /data/rate_limit.json
/data is protected by .htaccess (Apache). If you're on a different server, restrict access to /data.
