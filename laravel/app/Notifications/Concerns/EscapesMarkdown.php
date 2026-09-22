<?php

namespace App\Notifications\Concerns;

trait EscapesMarkdown
{
    /**
     * Laravel's MailMessage renders ->line() content as Markdown. Asset and
     * platform names come from a shared catalog any authenticated user can
     * create/rename, and the AI digest summary is built from third-party
     * news headlines — neither is trusted. Without this, a crafted name
     * like "Bitcoin [Claim your reward](https://evil.example)" renders as
     * a real clickable link inside a genuine transactional email.
     */
    protected function escapeMarkdown(string $text): string
    {
        return preg_replace('/([\[\]()*_`])/', '\\\\$1', $text);
    }
}
