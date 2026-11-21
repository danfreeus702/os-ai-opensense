# Hermes AI — OPNsense Native Plugin

**Architecture & Design Document**

---

## 1. Overview

Hermes is a native OPNsense plugin that embeds an AI chat assistant into the firewall's web GUI. Users describe what they want in plain language — "allow HTTPS to my web server from the internet" — and the AI returns correct OPNsense configuration: firewall rules, NAT entries, aliases, and routing changes — in the exact format the firewall expects, ready to review and apply.

**Target:** OPNsense 24.7+

**Plugin identifier:** `os-hermes`

**LLM backend:** Ollama (local `127.0.0.1:11434` or remote reachable host). Model is configurable; default `llama3` or `llama3.1`.

---

## 2. Architecture

```
┌─────────────────────────────────────────────────┐
│                 OPNsense GUI (Web)              │
│  ┌─────────────────────────────────────────┐    │
│  │         Hermes Chat Panel               │    │
│  │  ┌───────────────────────────────────┐  │    │
│  │  │ User message (natural language)   │  │    │
│  │  └───────────────────────────────────┘  │    │
│  │         ↓  AJAX POST /api/hermes/chat  │    │
│  └─────────────────────────────────────────┘    │
│                    ↑ JSON response              │
├─────────────────────────────────────────────────┤
│         os-hermes Plugin (PHP/Phalcon MVC)      │
│  ┌─────────────┬──────────────┬────────────┐    │
│  │ Index       │ Api/Chat     │ Api/Guide  │    │
│  │ Controller  │ Controller   │ Controller │    │
│  ├─────────────┼──────────────┼────────────┤    │
│  │ Model:      │ Config       │            │    │
│  │ Hermes      │ (settings)   │            │    │
│  └─────────────┴──────────────┴────────────┘    │
│                    ↓ cURL                        │
├─────────────────────────────────────────────────┤
│              Ollama API (external)              │
│  POST /api/chat  ←  prompt + context            │
│  ←  response (JSON with message.content)        │
├─────────────────────────────────────────────────┤
│         OPNsense Backend (read-only)            │
│  /conf/config.xml  ←  current firewall state    │
│  configd          ←  optional: apply actions    │
└─────────────────────────────────────────────────┘
```

### Layers

| Layer | Responsibility |
|---|---|
| **GUI (Volt + jQuery)** | Chat panel, settings form, message history, guide wizard UI |
| **Controllers (PHP)** | Validate input, build prompt with OPNsense context, call Ollama via cURL, return structured response |
| **Model (PHP)** | Mount point for config.xml settings (`//OPNsense/hermes`) |
| **Config (XML)** | `enabled`, `ollama_host`, `ollama_model`, `api_key`, `system_prompt`, `max_tokens`, `temperature`, `timeout` |
| **LLM (Ollama)** | Receives prompt with OPNsense context; returns natural-language guidance *plus* structured configuration suggestions |
| **Backend (optional)** | configd actions for enable/disable; Python stub for future config-apply via API |

### Data flow (chat request)

1. User types message in chat panel → jQuery `POST /api/hermes/chat`
2. `ChatController::sendAction()` receives `{message, context_level}`
3. Controller loads current OPNsense config (`/conf/config.xml`), extracts relevant state
4. Controller builds system prompt (from settings) + user message + context block
5. Controller POSTs to Ollama `/api/chat` (or `/api/generate`) via cURL
6. Ollama responds with `message.content` (text) + optionally structured JSON
7. Controller parses response, returns `{response, used_context, tokens_estimate, error}`
8. Chat panel renders response; if structured config was returned, renders as apply-ready blocks

### Data flow (wizard / guide)

1. User opens a guide (Firewall Rules, Unbound DoT, Ad Blocking) from Guides menu
2. `GuideController::viewAction()` loads guide definition + AI-suggested step content via `GuideApiController`
3. `GuideApiController::getAISuggestion()` calls Ollama per step with current OPNsense context
4. Wizard UI renders each step with AI explanation, form fields, and next/back navigation
5. User fills fields → saves progress → proceeds → summary with apply-ready config

---

## 3. AI Prompt Design

The prompt is the core of how the AI understands OPNsense. It has three parts:

### System prompt (configurable, default)

