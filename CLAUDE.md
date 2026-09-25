# Money Ready — Financial Literacy Assessment Tool

## What this project is

A quiz-style tool embedded on the Money Ready WordPress website via a shortcode
(`[finance_quiz]`). Visitors answer questions about their financial interests,
confidence, goals, and preferred learning format. The tool recommends 3–5
relevant content pieces — Learning Hub articles, YouTube videos, and social
media posts — matched to their answers.

This is a **rebuild and extension of a working prototype** (see
`finance-quiz-shortcode.php` in this repo), not a from-scratch build. A full
technical audit of the prototype has already been done; the decisions below
came out of that audit.

The quiz offers **10 live topics**: Banking, Big Money Lessons, Borrowing,
Budgeting, Car Ready, Income, Saving, Spending, Scams, and Student Living.
Four more — Insurance, Pensions, Renting & Mortgages, Money & Mental Health —
are declared in `topics.json` but hidden (`status: planned`) because no content
carries them yet. See "Topic reality" below; the original spec's flat list of 12
did not survive contact with the actual content.

## Key stakeholders

- Ruth (project owner) — non-technical + technical explanations both needed
  when documenting for colleagues
- **DigitalFootprints** — Money Ready's WordPress hosting/dev partner, provide
  the staging environment for testing before anything goes live

## Architectural decisions from the audit (already made — build to these)

1. **Single source of truth for topics — a version-controlled `topics.json`
   file.** Replace the hardcoded `$topic_map` array *and* the hardcoded quiz
   checkbox list with one JSON file sitting alongside the PHP. This one file
   drives all three consumers:
   - the quiz UI checkboxes
   - the content library filter
   - the Claude classification prompt

   **Why a file and not a database table:** topics change rarely but need to be
   added ahead of the content that answers them, the file is diffable in git so
   topic changes are visible in history, and a `status` flag lets a topic be
   declared before it is offered. Each entry declares the WordPress `topic`
   taxonomy slugs it maps to, which is what makes the article side work without
   re-tagging anything.

   **Why at all:** the old `$topic_map` covered 7 of the 12 specified topics,
   silently excluding the rest — and it mapped them against a taxonomy that
   does not exist, so it never matched anything at all.

   **Neither articles nor social posts are ever hardcoded.** Articles come live
   from WordPress, tagged on creation with `topic` terms. Social posts come
   from the nightly Buffer sync (decision 2). `topics.json` holds only the
   *vocabulary* — the topic names and how they map — never content.

   **Superseded:** the original audit proposed a WordPress custom post type for
   topics. A CPT is the wrong tool for a small, stable vocabulary that has to
   be editable before WordPress access exists. The CPT idea moves to decision 2,
   where it stores incoming social posts — which is what post types are for.

2. **Automated social post ingestion.** Use the **Buffer API** on a daily
   WP-Cron sync job, instead of hardcoding posts (`fq_get_social_posts()`) or
   making live API calls per request. Metricool was considered and rejected —
   not required for this project.

   **Buffer's API is GraphQL, not REST** (verified against the live docs
   2026-09-25; the API is badged "New", so older REST knowledge is stale).
   Everything goes to a single `POST https://api.buffer.com` with
   `Authorization: Bearer <token>`. The two queries needed:

   ```graphql
   query { channels(input: { organizationId: "..." }) {
     id name displayName service avatar isQueuePaused } }

   query { posts(input: {
     organizationId: "..."
     sort:   [{ field: dueAt, direction: desc }]
     filter: { status: sent, channelIds: ["..."] }
   }) { edges { node { id text createdAt channelId } } } }
   ```

   `service` distinguishes YouTube, Instagram, TikTok etc, which is what
   supplies each post's format. `organizationId` comes from a
   `GetOrganizations` query. The full field list on a post — media,
   permalink, sent timestamp — is **not** in the docs; get it by GraphQL
   introspection against a real key rather than guessing.

   **Storage:** each synced post becomes one entry in a `fq_social_post`
   **custom post type**, tagged with the same `topic` taxonomy terms the
   articles use, plus a meta field holding its classification status. This
   gives a WordPress admin screen for the review queue for free, and means the
   library filter treats articles and social posts identically instead of
   merging two different shapes.

