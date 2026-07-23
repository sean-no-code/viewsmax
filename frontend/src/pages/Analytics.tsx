import { useState, useEffect, useCallback } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";

import { BarChart3, Eye, Users, TrendingUp, Clock, Play, Youtube, RefreshCw, Unplug } from "lucide-react";
import { toast } from "sonner";
import { youtubeAuthService, type YouTubeChannelData, type YouTubeAnalytics, type YouTubeVideo } from "@/lib/youtube-auth";
import { viewsMaxApi } from "@/lib/api-service";
import PrivacyConsentDialog from "@/components/PrivacyConsentDialog";
import { useAuth } from "@/hooks/useAuth";
import { hasUserConsented, recordUserConsent, hasLocalConsent } from "@/lib/consent";

interface VideoData {
  id?: string;
  title: string;
  views: string;
  publishedAt: string;
  duration?: string;
  thumbnail?: string;
  likes?: string;
  tags?: string[];
}

interface BackendChannel {
  id: number;
  youtube_channel_id: string;
  channel_name: string;
  channel_description?: string;
  subscriber_count: number;
  video_count: number;
  view_count: number;
  avg_watch_time?: number;
  profile_image_url?: string;
  custom_url?: string;
  country?: string;
  published_at?: string;
}

interface ChannelAnalytics {
  avg_watch_time: number;
  total_views: number;
  estimated_minutes_watched: number;
  raw_data: any;
}

