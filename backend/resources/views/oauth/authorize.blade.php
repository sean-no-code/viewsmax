@extends('oauth.layout')

@section('title', 'Connect ' . $client->name . ' — ViewsMax')

@section('content')
    <div class="card">
        <h1 class="card-title">{{ $client->name }} wants to connect</h1>
        <p class="card-description">
            to your ViewsMax account<br>
            <span class="muted">Signed in as {{ $user->email }}</span>
        </p>

        <form id="mcp-approve" method="post" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <input type="hidden" name="access" id="access-input" value="full">

            <label>Access</label>
            <div style="display: flex; gap: 8px; margin-bottom: 24px;">
                <button type="button" id="seg-full" class="btn btn-primary" style="width: auto; padding: 8px 14px;" onclick="setAccess('full')">Full access</button>
                <button type="button" id="seg-read" class="btn btn-outline" style="width: auto; padding: 8px 14px;" onclick="setAccess('read')">Read-only</button>
            </div>
        </form>

        <p class="muted" style="font-size: 13px; margin: 0 0 6px;">This will let {{ $client->name }}:</p>
        <ul style="list-style: none; padding: 0; margin: 0 0 24px;">
            @forelse ($scopes as $scope)
                <li data-scope="{{ $scope->id }}" style="display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 14px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0FB67E" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 2px;">
                        <polyline points="20 6 9 17 4 12" />
                    </svg>
                    {{ $scope->description }}
                </li>
            @empty
                <li style="display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 14px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0FB67E" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 2px;">
                        <polyline points="20 6 9 17 4 12" />
                    </svg>
                    Basic account access
                </li>
            @endforelse
        </ul>

        <script>
            function setAccess(level) {
                document.getElementById('access-input').value = level;
                document.getElementById('seg-full').className = 'btn ' + (level === 'full' ? 'btn-primary' : 'btn-outline');
                document.getElementById('seg-read').className = 'btn ' + (level === 'read' ? 'btn-primary' : 'btn-outline');

                // The checklist is the explanation: read-only drops the write line.
                document.querySelectorAll('[data-scope="mcp:write"]').forEach(function (li) {
                    li.style.display = level === 'read' ? 'none' : 'flex';
                });
            }
        </script>

        <div style="display: flex; gap: 12px;">
            <form method="post" action="{{ route('passport.authorizations.deny') }}" style="flex: 1;">
                @csrf
                @method('DELETE')
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="btn btn-outline">Deny</button>
            </form>
            <button type="submit" form="mcp-approve" class="btn btn-primary" style="flex: 1;">Approve</button>
        </div>
    </div>
@endsection
