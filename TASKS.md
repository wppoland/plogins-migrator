# Migrator 1.5.0: Reprint in FREE (branch feat/reprint-1.5.0)

Decided with the user 2026-10-04: Reprint via its packages, all of it in FREE (pull, push, delta), fix the Reprint issues that apply to us, ship the useful open PRs.

- [x] Packagist reprint-client 0.10.13 fatals without the sqlite submodule: vendor from git tag v0.10.13 instead
- [ ] scripts/vendor-reprint.sh: tag + submodule + patches (scripts/reprint-patches/<PR>.patch) into lib/reprint-server, lib/reprint-client
- [ ] Triage 23 open PRs: apply mergeable, non-draft, useful; record the rest with a reason
- [ ] Triage 31 open issues: (a) fixed by a PR, (b) fixed in our wrapper, (c) moot for us, (d) upstream only
- [ ] Source role: bundled Reprint Server, off by default, skipped when the Reprint Server plugin is active
- [ ] Target role: wp migrator pull / push / reprint (client in a subprocess, no WordPress loaded in it)
- [ ] Pull into the running site: plugin folder survives, table prefix, DB snapshot before, URLs rewritten
- [ ] e2e: two wp-env sites, pull, delta pull, push back, Polish diacritics intact
- [ ] Package: .distignore, assert-package-clean, Plugin Check sev5, size
- [ ] readme, docs en+pl, registry, compare grid; PRO overlap list (no Freemius release)
- [ ] Bump 1.5.0, PR, zip to ~/Downloads (no wp.org release)
