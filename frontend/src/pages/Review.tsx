import { useState, useEffect, useCallback } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { Clock, Play, Youtube, RefreshCw, Unplug, Search, Star } from "lucide-react";
import { toast } from "sonner";
import { youtubeAuthService, type YouTubeChannelData, type YouTubeAnalytics } from "@/lib/youtube-auth";
import { viewsMaxApi } from "@/lib/api-service";
import PrivacyConsentDialog from "@/components/PrivacyConsentDialog";
import { useAuth } from "@/hooks/useAuth";
import { hasUserConsented, recordUserConsent, hasLocalConsent } from "@/lib/consent";
import { useNavigate } from "react-router-dom";
import ScoreDisplay from "@/components/ScoreDisplay";
import fireIcon from "@/assets/fire.png";
import smileIcon from "@/assets/smile.png";
import shockedIcon from "@/assets/shocked.png";

interface VideoData {
  id?: string; // YouTube video ID
  databaseId?: number; // Database ID for the video
  title: string;
  views: string;
  publishedAt: string;
  publishedAtRaw?: string; // ISO date for sorting
  duration?: string;
  durationRaw?: string; // ISO 8601 duration for parsing
  thumbnail?: string;
  likes?: string;
  tags?: string[];
  title_score?: {
    id: number;
    status: string;
    avg_score?: number | null;
  };
  thumbnail_score?: {
    id: number;
    status: string;
    avg_score?: number | null;
  };
}

interface BackendChannel {
  id: number;
  youtube_channel_id: string;
  channel_name: string;
  channel_description?: string;
  subscriber_count: number;
  video_count: number;
  view_count: number;
  profile_image_url?: string;
  custom_url?: string;
  country?: string;
  published_at?: string;
}

