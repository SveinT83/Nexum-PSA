import hashlib
import struct
import uuid
import unicodedata


MAGIC = b"NEXUM-VAULT\x00"


def field(tag: int, payload: bytes) -> bytes:
    return bytes([tag]) + struct.pack(">I", len(payload)) + payload


def null() -> bytes:
    return field(0x00, b"")


def raw(value: bytes) -> bytes:
    return field(0x01, value)


def text(value: str) -> bytes:
    assert unicodedata.normalize("NFC", value) == value
    return field(0x02, value.encode("utf-8"))


def u64(value: int) -> bytes:
    return field(0x03, value.to_bytes(8, "big", signed=False))


def flag(value: bool) -> bytes:
    return field(0x04, b"\x01" if value else b"\x00")


def instant(value: int) -> bytes:
    return field(0x05, struct.pack(">q", value))


def uid(value: str) -> bytes:
    return field(0x06, uuid.UUID(value).bytes)


def document(family: str, *fields: bytes) -> bytes:
    return MAGIC + family.encode("ascii") + b"\x00" + b"".join(fields)


installation = "01890f00-0000-7000-8000-000000000001"
plan_graph = "01890f00-0000-7000-8000-000000000002"
proof = "01890f00-0000-7000-8000-000000000003"
plan_authority = "01890f00-0000-7000-8000-000000000004"
plan_quorum = "01890f00-0000-7000-8000-000000000005"
lock = "01890f00-0000-7000-8000-000000000006"
obligation = "01890f00-0000-7000-8000-000000000007"
user_security_execution = "01890f00-0000-7000-8000-000000000008"
post_review_origin = "01890f00-0000-7000-8000-00000000000a"
post_review_operation = "01890f00-0000-7000-8000-00000000000b"
post_review_execution = "01890f00-0000-7000-8000-00000000000c"
plan_emergency = "01890f00-0000-7000-8000-00000000000d"
fresh_install_run = "01890f00-0000-7000-8000-00000000000e"
audit_trust_cutover = "01890f00-0000-7000-8000-00000000000f"
audit_event = "01890f00-0000-7000-8000-000000000010"
audit_correlation = "01890f00-0000-7000-8000-000000000011"

vectors = {}
vectors["empty"] = document("nexum.vault.vector.empty.v1")
vectors["nonempty"] = document(
    "nexum.vault.vector.nonempty.v1",
    text("abc"),
    null(),
    raw(b""),
    flag(True),
    u64(4294967296),
)
vectors["name"] = document("nexum.vault.name-key.v1", text("Økonomi"))
vectors["session"] = document(
    "nexum.vault.session-binding.v1",
    uid(installation),
    u64(42),
    raw(b"session-01"),
)

audit_manifest_entry = document(
    "nexum.vault.entry.foundation-audit-trust-manifest.v1",
    u64(7),
    uid(audit_event),
    text("client"),
    u64(17),
    u64(17),
    null(),
    null(),
    u64(42),
    text("human"),
    text("readiness_checked"),
    text("succeeded"),
    text("ready"),
    text("vault.health_view"),
    null(),
    null(),
    uid(audit_correlation),
    instant(1_000_000),
    instant(2_000_000),
)
vectors["foundation_audit_trust_manifest"] = document(
    "nexum.vault.foundation-audit-trust-manifest.v1",
    uid(installation),
    uid(audit_trust_cutover),
    u64(1),
    raw(audit_manifest_entry),
)

candidate_entry = document(
    "nexum.vault.entry.candidate.v1",
    u64(42),
    u64(2),
    u64(7),
    u64(9),
)
vectors["candidate"] = document(
    "nexum.vault.candidate-roster.v1",
    uid(installation),
    u64(3),
    u64(1),
    raw(candidate_entry),
)

governance_ready_entry = document(
    "nexum.vault.entry.governance-ready-roster.v1",
    raw(candidate_entry),
    u64(2),
    flag(True),
    flag(True),
    flag(True),
)
vectors["governance_ready_roster"] = document(
    "nexum.vault.governance-ready-roster-set.v1",
    text("governance-ready"),
    uid(installation),
    u64(3),
    u64(1),
    raw(governance_ready_entry),
)

