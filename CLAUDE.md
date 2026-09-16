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

The quiz covers 12 financial topics: Banking, Borrowing, Budgeting, Income,
Saving, Spending, Scams, Insurance, Pensions, Renting & Mortgages, Money &
Mental Health, and Student Living.

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
   topic changes are visible in history, and it works today without a local
   WordPress install. Each entry declares the WordPress `library-topic`
   taxonomy slugs it maps to, which is what makes the article side work without
   re-tagging anything.

   **Why at all:** the current `$topic_map` only covers 7 of the 12 quiz topics
   — Insurance, Pensions, Renting & Mortgages, Money & Mental Health, and
   Student Living are silently excluded from recommendations. This is a
   critical bug, not a style preference.

   **Neither articles nor social posts are ever hardcoded.** Articles come live
   from WordPress, tagged on creation with `library-topic` terms. Social posts
   come from the nightly Buffer sync (decision 2). `topics.json` holds only the
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
  `library-topic` custom taxonomy, plus (currently) a hardcoded social posts
  array — cached hourly, invalidated automatically on post publish.
- **The taxonomy slugs do not match the quiz values.** The taxonomy uses
  `earning` and `staying-safe` where the quiz uses `income` and `scams`. That
  translation is exactly what `$topic_map` existed to paper over, and is why
  topics could fall off the end unnoticed. `topics.json` now declares the
  mapping explicitly instead.
- **Unverified until the local WordPress copy exists:** whether `library-topic`
  actually has terms for the five missing topics, or whether they were never
  created. If they don't exist, someone has to create them and retro-tag
  existing Learning Hub articles — a content job, not a code job.
- The **answer cache** is keyed by MD5 hash of quiz answers (topics + goal +
  confidence + format), 24-hour TTL, shared across all visitors with identical
  answers — not per-user.
- The `wp_fq_events` table stores anonymous quiz completions and card clicks
  only. **No user identity is stored.** Keep it that way unless a deliberate,
  separately-reviewed decision changes this.
- Recommended library size before Claude sees a performance impact: 100–120
  URLs, though topic pre-filtering (already implemented) softens this ceiling.

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
- **Nothing in this repo has been executed.** No PHP runtime and no WordPress
  install is available in the development session, so every change so far is
  reviewed-but-unrun. First run of any of it will be on staging.

## Code review findings (full file reviewed — 1029 lines, nothing truncated)

The audit's "lines 150–879 not yet checked" item is now closed. Findings, in
priority order — items 1–4 are **fixed**, items 5–7 are **open**:

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
5. **Open — `fq_create_table()` runs on `init`.** A `require_once` of
   `wp-admin/includes/upgrade.php` plus a `CREATE TABLE` on every front-end
   page load. Should be gated behind a stored version option.
6. **Open — model output is interpolated into `innerHTML` unescaped.**
   `renderCards()` puts `title`, `description`, `reason` and `url` straight
   into markup with no escaping and no scheme check on `href`. Low likelihood,
   but it is model output reaching the DOM unfiltered.
7. **Open — rate-limit window re-extends itself.** Every request rewrites the
   transient with a fresh full TTL, so a busy visitor can be locked out for
   much longer than the intended window.

## Working style

- Ruth wants both a plain-language explanation and a "one level deeper"
  technical explanation for anything documented for colleagues — default to
  providing both rather than picking one.
- Numbered, sequential lists are the preferred reference format — use them
  for anything procedural.