> "You are Hermes, an AI assistant for OPNsense firewall administration. You help users configure their firewall through natural language. You understand OPNsense's configuration format: firewall rules in `/conf/config.xml`, NAT rules, aliases, interfaces, routing, Unbound DNS, and plugins. You always respond in clear, practical language. When users ask you to create or modify configuration, you provide the exact OPNsense-compatible entries (rule fields, NAT entries, alias definitions) formatted so the user can apply them directly through the GUI or CLI. You never apply changes yourself — you always show the user what to apply and let them decide."

### Context block (auto-generated, read-only)

```xml
--- Current OPNsense State (read-only snapshot) ---
hostname: <hostname>
interfaces:
  lan: <if>, <ipaddr>/<subnet>
  wan: <if>, <ipaddr>/<subnet> (or DHCP)
firewall_rules: <count> existing rules
nat_rules: <count> existing rules
aliases: <count> existing aliases
unbound: enabled=<bool>, dnstls_count=<n>
gateways: <list or none>
installed_plugins: [<list>]
--- End OPNsense State ---
```

This is a read-only snapshot — the AI sees what exists but cannot modify it. The AI uses this context to give relevant advice (e.g., "you already have a rule allowing SSH on WAN — do you want to modify it or add another?").

### User message

The user's natural-language request. Examples:

- "Allow HTTPS (443) to 192.168.1.50 from the internet, and block everything else inbound."
- "Set up DNS over TLS using Cloudflare."
- "Create an alias for my office network 10.0.0.0/8 and use it in a LAN rule."

### Response format

The AI returns free-text guidance *plus*, when applicable, structured configuration blocks. The plugin parses blocks delimited like:

```
=== FIREWALL RULE ===
action: pass
interface: wan
protocol: tcp
source: any
destination: 192.168.1.50
destination_port: 443
description: HTTPS to web server
=== END ===
```

The chat panel detects these blocks and renders them as apply-ready cards with an "Apply via GUI" hint.

---

## 4. Firewall Rules Feature (First Feature)

This is the primary feature to build first: **create firewall rules from natural language.**

### What it handles

| User request | AI produces |
|---|---|
| "Allow SSH from the internet to 192.168.1.10" | WAN pass rule, TCP port 22, destination 192.168.1.10 |
| "Block all inbound traffic except HTTPS to my web server" | Block rule + pass rule for TCP/443 |
| "Allow my LAN to reach the internet on ports 80 and 443 only" | LAN rules for TCP/80, TCP/443; block rest |
| "Forward port 8080 to 192.168.1.50:80" | NAT port forward rule + firewall rule |
| "Create an alias for 10.0.0.0/8 called OfficeNet" | Alias definition + suggestion to use it |

### Rule fields the AI knows

OPNsense firewall rule fields:
- `type` (pass/deny/block/reject)
- `interface` (wan/lan/opt1/etc.)
- `protocol` (tcp/udp/any/icmp)
- `source` (any/IP/net/alias)
- `destination` (any/IP/net/alias)
- `source_port` / `destination_port`
- `icmp_type`
- `descr` (description)
- `log` (yes/no)
- `stateless` (yes/no)
- `nat` (associated NAT rule)

### NAT fields the AI knows

- `interface` (wan)
- `proto` (tcp/udp/any)
- `src` (any)
- `src_port` (any)
- `dst` (WAN address or specific IP)
- `dst_port` (the port to forward)
- `target` (internal IP)
- `target_port` (internal port)
- `descr`

### How the AI constructs rules

The prompt instructs the AI to produce rule entries in OPNsense-compatible format. For firewall rules, it's the fields above. For NAT, it's the NAT fields above. The AI also recommends:

- Whether an alias should be created first (and what it should contain)
- Which interface the rule belongs on
- Whether a corresponding NAT rule is needed (for port forwards)
- Any ordering concerns (e.g., the pass rule should come before the block rule)

### What "apply" means

The plugin does **not** directly modify `/conf/config.xml`. Applying changes is done by:

1. **GUI apply:** The chat panel shows the rule entries in OPNsense's field layout; the user opens Firewall → Rules, creates a new rule, and fills in the fields the AI provided.
2. **CLI apply (future):** A configd action or REST API call that takes the AI-suggested rule and adds it to the config. This requires careful review — auto-applying firewall changes from an LLM is risky.

For the initial version, the AI shows the rule in a clear format, and the user applies it through the normal GUI. The plugin can optionally generate an XML snippet or a shell command using `configctl` for advanced users.