3. **Claude-assisted classification.** At sync time, use Claude to classify
   each new social post as learning content or not, using a **three-state
   status**: `classified`, `needs_review`, `excluded`. Nothing enters the
   recommendation pool without either Claude's confidence or explicit human
   sign-off.

   The classification prompt reads its list of valid topics — and each topic's
   one-line description — from `topics.json` (decision 1), so a topic added to
   that file immediately becomes available to the classifier. Claude never
   invents topic names.

   **This step is load-bearing, not a safeguard.** Measured against the live
   YouTube feed on 2026-09-25: of the 15 most recent videos, only about four
   are learning content. The rest are media appearances and organisational
   updates — ITV News Central, BBC Radio Solent, Voice of Islam, school
   visits, "Change the Game on the road". Without classification a visitor
   asking about budgeting could be recommended a radio interview. Do not
   treat this as an optional refinement to add later.

5. **De-duplicate across sources.** Two of the four learning videos in that
   feed are already in the recommendation pool by another route: "The Big
   Money Lesson: Borrowing" is a Learning Hub `library` item, and "How to
   avoid overspending online" is one of the hardcoded social posts. Without
   de-duplication the same video can be recommended twice on one results
   page. Match on the resolved destination URL, normalising YouTube forms
   (`youtu.be/ID`, `youtube.com/watch?v=ID`, `youtube.com/shorts/ID`).

4. **Session tracking.** Add a first-party cookie session ID so post-quiz
   learner journeys can be tracked across Learning Hub pages (extends the
   existing `wp_fq_events` anonymous event table).

## Security notes (do not regress on these)

- **Credentials live in `wp-config.php`**, which is git-ignored — never in
  plugin/theme PHP files that get committed. `FQ_API_KEY` and
  `FQ_GA4_MEASUREMENT_ID` are currently unset placeholders in the prototype
  and need real values set there, not hardcoded.
- **Buffer's personal API token has no read-only scope restriction, and the
  GraphQL API makes this worse than the audit assumed.** The original
  mitigation was "call only one GET endpoint". That reasoning does not
  survive GraphQL: there is exactly one endpoint, it is a POST, and the same
  endpoint that reads posts can also create and delete them. Narrowness has
  to come from the query text instead. Revised mitigations, in order:
  1. Use a dedicated limited team member account, not the primary owner's.
     This matters **more** now, not less, since the endpoint restriction is
     no longer available as a control.
  2. Hardcode the read query as a constant string. Never build it from
     anything outside the function, and never accept a query, fragment or
     variable set from a caller.
  3. Rotate the key periodically.
- `.gitignore` must exclude `wp-config.php` before the first commit that adds
  any real config file — order matters, since removing a file from tracking
  later doesn't purge it from git history.

## Existing system behaviour worth knowing before changing anything

- The **content library** is a merged result of a live `WP_Query` against the
  `topic` taxonomy, plus (currently) a hardcoded social posts array — cached
  hourly, invalidated automatically on post publish.
- **`library-topic` is NOT a taxonomy and never was.** It is the query-string
  parameter name the Learning Hub filter uses in URLs; the theme translates it
  to the real `topic` taxonomy itself (`class-honeycom3-library.php`, ~line
  327). The prototype took the parameter name for a taxonomy name. This is an
  easy mistake to make because the theme's naming is inconsistent — of its
  three filters, only one parameter matches its taxonomy:

  | URL parameter | Actual taxonomy |
  |---|---|
  | `library-type` | `library-type` (identical) |
  | `library-topic` | `topic` |
  | `library-theme` | `themes` |

- **Learning Hub articles are the `library` post type, not `post`.** There are
  zero published `post` items on the site. The prototype had `post` *and*
  `library-topic`, either of which alone was enough to return nothing.
- **Taxonomy slugs do not match quiz values.** `earning`→income,
  `staying-safe`→scams, `university`→student. `topics.json` declares these
  explicitly rather than papering over them.
- The **answer cache** is keyed by MD5 hash of quiz answers (topics + goal +
  confidence + format), 24-hour TTL, shared across all visitors with identical
  answers — not per-user.
- The `wp_fq_events` table stores anonymous quiz completions and card clicks
  only. **No user identity is stored.** Keep it that way unless a deliberate,
  separately-reviewed decision changes this.
- Recommended library size before Claude sees a performance impact: 100–120
  URLs, though topic pre-filtering (already implemented) softens this ceiling.

## Topic reality (measured 2026-09-25 against the local copy)

