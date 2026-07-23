import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Check, Zap, Crown, ArrowRight, /* Sparkles, */ Info } from "lucide-react";
import { useState, useEffect } from "react";
import { useAuth } from "@/hooks/useAuth";
import { useNavigate } from "react-router-dom";
import {
	Tooltip,
	TooltipContent,
	TooltipProvider,
	TooltipTrigger,
} from "@/components/ui/tooltip";
import { calculateUsageFromCredits } from "@/lib/credits-config";

const Pricing = () => {
  // Calculate usage from credits
  const proCredits = 1000;
  // const eliteCredits = 220;
  const proUsage = calculateUsageFromCredits(proCredits);
  // const eliteUsage = calculateUsageFromCredits(eliteCredits);

  const plans = [
    {
      name: "Creator Pro",
      price: "$29",
      period: "month",
      description: "Everything you need to go viral",
      icon: Crown,
      credits: proCredits,
      usage: proUsage,
      features: [
        "Unlimited Content Reviews",
        "Retention-Focused Script Writer (10 min max)",
        "Scroll Stopping Thumbnails",
        "Viral Idea Finder"
      ],
      cta: "Start Free Trial",
      popular: true,
      variant: "cta" as const
    },
    // {
    //   name: "Creator Elite",
    //   price: "$69",
    //   period: "month",
    //   description: "For serious creators who want it all",
    //   icon: Sparkles,
    //   credits: eliteCredits,
    //   usage: eliteUsage,
    //   features: [
    //     "Everything in Creator Pro",
    //     "Longer Scripts (unlimited)",
    //     "Generate Multiple Thumbnails"
    //   ],
    //   cta: "Start Free Trial",
    //   popular: false,
    //   variant: "default" as const
    // }
  ];

  const navigate = useNavigate();
  const { user } = useAuth();
  const [hasActiveSubscription, setHasActiveSubscription] = useState(false);

  useEffect(() => {
    const checkSubscription = () => {
      try {
        const raw = localStorage.getItem("active_subscription");
        setHasActiveSubscription(Boolean(raw));
      } catch (_e) {
        setHasActiveSubscription(false);
      }
    };

    // Check on mount
    checkSubscription();

    // Listen for subscription updates
    const handleSubscriptionUpdate = () => {
      checkSubscription();
    };

    window.addEventListener("subscriptionUpdated", handleSubscriptionUpdate);
    window.addEventListener("focus", handleSubscriptionUpdate);

    return () => {
      window.removeEventListener("subscriptionUpdated", handleSubscriptionUpdate);
      window.removeEventListener("focus", handleSubscriptionUpdate);
    };
  }, []);

  const handlePlanClick = (planName: string, price: string) => {
    if (!user) {
      navigate("/auth");
      return;
    }
    
    // If subscription is active, redirect to billing page
    if (hasActiveSubscription) {
      navigate("/dashboard/billing");
      return;
    }
    
    // Navigate to checkout page with plan details
    if (planName === "Creator Pro") {
      navigate("/checkout?plan=Creator Pro&price=29&period=month&description=Monthly Subscription");
    }
    // else if (planName === "Creator Elite") {
    //   navigate("/checkout?plan=Creator Elite&price=69&period=month&description=Monthly Subscription");
    // }
  };

  return (
    <section id="pricing" className="py-20 bg-background">
      <div className="container mx-auto px-4">
        <div className="text-center mb-16">
          <h2 className="text-3xl md:text-4xl font-bold text-foreground mb-4">
            Choose Your Growth Plan
          </h2>
          <p className="text-lg text-muted-foreground max-w-2xl mx-auto">
            Try it out for only $1, then upgrade when you're ready to dominate YouTube
          </p>
        </div>

        <div className="flex justify-center max-w-5xl mx-auto">
          {plans.map((plan, index) => (
            <Card 
              key={index} 
              className={`relative transition-all duration-300 hover:shadow-card ${
                plan.popular 
                  ? 'border-primary/50 shadow-primary ring-1 ring-primary/20 scale-105' 
                  : 'border-border/50 hover:border-primary/20'
              }`}
            >
              {plan.popular && (
                <div className="absolute -top-3 left-1/2 transform -translate-x-1/2">
                  <div className="bg-gradient-primary text-primary-foreground px-4 py-1 rounded-full text-sm font-semibold">
                    Most Popular
                  </div>
                </div>
              )}

              <CardHeader className="text-center pb-4">
                <div className={`w-16 h-16 mx-auto rounded-full flex items-center justify-center mb-4 ${
                  plan.popular ? 'bg-gradient-primary' : 'bg-secondary'
                }`}>
                  <plan.icon className={`w-8 h-8 ${
                    plan.popular ? 'text-primary-foreground' : 'text-primary'
                  }`} />
                </div>
                <CardTitle className="text-2xl font-bold text-foreground">
                  {plan.name}
                </CardTitle>
                <CardDescription className="text-muted-foreground mb-4">
                  {plan.description}
                </CardDescription>
                <div className="mb-6">
                  <div className="flex items-baseline justify-center gap-1">
                    <span className="text-4xl font-bold text-foreground">{plan.price}</span>
                    <span className="text-muted-foreground">/{plan.period}</span>
                  </div>
                  {plan.name === "Creator Pro" && (
                    <p className="text-sm text-muted-foreground mt-2">
                      7 day trial for only $1, then {plan.price}/month
                    </p>
                  )}
                  {plan.credits && (
                    <div className="mt-4 flex items-center justify-center gap-2">
                      <span className="text-lg font-semibold text-foreground">{plan.credits} credits per month</span>
                      <TooltipProvider>
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Button variant="ghost" size="sm" className="h-5 w-5 p-0">
                              <Info className="h-4 w-4 text-muted-foreground" />
                            </Button>
                          </TooltipTrigger>
                          <TooltipContent className="max-w-xs">
                            <div className="space-y-1 text-left">
                              <p>Up To {plan.usage.titles} Titles per month</p>
                              <p>Up To {plan.usage.thumbnails} Thumbnails per month</p>
                              <p>Up To {plan.usage.scripts} Scripts per month</p>
                            </div>
                          </TooltipContent>
                        </Tooltip>
                      </TooltipProvider>
                    </div>
                  )}
                </div>
              </CardHeader>

              <CardContent className="flex flex-col space-y-6 h-full">
                <ul className="space-y-3">
                  {plan.features.map((feature, featureIndex) => (
                    <li key={featureIndex} className="flex items-center gap-3">
                      <Check className="w-5 h-5 text-green-500 flex-shrink-0" />
                      <span className="text-muted-foreground">{feature}</span>
                    </li>
                  ))}
                </ul>

                <Button 
                  variant={plan.variant} 
                  size="lg" 
                  className={`w-full group font-semibold ${/* plan.name === "Creator Elite" ? "mt-auto" : */ ""}`}
                  onClick={() => handlePlanClick(plan.name, plan.price)}
                >
                  {hasActiveSubscription ? "View Subscription" : plan.cta}
                  <ArrowRight className="w-4 h-4 group-hover:translate-x-1 transition-transform" />
                </Button>
              </CardContent>
            </Card>
          ))}
        </div>

        <div className="text-center mt-12">
          <p className="text-lg font-semibold text-foreground mb-2">
            Try it out for only $1, then upgrade when you're ready to dominate YouTube
          </p>
          <div className="flex items-center justify-center gap-6 text-sm text-muted-foreground">
            <div className="flex items-center gap-2">
              <Check className="w-4 h-4 text-green-500" />
              <span>Cancel anytime</span>
            </div>
            <div className="flex items-center gap-2">
              <Check className="w-4 h-4 text-green-500" />
              <span>$1 7 day trial</span>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
};

export default Pricing;