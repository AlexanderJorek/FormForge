<?php

/**
 * Admin page that runs field-type unit tests in the browser.
 * Only registered when WP_DEBUG is true.
 *
 * PHP Version 8.1
 *
 * @category  FormForge
 * @package   FormForge
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 * @version   1.0.0
 * @link      https://github.com/AlexanderJorek/FormForge
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 */

namespace ForgeForms\Admin;

defined('ABSPATH') || exit;

/**
 * Runs PHP and JS field tests on a WP_DEBUG-only admin submenu page.
 */
class FieldTestPage
{
    /**
     * Registers the admin menu entry when WP_DEBUG is active.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu'], 20);
        add_filter('admin_body_class', [self::class, 'bodyClass']);
    }

    /**
     * Appends forge-list-page body class on the test page.
     *
     * @param string $classes Current body classes.
     *
     * @return string
     */
    public static function bodyClass(string $classes): string
    {
        if (isset($_GET['page']) && $_GET['page'] === 'forge-field-tests') {
            $classes .= ' forge-list-page';
        }
        return $classes;
    }

    /**
     * Adds the submenu page under the FormForge menu.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        add_submenu_page(
            'forge-forms',
            'Field Tests',
            'Field Tests',
            'manage_options',
            'forge-field-tests',
            [self::class, 'render']
        );
    }

    // ── test harness ────────────────────────────────────────────────────────

    private static int $pass = 0;
    private static int $fail = 0;
    /** @var string[] */
    private static array $log = [];
    /** @var string[] plain-text lines for the copy box */
    private static array $failLines = [];
    private static string $currentSection = '';
    /** Side-channel: helpers write input/output here; run() reads after the lambda. */
    private static string $lastIn  = '';
    private static string $lastOut = '';

    private static function run(string $name, callable $fn): void
    {
        self::$lastIn  = '';
        self::$lastOut = '';
        try {
            $result = $fn();
            $in  = esc_html(self::$lastIn);
            $out = esc_html(self::$lastOut);
            if ($result === true) {
                self::$pass++;
                self::$log[] = '<tr class="ff-pass"><td>✓</td><td>' . esc_html($name) . '</td>'
                    . '<td class="ff-io">' . $in . '</td>'
                    . '<td class="ff-io ff-out">' . $out . '</td>'
                    . '<td></td></tr>';
            } else {
                self::$fail++;
                $msg = is_string($result) ? $result : var_export($result, true);
                self::$log[] = '<tr class="ff-fail"><td>✗</td><td>' . esc_html($name) . '</td>'
                    . '<td class="ff-io">' . $in . '</td>'
                    . '<td class="ff-io ff-out">' . $out . '</td>'
                    . '<td>' . esc_html($msg) . '</td></tr>';
                self::$failLines[] = '[' . self::$currentSection . '] ' . $name
                    . "\tinput: " . self::$lastIn
                    . "\t" . $msg;
            }
        } catch (\Throwable $e) {
            self::$fail++;
            $in  = esc_html(self::$lastIn);
            $out = esc_html(self::$lastOut);
            self::$log[] = '<tr class="ff-fail"><td>✗</td><td>' . esc_html($name) . '</td>'
                . '<td class="ff-io">' . $in . '</td>'
                . '<td class="ff-io ff-out">' . $out . '</td>'
                . '<td>Exception: ' . esc_html($e->getMessage()) . '</td></tr>';
            self::$failLines[] = '[' . self::$currentSection . '] ' . $name
                . "\tinput: " . self::$lastIn
                . "\tException: " . $e->getMessage();
        }
    }

    private static function section(string $label): void
    {
        self::$currentSection = $label;
        self::$log[] = '<tr class="ff-section"><td colspan="5"><strong>' . esc_html($label) . '</strong></td></tr>';
    }

    // ── assertion helpers ────────────────────────────────────────────────────

    private static function contains(string $html, string $needle, string $what = ''): bool|string
    {
        $found = str_contains($html, $needle);
        self::$lastIn  = $needle;
        self::$lastOut = $found ? '✓ found' : '✗ not found';
        return $found ? true : (($what ?: $needle) . ' not found in output');
    }

    private static function expectError(mixed $value, array $config, \ForgeForms\Fields\BaseField $h): bool|string
    {
        $r = $h->validate($value, $config);
        self::$lastIn  = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
        self::$lastOut = is_string($r) ? $r : var_export($r, true);
        return (is_string($r) && $r !== '') ? true : 'expected error string, got ' . var_export($r, true);
    }

    private static function expectOk(mixed $value, array $config, \ForgeForms\Fields\BaseField $h): bool|string
    {
        $r = $h->validate($value, $config);
        self::$lastIn  = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
        self::$lastOut = $r === true ? '✓ valid' : var_export($r, true);
        return $r === true ? true : 'expected true, got ' . var_export($r, true);
    }

    private static function expectMap(mixed $value, array $config, \ForgeForms\Fields\BaseField $h, string $expected): bool|string
    {
        $r = $h->map($value, $config);
        self::$lastIn  = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : var_export($value, true);
        self::$lastOut = $r;
        return $r === $expected ? true : 'expected ' . var_export($expected, true) . ', got ' . var_export($r, true);
    }

    private static function expectMapContains(mixed $value, array $config, \ForgeForms\Fields\BaseField $h, string ...$needles): bool|string
    {
        $r = $h->map($value, $config);
        self::$lastIn  = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : var_export($value, true);
        self::$lastOut = $r;
        foreach ($needles as $needle) {
            if (!str_contains($r, $needle)) {
                return var_export($needle, true) . ' not found in: ' . $r;
            }
        }
        return true;
    }

    private static function schemaIntegrity(\ForgeForms\Fields\BaseField $h): bool|string
    {
        // These schema types are composite UI widgets with no single config key of their own
        $noKeyTypes = ['subfields', 'rating_preview', 'notice'];
        $schemaKeys = [];
        foreach (array_merge($h->getGeneralSchema(), $h->getAdvancedSchema()) as $entry) {
            if (isset($entry['type']) && in_array($entry['type'], $noKeyTypes, true)) {
                continue;
            }
            if (isset($entry['key'])) {
                $schemaKeys[] = $entry['key'];
            }
            foreach (['count_key','half_key','format_key','prefill_key'] as $k) {
                if (isset($entry[$k])) {
                    $schemaKeys[] = $entry[$k];
                }
            }
        }
        $missing = array_diff(array_unique($schemaKeys), array_keys($h->getDefaultConfig()));
        self::$lastIn  = count($schemaKeys) . ' schema keys';
        self::$lastOut = empty($missing) ? '✓ all in getDefaultConfig' : 'missing: ' . implode(', ', $missing);
        return empty($missing) ? true : 'schema keys missing from getDefaultConfig(): ' . implode(', ', $missing);
    }

    private static function renderBasic(\ForgeForms\Fields\BaseField $h, array $cfg, string $fid = 'field-test-1'): bool|string
    {
        $html = $h->render($cfg, $fid);
        self::$lastIn  = 'field_id=' . $fid;
        self::$lastOut = is_string($html)
            ? '[' . strlen($html) . 'B] ' . substr(strip_tags($html), 0, 55)
            : '(not string)';
        if (!is_string($html) || $html === '') {
            return 'render() returned empty string';
        }
        if (!str_contains($html, $fid)) {
            return 'render() output does not contain field_id';
        }
        if (!str_contains($html, 'forge-field')) {
            return 'render() output missing forge-field class';
        }
        return true;
    }

    // ── test suites — every assertion reflects the actual field implementation ──