const Analytics = () => {
  const { user } = useAuth();
  const [isChannelConnected, setIsChannelConnected] = useState(false);
  const [channelData, setChannelData] = useState<YouTubeChannelData | null>(null);
  const [analyticsData, setAnalyticsData] = useState<YouTubeAnalytics | null>(null);
  const [isConnecting, setIsConnecting] = useState(false);
  const [isLoadingAnalytics, setIsLoadingAnalytics] = useState(false);
  const [isRealAuth, setIsRealAuth] = useState(false);
  const [showAllPlaylists, setShowAllPlaylists] = useState(false);
  const [showPrivacyDialog, setShowPrivacyDialog] = useState(false);
  const [isLoadingChannels, setIsLoadingChannels] = useState(true);

  // Backend channel data
  const [backendChannels, setBackendChannels] = useState<BackendChannel[]>([]);
  const [selectedChannel, setSelectedChannel] = useState<BackendChannel | null>(null);
  const [isRefreshingChannel, setIsRefreshingChannel] = useState(false);
  const [refreshStatus, setRefreshStatus] = useState<'idle' | 'success' | 'error'>('idle');
  const [channelAnalytics, setChannelAnalytics] = useState<ChannelAnalytics | null>(null);
  


  // Fetch analytics data from backend
  const fetchAnalyticsData = useCallback(async () => {
    if (!selectedChannel) return;
    
    setIsLoadingAnalytics(true);
    
    try {
      toast.info('Fetching your channel analytics...');
      
      // Get token from auth session
      const storedSession = localStorage.getItem('auth_session');
      let token = null;
      if (storedSession) {
        try {
          const session = JSON.parse(storedSession);
          token = session.token;
        } catch (error) {
          console.error('Error parsing auth session:', error);
        }
      }

      if (!token) {
        toast.error('Authentication required');
        return;
      }

      console.log('🔍 Fetching analytics from backend for channel:', selectedChannel.id);
      const response = await viewsMaxApi.getChannelAnalytics(token, selectedChannel.id);
      
      if (!response.success || !response.data) {
        throw new Error(response.error || 'Failed to fetch analytics');
      }

      const analyticsData = response.data;
      console.log('📊 Analytics data received from backend:', analyticsData);

      // Get videos for top/recent videos
      const videosResponse = await viewsMaxApi.getChannelVideos(token, selectedChannel.id, 100);
      const allVideos = videosResponse.success && videosResponse.data ? videosResponse.data.videos : [];
      
      // Sort videos for top/recent
      const sortedByViews = [...allVideos].sort((a, b) => (b.view_count || 0) - (a.view_count || 0));
      const sortedByDate = [...allVideos].sort((a, b) => {
        const dateA = new Date(a.published_at).getTime();
        const dateB = new Date(b.published_at).getTime();
        return dateB - dateA;
      });

      // Transform videos to match frontend format
      const transformVideo = (video: any): YouTubeVideo => ({
        id: video.youtube_video_id || '',
        title: video.title || '',
        description: video.description || '',
        views: video.view_count?.toLocaleString() || '0',
        publishedAt: video.formatted_published_at || video.published_at || '',
        publishedAtRaw: video.published_at || '',
        duration: video.formatted_duration || video.duration || '',
        durationRaw: video.duration || '',
        thumbnail: video.thumbnail_high_url || video.thumbnail_medium_url || video.thumbnail_url || '',
        likes: video.like_count?.toLocaleString() || '0',
        tags: [] // Tags not stored in backend
      });

      // Transform backend data to match frontend format
      const transformedAnalytics: YouTubeAnalytics = {
        views: selectedChannel.view_count,
        subscribers: selectedChannel.subscriber_count,
        videosPublished: selectedChannel.video_count,
        avgWatchTime: analyticsData.watchTimeAnalytics?.averageViewDuration || '0:00',
        topVideos: sortedByViews.slice(0, 10).map(transformVideo),
        recentVideos: sortedByDate.slice(0, 10).map(transformVideo),
        playlists: analyticsData.playlists || [],
        channelStats: {
          totalViews: selectedChannel.view_count.toString(),
          subscriberCount: selectedChannel.subscriber_count.toString(),
          videoCount: selectedChannel.video_count.toString(),
          viewCount: selectedChannel.view_count.toString(),
          customUrl: selectedChannel.custom_url,
          description: selectedChannel.channel_description || '',
          publishedAt: selectedChannel.published_at || '',
          country: selectedChannel.country
        },
        viewsOverTime: analyticsData.viewsOverTime || [],
        audienceDemographics: analyticsData.audienceDemographics ? {
          ageGroups: {
            age13_17: analyticsData.audienceDemographics.ageGroups?.age13_17 || 0,
            age18_24: analyticsData.audienceDemographics.ageGroups?.age18_24 || 0,
            age25_34: analyticsData.audienceDemographics.ageGroups?.age25_34 || 0,
            age35_44: analyticsData.audienceDemographics.ageGroups?.age35_44 || 0,
            age45_54: analyticsData.audienceDemographics.ageGroups?.age45_54 || 0,
            age55_64: analyticsData.audienceDemographics.ageGroups?.age55_64 || 0,
            age65_: analyticsData.audienceDemographics.ageGroups?.age65_ || 0,
          },
          gender: {
            male: analyticsData.audienceDemographics.gender?.male || 0,
            female: analyticsData.audienceDemographics.gender?.female || 0,
            other: analyticsData.audienceDemographics.gender?.other || 0,
          },
          topCountries: analyticsData.audienceDemographics.topCountries || [],
          topCities: [] // Not stored in backend per migration plan
        } : undefined,
        watchTimeAnalytics: analyticsData.watchTimeAnalytics ? {
          averageViewDuration: analyticsData.watchTimeAnalytics.averageViewDuration,
          totalWatchTimeHours: analyticsData.watchTimeAnalytics.totalWatchTimeHours,
          audienceRetention: {
            relative: [],
            absolute: []
          }
        } : undefined,
        eligibilityInfo: {
          eligible: analyticsData.analyticsEligible,
          reason: analyticsData.analyticsReason || undefined
        }
      };

      setAnalyticsData(transformedAnalytics);
      
      if (analyticsData.viewsOverTime && analyticsData.viewsOverTime.length > 0) {
        toast.success('✅ Analytics data loaded successfully!');
      } else if (!analyticsData.analyticsEligible) {
        console.warn('📊 Advanced analytics unavailable:', analyticsData.analyticsReason);
        toast.info('📊 Basic channel data loaded. Advanced analytics may need time to accumulate.');
      } else {
        toast.info('📊 Basic channel data loaded. Advanced analytics may need time to accumulate.');
      }
    } catch (error: any) {
      console.error('Error fetching analytics:', error);
      toast.error(`Failed to fetch analytics: ${error.message || 'Unknown error'}`);
    } finally {
      setIsLoadingAnalytics(false);
    }
  }, [selectedChannel]);

  // Fetch backend channels
  const fetchBackendChannels = useCallback(async () => {
    if (!user) {
      setIsLoadingChannels(false);
      console.log('No user found, skipping backend channels fetch');
      return;
    }

    setIsLoadingChannels(true);
    try {
      // Get token from auth session
      const storedSession = localStorage.getItem('auth_session');
      let token = null;
      if (storedSession) {
        try {
          const session = JSON.parse(storedSession);
          token = session.token;
        } catch (error) {
          console.error('Error parsing auth session:', error);
        }
      }

      if (!token) {
        console.log('No auth_token found in session');
        setIsLoadingChannels(false);
        return;
      }

      console.log('Fetching backend channels with token:', token.substring(0, 20) + '...');
      const response = await viewsMaxApi.getChannels(token);
      console.log('Backend channels response:', response);

      if (response.success && response.data) {
        console.log('Setting backend channels:', response.data);
        setBackendChannels(response.data);
        // Auto-select the first channel if available
        if (response.data.length > 0 && !selectedChannel) {
          console.log('Auto-selecting channel:', response.data[0]);
          setSelectedChannel(response.data[0]);
        }
        // Set channel connected state based on whether channels exist
        setIsChannelConnected(response.data.length > 0);
      } else {
        console.error('Failed to get channels:', response.error);
        setIsChannelConnected(false);
      }
    } catch (error) {
      console.error('Failed to fetch backend channels:', error);
      setIsChannelConnected(false);
    } finally {
      setIsLoadingChannels(false);
    }
  }, [user, selectedChannel]);

  // Refresh channel data
  const handleRefreshChannel = async () => {
    console.log('handleRefreshChannel called');
    console.log('selectedChannel:', selectedChannel);

    if (!selectedChannel) {
      console.log('No selectedChannel, returning');
      return;
    }

    setIsRefreshingChannel(true);
    setRefreshStatus('idle');

    try {
      // Get token from auth session
      const storedSession = localStorage.getItem('auth_session');
      let token = null;
      if (storedSession) {
        try {
          const session = JSON.parse(storedSession);
          token = session.token;
        } catch (error) {
          console.error('Error parsing auth session:', error);
        }
      }

      console.log('auth_token from session:', token ? token.substring(0, 20) + '...' : 'null');

      if (!token) {
        toast.error('Authentication required');
        return;
      }

      console.log('Calling viewsMaxApi.refreshChannelData with channel ID:', selectedChannel.id);
      const response = await viewsMaxApi.refreshChannelData(token, selectedChannel.id);
      console.log('refreshChannelData response:', response);

      if (response.success && response.data) {
        console.log('Refresh successful, updating state');
        setRefreshStatus('success');
        console.log('Setting selectedChannel to:', response.data.data);
        setSelectedChannel(response.data.data);
        toast.success('Channel data refreshed successfully');
        // Refresh the channels list to get updated data
        await fetchBackendChannels();
        // Fetch fresh analytics data after refresh
        await fetchAnalyticsData();
      } else {
        console.log('Refresh failed:', response.error);
        setRefreshStatus('error');
        toast.error(response.error || 'Failed to refresh channel data');
      }
    } catch (error) {
      console.error('Failed to refresh channel data:', error);
      setRefreshStatus('error');
      toast.error('Failed to refresh channel data');
    } finally {
      setIsRefreshingChannel(false);
    }
  };

  // Format watch time from seconds to MM:SS
  const formatWatchTime = (seconds: number) => {
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;
    return `${minutes}:${remainingSeconds.toString().padStart(2, '0')}`;
  };

  // Fetch analytics when selectedChannel changes
  useEffect(() => {
    if (selectedChannel) {
      fetchAnalyticsData();
    }
  }, [selectedChannel, fetchAnalyticsData]);

  // Fetch backend channels on mount
  useEffect(() => {
    fetchBackendChannels();
  }, [fetchBackendChannels]);

  const handleConnectChannel = async () => {
    // If user is logged in, check local consent first
    if (user) {
      // If local data is not present, show consent form
      if (!hasLocalConsent(user.id.toString())) {
        console.debug('No local consent data found, showing consent form');
        setShowPrivacyDialog(true);
        return;
      }
      
      // If local consent exists, check database to be sure
      try {
        // Show loading state when starting consent check
        setIsConnecting(true);
        const consentOk = await hasUserConsented(user.id.toString());
        if (consentOk) {
          await handlePrivacyAccepted();
          return;
        }
        // Hide loading state if consent check didn't find existing consent
        setIsConnecting(false);
      } catch (e) {
        console.debug('Consent check failed', e);
        setIsConnecting(false);
      }
    }
    setShowPrivacyDialog(true);
  };

  const handlePrivacyAccepted = async () => {
    setIsConnecting(true);
    
    try {
      // Persist consent if user is authenticated
      if (user) {
        try { await recordUserConsent(user.id.toString()); } catch (e) { console.debug('Consent record failed', e); }
      }
      
      toast.info('Opening Google account picker...');
      console.log('🌐 Starting OAuth flow with account selection...');
      
      // Connect user's actual channel with real OAuth
      const channelData = await youtubeAuthService.connectUserChannel();
      
      
      setChannelData(channelData);
      setIsChannelConnected(true);
      setIsRealAuth(true);
      
      toast.success(`Successfully connected ${channelData.channelName}! 🎉`);
      
      // Refresh backend channels list to get the newly connected channel
      // Get token for fetching channels
      const storedSession = localStorage.getItem('auth_session');
      let token = null;
      if (storedSession) {
        try {
          const session = JSON.parse(storedSession);
          token = session.token;
        } catch (error) {
          console.error('Error parsing auth session:', error);
        }
      }

      if (token) {
        // Fetch channels to get the newly connected one
        const channelsResponse = await viewsMaxApi.getChannels(token);
        if (channelsResponse.success && channelsResponse.data && channelsResponse.data.length > 0) {
          const newChannel = channelsResponse.data[0];
          setBackendChannels(channelsResponse.data);
          setSelectedChannel(newChannel);
          setIsChannelConnected(true);
          
          // Give backend a moment to finish processing analytics and videos
          await new Promise(resolve => setTimeout(resolve, 2000));
          
          // Now fetch analytics with the selected channel
          // The useEffect will also trigger, but we'll call it directly to ensure it happens
          await fetchAnalyticsData();
        }
      }
    } catch (error) {
      console.error('Error connecting YouTube channel:', error);
      
      if (error.message.includes('popup')) {
        toast.error('Please allow popups and try again');
      } else if (error.message.includes('quota')) {
        toast.error('API quota exceeded. Please try again later or request quota increase.');
      } else {
        toast.error(`Failed to connect: ${error.message}`);
      }
    } finally {
      setIsConnecting(false);
    }
  };

  const disconnectChannel = async () => {
    if (!selectedChannel) {
      toast.error('No channel selected to disconnect');
      return;
    }

    try {
      // Get token from auth session
      const storedSession = localStorage.getItem('auth_session');
      let token = null;
      if (storedSession) {
        try {
          const session = JSON.parse(storedSession);
          token = session.token;
        } catch (error) {
          console.error('Error parsing auth session:', error);
        }
      }

      if (!token) {
        toast.error('Authentication required');
        return;
      }

      // Call backend to disconnect channel
      const response = await viewsMaxApi.disconnectChannel(token, selectedChannel.id);

      if (response.success) {
        // Clear local state
        youtubeAuthService.disconnect();
        setChannelData(null);
        setAnalyticsData(null);
        setSelectedChannel(null);
        setBackendChannels([]);
        setIsChannelConnected(false);
        setIsRealAuth(false);
        toast.success('YouTube channel disconnected successfully');
      } else {
        toast.error(response.error || 'Failed to disconnect channel');
      }
    } catch (error) {
      console.error('Error disconnecting channel:', error);
      toast.error('Failed to disconnect channel');
    }
  };


  // Use real analytics data only - no fake fallbacks
  const stats = [
    {
      title: "Total Views",
      value: selectedChannel?.view_count?.toLocaleString() || analyticsData?.channelStats?.totalViews || "No data",
      change: "N/A", // Change metrics would require historical data
      icon: Eye,
      positive: true
    },
    {
      title: "Subscribers",
      value: selectedChannel?.subscriber_count?.toLocaleString() || analyticsData?.channelStats?.subscriberCount || "No data",
      change: "N/A",
      icon: Users,
      positive: true
    },
    {
      title: "Videos Published",
      value: selectedChannel?.video_count?.toString() || analyticsData?.channelStats?.videoCount || "No data",
      change: "N/A",
      icon: Play,
      positive: true
    },
    {
      title: "Avg. Watch Time",
      value: analyticsData?.avgWatchTime || "No data",
      change: "N/A",
      icon: Clock,
      positive: false
    }
  ];

  // Use real top videos data only - no fake fallbacks
  const topVideos = analyticsData?.topVideos || [];

  // Simple helper to identify shorts vs regular videos
  const isShortVideo = (duration: string) => {
    if (!duration) return false;
    const match = duration.match(/PT(?:(\d+)M)?(?:(\d+)S)?/);
    if (!match) return false;
    
    const minutes = parseInt(match[1] || '0');
    const seconds = parseInt(match[2] || '0');
    const totalSeconds = minutes * 60 + seconds;
    
    return totalSeconds <= 60;
  };

  // Get videos for display (no filtering)
  const displayTopVideos = topVideos || [];
  const displayRecentVideos = analyticsData?.recentVideos?.filter(video => 
    video.duration && isShortVideo(video.duration)
  ) || [];

  // Show loading state while checking channels, don't show connect screen if we're still loading
  if (isLoadingChannels) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="text-center">
          <div className="h-8 w-8 animate-spin rounded-full border-4 border-primary border-t-transparent mx-auto mb-4" />
          <p className="text-muted-foreground">Loading...</p>
        </div>
      </div>
    );
  }

  // Show connect channel view if no channel is connected
  if (!isChannelConnected) {
    return (
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-2xl font-bold text-foreground">YouTube Analytics</h2>
            <p className="text-muted-foreground">Connect your YouTube channel to view comprehensive analytics</p>
          </div>
        </div>

        {/* Connect Channel Card */}
        <div className="flex items-center justify-center min-h-[400px]">
          <Card className="w-full max-w-md">
            <CardHeader className="text-center">
              <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-red-100">
                <Youtube className="h-8 w-8 text-red-600" />
              </div>
              <CardTitle>Connect Your YouTube Channel</CardTitle>
              <CardDescription>
                Choose your Google account and sign in to access your YouTube channel analytics, playlists, and video data.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <Button 
                onClick={handleConnectChannel} 
                disabled={isConnecting}
                className="w-full"
                size="lg"
              >
                {isConnecting ? (
                  <>
                    <div className="mr-2 h-4 w-4 animate-spin rounded-full border-2 border-background border-t-transparent" />
                    Connecting to YouTube...
                  </>
                ) : (
                  <>
                    <Youtube className="mr-2 h-4 w-4" />
                    Connect Your YouTube Channel
                  </>
                )}
              </Button>
              
            </CardContent>
          </Card>
        </div>

        <PrivacyConsentDialog 
          open={showPrivacyDialog}
          onOpenChange={setShowPrivacyDialog}
          onAccept={handlePrivacyAccepted}
        />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-foreground">Analytics</h2>
          <p className="text-muted-foreground">Track your channel's performance and growth</p>
        </div>
        <div className="flex gap-2">
          <Button 
            variant="outline" 
            size="icon"
            onClick={handleRefreshChannel}
            disabled={isRefreshingChannel || isLoadingAnalytics}
            title="Refresh Data"
          >
            <RefreshCw className={`h-4 w-4 ${(isRefreshingChannel || isLoadingAnalytics) ? 'animate-spin' : ''}`} />
          </Button>
          <Button 
            variant="outline" 
            size="icon"
            onClick={disconnectChannel}
            title="Disconnect Channel"
          >
            <Unplug className="h-4 w-4" />
          </Button>
        </div>
      </div>

      {/* Stats Overview */}
      <div className="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
        {stats.map((stat, index) => (
          <Card key={index}>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                {stat.title}
                {('derived' in stat && Boolean((stat as { derived?: boolean }).derived)) && (
                  <span className="text-xs opacity-60 ml-1">*Derived</span>
                )}
              </CardTitle>
              <stat.icon className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-foreground">{stat.value}</div>
              <p className={`text-xs ${stat.positive ? 'text-green-600' : 'text-red-600'}`}>
                {stat.change} from last month
              </p>
            </CardContent>
          </Card>
        ))}
      </div>

                {/* Top Videos Section */}
      {displayTopVideos && displayTopVideos.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Top Performing Videos</CardTitle>
            <CardDescription>Your most successful content by view count</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="space-y-4">
              {displayTopVideos.slice(0, 5).map((video, index) => (
                <div key={video.id || index} className="flex items-center gap-4 p-4 rounded-lg hover:bg-secondary/50 transition-colors">
                  {video.thumbnail && video.thumbnail !== '🎥' ? (
                    <img 
                      src={video.thumbnail} 
                      alt={video.title}
                      className="w-16 h-12 object-cover rounded"
                    />
                  ) : (
                    <div className="w-16 h-12 bg-gray-200 rounded flex items-center justify-center text-2xl">
                      🎥
                    </div>
                  )}
                  <div className="flex-1">
                    <h4 className="font-medium text-foreground line-clamp-2">{video.title}</h4>
                    <div className="flex items-center gap-4 text-sm text-muted-foreground mt-1">
                      <span>{video.views} views</span>
                      {video.likes && <span>{video.likes} likes</span>}
                      <span>{video.duration}</span>
                      <span>{video.publishedAt}</span>
                    </div>
                  </div>
                  {video.id && (
                    <Button 
                      variant="ghost" 
                      size="sm"
                      onClick={() => window.open(`https://youtube.com/watch?v=${video.id}`, '_blank')}
                    >
                      View Video
                    </Button>
                  )}
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      {/* Shorts Section */}
      {displayRecentVideos && displayRecentVideos.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Shorts</CardTitle>
            <CardDescription>Your short-form content</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
              {displayRecentVideos.slice(0, 4).map((video, index) => (
                <div key={video.id || index} className="border rounded-lg p-4 hover:bg-secondary/50 transition-colors">
                  {video.thumbnail ? (
                    <img 
                      src={video.thumbnail} 
                      alt={video.title}
                      className="w-full h-32 object-cover rounded mb-3"
                    />
                  ) : (
                    <div className="w-full h-32 bg-gray-200 rounded mb-3 flex items-center justify-center text-3xl">
                      🎬
                    </div>
                  )}
                  <h4 className="font-medium text-foreground line-clamp-2 mb-2">{video.title}</h4>
                  <div className="text-sm text-muted-foreground space-y-1">
                    <p>{video.views} views</p>
                    <p>{video.publishedAt}</p>
                  </div>
                  {video.id && (
                    <Button 
                      variant="outline" 
                      size="sm" 
                      className="w-full mt-3"
                      onClick={() => window.open(`https://youtube.com/watch?v=${video.id}`, '_blank')}
                    >
                      View Short
                    </Button>
                  )}
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      {/* Playlists Section */}
      {analyticsData?.playlists && analyticsData.playlists.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Your Playlists</CardTitle>
            <CardDescription>All playlists on your channel</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
              {(showAllPlaylists ? analyticsData.playlists : analyticsData.playlists.slice(0, 6)).map((playlist, index) => (
                <div key={playlist.id || index} className="border rounded-lg p-4 hover:bg-secondary/50 transition-colors">
                  {playlist.thumbnail ? (
                    <img 
                      src={playlist.thumbnail} 
                      alt={playlist.title}
                      className="w-full h-32 object-cover rounded mb-3"
                    />
                  ) : (
                    <div className="w-full h-32 bg-gray-200 rounded mb-3 flex items-center justify-center text-3xl">
                      📝
                    </div>
                  )}
                  <h5 className="font-medium text-sm line-clamp-2 mb-2">{playlist.title}</h5>
                  <div className="text-xs text-muted-foreground space-y-1">
                    <p>{playlist.itemCount} videos</p>
                    <p>{playlist.publishedAt}</p>
                  </div>
                  {playlist.id && (
                    <Button 
                      variant="outline" 
                      size="sm" 
                      className="w-full mt-3"
                      onClick={() => window.open(`https://youtube.com/playlist?list=${playlist.id}`, '_blank')}
                    >
                      View Playlist
                    </Button>
                  )}
                </div>
              ))}
            </div>
            {analyticsData.playlists.length > 6 && (
              <div className="mt-4 text-center">
                <Button 
                  variant="outline" 
                  onClick={() => setShowAllPlaylists(!showAllPlaylists)}
                >
                  {showAllPlaylists ? 'View Less' : `View All (${analyticsData.playlists.length})`}
                </Button>
              </div>
            )}
          </CardContent>
        </Card>
      )}

      {/* Charts Section */}
      <div className="grid lg:grid-cols-2 gap-6">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <TrendingUp className="w-5 h-5" />
              Views Over Time
            </CardTitle>
            <CardDescription>
              Your channel's view count for the last 30 days
              {analyticsData?.viewsOverTime && analyticsData.viewsOverTime.length > 0 && (
                <span className="ml-2 text-xs bg-green-100 text-green-800 px-2 py-1 rounded">
                  ✅ Real Data
                </span>
              )}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="h-64">
              {isLoadingAnalytics ? (
                <div className="flex items-center justify-center h-full">
                  <div className="text-center">
                    <BarChart3 className="w-12 h-12 text-muted-foreground mx-auto mb-2" />
                    <p className="text-muted-foreground">Loading analytics data...</p>
                  </div>
                </div>
              ) : analyticsData?.viewsOverTime && analyticsData.viewsOverTime.length > 0 ? (
                <div className="space-y-4">
                  <div className="grid grid-cols-3 gap-4 text-sm">
                    <div className="text-center">
                      <p className="text-muted-foreground">Total Views (30-day period) <span className="text-xs opacity-60">*Derived</span></p>
                      <p className="text-xl font-bold text-foreground">
                        {analyticsData.viewsOverTime.reduce((sum, day) => sum + day.views, 0).toLocaleString()}
                      </p>
                    </div>
                    <div className="text-center">
                      <p className="text-muted-foreground">Watch Time (hrs) <span className="text-xs opacity-60">*Derived</span></p>
                      <p className="text-xl font-bold text-foreground">
                        {Math.round(analyticsData.viewsOverTime.reduce((sum, day) => sum + day.estimatedMinutesWatched, 0) / 60).toLocaleString()}
                      </p>
                    </div>
                    <div className="text-center">
                      <p className="text-muted-foreground">Avg. Duration <span className="text-xs opacity-60">*Derived</span></p>
                      <p className="text-xl font-bold text-foreground">
                        {Math.round(analyticsData.viewsOverTime.reduce((sum, day) => sum + day.averageViewDuration, 0) / analyticsData.viewsOverTime.length / 60)}:{Math.round((analyticsData.viewsOverTime.reduce((sum, day) => sum + day.averageViewDuration, 0) / analyticsData.viewsOverTime.length) % 60).toString().padStart(2, '0')}
                      </p>
                    </div>
                  </div>
                  
                  {/* Simple line chart representation */}
                  <div className="space-y-2">
                    <p className="text-sm text-muted-foreground">Daily Views</p>
                    <div className="space-y-1">
                      {analyticsData.viewsOverTime.slice(-7).map((day, index) => {
                        const maxViews = Math.max(...analyticsData.viewsOverTime.map(d => d.views));
                        const barWidth = maxViews > 0 ? (day.views / maxViews) * 100 : 0;
                        return (
                          <div key={index} className="flex items-center gap-2 text-xs">
                            <span className="w-16 text-muted-foreground">{new Date(day.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}</span>
                            <div className="flex-1 bg-secondary rounded-full h-2 relative">
                              <div 
                                className="bg-blue-500 h-full rounded-full transition-all duration-300" 
                                style={{ width: `${barWidth}%` }}
                              />
                            </div>
                            <span className="w-16 text-right text-foreground">{day.views.toLocaleString()}</span>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                </div>
              ) : (
                <div className="flex items-center justify-center h-full">
                  <div className="text-center max-w-md">
                    <BarChart3 className="w-12 h-12 text-muted-foreground mx-auto mb-4" />
                    <h3 className="font-semibold text-foreground mb-2">Views Over Time Not Available</h3>
                    {analyticsData?.eligibilityInfo?.reason ? (
                      <div className="space-y-2 text-sm text-muted-foreground">
                        <p>
                          {analyticsData.eligibilityInfo.reason.includes('403') ? 
                            'Your channel needs to meet YouTube\'s Analytics API requirements. This typically requires:' :
                            ''
                          }
                        </p>
                        {analyticsData.eligibilityInfo.reason.includes('403') && (
                          <ul className="text-xs space-y-1 text-left bg-orange-50 p-3 rounded border">
                            <li>• Channel monetization enabled</li>
                            <li>• Sufficient watch time and subscriber thresholds</li>
                            <li>• Analytics data history (may take days/weeks to accumulate)</li>
                            <li>• Compliance with YouTube Partner Program policies</li>
                          </ul>
                        )}
                        <p className="text-xs mt-3">
                          For now, you can view basic channel statistics above. Advanced analytics will become available as your channel grows.
                        </p>
                      </div>
                    ) : (
                      <div className="space-y-2 text-sm text-muted-foreground">
                        <p>Analytics data is still accumulating for your channel.</p>
                        <p className="text-xs">This feature requires YouTube Analytics API access, which may take time to become available for new channels.</p>
                      </div>
                    )}
                  </div>
                </div>
              )}
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Users className="w-5 h-5" />
              Audience Demographics
            </CardTitle>
            <CardDescription>
              Age, gender, and location breakdown of your viewers
              {analyticsData?.audienceDemographics && (
                <span className="ml-2 text-xs bg-green-100 text-green-800 px-2 py-1 rounded">
                  ✅ Real Data
                </span>
              )}
            </CardDescription>
          </CardHeader>
          <CardContent>
            {isLoadingAnalytics ? (
              <div className="flex items-center justify-center h-32">
                <div className="text-center">
                  <Users className="w-8 h-8 mx-auto mb-2 text-muted-foreground" />
                  <p className="text-muted-foreground">Loading demographics...</p>
                </div>
              </div>
            ) : analyticsData?.audienceDemographics ? (
              <div className="space-y-6">
                {/* Age Groups */}
                <div>
                  <h4 className="font-semibold text-sm mb-3 text-foreground">Age Groups</h4>
                  <div className="space-y-2">
                    {Object.entries(analyticsData.audienceDemographics.ageGroups).map(([ageRange, percentage]) => {
                      if (percentage === 0) return null;
                      const label = ageRange.replace('age', '').replace('_', '-').replace('-', ' - ');
                      return (
                        <div key={ageRange} className="flex items-center gap-3">
                          <span className="text-xs text-muted-foreground w-16">{label}</span>
                          <div className="flex-1 h-2 bg-secondary rounded-full">
                            <div 
                              className="h-full bg-blue-500 rounded-full transition-all duration-300"
                              style={{ width: `${percentage}%` }}
                            />
                          </div>
                          <span className="text-xs font-medium w-12 text-right">{percentage.toFixed(1)}%</span>
                        </div>
                      );
                    })}
                  </div>
                </div>

                {/* Gender */}
                <div>
                  <h4 className="font-semibold text-sm mb-3 text-foreground">Gender</h4>
                  <div className="space-y-2">
                    {Object.entries(analyticsData.audienceDemographics.gender).map(([gender, percentage]) => {
                      if (percentage === 0) return null;
                      const colors = { male: 'bg-blue-500', female: 'bg-pink-500', other: 'bg-purple-500' };
                      return (
                        <div key={gender} className="flex items-center gap-3">
                          <span className="text-xs text-muted-foreground w-16 capitalize">{gender}</span>
                          <div className="flex-1 h-2 bg-secondary rounded-full">
                            <div 
                              className={`h-full rounded-full transition-all duration-300 ${colors[gender as keyof typeof colors]}`}
                              style={{ width: `${percentage}%` }}
                            />
                          </div>
                          <span className="text-xs font-medium w-12 text-right">{percentage.toFixed(1)}%</span>
                        </div>
                      );
                    })}
                  </div>
                </div>

                {/* Top Countries */}
                {analyticsData.audienceDemographics.topCountries.length > 0 && (
                  <div>
                    <h4 className="font-semibold text-sm mb-3 text-foreground">Top Countries</h4>
                    <div className="space-y-2">
                      {analyticsData.audienceDemographics.topCountries.slice(0, 5).map((country, index) => (
                        <div key={index} className="flex items-center gap-3">
                          <span className="text-xs text-muted-foreground w-16">{country.country}</span>
                          <div className="flex-1 h-2 bg-secondary rounded-full">
                            <div 
                              className="h-full bg-green-500 rounded-full transition-all duration-300"
                              style={{ width: `${country.percentage}%` }}
                            />
                          </div>
                          <span className="text-xs font-medium w-16 text-right">{country.views.toLocaleString()}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            ) : (
              <div className="flex items-center justify-center h-48 text-muted-foreground">
                <div className="text-center max-w-md">
                  <Users className="w-8 h-8 mx-auto mb-4 opacity-50" />
                  <h3 className="font-semibold text-foreground mb-2">Audience Demographics Not Available</h3>
                  {analyticsData?.eligibilityInfo?.reason ? (
                    <div className="space-y-2 text-sm">
                      <p>
                        Demographics data requires access to YouTube's advanced analytics, which becomes available as your channel meets certain criteria.
                      </p>
                    </div>
                  ) : (
                    <div className="space-y-2 text-sm">
                      <p>Demographics data is not yet available for your channel.</p>
                      <p className="text-xs">This feature typically becomes available after your channel accumulates sufficient analytics history and meets YouTube's requirements.</p>
                    </div>
                  )}
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Empty State */}
      {(!displayTopVideos || displayTopVideos.length === 0) && 
       (!displayRecentVideos || displayRecentVideos.length === 0) && 
       (!analyticsData?.playlists || analyticsData.playlists.length === 0) && (
        <Card>
          <CardContent className="flex items-center justify-center h-64">
            <div className="text-center">
              <Play className="w-12 h-12 mx-auto mb-4 text-muted-foreground opacity-50" />
              <h3 className="font-semibold text-foreground mb-2">No Content Available</h3>
              <p className="text-muted-foreground mb-4">Connect your YouTube channel to see your videos, shorts, and playlists.</p>
            </div>
          </CardContent>
        </Card>
      )}

      {/* YouTube API Compliance Disclaimer */}
      {isChannelConnected && (
        <Card className="bg-muted/30 border-muted">
          <CardContent className="p-4">
            <p className="text-xs text-muted-foreground">
              <strong>Data Sources:</strong> All metrics are sourced from YouTube APIs. 
              Metrics marked with "*Derived" are calculated from multiple API data points and may not represent official YouTube metrics. 
              Direct API metrics include: subscriber count, video count, individual video views, likes, and comments.
            </p>
          </CardContent>
        </Card>
      )}

      <PrivacyConsentDialog 
        open={showPrivacyDialog}
        onOpenChange={setShowPrivacyDialog}
        onAccept={handlePrivacyAccepted}
      />
    </div>
  );
};

export default Analytics;