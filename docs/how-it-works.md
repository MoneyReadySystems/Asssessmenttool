# How the Money Ready quiz works

For colleagues at Money Ready and for DigitalFootprints. Each section gives a
plain explanation first, then a **one level deeper** note with the technical
detail. Read as much of each as you need.

**Last verified against the running tool on 25 September 2026.** Figures come
from measurements against the real site and real Buffer account, not
estimates. Where something has not been tested, it says so.

---

## In one paragraph

A visitor answers four short questions about what they want to learn, how
confident they feel, their main money goal, and how they like to learn. The
tool narrows Money Ready's content down to the topics they picked, asks Claude
to choose the three to five most useful items and write a sentence explaining
each choice, then shows those as cards. Content comes from the Learning Hub and
from approved social posts. It installs as a WordPress plugin and is placed on
a page with the `[finance_quiz]` shortcode.

---

## 1. What a visitor sees

1. Four questions, one per screen, with a progress bar.
2. A loading screen with rotating messages, typically six to fifteen seconds.
3. Three to five cards, each with a title, one sentence on why it suits them,
   and a button per place the content lives — "Read article", "View on
   Instagram", "View on TikTok", "Watch on YouTube".
4. A link to the Learning Hub, and a "Start again" button.

The only thing Claude writes that a visitor reads is that one sentence per
card. Titles, links and descriptions all come from our own records.

> **One level deeper.** The whole interface is one shortcode, rendering inline
> CSS and JavaScript with no build step and no external assets. Everything is
> scoped under `#fq-wrap`. Several rules carry `!important` with a comment
> explaining which theme rule they beat — the honeycom3 theme applies
> `margin: 0 auto` widely and shrinks grid items to their content, and both
> have broken this layout more than once. They are load-bearing, not
> sloppiness.

---

## 2. How a recommendation is produced

Seven steps, all server-side except the first and last.

1. **The browser posts the answers** to WordPress.
2. **Bot checks.** A honeypot field, a signed timing token proving at least
   eight seconds passed, and a rate limit of ten requests per ten minutes per
   IP address. Failing the first two returns fallback content rather than an
   error.
3. **Answer cache.** Identical answers within 24 hours return the previous
   result without calling Claude at all.
4. **Topic filter.** The library is reduced to items carrying at least one
   topic the visitor selected. If nothing matches, the tool says so honestly
   rather than substituting unrelated content.
5. **The prompt is assembled** from `data/prompt.md`, the visitor's answers,
   and the filtered library as JSON.
6. **Claude replies** with a list of URLs and a reason for each, constrained
   by a schema so the reply cannot be malformed.
7. **Everything is re-checked against our records.** Any URL we do not hold is
   discarded; title, description, format and links are taken from our own
   library, not from the model. Near-identical titles are collapsed.

Step 7 is the important one. Claude chooses and explains; it cannot invent a
link or misdescribe an article, because only its `reason` text survives.

> **One level deeper.** `fq_proxy_handler()` in `includes/quiz.php`. Requests
> are **form-encoded with the payload carried as a single JSON field**, not
> posted as `application/json`. This matters: WordPress routes on
> `$_REQUEST['action']` and `check_ajax_referer()` reads `$_REQUEST['nonce']`,
> and PHP never populates `$_POST` from a JSON body. The original build posted
> JSON, so **every request was rejected with HTTP 400 before reaching a
> handler** — recommendations, analytics and the fallback path alike. Do not
> "tidy" this back to a JSON content type.
>
> The model is `claude-opus-5` at `effort: low`, with a JSON schema via
> `output_config.format`. Response parsing scans for the first text block
> rather than assuming `content[0]`, because thinking is on by default and
> `content[0]` is a thinking block. Server timeout 30s, browser abort 35s; the
> browser must always allow longer than the server.

---

## 3. Where the content comes from

Three sources, in order of how much they matter.