The specified 12 topics and the actual content only partly correspond, and the
mismatch runs in **both** directions. Live topics, with article counts at
verification:

| Quiz topic | `topic` term | Articles |
|---|---|---|
| Banking & financial products | `banking` | 7 |
| Big Money Lessons | `big-money-lessons` | 3 |
| Borrowing & debt | `borrowing` | 5 |
| Budgeting | `budgeting` | 8 |
| Car Ready | `car-ready` | 9 |
| Income & side hustles | `earning` | 3 |
| Saving & investing | `saving` | 9 |
| Spending smartly | `spending` | 10 |
| Staying safe from scams | `staying-safe` | 4 |
| Student living | `university` | **1** |

1. **Four specified topics have no content at all** — Insurance, Pensions,
   Renting & Mortgages, Money & Mental Health. Hidden as `planned` rather than
   removed, so the intent stays on record. Flip `status` to `live` the moment an
   article is tagged.
2. **Two content topics had no quiz topic** — `car-ready` (9 articles, the
   second-largest body of content on the site) and `big-money-lessons` (3).
   12 of 50 articles were unreachable from the quiz regardless of what a
   visitor selected. Both added as quiz topics on Ruth's decision.
3. **Student living has exactly one article**, so that checkbox yields a single
   recommendation. Ruth's read: the thin topics may be filled by social posts
   once the Buffer sync lands (decision 2), since social content likely covers
   student life, renting and mental health better than the Learning Hub does.
   **Re-check the live/planned split after the first Buffer sync** — some of
   the four hidden topics may become viable on social content alone.

## Content gaps worth fixing editorially (not code problems)

1. **8 of the 9 Car Ready articles have no summary text anywhere** — no Yoast
   meta description, no excerpt, and an ACF `content` field holding only the
   title as a heading. They reach the model as a title and nothing else. The
   titles are descriptive enough to work ("Car tax", "Insurance and extras"),
   but a Yoast meta description on each would measurably improve how well they
   get matched. Highest-value editorial fix available.
2. **Two library articles carry no `topic` term**, so they can never be
   recommended: "Five top tips for your finances when starting a family" and
   "5 money lessons children should learn before they're 12".
3. **Excerpts are empty on all 52 library articles.** Not a problem — the
   description chain handles it — but worth knowing they are unused.
4. `library-type` contains only abandoned placeholder terms ("Type A",
   "Type C", zero posts each). It is not a usable source of anything.

## YouTube — resolved: no separate integration needed

**YouTube is published through Buffer**, so it arrives with everything else.
Verified 2026-09-25 by comparing Buffer against the channel's RSS feed: of the
9 videos published in the overlapping window, **all 9 were in Buffer**. Nothing
was missing.

So: **do not build a YouTube integration.** No RSS parser, no Data API, no
Google Cloud project, no second credential. One integration covers everything.

Kept for reference in case that ever changes:

- Channel **@MoneyReadyUK**, id `UCv32RKUXnmL7EL3n1SZkm5g`
- Feed `https://www.youtube.com/feeds/videos.xml?channel_id=UCv32RKUXnmL7EL3n1SZkm5g`
  — no credentials, returns the 15 most recent with title, link, published
  date, full description and thumbnail. Useful only if YouTube publishing ever
  moves off Buffer, or to backfill videos older than Buffer's history.

## Buffer: what the real data looks like (sampled 2026-09-25)

Organisation id `65e74b698f9c10d39cc7392c`. **400 sent posts sampled**,
covering 2026-04-14 to 2026-09-24 — roughly 80 posts a month.

**Seven channels connected**, all healthy: instagram, linkedin, facebook,
tiktok, bluesky, youtube, twitter.

| Service | Posts in sample |
|---|---|
| instagram | 205 |
| linkedin | 88 |
| facebook | 52 |
| tiktok | 37 |
| bluesky | 9 |
| youtube | 9 |

**The fields that matter** (from schema introspection, not the docs, which
omit them):

- `text` — the post copy, and all the classifier gets to read
- `externalLink` — the permalink. Populated on every post in the sample
- `channelService` — the platform, directly on the post, no join needed
- `sentAt` — publication time
- also available: `assets`, `metrics`, `status`, `author`, `tags`

**Three things the sync must handle, all measured:**

