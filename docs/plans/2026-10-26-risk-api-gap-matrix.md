# Risk browser/API coverage matrix

Status: Verified on Dev / Merged to Main / Human Reviewed by Svein Tore, 2026-10-26.
Date: 2026-10-26. Owner: Codex. Product/review owner: Svein Tore.
Parent: ../rfc/2026-10-26-risk-full-api-parity.md.
Baseline: 2026-10-26-risk-api-route-baseline.json.
Human-review checklist: HR-2026-10-26-RISK-API (Reviewed; blocks Main and production).

## Definition and evidence

100% means every currently supported browser read, mutation, action, administration
and report has an official typed API with equivalent current authority, discovery,
validation, persisted result, relevant side effects and tested failure behavior.
Route counts do not define a percentage. New unsupported product features are not
invented to inflate coverage.

Pre-implementation baseline on authoritative Dev, branch Dev: 17 browser route rows; eight API rows / ten HTTP
operations excluding HEAD. Risk source has no pre-existing uncommitted edits.
The earlier Clients delivery is already Human Reviewed and merged to Main.
The current Risk source matches the last verified Main source. GitHub open-Issue
search for Risk found no matching open item; this is not a claim about closed issues.
Baseline RiskSystemTest: 9 passed / 63 assertions.
Final: all R01-R16 implemented; 27 operations / 37 schemas verified on Dev.

Source paths below are relative to app/Modules/Risk unless specified. The original
gap inventory is retained for accountability. Every target row is implemented on Dev.
The frozen catalogue is 2026-10-26-risk-api-contract.json.

| ID | Current browser workflow | Existing official API / gap | Complete target and required proof |
| --- | --- | --- | --- |
| R01 | Tech/RiskController::index; internal/active Client session context | GET risk/assessments supports partial explicit filters; no complete form/filter discovery or bounds | Pure explicit internal/Client/Work Context filters, stable bounded paging and truthful authorized totals; no session or Work Context writes on GET |
| R02 | Assessment create/edit form; active Clients, RiskSettings | No typed form/context or authorized bounded Client/category lookup | GET risk/context and assessment context; defaults, constraints, capabilities and bounded lookup APIs; same active-Client and category selection rules |
| R03 | StoreRiskAssessment with settings, scope and ResolveWorkContext | POST risk/assessments exists; supplied status and client scope need strict validation/actor parity | Preserve local creation and configured defaults, derive work_context_id, no approved status injection; atomic record and durable replay/read-back |
| R04 | Assessment show, category groups, items, approver and summary | GET assessment embeds items; no independent bounded item list, current capabilities or revision | Full typed resource and parent context, GET assessment/items, aggregates over permitted active items; minimal authorized identity references and exact computed read-back |
| R05 | Assessment edit/update metadata and scope | PUT/PATCH assessment exists; API accepts status that UpdateRiskAssessment ignores | Shared strict mutation contract; preserve omitted fields; reject unsupported lifecycle fields and invalid Client scope; revision and persisted context read-back |
| R06 | Add item modal; settings, categories, initial history and parent in_progress | POST assessment/items exists; actor right and category/default discovery incomplete | Same Risk create scope plus current browser risk.update right; item + initial history in one transaction; score/defaults/history exactly once |
| R07 | Item detail; baseline/current score, history, creators and review date | GET item embeds history; no update list/detail or edit context | GET item/context, item/updates and updates/{update}; deterministic history order and bounded complete pagination; reject children of deleted parents |
| R08 | Descriptive item edits, baseline-creator/Superuser rule, locked scoring | PUT/PATCH item calls shared action but omits browser ownership check; scoring fields silently discarded when history exists | Shared current owner policy on web/API/replay; forbid silently ignored scoring changes; same-value compatibility explicitly tested; read back exact fields/generated history |
| R09 | Add Update; status/likelihood/impact/date and assessment reopening | POST item/updates exists; actor/replay/concurrency parity incomplete | Current browser route authority plus API ceiling; transactional history/current snapshot, preserved omitted dates, score and truthful approval validity; one effect on replay |
| R10 | Superuser assessment/item soft deletion | No corresponding DELETE APIs | DELETE assessment/item with risk.delete, current route permission and Superuser; no force-delete/restore/cascade; tombstone read-back and inaccessible active children after parent deletion |
| R11 | Delete update; creator or Superuser; repair current snapshot | No update DELETE API | DELETE updates/{update}; risk.delete + current creator/Superuser; newest remaining (created_at,id) snapshot under parent lock; preserve current snapshot if history becomes empty; durable deletion proof, no unintended ownership grant |
| R12 | ApproveRiskAssessment; nonempty addressed item set; approver/time | No approval API; raw assessment status is not equivalent | Explicit POST assessment/approve; guarded current-state validation/revision, durable key, no repeated approval restamping; shared race protection with child/history mutations and exact approval read-back |
| R13 | PDF export, grouped risks, summary and approver | No binary PDF API | GET assessment/pdf through shared renderer/query; same permitted data/calculations, correct PDF content/headers/filename and typed JSON errors; no arbitrary URL input |
| R14 | Admin Risk Settings; six default fields | No settings API | GET and PUT/PATCH risk/settings, current risk.manage_settings; typed options/ranges, strict fields/partial semantics and exact saved read-back; do not rewrite existing risk data |
| R15 | Current Tech route rights, active account, Superuser/creator guards | APIs currently primarily enforce token abilities | Recheck current active actor + token + parent + field/role/owner rules and current authority on replay; fail closed, never auto-widen tokens; explicit operation scope table in RFC |
| R16 | API Management, documentation, tests and final human review | Generic OA response descriptions and partial mutation annotations | Exact OpenAPI for every method, errors, pagination, commands, revisions and PDF; positive/negative/native concurrency/HTTPS checks; Knowledge and actual BookStack publication/read-back; HR Reviewed by Svein Tore, 2026-10-26; Main/production separate |

