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

class FieldRegistry
{
    /** @var array<string, class-string<BaseField>> */
    private static array $types = [];

    public static function register(string $type, string $class): void
    {
        self::$types[$type] = $class;
    }

    public static function get(string $type): ?BaseField
    {
        $class = self::$types[$type] ?? null;
        if (!$class || !class_exists($class)) {
            return null;
        }
        return new $class();
    }

    /** @return array<string, class-string<BaseField>> */
    public static function all(): array
    {
        return self::$types;
    }

    public static function hasType(string $type): bool
    {
        return isset(self::$types[$type]);
    }

    public static function registerDefaults(): void
    {
        /* ── ADD NEW FIELDS HERE ─────────────────────────────────────────
         * 1. Add  'your-type' => YourField::class  to this map.
         * 2. Add the type string to the palette group you want below in
         *    paletteGroups() — that controls where it appears in the builder.
         * See _ExampleField.php for a full walkthrough of what a field class
         * must and can implement.
         * ──────────────────────────────────────────────────────────────── */
        $map = [
            'text'        => TextField::class,
            'textarea'    => TextareaField::class,
            'email'       => EmailField::class,
            'name'        => NameField::class,
            'phone'       => PhoneField::class,
            'number'      => NumberField::class,
            'address'     => AddressField::class,
            'date'        => DateField::class,
            'time'        => TimeField::class,
            'currency'    => CurrencyField::class,
            'select'      => SelectField::class,
            'radio'       => RadioField::class,
            'checkbox'    => CheckboxField::class,
            'upload'      => UploadField::class,
            'signature'   => SignatureField::class,
            'rating'      => RatingField::class,
            'slider'      => SliderField::class,
            'captcha'     => CaptchaField::class,
            'consent'     => ConsentField::class,
            'gdpr'        => GdprField::class,
            'html'        => HtmlField::class,
            'group'       => GroupField::class,
            'pagebreak'   => PageBreakField::class,
            'postdata'    => PostDataField::class,
            'website'     => WebsiteField::class,
            'sepa'        => SepaField::class,
        ];

        foreach ($map as $type => $class) {
            self::register($type, $class);
        }
    }

    /**
     * Returns palette data as an array of groups for the builder UI.
     * Shape: [ { label, items: [ { type, label, icon, defaults, schema } ] } ]
     * Order is explicit — most-used fields first.
     */
    public static function paletteGroups(): array
    {
        $order = [
            'Eingabe'    => [
                'color' => '#82CAFA',
                'types' => ['text', 'textarea', 'email', 'phone', 'number', 'website'],
            ],
            'Auswahl'    => ['color' => '#A0D468', 'types' => ['select', 'radio', 'checkbox']],
            'Persönlich' => ['color' => '#FFB347', 'types' => ['name', 'address', 'date', 'time']],
            'Erweitert'  => [
                'color' => '#CBA0E6',
                'types' => ['currency', 'rating', 'slider', 'upload', 'signature', 'sepa'],
            ],
            'Layout'     => ['color' => '#6ED5C4', 'types' => ['html', 'group', 'pagebreak']],
            'System'     => ['color' => '#F28C8C', 'types' => ['consent', 'gdpr', 'captcha', 'postdata']],
        ];

        $groups = [];
        foreach ($order as $label => $def) {
            $items = [];
            foreach ($def['types'] as $type) {
                $class = self::$types[$type] ?? null;
                if (!$class) {
                    continue;
                }
                $obj     = new $class();
                $items[] = [
                    'type'           => $type,
                    'label'          => $obj->getLabel(),
                    'icon'           => $obj->getIcon(),
                    'defaults'       => $obj->getDefaultConfig(),
                    'generalSchema'  => $obj->getGeneralSchema(),
                    'advancedSchema' => $obj->getAdvancedSchema(),
                    'noPanel'        => !$obj->hasSettingsPanel(),
                    'noRequired'     => !$obj->hasRequired(),
                ];
            }
            if ($items) {
                $groups[] = ['label' => $label, 'color' => $def['color'], 'items' => $items];
            }
        }
        return $groups;
    }
}
