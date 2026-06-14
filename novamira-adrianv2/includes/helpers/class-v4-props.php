<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * V4_Props — typed prop builder for Elementor 4.0 atomic elements.
 *
 * Wraps and unwraps Elementor 4.0 typed prop values ($$type system).
 * MCP abilities accept simple flat values from AI agents; this class converts
 * them to/from the $$type format that Elementor's atomic engine requires.
 *
 * Key invariants:
 * - image(): Uses 'image-attachment-id' $$type (not 'number').
 * - image(): Invariant IV — omits 'url' key entirely when id is set.
 *   Image_Src_Prop_Type requires exactly one of {id, url}.
 *
 * @package Novamira_AdrianV2
 * @since   1.0.0
 */

namespace Novamira\AdrianV2\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static helpers for building and reading atomic prop values.
 *
 * @since 1.0.0
 */
final class V4_Props {

    /**
     * Wraps a plain string into a typed prop.
     *
     * @param string $value The string value.
     * @return array{$$type: string, value: string}
     */
    public static function string(string $value): array {
        return [
            '$$type' => 'string',
            'value'  => $value,
        ];
    }

    /**
     * Wraps a number into a typed prop.
     *
     * @param int|float $value The numeric value.
     * @return array{$$type: string, value: int|float}
     */
    public static function number($value): array {
        return [
            '$$type' => 'number',
            'value'  => $value,
        ];
    }

    /**
     * Wraps a boolean into a typed prop.
     *
     * @param bool $value The boolean value.
     * @return array{$$type: string, value: bool}
     */
    public static function boolean(bool $value): array {
        return [
            '$$type' => 'boolean',
            'value'  => $value,
        ];
    }

    /**
     * Wraps a size value (number + unit) into a typed prop.
     *
     * @param int|float $size The size number.
     * @param string    $unit The CSS unit (px, em, rem, %, vw, vh).
     * @return array{$$type: string, value: array{size: int|float, unit: string}}
     */
    public static function size($size, string $unit = 'px'): array {
        return [
            '$$type' => 'size',
            'value'  => [
                'size' => $size,
                'unit' => $unit,
            ],
        ];
    }

    /**
     * Wraps text content into an html-v3 typed prop.
     *
     * @param string $text Plain text content.
     * @return array{$$type: string, value: array{content: array, children: array}}
     */
    public static function html(string $text): array {
        return [
            '$$type' => 'html-v3',
            'value'  => [
                'content'  => self::string($text),
                'children' => [],
            ],
        ];
    }

    /**
     * Wraps a URL into a typed prop.
     *
     * @param string $url The URL string.
     * @return array{$$type: string, value: string}
     */
    public static function url(string $url): array {
        return [
            '$$type' => 'url',
            'value'  => $url,
        ];
    }

    /**
     * Builds a link prop from a URL string.
     *
     * @param string $url           The destination URL.
     * @param bool   $target_blank  Whether to open in new tab.
     * @return array{$$type: string, value: array}
     */
    public static function link(string $url, bool $target_blank = false): array {
        $link_value = [
            'destination' => self::url($url),
            'tag'         => self::string('a'),
        ];

        if ($target_blank) {
            $link_value['isTargetBlank'] = self::boolean(true);
        }

        return [
            '$$type' => 'link',
            'value'  => $link_value,
        ];
    }

    /**
     * Builds a classes prop from an array of class IDs.
     *
     * @param string[] $class_ids Array of class identifiers.
     * @return array{$$type: string, value: string[]}
     */
    public static function classes(array $class_ids = []): array {
        return [
            '$$type' => 'classes',
            'value'  => $class_ids,
        ];
    }

    /**
     * Wraps a WordPress media image reference.
     *
     * Invariant IV: When id is set, OMIT the url key entirely.
     *
     * @param int    $image_id  The attachment ID.
     * @param string $image_url The image URL (optional fallback, only used when no id).
     * @return array{$$type: string, value: array{src: array}}
     */
    public static function image(int $image_id, string $image_url = ''): array {
        $src = [];

        if ($image_id > 0) {
            $src['id'] = [
                '$$type' => 'image-attachment-id',
                'value'  => $image_id,
            ];
        } elseif ('' !== $image_url) {
            $src['url'] = self::url($image_url);
        }

        return [
            '$$type' => 'image',
            'value'  => [
                'src' => $src,
            ],
        ];
    }

