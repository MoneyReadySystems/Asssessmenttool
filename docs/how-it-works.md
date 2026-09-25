# How the financial literacy assessment tool works

**Who this is for:** colleagues at Money Ready who need to understand what the
tool does and why, and DigitalFootprints, who host and deploy the site it runs
on.

**How to read it:** every section gives a plain explanation first. Where there
is more to know, a **One level deeper** note follows with the technical detail.
Stop at the plain version or carry on — both are complete in themselves.

**A word on certainty.** This project has a history of confident documentation
turning out to be wrong. The original audit stated the tool queried a taxonomy
called `library-topic`; no such taxonomy exists or ever did. The real taxonomy
is `topic`, and `library-topic` is only the name of a query-string parameter in
Learning Hub URLs. The tool was also querying the wrong post type. Between them,
those two errors meant the article half of the recommender returned zero results
for its entire life, undetected. So this document distinguishes carefully
between what has been run and measured, and what is believed but unverified.
Section 10 collects everything in the second category.

---

## 1. What a visitor sees

1. They land on a page containing the quiz (embedded with the shortcode
   `[finance_quiz]`).
2. They answer four questions: which money topics interest them (tick as many as
   they like), how confident they feel about managing money, their biggest
   current money goal, and how they prefer to learn.
3. A short loading animation plays while the recommendation is worked out.
4. They get three to five cards. Each shows a one-line reason it was picked, a
   title, a description, and a badge saying whether it is an article, a video or
   a social post. Clicking opens it in a new tab.
5. Below the cards, a prompt to browse the whole Learning Hub, and a "Start
   again" button.

No account, no email address, nothing to fill in. Answering is the whole
interaction.

> **One level deeper.** The quiz is a single shortcode function rendering four
> `<fieldset>` steps, only one visible at a time, with a progress bar. It is
> built to WCAG 2.1 AA: radio groups are properly labelled, focus moves to each
> question's legend on navigation, focus outlines are explicit, the loading
> state is an `aria-live` region, and the results heading receives focus when
> results appear. The topic checkboxes on question one are generated at render
> time from `topics.json` rather than hardcoded, so the quiz and the content
> library can never drift apart on what a topic is.
>
> The "Next" button on each step stays disabled until that step is answered, so
> every submission has all four answers. At least one topic is always selected.

---

## 2. How a recommendation is actually produced

This is the core of the tool. Seven steps, in order.

1. **The browser submits the four answers** to the site's own server. It does
   not talk to Anthropic directly — there is no API key anywhere in the page.
2. **Bot checks run.** A hidden honeypot field and a signed timing token. If
   either fails, the visitor quietly gets a set of hardcoded fallback
   recommendations instead of AI ones. A rate limit then applies (section 8).
3. **The answer cache is checked.** If somebody has already answered this exact
   combination of four answers in the last 24 hours, the stored result is
   returned immediately and nothing further happens.
4. **The content library is assembled** — every eligible Learning Hub item plus
   the social posts (section 3). This is cached for an hour, so most requests
   do not touch the database for it.
5. **The library is filtered to the topics the visitor chose.** Only items
   genuinely carrying one of those topics survive. If nothing survives, the tool
   stops here and says so honestly (section 8).
6. **A prompt is built and sent to Claude**: the visitor's four answers, plus
   the filtered library as structured data, with a request to pick the most
   relevant items and give a one-sentence reason for each.
7. **Claude's answer is validated against the library**, the cards are rendered,
   and the result is cached for 24 hours.

Step 7 is the one that deserves emphasis. **The AI is not trusted to describe
content.** Everything on a card except the reason line — the title, URL,
description and format — is taken from Money Ready's own records, not from the
model's output. If the model returns a URL that is not in the library, that
recommendation is discarded rather than shown.

