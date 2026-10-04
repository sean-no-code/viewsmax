@php extract(\App\Support\SiteChrome::data()); @endphp
<nav class="site-nav">
    <div class="site-nav-inner">
        <a href="{{ $frontend }}/" class="site-logo">{!! $logo('#0A0A0C') !!}</a>
        <div class="nav-links">
            <a href="{{ $frontend }}/#demos">Features</a>
            <a href="{{ $frontend }}/#channels">Channels</a>
            <a href="{{ $frontend }}/#pricing">Pricing</a>
            @foreach ([['Free Tools', $frontend.'/free-tools', $freeTools], ['AI Agents', $frontend.'/ai', $aiAgents], ['Resources', $frontend.'/ai', $resources]] as [$label, $href, $items])
                <div class="nav-dd">
                    <a href="{{ $href }}">{{ $label }} <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></a>
                    <div class="nav-dd-panel"><div class="nav-dd-card">
                        @foreach ($items as $item)
                            <a href="{{ $item['href'] }}" @if (! empty($item['external'])) target="_blank" rel="noreferrer" @endif>
                                <span class="nav-dd-label">{{ $item['label'] }}</span>
                                <span class="nav-dd-desc">{{ $item['desc'] }}</span>
                            </a>
                        @endforeach
                    </div></div>
                </div>
            @endforeach
        </div>
        <div class="nav-right">
            <a href="{{ route('login') }}" class="nav-login">Log in</a>
            <a href="{{ route('register') }}" class="btn-pill">Start for $0</a>
        </div>
    </div>
</nav>
