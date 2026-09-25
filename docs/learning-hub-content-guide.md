# Publishing for the recommender — a guide for the web team

**Who this is for:** anyone at Money Ready who publishes or edits Learning Hub
items in WordPress.

**Why it exists:** the financial literacy quiz (the `[finance_quiz]` shortcode)
reads the Learning Hub live and recommends items to visitors based on their quiz
answers. What you do at publish time decides whether an item can be recommended
at all, and how well it gets matched. None of it is extra work — it is the SEO
and tagging you would do anyway — but two specific fields carry much more weight
than they look like they do.

Throughout, there are two layers. The plain explanation is enough to work from.
The **One level deeper** notes say what the code is actually doing, for anyone
who wants to check the reasoning or is troubleshooting.

A note on confidence: this project has already lost months to a confidently
stated assumption that turned out to be wrong. So where something has been
measured, this guide says so and gives the date. Where it has not, it says that
too, rather than smoothing over the gap.

---

## The one-minute version

1. Write a Yoast meta description. That text is what visitors see on the
   recommendation card, and what the AI reads when deciding whether to recommend
   the item at all.
2. Tick at least one **Topic**. No topic, no recommendation — ever.
3. If you add a brand-new Topic term, tell the developer. The quiz reads the
   same vocabulary, and a new term does nothing until it is added there too.

Everything below is the reasoning behind those three lines.

---

## 1. Give every item a Yoast meta description

**This is the single highest-value thing you can do.**

The recommender does not read the body of your article. It reads a short summary
of it. Your Yoast meta description *is* that summary — it is used twice:

1. It is the text the AI reads when deciding which items to recommend.
2. It is the text printed on the recommendation card the visitor actually sees.

If there is no meta description, the item does not disappear — but it competes
on its title alone. It is being judged against items that get a title *and* a
sentence of context, and it is shown to the visitor as a card with nothing under
the headline but the title again.

### The live example

As measured on 25 September 2026 against a local copy of the production site:

- **8 of the 9 Car Ready articles have no summary text anywhere.** No Yoast meta
  description, no excerpt, and their body field contains only the title repeated
  as a heading. There is genuinely nothing for the tool to say about them.
- Car Ready is the second-largest body of content on the Learning Hub. Nine
  articles, eight of them reaching the recommender as a bare title.

The titles are descriptive enough that this is not a disaster — "Car tax" and
"Insurance and extras" do carry meaning. But a sentence each would measurably
improve how well they are matched, and would stop the cards looking empty. This
is the highest-value editorial fix currently available anywhere in the Learning
Hub.

### How to write one that works well here

1. **Say what the reader will be able to do or understand afterwards**, not what
   the article "covers". "Work out whether a used car is worth the asking price"
   beats "An overview of used car valuations".
2. **Front-load the meaning.** The first 25 words are what reaches the AI (see
   the deeper note below), so put the substance first and any branding or
   flourish last.
3. **Use the words a visitor would use.** The quiz matches interests, not
   jargon. "Overdraft" and "credit score" are worth more than "unsecured
   short-term facilities".
4. **Do not just restate the title.** That wastes the one slot you have to add
   information the title does not already carry.
5. Normal Yoast length guidance still applies — you are not trading SEO against
   the quiz, the same text serves both.

> **One level deeper.** The function `fq_resolve_description()` picks a
> description for each item by trying four sources in strict order and stopping
> at the first that yields text:
>
> 1. The Yoast meta description (`_yoast_wpseo_metadesc`).
> 2. The WordPress excerpt.
> 3. The ACF `content` field with HTML stripped — and if that text begins by
>    repeating the post title, the repeated title is removed. If fewer than five
>    words remain after removing it, the whole thing is discarded as worthless.
>    This is exactly what happens to the eight Car Ready articles.
> 4. The post title, so that nothing is ever described as an empty string.
>
> Only the *first* 25 words of the chosen description are sent to the model
> (`FQ_DESC_WORDS`); the full text is what gets printed on the card. So a long
> meta description is not wasted, but its opening does the matching work.
>
> A caveat worth stating: the code comment in `fq_resolve_description()` records
> Yoast descriptions on 31 of 52 items, while `CLAUDE.md` records 29 of the 50
> *tagged* items using Yoast. Those two figures are reconcilable (the two extra
> items are presumably the untagged pair in section 2), but they have not been
> reconciled by direct measurement, so treat "roughly 30 of 52" as the safe
> number rather than either figure precisely.

---

## 2. Give every item at least one Topic

An item with no `topic` term **can never be recommended**. Not "is ranked
lower" — it is excluded before the AI ever sees the library.

Two live Learning Hub articles are currently in that position:

1. *Five top tips for your finances when starting a family*
2. *5 money lessons children should learn before they're 12*

Both are perfectly good articles that no quiz answer can reach. Adding a Topic
term to each fixes it, and the fix takes effect within the hour.

### Which topics to use

The topics that currently exist and work, with the number of articles carrying
each as at 25 September 2026:

| Topic term | Articles |
|---|---|
| Banking | 7 |
| Big Money Lessons | 3 |
| Borrowing | 5 |
| Budgeting | 8 |
| Car Ready | 9 |
| Earning | 3 |
| Saving | 9 |
| Spending | 10 |
| Staying safe | 4 |
| University | 1 |

(Those add up to more than the number of articles because an article can carry
several topics. That is fine and encouraged where it is genuinely true — more
topics means more quiz answers can reach it. What is *not* helpful is tagging
everything with everything; a visitor who asks about scams and is shown a
pensions article learns that the tool does not work.)

Two things this table tells you editorially:

1. **University has one article.** In the Learning Hub, that is still the
   thinnest topic by a distance.

   *Update, since social posts were connected:* a visitor selecting "Student
   living" now gets six items rather than one, because five approved social
   posts carry that topic — on student loan repayments, budgeting at
   university, and the real cost of moving. So the quiz no longer looks broken
   on that topic. The Learning Hub gap itself is unchanged, and an article
   still outranks a social post for depth.
2. **Four intended topics have no content at all** — Insurance, Pensions,
   Renting & Mortgages, and Money & Mental Health. They are deliberately hidden
   from the quiz for now rather than offered and leading nowhere. The moment an
   article is tagged with one of them, a one-line change makes that checkbox
   appear.

> **One level deeper.** The library query is a `get_posts()` against the
> `library` post type with a tax query of
> `['taxonomy' => 'topic', 'operator' => 'EXISTS']` — so untagged items are
> filtered out in the database, not later. Then, for each surviving post, its
> term slugs are translated into quiz topic slugs through the map built from
> `topics.json`; **if none of an item's terms appear in that map, the item is
> dropped too** (`if ( empty($mapped) ) continue;`). That second filter is the
> subject of section 3.
>
> Health warning about terminology, because it has burned this project once
> already: the taxonomy is called **`topic`**. `library-topic` is the
> *query-string parameter* the Learning Hub's own filter puts in URLs, which the
> theme translates to `topic` internally. It is not a taxonomy and never was.
> The original build of this tool took the URL parameter for a taxonomy name —
> and simultaneously queried the wrong post type (`post` rather than `library`)
> — which meant the article half of the recommender returned zero results for
> its entire life without anyone noticing.

---

## 3. The Topic vocabulary is now shared — flag changes before you make them

This is new, and it is the one place where a routine WordPress action can have
an effect outside WordPress.

The `topic` taxonomy is no longer just an article filter. It is also the list of
checkboxes on the first question of the quiz. The two are kept in step by a file
in the tool's code called `topics.json`, which says, for each quiz checkbox,
which `topic` terms count as that checkbox.

What this means in practice:

1. **Adding a new Topic term in WordPress does not add it to the quiz.** Worse,
   until someone adds it to `topics.json`, any article tagged *only* with that
   new term drops out of the recommender entirely — it is treated as untagged.
2. **Renaming a Topic's slug breaks the link** between that term and its quiz
   checkbox, with the same result.
3. **Deleting a Topic term** removes its articles from the recommender unless
   they carry another mapped term.
4. Changing a Topic's *display name* in WordPress is safe — the link is made on
   the slug, and the quiz has its own labels anyway.

So: **before adding, renaming or deleting a `topic` term, flag it to whoever is
maintaining the quiz.** The change on their side is about two minutes; the cost
of not flagging it is content silently vanishing from recommendations.

The reverse is also worth knowing — it is a feature, not a problem. The four
hidden topics (Insurance, Pensions, Renting & Mortgages, Money & Mental Health)
are already declared in the file and waiting. When content for one of them
exists, creating the term and flipping one word in the file turns the checkbox
on.

> **One level deeper.** `topics.json` sits alongside the PHP and is read by
> `fq_get_topics()`. Each entry has a `slug` (internal id, also what gets stored
> in analytics — never change it once live), a `label` and `emoji` for the
> checkbox, a `status` of `live` or `planned`, a list of `taxonomy_terms`, and a
> one-sentence `description` written for the AI. Only `live` entries are
> rendered as checkboxes. The quiz's display order follows the file's order.
>
> The mapping is not one-to-one, which is the other reason the file exists:
> three quiz topics use a term with a different name from the label —
> `earning` → "Income & side hustles", `staying-safe` → "Staying safe from
> scams", `university` → "Student living".
>
> If the file is missing or is not valid JSON, the quiz does not break: it logs
> a warning to the PHP error log and falls back to a built-in list of seven
> topics. That is a safety net, not a working state — the file is then being
> ignored, and any topic outside those seven stops working.
>
> One thing this guide cannot tell you: whether the `topic` taxonomy contains
> terms beyond the ten in the table above. That has not been checked. If it
> does, any article carrying only such a term is currently invisible to the
> recommender, and it would be worth an audit.

---

## 4. Excerpts — not part of your workflow, and that is fine