> **One level deeper.**
>
> 1. The browser POSTs to `admin-ajax.php` with `action=fq_recommend`, a nonce,
>    the answers, the honeypot field and the timing token. The handler is
>    `fq_proxy_handler()`.
> 2. `fq_check_honeypot()` requires `fq_website` to be empty.
>    `fq_check_timing()` base64-decodes a token of the form
>    `timestamp|HMAC-SHA256(timestamp)`, verifies the signature with
>    `hash_equals()` against the site's `AUTH_KEY`, and requires at least
>    `FQ_MIN_TIME` (8) seconds to have passed since the page rendered. Both
>    failures return fallbacks as a *success* response — a bot gets a plausible
>    result rather than a signal that it was detected. `fq_check_rate_limit()`
>    is a fixed-window limiter, 10 requests per 10 minutes per IP, returning
>    HTTP 429.
> 3. The answer cache key is `'fq_ans_' . md5(...)` over the sorted topic list
>    plus experience, goal and format. It is a WordPress transient with a
>    24-hour TTL, and it is shared across all visitors — it is a cache of
>    *answers*, not of people.
> 4. `fq_get_content_library()` runs one `get_posts()` against post type
>    `library`, status `publish`, limit 500, with a tax query requiring the
>    `topic` taxonomy to EXIST. Each post's term slugs are mapped to quiz topic
>    slugs via `topics.json`; posts with no mappable term are skipped. Title,
>    permalink, resolved description, mapped topics and inferred format are
>    kept. The hardcoded social posts are appended. The whole array goes into an
>    hour-long transient.
> 5. The pre-filter keeps items whose `topics` intersect the visitor's
>    selection. There is deliberately no padding with general content — an
>    earlier version topped up thin result sets, which is what produced the
>    tool's worst historic bug (a visitor asking only about pensions received
>    confident recommendations drawn from a pool with nothing about pensions in
>    it). A genuinely empty match now short-circuits to the no-match path.
> 6. `fq_truncate_for_prompt()` reduces each item to title, url, topics, format
>    and a 25-word description. The request goes to
>    `https://api.anthropic.com/v1/messages` via `wp_remote_post()` with a 20
>    second timeout, model `claude-opus-5`, `max_tokens` 2000, and an
>    `output_config` carrying `effort: low` and a JSON schema. The schema
>    constrains each recommendation to exactly `url`, `title` and `reason`. The
>    count asked for adapts to the shortlist size — asking for "3 to 5" from a
>    list of two invites padding, so with two matches it asks for two.
> 7. The response's first *text* block is parsed (not `content[0]`, which may be
>    a thinking block). Every returned `url` is looked up in the library by
>    exact match; anything absent is dropped, and everything present is rebuilt
>    from the library record, keeping only `reason` from the model. If nothing
>    survives, fallbacks are served and the discard is logged.
>
> Some design choices worth stating for DigitalFootprints specifically:
> transport is `wp_remote_post`, not Anthropic's PHP SDK, because the SDK needs
> a Composer autoloader inside a WordPress theme — a deployment change. The
> schema-constrained output replaces the prototype's approach of asking for JSON
> in prose and stripping markdown fences with a regular expression. And `effort:
> low` is a deliberate latency choice: a visitor is watching a spinner against a
> 20 second server timeout and a 22 second browser abort, and choosing a few
> items from a shortlist is not hard reasoning.

---

## 3. The three sources of content

Everything the tool can recommend comes from one of three places.

**1. Learning Hub articles — live from WordPress.** The real source. No list of
articles is maintained anywhere in the tool; it queries WordPress every time the
hourly cache expires, so publishing an article is all it takes for it to become
recommendable. The requirements on an article are covered in
`learning-hub-content-guide.md`: a `topic` term, and a Yoast meta description.

**2. Social posts — currently a hardcoded list.** Five Instagram and YouTube
Shorts posts written directly into the code, each with a title, URL,
description, topic and format. Changing them means changing code.

> This is the part of the system most obviously due for replacement. **The
> planned Buffer sync is not built.** The intention is a nightly job pulling
> published posts from the Buffer API, storing each as a WordPress custom post
> type tagged with the same `topic` terms articles use, with Claude classifying
> each as learning content or not. None of that exists yet — today there are
> five posts in an array. Treat any description of the Buffer sync as a plan.

**3. Hardcoded fallback recommendations.** Four Learning Hub articles named
directly in the code, served whenever the AI path cannot complete for any
reason. They are the safety net, not a source of personalised results.

