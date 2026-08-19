# Running it

## Layout on the server

The single rule: **`src/`, `.env` and `storage/` must sit outside the web root.**
Inside `public_html` a server misconfiguration serves the `.env` as plain text
and leaks the Shopmonkey token.

```
/home/USER/
  shopvoice/                  ← outside the web root
    .env                      ← the token lives here, chmod 600
    src/
    config/
    tools/
    storage/logs/
    public_html/              ← symlink or DocumentRoot target for the subdomain
```

On cPanel, point a subdomain (say `bay.shybesautomotive.com`) at
`/home/USER/shopvoice/public_html`. Do not put the project inside the account's
main `public_html`.

```bash
chmod 600 ~/shopvoice/.env
chmod -R 750 ~/shopvoice/storage
```

## First run

```bash
cp .env.example .env      # then fill it in
php tools/migrate.php     # applies schema, syncs users, prints the posture
php tests/run.php         # 130+ checks, no database server or keys needed
```

`tools/migrate.php` finishes by printing which way every seam is thrown. Read
it. It is the fastest way to catch a deploy that is quietly running on fixture
data or a missing key.

## Enrolling a tablet

The tablet never receives a long-lived credential by any route but this one.

```bash
php -r 'require "src/autoload.php"; $a=ShopVoice\App::boot();
        echo $a->auth()->createEnrollmentCode("Bay 1"), "\n";'
```

Type the code into the tablet's setup screen. It trades the code for a device
token, once, and the code is then dead. To retire a stolen tablet:

```bash
php -r 'require "src/autoload.php"; $a=ShopVoice\App::boot();
        $a->auth()->revokeDevice("DEVICE_ID");'
```

That also ends any live session on it.

## Cron

```
# Nightly auto-logout (§7), at the hour in NIGHTLY_LOGOUT_AT, shop local time.
0 3 * * * /usr/local/bin/php /home/USER/shopvoice/tools/nightly_logout.php >> /home/USER/shopvoice/storage/logs/cron.log 2>&1
```

It closes every open session, sweeps inspections somebody walked away from, and
retries any note sync. The sync is a no-op while the note endpoint is locked
(§2) and becomes live the moment it is verified.

## Switching seams

Everything below is a `.env` change plus a page reload. No code, no deploy.

```bash
# Move off the free tier
INTENT_PROVIDER=groq        →   INTENT_PROVIDER=openai

# Go live against Shopmonkey (only after §2 is reviewed and endpoints corrected)
SHOPMONKEY_MODE=fixture     →   SHOPMONKEY_MODE=live
SHOPMONKEY_TOKEN=sk_live_...

# Push notes to Shopmonkey (also needs note.create marked verified in config/)
NOTE_STORE=local            →   NOTE_STORE=shopmonkey
```

A `live` mode with no token falls back to fixture data and logs
`shopmonkey.live_without_token` rather than going dark.

## Local development

```bash
php -S 127.0.0.1:8099 -t public_html tools/dev_server.php
```

Then `http://127.0.0.1:8099/bay/` for the client, or drive it from the command
line without a microphone at all:

```bash
php tools/say.php "pull up the RO for the silver Tahoe" \
                  "when did we last do brakes on this one" \
                  "make a note left front caliper is possibly sticking" \
                  "undo that"
```

Set `DB_DSN=sqlite:/absolute/path/dev.sqlite` in `.env` to skip MySQL entirely.

## Logs

Line-delimited JSON, one file per day, in `storage/logs/`. The two worth
watching:

```bash
# Everything the model produced that the whitelist refused — this is the only
# feedback loop the prompt has (§4).
php -r '$db=new PDO("mysql:...","user","pass");
        foreach ($db->query("SELECT raw_transcript, action, reject_reason, confidence
                             FROM intent_log WHERE accepted = 0
                             ORDER BY created_at DESC LIMIT 50") as $row) print_r($row);'

# Rate limits and provider trouble
grep intent.provider_error storage/logs/shopvoice-*.log
```

`intent_log` keeps transcripts verbatim on purpose. A cleaned-up transcript
would hide exactly the misfires worth tuning for.