All 52 Learning Hub items currently have an empty excerpt. Nobody uses the
field, and nothing here asks you to start.

It is mentioned only so the position is on record: the tool *would* honour an
excerpt if one existed. It sits second in the preference order, above the body
text and below the Yoast meta description. So if the team ever decided to start
writing excerpts, they would improve descriptions for any item lacking a Yoast
one, with no code change required.

Until then, treat the Yoast meta description as the field that matters. Writing
both would be duplicated effort for no gain.

---

## 5. A small bonus: how an item gets labelled "Video"

Every card now ends in one or more action buttons rather than a format badge —
"Read article" for a Learning Hub item, or "View on Instagram" / "View on
TikTok" / "Watch on YouTube" for a social post, one button per place the
content exists. For Learning Hub items there is no field you can set to
control this.

The tool works it out by looking for an embedded video in the item's body or
ACF `content` field. If it finds a YouTube, `youtu.be` or Vimeo link, or an
HTML `<video>` element, the item is labelled Video. Otherwise it is an Article.

Practical consequence: if a Learning Hub item is really a video piece,
**embed the video in the item** rather than only linking to it in text. Six of
the 52 items met the embed test at the time of verification.

> **One level deeper.** `fq_resolve_format()` runs a single regular expression
> over `post_content . ' ' . get_post_meta($post->ID, 'content', true)`. There
> is deliberately no authoritative field being read, because none exists: the
> `fq_format` ACF field the original prototype read is set on zero posts, and
> the `library-type` taxonomy contains only two abandoned placeholder terms
> ("Type A", "Type C") with no posts attached. If a real format field is
> introduced later, this function is the one place to read it.
>
> Format is passed to the AI and shown on the card, but it is not a hard filter
> — a visitor who says they prefer videos is not prevented from being shown a
> good article.

---

## 6. You will now see social posts in the Topic taxonomy

Since the Buffer connection went in, Money Ready's social posts are stored in
WordPress too, as a hidden post type, and they carry **the same Topic terms**
your articles do.

What this means for you day to day:

1. **Topic term counts will look higher than your article count.** A term
   showing 21 items may be eight articles and thirteen social posts.
2. **You may see unfamiliar entries under Social posts** in the admin menu.
   Those arrive automatically from Buffer each week. They are not pages, have
   no URL on the site, and link out to Instagram, TikTok and so on.
3. **You do not need to tag them.** Claude assigns their topics on arrival,
   restricted to the same vocabulary, and anything borderline waits in a
   review queue.
4. **Please still flag topic renames** (section 3). Renaming a term now
   affects articles, social posts and the quiz at once.

Worth knowing for context: of roughly 480 social posts, about 45 are judged to
be teaching content. The rest — fundraising, awards, job adverts, event
recaps, media appearances — are filtered out and never shown to a visitor.
Social posts supplement the Learning Hub in the recommender; they do not
replace it, and an article is still the more substantial thing to be sent.

---

## Pre-publish checklist

Run down this list before hitting Publish on any Learning Hub item.

1. **Yoast meta description written?** One or two sentences, saying what the
   reader will get. Not a restatement of the title.
2. **Does its first line carry the substance?** Only the opening ~25 words reach
   the matching step.
3. **At least one Topic ticked?** Without one, the item cannot be recommended
   at all.
4. **Are the Topics honestly true of the article?** Every extra topic is another
   quiz answer that can surface it — and another chance to surface it to someone
   it does not help.
5. **Creating a brand-new Topic term?** Stop and flag it to whoever maintains
   the quiz first. Until the term is added on their side, articles carrying only
   that term are invisible to the recommender.
6. **Is it really a video?** Embed the video in the item so it gets the Video
   badge.
7. Excerpt — leave it. Nothing needs it.

And one periodic job, not a per-item one:

8. **Chase the summary gaps.** The eight Car Ready articles with no summary text
   are the largest and easiest win available. After those, any item where the
   card would show nothing but its own title.

---

## What we are confident about, and what we are not

Stated plainly, because getting this wrong is what caused the problem this
rebuild exists to fix.

**Verified by running the real code against a local copy of production
(25 September 2026):**

- The taxonomy is `topic`; the post type is `library`.
- The library builds to 55 items with no blank descriptions.
- All ten live topics return matches.
- Two articles carry no topic term, named in section 2.
- Excerpts are empty on all 52 items.
- The article counts per topic in the section 2 table.

**Not verified, and stated as such above:**

- Whether the `topic` taxonomy holds terms beyond the ten listed.
- The exact split of where descriptions come from — two internal records
  disagree slightly (29 vs 31 Yoast descriptions), which is why section 1 says
  "roughly 30 of 52".
- Whether the eight Car Ready articles are counted as "falling back to the
  title" in the project's own measurements. Reading the code, they should be;
  one internal note says none do. Either way the editorial conclusion is the
  same, and it is the one in section 1: they arrive as a title and nothing else.
- Nothing in this guide has been checked against the *live* site, only against a
  local copy of it.