---

## 5. Configuration (settings.xml)

The plugin stores its settings under `//OPNsense/hermes`:

```xml
<hermes>
  <enabled>1</enabled>
  <ollama_host>http://127.0.0.1:11434</ollama_host>
  <ollama_model>llama3.1</ollama_model>
  <api_key></api_key>
  <system_prompt>You are Hermes, an AI assistant for OPNsense...</system_prompt>
  <max_tokens>2048</max_tokens>
  <temperature>0.7</temperature>
  <timeout>30</timeout>
</hermes>
```

All fields are editable through the Settings panel in the chat UI.

---

## 6. Security Considerations

- **Read-only config access:** The plugin reads `/conf/config.xml` but does not write to it directly. The AI never applies changes autonomously.
- **Ollama host authentication:** If Ollama is exposed over the network, use an API key (Ollama supports `--api-key` or proxy auth). The plugin stores the key in config.xml (readable only by root/www).
- **LLM trust boundary:** The AI's suggestions are advisory only. The user must review and apply each change. The plugin presents suggestions in a reviewable format, never silently applying them.
- **Context leakage:** The OPNsense context block includes internal IPs, aliases, and interface info. This is necessary for the AI to give relevant advice. If the Ollama backend is remote, consider whether this data should leave the firewall — users can opt for a local Ollama instance to keep context on-device.
- **HTTPS-only GUI:** OPNsense GUI is HTTPS by default; the chat panel inherits this.

---

## 7. Deployment

### Build

```bash
cd /path/to/os-hermes-plugin
make package
# Produces: os-hermes-0.1.txz
```

### Install on OPNsense

```bash
# Via SSH or SCP
scp os-hermes-0.1.txz root@opnsense:/tmp/
ssh root@opnsense
pkg add /tmp/os-hermes-0.1.txz
# Or via GUI: System → Firmware → Plugins → install from local file
```

### Configure Ollama

The firewall must be able to reach the Ollama API:

- **Local:** Run Ollama on the OPNsense box itself (FreeBSD — Ollama has a Linux/static build; check FreeBSD compatibility or run in a jail/VM).
- **Remote:** Point `ollama_host` to a reachable Ollama instance (e.g., `http://ollama.lan:11434`).

### First use

1. Install plugin
2. Open Hermes from the menu
3. Configure Ollama host + model in Settings
4. Type a message: "Allow SSH from WAN to 192.168.1.10"
5. Review the AI's suggested rule
6. Apply via Firewall → Rules GUI

---

## 8. File Manifest

```
os-hermes-plugin/
├── Makefile
├── pkg-descr
└── src/
    └── opnsense/
        ├── mvc/
        │   └── app/
        │       ├── controllers/OPNsense/Hermes/
        │       │   ├── IndexController.php
        │       │   ├── GuideController.php
        │       │   └── Api/
        │       │       ├── SettingsController.php
        │       │       ├── ChatController.php
        │       │       └── GuideApiController.php
        │       ├── models/OPNsense/Hermes/
        │       │   ├── Hermes.php
        │       │   ├── Hermes.xml
        │       │   └── Guide.php
        │       ├── views/OPNsense/Hermes/
        │       │   ├── index.volt
        │       │   ├── guides.volt
        │       │   └── guide.volt
        │       └── services/
        │           └── OPNsense/Hermes/
        │               └── Service.xml
        ├── mvc/app/forms/OPNsense/Hermes/
        │   └── general.xml
        ├── mvc/app/Menu/
        │   └── Menu.xml
        └── mvc/app/ACL/
            └── ACL.xml
```

---

## 9. Future Extensions

- **Apply via configd/REST:** A safe, reviewed path to apply AI-suggested rules directly (user confirms each change).
- **More wizards:** VPN setup, Captive Portal, Traffic Shaping, Reporting.
- **Conversation memory:** Use Ollama's chat history for multi-turn guidance ("now add a rule for…").
- **Structured output mode:** Ask the AI to return JSON instead of natural language for programmatic apply.
- **Local Ollama in a jail:** Run Ollama inside a FreeBSD jail on the OPNsense box for fully on-device AI.
- **Model fallback:** Try a secondary model if the primary fails.

---

*Document version: 1.0 — initial architecture for os-hermes plugin.*
