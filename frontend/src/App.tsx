import { Toaster } from "@/components/ui/toaster";
import { Toaster as Sonner } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { BrowserRouter, Routes, Route, Navigate, useParams } from "react-router-dom";
import { useEffect } from "react";
import Index from "./pages/Index";
import NotFound from "./pages/NotFound";
import Auth from "./pages/Auth";
import ResetPassword from "./pages/ResetPassword";
import Privacy from "./pages/Privacy";
import Terms from "./pages/Terms";
import ConnectAI from "./pages/ConnectAI";
import DashboardLayout from "./components/DashboardLayout";
import Dashboard from "./pages/Dashboard";
// Thumbnails + AI models pages retired from the dashboard (routes commented out below).
// import Thumbnails from "./pages/Thumbnails";
// import ThumbnailReview from "./pages/ThumbnailReview";
// import AIModels from "./pages/AIModels";
import Titles from "./pages/Titles";
import TitlesNew from "./pages/TitlesNew";
import TitlesEdit from "./pages/TitlesEdit";
import Scripts from "./pages/Scripts";
import ScriptsEdit from "./pages/ScriptsEdit";
import ScriptsCreate from "./pages/ScriptsCreate";
import ScriptsLibrary from "./pages/ScriptsLibrary";
import ScriptsLibraryCreate from "./pages/ScriptsLibraryCreate";
import ScriptsLibraryEdit from "./pages/ScriptsLibraryEdit";
import Trending from "./pages/Trending";
import Analytics from "./pages/Analytics";
import AnalyticsOverview from "./pages/analytics/Overview";
import Offers from "./pages/analytics/Offers";
import OfferDetail from "./pages/analytics/OfferDetail";
import AnalyticsLinkDetail from "./pages/analytics/LinkDetail";
import AnalyticsSources from "./pages/analytics/Sources";
import AnalyticsPlatformDetail from "./pages/analytics/PlatformDetail";
import AnalyticsAudienceGrowth from "./pages/analytics/AudienceGrowth";
import Post from "./pages/post/Post";
import PostDrafts from "./pages/post/Drafts";
import PostScheduled from "./pages/post/Scheduled";
import PostHistory from "./pages/post/History";
import ContentCalendarPage from "./pages/CalendarPage";
import AdminPosts from "./pages/admin/AdminPosts";
import AdminUsers from "./pages/admin/AdminUsers";
import AdminUserEdit from "./pages/admin/AdminUserEdit";
import AdminOffers from "./pages/admin/AdminOffers";
import AdminLeadMagnet from "./pages/admin/AdminLeadMagnet";
import LeadMagnetPrint from "./pages/admin/LeadMagnetPrint";
import Seo from "./pages/Seo";
import AdminLinks from "./pages/admin/AdminLinks";
import Connections from "./pages/Connections";
import Boosts from "./pages/Boosts";
import Review from "./pages/Review";
import ReviewVideo from "./pages/ReviewVideo";
import Settings from "./pages/Settings";
import Stats from "./pages/Stats";
import Monetization from "./pages/Monetization";
import Tracking from "./pages/Tracking";
import TrackingNew from "./pages/TrackingNew";
import TrackingEdit from "./pages/TrackingEdit";
import Summarize from "./pages/Summarize";
import OAuthCallback from "./pages/OAuthCallback";
import VerifyEmail from "./pages/VerifyEmail";
import Onboarding from "./pages/Onboarding";
import FeatureRequests from "./pages/FeatureRequests";
import OnboardingGate from "./components/OnboardingGate";
import Plans from "./pages/Plans";
import Checkout from "./pages/Checkout";
import RevenueCalculator from "./pages/RevenueCalculator";
import ThumbnailPreview from "./pages/ThumbnailPreview";
import FreeToolsHub from "./pages/free-tools/FreeToolsHub";
import YoutubeTranscript from "./pages/free-tools/YoutubeTranscript";
import TiktokTranscript from "./pages/free-tools/TiktokTranscript";
import InstagramTranscript from "./pages/free-tools/InstagramTranscript";
import { AuthProvider } from "./hooks/useAuth";
import { UserRoleProvider } from "./hooks/useUserRole";
import { AIModelProcessingProvider } from "./contexts/AIModelProcessingContext";
import { UserCreditsProvider } from "./contexts/UserCreditsContext";
import ProtectedRoute from "./components/ProtectedRoute";
import ProFeatureRoute from "./components/ProFeatureRoute";
import { MetaPixel } from "./components/MetaPixel";
import Outliers from "./pages/Outliers";
import OutliersLibrary from "./pages/OutliersLibrary";
import OutlierBreakdown from "./pages/OutlierBreakdown";

