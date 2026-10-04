<?php

namespace App\Support;

/**
 * Link lists + logo for the API host's site header and footer. Source of
 * truth is the SPA: frontend/src/pages/landing/LandingChrome.tsx (RESOURCES,
 * FREE_TOOLS, footer columns) and frontend/src/lib/agent-pages.ts
 * (AGENT_LIST). Keep in step when those change.
 */
class SiteChrome
{
    /** @return array{frontend: string, blog: string, resources: array, freeTools: array, aiAgents: array, logo: \Closure} */
    public static function data(): array
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $blog = 'https://blog.viewsmax.com';
        $resources = [
            ['label' => 'API docs', 'desc' => 'REST API reference & OpenAPI spec', 'href' => url('/docs'), 'external' => true],
            ['label' => 'Install MCP', 'desc' => 'The ViewsMax MCP server: address, auth & 30 tools', 'href' => $frontend.'/mcp'],
            ['label' => 'CLI setup', 'desc' => 'Use ViewsMax from Claude Code', 'href' => $frontend.'/ai#cli'],
            ['label' => 'Blog', 'desc' => 'Growth tactics & product updates', 'href' => $blog, 'external' => true],
            ['label' => 'Support', 'desc' => 'Join our Discord for help & updates', 'href' => 'https://discord.gg/Wwe57w3Dv5', 'external' => true],
            ['label' => 'GitHub', 'desc' => 'ViewsMax examples, SDKs & issues', 'href' => 'https://github.com/sean-no-code/viewsmax', 'external' => true],
        ];
        $freeTools = [
            ['label' => 'YouTube Transcript', 'desc' => 'Download & copy any YouTube transcript', 'href' => $frontend.'/free-tools/youtube-transcript'],
            ['label' => 'TikTok Transcript', 'desc' => 'Convert any TikTok video to text', 'href' => $frontend.'/free-tools/tiktok-transcript'],
            ['label' => 'Instagram Transcript', 'desc' => 'Turn Reels & videos into text', 'href' => $frontend.'/free-tools/instagram-transcript'],
            ['label' => 'Thumbnail Preview', 'desc' => "See your thumbnail on YouTube's home page", 'href' => $frontend.'/thumbnail-preview'],
            ['label' => 'Revenue Calculator', 'desc' => 'Estimate your YouTube earnings', 'href' => $frontend.'/youtube-monetization-calculator'],
        ];
        $aiAgents = [
            ['label' => 'Claude', 'desc' => "One connector across all of Claude's apps", 'href' => $frontend.'/claude'],
            ['label' => 'Claude Code', 'desc' => 'Same connector as Claude, used by the desktop agent', 'href' => $frontend.'/claude-code'],
            ['label' => 'Claude Cowork', 'desc' => 'One terminal command, sign in over OAuth', 'href' => $frontend.'/claude-cowork'],
            ['label' => 'ChatGPT', 'desc' => 'Custom connector in Developer mode', 'href' => $frontend.'/chatgpt'],
            ['label' => 'Codex', 'desc' => 'Two commands in the Codex CLI', 'href' => $frontend.'/codex'],
            ['label' => 'Cursor', 'desc' => 'MCP config with an API key', 'href' => $frontend.'/cursor'],
            ['label' => 'OpenClaw', 'desc' => 'ViewsMax skill with an API key', 'href' => $frontend.'/openclaw'],
            ['label' => 'Hermes Agent', 'desc' => 'MCP server in config.yaml, sign in over OAuth', 'href' => $frontend.'/hermes'],
        ];
        $logo = function (string $textFill): string {
            return '<svg width="133" height="30" viewBox="0 0 248 56" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="ViewsMax" role="img">'
                .'<g transform="translate(0,4)"><rect x="0" y="0" width="48" height="48" rx="12" fill="#FF1F3D"></rect>'
                .'<path d="M19 15.5 L34 24 L19 32.5 Z" fill="#FFFFFF"></path>'
                .'<rect x="11" y="38" width="26" height="3.4" rx="1.7" fill="#16E0C4"></rect></g>'
                .'<text x="62" y="38" font-family="Archivo, system-ui, sans-serif" font-weight="900" font-size="32" letter-spacing="-1.4" fill="'.$textFill.'">Views<tspan fill="#FF1F3D">Max</tspan></text></svg>';
        };

        return compact('frontend', 'blog', 'resources', 'freeTools', 'aiAgents', 'logo');
    }
}
