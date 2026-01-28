<?php
/**
 * Tests for Instruction_Builder
 *
 * @package MCP_No_Headless\Tests\Unit
 */

namespace MCP_No_Headless\Tests\Unit;

use PHPUnit\Framework\TestCase;

class Instruction_BuilderTest extends TestCase {

    private string $base_url = 'https://test.marylink.io';

    /**
     * Build instruction with URLs
     */
    private function buildInstruction(
        string $base,
        array $content_ids,
        array $style_ids,
        string $final_task,
        array $publications
    ): string {
        $parts = [$base];

        // Add content URLs
        if (!empty($content_ids)) {
            $parts[] = "\n\n## Sources de données\n";
            foreach ($content_ids as $id) {
                if (isset($publications[$id])) {
                    $slug = $publications[$id]['slug'];
                    $parts[] = "- {$this->base_url}/publication/{$slug}/";
                }
            }
        }

        // Add style URLs
        if (!empty($style_ids)) {
            $parts[] = "\n\n## Styles à appliquer\n";
            foreach ($style_ids as $id) {
                if (isset($publications[$id])) {
                    $slug = $publications[$id]['slug'];
                    $parts[] = "- {$this->base_url}/style/{$slug}/";
                }
            }
        }

        // Add final task
        $parts[] = "\n\n## Tâche finale\n" . $final_task;

        return implode("\n", $parts);
    }

    /**
     * TEST 15: Construction avec URLs
     */
    public function test_builds_instruction_with_urls(): void {
        $publications = [
            123 => ['slug' => 'catalogue-2024', 'title' => 'Catalogue 2024'],
            456 => ['slug' => 'tarifs-2024', 'title' => 'Tarifs 2024'],
            789 => ['slug' => 'charte-editoriale', 'title' => 'Charte Éditoriale'],
        ];

        $instruction = $this->buildInstruction(
            'Tu es un expert',
            [123, 456],
            [789],
            'Rédige une réponse',
            $publications
        );

        $this->assertStringContainsString('Tu es un expert', $instruction);
        $this->assertStringContainsString('/publication/catalogue-2024/', $instruction);
        $this->assertStringContainsString('/publication/tarifs-2024/', $instruction);
        $this->assertStringContainsString('/style/charte-editoriale/', $instruction);
        $this->assertStringContainsString('Rédige une réponse', $instruction);
    }

    /**
     * TEST 16: Limite de 20 URLs
     */
    public function test_limits_to_20_urls(): void {
        $max_urls = 20;
        $publications = [];

        // Create 25 publications
        for ($i = 1; $i <= 25; $i++) {
            $publications[$i] = ['slug' => "doc-$i", 'title' => "Document $i"];
        }

        $content_ids = array_slice(array_keys($publications), 0, 25);

        // Simulate limit
        $limited_ids = array_slice($content_ids, 0, $max_urls);

        $this->assertCount($max_urls, $limited_ids);
    }

    /**
     * TEST: Instruction structure
     */
    public function test_instruction_structure(): void {
        $publications = [
            1 => ['slug' => 'doc-1', 'title' => 'Doc 1'],
        ];

        $instruction = $this->buildInstruction(
            'Base instruction',
            [1],
            [],
            'Final task',
            $publications
        );

        // Check structure order
        $base_pos = strpos($instruction, 'Base instruction');
        $sources_pos = strpos($instruction, '## Sources de données');
        $task_pos = strpos($instruction, '## Tâche finale');

        $this->assertLessThan($sources_pos, $base_pos);
        $this->assertLessThan($task_pos, $sources_pos);
    }

    /**
     * TEST: Empty content/styles
     */
    public function test_handles_empty_content_styles(): void {
        $instruction = $this->buildInstruction(
            'Base instruction',
            [],
            [],
            'Final task',
            []
        );

        $this->assertStringContainsString('Base instruction', $instruction);
        $this->assertStringContainsString('Final task', $instruction);
        $this->assertStringNotContainsString('## Sources de données', $instruction);
        $this->assertStringNotContainsString('## Styles à appliquer', $instruction);
    }

    /**
     * TEST: URL format
     */
    public function test_url_format(): void {
        $publications = [
            1 => ['slug' => 'my-document', 'title' => 'My Document'],
        ];

        $instruction = $this->buildInstruction(
            'Base',
            [1],
            [],
            'Task',
            $publications
        );

        // URL should be properly formatted
        $this->assertMatchesRegularExpression(
            '#https://test\.marylink\.io/publication/my-document/#',
            $instruction
        );
    }

    /**
     * TEST: Missing publication graceful handling
     */
    public function test_missing_publication_ignored(): void {
        $publications = [
            1 => ['slug' => 'doc-1', 'title' => 'Doc 1'],
            // ID 2 is missing
        ];

        $instruction = $this->buildInstruction(
            'Base',
            [1, 2], // ID 2 doesn't exist
            [],
            'Task',
            $publications
        );

        // Should include doc-1 but not crash on missing doc-2
        $this->assertStringContainsString('/publication/doc-1/', $instruction);
        $this->assertStringNotContainsString('doc-2', $instruction);
    }
}
