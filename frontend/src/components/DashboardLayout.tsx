import { SidebarProvider, SidebarTrigger } from "@/components/ui/sidebar";
import { AppSidebar } from "@/components/AppSidebar";
import { Outlet, useLocation } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { LogOut, User, Crown, Coins, HelpCircle, MessageCircle, Settings, Lightbulb } from "lucide-react";
import { useTranslation } from "react-i18next";
import LanguageMenu from "@/components/LanguageMenu";
import { viewsMaxApi } from "@/lib/api-service";
import { useAuth } from "@/hooks/useAuth";
import { isAccessExpired } from "@/lib/access";
import { useUserCredits } from "@/contexts/UserCreditsContext";
import { Link } from "react-router-dom";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { AIModelProcessingLoader } from "@/components/AIModelProcessingLoader";
import ConnectAiAppsButton from "@/components/ConnectAiAppsButton";

export default function DashboardLayout() {
  const { user, signOut } = useAuth();
  const { t } = useTranslation();
  const location = useLocation();

  const { credits, isAnimating } = useUserCredits();
  // Expired promotional customer: nav is reduced to Billing + sign out.
  const locked = isAccessExpired(user);
  const promoEndedOn = user?.promo_expires_at
    ? new Date(user.promo_expires_at).toLocaleDateString([], { day: "numeric", month: "short", year: "numeric" })
    : null;

  const getPageTitle = () => {
    const path = location.pathname;
    if (path.startsWith('/dashboard/outliers/breakdown/')) return t("layout.pageTitle.breakdown", "Breakdown");
    if (path === '/dashboard/outliers/library') return t("layout.pageTitle.outliersLibrary", "Outliers Library");
    if (path === '/dashboard/outliers') return t("layout.pageTitle.outliers", "Outliers");
    if (path === '/dashboard/titles/new') return t("layout.pageTitle.createTitles", "Create Titles");
    if (path === '/dashboard/scripts') return t("layout.pageTitle.scripts", "Scripts");
    if (path === '/dashboard/scripts/library') return t("layout.pageTitle.scriptLibrary", "Script Library");
    if (path === '/dashboard/scripts/library/new') return t("layout.pageTitle.newComponent", "New Component");
    if (path.startsWith('/dashboard/scripts/library/edit/')) return t("layout.pageTitle.editComponent", "Edit Component");
    if (path.startsWith('/dashboard/scripts/edit/')) return t("layout.pageTitle.editScript", "Edit Script");
    if (path.startsWith('/dashboard/scripts/create/')) return t("layout.pageTitle.createScript", "Create Script");
    if (path === '/dashboard/trending') return t("layout.pageTitle.trending", "Trending");
    if (path === '/dashboard/post') return t("layout.pageTitle.newPost", "New Post");
    if (path === '/dashboard/post/drafts') return t("layout.pageTitle.drafts", "Drafts");
    if (path === '/dashboard/post/scheduled') return t("layout.pageTitle.scheduled", "Scheduled");
    if (path === '/dashboard/post/calendar' || path === '/dashboard/calendar') return t("layout.pageTitle.calendar", "Calendar");
    if (path === '/dashboard/connections') return t("layout.pageTitle.connections", "Connections");
    if (path === '/dashboard/channel-analytics') return t("layout.pageTitle.channelAnalytics", "Channel Analytics");
    if (path.startsWith('/dashboard/monetization/offers')) return t("layout.pageTitle.offers", "Offers");
    if (path.startsWith('/dashboard/monetization/links')) return t("layout.pageTitle.link", "Link");
    if (path.startsWith('/dashboard/analytics/platform')) return t("layout.pageTitle.analyticsSource", "Analytics · Source");
    if (path.startsWith('/dashboard/analytics/overview')) return t("layout.pageTitle.revenueGrowth", "Revenue Growth");
    if (path.startsWith('/dashboard/analytics/profile')) return t("layout.pageTitle.profilePerformance", "Profile Performance");
    if (path.startsWith('/dashboard/analytics/posts')) return t("layout.pageTitle.postPerformance", "Post Performance");
    if (path.startsWith('/dashboard/analytics')) return t("layout.pageTitle.analytics", "Analytics");
    if (path === '/dashboard/feature-requests') return t("layout.pageTitle.requestFeature", "Request a Feature");
    if (path === '/dashboard/settings') return t("layout.pageTitle.settings", "Settings");
    if (path === '/dashboard/billing') return t("layout.pageTitle.billing", "Billing");
    if (path === '/dashboard/review') return t("layout.pageTitle.review", "Review");
    if (path.startsWith('/dashboard/review/video/')) return t("layout.pageTitle.videoReview", "Video Review");
    return t("layout.pageTitle.dashboard", "Dashboard");
  };

  return (
    <SidebarProvider>
      <div className="min-h-screen flex w-full">
        <AppSidebar />

        <div className="flex-1 flex flex-col">
          <header className="h-16 flex items-center justify-between gap-3 border-b border-border px-4 sm:px-6">
            <div className="flex items-center gap-4">
              <SidebarTrigger />
              <div className="flex items-center gap-3">
                <h1 className="text-lg font-semibold text-foreground">{getPageTitle()}</h1>
              </div>
              <AIModelProcessingLoader />
            </div>

            <div className="flex items-center gap-1 sm:gap-4">
              {!locked && <ConnectAiAppsButton />}
              {/* Balance/credits indicator temporarily hidden
              <div className={`flex items-center gap-2 px-2.5 py-1.5 rounded-lg bg-secondary/50 border border-border transition-all duration-300 ${isAnimating ? 'animate-pulse scale-105 border-primary/50' : ''}`}>
                <Coins className={`w-4 h-4 text-primary transition-transform ${isAnimating ? 'animate-bounce' : ''}`} />
                <span className="text-sm font-semibold text-foreground">
                  <span className="text-muted-foreground">Balance:</span>{' '}
                  <span className={`transition-all duration-500 ${isAnimating ? 'text-primary scale-110' : ''}`}>
                    {credits}
                  </span>
                </span>
              </div>
              */}
              <LanguageMenu onChange={(code) => void viewsMaxApi.updateUserSettings({ locale: code })} />
              {!locked && <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" size="sm" className="gap-2">
                    <HelpCircle className="w-4 h-4" />
                    <span className="hidden sm:inline">{t("layout.topBar.support", "Support")}</span>
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem
                    onClick={() => window.open('https://discord.gg/Wwe57w3Dv5', '_blank')}
                  >
                    <MessageCircle className="w-4 h-4 mr-2" />
                    {t("layout.topBar.discordCommunity", "Discord Community")}
                  </DropdownMenuItem>
                  <DropdownMenuItem asChild>
                    <Link to="/dashboard/feature-requests" className="flex items-center">
                      <Lightbulb className="w-4 h-4 mr-2" />
                      {t("layout.topBar.requestFeature", "Request a feature")}
                    </Link>
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>}
              <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" size="icon" className="!ring-0 !ring-offset-0 !outline-none focus:!ring-0 focus:!outline-none focus-visible:!ring-0 focus-visible:!outline-none active:!ring-0 active:!outline-none data-[state=open]:!ring-0 data-[state=closed]:!ring-0">
                    <User className="w-4 h-4" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem disabled>
                    {user?.email}
                  </DropdownMenuItem>
                  {!locked && <DropdownMenuItem asChild>
                    <Link to="/dashboard/settings" className="flex items-center">
                      <Settings className="w-4 h-4 mr-2" />
                      {t("layout.userMenu.settings", "Settings")}
                    </Link>
                  </DropdownMenuItem>}
                  <DropdownMenuItem asChild>
                    <Link to="/dashboard/billing" className="flex items-center">
                      <Crown className="w-4 h-4 mr-2" />
                      {t("layout.userMenu.billing", "Billing")}
                    </Link>
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={signOut}>
                    <LogOut className="w-4 h-4 mr-2" />
                    {t("layout.userMenu.signOut", "Sign Out")}
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
          </header>

          {locked && (
            <div className="border-b border-destructive/40 bg-destructive/10 px-6 py-3 text-sm text-foreground">
              <span className="font-semibold">
                {promoEndedOn
                  ? t("layout.accessEnded.titleOn", "Your free access ended on {{date}}.", { date: promoEndedOn })
                  : t("layout.accessEnded.title", "Your free access ended.")}
              </span>{" "}
              {t("layout.accessEnded.choosePlan", "Choose a plan below to keep using ViewsMax.")}
            </div>
          )}
          <main className="flex-1 p-6">
            <Outlet />
          </main>
        </div>
      </div>
    </SidebarProvider>
  );
}