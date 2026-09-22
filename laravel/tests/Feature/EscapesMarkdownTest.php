<?php

namespace Tests\Feature;

use App\Notifications\Concerns\EscapesMarkdown;
use Tests\TestCase;

/**
 * Regression for a security-audit finding: asset/platform names (any
 * authenticated user can create/rename them) and the AI digest summary
 * (built from third-party news headlines) are embedded unescaped into a
 * Markdown-rendered MailMessage — a crafted name could inject a clickable
 * link that looks legitimate inside a real transactional email.
 */
class EscapesMarkdownTest extends TestCase
{
    private function escape(string $text): string
    {
        return (new class
        {
            use EscapesMarkdown;

            public function call(string $text): string
            {
                return $this->escapeMarkdown($text);
            }
        })->call($text);
    }

    public function test_a_crafted_link_is_neutralized(): void
    {
        $result = $this->escape('Bitcoin [Claim your reward](https://evil.example)');

        $this->assertSame('Bitcoin \[Claim your reward\]\(https://evil.example\)', $result);
    }

    public function test_ordinary_text_is_unchanged(): void
    {
        $this->assertSame('Bitcoin', $this->escape('Bitcoin'));
        $this->assertSame('Vanguard S&P 500 ETF', $this->escape('Vanguard S&P 500 ETF'));
    }

    public function test_every_markdown_special_character_is_escaped(): void
    {
        $result = $this->escape('[]()*_`');

        $this->assertSame('\[\]\(\)\*\_\`', $result);
    }
}
