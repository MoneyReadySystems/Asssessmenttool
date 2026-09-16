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

1. **Single source of truth for topics.** Replace the hardcoded `$topic_map`
   array (in the current prototype) and the hardcoded social posts array with
   a WordPress custom post type ("topics" table). This one source should drive:
   - the quiz UI checkboxes
   - the content library filter
   - the Claude classification prompt
   **Why:** the current `$topic_map` only covers 7 of the 12 quiz topics —
   Insurance, Pensions, Renting & Mortgages, Money & Mental Health, and Student
   Living are silently excluded from recommendations. This is a critical bug,
   not a style preference.

2. **Automated social post ingestion.** Use the **Buffer API** on a daily
   WP-Cron sync job, instead of hardcoding posts (`fq_get_social_posts()`) or
   making live API calls per request. Buffer's API is available on all plans
   including free. Metricool was considered and rejected — not required for
   this project.

3. **Claude-assisted classification.** At sync time, use Claude to classify
   each new social post as learning content or not, using a **three-state
   status**: `classified`, `needs_review`, `excluded`. Nothing enters the
   recommendation pool without either Claude's confidence or explicit human
   sign-off.

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

- The **content library** is a merged result of a live `WP_Query` against a
  custom taxonomy, plus (currently) a hardcoded social posts array — cached
  hourly, invalidated automatically on post publish.
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

## Open items (not yet done)

- Full code review of the PHP shortcode file, specifically lines 150–879
  (truncated middle section), against the spec documents — not yet checked
  line-by-line
- Implement the four architectural decisions above
- Set real values for `FQ_API_KEY` and `FQ_GA4_MEASUREMENT_ID`
  (currently `YOUR_API_KEY_HERE` / `G-XXXXXXXXXX` placeholders)
- Deploy to and test on the DigitalFootprints staging environment

## Working style

- Ruth wants both a plain-language explanation and a "one level deeper"
  technical explanation for anything documented for colleagues — default to
  providing both rather than picking one.
- Numbered, sequential lists are the preferred reference format — use them
  for anything procedural.