1. **Cross-posting is heavy, and naive de-duplication does not catch it.**
   De-duplicate on a *fingerprint* of the text, not the raw text and not the
   URL — the URLs differ per channel, and the copy is edited per platform
   (different @mentions, different hashtags, curly vs straight apostrophes,
   different emoji). Measured over the same 314 usable posts:

   | Strategy | Distinct items | Duplicates collapsed |
   |---|---|---|
   | Raw first 150 chars, lowercased | 230 | 84 |
   | Fingerprint, first 30 words | 224 | 90 |
   | **Fingerprint, first 20 words** | **212** | **102** |
   | Fingerprint, first 12 words | 203 | 111 |
   | Fingerprint, first 8 words | 197 | 117 |

   Fingerprint = lowercase, strip URLs, strip `@mentions` and `#hashtags`,
   strip all emoji and punctuation, collapse whitespace, take the first N
   words. **Use 20 words.** Raw matching missed real duplicates — one item
   ("Talking to your child about money") ran on five channels and was scored
   twice by the classifier because of it. Going below 20 starts over-merging:
   at 12 words, four *different* job adverts (Wales, South Wales, North East
   ×2) collapsed into one item, because the distinguishing words come later.
2. **Roughly a fifth of posts are unusable**: 72 have empty `text` (nothing
   for the classifier to read), 22 are stories whose links expire after 24
   hours, and 11 have no `externalLink`. 314 of 400 are usable at all.
3. **Volume planning.** 233 unique items per 5 months, against a documented
   comfort ceiling of 100–120 URLs in the prompt. Classification should
   remove most of it — the sample is dominated by fundraising, awards and
   programme news rather than learning content — but if it does not, topic
   pre-filtering is what keeps the per-request prompt small.

## De-duplication happens in two places, on purpose

**Storage is permissive; presentation is strict.** Worth understanding before
changing either.

1. **At sync time** — group on the 20-word fingerprint. Safe, cheap, and never
   merges things that are genuinely different.
2. **At recommendation time** — `fq_drop_near_duplicates()` compares the three
   to five chosen items and drops any pair of near-identical titles.

The second exists because the first cannot be made tight enough safely.
Measured on real data: 13 near-duplicate pairs survive the fingerprint out of
255 groups, caused by **multi-word @mentions**. `@Darren Collins` has
`@Darren` stripped and leaves `Collins` behind, while `@mrcollinsunbound`
vanishes entirely, so every following word shifts by one.

**Do not "fix" this by loosening the sync's matching.** At the similarity
threshold needed to catch those pairs (0.97), two genuinely different job
adverts — "Wales team" and "South Wales team" — also merge, because word-set
similarity cannot see that "South" is the entire distinction. Tightening
storage trades a cosmetic duplicate for real data loss. The presentation guard
has no such risk: the worst case is dropping one of two near-identical cards,
which is what we want anyway.

## Buffer sync — measured behaviour (2026-09-25)

Full run against the live account: 20 pages, **1,000 posts fetched, 273
skipped as unusable, 477 unique items**, 31.7s. The 20-page ceiling was
reached, so there is more history available than one run will take.

- Multi-channel items: **148 of 477 (31%)**, including one on five channels.
- Re-running created nothing and updated all 477 — the sync is idempotent.
- An incremental run fetched 100 posts against the full run's 1,000.
- Items arrive with **no status**, so nothing is recommendable until the
  classifier runs. Verified: after a full sync the library was still 50.

Classification cost for a full backfill: 477 items at ~40 per request is
roughly a dozen calls.

## Classifier calibration (run 2026-09-25 on 40 real unique items)

Ran the proposed classification prompt against real Buffer content before
building anything. `claude-opus-5`, `effort: medium` — a nightly batch, so
judgement matters more than latency. 28s, 7.3k in / 2.6k out.

| Status | Share |
|---|---|
| excluded | 73% |
| classified | 25% |
| needs_review | 3% |

**It behaved well.** No invented topic names. Correctly excluded job adverts,
fundraising challenges, award shortlistings, office news, partner
announcements, campaign launches and research-findings posts. Correctly kept
posts on choosing a savings account, reading a payslip, student budgeting,
student loans, and saving money while living sustainably. The single
`needs_review` was a genuinely borderline International Literacy Day post
defining "financial literacy" — part education, part positioning. Exactly the
case that status exists for.

