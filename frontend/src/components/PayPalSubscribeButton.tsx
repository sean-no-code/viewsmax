import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";

interface PayPalSubscriptionDetails {
  status?: string;
  plan_id?: string;
  billing_info?: { next_billing_time?: string };
  [key: string]: unknown;
}

interface PayPalSubscriptionActions {
  subscription: {
    create: (payload: { plan_id: string }) => Promise<string>;
    get?: () => Promise<PayPalSubscriptionDetails>;
  };
}

interface PayPalButtonsConfig {
  style?: {
    shape?: string;
    color?: string;
    layout?: string;
    label?: string;
  };
  createSubscription: (
    data: unknown,
    actions: PayPalSubscriptionActions
  ) => Promise<string>;
  onApprove: (
    data: { subscriptionID: string },
    actions: Partial<PayPalSubscriptionActions>
  ) => void | Promise<void>;
  onError?: (err: unknown) => void | Promise<void>;
}

interface PayPalSDK {
  Buttons: (
    config: PayPalButtonsConfig
  ) => { render: (container: HTMLElement | string) => void | Promise<void> };
}

declare global {
  interface Window {
    paypal?: PayPalSDK;
  }
}

interface PayPalSubscribeButtonProps {
  planId: string;
  className?: string;
  onSuccess?: (payload: { subscriptionId: string; details?: PayPalSubscriptionDetails | undefined }) => void;
  onError?: (error: Error) => void;
}

const loadPayPalSdk = (clientId: string) => {
  return new Promise<void>((resolve, reject) => {
    if (window.paypal) {
      resolve();
      return;
    }

    const existing = document.querySelector<HTMLScriptElement>(
      'script[src^="https://www.paypal.com/sdk/js"]'
    );
    if (existing) {
      existing.addEventListener("load", () => resolve());
      existing.addEventListener("error", () => reject(new Error("PayPal SDK failed to load")));
      return;
    }

    const script = document.createElement("script");
    script.src = `https://www.paypal.com/sdk/js?client-id=${encodeURIComponent(
      clientId
    )}&vault=true&intent=subscription`;
    script.async = true;
    script.addEventListener("load", () => resolve());
    script.addEventListener("error", () => reject(new Error("PayPal SDK failed to load")));
    document.body.appendChild(script);
  });
};

export const PayPalSubscribeButton = ({ planId, className, onSuccess, onError }: PayPalSubscribeButtonProps) => {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const [isRendering, setIsRendering] = useState(false);

  useEffect(() => {
    let isMounted = true;

    const setup = async () => {
      try {
        const clientId = import.meta.env.VITE_PAYPAL_CLIENT_ID;
        
        if (!clientId) {
          throw new Error('PayPal Client ID not configured. Please set VITE_PAYPAL_CLIENT_ID environment variable.');
        }

        setIsRendering(true);
        await loadPayPalSdk(clientId);
        if (!isMounted || !window.paypal || !containerRef.current) return;

        // Clear any previous render
        containerRef.current.innerHTML = "";

        window.paypal
          .Buttons({
            style: {
              shape: "rect",
              color: "gold",
              layout: "vertical",
              label: "subscribe",
            },
            createSubscription: function (_data: unknown, actions: PayPalSubscriptionActions) {
              return actions.subscription.create({ plan_id: planId });
            },
            onApprove: async function (
              data: { subscriptionID: string },
              actions: Partial<PayPalSubscriptionActions>
            ) {
              try {
                let details: PayPalSubscriptionDetails | undefined = undefined;
                if (actions && actions.subscription && typeof actions.subscription.get === "function") {
                  try {
                    details = await actions.subscription.get();
                  } catch (inner) {
                    // It's okay if details fetch fails; we'll still return the subscriptionId
                  }
                }
                const payload = { subscriptionId: data.subscriptionID, details };
                console.log("PayPal subscription approved:", payload);
                toast.success("Subscription approved in sandbox");
                onSuccess?.(payload);
              } catch (err: unknown) {
                console.error(err);
                const errorMessage = err instanceof Error ? err.message : "Failed to process subscription";
                toast.error(errorMessage);
                onError?.(err instanceof Error ? err : new Error(errorMessage));
              }
            },
            onError: function (err: unknown) {
              console.error("PayPal Button error", err);
              toast.error("PayPal checkout failed");
              onError?.(err instanceof Error ? err : new Error("PayPal checkout failed"));
            },
          })
          .render(containerRef.current);
      } catch (e: unknown) {
        console.error(e);
        const msg = e instanceof Error ? e.message : "Failed to load PayPal";
        toast.error(msg);
      } finally {
        if (isMounted) setIsRendering(false);
      }
    };

    setup();
    return () => {
      isMounted = false;
    };
  }, [planId, onError, onSuccess]);

  return (
    <div className={className}>
      <div ref={containerRef} />
      {isRendering && (
        <div className="text-center text-sm text-muted-foreground mt-2">Loading PayPal…</div>
      )}
    </div>
  );
};

export default PayPalSubscribeButton;


