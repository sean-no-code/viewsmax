import { useEffect, useRef, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { Loader2, CheckCircle2, XCircle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { useAuth } from "@/hooks/useAuth";
import { viewsMaxApi } from "@/lib/api-service";
import { toast } from "sonner";

type Status = "verifying" | "success" | "error";

const VerifyEmail = () => {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { setAuthData } = useAuth();
  const token = searchParams.get("token");
  const [status, setStatus] = useState<Status>("verifying");
  const [errorMessage, setErrorMessage] = useState("");
  const [resendEmail, setResendEmail] = useState("");
  const [resending, setResending] = useState(false);
  // Guard against React 18 StrictMode double-invocation consuming the token twice.
  const hasRun = useRef(false);

  useEffect(() => {
    if (hasRun.current) return;
    hasRun.current = true;

    if (!token) {
      setStatus("error");
      setErrorMessage("This verification link is missing its token.");
      return;
    }

    (async () => {
      const result = await viewsMaxApi.verifyEmail(token);
      if (result.success && result.data) {
        setAuthData(result.data);
        setStatus("success");
        // Verified users still need to finish onboarding before the app.
        navigate("/onboarding", { replace: true });
      } else {
        setStatus("error");
        setErrorMessage(result.error || "This verification link is invalid or has expired.");
      }
    })();
  }, [token, setAuthData, navigate]);

  const handleResend = async () => {
    if (!resendEmail) {
      toast.error("Enter your email to resend the link.");
      return;
    }
    setResending(true);
    const result = await viewsMaxApi.resendVerification(resendEmail);
    setResending(false);
    if (result.success) {
      toast.success("Verification email sent. Check your inbox.");
    } else {
      toast.error(result.error || "Couldn't resend the email.");
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-background p-4">
      <Card className="w-full max-w-md">
        <CardHeader className="text-center">
          {status === "verifying" && (
            <>
              <Loader2 className="mx-auto h-10 w-10 animate-spin text-primary" />
              <CardTitle className="mt-4 text-2xl">Verifying your email</CardTitle>
              <CardDescription>This should only take a moment…</CardDescription>
            </>
          )}
          {status === "success" && (
            <>
              <CheckCircle2 className="mx-auto h-10 w-10 text-green-500" />
              <CardTitle className="mt-4 text-2xl">Email verified</CardTitle>
              <CardDescription>Taking you to set up your account…</CardDescription>
            </>
          )}
          {status === "error" && (
            <>
              <XCircle className="mx-auto h-10 w-10 text-destructive" />
              <CardTitle className="mt-4 text-2xl">Verification failed</CardTitle>
              <CardDescription>{errorMessage}</CardDescription>
            </>
          )}
        </CardHeader>
        {status === "error" && (
          <CardContent className="space-y-3">
            <input
              type="email"
              value={resendEmail}
              onChange={(e) => setResendEmail(e.target.value)}
              placeholder="Enter your email to resend"
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
            />
            <Button className="w-full" onClick={handleResend} disabled={resending}>
              {resending ? "Sending…" : "Resend verification email"}
            </Button>
            <Button variant="link" className="w-full" onClick={() => navigate("/auth")}>
              Back to sign in
            </Button>
          </CardContent>
        )}
      </Card>
    </div>
  );
};

export default VerifyEmail;