**Implication for volume:** if ~25% survives, 212 unique items yields roughly
50 social items. With 50 Learning Hub articles that is ~100 in the pool —
right at the documented 100–120 comfort ceiling, so topic pre-filtering stays
necessary rather than optional.

**Keep this calibration honest.** Re-run it after any change to the
classification prompt or to `topics.json`, and read the excluded list rather
than just the percentages. A classifier that quietly starts excluding good
content will look identical in the summary numbers.

## "Also on" links — worth building

Ruth's suggestion, and the data supports it: **60% of the items classified as
learning content were published to two or more channels.** That is understated,
since it was measured before the fingerprint fix. Most common pairings for
learning content are `tiktok + instagram`; `linkedin + facebook` dominates
overall but skews organisational.

One classified item ("Talking to your child about money") ran on **five**
channels including YouTube. So rather than arbitrarily picking one link, an
item should store every channel's URL and the card can offer "also on
Instagram / TikTok". Design `fq_social_post` to hold a set of
`{service, url}` pairs from the outset rather than a single link field —
retrofitting that later means a schema change and a resync.

## Local development environment

Local by Flywheel, site `money-ready`, a copy of production including the
Learning Hub. Notes for running code against it:

1. Site root: `C:\Users\RuthPeacegood\Local Sites\money-ready\app\public`
2. PHP CLI: `%APPDATA%\Local\lightning-services\php-8.1.29+0\bin\win64\php.exe`
3. The CLI binary has no extensions loaded. Add
   `-d extension_dir=<that dir>\ext -d extension=php_mysqli.dll`, or WordPress
   aborts with "missing the MySQL extension".
4. MySQL listens on **port 10004**, but `wp-config.php` hardcodes `DB_HOST` as
   `localhost` — which from CLI means 3306. Pre-define
   `define( 'DB_HOST', '127.0.0.1:10004' )` *before* requiring `wp-load.php`;
   wp-config's own `define()` then no-ops. (The same `define()` precedence the
   quiz's config block was fixed to rely on.)
5. The site must be **started in the Local app** first — no `mysqld`, no
   database.
6. There is no WP-CLI. Bootstrap `wp-load.php` from a PHP script instead.
7. `gh` is installed but **not on PATH** in older sessions; call it at
   `C:\Program Files\GitHub CLI\gh.exe` if the bare command fails.

## Tooling

- **Claude Code** for development (this session)
- **GitHub** — private repo, org-owned, branch protection on `main`
- **Local WordPress environment** for development
- **DigitalFootprints staging environment** for pre-launch testing

## Claude API integration choices

1. **Model: `claude-opus-5`.** The prototype pinned `claude-sonnet-4-20250514`.
2. **Transport: `wp_remote_post`, not the official PHP SDK.** The SDK would
   normally be the default, but it needs a Composer autoloader, which is a
   deployment change DigitalFootprints would have to accommodate inside a
   WordPress theme. `wp_remote_post` is the WordPress-idiomatic HTTP call and
   respects site-level proxy and filter config. Revisit if the project ever
   becomes a properly packaged plugin.
3. **Structured outputs, not regex.** The prototype stripped markdown fences
   with `preg_replace` and hoped the result parsed. Requests now send a
   JSON schema via `output_config.format`, so malformed output isn't a
   failure mode.
4. **Effort `low`.** Selecting a handful of items from a shortlist is not a
   hard reasoning task, and a visitor is watching a spinner against a 20s
   server timeout. If staging shows latency is still too high, dropping to
   `claude-sonnet-5` or `claude-haiku-4-5` is a deliberate cost/quality
   decision for Ruth to make, not an automatic one.

## Open items (not yet done)

- Implement the four architectural decisions above. Decisions 2 and 4 are
  blocked on assets not yet available (Buffer account access; local WordPress
  copy including the Learning Hub).
- Set real values for `FQ_API_KEY` and `FQ_GA4_MEASUREMENT_ID`
  (currently `YOUR_API_KEY_HERE` / `G-XXXXXXXXXX` placeholders)
- Deploy to and test on the DigitalFootprints staging environment
- **Raise with DigitalFootprints before staging:** page caching will break this
  tool. The nonce and the anti-bot timing token are both baked into the HTML at
  render time, so under a full-page cache the nonce goes stale within 12–24h
  and every visitor silently gets fallback results, while the timing check
  becomes a no-op. Needs to be resolved against their actual cache config.
