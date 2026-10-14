import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { useAuth } from "@/hooks/useAuth";
import { useUserRole } from "@/hooks/useUserRole";
import { RoleBadge } from "@/components/ui/role-badge";
import { User, Crown, Calendar, Mail, Shield } from "lucide-react";
import { format } from "date-fns";
import { useTranslation } from "react-i18next";

const Dashboard = () => {
  const { t } = useTranslation();
  const { user } = useAuth();
  const { userRole, isPaidUser, isProUser, isFreeUser } = useUserRole();

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold text-foreground">{t("dashboard.welcome", "Welcome back!")}</h1>
          <p className="text-muted-foreground mt-1">
            {t("dashboard.subtitle", "Here's an overview of your account and current plan.")}
          </p>
        </div>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        {/* Subscription & Role Card */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Crown className="w-5 h-5" />
              {t("dashboard.subscription.title", "Subscription & Role")}
            </CardTitle>
            <CardDescription>
              {t("dashboard.subscription.description", "Your current plan and account privileges")}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-muted-foreground">{t("dashboard.subscription.currentPlan", "Current Plan:")}</span>
              <RoleBadge role={userRole.role} />
            </div>

            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-muted-foreground">{t("dashboard.subscription.subscriptionStatus", "Subscription Status:")}</span>
              <Badge variant={userRole.hasActiveSubscription ? "default" : "secondary"}>
                {userRole.hasActiveSubscription
                  ? t("dashboard.subscription.active", "Active")
                  : t("dashboard.subscription.inactive", "Inactive")}
              </Badge>
            </div>

            {userRole.subscriptionStatus && (
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-muted-foreground">{t("dashboard.subscription.status", "Status:")}</span>
                <span className="text-sm">{userRole.subscriptionStatus}</span>
              </div>
            )}

            {userRole.subscriptionId && (
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-muted-foreground">{t("dashboard.subscription.subscriptionId", "Subscription ID:")}</span>
                <span className="text-sm font-mono text-muted-foreground">
                  {userRole.subscriptionId.slice(0, 12)}...
                </span>
              </div>
            )}

           
          </CardContent>
        </Card>

        {/* User Information Card */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <User className="w-5 h-5" />
              {t("dashboard.account.title", "Account Information")}
            </CardTitle>
            <CardDescription>
              {t("dashboard.account.description", "Your account details and current status")}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-muted-foreground">{t("dashboard.account.email", "Email:")}</span>
              <div className="flex items-center gap-2">
                <Mail className="w-4 h-4 text-muted-foreground" />
                <span className="text-sm">{user?.email}</span>
              </div>
            </div>
            
            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-muted-foreground">{t("dashboard.account.userId", "User ID:")}</span>
              <span className="text-sm font-mono text-muted-foreground">
                {user?.id?.toString().slice(0, 8)}...
              </span>
            </div>

            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-muted-foreground">{t("dashboard.account.created", "Account Created:")}</span>
              <div className="flex items-center gap-2">
                <Calendar className="w-4 h-4 text-muted-foreground" />
                <span className="text-sm">
                  {user?.created_at ? format(new Date(user.created_at), 'MMM dd, yyyy') : t("dashboard.account.notAvailable", "N/A")}
                </span>
              </div>
            </div>

            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-muted-foreground">{t("dashboard.account.emailVerified", "Email Verified:")}</span>
              <Badge variant="secondary">
                {t("dashboard.account.pending", "Pending")}
              </Badge>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
};

export default Dashboard;
