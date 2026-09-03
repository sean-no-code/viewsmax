import { SidebarProvider, SidebarTrigger } from "@/components/ui/sidebar";
import { AppSidebar } from "@/components/AppSidebar";
import { Outlet, useLocation } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { LogOut, User, Crown, Coins, HelpCircle, MessageCircle, Settings, Globe, Lightbulb } from "lucide-react";
import { useTranslation } from "react-i18next";
import { SUPPORTED_LOCALES } from "@/i18n";
import { viewsMaxApi } from "@/lib/api-service";
import { useAuth } from "@/hooks/useAuth";
import { useUserCredits } from "@/contexts/UserCreditsContext";
import { Link } from "react-router-dom";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { AIModelProcessingLoader } from "@/components/AIModelProcessingLoader";

export default function DashboardLayout() {
  const { user, signOut } = useAuth();
  const { i18n } = useTranslation();
  const location = useLocation();

  const changeLocale = async (code: string) => {
    await i18n.changeLanguage(code); // persists to localStorage via the detector
    void viewsMaxApi.updateUserSettings({ locale: code }); // best-effort server sync
  };
  const { credits, isAnimating } = useUserCredits();

  const getPageTitle = () => {
    const path = location.pathname;
    if (path.startsWith('/dashboard/outliers/breakdown/')) return 'Breakdown';
    if (path === '/dashboard/outliers/library') return 'Outliers Library';
    if (path === '/dashboard/outliers') return 'Outliers';
    if (path === '/dashboard/titles/new') return 'Create Titles';
    if (path === '/dashboard/scripts') return 'Scripts';
    if (path === '/dashboard/scripts/library') return 'Script Library';
    if (path === '/dashboard/scripts/library/new') return 'New Component';
    if (path.startsWith('/dashboard/scripts/library/edit/')) return 'Edit Component';
    if (path.startsWith('/dashboard/scripts/edit/')) return 'Edit Script';
    if (path.startsWith('/dashboard/scripts/create/')) return 'Create Script';
    if (path === '/dashboard/trending') return 'Trending';
    if (path === '/dashboard/post') return 'New Post';
    if (path === '/dashboard/post/drafts') return 'Drafts';
    if (path === '/dashboard/post/scheduled') return 'Scheduled';
    if (path === '/dashboard/post/calendar' || path === '/dashboard/calendar') return 'Calendar';
    if (path === '/dashboard/connections') return 'Connections';
    if (path === '/dashboard/channel-analytics') return 'Channel Analytics';
    if (path.startsWith('/dashboard/monetization/offers')) return 'Offers';
    if (path.startsWith('/dashboard/monetization/links')) return 'Link';
    if (path.startsWith('/dashboard/analytics/platform')) return 'Analytics · Source';
    if (path.startsWith('/dashboard/analytics/overview')) return 'Revenue Growth';
    if (path.startsWith('/dashboard/analytics/audience-growth')) return 'Audience Growth';
    if (path.startsWith('/dashboard/analytics')) return 'Analytics';
    if (path === '/dashboard/admin/users') return 'Users';
    if (path === '/dashboard/seo') return 'SEO';
    if (path === '/dashboard/feature-requests') return 'Request a Feature';
    if (path === '/dashboard/settings') return 'Settings';
    if (path === '/dashboard/billing') return 'Billing';
    if (path === '/dashboard/review') return 'Review';
    if (path.startsWith('/dashboard/review/video/')) return 'Video Review';
    return 'Dashboard';
  };

  return (
    <SidebarProvider>
      <div className="min-h-screen flex w-full">
        <AppSidebar />

        <div className="flex-1 flex flex-col">
          <header className="h-16 flex items-center justify-between border-b border-border px-6">
            <div className="flex items-center gap-4">
              <SidebarTrigger />
              <div className="flex items-center gap-3">
                <h1 className="text-lg font-semibold text-foreground">{getPageTitle()}</h1>
              </div>
              <AIModelProcessingLoader />
            </div>

            <div className="flex items-center gap-4">
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
              <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" size="sm" className="gap-1.5" title="Language">
                    <Globe className="w-4 h-4" />
                    <span className="uppercase text-xs font-semibold">{i18n.resolvedLanguage ?? "en"}</span>
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  {SUPPORTED_LOCALES.map((locale) => (
                    <DropdownMenuItem
                      key={locale.code}
                      onClick={() => void changeLocale(locale.code)}
                      className={locale.code === i18n.resolvedLanguage ? "font-semibold" : undefined}
                    >
                      {locale.label}
                    </DropdownMenuItem>
                  ))}
                </DropdownMenuContent>
              </DropdownMenu>
              <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" size="sm" className="gap-2">
                    <HelpCircle className="w-4 h-4" />
                    <span>Support</span>
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem
                    onClick={() => window.open('https://discord.gg/Wwe57w3Dv5', '_blank')}
                  >
                    <MessageCircle className="w-4 h-4 mr-2" />
                    Discord Community
                  </DropdownMenuItem>
                  <DropdownMenuItem asChild>
                    <Link to="/dashboard/feature-requests" className="flex items-center">
                      <Lightbulb className="w-4 h-4 mr-2" />
                      Request a feature
                    </Link>
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
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
                  <DropdownMenuItem asChild>
                    <Link to="/dashboard/settings" className="flex items-center">
                      <Settings className="w-4 h-4 mr-2" />
                      Settings
                    </Link>
                  </DropdownMenuItem>
                  <DropdownMenuItem asChild>
                    <Link to="/dashboard/billing" className="flex items-center">
                      <Crown className="w-4 h-4 mr-2" />
                      Billing
                    </Link>
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={signOut}>
                    <LogOut className="w-4 h-4 mr-2" />
                    Sign Out
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
          </header>

          <main className="flex-1 p-6">
            <Outlet />
          </main>
        </div>
      </div>
    </SidebarProvider>
  );
}