1. **Learning Hub articles** — 50 of them, live from WordPress. An article
   qualifies by being the `library` post type and carrying at least one
   `topic` term that `topics.json` maps.
2. **Approved social posts** — around 45, synced weekly from Buffer and
   filtered by Claude. Covered in section 5.
3. **Four hardcoded fallbacks**, used only when something fails. They are the
   safety net, not part of the pool.

Descriptions are resolved in order of preference: the Yoast meta description
first (a purpose-written summary), then the excerpt, then the article body,
then the title. Across the 50 articles: 29 come from Yoast, 21 from the body.

> **One level deeper.** `fq_resolve_description()`. The body path exists
> because `library` posts store their content as a single ACF block, which in
> the database is an HTML *comment* containing JSON. `wp_strip_all_tags()`
> discards comments wholesale, so the original code's
> `wp_trim_words( strip_shortcodes( $post->post_content ), 30 )` returned an
> empty string for every article — measured at 34,875 characters in, 0 out.
> Every article reached the model with a blank description.
>
> Note the taxonomy trap, which cost this project months: the taxonomy is
> **`topic`**. `library-topic` is the query-string parameter the Learning Hub
> filter uses in URLs, which the theme translates internally. It is not a
> taxonomy. The original build used it as one, and also queried post type
> `post` (of which the site has zero), so the article half of the recommender
> returned **nothing at all** for its entire life. Measured: the old query
> returns 0 posts, the corrected one 50.

---

## 4. `topics.json` — the shared vocabulary

One file, at `money-ready-quiz/data/topics.json`, is the single source of truth
for topics. It drives the quiz checkboxes, the content filter, and the
classification of incoming social posts. Add a topic there and all three pick
it up.

Ten topics are live: Banking, Big Money Lessons, Borrowing, Budgeting, Car
Ready, Income, Saving, Spending, Scams, Student living.

Four are declared but hidden (`status: planned`) because no content carries
them: Insurance, Pensions, Renting & Mortgages, Money & Mental Health.
Offering a checkbox that leads nowhere is worse than not offering it. Flip one
to `live` the moment content exists.

> **One level deeper.** Each entry maps a quiz slug to one or more WordPress
> `topic` term slugs, which is how `earning` → income, `staying-safe` → scams
> and `university` → student are reconciled. A malformed file is ignored, a
> built-in fallback list is used, and a warning is logged — the quiz stays up.
> The library cache key is fingerprinted with the topic mapping, so edits take
> effect immediately rather than waiting out the hourly cache.

---

## 5. Social posts: sync, classify, review

**Plain version.** Once a week the tool pulls Money Ready's published social
posts from Buffer, groups together the copies posted to different channels,
and asks Claude which ones actually teach something about money. Only those
can be recommended. Anything borderline waits for a person.

This filtering is not a nicety. Of roughly 480 posts, only about 45 are
teaching content — the rest are fundraising, awards, job adverts, event
recaps and media appearances. Without it, someone asking for budgeting help
could be shown a job vacancy.

Three statuses:

| Status | Meaning |
|---|---|
| Approved | Teaches something useful. **Only these are recommended.** |
| Excluded | About the organisation, not about money. |
| Needs review | Borderline. Waits for a person in the admin queue. |

**A person's decision is never overwritten.** Once someone sets a status by
hand, later syncs update the text and links but leave the decision alone.

**Why weekly rather than nightly.** About nine approved items arrive a month,
so a nightly run mostly found nothing and scattered the review queue into ones
and twos. Weekly gives a batch worth sitting down with.

**One content item, several links.** The same post often runs on two or more
channels — 69% of approved items do, and one ran on five. Rather than picking
one arbitrarily, the card offers every channel as its own button.

