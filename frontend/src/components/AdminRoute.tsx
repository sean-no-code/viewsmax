import type { ReactNode } from "react";
import { Navigate } from "react-router-dom";
import { useAuth } from "@/hooks/useAuth";

// Admin-only section of the dashboard. Sits inside ProtectedRoute, so the
// user is already loaded; anyone without `is_admin` is sent to the dashboard home.
const AdminRoute = ({ children }: { children: ReactNode }) => {
  const { user } = useAuth();
  if (!user?.is_admin) return <Navigate to="/dashboard" replace />;
  return <>{children}</>;
};

export default AdminRoute;
