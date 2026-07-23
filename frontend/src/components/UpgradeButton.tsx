import { Button } from "@/components/ui/button";
import { Crown, ArrowRight } from "lucide-react";
import { useNavigate } from "react-router-dom";
import { cn } from "@/lib/utils";

interface UpgradeButtonProps {
  className?: string;
  size?: "sm" | "default" | "lg";
  variant?: "default" | "outline" | "ghost" | "cta";
  children?: React.ReactNode;
  fullWidth?: boolean;
}

export const UpgradeButton = ({ 
  className, 
  size = "default", 
  variant = "cta",
  children,
  fullWidth = false
}: UpgradeButtonProps) => {
  const navigate = useNavigate();

  const handleUpgrade = () => {
    navigate("/checkout?plan=Creator Pro&price=29");
  };

  return (
    <Button
      onClick={handleUpgrade}
      variant={variant}
      size={size}
      className={cn(
        "group font-semibold bg-gradient-to-r from-yellow-500 to-orange-500 hover:from-yellow-600 hover:to-orange-600 text-white border-transparent",
        fullWidth && "w-full",
        className
      )}
    >
      <Crown className="w-4 h-4 mr-2" />
      {children || "Upgrade to Pro"}
      <ArrowRight className="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform" />
    </Button>
  );
};


