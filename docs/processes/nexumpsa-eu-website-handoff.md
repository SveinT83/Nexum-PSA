# Nexum PSA Website Handoff

This process defines the file-based handoff from Nexum PSA development to the separate Content and
Visibility workflow for possible updates to `https://nexumpsa.eu`.

The handoff is an editorial input. It does not authorize publication, deployment, social-media
posting, or a change to Nexum production.

## Ownership And Destination

Content and Visibility owns editorial assessment and publication to WordPress and social media.
Nexum contributors only maintain the agreed handoff file:

```text
%USERPROFILE%\Documents\Innhold og synlighet\Innhold og synlighet\inputs\nexum-psa\nettsideoppdateringer.md
```

Resolve `%USERPROFILE%` from the active Windows profile. If a stored absolute path points to an old
profile, locate and verify the equivalent path under the active profile before writing. Do not create
an alternative handoff queue in this repository while the fixed file exists.

After every write, read back the appended or updated item from the destination file and verify UTF-8
text, status, publication gate, and item count. A successful write without read-back is not a
completed handoff.

## When To Add An Item

Add one item when a user-visible feature, improvement, fix, or documentation change is implemented
and verified well enough for editorial assessment. Consolidate related work into one customer-facing
item instead of adding internal implementation steps separately.

Do not add an item for:

- Internal refactoring or invisible foundations with no customer-relevant behavior.
- Unverified plans, ideas, diagnostics, or claims.
- Work that is blocked before a meaningful customer-facing result exists.
- Duplicate coverage already present in the active queue.

## Required Content

Write concise, customer-friendly Norwegian and include:

- A short customer-facing summary.
- New functions, fixes, or improvements that are actually implemented.
- Suggested placement on the public website.
- Test and verification status.
- Human-review identifier and status when one applies.
- Production or release status without implying deployment that was not verified.
- Explicit `Ikke publiser` notes for every remaining gate or unsafe claim.

Do not include secrets, credentials, tokens, personal data, customer data, ticket contents, internal
server names, IP addresses, database fields, internal class names, raw logs, screenshots, or other
implementation details that are not safe for public editorial review.

## Status Rules

Use `Status: klar for vurdering` when the item is technically ready for editorial assessment but
still needs editorial judgment, human review, release, deployment, or production verification.

Use `Status: godkjent for publisering` only when all required release, human-review, and production
evidence explicitly supports direct publication. Dev verification alone is insufficient.

Keep an explicit `Ikke publiser` section whenever any gate remains. Never remove or weaken another
contributor's publication gate without evidence that the named requirement is complete.

## Verification Checklist

Before reporting the handoff complete:

1. Confirm the implementation and relevant tests on the authoritative Dev environment.
2. Confirm the item is public-safe and contains no prohibited internal data.
3. Confirm the status matches human-review, release, and production evidence.
4. Confirm no equivalent active-queue item already covers the change.
5. Write only to the fixed handoff file.
6. Read back the exact item and report how many items were added or updated.

The Nexum workflow must not publish directly to `nexumpsa.eu` or social media. Publication remains a
separate Content and Visibility responsibility.
