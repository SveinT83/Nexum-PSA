# Assets Browser/API Coverage Matrix

Parent: approved RFC 2026-10-26-assets-full-api-parity. Exact methods, scopes and
actor rights: 2026-10-26-assets-api-contract.json. All module-owned browser routes
and the mounted Livewire actions below are in scope. No supported browser deletion exists.

| ID | Browser route/action and owner | Class | Official API evidence | Tests |
| --- | --- | --- | --- | --- |
| A01 | assets.index, clients.assets.index, clients.assets.legacy; global/client filter/sort/page | Read | GET assets; every AssetQuery filter, stateless paging and sorting | all_browser_filters |
| A02 | assets.show summary; ownership/vendor, network, classification, lifecycle, raw metadata | Read | GET assets/{asset}, typed record/revision | classification_round_trips; exact_keyed_replay |
| A03 | assets.show outage/checks and AssetAlerts.render | Read | GET assets/alerts with asset_id/status/unresolved_only | alert_tabs_and_summary |
| A04 | ClientAlertsSummary.render global/client counts | Read | GET assets/alerts/summary | alert_tabs_and_summary |
| A05 | assets.create, AssetForm.mount/updatedClientId | Read | GET assets/context and assets/lookups/{clients,sites,owners,vendors}; asset_id preserves disabled legacy options | context; lookups; disabled_legacy_type |
| A06 | assets.store; AssetForm.save(create) | Write | POST assets; shared SaveManualAsset defaults/ownership | strict_fields; settings; receipt; module fallback |
| A07 | assets.edit, assets.update; AssetForm.save(update) | Write | GET assets/{asset}/context? through GET assets/context?asset_id; PUT/PATCH assets/{asset}, all manual fields and existing enrichment | classification; disabled_legacy_type; strict_fields; revisions |
| A08 | assets.docs and embedded documentation | Read | GET assets/docs, same Markdown source | lookups_are_client_scoped |
| A09 | admin.settings.assets edit | Read/admin | GET assets/settings, assets.settings.read plus asset.manage_settings | settings_use_browser_rules |
| A10 | admin.settings.assets.update | Write/admin | PUT assets/settings; shared UpdateAssetSettings validation/read-back | settings; actor_permissions |
| A11 | AssetAlerts.syncAlerts; ClientAlertsSummary.syncAlerts -> AlertSyncProcessor global/client | Action | POST assets/alerts/sync/preview and sync, explicit asset/client/global scope; shared SyncAssetAlerts and Integration-owned jobs | provider_disabled; confirmation; tactical_alert_sync |
| A12 | Sync N-able button; NAbleRmmSync assets_from local/global/targeted | Action | POST assets/rmm/preview and sync provider=rmm; shared ImportNableAssets | nable_import |
| A13 | Sync Tactical button; TacticalRmmSync fetchCounts/processAssetsChunk | Action | POST assets/rmm/preview (inspect_provider=true) and sync provider=tactical_rmm; canonical job mapping, client/site fence | tactical_import |
| A14 | Provider progress/result, manual write exact result | Read/action | GET assets/operations/{operation}; owner/token/current operation rights, encrypted durable receipt | exact_keyed_replay; real_token; interrupted_receipt |

All 17 method/path pairs have explicit Sanctum abilities, current actor checks,
typed responses and generated Swagger. Runtime and Swagger sets are checked both
ways in AssetApiContractTest. The four lookups share one documented kind enum.
The related Tickets block is a static empty state, not an implemented relation action.
RMM routing administration belongs to Integration and #228 is separate future work.
Client/Site/Owner/Vendor records remain owned by their modules; lookups expose only
IDs/names already needed by the Asset form. No credentials enter responses.
