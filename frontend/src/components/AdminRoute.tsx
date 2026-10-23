import type { ReactNode } from "react";
import { Navigate } from "react-router-dom";
import { useAuth } from "@/hooks/useAuth";
import { inLimitedReleaseAudience } from "@/lib/access";

// Admin-only section of the dashboard. Sits inside ProtectedRoute, so the
// user is already loaded; anyone outside the audience is sent to the dashboard
// home. `allowPromo` opens the page to promotional customers too (the
// limited-release audience, see src/lib/access.ts).
const AdminRoute = ({ children, allowPromo = false }: { children: ReactNode; allowPromo?: boolean }) => {
  const { user } = useAuth();
  const allowed = allowPromo ? inLimitedReleaseAudience(user) : !!user?.is_admin;
  if (!allowed) return <Navigate to="/dashboard" replace />;
  return <>{children}</>;
};

export default AdminRoute;
