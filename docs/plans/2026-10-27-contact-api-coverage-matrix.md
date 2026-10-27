# Contact API Coverage Matrix

Status: All supported rows implemented/verified on Dev and the scoped Main candidate. Date: 2026-10-27.
Source: authoritative /var/Projects/tdPSA on Dev; companion JSON stores actual runtime routes.
8 Contact browser rows + 8 legacy Client alias rows; 9 API rows / 10 HTTP operations.
HEAD is excluded. Livewire workflows below are additional behaviors, not route rows.
Six Contact-controller OpenAPI declarations do not establish full typed contract coverage.
Every current business operation is supported; session-only clear and warning-only legacy delete
remain explicit non-mutating equivalents. Final contracts: 19 HTTP operations / 45 typed schemas.
The table records the original discovered gap; the completed evidence below supersedes its baseline status.

| ID | Browser/action source | Class | API mapping / current gap | Required evidence |
| --- | --- | --- | --- | --- |
| C01 | tech.contacts.index | Read | GET contacts exists; add Client/Site filters, title search, bounds | exact filtered identity, native rights, pagination |
| C02 | index Contact stats rightbar | Read | statistics missing | exact total/active/person/with-email under permitted scope |
| C03 | index Client/Site filter options | Read | bounded lookup/context missing | correct parent-child choices, denied directory access |
| C04 | tech.contacts.context.clear | Action | stateless omission of explicit API filters | no sticky session context or business mutation |
| C05 | tech.contacts.create | Read | form/defaults/capabilities discovery missing | settings, relation options, default Site and locked context |
| C06 | tech.contacts.store | Write | POST upsert exists; explicit non-overwriting reuse missing | no duplicate, shared action, stable bridge and audit |
| C07 | tech.contacts.edit | Read | GET detail exists; edit discovery incomplete | hydration and actor-specific allowed fields/context |
| C08 | ContactForm.save edit | Write | PUT/PATCH exists; preservation/type/identity contracts incomplete | field omission/null, context replacement, no portal send |
| C09 | ContactForm clientSuggestions/selectClient | Read | bounded Client lookup/default Site missing | exact client and Site, no foreign data |
| C10 | ContactForm duplicateMatches/selectExistingContact | Read/Write | duplicate lookup/reuse contract missing | normalized email/phone, conflicts, exact selected person |
| C11 | ContactForm create invitation | Action | API missing; explicit opt-in only | current invite right, transactional rollback, one queued job |
| C12 | approved Issue #254 role selection | Read/Action | UI still hardcodes Viewer; API must use authoritative roles | all entry points, Viewer default, chosen role and scope |
| C13 | tech.contacts.show details and methods | Read | GET exists; typed complete detail missing | exact displayed identity/email/phone/language and relations |
| C14 | show Compatibility rightbar | Read | safe bridge/user/reference summary missing | correct legacy IDs, no unrelated user/secret exposure |
| C15 | show related-signals partial | Read | embedded history missing | Signal query, scope/field bounds and ordering |
| C16 | tech.admin.settings.contacts | Read | settings API missing | native admin/settings rights and typed choices/defaults |
| C17 | tech.admin.settings.contacts.update | Write | settings API missing | native rights, validation, exact saved read-back |
| C18 | legacy ClientUsersController.index (2 URLs) | Read | canonical list with explicit Client/Site context | both aliases mapped; no separate catalogue |
| C19 | legacy ClientUsersController.create | Read | canonical create/context discovery | verified Client/default Site context |
| C20 | legacy ClientUsersController.store | Write | POST lacks supported legacy address payload | canonical storage, address/language, preserved bridge |
| C21 | legacy ClientUsersController.show | Read | canonical detail/bridge mapping | linked and controlled orphan-resolution behavior |
| C22 | legacy ClientUsersController.edit | Read | canonical edit discovery | same person and native edit right |
| C23 | legacy ClientUsersController.delete | Action | warning redirect only; preserve no-delete behavior | no invented DELETE-contact contract |
| C24 | Client/Site embedded Contact lists | Read | existing Clients APIs + Contact filtered projections | canonical relations, context role/Site/default flags |
| C25 | legacy update method (no current registered route) | Write boundary | shared action/address preservation regression only | do not count an unregistered route as runtime coverage |
| C26 | GET clients/{client}/contacts | Read | existing inspect; preserve getClientTicketContacts | customer ambiguity failure, active Ticket bridge map |
| C27 | POST contacts/{contact}/move | Action | exists, OpenAPI missing | native/scoped preview, stale/concurrent execute, bridge IDs |
| C28 | POST clients/{client}/contacts/bulk-fix | Action | exists, OpenAPI missing | per-item outcomes/conflicts, bounded IDs, read-back |
| C29 | POST clients/{client}/contacts/legacy-orphans/cleanup | Action | exists, OpenAPI missing | migration without stable-ID deletion, audit |
| C30 | DELETE clients/{client}/contacts/{contact} | Action | exists, OpenAPI missing | detach vs guarded orphan soft-delete; native delete right |
| C31 | POST contacts | Contract | both create/update scopes currently required | typed 200/201/upsert effects and explicit authority |
| C32 | all API operations | Contract | token checks present, native checks incomplete | exact actor/scope denial, inactive/revoked caller |
| C33 | side-effect commands and receipts | Action/Read | durable guarded API receipts missing | preview, body-bound replay, stale lock, current rights |
| C34 | Integration OpenAPI/MCP catalog | Read contract | 6 declarations vs 10 current HTTP operations | exact runtime/schema set and stable operation IDs |

