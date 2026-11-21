# Initiative application — Deciso / OPNsense

**Status:** draft, ready to send. Deciso currently lists no vacancies but explicitly
welcomes "initiative applications" by email to `contact@deciso.com`.

## Email draft

**To:** contact@deciso.com
**Subject:** Initiative application — OPNsense AI Security Wizard plugin author

> Hello Deciso team,
>
> My name is Daniel Freeman (CCNA, 2006). I've been building a native OPNsense
> plugin called **OPNsense AI – Security Wizard** and would like to introduce
> myself for any future developer or community role.
>
> The plugin adds a three-tier, review-before-apply hardening wizard to the
> OPNsense GUI: L1 (Unbound cache, blocklists, ClamAV), L2 (DoT + CrowdSec), and
> L3 (DNSSEC, second DoT, optional Tailscale exit node). It also includes a
> cloud-LLM assistant that is grounded in the official documentation, adapts its
> recommendations to the appliance's CPU/RAM/interfaces, shows a plain-language
> diff with exact GUI paths before changes, and saves a config snapshot with a
> one-click undo.
>
> I treated it as a real engineering exercise rather than a demo: 44 commits,
> packaging via `make package`, clean install/delete cycles on two OPNsense
> 26.7.9 VMs, a full 157-link menu regression sweep with zero console errors,
> and a repeatable L1→L2→L3 test suite with zero failures. While doing this I
> fixed several 26.7 compatibility issues (removed `getParam`/`trim` sanitizer
> APIs, non-autoloaded services, Volt array access) that are documented in the
> repository notes.
>
> The project is open source under the ISC license:
> https://github.com/danfreeus702/os-ai-opensense
>
> I'd be glad to discuss contributing to the official plugin repository, or to
> send a short technical write-up of what I learned about the 26.7 MVC and
> configd APIs. Thank you for your time.
>
> Kind regards,
> Daniel Freeman
> danfreeus702

## Notes before sending

- Attach the beige flyer if you want a visual: `images/opnsense-ai-flyer-beige.png`.
- Keep it short — the repo speaks for itself.
- Replace "44 commits" with the current count if it has grown.
- I cannot send this email from this machine (no mail credentials); send it from your own email client.
