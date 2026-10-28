import type { ReactNode } from "react";
import { Navigate } from "react-router-dom";
import { useAuth } from "@/hooks/useAuth";
import { canUseAnalytics } from "@/lib/access";

// Analytics pages (Profile Performance, Post Performance, the YouTube channel
// analytics page) are part of the Pro tier. Sits inside ProtectedRoute, so the
// user is already loaded; anyone on a lower tier is sent to the dashboard home,
// where the sidebar simply doesn't offer these pages. Admins and promotional
// customers are exempt (see src/lib/access.ts).
const AnalyticsRoute = ({ children }: { children: ReactNode }) => {
  const { user } = useAuth();
  if (!canUseAnalytics(user)) return <Navigate to="/dashboard" replace />;
  return <>{children}</>;
};

export default AnalyticsRoute;
