import { useState, useEffect } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { toast } from "sonner";
import { useNavigate, useSearchParams } from "react-router-dom";
import { Eye, EyeOff, Check, X, Mail } from "lucide-react";
import { useAuth } from "@/hooks/useAuth";
import { viewsMaxApi } from "@/lib/api-service";

const Auth = () => {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const initialTab = searchParams.get("tab") === "signup" ? "signup" : "signin";
  const { user, setAuthData } = useAuth();
  const [email, setEmail] = useState("");
  const [name, setName] = useState("");
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);
  const [showForgotPassword, setShowForgotPassword] = useState(false);
  const [forgotPasswordEmail, setForgotPasswordEmail] = useState("");
  const [joinList, setJoinList] = useState(true);
  // Email verification (magic link) state
  const [signupComplete, setSignupComplete] = useState(false);
  const [pendingEmail, setPendingEmail] = useState("");
  const [resendCooldown, setResendCooldown] = useState(0);
  const [unverifiedEmail, setUnverifiedEmail] = useState("");

  // Tick down the resend cooldown timer.
  useEffect(() => {
    if (resendCooldown <= 0) return;
    const timer = setTimeout(() => setResendCooldown((s) => s - 1), 1000);
    return () => clearTimeout(timer);
  }, [resendCooldown]);

  const handleResendVerification = async (targetEmail: string) => {
    if (!targetEmail || resendCooldown > 0) return;
    setResendCooldown(45);
    const result = await viewsMaxApi.resendVerification(targetEmail);
    if (result.success) {
      toast.success("Verification email sent. Check your inbox.");
    } else {
      toast.error(result.error || "Couldn't resend the email. Try again shortly.");
    }
  };

  // Password validation
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
      isValid: hasMinLength && hasUpperCase && hasLowerCase && hasSpecialChar
    };
  };

  const passwordValidation = validatePassword(password);

  useEffect(() => {
    // Check if user is already logged in. Send users who haven't finished
    // onboarding to the wizard instead of the dashboard (the OnboardingGate
    // would otherwise bounce them there anyway).
    if (user) {
      // navigate(user.onboarding_completed_at ? "/dashboard/thumbnails" : "/onboarding");
      navigate(user.onboarding_completed_at ? "/dashboard/post" : "/onboarding");
    }
  }, [user, navigate]);

  const cleanupAuthState = () => {
    Object.keys(localStorage).forEach((key) => {
      if (key.startsWith('supabase.auth.') || key.includes('sb-')) {
        localStorage.removeItem(key);
      }
    });
  };

  const handleForgotPassword = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!forgotPasswordEmail) {
      toast.error("Please enter your email");
      return;
    }

    setIsLoading(true);
    try {
      const result = await viewsMaxApi.forgotPassword(forgotPasswordEmail);

      if (result.success) {
        toast.success("Password reset email sent! Check your inbox.");
        setShowForgotPassword(false);
        setForgotPasswordEmail("");
      } else {
        toast.error(result.error || "Failed to send reset email. Please try again.");
      }
    } catch (error) {
      console.error('Forgot password error:', error);
      toast.error("An unexpected error occurred. Please try again.");
    } finally {
      setIsLoading(false);
    }
  };

  // Helper: setup auth session, fetch subscription, then set auth data and navigate
  const completeAuth = async (authData: { token: string; token_type: string; user: any }) => {
    viewsMaxApi.setAuthSession({ token: authData.token, token_type: authData.token_type });

    // Fetch subscription BEFORE setAuthData (which triggers re-renders)
    let hasPro = false;
    try {
      const planResult = await viewsMaxApi.getCurrentPlan();
      const pivot = planResult.data?.pivot;
      // Recognize either a PayPal or a Stripe subscription (both coexist).
      const subId = pivot?.paypal_subscription_id || pivot?.stripe_subscription_id;
      if (planResult.success && subId) {
        localStorage.setItem("active_subscription", JSON.stringify({
          subscriptionId: subId,
          details: { status: pivot.status, plan_id: pivot.paypal_plan_id ?? pivot.plan_id },
          cancelled_at: pivot.cancelled_at,
          expires_at: pivot.expires_at ?? pivot.current_period_end,
          createdAt: Date.now()
        }));
        // Dispatch event so other components know subscription is loaded
        window.dispatchEvent(new CustomEvent("subscriptionUpdated"));
        hasPro = true;
      } else {
        // No subscription found, ensure localStorage is clear
        localStorage.removeItem("active_subscription");
        window.dispatchEvent(new CustomEvent("subscriptionUpdated"));
      }
    } catch { /* ignore */ }

    setAuthData(authData);
    // Navigate to appropriate page
    // navigate("/dashboard/thumbnails", { replace: true });
    navigate("/dashboard/post", { replace: true });
  };

  const handleSignIn = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email || !password) {
      toast.error("Please fill in all fields");
      return;
    }

    setIsLoading(true);
    try {
      const result = await viewsMaxApi.login(email, password);
      if (result.success && result.data) {
        await completeAuth(result.data);
        toast.success("Successfully signed in!");
      } else if (result.error && /not_verified|not verified/i.test(result.error)) {
        // Account exists but the email hasn't been confirmed yet.
        setUnverifiedEmail(email);
        toast.error("Please verify your email first. We can resend the link below.");
      } else {
        toast.error(result.error || "Invalid email or password. Please check your credentials.");
      }
    } catch (error) {
      console.error('Sign in error:', error);
      toast.error("An unexpected error occurred. Please try again.");
    } finally {
      setIsLoading(false);
    }
  };

  const handleSignUp = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email || !name || !password || !confirmPassword) {
      toast.error("Please fill in all fields");
      return;
    }

    if (password !== confirmPassword) {
      toast.error("Passwords don't match");
      return;
    }

    const validation = validatePassword(password);
    if (!validation.isValid) {
      toast.error("Password must meet all requirements");
      return;
    }

    setIsLoading(true);
    try {
      const result = await viewsMaxApi.register(name, email, password, confirmPassword, joinList);
      if (result.success) {
        // Do NOT log the user in. They must confirm their email via the magic
        // link before the account becomes usable.
        setPendingEmail(email);
        setSignupComplete(true);
        setResendCooldown(45);
        toast.success("Account created! Check your email to confirm.");
      } else {
        if (result.error && result.error.includes("already exists")) {
          toast.error("An account with this email already exists. Try signing in instead.");
        } else {
          toast.error(result.error || "Failed to create account. Please try again.");
        }
      }
    } catch (error) {
      console.error('Sign up error:', error);
      toast.error("An unexpected error occurred. Please try again.");
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-background p-4">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle className="text-2xl text-center">Welcome</CardTitle>
          <CardDescription className="text-center">
            Sign in to your account or create a new one
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Tabs defaultValue={initialTab} className="w-full">
            <TabsList className="grid w-full grid-cols-2">
              <TabsTrigger value="signin">Sign In</TabsTrigger>
              <TabsTrigger value="signup">Sign Up</TabsTrigger>
            </TabsList>

            <TabsContent value="signin">
              {!showForgotPassword ? (
                <form onSubmit={handleSignIn} className="space-y-4">
                  <div className="space-y-2">
                    <Label htmlFor="email">Email</Label>
                    <Input
                      id="email"
                      type="email"
                      value={email}
                      onChange={(e) => setEmail(e.target.value)}
                      placeholder="Enter your email"
                      required
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="password">Password</Label>
                    <div className="relative">
                      <Input
                        id="password"
                        type={showPassword ? "text" : "password"}
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        placeholder="Enter your password"
                        required
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
                  </div>
                  <Button type="submit" className="w-full" disabled={isLoading}>
                    {isLoading ? "Signing in..." : "Sign In"}
                  </Button>
                  {unverifiedEmail && (
                    <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
                      <p className="mb-2">
                        Your email isn't verified yet. Check your inbox or resend the link.
                      </p>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="w-full"
                        disabled={resendCooldown > 0}
                        onClick={() => handleResendVerification(unverifiedEmail)}
                      >
                        {resendCooldown > 0 ? `Resend email (${resendCooldown}s)` : "Resend verification email"}
                      </Button>
                    </div>
                  )}
                  <div className="text-center">
                    <Button
                      type="button"
                      variant="link"
                      size="sm"
                      onClick={() => setShowForgotPassword(true)}
                      className="text-sm text-muted-foreground"
                    >
                      Forgot your password?
                    </Button>
                  </div>
                </form>
              ) : (
                <form onSubmit={handleForgotPassword} className="space-y-4">
                  <div className="space-y-2">
                    <Label htmlFor="forgot-email">Email</Label>
                    <Input
                      id="forgot-email"
                      type="email"
                      value={forgotPasswordEmail}
                      onChange={(e) => setForgotPasswordEmail(e.target.value)}
                      placeholder="Enter your email"
                      required
                    />
                  </div>
                  <Button type="submit" className="w-full" disabled={isLoading}>
                    {isLoading ? "Sending..." : "Send Reset Email"}
                  </Button>
                  <div className="text-center">
                    <Button
                      type="button"
                      variant="link"
                      size="sm"
                      onClick={() => setShowForgotPassword(false)}
                      className="text-sm text-muted-foreground"
                    >
                      Back to sign in
                    </Button>
                  </div>
                </form>
              )}
            </TabsContent>

            <TabsContent value="signup">
              {signupComplete ? (
                <div className="space-y-4 text-center py-4">
                  <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-primary/10">
                    <Mail className="h-6 w-6 text-primary" />
                  </div>
                  <div className="space-y-1">
                    <h3 className="text-lg font-semibold">Check your email</h3>
                    <p className="text-sm text-muted-foreground">
                      We sent a confirmation link to{" "}
                      <span className="font-medium text-foreground">{pendingEmail}</span>.
                      Click it to verify your account and continue setup.
                    </p>
                  </div>
                  <Button
                    type="button"
                    variant="outline"
                    className="w-full"
                    disabled={resendCooldown > 0}
                    onClick={() => handleResendVerification(pendingEmail)}
                  >
                    {resendCooldown > 0 ? `Resend email (${resendCooldown}s)` : "Resend email"}
                  </Button>
                  <Button
                    type="button"
                    variant="link"
                    size="sm"
                    className="text-muted-foreground"
                    onClick={() => {
                      setSignupComplete(false);
                      setPendingEmail("");
                    }}
                  >
                    Back to sign up
                  </Button>
                </div>
              ) : (
              <form onSubmit={handleSignUp} className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="signup-name">Name</Label>
                  <Input
                    id="signup-name"
                    type="text"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder="Enter your name"
                    required
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="signup-email">Email</Label>
                  <Input
                    id="signup-email"
                    type="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    placeholder="Enter your email"
                    required
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="signup-password">Password</Label>
                  <div className="relative">
                    <Input
                      id="signup-password"
                      type={showPassword ? "text" : "password"}
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      placeholder="Create a password"
                      required
                      className={`${password && !passwordValidation.isValid
                          ? 'border-red-500 focus-visible:ring-red-500'
                          : password && passwordValidation.isValid
                            ? 'border-green-500 focus-visible:ring-green-500'
                            : ''
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
                      <div className={`flex items-center gap-2 ${passwordValidation.hasMinLength ? 'text-green-600' : 'text-red-500'
                        }`}>
                        {passwordValidation.hasMinLength ? (
                          <Check className="w-4 h-4" />
                        ) : (
                          <X className="w-4 h-4" />
                        )}
                        <span>At least 8 characters</span>
                      </div>
                      <div className={`flex items-center gap-2 ${passwordValidation.hasUpperCase ? 'text-green-600' : 'text-red-500'
                        }`}>
                        {passwordValidation.hasUpperCase ? (
                          <Check className="w-4 h-4" />
                        ) : (
                          <X className="w-4 h-4" />
                        )}
                        <span>At least one uppercase letter</span>
                      </div>
                      <div className={`flex items-center gap-2 ${passwordValidation.hasLowerCase ? 'text-green-600' : 'text-red-500'
                        }`}>
                        {passwordValidation.hasLowerCase ? (
                          <Check className="w-4 h-4" />
                        ) : (
                          <X className="w-4 h-4" />
                        )}
                        <span>At least one lowercase letter</span>
                      </div>
                      <div className={`flex items-center gap-2 ${passwordValidation.hasSpecialChar ? 'text-green-600' : 'text-red-500'
                        }`}>
                        {passwordValidation.hasSpecialChar ? (
                          <Check className="w-4 h-4" />
                        ) : (
                          <X className="w-4 h-4" />
                        )}
                        <span>At least one special character</span>
                      </div>
                    </div>
                  )}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="confirm-password">Confirm Password</Label>
                  <div className="relative">
                    <Input
                      id="confirm-password"
                      type={showConfirmPassword ? "text" : "password"}
                      value={confirmPassword}
                      onChange={(e) => setConfirmPassword(e.target.value)}
                      placeholder="Confirm your password"
                      required
                      minLength={6}
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
                <div className="flex items-center space-x-2">
                  <Checkbox
                    id="join-list"
                    checked={joinList}
                    onCheckedChange={(checked) => setJoinList(checked === true)}
                  />
                  <Label
                    htmlFor="join-list"
                    className="text-sm font-normal cursor-pointer"
                  >
                    Join the list. Grow faster every week.
                  </Label>
                </div>
                <Button type="submit" className="w-full" disabled={isLoading}>
                  {isLoading ? "Creating account..." : "Sign Up"}
                </Button>
              </form>
              )}
            </TabsContent>
          </Tabs>
        </CardContent>
      </Card>
    </div>
  );
};

export default Auth;