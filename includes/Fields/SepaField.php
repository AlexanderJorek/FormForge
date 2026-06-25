<?php

/**
 * @package   FormForge
 * @copyright 2026 Alexander Jorek
 * @license   GPL-2.0-or-later
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 */

namespace ForgeForms\Fields;

defined('ABSPATH') || exit;

class SepaField extends BaseField
{
    public function getClientEmptyCheck(): array
    {
        /* Validators must always run so that BIC/Kontoinhaber/Sig can show
           their own error messages. The sepa-required validator handles all
           required-field checks, including IBAN. */
        return ['fn' => 'function(){return false;}'];
    }

    public function getClientValidation(): array
    {
        return [
            [
                'rule' => 'iban',
                'fn'   => <<<'JS'
                function (fieldEl) {
                    var inp = fieldEl.querySelector('.forge-sepa-iban');
                    if (!inp) return null;
                    var raw = inp.value.replace(/[^A-Za-z0-9]/g, '');
                    if (!raw) return null;
                    if (inp._forgeIbanInvalid) return 'Ungültige IBAN (Prüfziffer fehlerhaft).';
                    if (!inp._forgeIbanValid)  return 'Bitte geben Sie eine vollständige und gültige IBAN ein.';
                    return null;
                }
                JS,
            ],
            [
                'rule' => 'sepa-bic',
                'fn'   => <<<'JS'
                function (fieldEl) {
                    var bic = fieldEl.querySelector('.forge-sepa-bic');
                    if (!bic || !bic.value.trim()) return null;
                    var bicErr = bic.parentNode.querySelector('.forge-field-error');
                    if (/^[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$/.test(bic.value.trim())) return null;
                    if (bicErr && !bicErr.textContent) bicErr.textContent = 'Bitte geben Sie einen gültigen BIC ein.';
                    return '​';
                }
                JS,
            ],
            [
                'rule' => 'sepa-required',
                'fn'   => <<<'JS'
                function (fieldEl) {
                    if (fieldEl.dataset.required !== 'true') return null;
                    var missing = false;
                    var iban = fieldEl.querySelector('.forge-sepa-iban');
                    var ibanErr = iban ? iban.parentNode.querySelector('.forge-field-error') : null;
                    if (iban && !iban.value.replace(/[^A-Za-z0-9]/g, '')) {
                        if (ibanErr && !ibanErr.textContent) ibanErr.textContent = 'IBAN ist erforderlich.';
                        missing = true;
                    }
                    var bic = fieldEl.querySelector('.forge-sepa-bic');
                    var bicErr = bic ? bic.parentNode.querySelector('.forge-field-error') : null;
                    if (bic && !bic.value.trim()) {
                        if (bicErr && !bicErr.textContent) bicErr.textContent = 'BIC ist erforderlich.';
                        missing = true;
                    }
                    var holder = fieldEl.querySelector('.forge-sepa-holder');
                    var holderErr = holder ? holder.parentNode.querySelector('.forge-field-error') : null;
                    if (holder && !holder.value.trim()) {
                        if (holderErr && !holderErr.textContent)
                            holderErr.textContent = 'Kontoinhaber ist erforderlich.';
                        missing = true;
                    }
                    var sig = fieldEl.querySelector('.forge-sepa-sig-data');
                    var sigErr = fieldEl.querySelector('.forge-sepa-sig-error');
                    if (sig && !sig.value) {
                        if (sigErr && !sigErr.textContent) sigErr.textContent = 'Bitte unterschreiben.';
                        missing = true;
                    }
                    return missing ? '​' : null;
                }
                JS,
            ],
        ];
    }

    public function getStyles(): string
    {
        return <<<'CSS'
.forge-field--sepa {
    border: 1px solid var(--forge-border);
    border-radius: var(--forge-radius);
    padding: 24px;
    margin-bottom: 24px;
    background: var(--forge-bg);
}
.forge-sepa-title { font-size: 16px; font-weight: 700; margin: 0 0 14px; color: var(--forge-text); }
.forge-sepa-text { font-size: 14px; line-height: 1.65; color: var(--forge-text); margin-bottom: 10px; }
.forge-sepa-note { font-size: 12px; color: var(--forge-text-muted); margin-bottom: 20px; line-height: 1.55; }
.forge-sepa-creditor {
    background: var(--forge-bg-subtle);
    border: 1px solid var(--forge-border);
    padding: 10px 14px;
    border-radius: var(--forge-radius-sm);
    font-size: 13px;
    color: var(--forge-text-muted);
    margin-bottom: 20px;
    line-height: 1.6;
}
.forge-sepa-creditor p { margin: 1px 0; }
.forge-field-inner { margin-bottom: 14px; }
.forge-sepa-signatures { display: flex; flex-direction: column; gap: 20px; margin-top: 20px; }
.forge-sepa-dual-sig { flex-direction: row; flex-wrap: wrap; gap: 16px; }
.forge-sepa-dual-sig .forge-sepa-sig-block { flex: 1; min-width: 240px; }
.forge-sepa-sig-block { display: flex; flex-direction: column; gap: 0; }
.forge-sepa-sig-label { font-size: 13px; font-weight: 600; margin: 0 !important; color: var(--forge-text); }
.forge-sepa-iban { font-family: monospace; letter-spacing: 1px; font-size: 15px; }
@media (max-width: 600px) {
    .forge-sepa-dual-sig { flex-direction: column; }
    .forge-field--sepa { padding: 16px; }
}
CSS;
    }

    public function getLabel(): string
    {
        return 'SEPA Lastschrift';
    }
    public function getIcon(): string
    {
        return 'fa-solid fa-building-columns';
    }

    public function getClientInit(): string
    {
        return <<<'JS'
        function (root) {
            var IBAN_LEN = {
                AD:24,AE:23,AL:28,AT:20,AZ:28,BA:20,BE:16,BG:22,BH:22,BI:27,BR:29,BY:28,
                CH:21,CR:22,CY:28,CZ:24,DE:22,DJ:27,DK:18,DO:28,EE:20,EG:29,ES:24,FI:18,
                FK:18,FO:18,FR:27,GB:22,GE:22,GI:23,GL:18,GR:27,GT:28,HR:21,HU:28,IE:22,
                IL:23,IQ:23,IS:26,IT:27,JO:30,KW:30,KZ:20,LB:28,LC:32,LI:21,LT:20,LU:20,
                LV:21,LY:25,MC:27,MD:24,ME:22,MK:19,MN:20,MR:27,MT:31,MU:30,NI:28,NL:18,
                NO:15,OM:23,PK:24,PL:28,PS:29,PT:25,QA:29,RO:24,RS:22,RU:33,SA:24,SC:31,
                SD:18,SE:24,SI:19,SK:24,SM:27,SO:23,ST:25,SV:28,TL:23,TN:24,TR:26,UA:29,
                VA:22,VG:24,XK:20,YE:30
            };
            function ibanTemplate(cc) {
                var len = IBAN_LEN[cc];
                if (!len) return cc + '__ …';
                var raw = cc + new Array(len - 1).join('_');
                var out = '', pos = 0;
                while (pos < raw.length) {
                    if (pos > 0) out += ' ';
                    out += raw.substring(pos, pos + 4);
                    pos += 4;
                }
                return out;
            }
            root.querySelectorAll('.forge-sepa-iban').forEach(function (input) {
                if (input._forgeIbanInited) return;
                input._forgeIbanInited = true;
                var filterMode  = input.dataset.countryFilter || 'off';
                var filterList  = input.dataset.countryList
                    ? input.dataset.countryList.toUpperCase().split(',') : [];
                var defaultCc   = (input.dataset.placeholderCountry || 'DE').toUpperCase();
                var errorEl     = input.parentNode.querySelector('.forge-field-error');
                var lastValidCc = IBAN_LEN[defaultCc] ? defaultCc : 'DE';
                input.placeholder = ibanTemplate(lastValidCc);
                function countryAllowed(cc) {
                    if (filterMode === 'off' || !filterList.length) return true;
                    var inList = filterList.indexOf(cc) !== -1;
                    if (filterMode === 'allow')    return inList;
                    if (filterMode === 'disallow') return !inList;
                    return true;
                }
                var noticeEl = input.parentNode.querySelector('.forge-field-hint');
                function showError(msg)       { if (errorEl)  errorEl.textContent  = msg; }
                function showIbanNotice(msg)  { if (noticeEl) noticeEl.textContent = msg; }
                function getBicInput() {
                    var field = input.closest('.forge-field--sepa');
                    return field ? field.querySelector('.forge-sepa-bic') : null;
                }
                var bicManuallyEntered = false;
                function lookupBic(iban) {
                    if (bicManuallyEntered) return;
                    var bicInput = getBicInput();
                    if (!bicInput) return;
                    var ajaxUrl = (window.ForgeForms && window.ForgeForms.ajaxUrl) || '';
                    if (!ajaxUrl) return;
                    bicInput.value    = '';
                    bicInput.disabled = true;
                    var dots = 0;
                    var dotTimer = setInterval(function () {
                        dots = (dots + 1) % 4;
                        bicInput.placeholder = 'Wird gesucht' + '.'.repeat(dots);
                    }, 400);
                    var body = new FormData();
                    body.append('action', 'forge_iban_bic');
                    body.append('iban', iban);
                    fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            clearInterval(dotTimer);
                            bicInput.disabled    = false;
                            bicInput.placeholder = 'XXXXXXXXXXX';
                            var d = res.data || {};
                            if (!d.valid) {
                                input._forgeIbanValid   = false;
                                input._forgeIbanInvalid = true;
                                showError('Ungültige IBAN (Prüfziffer fehlerhaft).');
                            } else {
                                input._forgeIbanValid   = true;
                                input._forgeIbanInvalid = false;
                                showError('');
                                if (d.bic && !bicManuallyEntered) {
                                    bicInput.value = d.bic;
                                } else if (!d.bankCodeFound) {
                                    showIbanNotice('Konnte nicht validiert werden.');
                                }
                            }
                        })
                        .catch(function () {
                            clearInterval(dotTimer);
                            bicInput.disabled    = false;
                            bicInput.placeholder = 'XXXXXXXXXXX';
                        });
                }
                var bicEl = getBicInput();
                if (bicEl) {
                    bicEl.addEventListener('input', function () { bicManuallyEntered = !!this.value; });
                }
                function getRaw() { return input.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase(); }
                input.addEventListener('input', function () {
                    input._forgeIbanValid   = false;
                    input._forgeIbanInvalid = false;
                    var raw = getRaw();
                    var cc  = raw.substring(0, 2);
                    if (cc.length === 2 && IBAN_LEN[cc]) {
                        if (countryAllowed(cc)) { lastValidCc = cc; showError(''); showIbanNotice(''); }
                        else { showError('Dieses Land ist nicht zugelassen.'); }
                    } else { showError(''); showIbanNotice(''); }
                    this.placeholder = ibanTemplate(lastValidCc);
                    var maxLen = IBAN_LEN[cc] || 34;
                    raw = raw.substring(0, maxLen);
                    var out = '', pos = 0;
                    while (pos < raw.length) {
                        if (pos > 0) out += ' ';
                        out += raw.substring(pos, pos + 4);
                        pos += 4;
                    }
                    this.value = out;
                    if (cc.length === 2 && IBAN_LEN[cc] && raw.length === IBAN_LEN[cc] && countryAllowed(cc)) {
                        lookupBic(raw);
                    }
                });
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Backspace') {
                        var pos = this.selectionStart;
                        if (pos > 0 && this.value[pos - 1] === ' ') {
                            this.value = this.value.slice(0, pos - 1) + this.value.slice(pos);
                            this.setSelectionRange(pos - 1, pos - 1);
                            e.preventDefault();
                        }
                    }
                });
            });
            root.querySelectorAll('.forge-sepa-bic').forEach(function (input) {
                if (input._forgeBicInited) return;
                input._forgeBicInited = true;
                input.addEventListener('input', function () {
                    this.value = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 11);
                });
            });
        }
        JS;
    }

    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $val = is_array($value) ? $value : [];

        $mandate_title = esc_html($config['mandate_title'] ?? 'SEPA-Lastschriftmandat');
        $mandate_text  = wp_kses_post($config['mandate_text'] ?? $this->defaultMandateText());
        $mandate_note  = wp_kses_post($config['mandate_note'] ?? $this->defaultMandateNote());
        $iban_label    = esc_html($config['iban_label']    ?? 'IBAN:');
        $bic_label     = esc_html($config['bic_label']     ?? 'BIC:');
        $holder_label  = esc_html($config['holder_label']  ?? 'Kontoinhaber:');
        $creditor_id   = esc_html($config['creditor_id']   ?? '');
        $mandate_ref   = esc_html($config['mandate_ref']   ?? 'Ihre Mitgliedsnummer');
        $sig_label     = esc_html($config['sig_label']     ?? 'Unterschrift');
        $req_attr      = !empty($config['required']) ? ' data-required="true"' : '';

        $placeholder_cc    = esc_attr($config['placeholder_country'] ?? 'DE');
        $country_filter    = $config['country_filter_mode'] ?? 'off';
        $country_list      = is_array($config['country_filter_list'] ?? null)
            ? $config['country_filter_list'] : [];
        $filter_attr       = $country_filter !== 'off' && !empty($country_list)
            ? ' data-country-filter="' . esc_attr($country_filter)
                . '" data-country-list="' . esc_attr(implode(',', $country_list)) . '"'
            : '';

        $iban_val   = esc_attr($val['iban']   ?? '');
        $bic_val    = esc_attr($val['bic']    ?? '');
        $holder_val = esc_attr($val['holder'] ?? '');

        $rules = array_column($this->getClientValidation(), 'rule');
        $html  = '<div class="forge-field forge-field--sepa" data-field-id="' . esc_attr($field_id) . '"'
            . $req_attr . ' data-validate="' . esc_attr(wp_json_encode($rules)) . '">';
        $html .= '<div class="forge-sepa-mandate">';
        $html .= '<h3 class="forge-sepa-title">' . $mandate_title . '</h3>';
        $html .= '<div class="forge-sepa-text">' . $mandate_text . '</div>';

        if ($mandate_note !== '') {
            $html .= '<p class="forge-sepa-note">' . $mandate_note . '</p>';
        }

        /* ---- IBAN ---- */
        $html .= '<div class="forge-field-inner">';
        $html .= '<label class="forge-label" for="' . esc_attr($field_id) . '-iban">' . $iban_label;
        if (!empty($config['required'])) {
            $html .= ' <span class="forge-required" aria-hidden="true">*</span>';
        }
        $html .= '</label>';
        $html .= '<input type="text" id="' . esc_attr($field_id) . '-iban"'
            . ' name="' . esc_attr($field_id) . '[iban]"'
            . ' class="forge-input forge-sepa-iban"'
            . ' maxlength="42" autocomplete="off"'
            . ' inputmode="text" spellcheck="false"'
            . ' data-placeholder-country="' . $placeholder_cc . '"'
            . $filter_attr
            . ' value="' . $iban_val . '">';
        $html .= '<div class="forge-field-hint"></div>';
        $html .= '<div class="forge-field-error" id="' . esc_attr($field_id) . '-iban-error" role="alert"></div>';
        $html .= '</div>';

        /* ---- BIC ---- */
        $html .= '<div class="forge-field-inner">';
        $html .= '<label class="forge-label" for="' . esc_attr($field_id) . '-bic">' . $bic_label;
        if (!empty($config['required'])) {
            $html .= ' <span class="forge-required" aria-hidden="true">*</span>';
        }
        $html .= '</label>';
        $html .= '<input type="text" id="' . esc_attr($field_id) . '-bic"'
            . ' name="' . esc_attr($field_id) . '[bic]"'
            . ' class="forge-input forge-sepa-bic"'
            . ' placeholder="XXXXXXXXXXX" maxlength="11" autocomplete="off"'
            . ' value="' . $bic_val . '">';
        $html .= '<div class="forge-field-error" id="' . esc_attr($field_id) . '-bic-error" role="alert"></div>';
        $html .= '</div>';

        /* ---- Kontoinhaber ---- */
        $html .= '<div class="forge-field-inner">';
        $html .= '<label class="forge-label" for="' . esc_attr($field_id) . '-holder">' . $holder_label;
        if (!empty($config['required'])) {
            $html .= ' <span class="forge-required" aria-hidden="true">*</span>';
        }
        $html .= '</label>';
        $html .= '<input type="text" id="' . esc_attr($field_id) . '-holder"'
            . ' name="' . esc_attr($field_id) . '[holder]"'
            . ' class="forge-input forge-sepa-holder" autocomplete="off"'
            . ' value="' . $holder_val . '">';
        $html .= '<div class="forge-field-error" id="' . esc_attr($field_id) . '-holder-error" role="alert"></div>';
        $html .= '</div>';

        /* ---- Static creditor info ---- */
        if ($creditor_id !== '' || $mandate_ref !== '') {
            $html .= '<div class="forge-sepa-creditor">';
            if ($creditor_id !== '') {
                $html .= '<p>Gläubiger-Identifikationsnummer: ' . $creditor_id . '</p>';
            }
            if ($mandate_ref !== '') {
                $html .= '<p>Mandatsreferenz: ' . $mandate_ref . '</p>';
            }
            $html .= '</div>';
        }

        /* ---- Signature ---- */
        $canvas_id     = esc_attr($field_id) . '-sig-canvas';
        $canvas_height = (int)($config['canvas_height'] ?? 200);
        $stroke_width  = (float)($config['stroke_width'] ?? 2);
        $html .= '<div class="forge-sepa-signatures">';
        $html .= '<div class="forge-sepa-sig-block">';
        $html .= '<div class="forge-sepa-sig-label">' . $sig_label;
        if (!empty($config['required'])) {
            $html .= ' <span class="forge-required" aria-hidden="true">*</span>';
        }
        $html .= '</div>';
        $html .= '<div class="forge-signature-wrap"'
            . ' data-field-id="' . esc_attr($field_id) . '-sig"'
            . (!empty($config['required']) ? ' data-required="true"' : '')
            . ' data-stroke="' . esc_attr((string)$stroke_width) . '">';
        $html .= '<canvas id="' . $canvas_id . '" class="forge-signature-canvas"'
            . ' width="400" height="' . $canvas_height . '"'
            . ' style="height:' . $canvas_height . 'px"'
            . ' tabindex="0" aria-label="' . $sig_label . '"></canvas>';
        $html .= '<input type="hidden"'
            . ' name="' . esc_attr($field_id) . '-sig"'
            . ' id="' . esc_attr($field_id) . '-sig-data"'
            . ' class="forge-sepa-sig-data">';
        $html .= '<div class="forge-signature-toolbar">';
        $reset_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"'
            . ' aria-hidden="true" focusable="false">'
            . '<path d="M125.7 160H176a16 16 0 0 1 0 32H48a16 16 0 0 1-16-16V48a16 16 0 0 1 32 0v68.7'
            . 'C115.3 45.1 191.6 0 278 0c141.4 0 256 114.6 256 256S419.4 512 278 512'
            . 'C167.7 512 74.4 443.5 38 346a16 16 0 1 1 30-11c31.4 83.7 111.5 141 210 141'
            . ' 123.7 0 224-100.3 224-224S401.7 32 278 32c-78.1 0-145.8 39.4-185.3 99.3z"/>'
            . '</svg>';
        $html .= '<button type="button" class="forge-signature-clear"'
            . ' data-canvas="' . $canvas_id . '" title="Löschen" aria-label="Unterschrift löschen">'
            . $reset_icon . '</button>';
        $html .= '<span class="forge-signature-hint">Hier unterschreiben</span>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="forge-field-error forge-sepa-sig-error"'
            . ' id="' . esc_attr($field_id) . '-sig-error" role="alert"></div>';
        $html .= '</div>'; /* sig-block */
        $html .= '</div>'; /* signatures */

        $html .= '<div class="forge-field-error" id="'
            . esc_attr($field_id) . '-error" role="alert" aria-live="polite"></div>';
        $html .= '</div>'; /* forge-sepa-mandate */
        $html .= '</div>'; /* forge-field */

        return $html;
    }

    public function validate(mixed $value, array $config): bool|string
    {
        if (empty($config['required'])) {
            return true;
        }

        if (!is_array($value)) {
            return 'SEPA-Daten fehlen.';
        }

        $iban   = trim((string)($value['iban']   ?? ''));
        $bic    = trim((string)($value['bic']    ?? ''));
        $holder = trim((string)($value['holder'] ?? ''));

        if ($iban === '') {
            return 'IBAN ist ein Pflichtfeld.';
        }
        $iban_clean = strtoupper(preg_replace('/\s/', '', $iban));
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban_clean)) {
            return 'Bitte geben Sie eine gültige IBAN ein.';
        }
        if ($bic === '') {
            return 'BIC ist ein Pflichtfeld.';
        }
        if (!preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/i', $bic)) {
            return 'Bitte geben Sie einen gültigen BIC ein.';
        }
        if ($holder === '') {
            return 'Kontoinhaber ist ein Pflichtfeld.';
        }

        return true;
    }

    public function map(mixed $value, array $config): string
    {
        if (!is_array($value)) {
            return '[Kein Eintrag]';
        }
        $iban   = strtoupper(preg_replace('/\s/', '', (string)($value['iban']   ?? '')));
        $bic    = strtoupper((string)($value['bic']    ?? ''));
        $holder = (string)($value['holder'] ?? '');

        $parts = array_filter([
            $iban   !== '' ? 'IBAN: ' . wordwrap($iban, 4, ' ', true) : '',
            $bic    !== '' ? 'BIC: ' . $bic : '',
            $holder !== '' ? 'Kontoinhaber: ' . $holder : '',
        ]);
        return $parts ? trim(implode(' | ', $parts)) : '[Kein Eintrag]';
    }

    public function getDefaultConfig(): array
    {
        return [
            'label'               => 'SEPA-Lastschriftmandat',
            'required'            => true,
            'description'         => '',
            'mandate_title'       => 'SEPA-Lastschriftmandat',
            'mandate_text'        => $this->defaultMandateText(),
            'mandate_note'        => $this->defaultMandateNote(),
            'iban_label'          => 'IBAN:',
            'bic_label'           => 'BIC:',
            'holder_label'        => 'Kontoinhaber:',
            'creditor_id'         => '',
            'mandate_ref'         => '',
            'sig_label'           => 'Unterschrift',
            'canvas_height'       => 200,
            'stroke_width'        => 2,
            'placeholder_country' => 'DE',
            'country_filter_mode' => 'off',
            'country_filter_list' => [],
        ];
    }

    public function getGeneralSchema(): array
    {
        $country_options = $this->ibanCountryOptions();
        return [
            ['key' => 'mandate_title', 'type' => 'text',     'label' => 'Titel des Mandats'],
            ['key' => 'mandate_text',  'type' => 'textarea', 'label' => 'Mandatstext (HTML erlaubt)'],
            ['key' => 'mandate_note',  'type' => 'textarea', 'label' => 'Hinweistext (Kleingedrucktes, HTML erlaubt)'],
            ['key' => 'iban_label',    'type' => 'text',     'label' => 'IBAN-Label'],
            ['key'     => 'placeholder_country',
             'type'    => 'select',
             'label'   => 'Platzhalter-Land (IBAN-Format)',
             'default' => 'DE',
             'options' => $country_options],
            ['key' => 'bic_label',    'type' => 'text', 'label' => 'BIC-Label'],
            ['key' => 'holder_label', 'type' => 'text', 'label' => 'Kontoinhaber-Label'],
            ['key' => 'creditor_id',  'type' => 'text', 'label' => 'Gläubiger-ID'],
            ['key' => 'mandate_ref',  'type' => 'text', 'label' => 'Mandatsreferenz'],
            ['key' => 'sig_label',    'type' => 'text', 'label' => 'Unterschrift-Label'],
        ];
    }

    public function getAdvancedSchema(): array
    {
        return [
            ['key' => 'canvas_height', 'type' => 'number', 'label' => 'Unterschrift Höhe (px)', 'default' => 200],
            ['key' => 'stroke_width',  'type' => 'number', 'label' => 'Strichstärke',           'default' => 2],
        ];
    }

    private function ibanCountryOptions(): array
    {
        $countries = [
            'AD' => 'Andorra',          'AE' => 'Vereinigte Arab. Emirate', 'AL' => 'Albanien',
            'AT' => 'Österreich',       'AZ' => 'Aserbaidschan',            'BA' => 'Bosnien-Herzegowina',
            'BE' => 'Belgien',          'BG' => 'Bulgarien',                'BH' => 'Bahrain',
            'BR' => 'Brasilien',        'CH' => 'Schweiz',                  'CR' => 'Costa Rica',
            'CY' => 'Zypern',           'CZ' => 'Tschechien',               'DE' => 'Deutschland',
            'DJ' => 'Dschibuti',        'DK' => 'Dänemark',                 'DO' => 'Dominikanische Republik',
            'EE' => 'Estland',          'EG' => 'Ägypten',                  'ES' => 'Spanien',
            'FI' => 'Finnland',         'FR' => 'Frankreich',               'GB' => 'Vereinigtes Königreich',
            'GE' => 'Georgien',         'GI' => 'Gibraltar',                'GL' => 'Grönland',
            'GR' => 'Griechenland',     'GT' => 'Guatemala',                'HR' => 'Kroatien',
            'HU' => 'Ungarn',           'IE' => 'Irland',                   'IL' => 'Israel',
            'IQ' => 'Irak',             'IS' => 'Island',                   'IT' => 'Italien',
            'JO' => 'Jordanien',        'KW' => 'Kuwait',                   'KZ' => 'Kasachstan',
            'LB' => 'Libanon',          'LC' => 'St. Lucia',                'LI' => 'Liechtenstein',
            'LT' => 'Litauen',          'LU' => 'Luxemburg',                'LV' => 'Lettland',
            'LY' => 'Libyen',           'MA' => 'Marokko',                  'MC' => 'Monaco',
            'MD' => 'Moldau',           'ME' => 'Montenegro',               'MK' => 'Nordmazedonien',
            'MR' => 'Mauretanien',      'MT' => 'Malta',                    'MU' => 'Mauritius',
            'NI' => 'Nicaragua',        'NL' => 'Niederlande',              'NO' => 'Norwegen',
            'PK' => 'Pakistan',         'PL' => 'Polen',                    'PT' => 'Portugal',
            'QA' => 'Katar',            'RO' => 'Rumänien',                 'RS' => 'Serbien',
            'SA' => 'Saudi-Arabien',    'SE' => 'Schweden',                 'SI' => 'Slowenien',
            'SK' => 'Slowakei',         'SM' => 'San Marino',               'SV' => 'El Salvador',
            'TN' => 'Tunesien',         'TR' => 'Türkei',                   'UA' => 'Ukraine',
            'VA' => 'Vatikanstadt',     'VG' => 'Brit. Jungferninseln',     'XK' => 'Kosovo',
        ];

        $opts = [];
        foreach ($countries as $code => $name) {
            $opts[] = ['value' => $code, 'label' => $code . ' – ' . $name];
        }
        return $opts;
    }

    private function defaultMandateText(): string
    {
        return '<p>Hiermit ermächtige ich den Zahlungsempfänger, Zahlungen von'
            . ' meinem Konto mittels Lastschrift einzuziehen. Zugleich weise ich'
            . ' mein Kreditinstitut an, die vom Zahlungsempfänger auf mein Konto'
            . ' gezogenen Lastschriften einzulösen.</p>'
            . '<p>Wenn mein angegebenes Konto die erforderliche Deckung nicht aufweist,'
            . ' besteht seitens des kontoführenden Instituts keine Verpflichtung zur'
            . ' Einlösung. Teileinlösungen werden im Lastschriftverfahren nicht vorgenommen.'
            . ' Die Kosten der Rücklastschrift werden von mir getragen.</p>';
    }

    private function defaultMandateNote(): string
    {
        return '<small>Hinweis: Ich kann innerhalb von acht Wochen, beginnend mit'
            . ' dem Belastungsdatum, die Erstattung des belasteten Betrages verlangen.'
            . ' Es gelten dabei die mit meinem Kreditinstitut vereinbarten Bedingungen.</small>';
    }
}
