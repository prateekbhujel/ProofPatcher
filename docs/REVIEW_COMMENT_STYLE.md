# Review comment style (house spec)

Sources: [Conventional Comments](https://conventionalcomments.org)
(label vocabulary) and CodeRabbit practice (severity chip, why-it-matters,
committable suggestion). Two renderers implement this spec: PHP
`ReviewCommentFormatter` (posted GitHub comments) and `FindingCard.tsx`
(in-app cards). Both must stay in sync; both have tests.

## Label map (from finding status)

| status     | label     | meaning                                  |
| ---------- | --------- | ---------------------------------------- |
| `concern`  | `issue`   | Should be fixed; see blocking rule.      |
| `note`     | `nitpick` | Polish; never blocking.                  |
| `approval` | `praise`  | Genuine positive; never blocking.        |

## Blocking rule

`issue` + review verdict `request_changes` → blocking (no decoration).
Anything else → `non-blocking` decoration. Unknown verdict → no blocking
claim at all (neither decoration).

## Posted format (GitHub)

```
ProofPatcher here — spotted something worth a look:

**issue:** Subject sentence here.

Discussion: why it matters, context, next steps.

```suggestion
exact replacement code
```
```

- Subject = first sentence of the comment (split on `. `/`? `/`! `,
  max 160 chars). Discussion = the rest, may be empty.
- The suggestion fence is `suggestion` (GitHub renders a commit button
  when it applies cleanly, a code block otherwise).
- Sanitization unchanged: 1000-char cap, no mentions/links/angle
  brackets/control bytes; a failing suggestion drops, the comment posts.

## Voice

Human first, robot second: short, confident, plain words, a little edge —
never corporate filler ("we hope this finds you well"), never hype, never
over-polished. Say what happens next and who decides. Issue, close, and
summary comments end by making clear a human maintainer has the final
call; the bot does the legwork, not the deciding.

## In-app cards

Same label + blocking badge as chips, subject bold, discussion body,
`Suggested fix` block, anchor proof collapsed. The card never claims a
blocking state when the verdict is unknown.