## What has actually been executed (as of 2026-09-25)

Superseding the earlier "nothing has been run" caveat. Verified by running the
real code against the local copy of production:

1. `finance-quiz-shortcode.php` passes `php -l` cleanly.
2. `fq_get_content_library()` builds **55 items** — 45 articles, 7 videos,
   3 social posts — with **zero blank descriptions**.
3. All 10 live topics return matches; the 4 `planned` topics are correctly
   absent from the quiz.
4. Multi-topic selections behave sensibly (budgeting+spending → 17 items,
   banking+saving+scams → 21).
5. Description sources across the 50 tagged articles: 29 from Yoast meta
   descriptions, 21 resolved from the ACF `content` field. Of those 21, **8
   (the Car Ready series) contain only the title as a heading**, so they end up
   described by their title alone — see Content gaps item 1. An earlier note
   here claimed "0 falling through to a bare title"; that measured source
   attribution, not outcome, and was misleading.
6. Whole library serialised for the prompt is ~17KB, roughly 4,300 tokens —
   comfortably within budget, so topic pre-filtering is an optimisation rather
   than a necessity at current content volume.
7. **A full end-to-end submission succeeds.** Real HTTP POST through
   `admin-ajax.php`, real Anthropic call, against real content: HTTP 200, five
   sensible Car Ready recommendations with specific reasons, and the second
   identical request served from the 24-hour answer cache.
8. **Analytics logging works**, including `article_topic`, which was empty in
   every row before finding 2 was fixed. A bad nonce is rejected with 403.
9. `fq_events` table created correctly by the version-gated
   `fq_maybe_create_table()`, with `fq_db_version` set to 1.0.

**Latency is the open risk: the first call took 20.4s end to end**, against a
20s `wp_remote_post` timeout and a 22s client-side abort. It succeeded, but
with almost no margin. See "Model and latency" below.

**Still unverified:** the admin dashboard, and the quiz UI in a real browser.

## Model and latency — needs a decision

`claude-opus-5` at `effort: low` produced excellent recommendations but took
~20.4s end to end (~13-15s of that the API call), which is uncomfortably close
to the 22s client abort. Three options, none yet chosen:

1. **Keep `claude-opus-5`, raise the timeouts** (server 20s → 30s, client 22s →
   35s). Best quality, but a 15-20s wait on a spinner is a lot to ask of a
   visitor.
2. **Switch to `claude-sonnet-5`.** The task is "pick 5 items from a shortlist
   of 9 and write a sentence each" — not hard reasoning. Likely
   indistinguishable output, materially faster and cheaper. This is a
   deliberate quality/latency trade for Ruth to make, not an automatic one.
3. **Keep Opus but revisit prompt size.** Unlikely to help much; the library is
   only ~4,300 tokens and latency is dominated by generation, not input.

Recommendation: try option 2 on staging and compare the actual recommendations
side by side before deciding. The cost of being wrong is low and reversible.

## Code review findings

The audit's "lines 150–879 not yet checked" item is closed — the whole file has
been reviewed. All findings below are **fixed**; the notes are kept because
several describe traps worth not falling into again.

**Finding 8 is the most serious defect found in this project so far** and is
listed first for that reason.

8. ~~Every AJAX request was rejected before reaching its handler~~ — the
   browser posted `Content-Type: application/json` with `action` and `nonce`
   inside the JSON body. WordPress routes on `$_REQUEST['action']`
   (admin-ajax.php line 31) and `check_ajax_referer()` reads
   `$_REQUEST['nonce']`, and **PHP never populates `$_POST` from a JSON request
   body**. Verified empirically: a JSON body returns HTTP 400 `0`; the same
   request form-encoded returns 200. This affected `fq_recommend`, `fq_log`
   *and* `fq_fallback` — so the fallback path failed identically, leaving the
   visitor with the bare "having trouble" text rather than fallback cards, and
   no event was ever logged. Requests are now form-encoded with the payload
   carried as a single JSON field (`fq_read_payload()` / `postAjax()`).
   **Do not "tidy" this back to a JSON content type.**

1. ~~Five of 12 topics unreachable~~ — the `$topic_map` gap. Root cause of the
   critical bug in decision 1.