const Review = () => {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [isChannelConnected, setIsChannelConnected] = useState(false);
  const [channelData, setChannelData] = useState<YouTubeChannelData | null>(null);
  const [analyticsData, setAnalyticsData] = useState<YouTubeAnalytics | null>(null);
  const [isConnecting, setIsConnecting] = useState(false);
  const [isLoadingAnalytics, setIsLoadingAnalytics] = useState(false);
  const [isRealAuth, setIsRealAuth] = useState(false);
  const [showPrivacyDialog, setShowPrivacyDialog] = useState(false);
  const [isLoadingChannels, setIsLoadingChannels] = useState(true);
  
  // Backend channel data
  const [backendChannels, setBackendChannels] = useState<BackendChannel[]>([]);
  const [selectedChannel, setSelectedChannel] = useState<BackendChannel | null>(null);
  
  // Search and review functionality state
  const [searchQuery, setSearchQuery] = useState("");
  const [reviewingVideos, setReviewingVideos] = useState<Set<string>>(new Set());
  
  // Tabs and filter state
  const [activeTab, setActiveTab] = useState("videos");
  const [activeFilter, setActiveFilter] = useState("latest");

  // Fetch backend channels
  const fetchBackendChannels = useCallback(async () => {
    if (!user) {
      setIsLoadingChannels(false);
      return;
    }

    setIsLoadingChannels(true);
    try {
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
        setIsLoadingChannels(false);
        return;
      }

      const response = await viewsMaxApi.getChannels(token);
      if (response.success && response.data) {
        setBackendChannels(response.data);
        if (response.data.length > 0 && !selectedChannel) {
          setSelectedChannel(response.data[0]);
        }
        // Set channel connected state based on whether channels exist
        setIsChannelConnected(response.data.length > 0);
      } else {
        setIsChannelConnected(false);
      }
    } catch (error) {
      console.error('Failed to fetch backend channels:', error);
      setIsChannelConnected(false);
    } finally {
      setIsLoadingChannels(false);
    }
  }, [user, selectedChannel]);

  // Load videos from database (for initial load)
  const loadVideosFromDatabase = useCallback(async () => {
    if (!selectedChannel) return;
    
    setIsLoadingAnalytics(true);
    
    try {
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

      // Get videos from database only
      const videosResponse = await viewsMaxApi.getChannelVideos(token, selectedChannel.id, 100);
      let allVideos: any[] = [];
      if (videosResponse.success && videosResponse.data) {
        allVideos = videosResponse.data.videos || [];
      }
      
      // Sort videos for top/recent
      const sortedByViews = [...allVideos].sort((a, b) => (b.view_count || 0) - (a.view_count || 0));
      const sortedByDate = [...allVideos].sort((a, b) => {
        const dateA = new Date(a.published_at).getTime();
        const dateB = new Date(b.published_at).getTime();
        return dateB - dateA;
      });

      // Transform videos to match frontend format
      const transformVideo = (video: any): VideoData => ({
        id: video.youtube_video_id,
        databaseId: video.id, // Database ID
        title: video.title || '',
        views: video.view_count?.toLocaleString() || '0',
        publishedAt: video.formatted_published_at || video.published_at || '',
        publishedAtRaw: video.published_at || '',
        duration: video.formatted_duration || video.duration || '',
        durationRaw: video.duration || '',
        thumbnail: video.thumbnail_high_url || video.thumbnail_medium_url || video.thumbnail_url || '',
        likes: video.like_count?.toLocaleString() || '0',
        tags: [], // Tags not stored in backend
        title_score: video.title_score || undefined,
        thumbnail_score: video.thumbnail_score || undefined
      });

      // Transform videos for YouTubeAnalytics (requires description field and non-optional id)
      const transformVideoForAnalytics = (video: any) => ({
        id: video.youtube_video_id || '',
        databaseId: video.id, // Database ID
        title: video.title || '',
        description: video.description || '',
        thumbnail: video.thumbnail_high_url || video.thumbnail_medium_url || video.thumbnail_url || '',
        views: video.view_count?.toLocaleString() || '0',
        likes: video.like_count?.toLocaleString() || '0',
        duration: video.formatted_duration || video.duration || '',
        durationRaw: video.duration || '',
        publishedAt: video.formatted_published_at || video.published_at || '',
        publishedAtRaw: video.published_at || '',
        tags: [], // Tags not stored in backend
        title_score: video.title_score || undefined,
        thumbnail_score: video.thumbnail_score || undefined
      });

      // Create analytics data structure
      const analytics: YouTubeAnalytics = {
        views: selectedChannel.view_count,
        subscribers: selectedChannel.subscriber_count,
        videosPublished: selectedChannel.video_count,
        avgWatchTime: '0:00',
        topVideos: sortedByViews.map(transformVideoForAnalytics),
        recentVideos: sortedByDate.map(transformVideoForAnalytics),
        playlists: [],
        channelStats: {
          totalViews: selectedChannel.view_count.toString(),
          subscriberCount: selectedChannel.subscriber_count.toString(),
          videoCount: selectedChannel.video_count.toString(),
          viewCount: selectedChannel.view_count.toString(),
          customUrl: selectedChannel.custom_url,
          description: selectedChannel.channel_description || '',
          publishedAt: selectedChannel.published_at || '',
          country: selectedChannel.country
        }
      };

      setAnalyticsData(analytics);
      
      if (analytics.topVideos && analytics.topVideos.length > 0) {
        toast.success('✅ Videos loaded successfully!');
      } else {
        toast.info('📊 No videos found for review.');
      }
    } catch (error: any) {
      console.error('Error loading videos:', error);
      toast.error(`Failed to load videos: ${error.message || 'Unknown error'}`);
    } finally {
      setIsLoadingAnalytics(false);
    }
  }, [selectedChannel]);

  // Refresh videos from YouTube API (for refresh button)
  const refreshVideosFromYouTube = useCallback(async () => {
    if (!selectedChannel) return;
    
    setIsLoadingAnalytics(true);
    
    try {
      toast.info('Fetching videos from YouTube...');
      
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

      // First, fetch videos from YouTube API and import them
      let allVideos: any[] = [];
      const fetchResponse = await viewsMaxApi.fetchChannelVideosFromYouTube(token, selectedChannel.id);
      
      if (fetchResponse.success && fetchResponse.data) {
        // Backend fetch-videos returns: { success: true, data: [...videos...], imported, updated, total, fetched_from_youtube }
        if (fetchResponse.data.data && Array.isArray(fetchResponse.data.data)) {
          allVideos = fetchResponse.data.data;
          console.log('Videos fetched from YouTube API:', allVideos.length);
        } else if (Array.isArray(fetchResponse.data)) {
          allVideos = fetchResponse.data;
          console.log('Videos fetched from YouTube API:', allVideos.length);
        }
      }
      
      // After fetching from YouTube, get updated videos from database
      const videosResponse = await viewsMaxApi.getChannelVideos(token, selectedChannel.id, 100);
      if (videosResponse.success && videosResponse.data) {
        allVideos = videosResponse.data.videos || [];
      }

      // Sort videos for top/recent
      const sortedByViews = [...allVideos].sort((a, b) => (b.view_count || 0) - (a.view_count || 0));
      const sortedByDate = [...allVideos].sort((a, b) => {
        const dateA = new Date(a.published_at).getTime();
        const dateB = new Date(b.published_at).getTime();
        return dateB - dateA;
      });

      // Transform videos to match frontend format
      const transformVideo = (video: any): VideoData => ({
        id: video.youtube_video_id,
        databaseId: video.id, // Database ID
        title: video.title || '',
        views: video.view_count?.toLocaleString() || '0',
        publishedAt: video.formatted_published_at || video.published_at || '',
        publishedAtRaw: video.published_at || '',
        duration: video.formatted_duration || video.duration || '',
        durationRaw: video.duration || '',
        thumbnail: video.thumbnail_url || video.thumbnail_high_url || '',
        likes: video.like_count?.toLocaleString() || '0',
        tags: [], // Tags not stored in backend
        title_score: video.title_score || undefined,
        thumbnail_score: video.thumbnail_score || undefined
      });

      // Transform videos for YouTubeAnalytics (requires description field and non-optional id)
      const transformVideoForAnalytics = (video: any) => ({
        id: video.youtube_video_id || '',
        databaseId: video.id, // Database ID
        title: video.title || '',
        description: video.description || '',
        thumbnail: video.thumbnail_url || video.thumbnail_high_url || '',
        views: video.view_count?.toLocaleString() || '0',
        likes: video.like_count?.toLocaleString() || '0',
        duration: video.formatted_duration || video.duration || '',
        durationRaw: video.duration || '',
        publishedAt: video.formatted_published_at || video.published_at || '',
        publishedAtRaw: video.published_at || '',
        tags: [], // Tags not stored in backend
        title_score: video.title_score || undefined,
        thumbnail_score: video.thumbnail_score || undefined
      });

      // Create analytics data structure
      const analytics: YouTubeAnalytics = {
        views: selectedChannel.view_count,
        subscribers: selectedChannel.subscriber_count,
        videosPublished: selectedChannel.video_count,
        avgWatchTime: '0:00',
        topVideos: sortedByViews.map(transformVideoForAnalytics),
        recentVideos: sortedByDate.map(transformVideoForAnalytics),
        playlists: [],
        channelStats: {
          totalViews: selectedChannel.view_count.toString(),
          subscriberCount: selectedChannel.subscriber_count.toString(),
          videoCount: selectedChannel.video_count.toString(),
          viewCount: selectedChannel.view_count.toString(),
          customUrl: selectedChannel.custom_url,
          description: selectedChannel.channel_description || '',
          publishedAt: selectedChannel.published_at || '',
          country: selectedChannel.country
        }
      };

      setAnalyticsData(analytics);
      
      if (analytics.topVideos && analytics.topVideos.length > 0) {
        toast.success('✅ Videos refreshed successfully!');
      } else {
        toast.info('📊 No videos found.');
      }
    } catch (error: any) {
      console.error('Error refreshing videos:', error);
      toast.error(`Failed to refresh videos: ${error.message || 'Unknown error'}`);
    } finally {
      setIsLoadingAnalytics(false);
    }
  }, [selectedChannel]);

  // Fetch backend channels on mount
  useEffect(() => {
    fetchBackendChannels();
  }, [fetchBackendChannels]);

  // Load videos from database when selectedChannel changes (initial load only)
  useEffect(() => {
    if (selectedChannel) {
      loadVideosFromDatabase();
      setActiveTab("videos");
    }
  }, [selectedChannel, loadVideosFromDatabase]);

  const handleConnectChannel = async () => {
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
      if (user) {
        try { await recordUserConsent(user.id.toString()); } catch (e) { console.debug('Consent record failed', e); }
      }
      
      toast.info('Opening Google account picker...');

      
      // Connect user's actual channel with real OAuth
      const channelData = await youtubeAuthService.connectUserChannel();
      
      // Clear old cached data when connecting new channel
      try {
        localStorage.removeItem('youtube_analytics_data');
        localStorage.removeItem('youtube_analytics_timestamp');
        localStorage.removeItem('youtube_cache_channel_id');
      } catch (e) {
        // ignore storage errors
      }
      
      setChannelData(channelData);
      setIsChannelConnected(true);
      setIsRealAuth(true);
      // Set videos tab as active when channel is newly connected
      setActiveTab("videos");
      
      toast.success(`Successfully connected ${channelData.channelName}! 🎉`);
      
      // Refresh backend channels to get the newly connected channel
      await fetchBackendChannels();
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
        
        // Clear all cached data when disconnecting
        try {
          localStorage.removeItem('youtube_analytics_data');
          localStorage.removeItem('youtube_analytics_timestamp');
          localStorage.removeItem('youtube_cache_channel_id');
        } catch (e) {
          // ignore storage errors
        }
        
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

  // Review video function - Navigate to review page (review starts automatically in ReviewVideo component)
  const handleReviewVideo = async (video: VideoData) => {
    const videoKey = video.databaseId?.toString() || video.id || '';
    if (!video.databaseId || reviewingVideos.has(videoKey)) return;

    setReviewingVideos(prev => new Set(prev).add(videoKey));
    
    try {
      // Check if scores already exist
      const hasScores = hasCompletedScores(video);
      const message = hasScores 
        ? `Opening review for "${video.title.substring(0, 50)}..."`
        : `Starting review for "${video.title.substring(0, 50)}..."`;
      
      toast.info(message);

      // Navigate to the review video page using database ID (review starts automatically)
      navigate(`/dashboard/review/video/${video.databaseId}`);
    } catch (error: any) {
      console.error('Error navigating to review:', error);
      toast.error(`Failed to open review: ${error.message}`);
    } finally {
      setReviewingVideos(prev => {
        const newSet = new Set(prev);
        newSet.delete(videoKey);
        return newSet;
      });
    }
  };



  // Use real video data from YouTube API
  const allVideos = (analyticsData?.topVideos?.length > 0 || analyticsData?.recentVideos?.length > 0) 
    ? [
        ...(analyticsData?.topVideos || []),
        ...(analyticsData?.recentVideos || [])
      ].filter((video, index, self) => 
        index === self.findIndex(v => v.id === video.id)
      )
    : [];

  // Helper function to parse duration and determine if it's a short
  const isShortVideo = (video: VideoData) => {
    // Use raw duration if available, fallback to formatted duration
    const duration = video.durationRaw || video.duration;
    if (!duration) return false;
    
    // Parse ISO 8601 duration format (PT1H2M3S, PT2M30S, PT45S, etc.)
    const match = duration.match(/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/);
    if (!match) return false;
    
    const hours = parseInt(match[1] || '0');
    const minutes = parseInt(match[2] || '0');
    const seconds = parseInt(match[3] || '0');
    const totalSeconds = hours * 3600 + minutes * 60 + seconds;
    
    return totalSeconds <= 60;
  };

  // Helper function to parse formatted numbers (e.g., "1.2M", "45.3K")
  const parseFormattedNumber = (str: string): number => {
    if (!str) return 0;
    const num = parseFloat(str);
    if (str.includes('M') || str.includes('m')) return Math.round(num * 1000000);
    if (str.includes('K') || str.includes('k')) return Math.round(num * 1000);
    return Math.round(num) || 0;
  };

  // Helper function to check if video has completed scores
  const hasCompletedScores = (video: VideoData): boolean => {
    const hasTitleScore = video.title_score?.status === 'completed' && video.title_score?.avg_score !== null && video.title_score?.avg_score !== undefined;
    const hasThumbnailScore = video.thumbnail_score?.status === 'completed' && video.thumbnail_score?.avg_score !== null && video.thumbnail_score?.avg_score !== undefined;
    return hasTitleScore || hasThumbnailScore;
  };

  // Helper function to get overall average score
  const getOverallAvgScore = (video: VideoData): number | null => {
    const titleScore = video.title_score?.avg_score;
    const thumbnailScore = video.thumbnail_score?.avg_score;
    
    if (titleScore !== null && titleScore !== undefined && thumbnailScore !== null && thumbnailScore !== undefined) {
      return Math.round(((titleScore + thumbnailScore) / 2) * 10) / 10;
    } else if (titleScore !== null && titleScore !== undefined) {
      return titleScore;
    } else if (thumbnailScore !== null && thumbnailScore !== undefined) {
      return thumbnailScore;
    }
    return null;
  };

  // Helper function to get score icon based on score
  const getScoreIcon = (score: number): string => {
    if (score >= 9) return fireIcon;
    if (score >= 6) return smileIcon;
    if (score >= 0) return shockedIcon;
    return shockedIcon;
  };

  // Filter and sort videos based on search and filter preferences
  const filterVideos = useCallback((videos: VideoData[], type: "videos" | "shorts") => {
    if (!videos || videos.length === 0) return [];
    
    // Filter by search query
    const filtered = videos.filter(video =>
      video.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
      (video.tags && video.tags.some((tag: string) => tag.toLowerCase().includes(searchQuery.toLowerCase())))
    );

    let typeFiltered;
    if (type === "shorts") {
      typeFiltered = filtered.filter(video => isShortVideo(video));
    } else if (type === "videos") {
      typeFiltered = filtered.filter(video => !isShortVideo(video));
    } else {
      typeFiltered = filtered;
    }
    
    // Create a copy and sort by active filter
    const sortedVideos = [...typeFiltered];
    

    
    switch (activeFilter) {
      case "latest":
        sortedVideos.sort((a, b) => {
          const dateA = new Date(a.publishedAtRaw || a.publishedAt).getTime();
          const dateB = new Date(b.publishedAtRaw || b.publishedAt).getTime();
          return dateB - dateA; // newest first
        });
        break;
      case "popular":
        sortedVideos.sort((a, b) => {
          const viewsA = parseFormattedNumber(a.views);
          const viewsB = parseFormattedNumber(b.views);
          return viewsB - viewsA; // highest views first
        });
        break;
      case "oldest":
        sortedVideos.sort((a, b) => {
          const dateA = new Date(a.publishedAtRaw || a.publishedAt).getTime();
          const dateB = new Date(b.publishedAtRaw || b.publishedAt).getTime();
          return dateA - dateB; // oldest first
        });
        break;
    }
    
    return sortedVideos;
  }, [searchQuery, activeFilter]);



  // Get filtered results for different content types
  const filteredVideos = filterVideos(allVideos, "videos");
  const filteredShorts = filterVideos(allVideos, "shorts");





  // Show connect channel view if no channel is connected
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

  if (!isChannelConnected) {
    return (
      <div className="space-y-2.5">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-2xl font-bold text-foreground">Video Review</h2>
            <p className="text-muted-foreground">Connect your YouTube channel to review your videos</p>
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
                Connect your YouTube channel to review and optimize your video content with AI-powered insights.
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
    <div className="space-y-2.5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-foreground">Video Review</h2>
          <p className="text-muted-foreground mb-4">Review your videos for content optimization and performance insights</p>
          {/* Connection details removed per request */}
        </div>
        <div className="flex gap-2">
          <Button 
            variant="outline" 
            size="icon"
            onClick={refreshVideosFromYouTube}
            disabled={isLoadingAnalytics}
            title="Refresh Videos from YouTube"
          >
            <RefreshCw className={`h-4 w-4 ${isLoadingAnalytics ? 'animate-spin' : ''}`} />
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

      {/* Search Bar */}
      <div className="w-full">
        <div className="relative">
          <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-muted-foreground h-4 w-4" />
          <Input
            placeholder="Search content..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="pl-10 w-full"
          />
        </div>
      </div>

      {/* Main Navigation Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full">
        <div className="space-y-4">
          {/* Tab Navigation */}
          <div className="border-b border-border">
                         <TabsList className="h-auto bg-transparent border-none p-0 rounded-none justify-start w-auto gap-6">
               <TabsTrigger 
                 value="videos"
                 className="rounded-none border-b-2 border-transparent data-[state=active]:border-foreground data-[state=active]:bg-transparent data-[state=active]:shadow-none data-[state=active]:text-foreground px-0 py-3"
               >
                 Videos
               </TabsTrigger>
               <TabsTrigger 
                 value="shorts"
                 className="rounded-none border-b-2 border-transparent data-[state=active]:border-foreground data-[state=active]:bg-transparent data-[state=active]:shadow-none data-[state=active]:text-foreground px-0 py-3"
               >
                 Shorts
               </TabsTrigger>
             </TabsList>
          </div>
          
          {/* Filter Pills */}
          <div className="flex gap-2 pb-2">
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setActiveFilter("latest")}
              className={`h-8 text-xs rounded-lg px-3 py-1 ${activeFilter === "latest" ? "bg-black text-white hover:bg-black hover:text-white" : "bg-[#0000000d] text-foreground hover:bg-[#0000000d]"}`}
            >
              Latest
            </Button>
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setActiveFilter("popular")}
              className={`h-8 text-xs rounded-lg px-3 py-1 ${activeFilter === "popular" ? "bg-black text-white hover:bg-black hover:text-white" : "bg-[#0000000d] text-foreground hover:bg-[#0000000d]"}`}
            >
              Popular
            </Button>
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setActiveFilter("oldest")}
              className={`h-8 text-xs rounded-lg px-3 py-1 ${activeFilter === "oldest" ? "bg-black text-white hover:bg-black hover:text-white" : "bg-[#0000000d] text-foreground hover:bg-[#0000000d]"}`}
            >
              Oldest
            </Button>
          </div>
        </div>

        {/* Tab Content */}

        <TabsContent value="videos" className="space-y-4 mt-2.5">
          {filteredVideos && filteredVideos.length > 0 ? (
            <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-6">
              {filteredVideos.map((video, index) => (
                <Card key={video.id || index} className={`overflow-hidden hover:shadow-lg transition-shadow relative ${reviewingVideos.has(video.databaseId?.toString() || video.id || `temp-${index}`) ? 'opacity-75' : ''}`}>
                  {reviewingVideos.has(video.databaseId?.toString() || video.id || `temp-${index}`) && (
                    <div className="absolute inset-0 bg-black/20 flex items-center justify-center z-10">
                      <div className="bg-white rounded-full p-3 shadow-lg">
                        <div className="h-6 w-6 animate-spin rounded-full border-2 border-primary border-t-transparent" />
                      </div>
                    </div>
                  )}
                  <div className="aspect-video relative">
                    {video.thumbnail && video.thumbnail !== '🎥' ? (
                      <img 
                        src={video.thumbnail} 
                        alt={video.title}
                        className="w-full h-full object-cover"
                      />
                    ) : (
                      <div className="w-full h-full bg-gray-200 flex items-center justify-center text-3xl">
                        🎥
                      </div>
                    )}
                    {/* Score or Not Reviewed Badge */}
                    <div className="absolute top-2 left-2">
                      {hasCompletedScores(video) ? (
                        <div className="bg-white rounded-full px-[8px] text-xs font-semibold shadow-md flex items-center gap-1 h-[34px]">
                          <img 
                            src={getScoreIcon(getOverallAvgScore(video) || 0)} 
                            alt="score" 
                            className="w-[18px] h-[18px]"
                          />
                          <span className="text-sm"> {getOverallAvgScore(video)?.toFixed(1)}<span style={{ color: "#949494" }}>/10</span></span>
                        </div>
                      ) : (
                        <div className="backdrop-blur-sm rounded-full px-[8px] text-xs font-medium text-white shadow-sm h-[34px] flex items-center opacity-90" style={{ background: 'rgba(0, 0, 0, 0.70)' }}>
                          Not reviewed
                        </div>
                      )}
                    </div>
                  </div>
                  <CardContent className="p-2">
                    {video.id ? (
                      <a 
                        href={`https://youtube.com/watch?v=${video.id}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="block"
                      >
                        <h4 className="font-medium text-sm leading-[1.2] text-foreground line-clamp-2 hover:text-blue-600 transition-colors cursor-pointer mb-2">
                          {video.title}
                        </h4>
                      </a>
                    ) : (
                      <h4 className="font-medium text-sm leading-[1.2] text-foreground line-clamp-2 mb-2">{video.title}</h4>
                    )}
                    <>
                      <Button 
                        onClick={() => handleReviewVideo(video)}
                        disabled={reviewingVideos.has(video.databaseId?.toString() || video.id || `temp-${index}`) || !video.databaseId}
                        variant={hasCompletedScores(video) ? "secondary" : "default"}
                        className={hasCompletedScores(video) ? "w-full" : "w-full bg-gradient-primary text-white border-transparent"}
                        size="sm"
                      >
                        {reviewingVideos.has(video.databaseId?.toString() || video.id || `temp-${index}`) ? (
                          <>
                            <div className="mr-2 h-3 w-3 animate-spin rounded-full border-2 border-background border-t-transparent" />
                            {hasCompletedScores(video) ? 'Optimising...' : 'Reviewing...'}
                          </>
                        ) : (
                          <>
                            {hasCompletedScores(video) ? 'Optimise' : 'Review video'}
                          </>
                        )}
                      </Button>
                    </>
                  </CardContent>
                </Card>
              ))}
            </div>
          ) : (
            <div className="flex items-center justify-center h-32 text-muted-foreground">
              <div className="text-center">
                <Play className="w-8 h-8 mx-auto mb-2 opacity-50" />
                <p>{searchQuery ? 'No videos found for your search' : 'No regular videos available'}</p>
                <p className="text-xs">{searchQuery ? 'Try adjusting your search terms' : 'Only short-form content (Shorts) found'}</p>
              </div>
            </div>
          )}
        </TabsContent>

        <TabsContent value="shorts" className="space-y-4 mt-2.5">
              {filteredShorts && filteredShorts.length > 0 ? (
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-6">
                  {filteredShorts.map((video, index) => (
                    <Card key={video.id || index} className={`overflow-hidden hover:shadow-lg transition-shadow relative ${reviewingVideos.has(video.databaseId?.toString() || video.id || `temp-${index}`) ? 'opacity-75' : ''}`}>
                      {reviewingVideos.has(video.databaseId?.toString() || video.id || `temp-${index}`) && (
                        <div className="absolute inset-0 bg-black/20 flex items-center justify-center z-10">
                          <div className="bg-white rounded-full p-3 shadow-lg">
                            <div className="h-6 w-6 animate-spin rounded-full border-2 border-primary border-t-transparent" />
                          </div>
                        </div>
                      )}
                      <div className="h-[300px] overflow-hidden flex items-center justify-center bg-black/5 relative">
                        {video.thumbnail && video.thumbnail !== '🎥' ? (
                          <img 
                            src={video.thumbnail} 
                            alt={video.title}
                            className="w-full h-full object-cover"
                          />
                        ) : (
                          <div className="w-full h-full bg-gray-200 flex items-center justify-center text-2xl">
                            🎬
                          </div>
                        )}
                        {/* Score or Not Reviewed Badge */}
                        <div className="absolute top-2 left-2">
                          {hasCompletedScores(video) ? (
                            <div className="bg-white rounded-full px-[8px] text-xs font-bold shadow-sm flex items-center gap-1 h-[34px]">
                              <img 
                                src={getScoreIcon(getOverallAvgScore(video) || 0)} 
                                alt="score" 
                                className="w-[18px] h-[18px]"
                              />
                              <span>{getOverallAvgScore(video)?.toFixed(1)}/10</span>
                            </div>
                          ) : (
                            <div className="backdrop-blur-sm rounded-full px-[8px] text-xs font-medium text-white shadow-sm h-[34px] flex items-center opacity-90" style={{ background: 'rgba(0, 0, 0, 0.70)' }}>
                              Not reviewed
                            </div>
                          )}
                        </div>
                      </div>
                      <CardContent className="p-3">
                        {video.id ? (
                          <a 
                            href={`https://youtube.com/watch?v=${video.id}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="block"
                          >
                            <h4 className="font-medium text-sm leading-[1.2] text-foreground line-clamp-2 hover:text-blue-600 transition-colors cursor-pointer mb-2">
                              {video.title}
                            </h4>
                          </a>
                        ) : (
                          <h4 className="font-medium text-sm leading-[1.2] text-foreground line-clamp-2 mb-2">{video.title}</h4>
                        )}
                        <>
                          <Button 
                            onClick={() => handleReviewVideo(video)}
                            disabled={reviewingVideos.has(video.databaseId?.toString() || video.id || `temp-${index}`) || !video.databaseId}
                            variant={hasCompletedScores(video) ? "secondary" : "default"}
                            className= {hasCompletedScores(video) ? "w-full" : "w-full bg-gradient-primary text-white border-transparent"}
                            size="sm"
                          >
                            {reviewingVideos.has(video.databaseId?.toString() || video.id || `temp-${index}`) ? (
                              <>
                                <div className="mr-1 h-3 w-3 animate-spin rounded-full border-2 border-background border-t-transparent" />
                                {hasCompletedScores(video) ? 'Optimising...' : 'Reviewing...'}
                              </>
                            ) : (
                              <>
                                  {hasCompletedScores(video) ? 'Optimise' : 'Review video'}
                              </>
                            )}
                          </Button>
                        </>
                      </CardContent>
                    </Card>
                  ))}
                </div>
              ) : (
                <div className="flex items-center justify-center h-32 text-muted-foreground">
                  <div className="text-center">
                    <Play className="w-8 h-8 mx-auto mb-2 opacity-50" />
                    <p>{searchQuery ? 'No shorts found for your search' : 'No shorts available'}</p>
                    <p className="text-xs">{searchQuery ? 'Try adjusting your search terms' : 'Only long-form videos found'}</p>
                  </div>
                </div>
              )}
        </TabsContent>


      </Tabs>

      <PrivacyConsentDialog 
        open={showPrivacyDialog}
        onOpenChange={setShowPrivacyDialog}
        onAccept={handlePrivacyAccepted}
      />
    </div>
  );
};

export default Review;
