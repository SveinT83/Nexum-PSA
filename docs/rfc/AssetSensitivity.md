# RFC: Asset Sensitivity and Criticality Classification

## Problem
Assets missing structured fields for sensitivity and criticality.

## Proposal
Add two separate classifications on Asset:
- Sensitivity level
- Criticality level

## Initial Scope
- Database fields on assets.
- Validation on create/update.
- Display and editing in Asset UI.
- Badges on Asset detail/list.
- Tests for storage and validation.

## Out of Scope
- Automatic ticket-prioritization.
- AI-policy enforcement.
- Maintenance policy.
- Automatic restart-policy.

## Future Use
- Ticket priority scoring.
- AI diagnostic permission.
- Password/MFA/security requirements.
- Maintenance approval rules.
- Operational Signal escalation.