> **One level deeper.** Built library composition, measured 25 September 2026:
> **55 items** — 50 tagged `library` posts plus the 5 hardcoded social posts —
> with zero blank descriptions. By format badge that is 45 articles, 7 videos
> and 3 social posts; note that the "7 videos" and "3 social" counts are
> *format* counts, not source counts, because two of the five hardcoded social
> posts are marked `format: video`.
>
> Two Learning Hub items carry no `topic` term and so never enter the library at
> all — that is 52 published items reduced to 50.
>
> The social posts carry a `level` field (`beginner` / `all`). It is stripped
> before the prompt is built, deliberately: WordPress articles have no
> equivalent field, so including it fed the model an inconsistent attribute with
> no signal in it.
>
> One thing to be aware of: one of the five hardcoded social posts is tagged
> with the topic `property` (Renting & mortgages), which is a `planned` topic
> and therefore not offered as a quiz checkbox. Since the pre-filter matches on
> selected topics and the quiz requires at least one selection, **that post
> cannot currently be reached by any answer combination.** It is harmless but
> dead weight; it resolves itself when `property` goes live.

---

## 4. `topics.json` — the shared vocabulary

A single small file, version-controlled alongside the code, that defines what a
"topic" is for the whole tool. Three things read it:

1. The quiz checkboxes on question one.
2. The filter that decides which Learning Hub articles belong to which topic.
3. (Planned, not built) the prompt that will classify incoming social posts.

The reason it exists is that the WordPress term names and the words visitors
understand are not the same. The taxonomy says `earning`, `staying-safe` and
`university`; the quiz says "Income & side hustles", "Staying safe from scams"
and "Student living". Before this file, that translation lived in a hardcoded
array which covered only some of the topics, silently — and mapped them against
a taxonomy that did not exist, so it never matched anything at all.

It also lets a topic be *declared before it has content*. Four topics —
Insurance, Pensions, Renting & Mortgages, Money & Mental Health — are in the
file with `status: planned`. They are hidden from the quiz, because offering a
checkbox that leads nowhere is worse than not offering it, but the intent stays
on record and the switch is one word.

### Adding or changing a topic

1. Open `topics.json`, which sits next to `finance-quiz-shortcode.php`.
2. Copy an existing block in the `topics` array and edit the values.
3. Set `slug` to an internal id. **Never change a slug once it is live** —
   analytics rows already recorded against it would stop matching.
4. Set `label` and `emoji` to what the visitor should see on the checkbox.
5. Set `status` to `planned` if no content carries the topic yet, or `live` if
   it does.
6. Put the WordPress `topic` taxonomy slugs in `taxonomy_terms`. Several terms
   may map to one quiz topic. An empty list means no article can ever match it —
   only social posts.
7. Write one sentence in `description`, addressed to Claude, describing what
   belongs in this topic. This is for the future classifier, not for display.
8. Save. Nothing else changes, and nothing needs restarting — the topic appears
   in the quiz on the next page load.

To turn a planned topic on, do step 5 and step 6 only.

> **One level deeper.** `fq_get_topics()` reads and caches the file per-request
> in a static, skipping any entry whose `status` is not `live` and any entry
> missing a slug or label. `fq_get_taxonomy_map()` inverts it into
> taxonomy-slug → quiz-slug. The library cache key is fingerprinted with an MD5
> of that map (`fq_cache_key()`), so **editing `topics.json` invalidates the
> library cache immediately** rather than waiting out the hour.
>
> The file must stay valid JSON — no trailing commas, no comments. If it is
> missing or malformed, `fq_get_topics()` writes a warning to the PHP error log
> and returns a built-in list of seven topics, deliberately mirroring what the
> old prototype could map. The quiz keeps working; it is just ignoring the file.
> That is a degradation to notice, not a state to live in.
>
> Note the asymmetry with WordPress: adding a term to the `topic` taxonomy does
> **not** add it here. Worse, an article tagged only with an unmapped term is
> dropped from the library entirely. That is why the content guide asks the web
> team to flag new terms before creating them.

---

## 5. Caching — what is cached, and what clears it

Three separate caches, with different lifetimes and different triggers. They are
worth understanding together, because the interaction between them is where
surprises live.

| What | Lifetime | Cleared by |
|---|---|---|
| The content library | 1 hour | Publishing or updating a `library` post; editing `topics.json` |
| A set of recommendations for one exact answer combination | 24 hours | Nothing — it expires |
| The analytics dashboard | Not cached | n/a |

