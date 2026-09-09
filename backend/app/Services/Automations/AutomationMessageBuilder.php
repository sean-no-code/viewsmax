<?php

namespace App\Services\Automations;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Services\ShortLinkService;

/**
 * Build the Instagram `message` payload for a run. Without a button it is a
 * text message (URLs rewritten to per-run tracked links); with a button it
 * is a generic-template card whose web_url button carries the tracked link.
 * Meta allows exactly ONE private reply per comment, which is why the whole
 * DM must fit in a single message.
 */
class AutomationMessageBuilder
{
    public function __construct(protected ShortLinkService $links) {}

    /** @return array<string, mixed> */
    public function build(Automation $automation, AutomationRun $run): array
    {
        if (! $automation->hasButton()) {
            return ['text' => $this->links->shortenForRun($run, (string) $automation->dm_text)];
        }

        $element = array_filter([
            'title' => mb_substr((string) $automation->dm_text, 0, Automation::CARD_TITLE_MAX),
            'subtitle' => $automation->dm_subtitle ? mb_substr($automation->dm_subtitle, 0, Automation::CARD_SUBTITLE_MAX) : null,
            'image_url' => $automation->dm_image_url ?: null,
            'buttons' => [[
                'type' => 'web_url',
                'url' => $this->links->mintForRun($run, (string) $automation->dm_button_url)->shortUrl(),
                'title' => mb_substr((string) $automation->dm_button_label, 0, Automation::BUTTON_LABEL_MAX),
            ]],
        ], fn ($v) => $v !== null);

        return [
            'attachment' => [
                'type' => 'template',
                'payload' => [
                    'template_type' => 'generic',
                    'elements' => [$element],
                ],
            ],
        ];
    }

    /**
     * Same shape, no links minted — for the editor's phone preview.
     *
     * @return array<string, mixed>
     */
    public function preview(Automation $automation): array
    {
        if (! $automation->hasButton()) {
            return ['text' => (string) $automation->dm_text];
        }

        return [
            'card' => array_filter([
                'title' => mb_substr((string) $automation->dm_text, 0, Automation::CARD_TITLE_MAX),
                'subtitle' => $automation->dm_subtitle,
                'image_url' => $automation->dm_image_url,
                'button' => ['title' => $automation->dm_button_label, 'url' => $automation->dm_button_url],
            ]),
        ];
    }
}
