<?php

/**
 * @package   FormForge
 * @copyright 2026 Alexander Jorek
 * @license   GPL-2.0-or-later
 */

namespace ForgeForms\Form;

defined('ABSPATH') || exit;

class FormSelectModel
{
    private static string $option = 'forge_form_selects';

    public int    $id    = 0;
    public string $title = '';
    /** @var array<int,array{form_id:int,label:string,description:string,favorite:bool}> */
    public array $items = [];

    public static function getAll(): array
    {
        return array_map([self::class, 'fromArray'], self::getRaw());
    }

    public static function get(int $id): ?self
    {
        foreach (self::getRaw() as $record) {
            if ((int) ($record['id'] ?? 0) === $id) {
                return self::fromArray($record);
            }
        }
        return null;
    }

    public static function save(array $data, int $id = 0): int
    {
        $all   = self::getRaw();
        $title = sanitize_text_field($data['title'] ?? '');
        if ($title === '') {
            $title = 'Formular-Auswahl';
        }

        $items = [];
        foreach ((array) ($data['items'] ?? []) as $item) {
            $form_id = (int) ($item['form_id'] ?? 0);
            if ($form_id <= 0) {
                continue;
            }
            $items[] = [
                'form_id'     => $form_id,
                'label'       => sanitize_text_field($item['label'] ?? ''),
                'description' => sanitize_text_field($item['description'] ?? ''),
                'favorite'    => !empty($item['favorite']),
            ];
        }

        if ($id > 0) {
            foreach ($all as &$record) {
                if ((int) ($record['id'] ?? 0) === $id) {
                    $record['title'] = $title;
                    $record['items'] = $items;
                    update_option(self::$option, $all, false);
                    return $id;
                }
            }
            unset($record);
        }

        $new_id = self::nextId($all);
        $all[]  = ['id' => $new_id, 'title' => $title, 'items' => $items];
        update_option(self::$option, $all, false);
        return $new_id;
    }

    public static function delete(int $id): void
    {
        $all = array_values(array_filter(
            self::getRaw(),
            static fn($r) => (int) ($r['id'] ?? 0) !== $id
        ));
        update_option(self::$option, $all, false);
    }

    private static function getRaw(): array
    {
        $raw = get_option(self::$option, []);
        return is_array($raw) ? $raw : [];
    }

    private static function fromArray(array $data): self
    {
        $obj              = new self();
        $obj->id          = (int) ($data['id'] ?? 0);
        $obj->title       = (string) ($data['title'] ?? '');
        $obj->items       = array_map(
            static fn($item) => [
                'form_id'     => (int) ($item['form_id'] ?? 0),
                'label'       => (string) ($item['label'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'favorite'    => (bool) ($item['favorite'] ?? false),
            ],
            (array) ($data['items'] ?? [])
        );
        return $obj;
    }

    private static function nextId(array $all): int
    {
        $max = 0;
        foreach ($all as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id > $max) {
                $max = $id;
            }
        }
        return $max + 1;
    }
}
