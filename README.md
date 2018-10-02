# Price Snitch

A simple scraper for a popular apparel site to check prices on items and email the user when they drop. Manages and updates a Google Sheet for easy client CMS.


price_config.php (to be placed in top-level directory)
```php
$SPREADSHEET_ID = array(
	"production" 	=> // Google Sheet ID
);
$MAIL_CLIENT_ID = // Gmail Client ID;
$MAIL_CLIENT_SECRET = // Gmail Client Secret;
$EMAIL_SMTP = // Gmail SMTP Server address
$EMAIL_USER = // Gmail User
$EMAIL_PW   = // Gmail Password;

```