**The practical version:** publish a new article and it can appear in
recommendations within the hour, or immediately for anyone whose answer
combination has not been asked recently. But a visitor giving answers that
someone else gave earlier today will get the answer that was computed then, new
article or not, for up to 24 hours.

That is a deliberate trade — it keeps cost and latency down, and quiz answers
repeat far more often than content changes. It is worth knowing about when
testing, because it looks exactly like "my new article isn't being picked up".
To test cleanly, use an answer combination nobody has used.

> **One level deeper.** Both caches are WordPress transients, so on a site with
> a persistent object cache they live there and on one without they live in the
> options table.
>
> - Library: key `fq_content_library_<8 hex chars of the topic map hash>`, TTL
>   `HOUR_IN_SECONDS`. Invalidated by a `save_post` hook that fires only when
>   the post type is `library` **and** the status is `publish`.
> - Answers: key `fq_ans_<md5 of the four answers>`, TTL `DAY_IN_SECONDS`.
>
> Two consequences worth naming. First, **the answer cache is not invalidated by
> publishing.** The library cache is, but a stored recommendation set for a
> given answer combination survives up to 24 hours regardless of what was
> published in the meantime. Second, the `save_post` invalidator does not fire
> on unpublish or trash, so a withdrawn article could in principle be
> recommended for up to an hour (and up to 24 hours from the answer cache). The
> broken-link consequence of that is mild, but it is real.
>
> There is also a schema-version guard worth knowing about:
> `fq_maybe_create_table()` runs on `init` but compares the `fq_db_version`
> option against the `FQ_DB_VERSION` constant and does nothing unless they
> differ, so a normal front-end request costs one autoloaded option read rather
> than a `CREATE TABLE`. **`FQ_DB_VERSION` must be bumped whenever the table
> definition changes**, or `dbDelta` will never run again.

---

## 6. What is measured

Two events are recorded, in a WordPress database table called `wp_fq_events`:

1. **Quiz completion** — the topics chosen, confidence, goal, format
   preference, and how the result was produced (AI, cached, fallback, or no
   matching content).
2. **Card click** — all of the above, plus which item was clicked: its title,
   URL, topic and format.

**No user identity is recorded.** No name, no email, no IP address, no user id,
no cookie linking one event to another. The table cannot tell you that the same
person completed a quiz and then clicked a card; it can only tell you how many
of each happened. That is a deliberate design constraint and should not be
changed without a separate, explicit decision.

> The planned session-tracking cookie mentioned in the project notes would
> change this picture. **It is not built.** If it is built, it deserves its own
> privacy review before it ships.

The same two events are also sent to GA4 as `fq_quiz_complete` and
`fq_card_click`.

### The admin dashboard

A "Quiz Analytics" screen in WordPress admin, available to users with
`manage_options`. It shows, for a date range of 7, 30, 90 or 365 days:

1. Quiz completions, card clicks, and click-through rate between them.
2. Top ten clicked items, with topic, format and click count.
3. Most-selected topic combinations.
4. Most common goals.
5. A breakdown of result types — AI generated, cached, fallback, or **no
   matching content**.

Item 5 is the one to watch editorially. A rising "no matching content" count
means visitors are asking for topics the Learning Hub cannot answer yet. It is
the most direct read available on where content is missing.

Two CSV exports are available: a full row-level export and a per-item click
summary. Both cover all time, not the selected date range — the screen says so.

> **One level deeper.** The table is created by `dbDelta` with indexes on
> `event_time`, `event_type` and the first 191 characters of `article_url`.
> Events are posted from the browser to `admin-ajax.php` with
> `action=fq_log`, fire-and-forget, using `keepalive: true` so a click that
> navigates away still records. All values pass through
> `sanitize_text_field()` or `esc_url_raw()`. Dashboard queries use
> `$wpdb->prepare()` for the date bound, and the exports are nonce-checked with
> `check_admin_referer()` and capability-checked.
>
> One limitation in how topics are stored on clicks: an item can carry several
> topics, but only the **first** is written to `article_topic`. The dashboard's
> "Topic" column therefore shows one topic per item, not all of them. That is
> fine for spotting patterns and misleading if read as complete.
>
> The GA4 events fire through `gtag()` only **if `gtag` is already defined on
> the page** — the tool does not load the GA4 library itself. If GA4 is not
> already installed site-wide, those events silently do not happen, and the
> WordPress table remains the source of truth. `FQ_GA4_MEASUREMENT_ID` is also
> still the placeholder `G-XXXXXXXXXX` and needs a real value set in
> `wp-config.php`.