Adjacent operations stay owned by their current domains: Marketing list membership,
Taxonomy, Sales quick-create, CustomerPortal acceptance/membership administration,
Signal rule execution and Clients primary-contact creation. Verify their Contact boundary
without claiming those entire domains implemented. No Contact-owned public portal route exists.

Final verification must add actual runtime operation IDs, request/response schema names,
token/native rights, concrete test names and exact HTTP/DB read-back per row. Pending
rows remain pending until proven. Preserve existing URLs and getClientTicketContacts.
Baseline: HOME=/tmp php artisan test app/Modules/Contact/Tests/Feature passed 49 tests /
353 assertions (45.55s) on 2026-10-27. This is existing behavior, not future parity proof.


## Completed evidence by row

- C01-C03/C24: ContactApiParityTest list_filters_title_normalized_phone_pagination_and_statistics_are_canonical,
  mismatched_site_client_filters_are_rejected, all_workspace_and_repair_operations_have_successful_typed_readback;
  ClientTechTest canonical Client/Site tab and legacy-alias tests. Full resources expose relation roles,
  resolved Client/Site and bridge primary flags; ownership remains canonical.
- C04: stateless API filters have no session mutation; list test proves unfiltered results after scoped reads.
- C05/C07/C09: form_only_actor_can_discover_defaults_and_scoped_options_without_directory_access;
  context/lookups are validated against their concrete response schemas, including exact edit context.
- C06/C08/C10/C20/C25/C31: duplicate_lookup_and_create_reuse_do_not_overwrite_a_person,
  conflicting_email_and_phone_do_not_choose_an_arbitrary_contact,
  detail_and_patch_preserve_secondary_relations_bridge_and_addresses,
  selecting_a_secondary_phone_makes_it_primary_and_updates_the_stable_bridge;
  ContactModuleTest and ContactTicketApiRegressionTest verify shared create/update/legacy address paths.
- C11/C12: ContactPortalIssue254SimulationTest covers all nine entry-point/role combinations through
  actual create and activation requests. ContactApiParityTest covers invalid role/email/inactive Client,
  foreign Site/active-access rollback, default-off API execution, explicit preview/receipt/replay and no-resend.
  Native MySQL Livewire simulation independently proves central/Client/Site saved roles/scopes with
  fake delivery and full rollback of fixtures/audit. No human review or inbox receipt is inferred.
- C13-C15: complete resource/bridge/address read-back and
  related_history_exposes_only_the_contact_summary_and_keeps_provider_payload_private.
- C16/C17: settings_write_validates_and_returns_saved_values_with_audit and independent native/scope denials.
- C18-C23: canonical redirect/alias tests plus source/runtime inventory. Legacy delete is a warning redirect,
  not a supported destructive Contact action. The unregistered legacy update method is not counted as a route.
- C26-C30: ContactModuleTest ownership inspection/move/bulk/detach/orphan cases and
  repair_requires_preview_key_and_current_state_and_preserves_bridge_id;
  all_workspace_and_repair_operations_have_successful_typed_readback proves each exact HTTP operation.
- C32/C33: all_nineteen_operations_require_current_native_authority_and_their_token_abilities,
  preview_is_bound_to_exact_action_target_body_and_token, receipt isolation/revocation/replay and
  ContactApiAfterCommitTest actual outer-commit unknown-outcome/no-repeat regression.
  Native MySQL independently verifies create/replay/read/receipt encryption, rollback and competing locks.
- C34: every_runtime_contact_operation_has_one_typed_swagger_contract and concrete response validation
  record all 19 successful methods. Four new abilities have explicit read/write classifications.
  getClientTicketContacts retains its critical legacy Ticket ID instructions in summary and description.

All test names are in app/Modules/Contact/Tests/Feature unless a different class is named.
Machine-readable operation IDs, schemas, scopes/native rights and observed responses are in the
companion JSON and contact-api-contracts.json. Full current verification is in contact-api-verification.md.
No connector grants, provider runtime, production code or human-review checkboxes were changed.
