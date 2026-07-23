import { useState, useEffect, createContext, useContext, ReactNode } from 'react';
import { useAuth } from './useAuth';
import { viewsMaxApi } from '@/lib/api-service';

export type UserRole = 'free' | 'pro';

export interface UserRoleData {
  role: UserRole;
  hasActiveSubscription: boolean;
  subscriptionStatus?: string;
  subscriptionId?: string;
}

interface UserRoleContextType {
  userRole: UserRoleData;
  setUserRole: (role: UserRoleData) => void;
  isPaidUser: boolean;
  isProUser: boolean;
  isFreeUser: boolean;
  /** Actual tier name for display (e.g. "Creator"); undefined when on Free. */
  planName?: string;
}

const UserRoleContext = createContext<UserRoleContextType | undefined>(undefined);

export const useUserRole = () => {
  const context = useContext(UserRoleContext);
  if (context === undefined) {
    throw new Error('useUserRole must be used within a UserRoleProvider');
  }
  return context;
};

interface UserRoleProviderProps {
  children: ReactNode;
}

// Helper to check subscription from localStorage synchronously
const getInitialRole = (): UserRoleData => {
  try {
    const activeSubscription = localStorage.getItem('active_subscription');
    if (activeSubscription) {
      const subscription = JSON.parse(activeSubscription);
      const status = subscription.details?.status?.toLowerCase();
      const isActive = status === 'active' || status === 'trialing';
      const isCancelledWithGrace = status === 'cancelled' &&
                                  subscription.expires_at &&
                                  new Date(subscription.expires_at) > new Date();

      if (subscription.subscriptionId && (isActive || isCancelledWithGrace)) {
        return {
          role: 'pro',
          hasActiveSubscription: true,
          subscriptionStatus: subscription.details?.status || 'ACTIVE',
          subscriptionId: subscription.subscriptionId,
        };
      }
    }
  } catch { /* ignore */ }
  return { role: 'free', hasActiveSubscription: false };
};

// Read the cached tier display name so the badge renders the real label on the
// first paint (avoids a "PRO" → "Pro" flicker while getCurrentPlan resolves).
const getInitialPlanName = (): string | undefined => {
  try {
    const raw = localStorage.getItem('active_subscription');
    if (!raw) return undefined;
    const sub = JSON.parse(raw);
    const status = sub.details?.status?.toLowerCase();
    const isActive = status === 'active' || status === 'trialing';
    const isCancelledWithGrace = status === 'cancelled' &&
                                sub.expires_at && new Date(sub.expires_at) > new Date();
    if (sub.subscriptionId && (isActive || isCancelledWithGrace)) {
      return sub.plan_display_name || undefined;
    }
  } catch { /* ignore */ }
  return undefined;
};