---

## 7. Credentials

The Anthropic API key and the GA4 measurement id are PHP constants, every one
of them guarded with `defined()` so that `wp-config.php` — which loads before
themes and plugins — can set them and win.

**Both are still placeholders** (`YOUR_API_KEY_HERE` and `G-XXXXXXXXXX`). Real
values belong in `wp-config.php`, which is git-ignored, and never in the
committed PHP.

For DigitalFootprints: this means deploying the tool does not deploy any
secret, and setting the key is a `wp-config.php` change on each environment
rather than a code change. Until `FQ_API_KEY` is set, every request will fail
the API call and every visitor will see fallback recommendations.

---

## 8. What happens when something goes wrong

The tool is built so that a visitor always sees something useful. Five distinct
failure paths, all of which end in content rather than an error.

1. **No matching content.** The visitor picked topics the library genuinely
   cannot answer. They get the four fallback articles and an honest message:
   "We don't have anything on those topics just yet — we're adding new content
   all the time." This is recorded separately in analytics as `no_match`, so it
   shows up as a content gap rather than as a technical failure.
2. **The AI call fails** — network error, non-200 response, unparseable output,
   or every returned URL absent from the library. The visitor gets the four
   fallback articles with a softer note: "We had trouble connecting right now,
   so here are our top picks." Each of these is written to the PHP error log
   with the specific cause.
3. **A bot is suspected** — honeypot filled, or the quiz completed implausibly
   fast. Fallbacks, no message, no indication that anything was detected.
4. **Rate limit hit** — more than 10 requests from one IP in 10 minutes. The
   AI path returns 429; the browser falls back to requesting the fallback
   recommendations.
5. **Everything fails.** A plain message pointing the visitor at the Learning
   Hub.

Alongside those, two things protect the visitor from the model directly:

- **Nothing the model writes is trusted as fact.** Titles, descriptions, URLs
  and formats are all re-read from the library by URL. A hallucinated link
  cannot become a card, because the URL would not be found.
- **Nothing the model writes is trusted as markup.** Cards are built with DOM
  methods and `textContent`, never by interpolating into an HTML string, and
  every `href` is rejected unless it parses as plain `http:` or `https:`. This
  removes a whole class of problem rather than trying to escape each field
  correctly. It should stay that way — reintroducing a template literal there
  puts model output back into the page as markup.

> **One level deeper.** The rate limiter is a true fixed window: the expiry is
> set once when the window opens and never extended. An earlier version rewrote
> a full TTL on every request, which made it "10 requests with no gap longer
> than the window" — a steady trickle accumulated toward a lockout
> indefinitely, and the lockout then outlasted its intended duration.
>
> The timing token is HMAC-signed with the site's `AUTH_KEY`, so it cannot be
> forged, but it has **no upper age bound** — an arbitrarily old token still
> passes. That is intentional for people who leave a tab open, and it is
> directly relevant to the caching question in section 10.

---

## 9. What has actually been run

Stated explicitly, because "reviewed" and "executed" are not the same thing and
this project has confused them before.

**Run against a local copy of production, 25 September 2026:**

1. The PHP file passes `php -l` cleanly.
2. `fq_get_content_library()` builds 55 items with zero blank descriptions.
3. All ten live topics return matches; the four planned topics are correctly
   absent from the quiz.
4. Multi-topic selections behave sensibly — budgeting + spending gives 17 items,
   banking + saving + scams gives 21.
5. The whole library serialised for the prompt is about 17KB, roughly 4,300
   tokens. Comfortably within budget, which means the topic pre-filter is an
   optimisation rather than a necessity at current content volume.

**Never run:**

- The AJAX endpoints (see section 10, item 1 — there is a specific concern).
- The actual Anthropic API call, because no key is set.
- The admin dashboard.
- The quiz UI in a browser.

All of those need either a real `FQ_API_KEY` or the staging environment.

---

## 10. Known limitations and open questions

### For DigitalFootprints — please answer these before staging

