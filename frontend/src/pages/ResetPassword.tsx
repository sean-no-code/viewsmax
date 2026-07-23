import { useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { Eye, EyeOff, Check, X, CheckCircle2, XCircle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { viewsMaxApi } from "@/lib/api-service";
import { toast } from "sonner";

// Mirrors the rules enforced on sign-up (see Auth.tsx) and the backend's
// `min:8` so the form fails fast before hitting the API.
const validatePassword = (pwd: string) => {
  const hasMinLength = pwd.length >= 8;
  const hasUpperCase = /[A-Z]/.test(pwd);
  const hasLowerCase = /[a-z]/.test(pwd);
  const hasSpecialChar = /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(pwd);
  return {
    hasMinLength,
    hasUpperCase,
    hasLowerCase,
    hasSpecialChar,
    isValid: hasMinLength && hasUpperCase && hasLowerCase && hasSpecialChar,
  };
};

const ResetPassword = () => {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  // The reset email links here as /reset-password?token=...&email=...
  const token = searchParams.get("token") ?? "";
  const email = searchParams.get("email") ?? "";

  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [done, setDone] = useState(false);

  const validation = validatePassword(password);
  const linkValid = Boolean(token && email);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!validation.isValid) {
      toast.error("Password must meet all requirements");
      return;
    }
    if (password !== confirmPassword) {
      toast.error("Passwords don't match");
      return;
    }

    setIsLoading(true);
    try {
      const result = await viewsMaxApi.resetPassword(email, token, password, confirmPassword);
      if (result.success) {
        setDone(true);
        toast.success("Password reset! You can now sign in.");
      } else {
        toast.error(result.error || "Couldn't reset your password. The link may have expired.");
      }
    } catch (error) {
      console.error("Reset password error:", error);
      toast.error("An unexpected error occurred. Please try again.");
    } finally {
      setIsLoading(false);
    }
  };

  // Missing/garbled link — nothing we can do without token + email.
  if (!linkValid) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background p-4">
        <Card className="w-full max-w-md">
          <CardHeader className="text-center">
            <XCircle className="mx-auto h-10 w-10 text-destructive" />
            <CardTitle className="mt-4 text-2xl">Invalid reset link</CardTitle>
            <CardDescription>
              This password reset link is missing information or malformed. Request a new one.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Button className="w-full" onClick={() => navigate("/auth")}>
              Back to sign in
            </Button>
          </CardContent>
        </Card>
      </div>
    );
  }

  // Success state.
  if (done) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background p-4">
        <Card className="w-full max-w-md">
          <CardHeader className="text-center">
            <CheckCircle2 className="mx-auto h-10 w-10 text-green-500" />
            <CardTitle className="mt-4 text-2xl">Password reset</CardTitle>
            <CardDescription>Your password has been updated. Sign in with your new password.</CardDescription>
          </CardHeader>
          <CardContent>
            <Button className="w-full" onClick={() => navigate("/auth")}>
              Go to sign in
            </Button>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-background p-4">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle className="text-2xl text-center">Reset your password</CardTitle>
          <CardDescription className="text-center">
            Choose a new password for <span className="font-medium text-foreground">{email}</span>
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="new-password">New password</Label>
              <div className="relative">
                <Input
                  id="new-password"
                  type={showPassword ? "text" : "password"}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="Create a password"
                  required
                  className={`${password && !validation.isValid
                      ? "border-red-500 focus-visible:ring-red-500"
                      : password && validation.isValid
                        ? "border-green-500 focus-visible:ring-green-500"
                        : ""
                    }`}
                />
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="absolute right-0 top-0 h-full px-3"
                  onClick={() => setShowPassword(!showPassword)}
                >
                  {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </Button>
              </div>
              {password && (
                <div className="space-y-1.5 text-sm">
                  <div className={`flex items-center gap-2 ${validation.hasMinLength ? "text-green-600" : "text-red-500"}`}>
                    {validation.hasMinLength ? <Check className="w-4 h-4" /> : <X className="w-4 h-4" />}
                    <span>At least 8 characters</span>
                  </div>
                  <div className={`flex items-center gap-2 ${validation.hasUpperCase ? "text-green-600" : "text-red-500"}`}>
                    {validation.hasUpperCase ? <Check className="w-4 h-4" /> : <X className="w-4 h-4" />}
                    <span>At least one uppercase letter</span>
                  </div>
                  <div className={`flex items-center gap-2 ${validation.hasLowerCase ? "text-green-600" : "text-red-500"}`}>
                    {validation.hasLowerCase ? <Check className="w-4 h-4" /> : <X className="w-4 h-4" />}
                    <span>At least one lowercase letter</span>
                  </div>
                  <div className={`flex items-center gap-2 ${validation.hasSpecialChar ? "text-green-600" : "text-red-500"}`}>
                    {validation.hasSpecialChar ? <Check className="w-4 h-4" /> : <X className="w-4 h-4" />}
                    <span>At least one special character</span>
                  </div>
                </div>
              )}
            </div>
            <div className="space-y-2">
              <Label htmlFor="confirm-new-password">Confirm password</Label>
              <div className="relative">
                <Input
                  id="confirm-new-password"
                  type={showConfirmPassword ? "text" : "password"}
                  value={confirmPassword}
                  onChange={(e) => setConfirmPassword(e.target.value)}
                  placeholder="Confirm your password"
                  required
                />
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="absolute right-0 top-0 h-full px-3"
                  onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                >
                  {showConfirmPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </Button>
              </div>
            </div>
            <Button type="submit" className="w-full" disabled={isLoading}>
              {isLoading ? "Resetting…" : "Reset password"}
            </Button>
            <div className="text-center">
              <Button
                type="button"
                variant="link"
                size="sm"
                onClick={() => navigate("/auth")}
                className="text-sm text-muted-foreground"
              >
                Back to sign in
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  );
};

export default ResetPassword;
