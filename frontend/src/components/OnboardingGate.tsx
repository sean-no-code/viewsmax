import { Navigate, useLocation } from "react-router-dom";
import { useAuth } from "@/hooks/useAuth";
import { isAccessExpired } from "@/lib/access";

const BILLING_PATH = "/dashboard/billing";

interface OnboardingGateProps {
  children: React.ReactNode;
}

// Nested INSIDE ProtectedRoute, so `user` is guaranteed to exist here. Redirects
// authenticated-but-not-yet-onboarded users to the onboarding wizard before they
// can reach any /dashboard/* route. Existing users are grandfathered by the
// backend (onboarding_completed_at backfilled), so they pass straight through.
// A promotional customer whose free window has closed is pinned to Billing
// (the API refuses everything else with 403 access_expired anyway).
const OnboardingGate = ({ children }: OnboardingGateProps) => {
  const { user, loading } = useAuth();
  const { pathname } = useLocation();

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="animate-pulse text-lg">Loading...</div>
      </div>
    );
  }

  if (user && isAccessExpired(user)) {
    return pathname === BILLING_PATH ? <>{children}</> : <Navigate to={BILLING_PATH} replace />;
  }

  if (user && !user.onboarding_completed_at) {
    return <Navigate to="/onboarding" replace />;
  }

  return <>{children}</>;
};

export default OnboardingGate;