2. ~~`article_topic` was always empty in analytics~~ — the prompt never asked
   Claude to return `topics`, and the post-processing step re-injected only
   `description` from the library. Every `card_click` row and the dashboard's
   "Topic" column was blank. Now re-injects `topics` and `format` from the
   library, and drops any recommendation whose URL isn't in the library.
3. ~~Silent whole-library substitution on no-match~~ — the topic pre-filter
   discarded itself entirely when it found fewer than 6 matches, handing Claude
   the unfiltered library. Someone selecting only "Pensions" got confident
   recommendations drawn from a pool containing nothing about pensions. The
   filter also counted `level: all` items as matches, which masked true
   no-match cases. Now distinguishes genuine topic matches from general
   content, and says so honestly when there is nothing.
4. ~~Unconditional `define()` on credentials~~ — would have fired a PHP notice
   once the real key was set in `wp-config.php`, with the wp-config value
   winning by accident rather than design. Now guarded.
5. ~~`fq_create_table()` ran on `init`~~ — a `require_once` of
   `wp-admin/includes/upgrade.php` plus a `CREATE TABLE` on every front-end
   page load. Now gated behind an `fq_db_version` option, so a normal request
   costs one autoloaded option read. **Bump `FQ_DB_VERSION` whenever the
   `CREATE TABLE` statement changes**, or `dbDelta` will never run again.
6. ~~Model output was interpolated into `innerHTML` unescaped~~ —
   `renderCards()` put `title`, `description`, `reason` and `url` into a markup
   string with no escaping and no scheme check on `href`. Now built with DOM
   APIs and `textContent`, which removes the class of problem rather than
   escaping each field, plus a guard rejecting any href that isn't plain
   http(s). **Keep it that way** — reintroducing a template literal here puts
   model output back into the DOM as markup.
7. ~~Rate-limit window re-extended itself~~ — every request rewrote the
   transient with a fresh full TTL, making it "10 requests with no gap longer
   than the window" rather than "10 per window"; a steady trickle accumulated
   toward a lockout indefinitely. Now a true fixed window.

   **Unresolved dependency:** the limiter keys on `REMOTE_ADDR`, which behind a
   CDN or reverse proxy is the proxy's address, not the visitor's — that would
   pool every visitor into one bucket and lock out the whole site at 10
   requests. Confirm with DigitalFootprints whether the site is fronted, and
   if so read the forwarded-for header they set (never trust a client-supplied
   one).

## Known open issues (found 2026-09-25, not yet fixed)

Surfaced by a documentation pass over the code. None are blocking; recorded so
they are not rediscovered from scratch.

1. **One hardcoded social post is unreachable.** "Nobody tells you this stuff
   before getting your first mortgage" is tagged `property`, which is a
   `planned` (hidden) topic. Since the quiz requires at least one topic and
   never offers `property`, no answer combination can surface it. Resolves
   itself when `property` goes live or the Buffer sync replaces the hardcoded
   array.
2. **The answer cache is not invalidated on publish.** `save_post` clears the
   library transient but not stored recommendation sets, so a newly published
   article can be missing from recommendations for up to 24 hours for any
   answer combination already cached.
3. **`save_post` only fires on `post_status === 'publish'`.** Unpublishing or
   trashing an article does not clear the library cache, so it can keep being
   recommended for up to an hour.
4. **Card clicks record only the first topic.** The JS sends `r.topics[0]`, so
   the dashboard's Topic column under-reports multi-topic articles.
5. **GA4 events fire only if `gtag` is already defined**, and the tool never
   loads it. If the theme does not load GA4 on the quiz page, no GA4 event is
   sent — silently. The database logging is unaffected.
6. **The timing token has no upper age bound.** `fq_check_timing()` checks only
   that at least `FQ_MIN_TIME` seconds have passed, never that the token is
   recent. This compounds the page-caching problem: a cached page serves an
   ageing token that stays valid indefinitely.
7. **Unverified:** whether the `topic` taxonomy holds terms beyond the ten
   mapped in `topics.json`. An article tagged only with an unmapped term is
   silently dropped from the library. Worth a periodic check as content grows.
8. **Unverified:** whether the four hardcoded fallback URLs in
   `fq_get_fallbacks()` still resolve on the live site.

## Working style

- Ruth wants both a plain-language explanation and a "one level deeper"
  technical explanation for anything documented for colleagues — default to
  providing both rather than picking one.
- Numbered, sequential lists are the preferred reference format — use them
  for anything procedural.
