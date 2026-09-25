# Classification prompt

Everything above the `--- PROMPT ---` line is notes for whoever edits this
file. It is **not** sent to Claude. Only the text below that line is.

## What this file does

Every night the Buffer sync pulls in new social posts. This prompt decides
which of them are learning content a visitor should be offered, and which are
about the organisation rather than about money.

It matters because most of what Money Ready posts is **not** teaching content.
Measured over 40 real items: about a quarter were learning content, and the
rest were fundraising, awards, job adverts, event recaps, media appearances
and campaign news. Without this step someone asking for budgeting help could
be shown an ITV News interview or a job vacancy.

## The three statuses

| Status | Meaning |
|---|---|
| `classified` | Teaches something useful about money. **Only these can be recommended.** |
| `excluded` | About the organisation, not money education. |
| `needs_review` | Genuinely borderline. Waits for a person in the admin queue. |

Nothing is recommended without either Claude's confidence or a person's
sign-off, and a person's decision is never overwritten by a later sync.

## Editing it

Change the wording freely. Save the file and the next classification run uses
it. **The `{{TOPICS}}` and `{{POSTS}}` placeholders must survive** — without
them the file is ignored, a built-in fallback is used, and a warning is
logged.

After any edit, re-run classification on a sample and **read the excluded
list**, not just the percentages. A prompt that quietly starts discarding good
content looks identical in the summary numbers.

## A note on the boundary

The hardest cases are campaign posts that talk *about* helpful content without
containing any. "This week we're sharing tips for students" is an
announcement; "here's how to budget at uni" is the content. The current prompt
excludes the former. If good material is being lost, that is the line to move.

--- PROMPT ---
You are triaging Money Ready's social media posts for a financial education
content recommender. A visitor answers a short quiz and is shown a few pieces
of content to help them learn about money.

Classify each post:

- "classified": it teaches the reader something useful about money, and fits
  at least one topic below. Only this status can be shown to a visitor.
- "excluded": it is about the organisation rather than money education —
  fundraising, awards, recruitment, event recaps, media appearances, partner
  announcements, research findings, campaign news. Useful content, wrong place.
- "needs_review": genuinely borderline, and a person should decide. Use
  sparingly.

A post that only announces or promotes content is not itself content. "This
week we're sharing tips for students" is an announcement and should be
excluded. "Here is how to budget at uni" teaches something and should be
classified.

Assign topics ONLY from this list. Never invent one. Use an empty list if
none fit, and prefer excluding a post over stretching a topic to fit it:

{{TOPICS}}

Give a short reason for each decision, written for a colleague skimming a
review queue.

Also write a title for each post. Social copy has no title of its own, so
without one we are left showing the first few words of the post followed by
an ellipsis, which reads badly on a card.

- Six to nine words. Sentence case.
- Say what the content covers, as a person would describe it.
- It must read as a complete phrase. Never end with an ellipsis, a comma or a
  dangling preposition, and never just cut the post's opening short.
- No emoji, no hashtags, no "Money Ready".
- A question is fine if the post answers one.

Good: "How student loan repayments actually work"
Good: "Choosing between savings accounts"
Bad:  "Confused about how student loans work? You're not alone and we're…"
Bad:  "Worried about making your money last at uni?🤔 Budgeting doesn't…"

POSTS:
{{POSTS}}
