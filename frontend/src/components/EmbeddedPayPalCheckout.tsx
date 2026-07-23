import React, { useEffect, useRef, useState, useCallback } from "react";

type PayPalButtonsInstance = { render: (target: string | HTMLElement) => Promise<void> };
type PayPalButtonsFactory = (opts: unknown) => PayPalButtonsInstance;
type PayPalSDK = {
  Buttons?: PayPalButtonsFactory;
};

declare global {
  interface Window {
    paypal?: PayPalSDK;
  }
}

const loadPayPalSdk = (clientId: string): Promise<void> =>
  new Promise<void>((resolve, reject) => {
    // If PayPal SDK is already loaded and available, resolve immediately
    if (window.paypal?.Buttons) {
      return resolve();
    }

    const existing = document.querySelector<HTMLScriptElement>('script[src^="https://www.paypal.com/sdk/js"]');
    
    if (existing) {
      // Script tag exists - check if it's already loaded
      if (window.paypal?.Buttons) {
        return resolve();
      }
      
      // Script is loading, wait for it
      const handleLoad = () => {
        // Wait a tick for window.paypal to be set
        setTimeout(() => {
          if (window.paypal?.Buttons) {
            resolve();
          } else {
            reject(new Error("PayPal SDK loaded but Buttons not available"));
          }
        }, 100);
      };
      
      const handleError = () => reject(new Error("PayPal SDK failed to load"));
      
      existing.addEventListener("load", handleLoad);
      existing.addEventListener("error", handleError);
      
      // If script already loaded (readyState check for older browsers)
      if (existing.dataset.loaded === 'true') {
        handleLoad();
      }
      return;
    }

    // Create new script tag
    const params = new URLSearchParams({
      "client-id": clientId,
      components: "buttons",
      vault: "true",
      intent: "subscription",
    });

    const url = `https://www.paypal.com/sdk/js?${params.toString()}`;
    const script = document.createElement("script");
    script.src = url;
    script.async = true;
    script.dataset.loaded = 'false';
    
    script.onload = () => {
      script.dataset.loaded = 'true';
      // Wait a tick for window.paypal to be set
      setTimeout(() => {
        if (window.paypal?.Buttons) {
          resolve();
        } else {
          reject(new Error("PayPal SDK loaded but Buttons not available"));
        }
      }, 100);
    };
    script.onerror = () => reject(new Error("PayPal SDK failed to load"));
    document.body.appendChild(script);
  });

type Props = {
  className?: string;
  planId?: string;
  userId?: string | number;
  onSuccess?: (result: { subscriptionId?: string }) => void;
  onError?: (err: Error) => void;
};

const ENV = ((import.meta as unknown as { env?: Record<string, string | undefined> }).env) ?? {};
const CLIENT_ID = ENV.VITE_PAYPAL_CLIENT_ID || "";

export const EmbeddedPayPalCheckout: React.FC<Props> = ({ className, planId, userId, onSuccess, onError }) => {
  const PLAN_ID = planId || ENV.VITE_PAYPAL_PLAN_ID || "";
  const paypalContainerRef = useRef<HTMLDivElement | null>(null);
  const renderedRef = useRef(false);
  const initAttemptRef = useRef(0);

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Memoize callbacks to prevent unnecessary re-renders
  const handleSuccess = useCallback((data: { subscriptionID?: string }) => {
    if (data.subscriptionID) {
      onSuccess?.({ subscriptionId: data.subscriptionID });
    }
  }, [onSuccess]);

  const handleError = useCallback((err: unknown) => {
    onError?.(err instanceof Error ? err : new Error(String(err)));
  }, [onError]);

  useEffect(() => {
    let mounted = true;
    const currentAttempt = ++initAttemptRef.current;
    // Capture ref value at effect start for cleanup
    const paypalElForCleanup = paypalContainerRef.current;

    const init = async () => {
      try {
        if (!CLIENT_ID) throw new Error("Missing VITE_PAYPAL_CLIENT_ID");
        if (!PLAN_ID) throw new Error("Missing VITE_PAYPAL_PLAN_ID");

        setLoading(true);
        setError(null);
        
        await loadPayPalSdk(CLIENT_ID);

        // Check if this init attempt is still valid
        if (!mounted || currentAttempt !== initAttemptRef.current) return;

        if (!window.paypal?.Buttons) {
          throw new Error("PayPal Buttons not available");
        }

        const paypalEl = paypalContainerRef.current;
        if (!paypalEl) {
          throw new Error("PayPal container not found");
        }

        // Clear any existing content and reset rendered flag
        paypalEl.innerHTML = '';
        
        // Prevent duplicate renders
        if (renderedRef.current) return;
        renderedRef.current = true;

        const buttons = window.paypal.Buttons({
          style: {
            layout: 'vertical',
            label: 'subscribe',
          },
          createSubscription: (_: unknown, actions: { subscription: { create: (input: { plan_id: string; custom_id?: string }) => Promise<string> } }) => {
            return actions.subscription.create({
              plan_id: PLAN_ID,
              custom_id: userId?.toString() // Pass user ID for webhook reliability
            });
          },
          onApprove: handleSuccess,
          onError: handleError
        });

        await buttons.render(paypalEl);
      } catch (err: unknown) {
        if (mounted && currentAttempt === initAttemptRef.current) {
          const errorMessage = err instanceof Error ? err.message : String(err);
          setError(errorMessage);
          handleError(err);
        }
      } finally {
        if (mounted && currentAttempt === initAttemptRef.current) {
          setLoading(false);
        }
      }
    };

    // Small delay to ensure DOM is ready
    const timeoutId = setTimeout(init, 50);

    return () => {
      mounted = false;
      clearTimeout(timeoutId);
      if (paypalElForCleanup) paypalElForCleanup.innerHTML = '';
      renderedRef.current = false;
    };
  }, [PLAN_ID, userId, handleSuccess, handleError]);

  // SAFETY CHECK: Ensure userId exists before rendering PayPal buttons
  // If userId is missing, PayPal payment could succeed but webhook would fail to find user
  if (!userId) {
    return (
      <div className={className}>
        <div style={{ border: "1px solid #e6e6e6", padding: 20, borderRadius: 8, textAlign: 'center', color: '#666' }}>
          <p style={{ margin: 0 }}>Please log in to subscribe to a plan.</p>
        </div>
      </div>
    );
  }

  return (
    <div className={className}>
      <div style={{ border: "1px solid #e6e6e6", padding: 20, borderRadius: 8 }}>
        {loading && (
          <div style={{ padding: 20, textAlign: 'center', color: '#666' }}>
            Loading PayPal...
          </div>
        )}
        
        {error && !loading && (
          <div style={{ padding: 20, textAlign: 'center', color: '#dc2626' }}>
            <p style={{ margin: 0 }}>Failed to load PayPal. Please refresh the page.</p>
          </div>
        )}
        
        <div ref={paypalContainerRef} style={{ minHeight: loading ? 0 : 150 }} />
      </div>
    </div>
  );
};

export default EmbeddedPayPalCheckout;
