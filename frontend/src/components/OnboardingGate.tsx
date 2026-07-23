import { Navigate } from "react-router-dom";
import { useAuth } from "@/hooks/useAuth";

interface OnboardingGateProps {
  children: React.ReactNode;
}

// Nested INSIDE ProtectedRoute, so `user` is guaranteed to exist here. Redirects
// authenticated-but-not-yet-onboarded users to the onboarding wizard before they
// can reach any /dashboard/* route. Existing users are grandfathered by the
// backend (onboarding_completed_at backfilled), so they pass straight through.
const OnboardingGate = ({ children }: OnboardingGateProps) => {
  const { user, loading } = useAuth();

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="animate-pulse text-lg">Loading...</div>
      </div>
    );
  }

  if (user && !user.onboarding_completed_at) {
    return <Navigate to="/onboarding" replace />;
  }

  return <>{children}</>;
};

export default OnboardingGate;