    /**
     * Recursively unwraps $$type values back to plain values.
     *
     * @param mixed $prop The prop value (may or may not be $$type-wrapped).
     * @return mixed The unwrapped plain value.
     */
    public static function unwrap($prop) {
        if (!is_array($prop)) {
            return $prop;
        }

        if (isset($prop['$$type'])) {
            $type  = $prop['$$type'];
            $value = $prop['value'] ?? null;

            switch ($type) {
                case 'string':
                case 'number':
                case 'boolean':
                case 'url':
                    return $value;

                case 'size':
                    return is_array($value)
                        ? ($value['size'] ?? 0) . ($value['unit'] ?? 'px')
                        : $value;

                case 'html-v3':
                    if (is_array($value) && isset($value['content'])) {
                        return self::unwrap($value['content']);
                    }
                    return $value;

                case 'link':
                    if (is_array($value) && isset($value['destination'])) {
                        return self::unwrap($value['destination']);
                    }
                    return $value;

                case 'classes':
                    return is_array($value) ? $value : [];

                case 'image':
                    if (is_array($value) && isset($value['src']) && is_array($value['src'])) {
                        return [
                            'id'  => self::unwrap($value['src']['id'] ?? 0),
                            'url' => self::unwrap($value['src']['url'] ?? ''),
                        ];
                    }
                    return $value;

                default:
                    return is_array($value) ? self::unwrap_array($value) : $value;
            }
        }

        return self::unwrap_array($prop);
    }

    /**
     * Unwraps all values in an array recursively.
     *
     * @param array $arr The array to unwrap.
     * @return array Unwrapped array.
     */
    private static function unwrap_array(array $arr): array {
        $result = [];
        foreach ($arr as $key => $value) {
            $result[$key] = self::unwrap($value);
        }
        return $result;
    }

    /**
     * Returns the canonical V4 property-type schema.
     *
     * Used by the REST endpoint GET /wp-json/novamira/v1/prop-schema
     * to feed sync-schema.js with the live widget prop definitions.
     *
     * @return array Schema object with version, types, and properties.
     */
    public static function get_schema(): array {
        return [
            'version'    => '1.0.0',
            'types'      => [
                'e-heading', 'e-paragraph', 'e-button', 'e-image',
                'e-svg', 'e-divider', 'e-flexbox', 'e-div-block',
                'e-component', 'e-field-label', 'e-field-input', 'e-field-submit',
            ],
            'properties' => [
                'title'        => ['type' => 'html-v3', 'widgets' => ['e-heading']],
                'paragraph'    => ['type' => 'html-v3', 'widgets' => ['e-paragraph']],
                'text'         => ['type' => 'html-v3', 'widgets' => ['e-button']],
                'image'        => ['type' => 'image',   'widgets' => ['e-image']],
                'image-src'    => ['type' => 'image-src','widgets' => ['e-image']],
                'svg-icon'     => ['type' => 'html-v3', 'widgets' => ['e-svg']],
                'link'         => ['type' => 'link',    'widgets' => ['e-button']],
                'classes'      => ['type' => 'classes', 'widgets' => ['*']],
                'tag'          => ['type' => 'string',  'widgets' => ['e-heading', 'e-flexbox', 'e-div-block']],
                'flex-direction' => ['type' => 'string', 'widgets' => ['e-flexbox']],
                'component-id' => ['type' => 'string',  'widgets' => ['e-component']],
                'field-label'  => ['type' => 'html-v3', 'widgets' => ['e-field-label']],
                'field-placeholder' => ['type' => 'string', 'widgets' => ['e-field-input']],
            ],
        ];
    }

    /**
     * Checks whether Elementor atomic (V4) elements are available AND will persist.
     *
     * @return bool True if atomic element types are registered/available.
     */
    public static function is_atomic_supported(): bool {
        if (class_exists('\\Elementor\\Plugin') && method_exists('\\Elementor\\Plugin', 'instance')) {
            $elementor = \Elementor\Plugin::instance();

            if (
                isset($elementor->elements_manager)
                && is_object($elementor->elements_manager)
                && method_exists($elementor->elements_manager, 'get_element_types')
            ) {
                $types = $elementor->elements_manager->get_element_types();
                if (is_array($types) && (isset($types['e-flexbox']) || isset($types['e-div-block']))) {
                    return true;
                }
            }

            if (
                isset($elementor->experiments)
                && is_object($elementor->experiments)
                && method_exists($elementor->experiments, 'is_feature_active')
            ) {
                foreach (['e_atomic_elements', 'atomic_widgets'] as $feature) {
                    if ($elementor->experiments->is_feature_active($feature)) {
                        return true;
                    }
                }
            }
        }

        return defined('ELEMENTOR_VERSION') && version_compare(ELEMENTOR_VERSION, '4.0.0', '>=');
    }
}
