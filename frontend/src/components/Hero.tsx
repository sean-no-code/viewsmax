import { useState } from "react";
import { Button } from "@/components/ui/button";
import { ArrowRight, Play, TrendingUp, BarChart3 } from "lucide-react";
import { Link } from "react-router-dom";
import { useAuth } from "@/hooks/useAuth";
import { ChartContainer, ChartTooltip, ChartTooltipContent } from "@/components/ui/chart";
import { LineChart, Line, XAxis, YAxis, CartesianGrid, ResponsiveContainer, Area, AreaChart, ReferenceLine } from "recharts";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";

const Hero = () => {
  const { user } = useAuth();
  const [activeTab, setActiveTab] = useState("subscribers");
  const [isDemoModalOpen, setIsDemoModalOpen] = useState(false);

  // YouTube video ID extracted from the URL
  const youtubeVideoId = "PVdW3kjxC8U";

  // Subscriber growth data - 2 months before, 2 months after (4 months total)
  const subscriberData = [
    { month: "Apr", subscribers: 530 },
    { month: "May", subscribers: 525 },
    { month: "Jun", subscribers: 3200, joinedViewsMax: true }, // Joined ViewsMax
    { month: "Jul", subscribers: 4500 },
  ];

  // Views growth data - 2 months before, 2 months after (4 months total)
  const viewsData = [
    { month: "Apr", views: 17000 },
    { month: "May", views: 16000 },
    { month: "Jun", views: 45000, joinedViewsMax: true }, // Joined ViewsMax
    { month: "Jul", views: 68000 },
  ];

  const chartConfig = {
    subscribers: {
      label: "Subscribers",
      color: "hsl(var(--primary))",
    },
    views: {
      label: "Views",
      color: "hsl(217 91% 60%)", // Blue color for views
    },
  };

  return (
    <section className="pt-20 sm:pt-24 pb-12 sm:pb-16 bg-gradient-secondary">
      <div className="container mx-auto px-4">
        <div className="grid lg:grid-cols-2 gap-8 lg:gap-12 items-center">
          <div className="space-y-6 sm:space-y-8">
            <div className="space-y-3 sm:space-y-4">
              <div className="flex items-center gap-2 text-primary font-semibold text-sm sm:text-base">
                <TrendingUp className="w-4 h-4 sm:w-5 sm:h-5" />
                <span className="whitespace-nowrap">Turn Your Channel Into a Hit Machine</span>
              </div>
              <h1 className="text-3xl sm:text-4xl md:text-5xl lg:text-6xl font-bold text-foreground leading-tight">
                Build and monetize your Youtube audience
                <span className="text-primary"> fast</span>
              </h1>
              <p className="text-base sm:text-lg md:text-xl text-muted-foreground max-w-lg leading-relaxed">
                Stop guessing what works. Our AI shows you exactly
                what your audience wants – so you can create hits, not misses.
              </p>
            </div>

            <div className="flex flex-col sm:flex-row gap-3 sm:gap-4">
              {user ? (
                <Button variant="cta" size="lg" className="group font-semibold text-sm sm:text-base" asChild>
                  <Link to="/dashboard">
                    <BarChart3 className="w-4 h-4 group-hover:scale-110 transition-transform" />
                    Go to Dashboard
                    <ArrowRight className="w-4 h-4 group-hover:translate-x-1 transition-transform" />
                  </Link>
                </Button>
              ) : (
                <Button variant="cta" size="lg" className="group font-semibold text-sm sm:text-base" asChild>
                  <Link to="/auth">
                    Sign Up Free
                    <ArrowRight className="w-4 h-4 group-hover:translate-x-1 transition-transform" />
                  </Link>
                </Button>
              )}
              <Button
                variant="outline"
                size="lg"
                className="group border-primary/20 hover:border-primary text-sm sm:text-base"
                onClick={() => setIsDemoModalOpen(true)}
              >
                <Play className="w-4 h-4" />
                Watch Demo
              </Button>
            </div>

            <div className="flex flex-col sm:flex-row items-start sm:items-center gap-4 sm:gap-8 pt-2 sm:pt-4">
              <div className="flex items-center gap-2 sm:gap-3 flex-shrink-0">
                {/* Overlapping face icons */}
                <div className="flex items-center -space-x-2 flex-shrink-0">
                  <img
                    src="https://i.pravatar.cc/40?img=47"
                    alt="Creator"
                    className="w-7 h-7 sm:w-8 sm:h-8 rounded-full border-2 border-background object-cover"
                  />
                  <img
                    src="https://i.pravatar.cc/40?img=12"
                    alt="Creator"
                    className="w-7 h-7 sm:w-8 sm:h-8 rounded-full border-2 border-background object-cover"
                  />
                  <img
                    src="https://i.pravatar.cc/40?img=33"
                    alt="Creator"
                    className="w-7 h-7 sm:w-8 sm:h-8 rounded-full border-2 border-background object-cover"
                  />
                  <img
                    src="https://i.pravatar.cc/40?img=15"
                    alt="Creator"
                    className="w-7 h-7 sm:w-8 sm:h-8 rounded-full border-2 border-background object-cover"
                  />
                  <img
                    src="https://i.pravatar.cc/40?img=20"
                    alt="Creator"
                    className="w-7 h-7 sm:w-8 sm:h-8 rounded-full border-2 border-background object-cover"
                  />
                </div>
                <div className="text-left flex-shrink-0">
                  <div className="text-xl sm:text-2xl font-bold text-foreground">5000+</div>
                  <div className="text-xs sm:text-sm text-muted-foreground whitespace-nowrap">Creators Helped</div>
                </div>
              </div>
              <div className="text-left sm:text-center flex-shrink-0 hidden sm:block">
                <div className="text-xl sm:text-2xl font-bold text-foreground">2M+</div>
                <div className="text-xs sm:text-sm text-muted-foreground whitespace-nowrap">Views Generated</div>
              </div>
            </div>
          </div>

          <div className="relative mt-8 lg:mt-0 hidden lg:block">
            <div className="relative rounded-2xl bg-background border border-border p-4 sm:p-6 shadow-hero">
              <div className="mb-3 sm:mb-4">
                <h3 className="text-base sm:text-lg font-semibold text-foreground mb-1">Growth Analytics</h3>
              </div>

              <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full">
                <TabsList className="grid w-full grid-cols-2 mb-3 sm:mb-4 h-auto">
                  <TabsTrigger value="subscribers" className="text-xs sm:text-sm">Subscribers</TabsTrigger>
                  <TabsTrigger value="views" className="text-xs sm:text-sm">Views</TabsTrigger>
                </TabsList>

                {/* Subscribers Tab */}
                <TabsContent value="subscribers" className="mt-0">
                  <ChartContainer config={chartConfig} className="h-[250px] sm:h-[300px] w-full overflow-hidden">
                    <ResponsiveContainer width="100%" height="100%">
                      <AreaChart data={subscriberData} margin={{ top: 10, right: 5, left: -10, bottom: 5 }}>
                        <defs>
                          <linearGradient id="colorSubscribers" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="5%" stopColor="hsl(var(--primary))" stopOpacity={0.3} />
                            <stop offset="95%" stopColor="hsl(var(--primary))" stopOpacity={0} />
                          </linearGradient>
                        </defs>
                        <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                        <XAxis
                          dataKey="month"
                          className="text-xs fill-muted-foreground"
                          tickLine={false}
                          axisLine={false}
                          tickMargin={4}
                          interval={0}
                        />
                        <YAxis
                          className="text-xs fill-muted-foreground"
                          tickLine={false}
                          axisLine={false}
                          tickMargin={4}
                          width={40}
                          tickFormatter={(value) => {
                            if (value >= 1000) return `${(value / 1000).toFixed(1)}k`;
                            return value.toString();
                          }}
                        />
                        <ChartTooltip
                          content={({ active, payload }) => {
                            if (active && payload && payload.length) {
                              return (
                                <div className="rounded-lg border bg-background p-2 shadow-sm">
                                  <div className="flex items-center gap-2">
                                    <div className="h-2 w-2 rounded-full bg-primary" />
                                    <span className="text-xs text-muted-foreground">
                                      Subscribers: <span className="font-medium text-foreground">{payload[0].value.toLocaleString()}</span>
                                    </span>
                                  </div>
                                </div>
                              );
                            }
                            return null;
                          }}
                        />
                        <ReferenceLine
                          x="May"
                          stroke="hsl(var(--primary))"
                          strokeWidth={2}
                          strokeDasharray="5 5"
                          label={{
                            value: "Joined ViewsMax",
                            position: "top",
                            fill: "hsl(var(--primary))",
                            fontSize: 10,
                            fontWeight: 600,
                            offset: 5
                          }}
                        />
                        <Area
                          type="monotone"
                          dataKey="subscribers"
                          stroke="hsl(var(--primary))"
                          fill="url(#colorSubscribers)"
                          strokeWidth={2}
                          dot={(props: any) => {
                            const { cx, cy } = props;
                            if (!cx || !cy) return null;
                            return <circle cx={cx} cy={cy} r={4} fill="hsl(var(--primary))" />;
                          }}
                        />
                      </AreaChart>
                    </ResponsiveContainer>
                  </ChartContainer>
                </TabsContent>

                {/* Views Tab */}
                <TabsContent value="views" className="mt-0">
                  <ChartContainer config={chartConfig} className="h-[250px] sm:h-[300px] w-full overflow-hidden">
                    <ResponsiveContainer width="100%" height="100%">
                      <AreaChart data={viewsData} margin={{ top: 10, right: 5, left: -10, bottom: 5 }}>
                        <defs>
                          <linearGradient id="colorViews" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="5%" stopColor="hsl(217 91% 60%)" stopOpacity={0.3} />
                            <stop offset="95%" stopColor="hsl(217 91% 60%)" stopOpacity={0} />
                          </linearGradient>
                        </defs>
                        <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                        <XAxis
                          dataKey="month"
                          className="text-xs fill-muted-foreground"
                          tickLine={false}
                          axisLine={false}
                          tickMargin={4}
                          interval={0}
                        />
                        <YAxis
                          className="text-xs fill-muted-foreground"
                          tickLine={false}
                          axisLine={false}
                          tickMargin={4}
                          width={40}
                          tickFormatter={(value) => {
                            if (value >= 1000) return `${(value / 1000).toFixed(0)}k`;
                            return value.toString();
                          }}
                        />
                        <ChartTooltip
                          content={({ active, payload }) => {
                            if (active && payload && payload.length) {
                              return (
                                <div className="rounded-lg border bg-background p-2 shadow-sm">
                                  <div className="flex items-center gap-2">
                                    <div className="h-2 w-2 rounded-full" style={{ backgroundColor: 'hsl(217 91% 60%)' }} />
                                    <span className="text-xs text-muted-foreground">
                                      Views: <span className="font-medium text-foreground">{payload[0].value.toLocaleString()}</span>
                                    </span>
                                  </div>
                                </div>
                              );
                            }
                            return null;
                          }}
                        />
                        <ReferenceLine
                          x="May"
                          stroke="hsl(217 91% 60%)"
                          strokeWidth={2}
                          strokeDasharray="5 5"
                          label={{
                            value: "Joined ViewsMax",
                            position: "top",
                            fill: "hsl(217 91% 60%)",
                            fontSize: 10,
                            fontWeight: 600,
                            offset: 5
                          }}
                        />
                        <Area
                          type="monotone"
                          dataKey="views"
                          stroke="hsl(217 91% 60%)"
                          fill="url(#colorViews)"
                          strokeWidth={2}
                          dot={(props: any) => {
                            const { cx, cy } = props;
                            if (!cx || !cy) return null;
                            return <circle cx={cx} cy={cy} r={4} fill="hsl(217 91% 60%)" />;
                          }}
                        />
                      </AreaChart>
                    </ResponsiveContainer>
                  </ChartContainer>
                </TabsContent>
              </Tabs>
            </div>
            <div className="absolute -top-2 -right-2 sm:-top-4 sm:-right-4 w-16 h-16 sm:w-24 sm:h-24 bg-gradient-primary rounded-full flex items-center justify-center animate-float shadow-primary">
              <TrendingUp className="w-5 h-5 sm:w-8 sm:h-8 text-primary-foreground" />
            </div>
          </div>
        </div>
      </div>

      {/* Demo Video Modal */}
      <Dialog open={isDemoModalOpen} onOpenChange={setIsDemoModalOpen}>
        <DialogContent className="max-w-4xl w-full p-0">

          <div className="relative w-full" style={{ aspectRatio: "16/9" }}>
            <iframe
              src={`https://www.youtube.com/embed/${youtubeVideoId}?autoplay=1`}
              title="ViewsMax Demo Video"
              allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
              allowFullScreen
              className="absolute top-0 left-0 w-full h-full rounded-b-lg"
            />
          </div>
        </DialogContent>
      </Dialog>
    </section>
  );
};

export default Hero;