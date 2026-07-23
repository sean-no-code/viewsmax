import { Badge } from "@/components/ui/badge";
import { Zap } from "lucide-react";
import { cn } from "@/lib/utils";
import { iconForPlan } from "@/lib/plan-helpers";
import type { UserRole } from "@/hooks/useUserRole";

interface RoleBadgeProps {
  role: UserRole;
  /** Actual tier name to show for paid users (e.g. "Creator"). Falls back to "PRO". */
  planName?: string;
  className?: string;
  showIcon?: boolean;
}

export const RoleBadge = ({ role, planName, className, showIcon = true }: RoleBadgeProps) => {
  const isProUser = role === 'pro';
  const label = isProUser ? (planName ?? 'PRO') : 'FREE';
  // Per-tier icon for paid users (matches the pricing cards); Zap for free.
  const PaidIcon = iconForPlan(planName);

  return (
    <Badge
      variant={isProUser ? "default" : "secondary"}
      className={cn(
        "text-xs font-medium",
        isProUser 
          ? "bg-gradient-to-r from-yellow-500 to-orange-500 text-white border-yellow-400 hover:from-yellow-600 hover:to-orange-600" 
          : "bg-gray-100 text-gray-700 border-gray-200 hover:bg-gray-200",
        className
      )}
    >
      {showIcon && (
        isProUser ? (
          <PaidIcon className="w-3 h-3 mr-1" />
        ) : (
          <Zap className="w-3 h-3 mr-1" />
        )
      )}
      {label}
    </Badge>
  );
};