## Full browser accountability

- Index -> R01; create -> R02/R03.
- Assessment show/edit/update -> R02/R04/R05; destroy -> R10.
- Item store/show/update/destroy -> R06/R07/R08/R10.
- Item update store/delete -> R09/R11.
- Approval/PDF -> R12/R13.
- Settings GET/PUT -> R14.
- Every row -> R15/R16.

The baseline JSON preserves every exact method/path/name/action/middleware.
All ten existing API methods remain in scope; new operations do not excuse
incomplete existing validation, read-back, field visibility or actor checks.

## Approved baseline issues resolved by this delivery

- Before this delivery, the model's approval predicate checked only that no item is open.
  README says every item must be mitigated or accepted. Legacy unknown statuses
  can therefore differ from the documented rule. The RFC recommends the explicit
  documented rule, shared by browser/API, with no silent status normalization.
- The original history relation begins in ascending order. Deletion must explicitly select the
  newest remaining entry rather than accidentally keeping the oldest ordering.
- The original descriptive/history mutations could leave old approval metadata on an assessment.
  The target distinguishes current approval from the last recorded stamp and
  reopens an approved assessment when the signed-off history changes.
- Deleting the final history row currently leaves the current snapshot intact;
  retain that behavior, report an empty timeline honestly, and do not fabricate
  a new baseline/creator or grant API edit rights absent from the browser.
- The README's old SQLite-unavailable note is stale; the present Dev baseline ran.
  Its API/authorization and deployment guidance must be reconciled during delivery.

## Verified R01-R16 evidence

All rows are implemented and verified on Dev. See 2026-10-26-risk-api-verification.md
and .json for native/HTTPS/PDF, exact method/schema and handbook evidence.
HR-2026-10-26-RISK-API is Reviewed. Main merged in PR #321; production remains separate.

| Row | Named passing tests |
| --- | --- |
| R01 | `reads_have_stable_paging_legacy_scope_and_conflict_validation` |
| R02 | `context_and_lookups_are_pure_bounded_and_minimal` |
| R03 | `risk_creation_uses_configured_defaults`; `metadata_patch_preserves_omission_and_rejects_ignored_or_invalid_inputs` |
| R04 | `all_twenty_seven_success_contracts_match_real_operational_responses` |
| R05 | `metadata_patch_preserves_omission_and_rejects_ignored_or_invalid_inputs` |
| R06 | `risk_creation_uses_configured_defaults`; `browser_permission_distinctions_and_owned_history_delete_work_for_technicians` |
| R07 | `reads_have_stable_paging_legacy_scope_and_conflict_validation`; `history_delete_requires_exact_creator_and_deletion_closes_all_child_paths` |
| R08 | `item_edit_rechecks_original_creator_and_rejects_locked_fields_atomically`; `critical_fields_are_locked_when_history_exists`; `creator_can_edit_risk_item`; `normal_user_cannot_edit_risk_item_they_did_not_create` |
| R09 | `approval_requires_nonempty_addressed_items_and_never_restamps_a_replay`; `browser_permission_distinctions_and_owned_history_delete_work_for_technicians` |
| R10 | `history_delete_requires_exact_creator_and_deletion_closes_all_child_paths`; `all_twenty_seven_success_contracts_match_real_operational_responses` |
| R11 | `deleting_newest_nonnewest_and_last_history_reconciles_deterministically`; `deleting_update_resets_risk_item_state`; `browser_permission_distinctions_and_owned_history_delete_work_for_technicians` |
| R12 | `approval_requires_nonempty_addressed_items_and_never_restamps_a_replay`; `new_actions_require_revision_and_key_and_detect_same_second_changes` |
| R13 | `pdf_returns_the_shared_binary_report_and_denies_deleted_parents`; `all_twenty_seven_success_contracts_match_real_operational_responses` |
| R14 | `settings_patch_merges_full_put_validates_and_defaults_only_affect_future_records`; `superuser_can_update_risk_settings`; `context_and_lookups_are_pure_bounded_and_minimal` |
| R15 | `every_operation_requires_its_scope_and_current_actor_permission`; `receipts_recheck_current_underlying_rights_and_token_ceiling`; `real_tokens_cannot_read_each_others_command_receipts` |
| R16 | `every_registered_risk_api_method_has_an_exact_typed_contract`; `generated_swagger_contains_every_exact_method_and_response_schema`; `all_twenty_seven_success_contracts_match_real_operational_responses` |

## Main promotion completed

Main merge separately authorized by Svein Tore: "Ja", 2026-10-26.
PR #321: https://github.com/SveinT83/Nexum-PSA/pull/321. Merge SHA: bf5c7d0c608895031eea04aa9a65cfd7c8a7ba98.
Merged tree equals the tested isolated candidate. Production was not deployed.
See ../plans/2026-10-26-risk-api-main-promotion.md for promotion/test/deployment evidence.
