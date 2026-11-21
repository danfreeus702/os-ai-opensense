# E-book translations

The flagship guide `docs/ebook-opnsense-ai-guide.md` is the source of truth.
Translations live in this folder, one file per language:

| Language | File | Status |
|---|---|---|
| English | `../ebook-opnsense-ai-guide.md` | source |
| Spanish | `guide-es.md` | draft ch. 1–2 |
| French | `guide-fr.md` | draft ch. 1–2 |
| German | `guide-de.md` | draft ch. 1–2 |
| Portuguese | `guide-pt.md` | draft ch. 1–2 |

## Rules for translations

1. **Never translate commands, paths, API URLs, package names or GUI labels.**
   Keep `configctl`, `/conf/aiopensense.key`, `pkg add`, `Security Wizard`,
   `Undo last change`, etc. exactly as in English. If a GUI label must be
   localized for the reader, give the English label in parentheses first.
2. **Technical terms stay in English** where the local community uses them:
   DoT, DNSSEC, Unbound, ClamAV, CrowdSec, configd, pf, LLM, GUI. Use the
   localized generic term on first mention, e.g. *caché DNS (DNS cache)*.
3. **Numbers and test results must stay identical**: 44 commits, 157 menu
   links, two OPNsense 26.7.9_3 VMs, zero failures.
4. **Keep the markdown structure** — same headings, tables and code fences.
5. These are AI-assisted drafts. Have a native speaker review before selling.

## Glossary (shared)

| English | ES | FR | DE | PT |
|---|---|---|---|---|
| firewall | cortafuegos | pare-feu | Firewall | firewall |
| reviewable plan | plan revisable | plan révisable | überprüfbarer Plan | plano revisável |
| rollback snapshot | instantánea de reversión | instantané de restauration | Rollback-Snapshot | snapshot de reversão |
| plain-language diff | diferencias en lenguaje claro | différences en langage clair | Änderungen in Klartext | diferenças em linguagem simples |
| blocklist | lista de bloqueo | liste de blocage | Blocklist | lista de bloqueio |
| upstream | servidor upstream | serveur upstream | Upstream-Server | servidor upstream |
| exit node | nodo de salida | nœud de sortie | Exit-Node | nó de saída |
| audit log | registro de auditoría | journal d'audit | Audit-Log | registro de auditoria |

## Workflow when chapters are finished

1. Update the English source first.
2. Sync the changed headings/paragraphs into each translation.
3. Re-check code blocks against the source (they must be byte-identical).
4. Export each file to PDF/EPUB with a localized cover using the flyer artwork.
5. Create separate listings per language (Gumroad/Payhip/Leanpub support this).