const queryClient = new QueryClient();

// Component to handle external redirects
const ExternalRedirect = ({ to }: { to: string }) => {
  useEffect(() => {
    window.location.href = to;
  }, [to]);
  return null;
};

// Redirect an old `:id` route to the same id under a new base path.
const ParamRedirect = ({ base }: { base: string }) => {
  const { id } = useParams();
  return <Navigate to={id ? `${base}/${id}` : base} replace />;
};

const App = () => (
  <QueryClientProvider client={queryClient}>
    <TooltipProvider>
      <MetaPixel />
      <Toaster />
      <Sonner />
      <BrowserRouter>
        <AuthProvider>
          <UserCreditsProvider>
            <UserRoleProvider>
              <Routes>
                <Route path="/" element={<Index />} />
                <Route path="/auth" element={<Auth />} />
                <Route path="/privacy" element={<Privacy />} />
                <Route path="/terms" element={<Terms />} />
                <Route path="/ai" element={<ConnectAI />} />
                <Route path="/checkout" element={<Checkout />} />
                <Route path="/youtube-monetization-calculator" element={<RevenueCalculator />} />
                <Route path="/thumbnail-preview" element={<ThumbnailPreview />} />
                <Route path="/free-tools" element={<FreeToolsHub />} />
                <Route path="/free-tools/youtube-transcript" element={<YoutubeTranscript />} />
                <Route path="/free-tools/tiktok-transcript" element={<TiktokTranscript />} />
                <Route path="/free-tools/instagram-transcript" element={<InstagramTranscript />} />
                <Route path="/oauth/callback" element={<OAuthCallback />} />
                <Route path="/verify-email" element={<VerifyEmail />} />
                <Route path="/reset-password" element={<ResetPassword />} />
                <Route path="/onboarding" element={
                  <ProtectedRoute>
                    <Onboarding />
                  </ProtectedRoute>
                } />
                {/* Lead-magnet PDF render — outside DashboardLayout so the print output has no app chrome */}
                <Route path="/admin/lead-magnet/print" element={
                  <ProtectedRoute>
                    <LeadMagnetPrint />
                  </ProtectedRoute>
                } />
                <Route path="/blog" element={<ExternalRedirect to="http://13.41.160.78" />} />
                <Route path="/blog/*" element={<ExternalRedirect to="http://13.41.160.78" />} />
                <Route path="/dashboard" element={
                  <ProtectedRoute>
                    <OnboardingGate>
                      <AIModelProcessingProvider>
                        <DashboardLayout />
                      </AIModelProcessingProvider>
                    </OnboardingGate>
                  </ProtectedRoute>
                }>
                  <Route index element={<Dashboard />} />
                  <Route path="outliers" element={
                    <ProFeatureRoute>
                      <Outliers />
                    </ProFeatureRoute>
                  } />
                  <Route path="outliers/library" element={
                    <ProFeatureRoute>
                      <OutliersLibrary />
                    </ProFeatureRoute>
                  } />
                  <Route path="outliers/breakdown/:platform/:videoId" element={
                    <ProFeatureRoute>
                      <OutlierBreakdown />
                    </ProFeatureRoute>
                  } />
                  <Route path="titles/new" element={
                    <ProFeatureRoute>
                      <TitlesNew />
                    </ProFeatureRoute>
                  } />
                  {/* Thumbnails + AI models routes retired.
                  <Route path="thumbnails" element={<Navigate to="/dashboard/thumbnails/create" replace />} />
                  <Route path="thumbnails/create" element={
                    <ProFeatureRoute>
                      <Thumbnails />
                    </ProFeatureRoute>
                  } />
                  <Route path="thumbnails/review" element={
                    <ProFeatureRoute>
                      <ThumbnailReview />
                    </ProFeatureRoute>
                  } />
                  <Route path="ai-models" element={<AIModels />} />
                  */}
                  <Route path="scripts" element={
                    <ProFeatureRoute>
                      <Scripts />
                    </ProFeatureRoute>
                  } />
                  <Route path="scripts/edit/:id" element={
                    <ProFeatureRoute>
                      <ScriptsEdit />
                    </ProFeatureRoute>
                  } />
                  <Route path="scripts/create" element={
                    <ProFeatureRoute>
                      <ScriptsCreate />
                    </ProFeatureRoute>
                  } />
                  <Route path="scripts/create/:id" element={
                    <ProFeatureRoute>
                      <ScriptsCreate />
                    </ProFeatureRoute>
                  } />
                  <Route path="scripts/library" element={
                    <ProFeatureRoute>
                      <ScriptsLibrary />
                    </ProFeatureRoute>
                  } />
                  <Route path="scripts/library/new" element={
                    <ProFeatureRoute>
                      <ScriptsLibraryCreate />
                    </ProFeatureRoute>
                  } />
                  <Route path="scripts/library/edit/:id" element={
                    <ProFeatureRoute>
                      <ScriptsLibraryEdit />
                    </ProFeatureRoute>
                  } />
                  <Route path="billing" element={<Plans />} />
                  <Route path="trending" element={<Trending />} />
                  <Route path="summarize/:videoId" element={<Summarize />} />
                  {/* New Analytics (link-attribution / revenue) feature */}
                  <Route path="analytics" element={<Navigate to="/dashboard/analytics/overview" replace />} />
                  <Route path="analytics/overview" element={<AnalyticsOverview />} />
                  <Route path="analytics/sources" element={<AnalyticsSources />} />
                  <Route path="analytics/audience-growth" element={<AnalyticsAudienceGrowth />} />
                  <Route path="analytics/platform/:id" element={<AnalyticsPlatformDetail />} />
                  {/* Offers (formerly Landing Pages) — amalgamated with Links under Monetization */}
                  <Route path="monetization/offers" element={<Offers />} />
                  <Route path="monetization/offers/:id" element={<OfferDetail />} />
                  <Route path="monetization/links/:id" element={<AnalyticsLinkDetail />} />
                  {/* Redirects from the old analytics/landing-pages + analytics/links paths */}
                  <Route path="analytics/landing-pages" element={<Navigate to="/dashboard/monetization/offers" replace />} />
                  <Route path="analytics/landing-pages/:id" element={<ParamRedirect base="/dashboard/monetization/offers" />} />
                  <Route path="analytics/links" element={<Navigate to="/dashboard/monetization/offers" replace />} />
                  <Route path="analytics/links/:id" element={<ParamRedirect base="/dashboard/monetization/links" />} />
                  {/* Legacy YouTube channel analytics — kept, no sidebar item */}
                  <Route path="channel-analytics" element={<Analytics />} />
                  {/* Multi-platform Post composer + calendar */}
                  <Route path="post" element={<Post />} />
                  <Route path="post/:id" element={<Post />} />
                  <Route path="post/drafts" element={<PostDrafts />} />
                  <Route path="post/scheduled" element={<PostScheduled />} />
                  <Route path="post/history" element={<PostHistory />} />
                  <Route path="calendar" element={<ContentCalendarPage />} />
                  <Route path="post/calendar" element={<Navigate to="/dashboard/calendar" replace />} />
                  {/* Connect social accounts */}
                  <Route path="connections" element={<Connections />} />
                  {/* Boosts — like-threshold automations (auto repost / auto promo) */}
                  <Route path="boosts" element={<Boosts />} />
                  <Route path="review" element={<Review />} />
                  <Route path="review/video/:id" element={<ReviewVideo />} />
                  <Route path="monetization" element={<Monetization />} />
                  <Route path="stats" element={<Stats />} />
                  <Route path="tracking" element={<Tracking />} />
                  <Route path="tracking/new" element={<TrackingNew />} />
                  <Route path="tracking/edit/:id" element={<TrackingEdit />} />
                  <Route path="admin/posts" element={<AdminPosts />} />
                  <Route path="admin/users" element={<AdminUsers />} />
                  <Route path="admin/users/:id/edit" element={<AdminUserEdit />} />
                  <Route path="admin/offers" element={<AdminOffers />} />
                  <Route path="admin/lead-magnet" element={<AdminLeadMagnet />} />
                  <Route path="seo" element={<Seo />} />
                  <Route path="admin/links" element={<AdminLinks />} />
                  <Route path="settings" element={<Settings />} />
                  <Route path="feature-requests" element={<FeatureRequests />} />
                </Route>
                {/* ADD ALL CUSTOM ROUTES ABOVE THE CATCH-ALL "*" ROUTE */}
                <Route path="*" element={<NotFound />} />
              </Routes>
            </UserRoleProvider>
          </UserCreditsProvider>
        </AuthProvider>
      </BrowserRouter>
    </TooltipProvider>
  </QueryClientProvider>
);

export default App;
