# Deploying the Money Ready quiz

For DigitalFootprints. Written to be followed in order.

The tool is a self-contained WordPress plugin. There is no build step, no
Composer dependency, no npm, and no external assets to serve.

---

## Before you start — two questions we need answered

These are not blockers to installing, but they are blockers to the tool
working correctly for visitors, and we cannot answer them from outside.

### 1. Does this site use full-page caching?

**If it does, the tool will break in a way that looks like it is working.**

The security nonce and the anti-bot timing token are both written into the
page HTML at render time. Under a full-page cache:

- the nonce goes stale within 12–24 hours, after which every AJAX request is
  rejected and **every visitor silently receives fallback recommendations**
  rather than personalised ones;
- the timing token is served from cache with an old timestamp, so the
  eight-second bot check passes instantly and stops doing its job.

Nothing errors. The quiz appears to work. It just quietly stops being
personalised.

**What we need from you:** either exclude the quiz page from the page cache,
or tell us the caching layer in use so the nonce and token can be fetched
separately at run time instead of baked into the HTML.

### 2. Is the site behind a CDN or reverse proxy?

The rate limiter keys on `REMOTE_ADDR`. Behind a proxy that is the proxy's
address, not the visitor's — so every visitor shares one bucket and **the
whole site locks out after ten quiz submissions in ten minutes.**

**What we need from you:** confirmation of whether the site is fronted, and if
so which forwarded-for header *you* set. We will read that one specifically.
We will not trust a client-supplied header, since it is trivially spoofed.

---

## 1. Install

1. Copy the `money-ready-quiz` folder into `wp-content/plugins/`.
2. Activate **Money Ready Quiz** in Plugins.
3. Activation creates the `wp_fq_events` table, registers the Social posts
   type and schedules two weekly jobs. You should see a confirmation notice.

Nothing else is written outside the plugin folder, the `wp_fq_events` table,
the `fq_social_post` entries, and a handful of options prefixed `fq_`.

## 2. Add the credentials

In `wp-config.php`, **each on its own line**, above the
`/* That's all, stop editing! */` comment and **not inside a comment block**:

```php
define( 'FQ_API_KEY',            'sk-ant-...' );  // Anthropic
define( 'FQ_BUFFER_TOKEN',       '...'        );  // Buffer
define( 'FQ_GA4_MEASUREMENT_ID', 'G-...'      );  // optional
```

> Worth stating because it has already caught us once: pasting a key *inside*
> the `/* Add any custom values between this line and the "stop editing"
> line. */` comment silently disables it. PHP sees nothing, no error is
> raised, and the plugin falls back to a placeholder.

**If you add the credentials after activating,** deactivate and reactivate the
plugin. The scheduled jobs are only created when the relevant credential is
present.

## 3. Check the setup

Go to **Quiz Analytics** in the admin menu. The Setup check panel at the top
verifies eight things: both API keys, the topic registry, the content
taxonomy, the events table, both scheduled jobs, and whether WP-Cron is
disabled. Every line should be green before going further.

## 4. Populate the social content

On the same screen:

1. Click **Full resync** under "Social posts from Buffer". Expect roughly
   1,000 posts fetched and about 480 unique items, taking around 30 seconds.
2. Click **Classify now**. This takes about five minutes for a first run and
   calls the Anthropic API in batches.
3. Around 45 items will be approved, and roughly 20 will need a person to
   look at them under **Social posts**.

After the first run this happens automatically each Monday: the sync at 03:00
and classification at 04:00, site time.

## 5. Place the quiz

Add the shortcode to a page:

```
[finance_quiz]
```

No arguments. It renders the whole interface.

---

## Things worth knowing

### WP-Cron

The weekly jobs use WP-Cron, which only fires when someone visits the site. On
a quiet site they can run late. If `DISABLE_WP_CRON` is set — the setup check
will tell you — a real system cron calling `wp-cron.php` is needed, or the
sync will never run.

### Outbound connections

The plugin makes outbound HTTPS requests to exactly two hosts:

| Host | When | Why |
|---|---|---|
| `api.anthropic.com` | On a quiz submission, and weekly | Recommendations, classification |
| `api.buffer.com` | Weekly | Fetching published social posts |

Both use `wp_remote_post`, so they respect any WordPress-level proxy
configuration. If outbound traffic is restricted, these two need allowing.

### Performance

- A quiz submission makes one API call, typically about six seconds. Repeat
  submissions with identical answers are served from a 24-hour cache and make
  no call at all.
- The content library is cached for an hour and rebuilt on publish.
- On a normal page load the plugin does one autoloaded option read beyond the
  usual WordPress work.
- Timeouts are 30s server-side and 35s in the browser. These were raised from
  20s/22s after a real run took 27s on a slow environment. Please **re-measure
  on staging** before tightening them.

### Editable without a developer

Three files in `money-ready-quiz/data/` are intended to be edited directly:

| File | Controls |
|---|---|
| `topics.json` | The topic list driving the quiz, the filter and classification |
| `prompt.md` | What Claude is told when recommending |
| `classify-prompt.md` | What Claude is told when deciding what counts as learning content |

Changes take effect on the next request. In the two prompt files, everything
above the `--- PROMPT ---` line is notes for the editor and is not sent.

### Deactivation and deletion

- **Deactivating** unschedules the weekly jobs and changes nothing else. All
  data is kept.
- **Deleting** removes settings and caches but **deliberately keeps** the
  `wp_fq_events` analytics table and the social posts, including every human
  review decision. The SQL to remove those on purpose is commented in
  `uninstall.php`.

---

## What has not been tested

Stated plainly so it can be watched for on staging:

1. **Activation on a clean install** where `wp_fq_events` has never existed.
   Our test environment already had the table.
2. **Mobile layout.** Only desktop widths have been checked.
3. **Screen-reader behaviour** since the result card markup changed.
4. **Anything under real traffic**, including whether WP-Cron fires reliably
   at this site's volume.
5. **Real-world latency.** Every figure above comes from a local development
   stack, which is slower than real hosting in some respects and faster in
   others.

---

## If something looks wrong

1. **Check Quiz Analytics → Setup check first.** It catches most of it.
2. **Everyone gets generic recommendations** → almost certainly the page-cache
   problem above, or a missing/invalid `FQ_API_KEY`.
3. **No social posts appear** → check the Buffer token, then the last sync
   result on the analytics screen, then whether items are sitting in **Social
   posts** waiting for review. Nothing is recommended until approved.
4. **Look in the PHP error log.** Everything the plugin logs is prefixed
   `[finance-quiz]` or `[money-ready-quiz]`, with the reason spelled out
   rather than a code.
