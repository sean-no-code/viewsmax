import { BarChart3, DollarSign, Send, Plug, Shield, Zap, Search, CalendarDays, ScrollText, TrendingUp, type LucideIcon } from "lucide-react";

type NavSubItem = { title: string; i18nKey?: string; url: string };
type NavItem = {
  title: string;
  i18nKey?: string;
  url: string;
  icon: LucideIcon;
  isProFeature?: boolean;
  subItems?: NavSubItem[];
};
import { NavLink, useLocation, Link } from "react-router-dom";
import { Collapsible, CollapsibleContent } from "@/components/ui/collapsible";
import { useTranslation } from "react-i18next";
import { useAuth } from "@/hooks/useAuth";

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
      { title: "Audience Growth", i18nKey: "nav.audienceGrowth", url: "/dashboard/analytics/audience-growth" },
    ]
  },
  { title: "Boosts", i18nKey: "nav.boosts", url: "/dashboard/boosts", icon: Zap, isProFeature: false },
  { title: "Calendar", i18nKey: "nav.calendar", url: "/dashboard/calendar", icon: CalendarDays, isProFeature: false },
  // { title: "SEO", i18nKey: "nav.seo", url: "/dashboard/seo", icon: Search, isProFeature: false },
  { title: "Connections", i18nKey: "nav.connections", url: "/dashboard/connections", icon: Plug, isProFeature: false },
  // Settings lives in the top-nav user menu; feature requests under top-nav Support.
];

// Admin-only — appended to the nav when the signed-in user is an admin.
const adminNavigationItem: NavItem = {
  title: "Admin",
  url: "/dashboard/admin/posts",
  icon: Shield,
  isProFeature: false,
  subItems: [
    { title: "Publishing monitor", url: "/dashboard/admin/posts" },
    { title: "Users", url: "/dashboard/admin/users" },
    { title: "Offers", url: "/dashboard/admin/offers" },
    { title: "Links", url: "/dashboard/admin/links" },
    { title: "Lead magnet", url: "/dashboard/admin/lead-magnet" },
  ],
};

export function AppSidebar() {
  const { t } = useTranslation();
  const { state } = useSidebar();
  const { user } = useAuth();
  const location = useLocation();
  const currentPath = location.pathname;

  const isAdmin = !!user?.is_admin;

  // Audience Growth is admin-only for now (multi-platform data pipeline still
  // being finished) — hide it from the Analytics submenu for non-admins.
  const baseItems = allNavigationItems.map((item) =>
    item.title === "Analytics" && item.subItems
      ? { ...item, subItems: item.subItems.filter((sub) => sub.title !== "Audience Growth" || isAdmin) }
      : item,
  );

  const navigationItems = isAdmin ? [...baseItems, adminNavigationItem] : baseItems;

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
            <svg width="32" height="32" viewBox="0 0 32 32" fill="none" className="flex-shrink-0">
              <rect x="1" y="1" width="30" height="30" rx="8" fill="var(--vm-red)" />
              <circle cx="9.5" cy="16" r="2.6" fill="#fff" />
              <circle cx="22" cy="9.5" r="2.6" fill="#fff" />
              <circle cx="22" cy="22.5" r="2.6" fill="var(--vm-volt)" />
              <path d="M11.6 14.7 L20 10.4 M11.6 17.3 L20 21.6" stroke="#fff" strokeWidth="1.8" strokeLinecap="round" />
            </svg>
            {state !== "collapsed" && <span className="font-display text-xl font-extrabold tracking-tight text-foreground">ViewsMax</span>}
          </Link>
        </div>

        <SidebarGroup>
          <SidebarGroupLabel>{currentPath.startsWith('/dashboard/outliers') ? 'Outliers' : 'Dashboard'}</SidebarGroupLabel>

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
                                <NavLink
                                  to={sub.url}
                                  end
                                >
                                  {sub.i18nKey ? t(sub.i18nKey, sub.title) : sub.title}
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