export const UserRoleProvider = ({ children }: UserRoleProviderProps) => {
  const { user } = useAuth();
  // Initialize from localStorage to prevent flash
  const [userRole, setUserRole] = useState<UserRoleData>(() => getInitialRole());
  // Actual tier name for display (Starter/Creator/Pro/Agency). The role above
  // stays binary (free/pro) for feature gating; this is purely the label.
  const [planName, setPlanName] = useState<string | undefined>(() => getInitialPlanName());

  useEffect(() => {
    if (!user) { setPlanName(undefined); return; }
    let cancelled = false;
    const loadPlanName = async () => {
      const res = await viewsMaxApi.getCurrentPlan();
      if (cancelled) return;
      const pivot = res.data?.pivot;
      const status = pivot?.status?.toLowerCase();
      const active = status === 'active' || status === 'trialing'
        || (status === 'cancelled' && pivot?.expires_at && new Date(pivot.expires_at) > new Date());
      const displayName = res.data?.plan?.display_name;
      // Only update the label once we have a definitive answer: set it when the
      // API confirms an active plan, clear it when it confirms none. A failed
      // request leaves the cached label untouched (no flicker to "PRO").
      if (res.success && active) {
        setPlanName(displayName);
      } else if (res.success && !active) {
        setPlanName(undefined);
      }

      // The API is the source of truth: if it confirms an active subscription,
      // promote the role + seed localStorage so a plain page refresh (which never
      // re-ran login's completeAuth) doesn't leave a paying user showing as FREE.
      // We only PROMOTE here — never downgrade on a failed/blank response — so a
      // transient network error can't wrongly knock a paid user down to Free.
      const subId = pivot?.stripe_subscription_id || pivot?.paypal_subscription_id;
      if (res.success && active && subId) {
        localStorage.setItem('active_subscription', JSON.stringify({
          subscriptionId: subId,
          details: { status: pivot.status, plan_id: pivot.stripe_price_id ?? pivot.plan_id },
          cancelled_at: pivot.cancelled_at,
          expires_at: pivot.expires_at ?? pivot.current_period_end,
          // Cache the tier label so the badge paints it immediately on next load.
          plan_display_name: displayName,
          createdAt: Date.now(),
        }));
        setUserRole({
          role: 'pro',
          hasActiveSubscription: true,
          subscriptionStatus: pivot.status,
          subscriptionId: subId,
        });
      }
    };
    loadPlanName();
    window.addEventListener('subscriptionUpdated', loadPlanName);
    return () => { cancelled = true; window.removeEventListener('subscriptionUpdated', loadPlanName); };
  }, [user]);

  useEffect(() => {
    if (!user) {
      setUserRole({
        role: 'free',
        hasActiveSubscription: false,
      });
      return;
    }

    // Check for active subscription in localStorage
    const checkSubscription = () => {
      try {
        const activeSubscription = localStorage.getItem('active_subscription');
        if (activeSubscription) {
          const subscription = JSON.parse(activeSubscription);
          // Give pro access if:
          // 1. Status is 'active' (case-insensitive), OR
          // 2. Status is 'cancelled' but expires_at is still in future (grace period)
          const status = subscription.details?.status?.toLowerCase();
          const isActive = status === 'active' || status === 'trialing';
          const isCancelledWithGrace = status === 'cancelled' &&
                                      subscription.expires_at &&
                                      new Date(subscription.expires_at) > new Date();

          if (subscription.subscriptionId && (isActive || isCancelledWithGrace)) {
            setUserRole({
              role: 'pro',
              hasActiveSubscription: true,
              subscriptionStatus: subscription.details?.status || 'ACTIVE',
              subscriptionId: subscription.subscriptionId,
            });
            return;
          }
          // If subscription exists but is invalid, fall through to set free role
          // Note: Don't remove localStorage here - let Plans.tsx manage it to prevent loops
        }
      } catch (error) {
        console.error('Error checking subscription:', error);
        // Don't remove localStorage on parse error - could be transient issue
      }

      // Dummy data for testing - simulate different user roles
      const userEmail = user.email?.toLowerCase();
      
      // Demo users for testing
      if (userEmail?.includes('pro') || userEmail?.includes('paid')) {
        setUserRole({
          role: 'pro',
          hasActiveSubscription: true,
          subscriptionStatus: 'ACTIVE',
          subscriptionId: 'dummy-pro-subscription-123',
        });
      } else {
        setUserRole({
          role: 'free',
          hasActiveSubscription: false,
        });
      }
    };

    checkSubscription();

    // Listen for subscription updates via custom event only
    // Note: We don't listen to 'storage' event because:
    // 1. It's meant for cross-tab communication
    // 2. Multiple components modifying localStorage can cause infinite loops
    // 3. We use custom 'subscriptionUpdated' event for same-tab updates
    const handleSubscriptionUpdate = () => {
      console.log('[useUserRole] Subscription update event received');
      checkSubscription();
    };

    window.addEventListener('subscriptionUpdated', handleSubscriptionUpdate);

    return () => {
      window.removeEventListener('subscriptionUpdated', handleSubscriptionUpdate);
    };
  }, [user]);

  const isPaidUser = userRole.hasActiveSubscription;
  const isProUser = userRole.role === 'pro' && userRole.hasActiveSubscription;
  const isFreeUser = userRole.role === 'free' || !userRole.hasActiveSubscription;

  return (
    <UserRoleContext.Provider
      value={{
        userRole,
        setUserRole,
        isPaidUser,
        isProUser,
        isFreeUser,
        planName,
      }}
    >
      {children}
    </UserRoleContext.Provider>
  );
};

