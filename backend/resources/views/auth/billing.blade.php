@extends('oauth.layout')

@section('title', 'Add a card — ViewsMax')
@section('width', '36rem')

@section('content')
    <div class="card">
        <h1 class="card-title">Start your free trial</h1>
        <p class="card-description">
            <span class="setup-step done"><b>✓</b> Verify email</span>
            <span class="setup-step done"><b>✓</b> Connect channels</span>
            <span class="setup-step current"><b>3</b> Add a card</span>
            <span class="setup-step"><b>4</b> {{ $hasAgent ? 'Grant '.($client ?? 'your AI agent').' access' : 'Open ViewsMax' }}</span>
        </p>

        @if (session('error'))
            <div class="error">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="error">
                @foreach ($errors->all() as $message)
                    <div>{{ $message }}</div>
                @endforeach
            </div>
        @endif

        <div class="terms">
            @if ($trialEnd)
                <b>${{ $activationFee }} today · free until {{ $trialEnd->format('j M') }}</b>
                <span>Only the ${{ $activationFee }} activation fee is charged before {{ $trialEnd->format('j M') }}; your plan renews monthly from then. Cancel anytime.</span>
            @else
                <b>Charged today, then monthly</b>
                <span>Your free trial has ended, so your card is charged now. Cancel anytime.</span>
            @endif
        </div>

        <form method="post" action="{{ route('register.billing') }}" id="billing-form">
            @csrf
            <input type="hidden" name="payment_method_id" value="">

            <div class="plans">
                @foreach ($plans as $i => $p)
                    <label class="plan">
                        <input type="radio" name="price_id" value="{{ $p->stripe_price_id }}" @checked(old('price_id', $plans->first()?->stripe_price_id) === $p->stripe_price_id) required>
                        <span class="plan-body">
                            <span class="plan-name">{{ $p->display_name }}</span>
                            @if ($p->description)
                                <span class="plan-desc">{{ $p->description }}</span>
                            @endif
                        </span>
                        <span class="plan-price">${{ (int) round($p->price) }}<small>/mo</small></span>
                    </label>
                @endforeach
            </div>

            @if ($publishableKey && $clientSecret)
                <div id="payment-element" style="margin-top: 18px;"></div>
                <div id="payment-error" class="error" style="display: none; margin-top: 12px;"></div>
                <button type="submit" class="btn btn-primary" id="billing-submit" style="margin-top: 18px;">
                    {{ $trialEnd ? 'Start free trial — $'.$activationFee.' today' : 'Subscribe now' }}
                </button>
            @else
                <div class="error" style="margin-top: 18px;">Payments aren't available right now. Please try again in a moment or contact support.</div>
            @endif
        </form>

        <p class="card-footer">Cancel anytime from Settings in ViewsMax{{ $client ? ' or by asking '.$client : '' }}.</p>
    </div>

    <style>
        .setup-step { display: inline-flex; align-items: center; gap: 6px; margin: 0 8px; color: var(--muted-foreground); }
        .setup-step b { display: inline-grid; place-items: center; width: 20px; height: 20px; border-radius: 999px; background: var(--border); color: var(--foreground); font-size: 12px; }
        .setup-step.current { color: var(--foreground); font-weight: 600; }
        .setup-step.current b { background: var(--vm-red); color: #fff; }
        .setup-step.done b { background: #16A34A; color: #fff; }
        .terms { display: flex; flex-direction: column; gap: 4px; margin-bottom: 18px; padding: 12px 14px; border: 1px solid var(--border); border-radius: 12px; font-size: 14px; }
        .terms b { font-size: 15px; }
        .terms span { color: var(--muted-foreground); }
        .plans { display: flex; flex-direction: column; gap: 10px; }
        .plan { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 1px solid var(--border); border-radius: calc(var(--radius)); cursor: pointer; margin: 0; }
        .plan:has(input:checked) { border-color: var(--vm-red); box-shadow: 0 0 0 3px rgba(255,31,61,.12); }
        .plan input { width: auto; height: auto; margin: 0; accent-color: var(--vm-red); }
        .plan-body { display: flex; flex-direction: column; flex: 1; min-width: 0; }
        .plan-name { font-weight: 600; font-size: 15px; }
        .plan-desc { font-size: 13px; color: var(--muted-foreground); }
        .plan-price { font-weight: 700; font-size: 18px; white-space: nowrap; }
        .plan-price small { font-size: 12px; font-weight: 500; color: var(--muted-foreground); }
    </style>

    @if ($publishableKey && $clientSecret)
        <script src="https://js.stripe.com/v3/"></script>
        <script>
            // Same flow as the SPA's card step: vault the card on the SetupIntent
            // (no charge here), then POST the payment method so the server starts
            // the subscription on the user's terms.
            (function () {
                var form = document.getElementById('billing-form');
                var button = document.getElementById('billing-submit');
                var errorBox = document.getElementById('payment-error');
                var label = button.textContent;
                var stripe = Stripe(@json($publishableKey));
                var elements = stripe.elements({
                    clientSecret: @json($clientSecret),
                    appearance: { variables: { colorPrimary: '#FF1F3D', borderRadius: '6px', fontFamily: 'system-ui, sans-serif' } },
                });
                elements.create('payment', {
                    layout: 'tabs',
                    wallets: { link: 'auto' },
                    defaultValues: { billingDetails: { email: @json($email) } },
                }).mount('#payment-element');

                form.addEventListener('submit', async function (event) {
                    event.preventDefault();
                    errorBox.style.display = 'none';
                    button.disabled = true;
                    button.textContent = 'Saving card…';

                    var result = await stripe.confirmSetup({ elements: elements, redirect: 'if_required' });
                    if (result.error) {
                        errorBox.textContent = result.error.message || "Card couldn't be saved. Please try again.";
                        errorBox.style.display = 'block';
                        button.disabled = false;
                        button.textContent = label;
                        return;
                    }

                    var method = result.setupIntent && result.setupIntent.payment_method;
                    form.querySelector('input[name=payment_method_id]').value = typeof method === 'string' ? method : (method && method.id) || '';
                    form.submit();
                });
            })();
        </script>
    @endif
@endsection
