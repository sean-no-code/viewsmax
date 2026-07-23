import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { useAuth } from "@/hooks/useAuth";
import { useUserRole } from "@/hooks/useUserRole";
import { RoleBadge } from "@/components/ui/role-badge";
import { User, Crown, Calendar, Mail, Shield } from "lucide-react";
import { format } from "date-fns";

const Dashboard = () => {
  const { user } = useAuth();
  const { userRole, isPaidUser, isProUser, isFreeUser } = useUserRole();

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold text-foreground">Welcome back!</h1>
          <p className="text-muted-foreground mt-1">
            Here's an overview of your account and current plan.
          </p>
        </div>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        {/* Subscription & Role Card */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Crown className="w-5 h-5" />
              Subscription & Role
            </CardTitle>
            <CardDescription>
              Your current plan and account privileges
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-muted-foreground">Current Plan:</span>
              <RoleBadge role={userRole.role} />
            </div>

            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-muted-foreground">Subscription Status:</span>
              <Badge variant={userRole.hasActiveSubscription ? "default" : "secondary"}>
                {userRole.hasActiveSubscription ? "Active" : "Inactive"}
              </Badge>
            </div>

            {userRole.subscriptionStatus && (
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-muted-foreground">Status:</span>
                <span className="text-sm">{userRole.subscriptionStatus}</span>
              </div>
            )}

            {userRole.subscriptionId && (
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-muted-foreground">Subscription ID:</span>
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
              Account Information
            </CardTitle>
            <CardDescription>
              Your account details and current status
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-muted-foreground">Email:</span>
              <div className="flex items-center gap-2">
                <Mail className="w-4 h-4 text-muted-foreground" />
                <span className="text-sm">{user?.email}</span>
              </div>
            </div>
            
            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-muted-foreground">User ID:</span>
              <span className="text-sm font-mono text-muted-foreground">
                {user?.id?.toString().slice(0, 8)}...
              </span>
            </div>

            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-muted-foreground">Account Created:</span>
              <div className="flex items-center gap-2">
                <Calendar className="w-4 h-4 text-muted-foreground" />
                <span className="text-sm">
                  {user?.created_at ? format(new Date(user.created_at), 'MMM dd, yyyy') : 'N/A'}
                </span>
              </div>
            </div>

            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-muted-foreground">Email Verified:</span>
              <Badge variant="secondary">
                Pending
              </Badge>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
};

export default Dashboard;
