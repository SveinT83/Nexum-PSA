# Changelog

All notable Nexum PSA releases are recorded here. Release Please maintains future entries from
Conventional Commit messages merged into `main`.

## [0.3.0-beta](https://github.com/SveinT83/Nexum-PSA/compare/v0.2.0-beta...v0.3.0-beta) (2026-09-03)


### Features

* complete approved beta issue batch ([5db9926](https://github.com/SveinT83/Nexum-PSA/commit/5db9926914f23bfbef7280d90ee3943dc246d902))
* **email:** complete provider and ticket delivery safeguards ([71567d2](https://github.com/SveinT83/Nexum-PSA/commit/71567d2122dd1199c77d0c57c539d0dd7ccda375))
* **email:** retire provider-first credential path ([a9395a9](https://github.com/SveinT83/Nexum-PSA/commit/a9395a988b9a850967451ae2698c62b93e08c5d4))
* **email:** simplify account connection setup ([5a24edc](https://github.com/SveinT83/Nexum-PSA/commit/5a24edc5af8dc6bebe66fd2d3230bc8b2e7e8d4d))
* **intake:** finalize public inquiry form routing and submission review ([a3614ef](https://github.com/SveinT83/Nexum-PSA/commit/a3614efc296d6744d7a33e7ea6417c33d2628b31))
* **integration:** add cloudfactory commerce and legal workflows ([e4cf92a](https://github.com/SveinT83/Nexum-PSA/commit/e4cf92a4175d3d896be3da4417fc64704a157b0b))
* **integration:** implement AI rate card system and cost telemetry ([884459e](https://github.com/SveinT83/Nexum-PSA/commit/884459e46271f028c68bd6a660f1645dcdfabe2d))
* **knowledge:** increase article body capacity to handle large HTML and Markdown content ([03e2a12](https://github.com/SveinT83/Nexum-PSA/commit/03e2a1250e63e8c4d41e51c03e65b71e2441e298))
* **mail:** add migrations for email live security, triggers, and guards ([3333275](https://github.com/SveinT83/Nexum-PSA/commit/3333275c2a473e9ce4332460e6763ed962f82513))
* **mail:** complete live authority invalidation ([883f27b](https://github.com/SveinT83/Nexum-PSA/commit/883f27b1318dfce3225a45739b49a072f7757e65))
* **mail:** finish shared composer workflow ([a21ec8c](https://github.com/SveinT83/Nexum-PSA/commit/a21ec8c1c2aed7b4303c2dd2c458cd66e30011ce))
* **mail:** finish ticket suppression and merge compatibility ([cd68cea](https://github.com/SveinT83/Nexum-PSA/commit/cd68ceab24ac53095efe3c542e434e2c5e0d8192))
* **mail:** implement live email invalidation and projection handling ([a5c1279](https://github.com/SveinT83/Nexum-PSA/commit/a5c127990bd5592c6e356a4faebd3f07cfe11b40))
* **mail:** integrate email service broadcasting and cleanup routines ([3fa9c86](https://github.com/SveinT83/Nexum-PSA/commit/3fa9c869f4ff5d6037957d6d86b22c16bac0d919))
* **mail:** integrate email service broadcasting and cleanup routines ([b55cd13](https://github.com/SveinT83/Nexum-PSA/commit/b55cd1376772b40de8db4e3dc72f469ba30c480d))
* **mail:** unify Ticket conversation delivery ([adf5608](https://github.com/SveinT83/Nexum-PSA/commit/adf5608e971348b73a0c8108cb3746f4da7ea934))
* **marketing:** add evergreen contact sequences ([23614e8](https://github.com/SveinT83/Nexum-PSA/commit/23614e828dff83df02a7db10504ed0858647bc66))
* **notification:** add inbound email notification system ([570679f](https://github.com/SveinT83/Nexum-PSA/commit/570679f40122d5877aaad42c633841ec51e3da60))
* prepare Dev release candidate with CloudFactory and workflow hardening ([2cc048a](https://github.com/SveinT83/Nexum-PSA/commit/2cc048a7a378f23d3545b24638a694074b18e313))
* **storage:** unify and automate supplier orders ([83c4824](https://github.com/SveinT83/Nexum-PSA/commit/83c4824e1795c40db0be2b437f83a711536bde62))
* **storage:** unify and automate supplier orders ([c86a8b3](https://github.com/SveinT83/Nexum-PSA/commit/c86a8b33e86baaf10f21d28b59bf53603082fef1))
* **system:** automate release and build metadata ([0ff6e3f](https://github.com/SveinT83/Nexum-PSA/commit/0ff6e3f3b4de10470fbd80d7b6ea9dacc7f86507))
* **ticket:** add workflow v3 orchestration ([7bfdd87](https://github.com/SveinT83/Nexum-PSA/commit/7bfdd870f8e8967f2be6cc0ba3267c444a66525b))
* **ticket:** release storage reservations explicitly ([2929966](https://github.com/SveinT83/Nexum-PSA/commit/2929966592b598cd4ccee9aa86d3ff164eee2df1))
* **tickets:** add recurring scheduling and calendar integration ([c04c14b](https://github.com/SveinT83/Nexum-PSA/commit/c04c14b845a331792cbc974bd1bf0ad6b6cccedd))
* **tickets:** add scheduling functionality with SLA deferral support ([0a6022c](https://github.com/SveinT83/Nexum-PSA/commit/0a6022c47153a8cf1d0d304d2db0d89e7d636728))
* **tickets:** restrict "Mine" and "Unread" stats to open tickets only ([ad446f4](https://github.com/SveinT83/Nexum-PSA/commit/ad446f4cc73b07ff60b52d77374cf49adb8efee3))
* **warroom:** add shared sidebar and tests for dashboard and My Day views ([4f43d47](https://github.com/SveinT83/Nexum-PSA/commit/4f43d478825b54c46c41c544ea8829e0fa3af28d))


### Bug Fixes

* add rate-limit handling to BookStack API client ([27f6ae8](https://github.com/SveinT83/Nexum-PSA/commit/27f6ae8ece8577fc73098ae739eae965d2861b69))
* BookStack API rate-limit handling (429 Too Many Attempts) ([b8e6120](https://github.com/SveinT83/Nexum-PSA/commit/b8e6120ca0683707f4f963fdd656945024218619))
* **email:** clarify provider credential lifecycle ([15c2a93](https://github.com/SveinT83/Nexum-PSA/commit/15c2a9365863c04ca93d8da471ceeb67fd0af289))
* **email:** confine legacy lifecycle to tests ([2a5abef](https://github.com/SveinT83/Nexum-PSA/commit/2a5abef1f4faf9ae956218fe026ff7f7e6757a1e))
* **email:** verify providers through bounded worker ([eea9247](https://github.com/SveinT83/Nexum-PSA/commit/eea9247adac9634f90abe782bb864cadf6907838))
* **integration:** correct BookStack retry semantics ([c2e8061](https://github.com/SveinT83/Nexum-PSA/commit/c2e8061cd3b9b33939f3a37d9c6318da4a3aa2a2))
* **integration:** remove retired email provider card ([2c5c62a](https://github.com/SveinT83/Nexum-PSA/commit/2c5c62aefd8b0519b14c078d81bdf08b2f3b216b))
* **notification:** harden web push migration fallback ([bb020e5](https://github.com/SveinT83/Nexum-PSA/commit/bb020e5988c7f80e0491e414ed34aefbc18fa869))
* **notification:** harden web push migration fallback ([14906c9](https://github.com/SveinT83/Nexum-PSA/commit/14906c90842b46a1912ddf0a6d3ec4874d0236f1))
* **sales:** reuse follow-up calendar events ([cc090a8](https://github.com/SveinT83/Nexum-PSA/commit/cc090a85ff8bc2bd55ef4cdb8acf0bf7792d8f1a))

## 0.2.0-beta - 2026-06-02

- Expanded the connected beta across core PSA workflows, API coverage, administration, branding,
  permissions, reporting, integrations, and regression testing.
- Published the historical GitHub release under the legacy `Beta2` tag.

Historical detail remains available in the GitHub release notes.
