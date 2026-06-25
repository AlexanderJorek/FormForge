# CLAUDE.md — form-forge

## Project Goal

Build a custom WordPress form plugin (`form-forge`) to replace Forminator. All work goes in `.new/`. The `.old/` directory is **read-only — never modify anything inside `.old/`**.

## Directory Layout

```
.old/   — read-only reference: Forminator source, forminator-addons-pdf, forminator-custom-pdf
.new/   — active development target (the new plugin)
```

## Scope

### Fields (implemented)

- text, textarea, email, name, phone, number
- address, date, time, currency
- select, radio, multivalue (checkboxes)
- upload, signature (canvas)
- rating, slider
- captcha, consent, gdprcheckbox
- html, feldgruppe (section), page-break
- postdata, website
- **SEPA Lastschriftmandat** — composite field (IBAN with masked input, BIC, Kontoinhaber, static creditor info block, dual signature canvases)

**Discarded** (out of scope): calculation, hidden, password

### Data Storage

No form submission data is ever stored locally. All data goes to email and/or PDF only. No custom DB tables for entries.

### PDF System

Port `.old/forminator-custom-pdf` to work with the new plugin. The coupling points are:
- `SubmissionHook.php` — field-type mapping (replace Forminator field types with new plugin's types)
- `MailAttachments.php` — hooks into Forminator mail system (replace with new plugin's mail hooks)

Everything else (Generator, HashSeal, Verificationpage, Admin/FormSettings) can be carried over with minimal changes.

### UI / Admin

Similar to Forminator's drag-and-drop builder. Implementation approach is open as long as it is performant and secure. A lightweight JS solution is preferred over a heavy framework.

## Security & Code-Quality Review Protocol

Whenever reviewing or significantly modifying a file, silently evaluate it against the four frameworks below and report any findings (severity: Critical / High / Medium / Low). Provide file + line, violated standard, risk description, and a fixed code snippet for each finding.

| Framework | Focus |
|---|---|
| **Best Practices** | Clean code, modularity, error handling, performance |
| **OWASP Top 10** | Injection, broken access control, SSRF, XSS, CSRF, insecure deserialization, etc. |
| **CERT Coding Standards** | Insecure constructs, undefined behaviour, input validation |
| **NIST SSDF** | Protecting software, producing secure software, responding to vulnerabilities |

Output format for each finding:

```
[SEVERITY] File:line — Standard violated
Risk: <one sentence>
Fix:
<minimal corrected code snippet>
```

After all findings: one paragraph of strategic recommendations for long-term NIST SSDF / CERT alignment.

### JS Assets

Follow the pattern from `.old/forminator-custom-pdf/assets/js/` — static files served directly, no build step unless explicitly added.