reachability_entry = document(
    "nexum.vault.entry.reachability.v1",
    text("user"),
    u64(42),
    null(),
    text("item"),
    uid(plan_graph),
    null(),
    text("vector.operation"),
)
vectors["reachability_set"] = document(
    "nexum.vault.set.v1",
    text("reachability"),
    uid(installation),
    text("client"),
    u64(17),
    u64(17),
    u64(5),
    u64(1),
    raw(reachability_entry),
)

group_epoch_entry = document(
    "nexum.vault.entry.group-epoch.v1",
    uid(plan_graph),
    u64(5),
    raw(b"\x24" * 32),
)
vectors["group_epoch_set"] = document(
    "nexum.vault.set.v1",
    text("graph-plan-groups"),
    uid(installation),
    text("client"),
    u64(17),
    u64(17),
    u64(5),
    u64(1),
    raw(group_epoch_entry),
)

collection_epoch_entry = document(
    "nexum.vault.entry.collection-epoch.v1",
    uid(plan_authority),
    u64(7),
    raw(b"\x25" * 32),
)
vectors["collection_epoch_set"] = document(
    "nexum.vault.set.v1",
    text("graph-plan-collections"),
    uid(installation),
    text("client"),
    u64(17),
    u64(17),
    u64(5),
    u64(1),
    raw(collection_epoch_entry),
)

quorum_facts = document(
    "nexum.vault.quorum-authority-facts.v1",
    uid(installation),
    uid(plan_quorum),
    raw(b"\x44" * 32),
    raw(b"\x45" * 32),
    raw(b"\x46" * 32),
)
vectors["quorum_facts"] = quorum_facts

post_review_blocker_entry = document(
    "nexum.vault.entry.post-review-blocker.v1",
    uid(obligation),
    instant(1_000_000),
    u64(2),
    flag(False),
)
vectors["post_review_blocker_set"] = document(
    "nexum.vault.post-review-blocker-set.v1",
    text("post-review-blockers"),
    uid(installation),
    u64(9),
    u64(1),
    raw(post_review_blocker_entry),
)
vectors["post_review_gate_baseline"] = document(
    "nexum.vault.post-review-blocker-set.v1",
    text("post-review-blockers"),
    uid(installation),
    u64(1),
    u64(0),
)
vectors["fresh_install_empty_state"] = document(
    "nexum.vault.fresh-install-empty-state.v1",
    uid(installation),
    u64(0),
    u64(0),
    u64(0),
    u64(0),
    u64(0),
    u64(0),
    flag(False),
    flag(False),
)
fresh_install_permission_fact = document(
    "nexum.vault.entry.fresh-install-bootstrap-fact.v1",
    text("permission"),
    null(),
    u64(11),
    null(),
    null(),
    text("vault.grant_manage"),
    null(),
    flag(True),
    null(),
    null(),
    null(),
)
vectors["fresh_install_stage_snapshot"] = document(
    "nexum.vault.fresh-install-stage-snapshot.v1",
    uid(installation),
    uid(fresh_install_run),
    text("permissions_installed"),
    u64(1),
    u64(1),
    raw(fresh_install_permission_fact),
)
vectors["password_rehash_on_login_subject"] = document(
    "nexum.vault.subject.user-security.password-rehash-on-login.v1",
    u64(42),
    u64(3),
    u64(4),
    text("password_hash_policy_upgrade"),
)
vectors["remember_token_transition_subject"] = document(
    "nexum.vault.subject.user-security.remember-token-transition.v1",
    u64(42),
    text("password_login"),
    u64(3),
    u64(4),
)
vectors["user_security_pre_state_snapshot"] = document(
    "nexum.vault.user-security-pre-state-snapshot.v1",
    uid(installation),
    uid(user_security_execution),
    text("password-reset-token"),
    null(),
    null(),
    raw(b"\x51" * 32),
    raw(b"\x52" * 32),
    u64(3),
    u64(2),
    null(),
    instant(1_234_567),
)

