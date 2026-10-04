# Migrator 1.5.0: Reprint in FREE (branch feat/reprint-1.5.0)

Decided with the user 2026-10-04: Reprint via its packages, all of it in FREE (pull, push, delta), fix the Reprint issues that apply to us, ship the useful open PRs.

- [x] Packagist reprint-client 0.10.13 fatals without the sqlite submodule: vendor from git tag v0.10.13 instead
- [x] scripts/vendor-reprint.sh: tag + submodule + patches into lib/vendor/reprint (Plugin Check skips vendor folders; server strings moved to our text domain)
- [ ] Triage 23 open PRs: apply mergeable, non-draft, useful; record the rest with a reason
- [~] Triage 31 open issues: (a) fixed by a PR, (b) fixed in our wrapper, (c) moot for us, (d) upstream only
  - (b) done: #24 password via MYSQL_PASSWORD env, #25 disk space before first pull, #114 server-bound drop-ins skipped, #789 host platform plugins excluded by default, #276 files push shipped
  - (c) moot: #327 (our floor is PHP 8.1), #792 (we never start the php built-in runtime)
  - (a)/(d) pending the PR port report
- [x] Source role: bundled Reprint Server, off by default, skipped when the Reprint Server plugin is active; push never overwrites Migrator or its backups
- [x] Target role: wp migrator pull / push / reprint (client in a subprocess, no WordPress loaded in it)
- [x] Pull into the running site: plugin folder survives, prefix check, DB snapshot + rollback, source home/siteurl rewritten (client maps only the connect address)
- [x] e2e: wp-env 8901 to 8902 pull + delta; host php -S site 8931 for push (wp-env cannot: push dir must be outside docroot on the same disk); diacritics intact
- [x] Package: Plugin Check sev5 PASS, zip 2.0 MB
- [x] readme (Pull and Push, grid, FAQ, third-party, 1.5.0 changelog)
- [~] store registry + docs en/pl/de/es (agent running); PRO overlap list (no Freemius release)
- [x] Bump 1.5.0, pot via scripts/make-pot.sh, catalogues merged empty
- [ ] PR, zip to ~/Downloads (no wp.org release)
