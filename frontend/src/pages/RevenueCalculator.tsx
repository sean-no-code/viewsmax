import { useState, useEffect } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
} from "@/components/ui/chart";
import { LineChart, Line, XAxis, YAxis, CartesianGrid, ResponsiveContainer, Legend } from "recharts";
import { TrendingUp, DollarSign } from "lucide-react";
import { cn } from "@/lib/utils";
import { LandingNav, LandingFooter } from "@/pages/landing/LandingChrome";

interface CategoryRPM {
  category: string;
  rpm: number;
}

const CATEGORIES: CategoryRPM[] = [
  { category: "Finance", rpm: 10.95 },
  { category: "Technology", rpm: 4.95 },
  { category: "Health And Fitness", rpm: 3.5 },
  { category: "Education", rpm: 4.75 },
  { category: "Gaming", rpm: 1.5 },
  { category: "Beauty and Fashion", rpm: 2.8 },
  { category: "Travel And Adventure", rpm: 3.75 },
  { category: "DIY", rpm: 2.0 },
];

interface ProjectionData {
  month: string;
  conservative: number;
  moderate: number;
  aggressive: number;
}

const RevenueCalculator = () => {
  const [monthlyViews, setMonthlyViews] = useState("");
  const [currentSubscribers, setCurrentSubscribers] = useState("");
  const [contentCategory, setContentCategory] = useState("");
  const [projections, setProjections] = useState<ProjectionData[]>([]);
  const [hasCalculated, setHasCalculated] = useState(false);

  // SEO Metadata
  useEffect(() => {
    const title = "YouTube Monetization Calculator - Calculate Your Channel Revenue | ViewsMax";
    const description = "Calculate your potential YouTube revenue with our free monetization calculator. Get 12-month growth projections for conservative, moderate, and aggressive scenarios based on your channel's views, subscribers, and content category.";
    const keywords = "youtube monetization calculator, youtube revenue calculator, youtube earnings calculator, youtube cpm calculator, youtube rpm calculator, youtube ad revenue, youtube channel revenue";

    // Update document title
    document.title = title;

    // Update or create meta tags
    const updateMetaTag = (name: string, content: string, isProperty = false) => {
      const attribute = isProperty ? "property" : "name";
      let meta = document.querySelector(`meta[${attribute}="${name}"]`) as HTMLMetaElement;
      if (!meta) {
        meta = document.createElement("meta");
        meta.setAttribute(attribute, name);
        document.head.appendChild(meta);
      }
      meta.content = content;
    };

    // Basic meta tags
    updateMetaTag("description", description);
    updateMetaTag("keywords", keywords);

    // Open Graph tags
    updateMetaTag("og:title", title, true);
    updateMetaTag("og:description", description, true);
    updateMetaTag("og:type", "website", true);
    updateMetaTag("og:url", `${window.location.origin}/youtube-monetization-calculator`, true);

    // Twitter Card tags
    updateMetaTag("twitter:card", "summary_large_image");
    updateMetaTag("twitter:title", title);
    updateMetaTag("twitter:description", description);

    // Canonical URL
    let canonical = document.querySelector("link[rel='canonical']") as HTMLLinkElement;
    if (!canonical) {
      canonical = document.createElement("link");
      canonical.rel = "canonical";
      document.head.appendChild(canonical);
    }
    canonical.href = `${window.location.origin}/youtube-monetization-calculator`;

    // Cleanup function to restore original title when component unmounts
    return () => {
      document.title = "ViewsMax - Content Analytics & Trend Insights";
    };
  }, []);

  const calculateProjections = () => {
    if (!monthlyViews || !currentSubscribers || !contentCategory) {
      return;
    }

    const views = parseInt(monthlyViews);
    const subscribers = parseInt(currentSubscribers);
    const selectedCategory = CATEGORIES.find((cat) => cat.category === contentCategory);

    if (!selectedCategory) return;

    const rpm = selectedCategory.rpm;

    // Calculate growth projections over 12 months for three scenarios
    // Conservative: 2% monthly growth
    // Moderate: 5% monthly growth
    // Aggressive: 8% monthly growth
    const projectionData: ProjectionData[] = [];

    let conservativeViews = views;
    let moderateViews = views;
    let aggressiveViews = views;

    const monthNames = [
      "Month 1",
      "Month 2",
      "Month 3",
      "Month 4",
      "Month 5",
      "Month 6",
      "Month 7",
      "Month 8",
      "Month 9",
      "Month 10",
      "Month 11",
      "Month 12",
    ];

    for (let i = 0; i < 12; i++) {
      const conservativeRevenue = (conservativeViews / 1000) * rpm;
      const moderateRevenue = (moderateViews / 1000) * rpm;
      const aggressiveRevenue = (aggressiveViews / 1000) * rpm;

      projectionData.push({
        month: monthNames[i],
        conservative: Math.round(conservativeRevenue * 100) / 100,
        moderate: Math.round(moderateRevenue * 100) / 100,
        aggressive: Math.round(aggressiveRevenue * 100) / 100,
      });

      // Growth rates
      conservativeViews = conservativeViews * 1.02; // 2% per month
      moderateViews = moderateViews * 1.05; // 5% per month
      aggressiveViews = aggressiveViews * 1.08; // 8% per month
    }

    setProjections(projectionData);
    setHasCalculated(true);
  };

  const chartConfig = {
    conservative: {
      label: "Conservative (2% growth)",
      color: "hsl(142 76% 36%)", // green
    },
    moderate: {
      label: "Moderate (5% growth)",
      color: "hsl(217 91% 60%)", // blue
    },
    aggressive: {
      label: "Aggressive (8% growth)",
      color: "hsl(0 84% 60%)", // red/orange
    },
  };

  const totalConservative = projections.reduce((sum, p) => sum + p.conservative, 0);
  const totalModerate = projections.reduce((sum, p) => sum + p.moderate, 0);
  const totalAggressive = projections.reduce((sum, p) => sum + p.aggressive, 0);
  const finalMonthConservative = projections[projections.length - 1]?.conservative || 0;
  const finalMonthModerate = projections[projections.length - 1]?.moderate || 0;
  const finalMonthAggressive = projections[projections.length - 1]?.aggressive || 0;

  return (
    <div className="min-h-screen bg-background flex flex-col">
      <LandingNav />
      <div className="flex-1 py-12">
      <div className="container mx-auto px-4 max-w-6xl">
        <div className="text-center mb-8">
          <h1 className="text-4xl font-bold text-foreground mb-2">YouTube Revenue Calculator</h1>
          <p className="text-muted-foreground text-lg">
            Calculate your potential YouTube revenue and growth projections
          </p>
        </div>

        <div className="grid lg:grid-cols-2 gap-6 mb-6">
          {/* Form Card */}
          <Card>
            <CardHeader>
              <CardTitle>Enter Your Channel Data</CardTitle>
              <CardDescription>Fill in your current channel metrics to calculate projections</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="monthly-views">
                  Monthly Views <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="monthly-views"
                  type="number"
                  min="0"
                  placeholder="e.g., 100000"
                  value={monthlyViews}
                  onChange={(e) => setMonthlyViews(e.target.value)}
                  className={cn(!monthlyViews ? "border-red-500" : "")}
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="subscribers">
                  Current Subscribers <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="subscribers"
                  type="number"
                  min="0"
                  placeholder="e.g., 5000"
                  value={currentSubscribers}
                  onChange={(e) => setCurrentSubscribers(e.target.value)}
                  className={cn(!currentSubscribers ? "border-red-500" : "")}
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="category">
                  Content Category <span className="text-destructive">*</span>
                </Label>
                <Select value={contentCategory} onValueChange={setContentCategory}>
                  <SelectTrigger
                    id="category"
                    className={cn(!contentCategory ? "border-red-500" : "")}
                  >
                    <SelectValue placeholder="Select category" />
                  </SelectTrigger>
                  <SelectContent>
                    {CATEGORIES.map((cat) => (
                      <SelectItem key={cat.category} value={cat.category}>
                        {cat.category} (RPM: ${cat.rpm})
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <Button
                onClick={calculateProjections}
                disabled={!monthlyViews || !currentSubscribers || !contentCategory}
                className="w-full"
                size="lg"
              >
                <TrendingUp className="mr-2 h-4 w-4" />
                Calculate Revenue
              </Button>
            </CardContent>
          </Card>

          {/* Results Summary Card */}
          {hasCalculated && (
            <Card>
              <CardHeader>
                <CardTitle>12-Month Projection Summary</CardTitle>
                <CardDescription>Your estimated growth and earnings</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid grid-cols-3 gap-4">
                  <div className="p-4 rounded-lg bg-secondary/50">
                    <div className="text-sm text-muted-foreground mb-1">Conservative Total</div>
                    <div className="text-2xl font-bold text-foreground">
                      ${totalConservative.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </div>
                    <div className="text-xs text-muted-foreground mt-1">Month 12: ${finalMonthConservative.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
                  </div>
                  <div className="p-4 rounded-lg bg-secondary/50">
                    <div className="text-sm text-muted-foreground mb-1">Moderate Total</div>
                    <div className="text-2xl font-bold text-foreground">
                      ${totalModerate.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </div>
                    <div className="text-xs text-muted-foreground mt-1">Month 12: ${finalMonthModerate.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
                  </div>
                  <div className="p-4 rounded-lg bg-secondary/50">
                    <div className="text-sm text-muted-foreground mb-1">Aggressive Total</div>
                    <div className="text-2xl font-bold text-foreground">
                      ${totalAggressive.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </div>
                    <div className="text-xs text-muted-foreground mt-1">Month 12: ${finalMonthAggressive.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
                  </div>
                </div>
              </CardContent>
            </Card>
          )}
        </div>

        {/* Chart Card */}
        {hasCalculated && projections.length > 0 && (
          <Card className="w-full">
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <DollarSign className="w-5 h-5 text-primary" />
                Revenue Growth Projection (12 Months)
              </CardTitle>
              <CardDescription>
                Projected monthly revenue for conservative (2%), moderate (5%), and aggressive (8%) growth scenarios
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="h-[500px] w-full">
                <ChartContainer config={chartConfig} className="h-full">
                  <ResponsiveContainer width="100%" height="100%">
                    <LineChart data={projections} margin={{ top: 10, right: 30, left: 0, bottom: 0 }}>
                      <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                      <XAxis
                        dataKey="month"
                        className="text-xs fill-muted-foreground"
                        tickLine={false}
                        axisLine={false}
                      />
                      <YAxis
                        className="text-xs fill-muted-foreground"
                        tickLine={false}
                        axisLine={false}
                        tickFormatter={(value) => `$${value.toLocaleString()}`}
                      />
                      <ChartTooltip
                        content={
                          <ChartTooltipContent
                            formatter={(value: any, name: string) => {
                              const label = chartConfig[name as keyof typeof chartConfig]?.label || name;
                              return [`$${Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`, label];
                            }}
                          />
                        }
                      />
                      <Legend
                        wrapperStyle={{ paddingTop: '20px' }}
                        formatter={(value) => chartConfig[value as keyof typeof chartConfig]?.label || value}
                      />
                      <Line
                        type="monotone"
                        dataKey="conservative"
                        stroke="hsl(142 76% 36%)"
                        strokeWidth={2}
                        dot={{ r: 4 }}
                        name="conservative"
                      />
                      <Line
                        type="monotone"
                        dataKey="moderate"
                        stroke="hsl(217 91% 60%)"
                        strokeWidth={2}
                        dot={{ r: 4 }}
                        name="moderate"
                      />
                      <Line
                        type="monotone"
                        dataKey="aggressive"
                        stroke="hsl(0 84% 60%)"
                        strokeWidth={2}
                        dot={{ r: 4 }}
                        name="aggressive"
                      />
                    </LineChart>
                  </ResponsiveContainer>
                </ChartContainer>
              </div>
            </CardContent>
          </Card>
        )}
      </div>
      </div>
      <LandingFooter />
    </div>
  );
};

export default RevenueCalculator;