**1. Page caching will break this tool.** Two values are baked into the HTML at
render time: the WordPress nonce that authorises the AJAX calls, and the
anti-bot timing token. Under a full-page cache, the nonce goes stale within 12
to 24 hours, and the timing token becomes a no-op because its age is measured
from whenever the page was cached rather than when the visitor arrived.

*Consequence, plainly:* once the nonce is stale, the tool stops producing
recommendations. Reading the code, the degradation is probably worse than
"visitors get fallbacks" — a rejected nonce causes `admin-ajax.php` to die, so
the browser's fallback request uses the same stale nonce and fails too, leaving
the visitor with a plain "we're having trouble" message. That path has not been
tested and the exact behaviour should be confirmed on staging.

*What we need to know:* what the actual cache configuration is for the page the
quiz will sit on, and whether the quiz page can be excluded, or a
cache-compatible nonce approach used.

**2. Is the site fronted by a CDN or reverse proxy?** The rate limiter keys on
`REMOTE_ADDR`. Behind a proxy, that is the proxy's address, not the visitor's —
which would pool every visitor into a single bucket and lock out the entire site
after 10 quiz submissions in 10 minutes.

*What we need to know:* whether the site is fronted, and if so, which
forwarded-for header the infrastructure sets. The code must read the header the
proxy sets and never a client-supplied one, so this cannot be guessed.

**3. A suspected bug in how the AJAX requests are formed.** Flagged here because
it would affect every request and DigitalFootprints will see it first on
staging. The browser sends its requests with `Content-Type: application/json`
and puts `action` and `nonce` inside the JSON body. PHP does not populate
`$_POST` from a JSON body, and both `admin-ajax.php` (to route on `action`) and
`check_ajax_referer()` (to read `nonce`) look in `$_REQUEST`. On that reading,
every AJAX call would be rejected before reaching its handler, and every visitor
would see the "having trouble" message.

This is inference from reading the code, not an observed failure — the AJAX
layer has never been executed. If it is right, the fix is small: move `action`
and `nonce` into the query string or a form-encoded body while keeping the JSON
payload for the answers. **This should be the first thing tested on staging.**

### Other limitations, in descending order of how much they matter

4. **The library is thin in places.** Student living has exactly one article, so
   a visitor selecting only that topic gets exactly one recommendation. Four
   intended topics — Insurance, Pensions, Renting & Mortgages, Money & Mental
   Health — have no content at all and are hidden from the quiz for that reason.
5. **Eight of nine Car Ready articles have no summary text anywhere**, so they
   reach the model as a title and nothing else, and their cards show nothing but
   the title. This is an editorial fix, not a code fix; it is the highest-value
   one available.
6. **Two Learning Hub articles carry no topic term** and can never be
   recommended: "Five top tips for your finances when starting a family" and
   "5 money lessons children should learn before they're 12".
7. **Social posts are still hardcoded** — five of them, in the PHP. The Buffer
   sync that would replace this is planned and **not built**, and is blocked on
   Buffer account access. Related: the session-tracking cookie is also planned
   and not built.
8. **The answer cache is not invalidated by publishing.** A repeated answer
   combination can serve a result computed up to 24 hours earlier, from before
   a new article existed.
9. **The `save_post` invalidator does not fire on unpublish or trash**, so a
   withdrawn article could be recommended for up to an hour after withdrawal,
   and up to 24 hours from a cached answer set.
10. **One hardcoded social post is unreachable** — it is tagged with the
    `property` topic, which is `planned` and so never appears as a quiz
    checkbox. It becomes reachable when that topic goes live.
11. **Only the first topic of a clicked item is stored** in analytics, so the
    dashboard's Topic column under-reports multi-topic items.
12. **GA4 events depend on `gtag` already being on the page.** The tool does not
    load it, and the measurement id is still a placeholder.
13. **Format preference is a hint, not a filter.** A visitor who says they
    prefer videos can still be shown articles — which is usually right, given
    how few videos exist, but it is worth knowing it is not a hard constraint.
14. **The four hardcoded fallback articles have not been verified** to still
    exist at the URLs written into the code. Worth a five-minute check, since
    they are what every failure path serves.
15. **Whether the `topic` taxonomy contains terms beyond the ten mapped in
    `topics.json` has not been checked.** If it does, articles carrying only
    such a term are currently invisible to the recommender.
