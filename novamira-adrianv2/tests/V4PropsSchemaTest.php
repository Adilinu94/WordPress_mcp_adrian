<?php
/**
 * Test: V4_Props::get_schema() — REST Endpoint Schema
 *
 * Verifies the canonical V4 property-type schema served by
 * GET /wp-json/novamira/v1/prop-schema and consumed by
 * sync-schema.js (Phase 0.2).
 *
 * @package Novamira\AdrianV2\Tests
 * @since 1.3.0
 */

declare(strict_types=1);

use Novamira\AdrianV2\Helpers\V4_Props;
use PHPUnit\Framework\TestCase;

/**
 * V4 Props Schema Tests (ENH-16).
 */
#[CoversClass(V4_Props::class)]
class V4PropsSchemaTest extends TestCase
{
    private array $schema;

    protected function setUp(): void
    {
        $this->schema = V4_Props::get_schema();
    }

    // ── Top-Level Structure ──────────────────────────────────────────────────

    public function test_schema_is_array(): void
    {
        $this->assertIsArray($this->schema,
            'get_schema() must return an array');
    }

    public function test_schema_has_required_keys(): void
    {
        $this->assertArrayHasKey('version', $this->schema,
            'Schema must have a version key');
        $this->assertArrayHasKey('types', $this->schema,
            'Schema must have a types key');
        $this->assertArrayHasKey('properties', $this->schema,
            'Schema must have a properties key');
    }

    public function test_version_is_string(): void
    {
        $this->assertIsString($this->schema['version'],
            'Version must be a string');
        $this->assertNotEmpty($this->schema['version'],
            'Version must not be empty');
    }

    public function test_version_is_semver(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            $this->schema['version'],
            'Version must be semver (e.g. 1.0.0)'
        );
    }

    // ── Types ────────────────────────────────────────────────────────────────

    public function test_types_is_array(): void
    {
        $this->assertIsArray($this->schema['types'],
            'types must be an array');
    }

    public function test_types_contains_core_atomic_widgets(): void
    {
        $core = ['e-heading', 'e-paragraph', 'e-button', 'e-image',
                  'e-flexbox', 'e-div-block', 'e-divider', 'e-svg'];
        foreach ($core as $widget) {
            $this->assertContains($widget, $this->schema['types'],
                "types must include core atomic widget: {$widget}");
        }
    }

    public function test_types_contains_form_widgets(): void
    {
        $form = ['e-field-label', 'e-field-input', 'e-field-submit'];
        foreach ($form as $widget) {
            $this->assertContains($widget, $this->schema['types'],
                "types must include form widget: {$widget}");
        }
    }

    public function test_types_contains_component(): void
    {
        $this->assertContains('e-component', $this->schema['types'],
            'types must include e-component for reusable components');
    }

    public function test_type_count(): void
    {
        $this->assertCount(12, $this->schema['types'],
            'Schema must define exactly 12 widget types');
    }

    // ── Properties ───────────────────────────────────────────────────────────

    public function test_properties_is_array(): void
    {
        $this->assertIsArray($this->schema['properties'],
            'properties must be an array');
    }

    public function test_property_count(): void
    {
        $this->assertCount(13, $this->schema['properties'],
            'Schema must define exactly 13 property definitions');
    }

    /** @dataProvider providePropertyNames */
    public function test_each_property_has_type_and_widgets(string $propName): void
    {
        $this->assertArrayHasKey($propName, $this->schema['properties'],
            "Schema must define property: {$propName}");

        $prop = $this->schema['properties'][$propName];

        $this->assertIsArray($prop,
            "Property {$propName} must be an array");
        $this->assertArrayHasKey('type', $prop,
            "Property {$propName} must have a type key");
        $this->assertIsString($prop['type'],
            "Property {$propName} type must be a string");
        $this->assertArrayHasKey('widgets', $prop,
            "Property {$propName} must have a widgets key");
        $this->assertIsArray($prop['widgets'],
            "Property {$propName} widgets must be an array");
    }

    /** @return array<string, array{string}> */
    public static function providePropertyNames(): array
    {
        return [
            'title'                => ['title'],
            'paragraph'            => ['paragraph'],
            'text (button)'        => ['text'],
            'image'                => ['image'],
            'image-src'            => ['image-src'],
            'svg-icon'             => ['svg-icon'],
            'link'                 => ['link'],
            'classes'              => ['classes'],
            'tag'                  => ['tag'],
            'flex-direction'       => ['flex-direction'],
            'component-id'         => ['component-id'],
            'field-label'          => ['field-label'],
            'field-placeholder'    => ['field-placeholder'],
        ];
    }

    // ── Specific Property Validations ───────────────────────────────────────

    public function test_classes_property_has_wildcard_widgets(): void
    {
        $classes = $this->schema['properties']['classes'];
        $this->assertContains('*', $classes['widgets'],
            'classes must apply to all widgets (wildcard *)');
        $this->assertSame('classes', $classes['type'],
            'classes must be of type "classes"');
    }

    public function test_heading_properties(): void
    {
        $title = $this->schema['properties']['title'];
        $this->assertContains('e-heading', $title['widgets']);
        $this->assertSame('html-v3', $title['type']);

        $tag = $this->schema['properties']['tag'];
        $this->assertContains('e-heading', $tag['widgets']);
        $this->assertSame('string', $tag['type']);
    }

    public function test_image_properties(): void
    {
        $image = $this->schema['properties']['image'];
        $this->assertContains('e-image', $image['widgets']);
        $this->assertSame('image', $image['type']);

        $imageSrc = $this->schema['properties']['image-src'];
        $this->assertContains('e-image', $imageSrc['widgets']);
        $this->assertSame('image-src', $imageSrc['type']);
    }

    public function test_button_properties(): void
    {
        $text = $this->schema['properties']['text'];
        $this->assertContains('e-button', $text['widgets']);

        $link = $this->schema['properties']['link'];
        $this->assertContains('e-button', $link['widgets']);
        $this->assertSame('link', $link['type']);
    }

    public function test_flexbox_properties(): void
    {
        $flexDir = $this->schema['properties']['flex-direction'];
        $this->assertContains('e-flexbox', $flexDir['widgets']);
        $this->assertSame('string', $flexDir['type']);
    }

    public function test_component_properties(): void
    {
        $compId = $this->schema['properties']['component-id'];
        $this->assertContains('e-component', $compId['widgets']);
        $this->assertSame('string', $compId['type']);
    }

    public function test_form_properties(): void
    {
        $label = $this->schema['properties']['field-label'];
        $this->assertContains('e-field-label', $label['widgets']);
        $this->assertSame('html-v3', $label['type']);

        $placeholder = $this->schema['properties']['field-placeholder'];
        $this->assertContains('e-field-input', $placeholder['widgets']);
        $this->assertSame('string', $placeholder['type']);
    }
}
