import { BarChart3, DollarSign, Send, Plug, Zap, CalendarDays, ScrollText, TrendingUp, Crown, type LucideIcon } from "lucide-react";

type NavSubItem = { title: string; i18nKey?: string; url: string; analyticsOnly?: boolean };
type NavItem = {
  title: string;
  i18nKey?: string;
  url: string;
  icon: LucideIcon;
  isProFeature?: boolean;
  subItems?: NavSubItem[];
};
import appIcon from "@/assets/icon.svg";
import { NavLink, useLocation, Link } from "react-router-dom";
import { Collapsible, CollapsibleContent } from "@/components/ui/collapsible";
import { useTranslation } from "react-i18next";
import { useAuth } from "@/hooks/useAuth";
import { canUseAnalytics, isAccessExpired } from "@/lib/access";

import {
  Sidebar,
  SidebarContent,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarMenuSub,
  SidebarMenuSubButton,
  SidebarMenuSubItem,
  SidebarTrigger,
  useSidebar,
} from "@/components/ui/sidebar";


// `i18nKey` looks up the translated label (nav.* in locales/*/translation.json);
// `title` stays as the English fallback and the stable React key.
const allNavigationItems: NavItem[] = [
  {
    title: "Post",
    i18nKey: "nav.post",
    url: "/dashboard/post",
    icon: Send,
    isProFeature: false,
    subItems: [
      { title: "New Post", i18nKey: "nav.newPost", url: "/dashboard/post" },
      { title: "Drafts", i18nKey: "nav.drafts", url: "/dashboard/post/drafts" },
      { title: "Scheduled", i18nKey: "nav.scheduled", url: "/dashboard/post/scheduled" },
      { title: "History", i18nKey: "nav.history", url: "/dashboard/post/history" },
    ]
  },
  // {
  //   title: "Scripts",
  //   i18nKey: "nav.scripts",
  //   url: "/dashboard/scripts",
  //   icon: ScrollText,
  //   isProFeature: true,
  //   subItems: [
  //     { title: "Create", i18nKey: "nav.scriptsCreate", url: "/dashboard/scripts/create" },
  //     { title: "Library", i18nKey: "nav.scriptsLibrary", url: "/dashboard/scripts/library" },
  //   ]
  // },
  {
    title: "Outliers",
    i18nKey: "nav.outliers",
    url: "/dashboard/outliers",
    icon: TrendingUp,
    isProFeature: true,
    subItems: [
      { title: "Browse", i18nKey: "nav.outliersBrowse", url: "/dashboard/outliers" },
      { title: "Library", i18nKey: "nav.outliersLibrary", url: "/dashboard/outliers/library" },
    ],
  },
  {
    title: "Monetization",
    i18nKey: "nav.monetization",
    url: "/dashboard/monetization",
    icon: DollarSign,
    isProFeature: false,
    subItems: [
      { title: "Offers", i18nKey: "nav.offers", url: "/dashboard/monetization/offers" },
    ]
  },
  {
    title: "Analytics",
    i18nKey: "nav.analytics",
    url: "/dashboard/analytics/overview",
    icon: BarChart3,
    isProFeature: false,
    subItems: [
      { title: "Revenue Growth", i18nKey: "nav.revenueGrowth", url: "/dashboard/analytics/overview" },
      { title: "Profile Performance", i18nKey: "nav.profilePerformance", url: "/dashboard/analytics/profile", analyticsOnly: true },
      { title: "Post Performance", i18nKey: "nav.postPerformance", url: "/dashboard/analytics/posts", analyticsOnly: true },
    ]
  },
  { title: "Boosts", i18nKey: "nav.boosts", url: "/dashboard/boosts", icon: Zap, isProFeature: false },
  { title: "Calendar", i18nKey: "nav.calendar", url: "/dashboard/calendar", icon: CalendarDays, isProFeature: false },
  { title: "Connections", i18nKey: "nav.connections", url: "/dashboard/connections", icon: Plug, isProFeature: false },
  // Settings lives in the top-nav user menu; feature requests under top-nav Support.
];

