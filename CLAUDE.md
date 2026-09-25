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
   making live API calls per request. Buffer's API is available on all plans
   including free. Metricool was considered and rejected — not required for
   this project.

   **Storage:** each synced post becomes one entry in a `fq_social_post`
   **custom post type**, tagged with the same `library-topic` taxonomy terms
   the articles use, plus a meta field holding its classification status. This
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

4. **Session tracking.** Add a first-party cookie session ID so post-quiz
   learner journeys can be tracked across Learning Hub pages (extends the
   existing `wp_fq_events` anonymous event table).

## Security notes (do not regress on these)

- **Credentials live in `wp-config.php`**, which is git-ignored — never in
  plugin/theme PHP files that get committed. `FQ_API_KEY` and
  `FQ_GA4_MEASUREMENT_ID` are currently unset placeholders in the prototype
  and need real values set there, not hardcoded.
- **Buffer's personal API token has no read-only scope restriction.**
  Mitigations, in order:
  1. Use a dedicated limited team member account, not the primary owner's
  2. Write the sync job as a structurally narrow function calling only one
     GET endpoint — don't give it broader Buffer access than it needs
  3. Rotate the key periodically
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
   descriptions, 21 from the ACF `content` field, **0 falling through to a bare
   title**.
6. Whole library serialised for the prompt is ~17KB, roughly 4,300 tokens —
   comfortably within budget, so topic pre-filtering is an optimisation rather
   than a necessity at current content volume.

**Still unverified:** the AJAX endpoints, the actual Anthropic API call (no key
set), the admin dashboard, and the quiz UI in a browser. Those need either a
real `FQ_API_KEY` or the staging environment.

## Code review findings (full file reviewed — 1029 lines, nothing truncated)

The audit's "lines 150–879 not yet checked" item is now closed. All seven
findings are **fixed**; the notes are kept because several describe traps worth
not falling into again:

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

## Working style

- Ruth wants both a plain-language explanation and a "one level deeper"
  technical explanation for anything documented for colleagues — default to
  providing both rather than picking one.
- Numbered, sequential lists are the preferred reference format — use them
  for anything procedural.
