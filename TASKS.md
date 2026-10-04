# Migrator 1.5.0: Reprint in FREE (branch feat/reprint-1.5.0)

Decided with the user 2026-10-04: Reprint via its packages, all of it in FREE (pull, push, delta), fix the Reprint issues that apply to us, ship the useful open PRs.

- [x] Packagist reprint-client 0.10.13 fatals without the sqlite submodule: vendor from git tag v0.10.13 instead
- [x] scripts/vendor-reprint.sh: tag + submodule + patches into lib/vendor/reprint (Plugin Check skips vendor folders; server strings moved to our text domain)
- [x] 23 open PRs: 15 ported (849 845 771 751 284 817 818 837 663 586 854 295 853 801 562); skipped 821 (in 817), 850 (in 853), 570 (rewriter redesigned in tag), 380 + 394 (old paths, huge conflicts), 460 (overlaps 837, conflicts with 849), 683 + 651 (drafts against removed behaviour)
- [~] Triage 31 open issues: (a) fixed by a PR, (b) fixed in our wrapper, (c) moot for us, (d) upstream only
  - (b) done: #24 password via MYSQL_PASSWORD env, #25 disk space before first pull, #114 server-bound drop-ins skipped, #789 host platform plugins excluded by default, #276 files push shipped
  - (c) moot: #327 (our floor is PHP 8.1), #792 (we never start the php built-in runtime)
  - (a) via ported PRs: #680 Retry-After (771), #670 response logging (751), #24 env credentials (284, plus our db-apply MYSQL_PASSWORD fallback), #836 SET members (845), #647 mapped index paths (663), #726 POST params (801)
  - (d) upstream only: #812-#816, #650 Windows/symlink paths; #822, #827, #128 database push; #622, #634, #628 internals; #543, #544, #791, #624 rewriter edge cases; #197 Atomic; #368 renames; #96 post-import sanitising (not built)
- [x] Source role: bundled Reprint Server, off by default, skipped when the Reprint Server plugin is active; push never overwrites Migrator or its backups
- [x] Target role: wp migrator pull / push / reprint (client in a subprocess, no WordPress loaded in it)
- [x] Pull into the running site: plugin folder survives, prefix check, DB snapshot + rollback, source home/siteurl rewritten (client maps only the connect address)
- [x] e2e: wp-env 8901 to 8902 pull + delta; host php -S site 8931 for push (wp-env cannot: push dir must be outside docroot on the same disk); diacritics intact
- [x] Package: Plugin Check sev5 PASS, zip 2.0 MB
- [x] readme (Pull and Push, grid, FAQ, third-party, 1.5.0 changelog)
- [~] store registry + docs en/pl/de/es: done, key-first flow update running; no Reprint name anywhere (user, 2026-10-04)
- [ ] PRO overlap: migrator-pro Transfer (wp-admin pull by key) now overlaps FREE; copy reworded, product decision is the user's
- [x] Bump 1.5.0, pot via scripts/make-pot.sh, catalogues merged empty
- [ ] PR, zip to ~/Downloads (no wp.org release)