export function AppSidebar() {
  const { t } = useTranslation();
  const { state } = useSidebar();
  const { user } = useAuth();
  const location = useLocation();
  const currentPath = location.pathname;

  // Profile/Post Performance are part of the Pro tier (plus admins and
  // promotional customers); a lower tier never sees them offered.
  const analytics = canUseAnalytics(user);
  const baseItems = allNavigationItems.map((item) =>
    item.subItems ? { ...item, subItems: item.subItems.filter((sub) => !sub.analyticsOnly || analytics) } : item,
  );

  // An expired promotional customer can only reach Billing until they subscribe.
  const navigationItems: NavItem[] = isAccessExpired(user)
    ? [{ title: t("layout.sidebar.billing", "Billing"), url: "/dashboard/billing", icon: Crown, isProFeature: false }]
    : baseItems;

  const isActive = (path: string) => currentPath === path;
  const getNavCls = ({ isActive }: { isActive: boolean }) =>
    isActive ? "bg-primary text-primary-foreground" : "hover:bg-accent hover:text-accent-foreground";

  return (
    <Sidebar
      collapsible="icon"
    >
      <SidebarContent>
        <div className="p-4 border-b border-border">
          <Link to="/dashboard" className="flex items-center gap-2 hover:opacity-80 transition-opacity">
            <img src={appIcon} alt="" aria-hidden="true" width={32} height={32} className="flex-shrink-0 rounded-lg" />
            {state !== "collapsed" && <span className="font-display text-xl font-extrabold tracking-tight text-foreground">ViewsMax</span>}
          </Link>
        </div>

        <SidebarGroup>
          <SidebarGroupLabel>
            {currentPath.startsWith('/dashboard/outliers')
              ? t("layout.sidebar.groupOutliers", "Outliers")
              : t("layout.sidebar.groupDashboard", "Dashboard")}
          </SidebarGroupLabel>

          <SidebarGroupContent>
            <SidebarMenu>
              {navigationItems.map((item) => (
                <SidebarMenuItem key={item.title}>
                  {item.subItems ? (
                    <Collapsible
                      open={state !== "collapsed"}
                      className="group/collapsible"
                    >
                      <div className="flex items-center">
                        <SidebarMenuButton asChild className="flex-1">
                          <NavLink
                            to={item.url}
                            end
                            className={getNavCls}
                            onClick={() => {
                              // Allow navigation, don't toggle collapsible
                            }}
                          >
                            <item.icon className="mr-2 h-4 w-4" />
                            {state !== "collapsed" && (
                              <div className="flex items-center gap-2 flex-1">
                                <span>{item.i18nKey ? t(item.i18nKey, item.title) : item.title}</span>
                              </div>
                            )}
                          </NavLink>
                        </SidebarMenuButton>
                      </div>
                      <CollapsibleContent className="overflow-hidden data-[state=open]:animate-accordion-down data-[state=closed]:animate-accordion-up">
                        <SidebarMenuSub>
                          {item.subItems.map((sub) => (
                            <SidebarMenuSubItem key={sub.title}>
                              <SidebarMenuSubButton asChild isActive={isActive(sub.url)}>
                                {/* The span gets the button's truncate rule: long translated labels end in "…" instead of wrapping over the next row. */}
                                <NavLink
                                  to={sub.url}
                                  end
                                  title={sub.i18nKey ? t(sub.i18nKey, sub.title) : sub.title}
                                >
                                  <span>{sub.i18nKey ? t(sub.i18nKey, sub.title) : sub.title}</span>
                                </NavLink>
                              </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                          ))}
                        </SidebarMenuSub>
                      </CollapsibleContent>
                    </Collapsible>
                  ) : (
                    <SidebarMenuButton asChild>
                      <NavLink
                        to={item.url}
                        end
                        className={getNavCls}
                      // onClick={(e) => handleNavClick(e, item)}
                      >
                        <item.icon className="mr-2 h-4 w-4" />
                        {state !== "collapsed" && (
                          <div className="flex items-center gap-2 flex-1">
                            <span>{item.i18nKey ? t(item.i18nKey, item.title) : item.title}</span>

                          </div>
                        )}
                      </NavLink>
                    </SidebarMenuButton>
                  )}
                </SidebarMenuItem>
              ))}
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      </SidebarContent>
    </Sidebar>
  );
}