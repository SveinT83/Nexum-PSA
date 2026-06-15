# RFC: Lead Intelligence Schedule Foundation

Status: Approved
Date: 2026-06-12
Owner: Nexum PSA

## Summary

Add schedule and budget controls to Lead Intelligence segments so active segments can create queued research-run plans over time. This slice also makes the segment description the human goal prompt and adds an AI-assisted segment drafting surface.

## Approval

Approved by the product owner request on 2026-06-12. The request specified weekly/daily pacing, goals such as five new leads per week, unlimited token mode until the goal is reached, using Description as a prompt, and an AI icon that can draft editable segment fields.

## Goals

- Add segment schedule fields for daily, weekly, and monthly goal periods.
- Allow a target number of new leads per period.
- Allow token budget per period or unlimited token mode.
- Let the planner queue runs until period goal, token budget, or max-run limit is reached.
- Use segment Description as the goal prompt in planned research-run context.
- Add an AI segment draft panel that fills editable fields but does not save automatically.
- Add a default Lead Intelligence AI agent when an active provider exists.

## Non-Goals

- No real crawler.
- No BRREG/search integration.
- No AI enrichment of companies or contacts.
- No automatic Client or Contact creation.
- No automatic Marketing-list promotion.
- No email sending.

## Operational Model

Plesk or cron can run:

```bash
php artisan lead-intelligence:plan-due-runs
```

The command creates queued `lead_research_runs` for due active segments. A future discovery worker will process those queued runs.

## Test Plan

- Segment API includes schedule fields.
- Planner creates due queued runs.
- Planner stores Description as `goal_prompt`.
- Planner defers when period goal is reached.
- Unlimited token budget does not block a due run.

