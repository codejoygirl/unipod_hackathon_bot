# 002 — Group vs private venue for personal help

Status: accepted  
Product: Community Assistant (Zak)

## Decision

- **Community knowledge** (schedules, people, links, hackathon facts, catch-ups): answer in **groups and DMs**.
- **Personal help** (study tips, motivation, how to achieve a goal, refine a pitch — helpful coaching, not community-fact RAG):
  - **Private chat:** answer with the conversational `personal_help` mode.
  - **Group:** do not coach in-thread; model writes a short nudge to continue privately; Laravel appends the real DM URL from config.
- **Hard out-of-scope** (math, romance-at-bot, world trivia, on-demand jokes/riddles): refuse — never classify as `personal_help`.

## Where it lives

| Layer | Role |
| --- | --- |
| AI classify / reply system prompts | Meaning + venue policy (any language; few-shot examples teach patterns, not fixed phrases) |
| Laravel channel adapters | If `personal_help` + group → `take_private` + append DM link; if private → `personal_help` reply |
| `AGENTS.md` / cursor rules | Engineering only: model-owned understanding; no keyword catalogs |

## Not this

Do not encode “motivate me / aide-moi / حفزني” as PHP/Python regex. Phrasing changes; the model owns intent.
