import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "@/hooks/useAuth";
import { viewsMaxApi } from "@/lib/api-service";
import { endImpersonation, readImpersonator } from "@/lib/impersonation";

/**
 * Shown across the top of the dashboard while an admin is logged in as a
 * user. "Return to admin" revokes the user's token and restores the parked
 * admin session.
 */
export default function ImpersonationBar() {
  const { user, setAuthData } = useAuth();
  const navigate = useNavigate();
  const [busy, setBusy] = useState(false);
  const admin = readImpersonator();

  if (!admin) return null;

  const handleReturn = async () => {
    setBusy(true);
    try {
      await viewsMaxApi.logout(); // best effort: the token expires within the hour anyway
    } finally {
      endImpersonation(setAuthData);
      setBusy(false);
      navigate("/dashboard/admin/users");
    }
  };

  return (
    <div
      role="status"
      className="flex items-center justify-between gap-4 px-6 py-2 text-sm bg-amber-100 text-amber-900 border-b border-amber-300 dark:bg-amber-950/40 dark:text-amber-200 dark:border-amber-800"
    >
      <span>
        Logged in as <strong>{user?.email}</strong> (you are {admin.user?.email}).
      </span>
      <button
        type="button"
        onClick={handleReturn}
        disabled={busy}
        className="font-semibold underline underline-offset-2 disabled:opacity-50"
      >
        {busy ? "Returning…" : "Return to admin"}
      </button>
    </div>
  );
}
