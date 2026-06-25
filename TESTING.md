# FormForge — Manual Test Checklist

## Setup

```bash
# In WSL, from repo root:
docker compose up -d
bash setup-wp.sh
```

Open http://localhost:8080/wp-admin → login `admin / admin`  
Navigate to **FormForge → Neues Formular**

---

## 1 · Builder UI

| # | Step | Expected |
|---|------|----------|
| 1.1 | Open builder on a new form | Canvas is empty, save indicator absent |
| 1.2 | Type a form name | Title updates in header input |
| 1.3 | Drag a Text field onto the canvas | Field tile appears, no JS error |
| 1.4 | Click **Speichern** | Status pill shows blue spinner → green "Gespeichert" → fades |
| 1.5 | Click **Speichern** again immediately | Same cycle repeats; no duplicate pills |
| 1.6 | Click **Vorschau** | Opens new tab with rendered form; no blank page |
| 1.7 | While preview loads | Button shows spinner + "Vorschau" label; re-enables after tab opens |

---

## 2 · Field Rendering (add one of each to a test form, save, open preview)

| Field | Check |
|-------|-------|
| Text | Input renders, placeholder shows |
| Textarea | Resizable, min-height correct |
| Email | Type invalid → format error on submit |
| Name | Prefix dropdown + first/last inputs render |
| Phone | Input renders |
| Number | Up/down arrows; rejects alpha input |
| Address | Street / city / zip / country sub-fields render |
| Date | Text input + calendar button; pick date → formats as DD.MM.YYYY |
| Time | Input renders; invalid format rejected |
| Currency | Amount + currency code |
| Select | Custom dropdown opens, option selects, closes |
| Radio | Options render; only one selectable |
| Checkbox (multivalue) | Multiple checkable; at least one required if marked |
| Upload | File input renders; button opens dialog |
| Signature | Canvas renders; can draw; clear button works |
| Rating | Stars render; clicking sets value; hover effect works |
| Slider (single) | Thumb draggable; value display updates |
| Slider (range) | Both thumbs draggable; fill between them |
| Captcha | reCAPTCHA widget loads (needs site key in settings) |
| Consent | Checkbox + label; required blocks submit |
| GDPR | Checkbox + policy link; required blocks submit |
| HTML | Raw HTML content rendered as-is |
| Feldgruppe | Fields inside group render; group label shows |
| Page Break | "Weiter" button navigates; page counter updates |
| SEPA | IBAN masked input, BIC, Kontoinhaber, creditor block, dual canvases |
| Website | URL input; invalid URL rejected |

---

## 3 · Validation

| # | Test | Expected |
|---|------|----------|
| 3.1 | Submit empty required Text | "Dieses Feld ist ein Pflichtfeld." under field; scroll to it |
| 3.2 | Submit empty required Slider | Same error (slider was untouched, value is '') |
| 3.3 | Drag slider to 0, submit | Passes — value is "0" not '' |
| 3.4 | Submit invalid Email | Format error message |
| 3.5 | Submit invalid Date (e.g. 99.99.2024) | Date format error |
| 3.6 | Multi-page: click Weiter with empty required field on page 1 | Blocks navigation, error shown |
| 3.7 | Multi-page: fill page 1, click Weiter | Advances to page 2 |
| 3.8 | Multi-page: click Zurück | Returns to page 1, no data loss |
| 3.9 | Preview toolbar "Pflichtfelder ignorieren" on | Weiter and submit bypass required checks |

---

## 4 · Submission

| # | Test | Expected |
|---|------|----------|
| 4.1 | Fill all fields, click Absenden | Button shows "Wird gesendet…" + spinner while pending |
| 4.2 | Success | Spinner hidden; label restores to "Absenden"; green success box shown |
| 4.3 | Submit again | Fresh validation; previous success message cleared |
| 4.4 | Honeypot (fill `forge_hp_field` via DevTools) | Submission silently rejected (success UI but no email sent) |

---

## 5 · Submit Text Customisation

| # | Test | Expected |
|---|------|----------|
| 5.1 | In builder Settings, change "Schaltflächen-Text" | Preview shows new label immediately |
| 5.2 | Change "Sende-Text" | Live form shows it during submit |
| 5.3 | Change "Erfolgsmeldung" | Success box shows new text after submit |
| 5.4 | Unsaved settings → preview | Preview reflects unsaved values, not DB values |

---

## 6 · Email

> Configure a notification in the builder (Benachrichtigungen tab) before this section.

| # | Test | Expected |
|---|------|----------|
| 6.1 | Submit valid form | Email arrives at configured address |
| 6.2 | Email body contains all field values | Each field label + value present |
| 6.3 | File upload included | Attachment present in email |

> Use [Mailpit](https://mailpit.axllent.org/) for local email capture:  
> Add `mailpit` service to docker-compose and set `WORDPRESS_SMTP_HOST=mailpit:1025`.

---

## 7 · PDF (if forminator-custom-pdf is ported)

| # | Test | Expected |
|---|------|----------|
| 7.1 | Submit form with PDF notification enabled | PDF attached to email |
| 7.2 | Open PDF | All field values present, signature images embedded |
| 7.3 | SEPA field in PDF | IBAN shown masked, both signatures visible |

---

## 8 · Accent Colour

| # | Test | Expected |
|---|------|----------|
| 8.1 | Settings → Appearance → change accent colour to red | All form highlights, focus rings, submit button turn red |
| 8.2 | Switch back to default | Reverts correctly |

---

## 9 · Security smoke-test

| # | Test | Expected |
|---|------|----------|
| 9.1 | Submit without nonce (remove `forge_nonce` in DevTools) | 400 / error response |
| 9.2 | XSS in text field (`<script>alert(1)</script>`) | Stored/emailed as escaped text, not executed |
| 9.3 | Logged-out user visits builder URL | Redirected to login / permission denied |

---

## Teardown

```bash
docker compose down -v   # removes containers AND volumes (full reset)
```
