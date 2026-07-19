<?php

/**
 * Registry of all available form field types.
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

namespace ForgeForms\Fields;

defined('ABSPATH') || exit;

/**
 * Registry of all available form field types.
 */
class FieldRegistry
{
    /**
     * Map of field type slugs to their handler class names.
     *
     * @var array<string, class-string<BaseField>>
     */
    private static array $types = [];

    /**
     * Registers a field type with its handler class.
     *
     * @param string $type  Field type slug.
     * @param string $class Fully-qualified class name.
     *
     * @return void
     */
    public static function register(string $type, string $class): void
    {
        self::$types[$type] = $class;
    }

    /**
     * Returns a new instance of the handler for the given field type.
     *
     * @param string $type Field type slug.
     *
     * @return BaseField|null Handler instance, or null if the type is unknown.
     */
    public static function get(string $type): ?BaseField
    {
        $class = self::$types[$type] ?? null;
        if (!$class || !class_exists($class)) {
            return null;
        }
        return new $class();
    }

    /**
     * Returns all registered field types as a slug-to-class map.
     *
     * @return array<string, class-string<BaseField>>
     */
    public static function all(): array
    {
        return self::$types;
    }

    /**
     * Returns true if the given field type slug is registered.
     *
     * @param string $type Field type slug.
     *
     * @return bool
     */
    public static function hasType(string $type): bool
    {
        return isset(self::$types[$type]);
    }

    /**
     * Registers all built-in field types.
     *
     * @return void
     */
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
     *
     * Shape: [ { label, items: [ { type, label, icon, defaults, schema } ] } ]
     * Order is explicit — most-used fields first.
     *
     * @return array
     */
    public static function paletteGroups(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $order = [
            __('Input', 'form-forge')    => [
                'color' => '#82CAFA',
                'types' => ['text', 'textarea', 'email', 'phone', 'number', 'website'],
            ],
            __('Choice', 'form-forge')    => ['color' => '#A0D468', 'types' => ['select', 'radio', 'checkbox']],
            __('Personal', 'form-forge') => ['color' => '#FFB347', 'types' => ['name', 'address', 'date', 'time']],
            __('Advanced', 'form-forge')  => [
                'color' => '#CBA0E6',
                'types' => ['currency', 'rating', 'slider', 'upload', 'signature', 'sepa'],
            ],
            __('Layout', 'form-forge')     => ['color' => '#6ED5C4', 'types' => ['html', 'group', 'pagebreak']],
            __('System', 'form-forge')     => ['color' => '#F28C8C', 'types' => ['consent', 'gdpr', 'captcha', 'postdata']],
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
        $cache = $groups;
        return $cache;
    }

    /**
     * Maps raw form submission values to a normalized array for PDF/email.
     *
     * Iterates form fields, calls each handler's mapNormalized(), and merges
     * all returned entries. Fields that produce no output (pagebreak, empty html)
     * return an empty array and are silently skipped.
     *
     * @param array $fields     Form field configuration array.
     * @param array $raw_values Raw submitted POST values.
     * @param array $files      Uploaded files ($_FILES).
     *
     * @return array Normalized mapped values.
     */
    public static function mapSubmission(
        array $fields,
        array $raw_values,
        array $files = []
    ): array {
        $mapped  = [];
        $context = ['files' => $files, 'raw_values' => $raw_values];

        foreach ($fields as $field_cfg) {
            $field_id   = $field_cfg['id']   ?? '';
            $field_type = $field_cfg['type'] ?? '';
            $label      = $field_cfg['label'] ?? $field_id;

            if (!$field_id || !$field_type) {
                continue;
            }

            $handler = self::get($field_type);
            if (!$handler) {
                continue;
            }

            $value   = $raw_values[$field_id] ?? null;
            $entries = $handler->mapNormalized(
                $field_id,
                $label,
                $value,
                $field_cfg,
                $context
            );
            foreach ($entries as $key => $entry) {
                $mapped[$key] = $entry;
            }
        }

        return $mapped;
    }
}
