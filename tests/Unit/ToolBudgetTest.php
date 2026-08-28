<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * WebMCP imposes hard character budgets: a tool name may not exceed 30
 * characters and a description may not exceed 500. Going over does not fail
 * loudly at runtime, it just makes the agent's guardrails drop the tool, so
 * nothing in the app would ever tell us.
 *
 * This reads the tool source directly rather than booting a browser, so it
 * costs nothing and can run on every commit. It deliberately extends PHPUnit's
 * TestCase, not Tests\TestCase: there is no database here, so it must not be
 * blocked by the swash_test guard.
 */
class ToolBudgetTest extends TestCase
{
    private const NAME_LIMIT = 30;

    private const DESCRIPTION_LIMIT = 500;

    private const TOOL_DIR = __DIR__.'/../../resources/js/webmcp/tools';

    /**
     * @return array<int, array{name: string, description: string, file: string}>
     */
    private function tools(): array
    {
        $tools = [];

        foreach (glob(self::TOOL_DIR.'/*.ts') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all(
                "/name:\s*'([a-z_]+)',\s*\n\s*description:\s*\n?\s*'((?:[^'\\\\]|\\\\.)*)'/m",
                $source,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $tools[] = [
                    'name' => $match[1],
                    'description' => $match[2],
                    'file' => basename($file),
                ];
            }
        }

        return $tools;
    }

    public function test_the_scanner_actually_found_the_tools(): void
    {
        // A regex that silently matches nothing would make every other
        // assertion below pass vacuously.
        $this->assertGreaterThanOrEqual(
            30,
            count($this->tools()),
            'The tool scanner found almost nothing, so its regex is stale. Fix the scanner before trusting this file.'
        );
    }

    public function test_every_tool_name_is_within_the_character_budget(): void
    {
        foreach ($this->tools() as $tool) {
            $this->assertLessThanOrEqual(
                self::NAME_LIMIT,
                mb_strlen($tool['name']),
                "Tool name \"{$tool['name']}\" in {$tool['file']} exceeds the 30-character name budget."
            );
        }
    }

    public function test_every_tool_description_is_within_the_character_budget(): void
    {
        foreach ($this->tools() as $tool) {
            $this->assertLessThanOrEqual(
                self::DESCRIPTION_LIMIT,
                mb_strlen($tool['description']),
                "Description for \"{$tool['name']}\" in {$tool['file']} exceeds the 500-character description budget."
            );
        }
    }

    public function test_tool_names_are_unique_across_every_mode(): void
    {
        $names = array_column($this->tools(), 'name');
        $duplicates = array_keys(array_filter(array_count_values($names), static fn (int $n): bool => $n > 1));

        $this->assertSame(
            [],
            $duplicates,
            'Duplicate tool names would make the agent pick between identical entries: '.implode(', ', $duplicates)
        );
    }

    public function test_every_tool_name_is_snake_case(): void
    {
        foreach ($this->tools() as $tool) {
            $this->assertMatchesRegularExpression(
                '/^[a-z]+(_[a-z]+)*$/',
                $tool['name'],
                "Tool name \"{$tool['name']}\" breaks the snake_case convention the other tools follow."
            );
        }
    }
}
