@php extract(\App\Support\SiteChrome::data()); @endphp
@php
    $columns = [
        ['Product', [['Scheduler', $frontend.'/#demos'], ['Sales tracking', $frontend.'/#demos'], ['Analytics', $frontend.'/#demos'], ['Channels', $frontend.'/#channels'], ['Pricing', $frontend.'/#pricing']]],
        ['Company', [['About', $frontend.'/'], ['Careers', $frontend.'/'], ['Blog', $blog], ['Contact', 'https://discord.gg/Wwe57w3Dv5'], ['Affiliates', 'https://viewsmax.getrewardful.com/signup']]],
        ['Resources', [['API docs', url('/docs')], ['Install MCP', $frontend.'/mcp'], ['CLI setup', $frontend.'/ai#cli']]],
        ['AI agents', array_map(fn ($a) => [$a['label'], $a['href']], $aiAgents)],
        ['Free tools', array_map(fn ($t) => [$t['label'], $t['href']], $freeTools)],
    ];
@endphp
<footer class="site-foot">
    <div class="site-foot-grid">
        <div>
            {!! $logo('#F6F6F8') !!}
            <p class="site-foot-tagline">Make content that makes sales.</p>
        </div>
        @foreach ($columns as [$heading, $links])
            <div>
                <div class="site-foot-heading">{{ $heading }}</div>
                <div class="site-foot-links">
                    @foreach ($links as [$label, $href])
                        <a href="{{ $href }}" @if (str_starts_with($href, 'http') && ! str_starts_with($href, $frontend)) target="_blank" rel="noreferrer" @endif>{{ $label }}</a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
    <div class="site-foot-bar">
        <div class="site-foot-bar-inner">
            <span>© {{ date('Y') }} ViewsMax. Not affiliated with the social platforms shown.</span>
            <span class="site-foot-legal"><a href="{{ $frontend }}/privacy">Privacy</a><a href="{{ $frontend }}/terms">Terms</a></span>
        </div>
    </div>
</footer>