> **One level deeper.** Buffer's API is **GraphQL**, not REST: a single
> `POST https://api.buffer.com` with a Bearer token. `includes/buffer-sync.php`
> holds the query as a constant and passes everything variable as a typed
> GraphQL variable, so no value can alter the query's shape.
> `fq_buffer_request()` is the only function that talks to Buffer and accepts
> variables only, never a query. That is deliberate and should stay:
> Buffer's token has no read-only scope, and the endpoint that reads posts can
> also delete them.
>
> Identity is a fingerprint — lowercase, strip URLs, `@mentions`, `#hashtags`,
> emoji and punctuation, then the first 20 words. Raw text does not match
> across channels because copy is edited per platform. A second, stricter
> guard runs at recommendation time and drops near-identical titles among the
> chosen three to five. Storage is permissive and presentation is strict,
> because the similarity level needed to catch every duplicate in storage also
> merges genuinely different content — two job adverts differing only by
> "South Wales".
>
> Posts with empty text, expired stories, or no permalink are dropped: about a
> fifth of what Buffer returns. Classification runs in batches of 40 at
> `effort: medium`; a full pass over 477 items took about five minutes.
>
> **YouTube needs no separate integration.** It is published through Buffer —
> verified by comparing against the channel's RSS feed, where all nine videos
> in the overlapping window were present in Buffer.

---

## 6. Caching

| What | How long | What clears it |
|---|---|---|
| Content library | 1 hour | Publishing a post; approving a social item; editing `topics.json` |
| A set of answers | 24 hours | Nothing — it expires |
| Rate limit | 10 minutes | Expires |

The answer cache is shared, not per visitor: two people giving identical
answers get the same result, and the second costs nothing.

> **One level deeper.** Both are WordPress transients. Two known gaps, neither
> serious: publishing a new article clears the library cache but **not** stored
> answer sets, so a new article can be missing from a previously-cached answer
> combination for up to 24 hours; and the invalidation hook fires only on
> publish, so unpublishing or binning an article leaves it recommendable for up
> to an hour.

---

## 7. What is measured

Quiz completions and card clicks, in a `wp_fq_events` table. **No user
identity is stored** — no names, emails, IPs or cookies identifying anyone.

The dashboard at **Quiz Analytics** shows completions, clicks,
click-through rate, most-clicked articles, most-selected topic combinations,
most common goals, and where results came from (AI, cached, fallback, or no
match). CSV export is available.

Two things worth watching:

1. **"No matching content"** tells you which topic combinations visitors want
   and you cannot serve. That is content planning data.
2. **The setup check** at the top of the same screen flags a missing API key,
   an unscheduled job, a broken data file or disabled cron. Nearly every
   failure on this project has been silent; this surfaces that class of
   problem at a glance.

> **One level deeper.** Events are logged asynchronously with `keepalive`, so
> logging never slows the visitor down. GA4 events fire in parallel **only if
> `gtag` is already on the page** — the tool never loads it. If the theme does
> not load GA4 on the quiz page, no GA4 event is sent, silently. Database
> logging is unaffected.

---

## 8. Credentials

Nothing is stored in the plugin. Three constants go in `wp-config.php`, each
on its own line, above the "stop editing" comment and **not inside a comment
block** — pasting inside the `/* Add any custom values */` comment silently
disables them, which has already happened once.

```php
define( 'FQ_API_KEY',            'sk-ant-...' );  // Anthropic
define( 'FQ_BUFFER_TOKEN',       '...'        );  // Buffer
define( 'FQ_GA4_MEASUREMENT_ID', 'G-...'      );  // optional
```

**Being a plugin does not improve key security** — the keys live in
`wp-config.php` either way. The Buffer token should come from a dedicated
limited team account rather than an owner's, because Buffer offers no
read-only scope and the same endpoint that reads can also write.

Cost is minor: roughly 2p per recommendation, and repeats within 24 hours are
free.

---

## 9. When something goes wrong

The tool is built to degrade rather than break. In rough order of severity:

1. **No matching content** → an honest message and popular guides, logged
   distinctly so you can see the gap.
2. **The API is slow or fails** → hardcoded fallback recommendations with a
   note explaining.
