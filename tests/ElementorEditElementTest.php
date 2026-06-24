<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Tests;

use Novamira\AdrianV2\Abilities\Elementor\Elementor_Edit_Element;
use PHPUnit\Framework\TestCase;

/**
 * Tests for novamira-adrianv2/elementor-edit-element (pure logic via reflection).
 *
 * @covers \Novamira\AdrianV2\Abilities\Elementor\Elementor_Edit_Element
 */
final class ElementorEditElementTest extends TestCase
{
    // =========================================================================
    // walk_and_update — pure tree-walk logic
    // =========================================================================

    private function callWalk(array &$elements, string $id, array $settings): bool
    {
        $found = false;
        $ref   = new \ReflectionMethod(Elementor_Edit_Element::class, 'walk_and_update');
        $ref->setAccessible(true);
        $args = [&$elements, $id, $settings, &$found];
        $ref->invokeArgs(null, $args);
        return $found;
    }

    public function test_walk_finds_top_level_element(): void
    {
        $tree = [
            ['id' => 'aaa111', 'elType' => 'section', 'settings' => ['color' => 'red'], 'elements' => []],
            ['id' => 'bbb222', 'elType' => 'section', 'settings' => ['color' => 'blue'], 'elements' => []],
        ];

        $result = $this->callWalk($tree, 'aaa111', ['color' => 'green', 'padding' => ['top' => '20px']]);

        $this->assertTrue($result);
        $this->assertSame('green', $tree[0]['settings']['color']);
        $this->assertSame(['top' => '20px'], $tree[0]['settings']['padding']);
        $this->assertSame('blue', $tree[1]['settings']['color']); // untouched
    }

    public function test_walk_finds_nested_widget(): void
    {
        $tree = [
            [
                'id'       => 'section1',
                'elType'   => 'section',
                'settings' => [],
                'elements' => [
                    [
                        'id'       => 'col1',
                        'elType'   => 'column',
                        'settings' => [],
                        'elements' => [
                            ['id' => 'widget1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => 'Old'], 'elements' => []],
                        ],
                    ],
                ],
            ],
        ];

        $found = $this->callWalk($tree, 'widget1', ['title' => 'New Title', '_background_color' => '#ff0000']);

        $this->assertTrue($found);
        $widget = $tree[0]['elements'][0]['elements'][0];
        $this->assertSame('New Title', $widget['settings']['title']);
        $this->assertSame('#ff0000', $widget['settings']['_background_color']);
    }

    public function test_walk_returns_false_for_unknown_id(): void
    {
        $tree = [
            ['id' => 'known', 'elType' => 'section', 'settings' => [], 'elements' => []],
        ];

        $result = $this->callWalk($tree, 'unknown_id', ['foo' => 'bar']);
        $this->assertFalse($result);
    }

    public function test_walk_deep_merges_nested_settings(): void
    {
        $tree = [
            [
                'id'       => 'el1',
                'elType'   => 'widget',
                'settings' => [
                    'image' => ['id' => 10, 'url' => 'https://old.com/img.jpg'],
                    'title' => 'Keep me',
                ],
                'elements' => [],
            ],
        ];

        $this->callWalk($tree, 'el1', ['image' => ['id' => 99, 'url' => 'https://new.com/img.jpg']]);

        $this->assertSame(99, $tree[0]['settings']['image']['id']);
        $this->assertSame('https://new.com/img.jpg', $tree[0]['settings']['image']['url']);
        $this->assertSame('Keep me', $tree[0]['settings']['title']); // untouched
    }

    public function test_walk_stops_after_first_match(): void
    {
        $tree = [
            ['id' => 'dup', 'elType' => 'section', 'settings' => ['val' => 'first'], 'elements' => []],
            ['id' => 'dup', 'elType' => 'section', 'settings' => ['val' => 'second'], 'elements' => []],
        ];

        $this->callWalk($tree, 'dup', ['val' => 'patched']);

        $this->assertSame('patched', $tree[0]['settings']['val']);
        $this->assertSame('second', $tree[1]['settings']['val']); // only first match updated
    }

    public function test_walk_handles_empty_tree(): void
    {
        $tree  = [];
        $found = $this->callWalk($tree, 'any', ['foo' => 'bar']);
        $this->assertFalse($found);
    }

    // =========================================================================
    // execute() — input validation (no WP DB needed)
    // =========================================================================

    public function test_execute_returns_error_for_missing_post_id(): void
    {
        $result = Elementor_Edit_Element::execute(['element_id' => 'abc', 'settings' => ['foo' => 'bar']]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('post_id', $result['error']);
    }

    public function test_execute_returns_error_for_empty_element_id(): void
    {
        $result = Elementor_Edit_Element::execute(['post_id' => 1, 'element_id' => '', 'settings' => ['foo' => 'bar']]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('element_id', $result['error']);
    }

    public function test_execute_returns_error_for_empty_settings(): void
    {
        $result = Elementor_Edit_Element::execute(['post_id' => 1, 'element_id' => 'abc', 'settings' => []]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('settings', $result['error']);
    }

    public function test_execute_returns_error_for_null_input(): void
    {
        $result = Elementor_Edit_Element::execute(null);
        $this->assertFalse($result['success']);
    }
}