session_digest = hashlib.sha256(vectors["session"]).digest()
candidate_digest = hashlib.sha256(vectors["candidate"]).digest()
name_digest = hashlib.sha256(
    document("nexum.vault.name-key.v1", text("Nettverk"))
).digest()

vectors["graph_plan"] = document(
    "nexum.vault.graph-plan.v1",
    uid(installation),
    uid(plan_graph),
    u64(42),
    raw(session_digest),
    u64(3),
    text("step_up"),
    uid(proof),
    text("collection.rename"),
    text("client"),
    u64(17),
    u64(17),
    raw(b"\x11" * 32),
    text("Nettverk"),
    raw(name_digest),
    text("planned_change"),
    raw(b"\x22" * 32),
    raw(b"\x23" * 32),
    u64(5),
    raw(b"\x24" * 32),
    raw(b"\x25" * 32),
    raw(candidate_digest),
    raw(b"\x26" * 32),
    raw(b"\x27" * 32),
    u64(9),
    raw(b"\x28" * 32),
    instant(0),
    instant(1_800_000_000),
)
graph_plan_digest = hashlib.sha256(vectors["graph_plan"]).digest()
vectors["post_review_authorization_snapshot"] = document(
    "nexum.vault.post-review-authorization-snapshot.v1",
    uid(installation),
    uid(post_review_origin),
    uid(plan_graph),
    raw(graph_plan_digest),
    text("graph"),
    uid(post_review_operation),
    u64(42),
    text("step_up"),
    uid(proof),
    raw(session_digest),
    u64(3),
    u64(3),
    raw(b"client:17"),
    u64(5),
    raw(b"\x22" * 32),
    raw(b"\x26" * 32),
    raw(b"\x27" * 32),
    u64(9),
    raw(b"\x28" * 32),
    uid(post_review_execution),
    instant(1_000_000),
    instant(1_800_000_000),
)
vectors["authority_plan"] = document(
    "nexum.vault.authority-plan.v1",
    uid(installation),
    uid(plan_authority),
    u64(42),
    raw(session_digest),
    u64(3),
    text("step_up"),
    uid(proof),
    text("authority.user_role_add"),
    raw(b"\x31" * 32),
    raw(candidate_digest),
    raw(b"\x32" * 32),
    raw(b"\x33" * 32),
    raw(b"\x34" * 32),
    u64(9),
    raw(b"\x35" * 32),
    text("planned_change"),
    instant(0),
    instant(1_800_000_000),
)
vectors["authority_emergency_plan"] = document(
    "nexum.vault.authority-plan.v1",
    uid(installation),
    uid(plan_emergency),
    u64(42),
    raw(session_digest),
    u64(3),
    text("step_up"),
    uid(proof),
    text("authority.emergency_deactivate"),
    raw(b"\x61" * 32),
    raw(candidate_digest),
    raw(candidate_digest),
    raw(b"\x62" * 32),
    raw(b"\x63" * 32),
    u64(9),
    raw(b"\x35" * 32),
    text("emergency_security_deactivation"),
    instant(0),
    instant(1_800_000_000),
)
vectors["quorum_plan"] = document(
    "nexum.vault.quorum-plan.v1",
    uid(installation),
    uid(plan_quorum),
    u64(42),
    raw(session_digest),
    u64(3),
    text("step_up"),
    uid(proof),
    text("quorum.peer_unlock"),
    uid(lock),
    instant(0),
    instant(1_000_000),
    raw(b"\x41" * 32),
    raw(candidate_digest),
    raw(b"\x42" * 32),
    raw(b"\x43" * 32),
    raw(quorum_facts),
    u64(9),
    raw(b"\x47" * 32),
    text("reviewed_unlock"),
    flag(True),
    instant(0),
    instant(300_000_000),
)

for name, encoded in vectors.items():
    print(name)
    print(f"length={len(encoded)}")
    print(f"encoded={encoded.hex()}")
    print(f"sha256={hashlib.sha256(encoded).hexdigest()}")