3. **Claude returns URLs we do not hold** → those are discarded; if none
   survive, fallbacks.
4. **`topics.json` or a prompt file is broken** → a built-in version is used
   and a warning logged. The quiz stays up, but edits to that file stop having
   any effect.
5. **The Buffer sync fails midway** → pages already fetched are kept. A failed
   classification batch stops the run; those items stay unclassified and are
   retried next week.
6. **Everything fails** → a short message pointing at the Learning Hub.

---

## 10. What has actually been run

Verified by execution against a local copy of the production site:

1. A full quiz submission, through `admin-ajax.php`, against real content and
   the real API, returning sensible recommendations.
2. Library builds with 50 articles plus approved social posts; zero blank
   descriptions.
3. All 10 live topics return content; the 4 hidden ones are correctly absent.
4. Analytics logging works, including `article_topic`, which was empty in
   every row before it was fixed. A bad nonce is rejected with 403.
5. Buffer sync: 1,000 posts fetched, 477 unique items, idempotent on re-run.
6. Classification: 477 items decided, no invented topic names.
7. Plugin lifecycle: activation creates the table and schedules both jobs;
   deactivation unschedules them and keeps all data.
8. The quiz clicked through in a browser, on the real theme.

**Not yet tested:** mobile layout, screen-reader behaviour since the card
markup changed, activation on a clean install where the events table has never
existed, and everything under real traffic on staging.

---

## 11. Open questions and limitations

### For DigitalFootprints — please answer before staging

1. **Does the site use full-page caching?** If so it will break this tool. The
   security nonce and the anti-bot timing token are both written into the page
   HTML when it renders. Under a page cache the nonce goes stale within 12–24
   hours and **every visitor silently gets fallback results**, while the timing
   check stops working. This needs resolving against your actual cache
   configuration; it is not a problem we can fix blind.
2. **Is the site behind a CDN or reverse proxy?** The rate limiter keys on
   `REMOTE_ADDR`. Behind a proxy that is the proxy's address, so all visitors
   share one bucket and the whole site locks out after ten quiz submissions. If
   the site is fronted, we need to read the forwarded-for header *you* set —
   never a client-supplied one.

### Known limitations

1. **Eight of the nine Car Ready articles have no summary text** anywhere, so
   they reach the model as a title alone. Their titles are descriptive enough
   to work, but a Yoast meta description on each is the highest-value editorial
   fix available.
2. **Two Learning Hub articles carry no topic** and can never be recommended:
   "Five top tips for your finances when starting a family" and "5 money
   lessons children should learn before they're 12".
3. **The approval rate is a judgement call.** Of 477 social items, 9.4% were
   approved. A 40-item sample earlier approved 25%. The difference is largely
   one rule — that an announcement about content is not itself content. That
   line is editable in `data/classify-prompt.md` and is the biggest lever on
   how much social content reaches visitors.
4. **Session tracking is not built.** Following a learner from the quiz into
   the Learning Hub would need a first-party cookie, which changes the data
   from unlinkable events to a linkable journey. That has cookie-consent
   implications and wants a deliberate decision rather than being slipped in.
5. **Latency is unmeasured on real hardware.** On the local development stack
   a full submission took 6–27 seconds, but most of that was the local
   environment rather than the API, which measured about 6 seconds. Staging
   will give the real figure.

---

## Appendix: what is where

```
money-ready-quiz/
  money-ready-quiz.php     plugin header, activation, setup health check
  includes/
    quiz.php               topics, library, the recommender, shortcode, dashboard
    social-posts.php       the social post store and review queue
    buffer-sync.php        weekly pull from Buffer
    classify.php           weekly classification
  data/
    topics.json            the topic vocabulary
    prompt.md              what Claude is told when recommending
    classify-prompt.md     what Claude is told when classifying
  uninstall.php            removes settings, deliberately keeps your data
```

Both prompt files are editable without a developer. Everything above the
`--- PROMPT ---` line in each is notes for the editor and is not sent.