    private static function testText(): void
    {
        self::section('text');
        $h   = new \ForgeForms\Fields\TextField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'text','label'=>'Name']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render <input', fn() => self::contains($h->render($cfg, 'f1'), '<input'));
        self::run('render required attr', fn() => self::contains($h->render(array_merge($cfg, ['required'=>true]), 'f1'), 'required'));
        self::run('render hide_label', function () use ($h, $cfg) {
            return !str_contains($h->render(array_merge($cfg, ['hide_label'=>true]), 'f1'), '<label') ? true : 'label still present';
        });
        self::run('render placeholder', fn() => self::contains($h->render(array_merge($cfg, ['placeholder'=>'Enter']), 'f1'), 'Enter'));
        self::run('render description', fn() => self::contains($h->render(array_merge($cfg, ['description'=>'Help']), 'f1'), 'Help'));
        self::run('render char limit → maxlength', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['limit_type'=>'chars','limit_max'=>'10']), 'f1'), 'maxlength');
        });
        self::run('render word limit → data-word-limit', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['limit_type'=>'words','limit_max'=>'5']), 'f1'), 'data-word-limit');
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate with value', fn() => self::expectOk('Hello', $cfg, $h));
        self::run('validate word limit exceeded', function () use ($h, $cfg) {
            return self::expectError('one two three four five six', array_merge($cfg, ['limit_type'=>'words','limit_max'=>'3']), $h);
        });
        self::run('validate word limit within', function () use ($h, $cfg) {
            return self::expectOk('one two three', array_merge($cfg, ['limit_type'=>'words','limit_max'=>'3']), $h);
        });
        self::run('validate word limit exact at boundary (count===max)', function () use ($h, $cfg) {
            return self::expectOk('alpha beta gamma delta', array_merge($cfg, ['limit_type'=>'words','limit_max'=>'4']), $h);
        });
        self::run('validate char limit exceeded (server-side)', function () use ($h, $cfg) {
            return self::expectError('this value is far too long', array_merge($cfg, ['limit_type'=>'chars','limit_max'=>'5']), $h);
        });
        self::run('validate char limit within', function () use ($h, $cfg) {
            return self::expectOk('short', array_merge($cfg, ['limit_type'=>'chars','limit_max'=>'10']), $h);
        });
        self::run('validate char limit exact at boundary (len===max)', function () use ($h, $cfg) {
            return self::expectOk('abcde', array_merge($cfg, ['limit_type'=>'chars','limit_max'=>'5']), $h);
        });
        self::run('map non-empty', fn() => self::expectMap('Hello', $cfg, $h, 'Hello'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, 'No entry'));
        self::run('sanitize strips <script>', function () use ($h) {
            return !str_contains($h->sanitizeConfigValue('label', '<script>x</script>'), '<script') ? true : 'script not stripped';
        });
    }

    private static function testTextarea(): void
    {
        self::section('textarea');
        $h   = new \ForgeForms\Fields\TextareaField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'textarea','label'=>'Nachricht']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render <textarea', fn() => self::contains($h->render($cfg, 'f1'), '<textarea'));
        self::run('render default rows=5 (no override)', fn() => self::contains($h->render($cfg, 'f1'), 'rows="5"'));
        self::run('render rows attr', fn() => self::contains($h->render(array_merge($cfg, ['rows'=>6]), 'f1'), 'rows="6"'));
        self::run('render char limit → maxlength', fn() => self::contains($h->render(array_merge($cfg, ['limit_type'=>'chars','limit_max'=>'200']), 'f1'), 'maxlength'));
        self::run('render word limit → data-word-limit', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['limit_type'=>'words','limit_max'=>'10']), 'f1'), 'data-word-limit');
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate multiline', fn() => self::expectOk("line1\nline2", $cfg, $h));
        self::run('validate word limit exceeded', function () use ($h, $cfg) {
            return self::expectError('a b c d e', array_merge($cfg, ['limit_type'=>'words','limit_max'=>'3']), $h);
        });
        self::run('validate word limit within', function () use ($h, $cfg) {
            return self::expectOk('a b c', array_merge($cfg, ['limit_type'=>'words','limit_max'=>'3']), $h);
        });
        self::run('map non-empty', fn() => self::expectMap('Hello', $cfg, $h, 'Hello'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, 'No entry'));
        self::run('sanitize strips <script> (inherited from BaseField)', function () use ($h) {
            return !str_contains($h->sanitizeConfigValue('description', '<p>ok</p><script>x</script>'), '<script') ? true : 'script not stripped';
        });
    }

    private static function testEmail(): void
    {
        self::section('email');
        $h   = new \ForgeForms\Fields\EmailField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'email','label'=>'E-Mail']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render type="email"', fn() => self::contains($h->render($cfg, 'f1'), 'type="email"'));
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate valid email', fn() => self::expectOk('user@example.com', $cfg, $h));
        self::run('validate invalid format', function () use ($h, $cfg) {
            return self::expectError('notanemail', array_merge($cfg, ['validate_format'=>true]), $h);
        });
        self::run('validate blocked domain', function () use ($h, $cfg) {
            // patterns match the full address; use wildcard *@domain
            $c = array_merge($cfg, ['filter_mode'=>'block','filter_patterns'=>'*@spam.com']);
            return self::expectError('user@spam.com', $c, $h);
        });
        self::run('validate allowed domain pass', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['filter_mode'=>'allow','filter_patterns'=>'*@example.com']);
            return self::expectOk('user@example.com', $c, $h);
        });
        self::run('validate allowed domain fail', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['filter_mode'=>'allow','filter_patterns'=>'*@example.com']);
            return self::expectError('user@other.com', $c, $h);
        });
        self::run('validate_format=false allows malformed string', function () use ($h, $cfg) {
            return self::expectOk('notanemail', array_merge($cfg, ['validate_format'=>false]), $h);
        });
        self::run('validate multi-pattern allow list (; separated)', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['filter_mode'=>'allow','filter_patterns'=>'*@example.com;*@test.com']);
            return self::expectOk('user@test.com', $c, $h);
        });
    }

    private static function testName(): void
    {
        self::section('name');
        $h   = new \ForgeForms\Fields\NameField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'name','label'=>'Name','expanded'=>false]);
        $exp = array_merge($cfg, [
            'expanded'=>true,'fname_enabled'=>true,'fname_label'=>'Vorname','fname_required'=>true,
            'lname_enabled'=>true,'lname_label'=>'Nachname','lname_required'=>true,
            'mname_enabled'=>false,'prefix_enabled'=>false,
        ]);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render simple <input>', fn() => self::renderBasic($h, $cfg));
        self::run('render expanded subfields', fn() => self::contains($h->render($exp, 'f1'), 'Vorname'));
        self::run('render expanded prefix select', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['expanded'=>true,'prefix_enabled'=>true,'prefix_label'=>'Anrede','fname_enabled'=>false,'lname_enabled'=>false,'mname_enabled'=>false]);
            return self::contains($h->render($c, 'f1'), '<select');
        });
        self::run('validate required empty simple', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty simple', fn() => self::expectOk('', $cfg, $h));
        self::run('validate filled simple', fn() => self::expectOk('Max Mustermann', $cfg, $h));
        self::run('validate required empty expanded', fn() => self::expectError(['fname'=>'','lname'=>''], $exp, $h));
        self::run('validate required filled expanded', fn() => self::expectOk(['fname'=>'Hans','lname'=>'Müller'], $exp, $h));
        self::run('validate partial expanded → error', function () use ($h, $exp) {
            return self::expectError(['fname'=>'Hans','lname'=>''], $exp, $h);
        });
        self::run('map simple string', fn() => self::expectMap('Max Mustermann', $cfg, $h, 'Max Mustermann'));
        self::run('map simple empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, 'No entry'));
        self::run('map expanded has Vorname', fn() => self::expectMapContains(['fname'=>'Hans','lname'=>'Müller'], $exp, $h, 'Hans'));
        self::run('map expanded has Nachname', fn() => self::expectMapContains(['fname'=>'Hans','lname'=>'Müller'], $exp, $h, 'Müller'));
        self::run('map expanded empty → Kein Eintrag', fn() => self::expectMapContains(['fname'=>'','lname'=>''], $exp, $h, 'No entry'));
        self::run('render middle name subfield', function () use ($h, $exp) {
            $c = array_merge($exp, ['mname_enabled'=>true,'mname_label'=>'Zweiter Vorname']);
            return self::contains($h->render($c, 'f1'), 'Zweiter Vorname');
        });
        self::run('validate middle name filled passes', function () use ($h, $exp) {
            $c = array_merge($exp, ['mname_enabled'=>true,'mname_required'=>true]);
            return self::expectOk(['fname'=>'Hans','lname'=>'Müller','mname'=>'Peter'], $c, $h);
        });
        self::run('map includes middle name', function () use ($h, $exp) {
            $c = array_merge($exp, ['mname_enabled'=>true]);
            return self::expectMapContains(['fname'=>'Hans','lname'=>'Müller','mname'=>'Peter'], $c, $h, 'Peter');
        });
        self::run('validate prefix_required=true is skipped (select subfield always has a value)', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['expanded'=>true,'prefix_enabled'=>true,'prefix_required'=>true,'fname_enabled'=>false,'lname_enabled'=>false,'mname_enabled'=>false]);
            return self::expectOk([], $c, $h);
        });
    }

    private static function testPhone(): void
    {
        self::section('phone');
        $h   = new \ForgeForms\Fields\PhoneField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'phone','label'=>'Telefon']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render type="tel"', fn() => self::contains($h->render($cfg, 'f1'), 'type="tel"'));
        self::run('render phone_mode data-attr', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['phone_mode'=>'any']), 'f1'), 'data-phone-mode');
        });
        self::run('render countries data attrs', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'allow','phone_country_list'=>['+49','+43']]);
            return self::contains($h->render($c, 'f1'), 'data-phone-country');
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate no-mode any value passes', fn() => self::expectOk('+4915123456789', $cfg, $h));
        self::run('validate mode=any valid', fn() => self::expectOk('+4915123456789', array_merge($cfg, ['phone_mode'=>'any']), $h));
        self::run('validate mode=any invalid', function () use ($h, $cfg) {
            return self::expectError('123', array_merge($cfg, ['phone_mode'=>'any']), $h);
        });
        self::run('validate mode=countries missing +', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'allow','phone_country_list'=>['+49']]);
            return self::expectError('04915123456789', $c, $h);
        });
        self::run('validate mode=countries allowed pass', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'allow','phone_country_list'=>['+49']]);
            return self::expectOk('+4915123456789', $c, $h);
        });
        self::run('validate mode=countries allowed fail', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'allow','phone_country_list'=>['+44']]);
            return self::expectError('+4915123456789', $c, $h);
        });
        self::run('validate mode=countries disallow match', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'disallow','phone_country_list'=>['+49']]);
            return self::expectError('+4915123456789', $c, $h);
        });
        self::run('validate mode=countries disallow non-matching passes', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'disallow','phone_country_list'=>['+49']]);
            return self::expectOk('+33123456789', $c, $h);
        });
        self::run('validate overlapping country prefix codes both match', function () use ($h, $cfg) {
            // '+3' is a prefix of '+358' — matching should still succeed for a +358 number
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'allow','phone_country_list'=>['+3','+358']]);
            return self::expectOk('+358123456789', $c, $h);
        });
        self::run('map non-empty', fn() => self::expectMap('+4915123456789', $cfg, $h, '+4915123456789'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, 'No entry'));
    }

    private static function testNumber(): void
    {
        self::section('number');
        $h   = new \ForgeForms\Fields\NumberField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'number','label'=>'Anzahl']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render type="number"', fn() => self::contains($h->render($cfg, 'f1'), 'type="number"'));
        self::run('render min/max/step', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['min'=>'1','max'=>'100','step'=>'5']), 'f1');
            return str_contains($html, 'min') ? true : 'min attr missing';
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate valid number', fn() => self::expectOk('42', $cfg, $h));
        self::run('validate non-numeric', function () use ($h, $cfg) {
            return self::expectError('abc', array_merge($cfg, ['required'=>true]), $h);
        });
        self::run('validate below min', function () use ($h, $cfg) {
            return self::expectError('5', array_merge($cfg, ['min'=>'10']), $h);
        });
        self::run('validate above max', function () use ($h, $cfg) {
            return self::expectError('150', array_merge($cfg, ['max'=>'100']), $h);
        });
        self::run('validate exact at min passes', fn() => self::expectOk('10', array_merge($cfg, ['min'=>'10']), $h));
        self::run('validate exact at max passes', fn() => self::expectOk('100', array_merge($cfg, ['max'=>'100']), $h));
    }

    private static function testAddress(): void
    {
        self::section('address');
        $h   = new \ForgeForms\Fields\AddressField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'address','label'=>'Adresse','expanded'=>false]);
        $exp = array_merge($cfg, [
            'expanded'=>true,
            'street_enabled'=>true,'street_label'=>'Straße','street_required'=>true,
            'city_enabled'=>true,'city_label'=>'Stadt','city_required'=>true,
            'zip_enabled'=>true,'zip_label'=>'PLZ','zip_required'=>true,
            'street2_enabled'=>false,'state_enabled'=>false,'country_enabled'=>false,
        ]);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render simple <input>', fn() => self::renderBasic($h, $cfg));
        self::run('render expanded subfields', fn() => self::contains($h->render($exp, 'f1'), 'Straße'));
        self::run('validate required empty simple', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty simple', fn() => self::expectOk('', $cfg, $h));
        self::run('validate filled simple', fn() => self::expectOk('Hauptstr. 1, Berlin', $cfg, $h));
        self::run('validate required fields empty exp', fn() => self::expectError(['street'=>'','city'=>'','zip'=>''], $exp, $h));
        self::run('validate required fields filled exp', fn() => self::expectOk(['street'=>'Hauptstr. 1','city'=>'Berlin','zip'=>'10115'], $exp, $h));
        self::run('validate partial required exp err', function () use ($h, $exp) {
            return self::expectError(['street'=>'Hauptstr. 1','city'=>'','zip'=>'10115'], $exp, $h);
        });
        self::run('map non-array → Kein Eintrag', fn() => self::expectMapContains('not-array', $cfg, $h, 'No entry'));
        self::run('map array has Straße', fn() => self::expectMapContains(['street'=>'Hauptstr. 1','city'=>'Berlin','zip'=>'10115'], $exp, $h, 'Hauptstr'));
        self::run('map array has Stadt', fn() => self::expectMapContains(['street'=>'Hauptstr. 1','city'=>'Berlin','zip'=>'10115'], $exp, $h, 'Berlin'));
        $expFull = array_merge($exp, [
            'street2_enabled'=>true,'street2_label'=>'Adresszusatz',
            'state_enabled'=>true,'state_label'=>'Bundesland',
            'country_enabled'=>true,'country_label'=>'Land',
        ]);
        self::run('render street2/state/country subfields', function () use ($h, $expFull) {
            $html = $h->render($expFull, 'f1');
            self::$lastIn  = 'expanded with street2/state/country enabled';
            self::$lastOut = $html;
            foreach (['Adresszusatz', 'Bundesland', 'Land'] as $needle) {
                if (!str_contains($html, $needle)) {
                    return $needle . ' not found in output';
                }
            }
            return true;
        });
        self::run('map includes street2/state/country', function () use ($h, $expFull) {
            return self::expectMapContains(
                ['street'=>'Hauptstr. 1','street2'=>'3.OG','city'=>'Berlin','zip'=>'10115','state'=>'Bayern','country'=>'Deutschland'],
                $expFull,
                $h,
                '3.OG',
                'Bayern',
                'Deutschland'
            );
        });
        self::run('validate multiple required fields empty simultaneously', function () use ($h, $exp) {
            $r = $h->validate(['street'=>'','city'=>'','zip'=>'10115'], $exp);
            self::$lastIn  = 'street empty, city empty, zip filled';
            self::$lastOut = is_string($r) ? $r : var_export($r, true);
            if (!is_string($r) || $r === '') {
                return 'expected error string, got ' . var_export($r, true);
            }
            return (str_contains($r, $exp['street_label']) && str_contains($r, $exp['city_label']))
                ? true
                : 'expected both missing labels in message: ' . $r;
        });
    }

    private static function testDate(): void
    {
        self::section('date');
        $h   = new \ForgeForms\Fields\DateField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'date','label'=>'Datum']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render show_picker=true → cal btn', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['show_picker'=>true]), 'f1'), 'forge-date-cal-btn');
        });
        self::run('render prefill_today attr', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['prefill_today'=>true]), 'f1'), 'data-prefill-today');
        });
        self::run('render show_picker=false → no cal btn', function () use ($h, $cfg) {
            return !str_contains($h->render(array_merge($cfg, ['show_picker'=>false]), 'f1'), 'forge-date-cal-btn')
                ? true : 'calendar button present when show_picker=false';
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate valid DD.MM.YYYY', fn() => self::expectOk('10.07.2026', $cfg, $h));
        self::run('validate wrong format (ISO)', fn() => self::expectError('2026-07-10', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate wrong format (text)', fn() => self::expectError('not-a-date', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate invalid calendar date', fn() => self::expectError('31.02.2026', $cfg, $h));
        self::run('map non-empty returns value', fn() => self::expectMap('10.07.2026', $cfg, $h, '10.07.2026'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, 'No entry'));
        self::run('validate before min_date → error', function () use ($h, $cfg) {
            return self::expectError('10.07.2020', array_merge($cfg, ['min_date'=>'01.01.2025','max_date'=>'31.12.2025']), $h);
        });
        self::run('validate after max_date → error', function () use ($h, $cfg) {
            return self::expectError('10.07.2030', array_merge($cfg, ['min_date'=>'01.01.2025','max_date'=>'31.12.2025']), $h);
        });
        self::run('validate within min_date/max_date range → ok', function () use ($h, $cfg) {
            return self::expectOk('15.06.2025', array_merge($cfg, ['min_date'=>'01.01.2025','max_date'=>'31.12.2025']), $h);
        });
        self::run('validate exactly at min_date → ok', function () use ($h, $cfg) {
            return self::expectOk('01.01.2025', array_merge($cfg, ['min_date'=>'01.01.2025']), $h);
        });
        self::run('validate exactly at max_date → ok', function () use ($h, $cfg) {
            return self::expectOk('31.12.2025', array_merge($cfg, ['max_date'=>'31.12.2025']), $h);
        });
    }

    private static function testTime(): void
    {
        self::section('time');
        $h   = new \ForgeForms\Fields\TimeField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'time','label'=>'Uhrzeit']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render type="time"', fn() => self::contains($h->render($cfg, 'f1'), 'type="time"'));
        self::run('render 12h data-attr', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['time_format'=>true]), 'f1'), 'data-time-format');
        });
        self::run('render prefill_now attr', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['prefill_now'=>true]), 'f1'), 'data-prefill-now');
        });
        self::run('render default has no data-time-format attr', function () use ($h, $cfg) {
            return !str_contains($h->render($cfg, 'f1'), 'data-time-format') ? true : 'attr present by default';
        });
        self::run('render default has no data-prefill-now attr', function () use ($h, $cfg) {
            return !str_contains($h->render($cfg, 'f1'), 'data-prefill-now') ? true : 'attr present by default';
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        // TimeField has no format validation — any non-empty value passes
        self::run('validate any non-empty', fn() => self::expectOk('14:30', $cfg, $h));
        self::run('validate string passes', fn() => self::expectOk('not-a-time', $cfg, $h));
        self::run('map non-empty', fn() => self::expectMap('14:30', $cfg, $h, '14:30'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, 'No entry'));
    }

    private static function testCurrency(): void
    {
        self::section('currency');
        $h   = new \ForgeForms\Fields\CurrencyField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'currency','label'=>'Betrag','currency'=>'EUR']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render EUR symbol', fn() => self::contains($h->render($cfg, 'f1'), '€'));
        self::run('render USD symbol', fn() => self::contains($h->render(array_merge($cfg, ['currency'=>'USD']), 'f1'), '$'));
        self::run('render GBP symbol', fn() => self::contains($h->render(array_merge($cfg, ['currency'=>'GBP']), 'f1'), '£'));
        self::run('render CHF symbol', fn() => self::contains($h->render(array_merge($cfg, ['currency'=>'CHF']), 'f1'), 'Fr.'));
        self::run('render JPY symbol', fn() => self::contains($h->render(array_merge($cfg, ['currency'=>'JPY']), 'f1'), '¥'));
        self::run('render CAD symbol', fn() => self::contains($h->render(array_merge($cfg, ['currency'=>'CAD']), 'f1'), 'CA$'));
        self::run('render min/max attrs', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['min_value'=>'5','max_value'=>'1000']), 'f1');
            return str_contains($html, 'min') && str_contains($html, 'max') ? true : 'attrs missing';
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate valid amount', fn() => self::expectOk('12.50', $cfg, $h));
        self::run('validate non-numeric', fn() => self::expectError('abc', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate below min_value', fn() => self::expectError('5', array_merge($cfg, ['min_value'=>'10']), $h));
        self::run('validate above max_value', fn() => self::expectError('200', array_merge($cfg, ['max_value'=>'100']), $h));
        self::run('validate exact at min_value passes', fn() => self::expectOk('10', array_merge($cfg, ['min_value'=>'10']), $h));
        self::run('validate exact at max_value passes', fn() => self::expectOk('100', array_merge($cfg, ['max_value'=>'100']), $h));
        self::run('map has numeric value', fn() => self::expectMapContains('12.5', $cfg, $h, '12'));
        self::run('map has currency symbol', fn() => self::expectMapContains('12.5', $cfg, $h, '€'));
        self::run('map uses comma decimal', fn() => self::expectMapContains('12.5', $cfg, $h, ','));
        self::run('map non-numeric string passes through unformatted', fn() => self::expectMap('N/A', $cfg, $h, 'N/A €'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, 'No entry'));
    }

    private static function testSelect(): void
    {
        self::section('select');
        $opts = [['value'=>'a','label'=>'Alpha','default'=>false],['value'=>'b','label'=>'Beta','default'=>true]];
        $h    = new \ForgeForms\Fields\SelectField();
        $cfg  = array_merge($h->getDefaultConfig(), ['type'=>'select','label'=>'Auswahl','options'=>$opts]);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render <select', fn() => self::contains($h->render($cfg, 'f1'), '<select'));
        self::run('render option labels', fn() => self::contains($h->render($cfg, 'f1'), 'Alpha'));
        self::run('render default selected', fn() => self::contains($h->render($cfg, 'f1'), 'selected'));
        self::run('render explicit value overrides default option', function () use ($h, $cfg) {
            $html = $h->render($cfg, 'f1', 'a');
            self::$lastIn  = 'explicit value=a (option b has default:true)';
            self::$lastOut = $html;
            if (!str_contains($html, 'value="a" selected')) {
                return 'explicit value "a" not selected';
            }
            if (str_contains($html, 'value="b" selected')) {
                return 'default option "b" should not be selected when an explicit value is given';
            }
            return true;
        });
        self::run('render other_option', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['other_option'=>true]), 'f1');
            return self::contains($html, '__other__');
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        // SelectField has no server-side option-list validation — any value passes
        self::run('validate any value passes', fn() => self::expectOk('a', $cfg, $h));
        self::run('map known value → label', fn() => self::expectMap('a', $cfg, $h, 'Alpha'));
        self::run('map __other__ → [Other]', fn() => self::expectMapContains('__other__', $cfg, $h, '[Other]'));
        self::run('map unknown value → raw', fn() => self::expectMap('unknown', $cfg, $h, 'unknown'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, 'No entry'));
    }

    private static function testRadio(): void
    {
        self::section('radio');
        $opts = [['value'=>'x','label'=>'X-Ray','default'=>false],['value'=>'y','label'=>'Yankee','default'=>false]];
        $h    = new \ForgeForms\Fields\RadioField();
        $cfg  = array_merge($h->getDefaultConfig(), ['type'=>'radio','label'=>'Radio','options'=>$opts]);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render type="radio"', fn() => self::contains($h->render($cfg, 'f1'), 'type="radio"'));
        self::run('render option labels', fn() => self::contains($h->render($cfg, 'f1'), 'X-Ray'));
        // RadioField render() has no other_option PHP output; the JS init wires __other__ client-side
        self::run('render other_option does not crash', function () use ($h, $cfg) {
            return is_string($h->render(array_merge($cfg, ['other_option'=>true]), 'f1')) ? true : 'render threw';
        });
        self::run('render layout=false → no horizontal class', function () use ($h, $cfg) {
            return !str_contains($h->render(array_merge($cfg, ['layout'=>false]), 'f1'), 'forge-radio-group--horizontal')
                ? true : 'horizontal class present when layout=false';
        });
        self::run('render layout=true → horizontal class', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['layout'=>true]), 'f1'), 'forge-radio-group--horizontal');
        });
        self::run('render default-selected fallback (no value)', function () use ($h, $cfg) {
            $optsD = [['value'=>'x','label'=>'X-Ray','default'=>false],['value'=>'y','label'=>'Yankee','default'=>true]];
            $c     = array_merge($cfg, ['options'=>$optsD]);
            $html  = $h->render($c, 'f1');
            self::$lastIn  = 'no value, "y" has default:true';
            self::$lastOut = $html;
            if (!preg_match('/value="y"[^>]*>/', $html, $m)) {
                return 'option "y" input not found';
            }
            return str_contains($m[0], "checked='checked'") ? true : 'default option "y" not checked';
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        // RadioField has no server-side option-list validation — any value passes
        self::run('validate any value passes', fn() => self::expectOk('x', $cfg, $h));
        self::run('map known value → label', fn() => self::expectMap('x', $cfg, $h, 'X-Ray'));
        self::run('map unknown value → raw', fn() => self::expectMap('unknown', $cfg, $h, 'unknown'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, 'No entry'));
    }

    private static function testCheckbox(): void
    {
        self::section('checkbox');
        $opts = [['value'=>'one','label'=>'Eins','default'=>true],['value'=>'two','label'=>'Zwei','default'=>false],['value'=>'three','label'=>'Drei','default'=>false]];
        $h    = new \ForgeForms\Fields\CheckboxField();
        $cfg  = array_merge($h->getDefaultConfig(), ['type'=>'checkbox','label'=>'Checkboxen','options'=>$opts]);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render type="checkbox"', fn() => self::contains($h->render($cfg, 'f1'), 'type="checkbox"'));
        self::run('render option labels', fn() => self::contains($h->render($cfg, 'f1'), 'Eins'));
        self::run('render min/max_selections', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['min_selections'=>1,'max_selections'=>2]), 'f1');
            return self::contains($html, 'data-min-selections');
        });
        self::run('render other_option', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['other_option'=>true]), 'f1'), '__other__');
        });
        self::run('render layout=false → no horizontal class', function () use ($h, $cfg) {
            return !str_contains($h->render(array_merge($cfg, ['layout'=>false]), 'f1'), 'forge-checkbox-group--horizontal')
                ? true : 'horizontal class present when layout=false';
        });
        self::run('render default pre-checked options (no value)', function () use ($h, $cfg) {
            $html = $h->render($cfg, 'f1');
            self::$lastIn  = 'no value, "one" has default:true';
            self::$lastOut = $html;
            return str_contains($html, 'value="one" autocomplete="off" checked') ? true : 'default option "one" not checked';
        });
        self::run('validate required empty []', fn() => self::expectError([], array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty []', fn() => self::expectOk([], $cfg, $h));
        self::run('validate valid selection', fn() => self::expectOk(['one'], $cfg, $h));
        self::run('validate below min_selections', fn() => self::expectError(['one'], array_merge($cfg, ['min_selections'=>2]), $h));
        self::run('validate above max_selections', fn() => self::expectError(['one','two'], array_merge($cfg, ['max_selections'=>1]), $h));
        self::run('validate exact at min_selections passes', fn() => self::expectOk(['one','two'], array_merge($cfg, ['min_selections'=>2]), $h));
        self::run('validate exact at max_selections passes', fn() => self::expectOk(['one','two'], array_merge($cfg, ['max_selections'=>2]), $h));
        self::run('map known values has Eins', fn() => self::expectMapContains(['one','two'], $cfg, $h, 'Eins'));
        self::run('map known values has Zwei', fn() => self::expectMapContains(['one','two'], $cfg, $h, 'Zwei'));
        self::run('map __other__ → [Other]', fn() => self::expectMapContains(['__other__'], $cfg, $h, '[Other]'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains([], $cfg, $h, 'No entry'));
    }

    private static function testUpload(): void
    {
        self::section('upload');
        $h   = new \ForgeForms\Fields\UploadField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'upload','label'=>'Datei']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render multiple attr', fn() => self::contains($h->render(array_merge($cfg, ['multiple'=>true]), 'f1'), 'multiple'));
        self::run('render max_size hint', fn() => self::contains($h->render(array_merge($cfg, ['max_size_mb'=>5]), 'f1'), '5 MB'));
        self::run('render custom allowed_types', fn() => self::contains($h->render(array_merge($cfg, ['allowed_types'=>'pdf,docx']), 'f1'), 'pdf'));
        self::run('render allow_images=true includes image extensions', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['allow_images'=>true,'allow_documents'=>false]), 'f1'), '.jpg');
        });
        self::run('render allow_images=false excludes image extensions', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['allow_images'=>false,'allow_documents'=>false]), 'f1');
            self::$lastIn  = 'allow_images=false, allow_documents=false';
            self::$lastOut = $html;
            return !str_contains($html, '.jpg') ? true : '.jpg present despite allow_images=false';
        });
        self::run('render blocked extension excluded even when in custom allowed_types', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['allow_images'=>false,'allow_documents'=>false,'allowed_types'=>'php,pdf']), 'f1');
            self::$lastIn  = 'allowed_types=php,pdf (.php is blocked)';
            self::$lastOut = $html;
            if (str_contains($html, '.php')) {
                return '.php should be excluded from accept list (blocked type)';
            }
            return str_contains($html, '.pdf') ? true : '.pdf missing from accept list';
        });
        self::run('needsMultipartEncoding=true', fn() => $h->needsMultipartEncoding() ? true : 'expected true');
        // validate: optional with no file → true
        self::run('validate optional no file', fn() => self::expectOk(null, $cfg, $h));
        // validate: required with no file → error
        self::run('validate required no file', fn() => self::expectError(null, array_merge($cfg, ['required'=>true]), $h));
        self::run('validate required empty name', fn() => self::expectError(['name'=>'','tmp_name'=>'','error'=>0,'size'=>0,'type'=>''], array_merge($cfg, ['required'=>true]), $h));
        // validate: blocked extension regardless of required
        self::run('validate blocked ext php', fn() => self::expectError(['name'=>'evil.php','tmp_name'=>'/tmp/x','error'=>0,'size'=>100,'type'=>'text/plain'], $cfg, $h));
        self::run('validate blocked ext js', fn() => self::expectError(['name'=>'evil.js','tmp_name'=>'/tmp/x','error'=>0,'size'=>100,'type'=>'text/plain'], $cfg, $h));
        self::run('validate blocked ext exe', fn() => self::expectError(['name'=>'evil.exe','tmp_name'=>'/tmp/x','error'=>0,'size'=>100,'type'=>'application/octet-stream'], $cfg, $h));
        self::run('validate allowed ext pdf', fn() => self::expectOk(['name'=>'doc.pdf','tmp_name'=>'/tmp/x','error'=>0,'size'=>100,'type'=>'application/pdf'], $cfg, $h));
        self::run('map string value', fn() => self::expectMap('file.pdf', $cfg, $h, 'file.pdf'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, 'No entry'));
    }

    private static function testSignature(): void
    {
        self::section('signature');
        $h   = new \ForgeForms\Fields\SignatureField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'signature','label'=>'Unterschrift','export_format'=>'png']);

        $validPng = 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n".str_repeat("\x00", 100));
        $validJpg = 'data:image/jpeg;base64,'.base64_encode("\xff\xd8\xff".str_repeat("\x00", 100));

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render <canvas', fn() => self::contains($h->render($cfg, 'f1'), '<canvas'));
        self::run('render data-format=png', fn() => self::contains($h->render($cfg, 'f1'), 'data-format="png"'));
        self::run('render data-format=jpeg', fn() => self::contains($h->render(array_merge($cfg, ['export_format'=>'jpeg']), 'f1'), 'data-format="jpeg"'));
        self::run('render data-required when req', fn() => self::contains($h->render(array_merge($cfg, ['required'=>true]), 'f1'), 'data-required="true"'));
        self::run('render canvas_height attr', fn() => self::contains($h->render(array_merge($cfg, ['canvas_height'=>300]), 'f1'), '300'));
        self::run('render data-stroke attr', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['stroke_width'=>4]), 'f1'), 'data-stroke="4"');
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate required valid png', fn() => self::expectOk($validPng, array_merge($cfg, ['required'=>true]), $h));
        self::run('validate required jpeg format', function () use ($h, $cfg, $validJpg) {
            return self::expectOk($validJpg, array_merge($cfg, ['required'=>true,'export_format'=>'jpeg']), $h);
        });
        self::run('validate png rejected for jpeg config', function () use ($h, $cfg, $validPng) {
            return self::expectError($validPng, array_merge($cfg, ['required'=>true,'export_format'=>'jpeg']), $h);
        });
        self::run('map non-empty → empty string', fn() => self::expectMap($validPng, $cfg, $h, ''));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, 'No entry'));
        self::run('includeValueInSeal=false', fn() => !$h->includeValueInSeal() ? true : 'expected false');
    }

    private static function testRating(): void
    {
        self::section('rating');
        $h   = new \ForgeForms\Fields\RatingField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'rating','label'=>'Bewertung','max'=>5,'icon_type'=>'star']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render 5 radio inputs', function () use ($h, $cfg) {
            $count = substr_count($h->render($cfg, 'f1'), 'type="radio"');
            return $count === 5 ? true : "expected 5 radio inputs, got $count";
        });
        self::run('render max=3 → 3 radios', function () use ($h, $cfg) {
            $count = substr_count($h->render(array_merge($cfg, ['max'=>3]), 'f1'), 'type="radio"');
            return $count === 3 ? true : "expected 3, got $count";
        });
        self::run('render allow_half doubles inputs', function () use ($h, $cfg) {
            $count = substr_count($h->render(array_merge($cfg, ['allow_half'=>true]), 'f1'), 'type="radio"');
            return $count === 10 ? true : "expected 10 (5*2), got $count";
        });
        foreach (['star','heart','circle','diamond'] as $icon) {
            self::run("render icon_type=$icon", function () use ($h, $cfg, $icon) {
                return is_string($h->render(array_merge($cfg, ['icon_type'=>$icon]), 'f1')) ? true : "failed for $icon";
            });
        }
        self::run('render custom icon_source uses image url', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['icon_source'=>true,'custom_icon_url'=>'https://example.com/star.png']);
            return self::contains($h->render($c, 'f1'), 'star.png');
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate valid rating', fn() => self::expectOk('3', $cfg, $h));
        self::run('validate above max → error', fn() => self::expectError('99', $cfg, $h));
        self::run('validate negative → error', fn() => self::expectError('-1', $cfg, $h));
        self::run('validate non-numeric → error', fn() => self::expectError('abc', $cfg, $h));
        self::run('validate half-step rejected without allow_half', fn() => self::expectError('2.5', $cfg, $h));
        self::run('validate half-step accepted with allow_half', function () use ($h, $cfg) {
            return self::expectOk('2.5', array_merge($cfg, ['allow_half'=>true]), $h);
        });
        self::run('validate exactly at max → ok', fn() => self::expectOk('5', $cfg, $h));
        self::run('map value/max format', fn() => self::expectMap('3', $cfg, $h, '3 / 5'));
        self::run('map half value format', fn() => self::expectMap('2.5', $cfg, $h, '2.5 / 5'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, 'No entry'));
    }

    private static function testSlider(): void
    {
        self::section('slider');
        $h   = new \ForgeForms\Fields\SliderField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'slider','label'=>'Slider','min'=>0,'max'=>100,'step'=>1,'ranged'=>false]);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render data-min/max/step', fn() => self::contains($h->render($cfg, 'f1'), 'data-min'));
        self::run('render ranged two hidden inputs', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['ranged'=>true]), 'f1');
            $count = substr_count($html, 'type="hidden"');
            return $count === 2 ? true : "expected 2 hidden inputs, got $count";
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate valid in range', fn() => self::expectOk('50', $cfg, $h));
        self::run('validate below min', fn() => self::expectError('-5', $cfg, $h));
        self::run('validate above max', fn() => self::expectError('150', $cfg, $h));
        self::run('validate exact at min passes', fn() => self::expectOk('0', $cfg, $h));
        self::run('validate exact at max passes', fn() => self::expectOk('100', $cfg, $h));
        self::run('validate non-numeric', fn() => self::expectError('abc', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate ranged valid', fn() => self::expectOk(['from'=>'20','to'=>'80'], array_merge($cfg, ['ranged'=>true]), $h));
        self::run('validate ranged below min', fn() => self::expectError(['from'=>'-5','to'=>'50'], array_merge($cfg, ['ranged'=>true]), $h));
        self::run('validate ranged above max', fn() => self::expectError(['from'=>'50','to'=>'150'], array_merge($cfg, ['ranged'=>true]), $h));
        self::run('validate ranged exact at min/max passes', function () use ($h, $cfg) {
            return self::expectOk(['from'=>'0','to'=>'100'], array_merge($cfg, ['ranged'=>true]), $h);
        });
        self::run('map scalar value', fn() => self::expectMap('50', $cfg, $h, '50'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, 'No entry'));
        self::run('map ranged has from', fn() => self::expectMapContains(['from'=>'20','to'=>'80'], array_merge($cfg, ['ranged'=>true]), $h, '20'));
        self::run('map ranged has to', fn() => self::expectMapContains(['from'=>'20','to'=>'80'], array_merge($cfg, ['ranged'=>true]), $h, '80'));
    }

    private static function testCaptcha(): void
    {
        self::section('captcha');
        $h   = new \ForgeForms\Fields\CaptchaField();
        $cfg = $h->getDefaultConfig();

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render returns string', fn() => is_string($h->render($cfg, 'f1')) ? true : 'render failed');
        // skipValidation=false — captcha runs its own server-side verify, not skipped
        self::run('skipValidation=false', fn() => $h->skipValidation() === false ? true : 'expected false');
        // validate: empty token always errors (no secret key configured in test env)
        self::run('validate empty token → error', fn() => self::expectError('', $cfg, $h));
        // map always returns confirmation string regardless of value
        self::run('map always confirmed string', fn() => self::expectMapContains('anytoken', $cfg, $h, 'CAPTCHA'));
    }

    private static function testConsent(): void
    {
        self::section('consent');
        $h   = new \ForgeForms\Fields\ConsentField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'consent','label'=>'Einwilligung','consent_text'=>'Ich stimme zu.']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render consent text', fn() => self::contains($h->render($cfg, 'f1'), 'Ich stimme zu'));
        self::run('render type="checkbox"', fn() => self::contains($h->render($cfg, 'f1'), 'type="checkbox"'));
        self::run('render checked attr for truthy value', fn() => self::contains($h->render($cfg, 'f1', '1'), 'checked'));
        self::run('render default consent_text fallback when key omitted', function () use ($h) {
            $c = $h->getDefaultConfig();
            unset($c['consent_text']);
            $c['type'] = 'consent';
            return self::contains($h->render($c, 'f1'), 'I agree.');
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate required checked', fn() => self::expectOk('1', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('map checked → Yes', fn() => self::expectMap('1', $cfg, $h, 'Yes'));
        self::run('map unchecked → No', fn() => self::expectMap('', $cfg, $h, 'No'));
        self::run('map 0 → No', fn() => self::expectMap('0', $cfg, $h, 'No'));
        self::run('sanitize keeps <a> in text', function () use ($h) {
            $out = $h->sanitizeConfigValue('consent_text', '<a href="https://x.com">Link</a>');
            return str_contains($out, '<a') ? true : 'link stripped: '.$out;
        });
    }

    private static function testGdpr(): void
    {
        self::section('gdpr');
        $h   = new \ForgeForms\Fields\GdprField();
        $cfg = array_merge($h->getDefaultConfig(), [
            'type'=>'gdpr','label'=>'DSGVO',
            'privacy_policy_url'=>'https://example.com/privacy',
            'privacy_policy_text'=>'Datenschutz',
        ]);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render policy URL in link', fn() => self::contains($h->render($cfg, 'f1'), 'example.com/privacy'));
        self::run('render policy text', fn() => self::contains($h->render($cfg, 'f1'), 'Datenschutz'));
        self::run('render always required attr', fn() => self::contains($h->render($cfg, 'f1'), 'required'));
        self::run('render checked attr for truthy value', fn() => self::contains($h->render($cfg, 'f1', '1'), 'checked'));
        self::run('render default privacy_policy_url falls back to get_privacy_policy_url()', function () use ($h) {
            $c = $h->getDefaultConfig();
            unset($c['privacy_policy_url']); // key merely defaults to '' otherwise, which ?? would NOT fall through on
            $c['type']  = 'gdpr';
            $c['label'] = 'DSGVO';
            $html = $h->render($c, 'f1');
            self::$lastIn  = 'privacy_policy_url omitted (default "")';
            self::$lastOut = $html;
            return !str_contains($html, 'example.com/privacy')
                ? true : 'should not carry over the explicitly-configured URL when key is left at its default';
        });
        self::run('validate checked → ok', fn() => self::expectOk('1', $cfg, $h));
        // GDPR always errors when unchecked, regardless of required config flag
        self::run('validate unchecked required=true → error', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate unchecked required=false → error', fn() => self::expectError('', array_merge($cfg, ['required'=>false]), $h));
        self::run('map checked → Privacy accepted', fn() => self::expectMapContains('1', $cfg, $h, 'Privacy accepted'));
        self::run('map unchecked → Privacy not accepted', fn() => self::expectMapContains('', $cfg, $h, 'Privacy not accepted'));
    }

    private static function testHtml(): void
    {
        self::section('html');
        $h   = new \ForgeForms\Fields\HtmlField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'html','label'=>'','html_content'=>'<p>Hello <strong>World</strong></p>']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render preserves <strong>', fn() => self::contains($h->render($cfg, 'f1'), '<strong>World</strong>'));
        self::run('hasRequired=false', fn() => !$h->hasRequired() ? true : 'expected false');
        self::run('skipValidation=true', fn() => $h->skipValidation() ? true : 'expected true');
        self::run('includeInEmailSummary=false', fn() => !$h->includeInEmailSummary() ? true : 'expected false');
        self::run('map strips all tags', function () use ($h, $cfg) {
            $m = $h->map('', $cfg);
            self::$lastIn  = '(html_content from config)';
            self::$lastOut = $m;
            return !str_contains($m, '<') ? true : 'tags remain: '.$m;
        });
        self::run('sanitize strips <script>', function () use ($h) {
            return !str_contains($h->sanitizeConfigValue('html_content', '<p>OK</p><script>evil()</script>'), '<script') ? true : 'script not stripped';
        });
        self::run('sanitize preserves <input>', function () use ($h) {
            return str_contains($h->sanitizeConfigValue('html_content', '<input type="text" name="x">'), '<input') ? true : 'input stripped';
        });
        self::run('sanitize preserves <canvas>', function () use ($h) {
            return str_contains($h->sanitizeConfigValue('html_content', '<canvas id="c"></canvas>'), '<canvas') ? true : 'canvas stripped';
        });
        self::run('sanitize preserves <svg>', function () use ($h) {
            return str_contains($h->sanitizeConfigValue('html_content', '<svg><circle cx="10" cy="10" r="5"/></svg>'), '<svg') ? true : 'svg stripped';
        });
        self::run('sanitize preserves <select>', function () use ($h) {
            return str_contains($h->sanitizeConfigValue('html_content', '<select><option value="a">A</option></select>'), '<select') ? true : 'select stripped';
        });
        self::run('sanitize preserves <source>', function () use ($h) {
            return str_contains($h->sanitizeConfigValue('html_content', '<source src="a.mp4" type="video/mp4">'), '<source') ? true : 'source stripped';
        });
        self::run('sanitize <use href="#frag"> kept, external href stripped', function () use ($h) {
            $out = $h->sanitizeConfigValue('html_content', '<svg><use href="#frag"></use><use href="http://evil.com/x"></use></svg>');
            self::$lastIn  = '<use href="#frag"> + <use href="http://evil.com/x">';
            self::$lastOut = $out;
            if (!str_contains($out, 'href="#frag"')) {
                return 'in-document fragment href was stripped unexpectedly';
            }
            return !str_contains($out, 'evil.com') ? true : 'external href was not stripped';
        });
        self::run('sanitize other key uses plain wp_kses_post (strips <script>)', function () use ($h) {
            return !str_contains($h->sanitizeConfigValue('label', '<p>ok</p><script>evil()</script>'), '<script')
                ? true : 'script not stripped for non-html_content key';
        });
        self::run('mapNormalized empty html_content → []', function () use ($h) {
            $c = array_merge($h->getDefaultConfig(), ['type'=>'html','label'=>'','html_content'=>'']);
            $r = $h->mapNormalized('f1', '', '', $c, []);
            self::$lastIn  = 'html_content=""';
            self::$lastOut = var_export($r, true);
            return $r === [] ? true : 'expected [], got: ' . var_export($r, true);
        });
        self::run('mapNormalized non-empty html_content → labeled entry', function () use ($h) {
            $c = array_merge($h->getDefaultConfig(), ['type'=>'html','label'=>'Block','html_content'=>'<p>Hi</p>']);
            $r = $h->mapNormalized('f1', 'Block', '', $c, []);
            self::$lastIn  = 'html_content=<p>Hi</p>';
            self::$lastOut = var_export($r, true);
            return (isset($r['f1']) && $r['f1']['label'] === 'Block' && str_contains($r['f1']['value'], 'Hi'))
                ? true : 'unexpected result: ' . var_export($r, true);
        });
    }

    private static function testGroup(): void
    {
        self::section('group');
        $h   = new \ForgeForms\Fields\GroupField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'group','label'=>'Gruppe','children'=>[]]);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('isGroupContainer=true', fn() => $h->isGroupContainer() ? true : 'expected true');
        self::run('hasRequired=false', fn() => !$h->hasRequired() ? true : 'expected false');
        self::run('includeInEmailSummary=false', fn() => !$h->includeInEmailSummary() ? true : 'expected false');
        self::run('render returns string', fn() => is_string($h->render($cfg, 'f1')) ? true : 'render failed');
        self::run('render opens forge-field-group', fn() => self::contains($h->render($cfg, 'f1'), 'forge-field-group'));
        self::run('map always empty string', fn() => self::expectMap(null, $cfg, $h, ''));
        self::run('mapNormalized empty → []', function () use ($h, $cfg) {
            $r = $h->mapNormalized('f1', 'Group', [], $cfg, []);
            return $r === [] ? true : 'expected [], got: '.var_export($r, true);
        });
        self::run('mapNormalized populated single copy', function () use ($h) {
            $children = [['id'=>'child_text','type'=>'text','label'=>'Kind']];
            $c        = array_merge($h->getDefaultConfig(), ['type'=>'group','label'=>'Gruppe','children'=>$children]);
            $value    = [0 => ['child_text' => 'Hallo']];
            $r        = $h->mapNormalized('grp1', 'Gruppe', $value, $c, []);
            self::$lastIn  = json_encode($value);
            self::$lastOut = json_encode($r);
            if (!isset($r['child_text'])) {
                return 'expected key "child_text" (single copy, no suffix), got: ' . var_export($r, true);
            }
            return $r['child_text']['value'] === 'Hallo' ? true : 'unexpected value: ' . var_export($r['child_text'], true);
        });
        self::run('mapNormalized populated multiple copies suffixes keys', function () use ($h) {
            $children = [['id'=>'child_text','type'=>'text','label'=>'Kind']];
            $c        = array_merge($h->getDefaultConfig(), ['type'=>'group','label'=>'Gruppe','children'=>$children]);
            $value    = [0 => ['child_text' => 'Erste'], 1 => ['child_text' => 'Zweite']];
            $r        = $h->mapNormalized('grp1', 'Gruppe', $value, $c, []);
            self::$lastIn  = json_encode($value);
            self::$lastOut = json_encode($r);
            if (!isset($r['child_text_copy_0']) || !isset($r['child_text_copy_1'])) {
                return 'expected suffixed keys child_text_copy_0/1, got: ' . var_export($r, true);
            }
            return ($r['child_text_copy_0']['value'] === 'Erste' && $r['child_text_copy_1']['value'] === 'Zweite')
                ? true : 'unexpected values: ' . var_export($r, true);
        });
    }

    private static function testPageBreak(): void
    {
        self::section('pagebreak');
        $h   = new \ForgeForms\Fields\PageBreakField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'pagebreak','label'=>'','prev_btn'=>'Zurück','next_btn'=>'Weiter']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('isPageBreak=true', fn() => $h->isPageBreak() ? true : 'expected true');
        self::run('hasSettingsPanel=false', fn() => !$h->hasSettingsPanel() ? true : 'expected false');
        self::run('hasRequired=false', fn() => !$h->hasRequired() ? true : 'expected false');
        self::run('skipValidation=true', fn() => $h->skipValidation() ? true : 'expected true');
        self::run('includeInEmailSummary=false', fn() => !$h->includeInEmailSummary() ? true : 'expected false');
        self::run('render returns empty string', fn() => $h->render($cfg, 'f1') === '' ? true : 'expected empty string');
        self::run('map returns empty string', fn() => self::expectMap(null, $cfg, $h, ''));
        self::run('mapNormalized returns []', function () use ($h, $cfg) {
            $r = $h->mapNormalized('f1', '', null, $cfg, []);
            return $r === [] ? true : 'expected []';
        });
        self::run('renderBreak page 2 has nav', function () use ($h, $cfg) {
            $html = $h->renderBreak($cfg, 2);
            return str_contains($html, 'forge-btn-next') && str_contains($html, 'forge-btn-prev') ? true : 'nav missing';
        });
        // page 1: bottom nav uses <span></span> instead of prev button
        self::run('renderBreak page 1 bottom has span', function () use ($h, $cfg) {
            $html = $h->renderBreak($cfg, 1);
            return str_contains($html, '<span></span>') ? true : 'expected <span></span> on page 1 bottom nav';
        });
        self::run('renderBreak custom prev/next labels appear', function () use ($h, $cfg) {
            $html = $h->renderBreak($cfg, 2);
            self::$lastIn  = 'prev_btn=Zurück, next_btn=Weiter';
            self::$lastOut = $html;
            return (str_contains($html, 'Zurück') && str_contains($html, 'Weiter'))
                ? true : 'custom button labels missing from renderBreak output';
        });
    }

    private static function testPostData(): void
    {
        self::section('postdata');
        $h   = new \ForgeForms\Fields\PostDataField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'postdata','label'=>'Beitragsinfo','post_field'=>['post_title']]);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render returns string', fn() => is_string($h->render($cfg, 'f1')) ? true : 'render failed');
        self::run('hasRequired=false', fn() => !$h->hasRequired() ? true : 'expected false');
        self::run('render produces hidden input with correct name', function () use ($h, $cfg) {
            $html = $h->render($cfg, 'f1');
            self::$lastIn  = 'post_field=[post_title]';
            self::$lastOut = $html;
            return str_contains($html, '<input type="hidden" name="f1[post_title]"')
                ? true : 'expected hidden input name f1[post_title], got: ' . $html;
        });
        self::run('map array → imploded values', fn() => self::expectMapContains(['post_title'=>'My Page','post_id'=>'42'], $cfg, $h, 'My Page'));
        self::run('map excludes fields not selected in post_field', function () use ($h, $cfg) {
            $value = ['post_title'=>'My Page','post_id'=>'42','post_url'=>'https://example.com','post_author'=>'Admin'];
            $r     = $h->map($value, $cfg);
            self::$lastIn  = json_encode($value);
            self::$lastOut = $r;
            if (str_contains($r, '42') || str_contains($r, 'example.com') || str_contains($r, 'Admin')) {
                return 'unselected post_field values leaked into output: ' . $r;
            }
            return str_contains($r, 'My Page') ? true : 'expected "My Page" in output';
        });
        self::run('map empty array → Kein Eintrag', fn() => self::expectMapContains([], $cfg, $h, 'No entry'));
        self::run('map non-array → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, 'No entry'));
    }

    private static function testWebsite(): void
    {
        self::section('website');
        $h   = new \ForgeForms\Fields\WebsiteField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'website','label'=>'Webseite']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render type="url"', fn() => self::contains($h->render($cfg, 'f1'), 'type="url"'));
        self::run('render validate_url data-attr', fn() => self::contains($h->render(array_merge($cfg, ['validate_url'=>true]), 'f1'), 'data-validate-url'));
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate valid URL', fn() => self::expectOk('https://example.com', $cfg, $h));
        self::run('validate invalid URL when flag', fn() => self::expectError('not a url', array_merge($cfg, ['validate_url'=>true]), $h));
        // validate_url=false: invalid URLs pass (no format check)
        self::run('validate invalid URL no flag', fn() => self::expectOk('not a url', array_merge($cfg, ['validate_url'=>false]), $h));
        self::run('map value', fn() => self::expectMap('https://example.com', $cfg, $h, 'https://example.com'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, 'No entry'));
    }

    private static function testSepa(): void
    {
        self::section('sepa');
        $h   = new \ForgeForms\Fields\SepaField();
        $cfg = array_merge($h->getDefaultConfig(), [
            'type'=>'sepa','label'=>'SEPA-Mandat',
            'mandate_title'=>'SEPA Lastschriftmandat',
            'creditor_id'=>'DE98ZZZ09999999999','mandate_ref'=>'MANDAT-001',
        ]);

        $validData = ['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','holder'=>'Max Mustermann','sig'=>''];

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render mandate title', fn() => self::contains($h->render($cfg, 'f1'), 'SEPA Lastschriftmandat'));
        self::run('render IBAN input class', fn() => self::contains($h->render($cfg, 'f1'), 'forge-sepa-iban'));
        self::run('render BIC input class', fn() => self::contains($h->render($cfg, 'f1'), 'forge-sepa-bic'));
        self::run('render holder input class', fn() => self::contains($h->render($cfg, 'f1'), 'forge-sepa-holder'));
        self::run('render <canvas for sig', fn() => self::contains($h->render($cfg, 'f1'), '<canvas'));
        self::run('render creditor_id in output', fn() => self::contains($h->render($cfg, 'f1'), 'DE98ZZZ09999999999'));
        self::run('render country_filter → data attr present', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['country_filter_mode'=>'allow','country_filter_list'=>['DE','AT']]);
            return self::contains($h->render($c, 'f1'), 'data-country-filter');
        });
        self::run('render country_filter → list attr present', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['country_filter_mode'=>'allow','country_filter_list'=>['DE','AT']]);
            return self::contains($h->render($c, 'f1'), 'data-country-list');
        });
        self::run('render country_filter off → no attr', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['country_filter_mode'=>'off']), 'f1');
            self::$lastIn  = 'country_filter_mode=off';
            self::$lastOut = str_contains($html, 'data-country-filter') ? 'attr present' : 'attr absent';
            return !str_contains($html, 'data-country-filter') ? true : 'attr should be absent when mode=off';
        });
        // Server-side country filter validation
        self::run('validate allow-list blocks foreign IBAN', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['required'=>true,'country_filter_mode'=>'allow','country_filter_list'=>['DE']]);
            return self::expectError(['iban'=>'AT611904300234573201','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>''], $c, $h);
        });
        self::run('validate allow-list passes matching IBAN', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['required'=>true,'country_filter_mode'=>'allow','country_filter_list'=>['DE']]);
            return self::expectOk(['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>''], $c, $h);
        });
        self::run('validate disallow-list blocks listed IBAN', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['required'=>true,'country_filter_mode'=>'disallow','country_filter_list'=>['AT']]);
            return self::expectError(['iban'=>'AT611904300234573201','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>''], $c, $h);
        });
        self::run('validate disallow-list passes unlisted IBAN', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['required'=>true,'country_filter_mode'=>'disallow','country_filter_list'=>['AT']]);
            return self::expectOk(['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>''], $c, $h);
        });
        self::run('validate country filter off passes any country', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['required'=>true,'country_filter_mode'=>'off','country_filter_list'=>['DE']]);
            return self::expectOk(['iban'=>'AT611904300234573201','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>''], $c, $h);
        });
        // optional=false skips all validation immediately
        self::run('validate optional → true', fn() => self::expectOk([], array_merge($cfg, ['required'=>false]), $h));
        self::run('validate req non-array → error', fn() => self::expectError(null, array_merge($cfg, ['required'=>true]), $h));
        self::run('validate req empty IBAN → error', fn() => self::expectError(['iban'=>'','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>''], array_merge($cfg, ['required'=>true]), $h));
        self::run('validate req invalid IBAN', fn() => self::expectError(['iban'=>'INVALID','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>''], array_merge($cfg, ['required'=>true]), $h));
        self::run('validate req empty BIC → error', fn() => self::expectError(['iban'=>'DE89370400440532013000','bic'=>'','holder'=>'Max','sig'=>''], array_merge($cfg, ['required'=>true]), $h));
        // 'TOO' is only 3 chars — fails [A-Z]{6} minimum
        self::run('validate req invalid BIC', fn() => self::expectError(['iban'=>'DE89370400440532013000','bic'=>'TOO','holder'=>'Max','sig'=>''], array_merge($cfg, ['required'=>true]), $h));
        self::run('validate req empty holder', fn() => self::expectError(['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','holder'=>'','sig'=>''], array_merge($cfg, ['required'=>true]), $h));
        self::run('validate req all valid → true', fn() => self::expectOk($validData, array_merge($cfg, ['required'=>true]), $h));
        self::run('validate lowercase BIC passes (case-insensitive regex)', function () use ($h, $cfg) {
            return self::expectOk(['iban'=>'DE89370400440532013000','bic'=>'cobadeffxxx','holder'=>'Max','sig'=>''], array_merge($cfg, ['required'=>true]), $h);
        });
        self::run('validate lowercase country_filter_list entry matches (strtoupper normalized)', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['required'=>true,'country_filter_mode'=>'allow','country_filter_list'=>['de']]);
            return self::expectOk(['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>''], $c, $h);
        });
        self::run('map non-array → No entry', fn() => str_contains($h->map(null, $cfg), 'No entry') ? true : 'wrong map');
        self::run('map valid data contains IBAN', fn() => self::expectMapContains($validData, $cfg, $h, 'IBAN'));
        self::run('map valid data contains BIC', fn() => self::expectMapContains($validData, $cfg, $h, 'BIC'));
        self::run('map valid data contains holder', fn() => self::expectMapContains($validData, $cfg, $h, 'Mustermann'));
    }

    private static function testRegistryCoverage(): void
    {
        self::section('FieldRegistry coverage');
        $registry    = \ForgeForms\Fields\FieldRegistry::all();
        $testedTypes = [
            'text', 'textarea', 'email', 'name', 'phone', 'number', 'address',
            'date', 'time', 'currency', 'select', 'radio', 'checkbox', 'upload',
            'signature', 'rating', 'slider', 'captcha', 'consent', 'gdpr', 'html',
            'group', 'pagebreak', 'postdata', 'website', 'sepa',
        ];

        self::run('all registered types are tested', function () use ($registry, $testedTypes) {
            $missing = array_diff(array_keys($registry), $testedTypes);
            return empty($missing) ? true : 'untested: '.implode(', ', $missing);
        });
        self::run('no tested type is unregistered', function () use ($registry, $testedTypes) {
            $unknown = array_diff($testedTypes, array_keys($registry));
            return empty($unknown) ? true : 'not registered: '.implode(', ', $unknown);
        });
    }

    // ── JS test helpers ──────────────────────────────────────────────────────

    /**
     * Generates the same window.ForgeValidators / ForgeEmptyChecks / ForgeFieldInits /
     * ForgeSkipValidation globals that Assets::enqueueFront() emits, so the JS test
     * harness has the real field implementations available on the admin test page.
     */
    private static function generateFrontGlobals(): string
    {
        $emptyChecks = [];
        $pairs = [];
        $seenRules = [];
        $inits = [];
        $skip = [];

        foreach (\ForgeForms\Fields\FieldRegistry::all() as $type => $class) {
            $handler = new $class();

            $entry = $handler->getClientEmptyCheck();
            if (!empty($entry['fn'])) {
                $emptyChecks[] = json_encode($type) . ':' . trim($entry['fn']);
            }

            foreach ($handler->getClientValidation() as $vEntry) {
                $rule = $vEntry['rule'] ?? '';
                $fn = $vEntry['fn'] ?? '';
                if ($rule !== '' && $fn !== '' && !isset($seenRules[$rule])) {
                    $seenRules[$rule] = true;
                    $pairs[] = json_encode($rule) . ':' . trim($fn);
                }
            }

            $fn = $handler->getClientInit();
            if ($fn !== '') {
                $inits[] = json_encode($type) . ':' . trim($fn);
            }

            if ($handler->skipValidation()) {
                $skip[] = json_encode($type);
            }
        }

        $js = "window.__FORGE_TEST__=true;\n";
        $js .= "window.ForgeForms={ajaxUrl:''};\n";
        $js .= 'window.ForgeValidators=' . (!empty($pairs)
            ? '{' . implode(',', $pairs) . '}'
            : '{}') . ";\n";
        $js .= 'window.ForgeEmptyChecks=' . (!empty($emptyChecks)
            ? '{' . implode(',', $emptyChecks) . '}'
            : '{}') . ";\n";
        $js .= 'window.ForgeFieldInits=' . (!empty($inits)
            ? '{' . implode(',', $inits) . '}'
            : '{}') . ";\n";
        $js .= 'window.ForgeSkipValidation=' . (!empty($skip)
            ? '[' . implode(',', $skip) . ']'
            : '[]') . ";\n";

        return $js;
    }

    private static function jsTestHarness(): string
    {
        return <<<'JS'
(function () {
    'use strict';
    var pass = 0;
    var fail = 0;
    var rows = [];

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function run(name, fn) {
        var r;
        try {
            r = fn();
        } catch (e) {
            fail++;
            rows.push('<tr class="ff-fail"><td>✗</td><td>' + esc(name) + '</td>'
                + '<td></td><td></td><td>Exception: ' + esc(e.message) + '</td></tr>');
            return;
        }
        if (r.ok) {
            pass++;
            rows.push('<tr class="ff-pass"><td>✓</td><td>' + esc(name) + '</td>'
                + '<td class="ff-io">' + esc(r.i) + '</td>'
                + '<td class="ff-io ff-out">' + esc(r.o) + '</td>'
                + '<td></td></tr>');
        } else {
            fail++;
            rows.push('<tr class="ff-fail"><td>✗</td><td>' + esc(name) + '</td>'
                + '<td class="ff-io">' + esc(r.i) + '</td>'
                + '<td class="ff-io ff-out">' + esc(r.o) + '</td>'
                + '<td>' + esc(r.msg || 'FAIL') + '</td></tr>');
        }
    }

    function section(label) {
        rows.push('<tr class="ff-section"><td colspan="5"><strong>' + esc(label) + '</strong></td></tr>');
    }

    function ok(i, o)       { return { ok: true,  i: i, o: o }; }
    function ko(i, o, msg)  { return { ok: false, i: i, o: String(o), msg: msg }; }

    /* ── ForgeSkipValidation ─────────────────────────────────────────────── */
    section('JS: ForgeSkipValidation');
    var skip = window.ForgeSkipValidation || [];
    run('skip array exists', function () {
        return Array.isArray(skip) ? ok('ForgeSkipValidation', skip.join(', ')) : ko('', '', 'not an array');
    });
    run('html is skipped', function () {
        return skip.indexOf('html') !== -1 ? ok('html', 'in skip list') : ko('html', '', 'not in skip list');
    });
    run('pagebreak is skipped', function () {
        return skip.indexOf('pagebreak') !== -1 ? ok('pagebreak', 'in skip list') : ko('pagebreak', '', 'not in skip list');
    });

    /* ── ForgeValidators ─────────────────────────────────────────────────── */
    section('JS: ForgeValidators');
    var validators = window.ForgeValidators || {};
    run('ForgeValidators is an object', function () {
        return typeof validators === 'object'
            ? ok('ForgeValidators', Object.keys(validators).join(', '))
            : ko('', '', 'not object');
    });

    /* email */
    run('email: valid → null', function () {
        var fn = validators['email'];
        if (!fn) { return ko('', '', 'email validator not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="email" value="test@example.com">';
        var r = fn(el);
        return r == null ? ok('test@example.com', 'null') : ko('test@example.com', r, 'expected null');
    });
    run('email: invalid → error string', function () {
        var fn = validators['email'];
        if (!fn) { return ko('', '', 'email validator not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="email" value="not-an-email">';
        var r = fn(el);
        return (typeof r === 'string' && r.length > 0)
            ? ok('not-an-email', r)
            : ko('not-an-email', r, 'expected error string');
    });

    /* iban — validator reads _forgeIbanValid/_forgeIbanInvalid flags set by the
       init+input handler, so we set them manually to test each branch */
    run('iban: valid flag → null', function () {
        var fn = validators['iban'];
        if (!fn) { return ko('', '', 'iban validator not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input class="forge-sepa-iban" value="DE89370400440532013000">';
        el.querySelector('.forge-sepa-iban')._forgeIbanValid = true;
        var r = fn(el);
        return r == null ? ok('DE89370400440532013000 + _forgeIbanValid', 'null') : ko('DE89370400440532013000', r, 'expected null');
    });
    run('iban: invalid checksum flag → error string', function () {
        var fn = validators['iban'];
        if (!fn) { return ko('', '', 'iban validator not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input class="forge-sepa-iban" value="DE00000000000000000000">';
        el.querySelector('.forge-sepa-iban')._forgeIbanInvalid = true;
        var r = fn(el);
        return (typeof r === 'string' && r.length > 0)
            ? ok('_forgeIbanInvalid=true', r)
            : ko('_forgeIbanInvalid=true', r, 'expected error string');
    });
    run('iban: incomplete (no flags) → incomplete error', function () {
        var fn = validators['iban'];
        if (!fn) { return ko('', '', 'iban validator not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input class="forge-sepa-iban" value="DE123">';
        var r = fn(el);
        return (typeof r === 'string' && r.length > 0)
            ? ok('DE123 (no flags set)', r)
            : ko('DE123', r, 'expected incomplete-IBAN error');
    });

    /* phone ─────────────────────────────────────────────────────────────── */
    section('JS: validators — phone');
    run('phone: empty → null', function () {
        var fn = validators['phone'];
        if (!fn) { return ko('', '', 'phone not registered'); }
        var el = document.createElement('div');
        var inp = document.createElement('input');
        inp.type = 'tel'; inp.value = '';
        inp.dataset.phoneMode = 'any';
        el.appendChild(inp);
        return fn(el) == null ? ok('(empty) mode=any', 'null') : ko('(empty)', fn(el), 'expected null');
    });
    run('phone: no mode → null (no validation)', function () {
        var fn = validators['phone'];
        if (!fn) { return ko('', '', 'phone not registered'); }
        var el = document.createElement('div');
        var inp = document.createElement('input');
        inp.type = 'tel'; inp.value = 'abc123';
        el.appendChild(inp);
        return fn(el) == null ? ok('abc123 (no mode)', 'null') : ko('abc123', fn(el), 'expected null when no mode');
    });
    run('phone: mode=any valid → null', function () {
        var fn = validators['phone'];
        if (!fn) { return ko('', '', 'phone not registered'); }
        var el = document.createElement('div');
        var inp = document.createElement('input');
        inp.type = 'tel'; inp.value = '+4915123456789';
        inp.dataset.phoneMode = 'any';
        el.appendChild(inp);
        var r = fn(el);
        return r == null ? ok('+4915123456789 mode=any', 'null') : ko('+4915123456789', r, 'expected null');
    });
    run('phone: mode=any too short → error', function () {
        var fn = validators['phone'];
        if (!fn) { return ko('', '', 'phone not registered'); }
        var el = document.createElement('div');
        var inp = document.createElement('input');
        inp.type = 'tel'; inp.value = '123';
        inp.dataset.phoneMode = 'any';
        el.appendChild(inp);
        var r = fn(el);
        return typeof r === 'string' && r ? ok('123 mode=any', r) : ko('123', r, 'expected error');
    });
    run('phone: mode=countries missing + → error', function () {
        var fn = validators['phone'];
        if (!fn) { return ko('', '', 'phone not registered'); }
        var el = document.createElement('div');
        var inp = document.createElement('input');
        inp.type = 'tel'; inp.value = '015123456789';
        inp.dataset.phoneMode = 'countries';
        inp.dataset.phoneCountryMode = 'allow';
        inp.dataset.phoneCountryList = '["49"]';
        el.appendChild(inp);
        var r = fn(el);
        return typeof r === 'string' && r ? ok('015… (no +)', r) : ko('015…', r, 'expected + required error');
    });
    run('phone: mode=countries allow matching → null', function () {
        var fn = validators['phone'];
        if (!fn) { return ko('', '', 'phone not registered'); }
        var el = document.createElement('div');
        var inp = document.createElement('input');
        inp.type = 'tel'; inp.value = '+4915123456789';
        inp.dataset.phoneMode = 'countries';
        inp.dataset.phoneCountryMode = 'allow';
        inp.dataset.phoneCountryList = '["49"]';
        el.appendChild(inp);
        var r = fn(el);
        return r == null ? ok('+4915… (DE, in allow list)', 'null') : ko('+4915…', r, 'expected null');
    });
    run('phone: mode=countries allow non-matching → error', function () {
        var fn = validators['phone'];
        if (!fn) { return ko('', '', 'phone not registered'); }
        var el = document.createElement('div');
        var inp = document.createElement('input');
        inp.type = 'tel'; inp.value = '+33123456789';
        inp.dataset.phoneMode = 'countries';
        inp.dataset.phoneCountryMode = 'allow';
        inp.dataset.phoneCountryList = '["49"]';
        el.appendChild(inp);
        var r = fn(el);
        return typeof r === 'string' && r ? ok('+33… (FR, not in DE allow list)', r) : ko('+33…', r, 'expected error');
    });

    /* number-range ──────────────────────────────────────────────────────── */
    section('JS: validators — number-range');
    run('number-range: empty → null', function () {
        var fn = validators['number-range'];
        if (!fn) { return ko('', '', 'number-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="number" value="" min="1" max="10">';
        return fn(el) == null ? ok('(empty)', 'null') : ko('(empty)', fn(el), 'expected null');
    });
    run('number-range: in range → null', function () {
        var fn = validators['number-range'];
        if (!fn) { return ko('', '', 'number-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="number" value="5" min="1" max="10">';
        var r = fn(el);
        return r == null ? ok('5 (1–10)', 'null') : ko('5', r, 'expected null');
    });
    run('number-range: below min → error', function () {
        var fn = validators['number-range'];
        if (!fn) { return ko('', '', 'number-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="number" value="0" min="1" max="10">';
        var r = fn(el);
        return typeof r === 'string' && r.indexOf('1') !== -1 ? ok('0 < min=1', r) : ko('0', r, 'expected Mindestwert: 1');
    });
    run('number-range: above max → error', function () {
        var fn = validators['number-range'];
        if (!fn) { return ko('', '', 'number-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="number" value="11" min="1" max="10">';
        var r = fn(el);
        return typeof r === 'string' && r.indexOf('10') !== -1 ? ok('11 > max=10', r) : ko('11', r, 'expected Maximalwert: 10');
    });

    /* date-format ───────────────────────────────────────────────────────── */
    section('JS: validators — date-format');
    run('date-format: empty → null', function () {
        var fn = validators['date-format'];
        if (!fn) { return ko('', '', 'date-format not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input class="forge-date-text" value="">';
        return fn(el) == null ? ok('(empty)', 'null') : ko('(empty)', fn(el), 'expected null');
    });
    run('date-format: valid TT.MM.JJJJ → null', function () {
        var fn = validators['date-format'];
        if (!fn) { return ko('', '', 'date-format not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input class="forge-date-text" value="15.06.2024">';
        var r = fn(el);
        return r == null ? ok('15.06.2024', 'null') : ko('15.06.2024', r, 'expected null');
    });
    run('date-format: ISO format → format error', function () {
        var fn = validators['date-format'];
        if (!fn) { return ko('', '', 'date-format not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input class="forge-date-text" value="2024-06-15">';
        var r = fn(el);
        return typeof r === 'string' && r ? ok('2024-06-15', r) : ko('2024-06-15', r, 'expected format error');
    });
    run('date-format: impossible date 32.01.2024 → error', function () {
        var fn = validators['date-format'];
        if (!fn) { return ko('', '', 'date-format not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input class="forge-date-text" value="32.01.2024">';
        var r = fn(el);
        return typeof r === 'string' && r ? ok('32.01.2024', r) : ko('32.01.2024', r, 'expected invalid date error');
    });

    /* currency-range ────────────────────────────────────────────────────── */
    section('JS: validators — currency-range');
    run('currency-range: empty → null', function () {
        var fn = validators['currency-range'];
        if (!fn) { return ko('', '', 'currency-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="number" value="" min="10" max="1000">';
        return fn(el) == null ? ok('(empty)', 'null') : ko('(empty)', fn(el), 'expected null');
    });
    run('currency-range: valid → null', function () {
        var fn = validators['currency-range'];
        if (!fn) { return ko('', '', 'currency-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="number" value="100" min="10" max="1000">';
        var r = fn(el);
        return r == null ? ok('100 (10–1000)', 'null') : ko('100', r, 'expected null');
    });
    run('currency-range: below min → error', function () {
        var fn = validators['currency-range'];
        if (!fn) { return ko('', '', 'currency-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="number" value="5" min="10" max="1000">';
        var r = fn(el);
        return typeof r === 'string' && r.indexOf('10') !== -1 ? ok('5 < min=10', r) : ko('5', r, 'expected Mindestwert: 10');
    });
    run('currency-range: above max → error', function () {
        var fn = validators['currency-range'];
        if (!fn) { return ko('', '', 'currency-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="number" value="2000" min="10" max="1000">';
        var r = fn(el);
        return typeof r === 'string' && r.indexOf('1000') !== -1 ? ok('2000 > max=1000', r) : ko('2000', r, 'expected Maximalwert: 1000');
    });

    /* text-word-limit ───────────────────────────────────────────────────── */
    section('JS: validators — text-word-limit');
    run('text-word-limit: no data-word-limit attr → null', function () {
        var fn = validators['text-word-limit'];
        if (!fn) { return ko('', '', 'text-word-limit not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="text" value="too many words here">';
        return fn(el) == null ? ok('(no data-word-limit)', 'null') : ko('', fn(el), 'expected null');
    });
    run('text-word-limit: within limit → null', function () {
        var fn = validators['text-word-limit'];
        if (!fn) { return ko('', '', 'text-word-limit not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="text" value="two words" data-word-limit="5">';
        var r = fn(el);
        return r == null ? ok('"two words" limit=5', 'null') : ko('"two words"', r, 'expected null');
    });
    run('text-word-limit: over limit → error', function () {
        var fn = validators['text-word-limit'];
        if (!fn) { return ko('', '', 'text-word-limit not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="text" value="one two three four" data-word-limit="3">';
        var r = fn(el);
        return typeof r === 'string' && r ? ok('4 words limit=3', r) : ko('4 words', r, 'expected word-limit error');
    });

    /* textarea-word-limit ───────────────────────────────────────────────── */
    section('JS: validators — textarea-word-limit');
    run('textarea-word-limit: within limit → null', function () {
        var fn = validators['textarea-word-limit'];
        if (!fn) { return ko('', '', 'textarea-word-limit not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<textarea data-word-limit="5">hello world</textarea>';
        var r = fn(el);
        return r == null ? ok('"hello world" limit=5', 'null') : ko('"hello world"', r, 'expected null');
    });
    run('textarea-word-limit: over limit → error', function () {
        var fn = validators['textarea-word-limit'];
        if (!fn) { return ko('', '', 'textarea-word-limit not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<textarea data-word-limit="2">one two three</textarea>';
        var r = fn(el);
        return typeof r === 'string' && r ? ok('"one two three" limit=2', r) : ko('', r, 'expected error');
    });

    /* website-url ───────────────────────────────────────────────────────── */
    section('JS: validators — website-url');
    run('website-url: no data-validate-url attr → null', function () {
        var fn = validators['website-url'];
        if (!fn) { return ko('', '', 'website-url not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="url" value="not-a-url">';
        return fn(el) == null ? ok('(no data-validate-url)', 'null') : ko('', fn(el), 'expected null');
    });
    run('website-url: valid https → null', function () {
        var fn = validators['website-url'];
        if (!fn) { return ko('', '', 'website-url not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="url" value="https://example.de" data-validate-url="1">';
        var r = fn(el);
        return r == null ? ok('https://example.de', 'null') : ko('https://example.de', r, 'expected null');
    });
    run('website-url: invalid → error', function () {
        var fn = validators['website-url'];
        if (!fn) { return ko('', '', 'website-url not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="url" value="not-a-url" data-validate-url="1">';
        var r = fn(el);
        return typeof r === 'string' && r ? ok('not-a-url', r) : ko('not-a-url', r, 'expected url error');
    });

    /* checkbox-count ────────────────────────────────────────────────────── */
    section('JS: validators — checkbox-count');
    run('checkbox-count: no .forge-checkbox-group → null', function () {
        var fn = validators['checkbox-count'];
        if (!fn) { return ko('', '', 'checkbox-count not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="checkbox" checked>';
        return fn(el) == null ? ok('(no group)', 'null') : ko('', fn(el), 'expected null');
    });
    run('checkbox-count: no min/max → null', function () {
        var fn = validators['checkbox-count'];
        if (!fn) { return ko('', '', 'checkbox-count not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div class="forge-checkbox-group"><input type="checkbox" checked></div>';
        return fn(el) == null ? ok('(no min/max)', 'null') : ko('', fn(el), 'expected null');
    });
    run('checkbox-count: below min → error', function () {
        var fn = validators['checkbox-count'];
        if (!fn) { return ko('', '', 'checkbox-count not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div class="forge-checkbox-group" data-min-selections="2" data-max-selections="0">'
            + '<input type="checkbox" checked><input type="checkbox"></div>';
        var r = fn(el);
        return typeof r === 'string' && r.indexOf('2') !== -1 ? ok('1 checked, min=2', r) : ko('1/min=2', r, 'expected min error');
    });
    run('checkbox-count: at min → null', function () {
        var fn = validators['checkbox-count'];
        if (!fn) { return ko('', '', 'checkbox-count not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div class="forge-checkbox-group" data-min-selections="2" data-max-selections="0">'
            + '<input type="checkbox" checked><input type="checkbox" checked></div>';
        var r = fn(el);
        return r == null ? ok('2 checked, min=2', 'null') : ko('2/min=2', r, 'expected null');
    });
    run('checkbox-count: above max → error', function () {
        var fn = validators['checkbox-count'];
        if (!fn) { return ko('', '', 'checkbox-count not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div class="forge-checkbox-group" data-min-selections="0" data-max-selections="1">'
            + '<input type="checkbox" checked><input type="checkbox" checked></div>';
        var r = fn(el);
        return typeof r === 'string' && r.indexOf('1') !== -1 ? ok('2 checked, max=1', r) : ko('2/max=1', r, 'expected max error');
    });

    /* slider-range ──────────────────────────────────────────────────────── */
    section('JS: validators — slider-range');
    run('slider-range: no .forge-slider-wrap → null', function () {
        var fn = validators['slider-range'];
        if (!fn) { return ko('', '', 'slider-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input type="hidden" value="5">';
        return fn(el) == null ? ok('(no wrap)', 'null') : ko('', fn(el), 'expected null');
    });
    run('slider-range: single, in range → null', function () {
        var fn = validators['slider-range'];
        if (!fn) { return ko('', '', 'slider-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div class="forge-slider-wrap" data-min="0" data-max="100"></div>'
            + '<input type="hidden" value="50">';
        var r = fn(el);
        return r == null ? ok('50 (0–100)', 'null') : ko('50', r, 'expected null');
    });
    run('slider-range: single, below min → error', function () {
        var fn = validators['slider-range'];
        if (!fn) { return ko('', '', 'slider-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div class="forge-slider-wrap" data-min="10" data-max="100"></div>'
            + '<input type="hidden" value="5">';
        var r = fn(el);
        return typeof r === 'string' && r ? ok('5 < min=10', r) : ko('5/min=10', r, 'expected min error');
    });
    run('slider-range: range mode, valid → null', function () {
        var fn = validators['slider-range'];
        if (!fn) { return ko('', '', 'slider-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div class="forge-slider-wrap forge-slider-wrap--range" data-min="0" data-max="100">'
            + '<input class="forge-slider-input-from" value="20">'
            + '<input class="forge-slider-input-to" value="80"></div>';
        var r = fn(el);
        return r == null ? ok('from=20 to=80 (0–100)', 'null') : ko('20–80', r, 'expected null');
    });
    run('slider-range: range mode, out of bounds → error', function () {
        var fn = validators['slider-range'];
        if (!fn) { return ko('', '', 'slider-range not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div class="forge-slider-wrap forge-slider-wrap--range" data-min="0" data-max="100">'
            + '<input class="forge-slider-input-from" value="-5">'
            + '<input class="forge-slider-input-to" value="80"></div>';
        var r = fn(el);
        return typeof r === 'string' && r ? ok('from=-5 (min=0)', r) : ko('-5/0–100', r, 'expected out-of-range error');
    });

    /* sepa-bic ──────────────────────────────────────────────────────────── */
    section('JS: validators — sepa-bic');
    run('sepa-bic: empty → null', function () {
        var fn = validators['sepa-bic'];
        if (!fn) { return ko('', '', 'sepa-bic not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input class="forge-sepa-bic" value="">';
        return fn(el) == null ? ok('(empty)', 'null') : ko('(empty)', fn(el), 'expected null');
    });
    run('sepa-bic: valid 8-char BIC → null', function () {
        var fn = validators['sepa-bic'];
        if (!fn) { return ko('', '', 'sepa-bic not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div><input class="forge-sepa-bic" value="COBADEFF"><span class="forge-field-error"></span></div>';
        var r = fn(el);
        return r == null ? ok('COBADEFF', 'null') : ko('COBADEFF', r, 'expected null');
    });
    run('sepa-bic: valid 11-char BIC → null', function () {
        var fn = validators['sepa-bic'];
        if (!fn) { return ko('', '', 'sepa-bic not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div><input class="forge-sepa-bic" value="COBADEFFXXX"><span class="forge-field-error"></span></div>';
        var r = fn(el);
        return r == null ? ok('COBADEFFXXX', 'null') : ko('COBADEFFXXX', r, 'expected null');
    });
    run('sepa-bic: 7-char (INVALID) → error', function () {
        var fn = validators['sepa-bic'];
        if (!fn) { return ko('', '', 'sepa-bic not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<div><input class="forge-sepa-bic" value="INVALID"><span class="forge-field-error"></span></div>';
        var r = fn(el);
        return (r != null && r.length > 0) ? ok('INVALID (7 chars)', 'non-null') : ko('INVALID', r, 'expected BIC error');
    });

    /* sepa-required ─────────────────────────────────────────────────────── */
    section('JS: validators — sepa-required');
    run('sepa-required: data-required missing → null', function () {
        var fn = validators['sepa-required'];
        if (!fn) { return ko('', '', 'sepa-required not registered'); }
        var el = document.createElement('div');
        el.innerHTML = '<input class="forge-sepa-iban" value="">';
        return fn(el) == null ? ok('(no data-required)', 'null') : ko('', fn(el), 'expected null');
    });
    run('sepa-required: required + empty IBAN → error', function () {
        var fn = validators['sepa-required'];
        if (!fn) { return ko('', '', 'sepa-required not registered'); }
        var el = document.createElement('div');
        el.dataset.required = 'true';
        el.innerHTML = '<div><input class="forge-sepa-iban" value=""><span class="forge-field-error"></span></div>'
            + '<div><input class="forge-sepa-bic" value="COBADEFF"><span class="forge-field-error"></span></div>'
            + '<div><input class="forge-sepa-holder" value="Max"><span class="forge-field-error"></span></div>';
        var r = fn(el);
        return (r != null && r.length > 0) ? ok('required, IBAN empty', 'non-null error') : ko('IBAN empty', r, 'expected error');
    });
    run('sepa-required: required + all filled → null', function () {
        var fn = validators['sepa-required'];
        if (!fn) { return ko('', '', 'sepa-required not registered'); }
        var el = document.createElement('div');
        el.dataset.required = 'true';
        el.innerHTML = '<div><input class="forge-sepa-iban" value="DE89370400440532013000"><span class="forge-field-error"></span></div>'
            + '<div><input class="forge-sepa-bic" value="COBADEFF"><span class="forge-field-error"></span></div>'
            + '<div><input class="forge-sepa-holder" value="Max Muster"><span class="forge-field-error"></span></div>';
        var r = fn(el);
        return r == null ? ok('all filled', 'null') : ko('all filled', r, 'expected null');
    });

    /* ── ForgeFieldInits ─────────────────────────────────────────────────── */
    section('JS: ForgeFieldInits');
    var inits = window.ForgeFieldInits || {};
    run('ForgeFieldInits is an object', function () {
        return typeof inits === 'object'
            ? ok('ForgeFieldInits', Object.keys(inits).join(', '))
            : ko('', '', 'not object');
    });
    ['slider', 'rating', 'date', 'select', 'radio', 'upload', 'signature', 'sepa'].forEach(function (type) {
        run(type + ' init function registered', function () {
            return typeof inits[type] === 'function'
                ? ok(type, 'typeof function')
                : ko(type, typeof inits[type], 'expected function');
        });
    });

    /* ── validatePage (via ForgeTestHooks) ───────────────────────────────── */
    section('JS: validatePage');
    var hooks = window.ForgeTestHooks || {};
    var vp = hooks.validatePage;
    run('ForgeTestHooks.validatePage exported', function () {
        return typeof vp === 'function'
            ? ok('ForgeTestHooks', 'validatePage function')
            : ko('', '', 'not exported — check __FORGE_TEST__ hook in front.js');
    });

    if (typeof vp === 'function') {
        run('empty scope → valid', function () {
            var el = document.createElement('div');
            var r = vp(el);
            return (r.valid && !r.hasRequired && !r.hasInvalid)
                ? ok('empty scope', JSON.stringify(r))
                : ko('empty scope', JSON.stringify(r), 'expected valid=true');
        });

        run('required empty text → hasRequired', function () {
            var el = document.createElement('div');
            el.innerHTML = '<div class="forge-field forge-field--text forge-required-field">'
                + '<input type="text" value=""><span class="forge-field-error"></span></div>';
            var r = vp(el);
            return (!r.valid && r.hasRequired)
                ? ok('required empty text', JSON.stringify({ valid: r.valid, hasRequired: r.hasRequired }))
                : ko('required empty text', JSON.stringify(r), 'expected hasRequired=true');
        });

        run('required filled text → valid', function () {
            var el = document.createElement('div');
            el.innerHTML = '<div class="forge-field forge-field--text forge-required-field">'
                + '<input type="text" value="hello"><span class="forge-field-error"></span></div>';
            var r = vp(el);
            return r.valid
                ? ok('"hello" required text', JSON.stringify({ valid: r.valid }))
                : ko('"hello" required text', JSON.stringify(r), 'expected valid=true');
        });

        run('html field skipped (in ForgeSkipValidation)', function () {
            var el = document.createElement('div');
            el.innerHTML = '<div class="forge-field forge-field--html forge-required-field">'
                + '<input type="text" value=""><span class="forge-field-error"></span></div>';
            var r = vp(el);
            return r.valid
                ? ok('required html (skipped)', JSON.stringify({ valid: r.valid }))
                : ko('required html (skipped)', JSON.stringify(r), 'html field should be skipped');
        });

        run('conditionally hidden field → skipped', function () {
            var el = document.createElement('div');
            el.innerHTML = '<div data-conditions=\'{"rules":[]}\' style="display:none">'
                + '<div class="forge-field forge-field--text forge-required-field">'
                + '<input type="text" value=""><span class="forge-field-error"></span></div></div>';
            var r = vp(el);
            return r.valid
                ? ok('hidden required field', JSON.stringify({ valid: r.valid }))
                : ko('hidden required field', JSON.stringify(r), 'hidden field should be skipped');
        });

        run('invalid email format → hasInvalid', function () {
            var fn = validators['email'];
            if (!fn) { return ok('(skipped — email validator not registered)', ''); }
            var el = document.createElement('div');
            el.innerHTML = '<div class="forge-field forge-field--email" data-validate=\'["email"]\'>'
                + '<input type="email" value="not-valid"><span class="forge-field-error"></span></div>';
            var r = vp(el);
            return (!r.valid && r.hasInvalid)
                ? ok('not-valid', JSON.stringify({ valid: r.valid, hasInvalid: r.hasInvalid }))
                : ko('not-valid', JSON.stringify(r), 'expected hasInvalid=true');
        });

        run('per-sub-input required check (composite field)', function () {
            var el = document.createElement('div');
            el.innerHTML = '<div class="forge-field forge-field--name">'
                + '<input type="text" value="Hans">'
                + '<input type="text" required value="">'
                + '<span class="forge-field-error"></span></div>';
            var r = vp(el);
            return (!r.valid && r.hasRequired)
                ? ok('one sub-input required+empty', JSON.stringify({ valid: r.valid, hasRequired: r.hasRequired }))
                : ko('one sub-input required+empty', JSON.stringify(r), 'expected hasRequired=true');
        });

        run('per-sub-input required check all filled → valid', function () {
            var el = document.createElement('div');
            el.innerHTML = '<div class="forge-field forge-field--name">'
                + '<input type="text" required value="Hans">'
                + '<input type="text" required value="Müller">'
                + '<span class="forge-field-error"></span></div>';
            var r = vp(el);
            return r.valid
                ? ok('all sub-inputs filled', JSON.stringify({ valid: r.valid }))
                : ko('all sub-inputs filled', JSON.stringify(r), 'expected valid=true');
        });

        run('unregistered validation rule is silently skipped', function () {
            var el = document.createElement('div');
            el.innerHTML = '<div class="forge-field forge-field--text" data-validate=\'["not-a-real-rule"]\'>'
                + '<input type="text" value="anything"><span class="forge-field-error"></span></div>';
            var r = vp(el);
            return r.valid
                ? ok('unknown rule "not-a-real-rule"', JSON.stringify({ valid: r.valid }))
                : ko('unknown rule', JSON.stringify(r), 'expected valid=true (rule silently ignored)');
        });

        run('multiple validation rules — second rule catches error', function () {
            var fn = validators['email'];
            if (!fn) { return ok('(skipped — email validator not registered)', ''); }
            var el = document.createElement('div');
            el.innerHTML = '<div class="forge-field forge-field--email" data-validate=\'["not-a-real-rule","email"]\'>'
                + '<input type="email" value="not-valid"><span class="forge-field-error"></span></div>';
            var r = vp(el);
            return (!r.valid && r.hasInvalid)
                ? ok('rules: [unknown, email]', JSON.stringify({ valid: r.valid, hasInvalid: r.hasInvalid }))
                : ko('rules: [unknown, email]', JSON.stringify(r), 'expected hasInvalid=true from second rule');
        });
    }

    /* ── Condition operators (via initConditions DOM) ─────────────────────── */
    section('JS: condition operators (via initConditions)');
    var ic = hooks.initConditions;
    run('ForgeTestHooks.initConditions exported', function () {
        return typeof ic === 'function'
            ? ok('ForgeTestHooks', 'initConditions function')
            : ko('', '', 'not exported — check __FORGE_TEST__ hook in front.js');
    });

    if (typeof ic === 'function') {
        function condTest(label, inputVal, op, condVal, expectVisible) {
            return function () {
                var wrap = document.createElement('div');
                var cond = JSON.stringify({
                    action: 'show', match: 'all',
                    rules: [{ field_id: 'ctrl', operator: op, value: condVal }]
                });
                wrap.innerHTML = '<form class="forge-form">'
                    + '<input name="ctrl" type="text">'
                    + '<div class="forge-field" data-conditions=\'' + cond + '\'>'
                    + '<span>target</span></div></form>';
                document.body.appendChild(wrap);
                ic(wrap);
                var ctrl = wrap.querySelector('input[name="ctrl"]');
                ctrl.value = inputVal;
                ctrl.dispatchEvent(new Event('change', { bubbles: true }));
                var target = wrap.querySelector('[data-conditions]');
                var visible = target.style.display !== 'none';
                document.body.removeChild(wrap);
                var inp = 'ctrl="' + inputVal + '" op=' + op + ' cond="' + condVal + '"';
                var out = visible ? 'visible' : 'hidden';
                return visible === expectVisible
                    ? ok(inp, out)
                    : ko(inp, out, 'expected ' + (expectVisible ? 'visible' : 'hidden'));
            };
        }

        run('equals: matching value → show',          condTest('equals match',    'hello', 'equals',      'hello', true));
        run('equals: non-matching value → hide',       condTest('equals no match', 'world', 'equals',      'hello', false));
        run('not_equals: different value → show',      condTest('not_eq pass',    'world', 'not_equals',   'hello', true));
        run('not_equals: same value → hide',           condTest('not_eq fail',    'hello', 'not_equals',   'hello', false));
        run('contains: substring present → show',      condTest('contains pass',  'hello world', 'contains', 'hello', true));
        run('contains: substring absent → hide',       condTest('contains fail',  'goodbye', 'contains',   'hello', false));
        run('not_contains: absent → show',             condTest('ncontains pass', 'goodbye', 'not_contains', 'hello', true));
        run('not_contains: present → hide',            condTest('ncontains fail', 'hello world', 'not_contains', 'hello', false));
        run('empty: empty value → show',               condTest('empty pass',     '', 'empty',      '', true));
        run('empty: non-empty value → hide',           condTest('empty fail',     'text', 'empty',  '', false));
        run('not_empty: non-empty → show',             condTest('nempty pass',    'text', 'not_empty', '', true));
        run('not_empty: empty → hide',                 condTest('nempty fail',    '', 'not_empty',   '', false));
        run('greater: 10 > 5 → show',                 condTest('greater pass',   '10', 'greater', '5', true));
        run('greater: 3 > 5 → hide',                  condTest('greater fail',   '3',  'greater', '5', false));
        run('less: 3 < 5 → show',                     condTest('less pass',      '3',  'less',    '5', true));
        run('less: 10 < 5 → hide',                    condTest('less fail',      '10', 'less',    '5', false));

        run('action=hide: matching value → hidden', function () {
            var wrap = document.createElement('div');
            var cond = JSON.stringify({
                action: 'hide', match: 'all',
                rules: [{ field_id: 'ctrl', operator: 'equals', value: 'hello' }]
            });
            wrap.innerHTML = '<form class="forge-form">'
                + '<input name="ctrl" type="text">'
                + '<div class="forge-field" data-conditions=\'' + cond + '\'>'
                + '<span>target</span></div></form>';
            document.body.appendChild(wrap);
            ic(wrap);
            var ctrl = wrap.querySelector('input[name="ctrl"]');
            ctrl.value = 'hello';
            ctrl.dispatchEvent(new Event('change', { bubbles: true }));
            var visible = wrap.querySelector('[data-conditions]').style.display !== 'none';
            document.body.removeChild(wrap);
            return !visible
                ? ok('action=hide, ctrl="hello" matches', 'hidden')
                : ko('action=hide, ctrl="hello" matches', 'visible', 'expected hidden when hide-rule matches');
        });

        run('match=any: one of two rules passes → show', function () {
            var wrap = document.createElement('div');
            var cond = JSON.stringify({
                action: 'show', match: 'any',
                rules: [
                    { field_id: 'ctrl', operator: 'equals', value: 'nomatch' },
                    { field_id: 'ctrl', operator: 'equals', value: 'hello' },
                ]
            });
            wrap.innerHTML = '<form class="forge-form">'
                + '<input name="ctrl" type="text">'
                + '<div class="forge-field" data-conditions=\'' + cond + '\'>'
                + '<span>target</span></div></form>';
            document.body.appendChild(wrap);
            ic(wrap);
            var ctrl = wrap.querySelector('input[name="ctrl"]');
            ctrl.value = 'hello';
            ctrl.dispatchEvent(new Event('change', { bubbles: true }));
            var visible = wrap.querySelector('[data-conditions]').style.display !== 'none';
            document.body.removeChild(wrap);
            return visible
                ? ok('match=any, second rule matches', 'visible')
                : ko('match=any, second rule matches', 'hidden', 'expected visible (any rule matching is enough)');
        });

        run('match=all: one of two rules fails → hide', function () {
            var wrap = document.createElement('div');
            var cond = JSON.stringify({
                action: 'show', match: 'all',
                rules: [
                    { field_id: 'ctrl', operator: 'equals', value: 'nomatch' },
                    { field_id: 'ctrl', operator: 'equals', value: 'hello' },
                ]
            });
            wrap.innerHTML = '<form class="forge-form">'
                + '<input name="ctrl" type="text">'
                + '<div class="forge-field" data-conditions=\'' + cond + '\'>'
                + '<span>target</span></div></form>';
            document.body.appendChild(wrap);
            ic(wrap);
            var ctrl = wrap.querySelector('input[name="ctrl"]');
            ctrl.value = 'hello';
            ctrl.dispatchEvent(new Event('change', { bubbles: true }));
            var visible = wrap.querySelector('[data-conditions]').style.display !== 'none';
            document.body.removeChild(wrap);
            return !visible
                ? ok('match=all, one rule fails', 'hidden')
                : ko('match=all, one rule fails', 'visible', 'expected hidden (all rules must match)');
        });

        run('checkbox group value: equals matches one checked box → show', function () {
            var wrap = document.createElement('div');
            var cond = JSON.stringify({
                action: 'show', match: 'all',
                rules: [{ field_id: 'ctrl', operator: 'equals', value: 'b' }]
            });
            wrap.innerHTML = '<form class="forge-form">'
                + '<input name="ctrl" type="checkbox" value="a">'
                + '<input name="ctrl" type="checkbox" value="b">'
                + '<div class="forge-field" data-conditions=\'' + cond + '\'>'
                + '<span>target</span></div></form>';
            document.body.appendChild(wrap);
            ic(wrap);
            var boxes = wrap.querySelectorAll('input[name="ctrl"]');
            boxes[1].checked = true;
            boxes[1].dispatchEvent(new Event('change', { bubbles: true }));
            var visible = wrap.querySelector('[data-conditions]').style.display !== 'none';
            document.body.removeChild(wrap);
            return visible
                ? ok('checkbox "b" checked, cond=b', 'visible')
                : ko('checkbox "b" checked, cond=b', 'hidden', 'expected visible — array value should match "equals"');
        });

        run('checkbox group value: contains checks any checked box → show', function () {
            var wrap = document.createElement('div');
            var cond = JSON.stringify({
                action: 'show', match: 'all',
                rules: [{ field_id: 'ctrl', operator: 'contains', value: 'b' }]
            });
            wrap.innerHTML = '<form class="forge-form">'
                + '<input name="ctrl" type="checkbox" value="a">'
                + '<input name="ctrl" type="checkbox" value="ab">'
                + '<div class="forge-field" data-conditions=\'' + cond + '\'>'
                + '<span>target</span></div></form>';
            document.body.appendChild(wrap);
            ic(wrap);
            var boxes = wrap.querySelectorAll('input[name="ctrl"]');
            boxes[1].checked = true;
            boxes[1].dispatchEvent(new Event('change', { bubbles: true }));
            var visible = wrap.querySelector('[data-conditions]').style.display !== 'none';
            document.body.removeChild(wrap);
            return visible
                ? ok('checkbox "ab" checked, cond contains "b"', 'visible')
                : ko('checkbox "ab" checked, cond contains "b"', 'hidden', 'expected visible');
        });

        run('checkbox group value: none checked → empty → show', function () {
            var wrap = document.createElement('div');
            var cond = JSON.stringify({
                action: 'show', match: 'all',
                rules: [{ field_id: 'ctrl', operator: 'empty', value: '' }]
            });
            wrap.innerHTML = '<form class="forge-form">'
                + '<input name="ctrl" type="checkbox" value="a">'
                + '<input name="ctrl" type="checkbox" value="b">'
                + '<div class="forge-field" data-conditions=\'' + cond + '\'>'
                + '<span>target</span></div></form>';
            document.body.appendChild(wrap);
            ic(wrap);
            var boxes = wrap.querySelectorAll('input[name="ctrl"]');
            boxes[0].dispatchEvent(new Event('change', { bubbles: true }));
            var visible = wrap.querySelector('[data-conditions]').style.display !== 'none';
            document.body.removeChild(wrap);
            return visible
                ? ok('no checkboxes checked, cond=empty', 'visible')
                : ko('no checkboxes checked, cond=empty', 'hidden', 'expected visible — [] should count as empty');
        });

        run('select multiple value: equals matches one selected option → show', function () {
            var wrap = document.createElement('div');
            var cond = JSON.stringify({
                action: 'show', match: 'all',
                rules: [{ field_id: 'ctrl', operator: 'equals', value: 'y' }]
            });
            wrap.innerHTML = '<form class="forge-form">'
                + '<select name="ctrl" multiple>'
                + '<option value="x">X</option><option value="y">Y</option></select>'
                + '<div class="forge-field" data-conditions=\'' + cond + '\'>'
                + '<span>target</span></div></form>';
            document.body.appendChild(wrap);
            ic(wrap);
            var sel = wrap.querySelector('select[name="ctrl"]');
            sel.options[1].selected = true;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            var visible = wrap.querySelector('[data-conditions]').style.display !== 'none';
            document.body.removeChild(wrap);
            return visible
                ? ok('multi-select "y" selected, cond=y', 'visible')
                : ko('multi-select "y" selected, cond=y', 'hidden', 'expected visible');
        });

        run('input nested inside a hidden conditional ancestor counts as absent', function () {
            var wrap = document.createElement('div');
            /* Inner wrapper's own condition always evaluates false (gate field never
               equals "impossible"), so initConditions genuinely computes it as hidden —
               not a hardcoded inline style that init would immediately overwrite. */
            var innerCond = JSON.stringify({
                action: 'show', match: 'all',
                rules: [{ field_id: 'gate', operator: 'equals', value: 'impossible' }]
            });
            var outerCond = JSON.stringify({
                action: 'show', match: 'all',
                rules: [{ field_id: 'ctrl', operator: 'not_empty', value: '' }]
            });
            wrap.innerHTML = '<form class="forge-form">'
                + '<input name="gate" type="text" value="">'
                + '<div data-conditions=\'' + innerCond + '\'>'
                + '<input name="ctrl" type="text" value="hello"></div>'
                + '<div class="forge-field" data-conditions=\'' + outerCond + '\'>'
                + '<span>target</span></div></form>';
            document.body.appendChild(wrap);
            ic(wrap);
            var innerWrap = wrap.querySelector('div[data-conditions]:not(.forge-field)');
            if (innerWrap.style.display !== 'none') {
                document.body.removeChild(wrap);
                return ko('setup', 'inner wrap visible', 'test setup broken: inner wrapper should already be hidden');
            }
            var visible = wrap.querySelector('.forge-field[data-conditions]').style.display !== 'none';
            document.body.removeChild(wrap);
            return !visible
                ? ok('ctrl hidden inside hidden ancestor, cond=not_empty', 'hidden')
                : ko('ctrl hidden inside hidden ancestor, cond=not_empty', 'visible', 'expected hidden — hidden-ancestor input should count as absent (empty)');
        });
    }

    /* ── Render results ──────────────────────────────────────────────────── */
    var container = document.getElementById('forge-js-tests');
    if (!container) { return; }
    var total = pass + fail;
    var badge = document.getElementById('forge-js-tab-badge');
    if (badge) {
        badge.className = 'forge-tab-badge ' + (fail === 0 ? 'forge-tab-badge--pass' : 'forge-tab-badge--fail');
        badge.textContent = fail === 0 ? '✓ ' + total : '✗ ' + fail + '/' + total;
    }
    container.innerHTML = '<table class="forge-test-table">'
        + '<colgroup><col style="width:24px"><col style="width:26%"><col style="width:20%"><col style="width:28%"><col></colgroup>'
        + '<thead><tr>'
        + '<th></th>'
        + '<th>Test</th>'
        + '<th>Input</th>'
        + '<th>Output</th>'
        + '<th>Note</th>'
        + '</tr></thead>'
        + '<tbody>' + rows.join('') + '</tbody></table>';
    if (window.forgeCollapseSections) {
        window.forgeCollapseSections(container.querySelector('table'));
    }
}());
JS;
    }

    private static function renderJsTests(): void
    {
        $globals = self::generateFrontGlobals();
        $frontUrl = esc_url(FORGE_FORMS_URL . 'assets/js/front.js');

        echo '<div id="forge-js-tests" style="color:#555;font-style:italic;">Running JS tests…</div>';
        echo '<script>' . $globals . '</script>';
        echo '<script src="' . $frontUrl . '"></script>';
        /* Collapse helper must be defined BEFORE the harness script that calls it */
        echo <<<'JS'
<script>
window.forgeCollapseSections = function (table) {
    if (!table) { return; }
    table.querySelectorAll('tr.ff-section').forEach(function (sec) {
        var bodyRows = [];
        var hasFail  = false;
        var cur = sec.nextElementSibling;
        while (cur && !cur.classList.contains('ff-section')) {
            bodyRows.push(cur);
            if (cur.classList.contains('ff-fail')) { hasFail = true; }
            cur = cur.nextElementSibling;
        }
        if (!bodyRows.length) { return; }

        var passCount = bodyRows.filter(function (r) { return r.classList.contains('ff-pass'); }).length;
        var td = sec.querySelector('td');

        var arrow = document.createElement('span');
        arrow.style.cssText = 'display:inline-block;width:1.4em;font-style:normal;';
        td.insertBefore(arrow, td.firstChild);

        var count = document.createElement('span');
        count.style.cssText = 'color:#888;font-weight:normal;margin-left:8px;font-size:11px;';
        count.textContent = '(' + passCount + '/' + bodyRows.length + ')';
        td.appendChild(count);

        sec.style.cursor = 'pointer';
        sec.title = 'Click to toggle';

        function doCollapse() {
            bodyRows.forEach(function (r) { r.style.display = 'none'; });
            arrow.textContent = '▶';
        }
        function doExpand() {
            bodyRows.forEach(function (r) { r.style.display = ''; });
            arrow.textContent = '▼';
        }

        sec.addEventListener('click', function () {
            if (bodyRows[0].style.display === 'none') { doExpand(); } else { doCollapse(); }
        });

        if (hasFail) { doExpand(); } else { doCollapse(); }
    });
};
window.forgeCollapseSections(document.getElementById('forge-php-tests'));
</script>
JS;
        echo '<script>' . self::jsTestHarness() . '</script>';
    }

    // ── page output ──────────────────────────────────────────────────────────

    /**
     * Runs every PHP test suite, then outputs the tabbed PHP/JS results page.
     * The JS panel embeds the same field-handler validators/inits used on the
     * live front end (see generateFrontGlobals()) and runs its own suite client-side.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // reset
        self::$pass           = 0;
        self::$fail           = 0;
        self::$log            = [];
        self::$failLines      = [];
        self::$currentSection = '';
        self::$lastIn         = '';
        self::$lastOut        = '';

        // run all suites
        self::testText();
        self::testTextarea();
        self::testEmail();
        self::testName();
        self::testPhone();
        self::testNumber();
        self::testAddress();
        self::testDate();
        self::testTime();
        self::testCurrency();
        self::testSelect();
        self::testRadio();
        self::testCheckbox();
        self::testUpload();
        self::testSignature();
        self::testRating();
        self::testSlider();
        self::testCaptcha();
        self::testConsent();
        self::testGdpr();
        self::testHtml();
        self::testGroup();
        self::testPageBreak();
        self::testPostData();
        self::testWebsite();
        self::testSepa();
        self::testRegistryCoverage();

        $total    = self::$pass + self::$fail;
        $allOk    = self::$fail === 0;


        echo '<style>
            #forge-panel-php, #forge-panel-js { font-family: monospace; }
            .forge-test-topbar {
                display: flex;
                align-items: center;
                gap: 20px;
                padding: 10px 0 0;
                border-bottom: 2px solid #dcdcde;
                flex-shrink: 0;
            }
            .forge-test-title {
                margin: 0;
                padding: 0 0 10px;
                font-size: 16px;
                font-weight: 700;
                color: #1d2327;
                white-space: nowrap;
                flex-shrink: 0;
            }
            .forge-test-tabs { display: flex; gap: 0; margin-bottom: -2px; }
            .forge-test-tab {
                padding: 8px 22px;
                cursor: pointer;
                font-size: 13px;
                font-weight: 600;
                border: 1px solid transparent;
                border-bottom: none;
                border-radius: 3px 3px 0 0;
                background: transparent;
                color: #50575e;
                user-select: none;
            }
            .forge-test-tab:hover { background: rgba(0,0,0,.06); }
            .forge-test-tab.active { background: #fff; color: #1d2327; border-color: #dcdcde; }
            .forge-test-tab .forge-tab-badge {
                display: inline-block;
                margin-left: 6px;
                padding: 1px 6px;
                border-radius: 10px;
                font-size: 11px;
                font-weight: bold;
            }
            .forge-tab-badge--pass { background: #d1e7dd; color: #0a3622; }
            .forge-tab-badge--fail { background: #f8d7da; color: #58151c; }
            .forge-tab-badge--loading { background: #e2e3e5; color: #41464b; }
            .forge-test-panel { display: none; padding-top: 16px; }
            .forge-test-panel.active { display: block; }
            .forge-test-table {
                border-collapse: collapse;
                width: 100%;
                font-size: 13px;
                table-layout: fixed;
            }
            .forge-test-table th {
                text-align: left;
                padding: 6px 10px;
                background: #f0f0f1;
                border-bottom: 2px solid #dcdcde;
            }
            .ff-pass td { color: #1a7a3c; padding: 4px 10px; border-bottom: 1px solid #f0f0f1; vertical-align: top; }
            .ff-fail td { color: #8a1a24; background: #fff5f5; padding: 4px 10px; border-bottom: 1px solid #f1aeb5; font-weight: bold; vertical-align: top; }
            .ff-section td { background: #f0f0f1; padding: 8px 10px 4px; font-size: 12px; text-transform: uppercase; letter-spacing: .05em; border-bottom: 2px solid #dcdcde; }
            .ff-io { font-family: monospace; font-size: 11px; color: #50575e; word-break: break-word; white-space: pre-wrap; font-weight: normal; }
            .ff-out { color: #2271b1; }
            .ff-fail .ff-io { color: #8a1a24; }
            .ff-fail .ff-out { color: #a21e2b; }
        </style>';

        echo '<div class="wrap forge-list-wrap">';
        echo '<hr class="wp-header-end" style="display:none">';

        $phpBadge = '<span class="forge-tab-badge ' . ($allOk ? 'forge-tab-badge--pass' : 'forge-tab-badge--fail') . '">'
            . ($allOk ? '✓ ' . $total : '✗ ' . self::$fail . '/' . $total) . '</span>';

        echo '<div class="forge-test-topbar">';
        echo '<h1 class="forge-test-title">Field Tests</h1>';
        echo '<div class="forge-test-tabs">';
        echo '<div class="forge-test-tab active" data-panel="forge-panel-php">PHP' . $phpBadge . '</div>';
        echo '<div class="forge-test-tab" data-panel="forge-panel-js">JS <span id="forge-js-tab-badge" class="forge-tab-badge forge-tab-badge--loading">…</span></div>';
        echo '</div>';
        echo '</div>';

        // PHP panel
        echo '<div id="forge-panel-php" class="forge-test-panel active">';
        if (!$allOk) {
            $copyText = esc_js(implode("\n", self::$failLines));
            echo '<div style="margin-bottom:10px;">';
            echo '<button onclick="navigator.clipboard.writeText(\'' . $copyText . '\').then(function(){this.textContent=\'Copied!\';}.bind(this))" style="cursor:pointer;padding:6px 14px;font-size:13px;">📋 Copy failures</button>';
            echo '</div>';
        }
        echo '<table id="forge-php-tests" class="forge-test-table">';
        echo '<colgroup>'
            . '<col style="width:24px">'
            . '<col style="width:26%">'
            . '<col style="width:20%">'
            . '<col style="width:28%">'
            . '<col>'
            . '</colgroup>';
        echo '<thead><tr>'
            . '<th></th>'
            . '<th>Test</th>'
            . '<th>Input</th>'
            . '<th>Output</th>'
            . '<th>Note</th>'
            . '</tr></thead>';
        echo '<tbody>';
        echo implode('', self::$log);
        echo '</tbody></table>';
        echo '</div>';

        // JS panel
        echo '<div id="forge-panel-js" class="forge-test-panel">';
        self::renderJsTests();
        echo '</div>';

        echo '<script>
(function () {
    document.querySelectorAll(".forge-test-tab").forEach(function (tab) {
        tab.addEventListener("click", function () {
            var panel = document.getElementById(tab.dataset.panel);
            if (!panel) { return; }
            document.querySelectorAll(".forge-test-tab").forEach(function (t) { t.classList.remove("active"); });
            document.querySelectorAll(".forge-test-panel").forEach(function (p) { p.classList.remove("active"); });
            tab.classList.add("active");
            panel.classList.add("active");
        });
    });
}());
</script>';

        echo '</div>';
    }
}
