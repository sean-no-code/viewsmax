import { useEffect, useRef, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { Loader2, CheckCircle2, XCircle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { useAuth } from "@/hooks/useAuth";
import { viewsMaxApi } from "@/lib/api-service";
import { toast } from "sonner";
import { useTranslation } from "react-i18next";

type Status = "verifying" | "success" | "error";

const VerifyEmail = () => {
  const navigate = useNavigate();
  const { t } = useTranslation();
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
      setErrorMessage(t("verifyEmail.errors.missingToken", "This verification link is missing its token."));
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
        setErrorMessage(result.error || t("verifyEmail.errors.invalidOrExpired", "This verification link is invalid or has expired."));
      }
    })();
  }, [token, setAuthData, navigate, t]);

  const handleResend = async () => {
    if (!resendEmail) {
      toast.error(t("verifyEmail.resend.emailRequired", "Enter your email to resend the link."));
      return;
    }
    setResending(true);
    const result = await viewsMaxApi.resendVerification(resendEmail);
    setResending(false);
    if (result.success) {
      toast.success(t("verifyEmail.resend.sent", "Verification email sent. Check your inbox."));
    } else {
      toast.error(result.error || t("verifyEmail.resend.failed", "Couldn't resend the email."));
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-background p-4">
      <Card className="w-full max-w-md">
        <CardHeader className="text-center">
          {status === "verifying" && (
            <>
              <Loader2 className="mx-auto h-10 w-10 animate-spin text-primary" />
              <CardTitle className="mt-4 text-2xl">{t("verifyEmail.verifying.title", "Verifying your email")}</CardTitle>
              <CardDescription>{t("verifyEmail.verifying.description", "This should only take a moment…")}</CardDescription>
            </>
          )}
          {status === "success" && (
            <>
              <CheckCircle2 className="mx-auto h-10 w-10 text-green-500" />
              <CardTitle className="mt-4 text-2xl">{t("verifyEmail.success.title", "Email verified")}</CardTitle>
              <CardDescription>{t("verifyEmail.success.description", "Taking you to set up your account…")}</CardDescription>
            </>
          )}
          {status === "error" && (
            <>
              <XCircle className="mx-auto h-10 w-10 text-destructive" />
              <CardTitle className="mt-4 text-2xl">{t("verifyEmail.error.title", "Verification failed")}</CardTitle>
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
              placeholder={t("verifyEmail.resend.placeholder", "Enter your email to resend")}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
            />
            <Button className="w-full" onClick={handleResend} disabled={resending}>
              {resending ? t("verifyEmail.resend.sending", "Sending…") : t("verifyEmail.resend.button", "Resend verification email")}
            </Button>
            <Button variant="link" className="w-full" onClick={() => navigate("/auth")}>
              {t("verifyEmail.backToSignIn", "Back to sign in")}
            </Button>
          </CardContent>
        )}
      </Card>
    </div>
  );
};

export default VerifyEmail;
