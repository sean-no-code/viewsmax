// YouTube OAuth and Analytics API integration
interface YouTubeChannelData {
  channelId: string;
  channelName: string;
  subscribers: string;
  totalViews: string;
  profileImageUrl?: string;
}

interface YouTubePlaylist {
  id: string;
  title: string;
  description: string;
  thumbnail: string;
  itemCount: number;
  privacy: string;
  publishedAt: string;
}

interface YouTubeVideo {
  id: string;
  title: string;
  description: string;
  thumbnail: string;
  views: string;
  likes: string;
  duration: string;
  durationRaw: string; // ISO 8601 duration for parsing (e.g., PT1M30S)
  publishedAt: string;
  publishedAtRaw: string; // ISO date for sorting
  tags: string[];
}

interface YouTubeAnalytics {
  views: number;
  subscribers: number;
  videosPublished: number;
  avgWatchTime: string;
  topVideos: YouTubeVideo[];
  playlists: YouTubePlaylist[];
  recentVideos: YouTubeVideo[];
  channelStats: {
    totalViews: string;
    subscriberCount: string;
    videoCount: string;
    viewCount: string;
    customUrl?: string;
    description: string;
    publishedAt: string;
    country?: string;
  };
  // YouTube Analytics API data
  viewsOverTime?: ViewsTimeSeriesData[];
  audienceDemographics?: AudienceDemographics;
  watchTimeAnalytics?: WatchTimeAnalytics;
  eligibilityInfo?: {
    eligible: boolean;
    reason?: string;
  };
}

interface ViewsTimeSeriesData {
  date: string;
  views: number;
  estimatedMinutesWatched: number;
  averageViewDuration: number;
}

interface AudienceDemographics {
  ageGroups: {
    age13_17: number;
    age18_24: number;
    age25_34: number;
    age35_44: number;
    age45_54: number;
    age55_64: number;
    age65_: number;
  };
  gender: {
    male: number;
    female: number;
    other: number;
  };
  topCountries: {
    country: string;
    percentage: number;
    views: number;
  }[];
  topCities: {
    city: string;
    country: string;
    percentage: number;
  }[];
}

interface WatchTimeAnalytics {
  averageViewDuration: string;
  totalWatchTimeHours: number;
  audienceRetention: {
    relative: number[];
    absolute: number[];
  };
}



interface YouTubeApiPlaylistItem {
  id: string;
  snippet: {
    title: string;
    description: string;
    publishedAt: string;
    thumbnails?: {
      default?: { url: string };
      medium?: { url: string };
      high?: { url: string };
    };
  };
  status: {
    privacyStatus: string;
  };
  contentDetails: {
    itemCount: number;
  };
}

interface YouTubeApiVideoItem {
  id: string;
  snippet: {
    title: string;
    description: string;
    publishedAt: string;
    tags?: string[];
    thumbnails?: {
      default?: { url: string };
      medium?: { url: string };
      high?: { url: string };
    };
  };
  statistics: {
    viewCount?: string;
    likeCount?: string;
  };
  contentDetails: {
    duration: string;
  };
}

class YouTubeAuthService {
  private readonly API_KEY = import.meta.env.VITE_YOUTUBE_API_KEY;
  private readonly CLIENT_ID = import.meta.env.VITE_GOOGLE_CLIENT_ID;
  private readonly REDIRECT_URI = `${window.location.origin}/oauth/callback`;
  private readonly SCOPES = [
    'https://www.googleapis.com/auth/youtube.readonly',
    'https://www.googleapis.com/auth/yt-analytics.readonly',
    'https://www.googleapis.com/auth/userinfo.profile'
  ].join(' ');


  // Real OAuth flow to connect user's actual YouTube channel
  async connectUserChannel(): Promise<YouTubeChannelData> {

    try {
      console.log('🔐 Starting Google OAuth flow for YouTube channel...');

      // Clear any existing auth state to ensure fresh login
      this.clearStoredAuth();

      // Build OAuth URL and open popup
      const authUrl = this.buildOAuthUrl();
      console.log('🌐 Opening Google OAuth popup...');

      const popup = window.open(
        authUrl,
        'youtube-oauth',
        'width=500,height=700,scrollbars=yes,resizable=yes,location=yes'
      );

      if (!popup) {
        throw new Error('Popup blocked. Please allow popups for this site and try again.');
      }

      // Wait for OAuth completion
      const authResult = await this.waitForOAuthCallback(popup);
      console.log('✅ OAuth completed successfully!');

      // Exchange code for tokens
      const tokens = await this.exchangeCodeForTokens(authResult.code);

      // Get user's channel data with comprehensive information
      const channelData = await this.getUserChannelData(tokens.access_token);

      // Store tokens and channel data
      localStorage.setItem('youtube_access_token', tokens.access_token);
      localStorage.setItem('youtube_refresh_token', tokens.refresh_token || '');
      localStorage.setItem('youtube_channel_data', JSON.stringify(channelData));
      localStorage.setItem('youtube_auth_type', 'real');

      console.log('🎉 Successfully connected YouTube channel:', channelData.channelName);
      return channelData;

    } catch (error) {
      console.error('❌ OAuth connection failed:', error);
      throw error;
    }
  }

  // Build OAuth URL
  private buildOAuthUrl(): string {
    const clientId = this.CLIENT_ID || 'YOUR_CLIENT_ID_HERE';

    const params = new URLSearchParams({
      client_id: clientId,
      redirect_uri: this.REDIRECT_URI,
      scope: this.SCOPES,
      response_type: 'code',
      access_type: 'offline',
      prompt: 'select_account consent', // Force account picker and fresh consent
      state: `youtube_auth_${Date.now()}`, // Add state for security
      include_granted_scopes: 'true'
    });

    return `https://accounts.google.com/o/oauth2/v2/auth?${params.toString()}`;
  }

  // Wait for OAuth callback
  private waitForOAuthCallback(popup: Window): Promise<{ code: string }> {
    return new Promise((resolve, reject) => {
      const checkClosed = setInterval(() => {
        if (popup.closed) {
          clearInterval(checkClosed);
          reject(new Error('OAuth window was closed by user'));
        }
      }, 1000);

      // Listen for OAuth callback message
      const messageHandler = (event: MessageEvent) => {
        if (event.origin !== window.location.origin) return;

        if (event.data.type === 'YOUTUBE_OAUTH_SUCCESS') {
          clearInterval(checkClosed);
          window.removeEventListener('message', messageHandler);
          popup.close();
          resolve({ code: event.data.code });
        } else if (event.data.type === 'YOUTUBE_OAUTH_ERROR') {
          clearInterval(checkClosed);
          window.removeEventListener('message', messageHandler);
          popup.close();
          reject(new Error(event.data.error));
        }
      };

      window.addEventListener('message', messageHandler);
    });
  }

  // Exchange authorization code for access tokens using viewsmax.ai backend
  private async exchangeCodeForTokens(code: string): Promise<{ access_token: string; refresh_token?: string }> {
    console.log('🔄 Exchanging authorization code for tokens via viewsmax.ai backend...');

    try {
      // Import the API service dynamically to avoid circular dependencies
      const { viewsMaxApi } = await import('@/lib/api-service');

      // Get auth token from session
      const storedSession = localStorage.getItem('auth_session');
      let authToken = null;
      if (storedSession) {
        try {
          const session = JSON.parse(storedSession);
          authToken = session.token;
        } catch (error) {
          console.error('Error parsing auth session:', error);
        }
      }

      if (!authToken) {
        throw new Error('No authentication token found. Please log in first.');
      }

      const result = await viewsMaxApi.exchangeYouTubeCode(code, this.REDIRECT_URI, authToken);

      if (!result.success) {
        console.error('Token exchange failed:', result.error);
        throw new Error(result.error || 'Token exchange failed');
      }

      console.log('✅ Tokens received successfully');

      return {
        access_token: result.data!.access_token,
        refresh_token: result.data!.refresh_token
      };
    } catch (error) {
      console.error('Error exchanging tokens:', error);
      throw error; // Re-throw instead of using demo token
    }
  }

  // Get authenticated user's comprehensive channel data
  private async getUserChannelData(accessToken: string): Promise<YouTubeChannelData> {
    try {
      console.log('📺 Fetching comprehensive user channel data...');

      const response = await fetch(
        `https://www.googleapis.com/youtube/v3/channels?part=snippet,statistics,brandingSettings,status&mine=true&key=${this.API_KEY}`,
        {
          headers: {
            'Authorization': `Bearer ${accessToken}`,
            'Accept': 'application/json',
          },
        }
      );

      if (!response.ok) {
        const errorText = await response.text();
        console.error('Channel data fetch failed:', response.status, errorText);
        throw new Error(`Failed to fetch channel data: ${response.status} ${response.statusText}`);
      }

      const data = await response.json();

      if (!data.items || data.items.length === 0) {
        throw new Error('No YouTube channel found for this Google account. Please ensure you have a YouTube channel.');
      }

      const channel = data.items[0];
      console.log('✅ Channel data retrieved successfully:', channel.snippet.title);

      return {
        channelId: channel.id,
        channelName: channel.snippet.title,
        subscribers: this.formatNumber(parseInt(channel.statistics.subscriberCount || '0')),
        totalViews: this.formatNumber(parseInt(channel.statistics.viewCount || '0')),
        profileImageUrl: channel.snippet.thumbnails?.high?.url || channel.snippet.thumbnails?.default?.url,
      };
    } catch (error) {
      console.error('Error fetching user channel data:', error);
      throw error;
    }
  }

  // Check if user has real OAuth authentication
  isRealAuth(): boolean {
    return localStorage.getItem('youtube_auth_type') === 'real';
  }

  // Get authentication type
  getAuthType(): 'real' | null {
    return localStorage.getItem('youtube_auth_type') as 'real' | null;
  }

  // Load Google API library
  private async loadGoogleAPI(): Promise<void> {
    return new Promise((resolve, reject) => {
      if ('gapi' in window && window.gapi) {
        resolve();
        return;
      }

      const script = document.createElement('script');
      script.src = 'https://apis.google.com/js/api.js';
      script.onload = () => {
        if ('gapi' in window && window.gapi) {
          (window.gapi as { load: (api: string, callback: () => void) => void }).load('auth2', resolve);
        } else {
          reject(new Error('Google API failed to load'));
        }
      };
      script.onerror = reject;
      document.head.appendChild(script);
    });
  }

  // Authenticate with Google OAuth
  private async authenticateWithGoogle(): Promise<{ access_token: string; refresh_token?: string }> {
    const authUrl = `https://accounts.google.com/oauth/authorize?` +
      `client_id=${this.CLIENT_ID}&` +
      `redirect_uri=${encodeURIComponent(this.REDIRECT_URI)}&` +
      `scope=${encodeURIComponent(this.SCOPES)}&` +
      `response_type=code&` +
      `access_type=offline&` +
      `prompt=consent`;

    // Open OAuth popup
    const popup = window.open(authUrl, 'youtube-auth', 'width=500,height=600');

    return new Promise((resolve, reject) => {
      const checkClosed = setInterval(() => {
        if (popup?.closed) {
          clearInterval(checkClosed);
          reject(new Error('Authentication cancelled'));
        }
      }, 1000);

      // Listen for the OAuth callback
      window.addEventListener('message', (event) => {
        if (event.origin !== window.location.origin) return;

        if (event.data.type === 'YOUTUBE_AUTH_SUCCESS') {
          clearInterval(checkClosed);
          popup?.close();
          resolve(event.data.tokens);
        } else if (event.data.type === 'YOUTUBE_AUTH_ERROR') {
          clearInterval(checkClosed);
          popup?.close();
          reject(new Error(event.data.error));
        }
      });
    });
  }

  // Get channel information from YouTube API using API key
  private async getChannelInfoWithApiKey(accessToken: string): Promise<YouTubeChannelData> {
    try {
      // First, try to get the authenticated user's channel
      const response = await fetch(
        `https://www.googleapis.com/youtube/v3/channels?part=snippet,statistics&mine=true&key=${this.API_KEY}`,
        {
          headers: {
            'Authorization': `Bearer ${accessToken}`,
            'Accept': 'application/json',
          },
        }
      );

      if (!response.ok) {
        throw new Error(`Failed to fetch channel data: ${response.status} ${response.statusText}`);
      }

      const data = await response.json();

      if (!data.items || data.items.length === 0) {
        throw new Error('No channel found for authenticated user');
      }

      const channel = data.items[0];

      return {
        channelId: channel.id,
        channelName: channel.snippet.title,
        subscribers: this.formatNumber(parseInt(channel.statistics.subscriberCount)),
        totalViews: this.formatNumber(parseInt(channel.statistics.viewCount)),
        profileImageUrl: channel.snippet.thumbnails?.default?.url,
      };
    } catch (error) {
      console.error('Error fetching channel info:', error);
      throw error;
    }
  }

  // Test with a public channel to verify API key works
  async testApiKey(): Promise<boolean> {
    try {
      console.log('🔍 Testing API key:', this.API_KEY.substring(0, 15) + '...');

      // Test with a well-known public channel (YouTube's own channel)
      const testUrl = `https://www.googleapis.com/youtube/v3/channels?part=snippet&id=UCBR8-60-B28hp2BmDPdntcQ&key=${this.API_KEY}`;
      console.log('📡 Making request to:', testUrl.replace(this.API_KEY, 'API_KEY_HIDDEN'));

      const response = await fetch(testUrl);

      console.log('📊 Response status:', response.status, response.statusText);

      if (!response.ok) {
        const errorText = await response.text();
        console.error('❌ API key test failed with response:', errorText);

        // Parse the error response to get more details
        try {
          const errorData = JSON.parse(errorText);
          console.error('🔍 Error details:', errorData);

          if (errorData.error) {
            console.error('📋 Error message:', errorData.error.message);
            console.error('📋 Error reason:', errorData.error.errors?.[0]?.reason);
          }
        } catch (parseError) {
          console.error('❌ Could not parse error response');
        }

        // Common error cases
        if (response.status === 403) {
          console.error('🚫 403 Forbidden - Possible causes:');
          console.error('   • API key is invalid');
          console.error('   • YouTube Data API v3 is not enabled');
          console.error('   • Quota exceeded');
          console.error('   • IP restrictions on API key');
        } else if (response.status === 400) {
          console.error('🚫 400 Bad Request - Check API parameters');
        }

        return false;
      }

      const data = await response.json();
      console.log('✅ API key test successful!');
      console.log('📺 Channel found:', data.items?.[0]?.snippet?.title || 'Unknown');
      console.log('📋 Full response:', data);
      return true;
    } catch (error) {
      console.error('❌ API key test error:', error);
      if (error.message.includes('CORS')) {
        console.error('🌐 CORS Error detected - this might be a browser restriction');
      } else if (error.message.includes('Failed to fetch')) {
        console.error('🌐 Network error - check internet connection');
      }
      return false;
    }
  }

  // Validate current access token
  async validateAccessToken(accessToken: string): Promise<boolean> {
    try {
      const response = await fetch(
        `https://www.googleapis.com/oauth2/v1/tokeninfo?access_token=${accessToken}`
      );

      if (!response.ok) {
        return false;
      }

      const tokenInfo = await response.json();

      // Check if token has required scopes
      const hasYouTubeScope = tokenInfo.scope?.includes('youtube') || false;
      const notExpired = parseInt(tokenInfo.expires_in) > 300; // At least 5 minutes left

      return hasYouTubeScope && notExpired;
    } catch (error) {
      console.error('Error validating access token:', error);
      return false;
    }
  }

  // Fetch comprehensive YouTube Analytics data
  async fetchAnalyticsData(accessToken: string, channelId: string): Promise<YouTubeAnalytics> {
    try {
      console.log('🔄 Fetching comprehensive analytics data for channel:', channelId);

      // Fetch basic data and analytics data in parallel
      const [channelData, playlistsData, videosData, analyticsData] = await Promise.all([
        this.fetchChannelStats(accessToken, channelId),
        this.fetchChannelPlaylists(accessToken, channelId),
        this.fetchChannelVideos(accessToken, channelId),
        this.fetchYouTubeAnalyticsData(accessToken, channelId)
      ]);

      return {
        views: parseInt(channelData.statistics?.viewCount || '0'),
        subscribers: parseInt(channelData.statistics?.subscriberCount || '0'),
        videosPublished: parseInt(channelData.statistics?.videoCount || '0'),
        avgWatchTime: analyticsData.watchTimeAnalytics?.averageViewDuration || (parseInt(channelData.statistics?.videoCount || '0') === 0 ? '0:00' : 'N/A'),
        topVideos: videosData.topVideos,
        recentVideos: videosData.recentVideos,
        playlists: playlistsData,
        channelStats: {
          totalViews: this.formatNumber(parseInt(channelData.statistics?.viewCount || '0')),
          subscriberCount: this.formatNumber(parseInt(channelData.statistics?.subscriberCount || '0')),
          videoCount: channelData.statistics?.videoCount || '0',
          viewCount: channelData.statistics?.viewCount || '0',
          customUrl: channelData.snippet?.customUrl,
          description: channelData.snippet?.description || '',
          publishedAt: channelData.snippet?.publishedAt || '',
          country: channelData.snippet?.country
        },
        // Add YouTube Analytics API data
        viewsOverTime: analyticsData.viewsOverTime,
        audienceDemographics: analyticsData.audienceDemographics,
        watchTimeAnalytics: analyticsData.watchTimeAnalytics,
        eligibilityInfo: analyticsData.eligibilityInfo
      };
    } catch (error) {
      console.error('Error fetching comprehensive analytics data:', error);
      throw error;
    }
  }

  // Fetch YouTube Analytics API data for advanced analytics
  private async fetchYouTubeAnalyticsData(accessToken: string, channelId: string): Promise<{
    viewsOverTime?: ViewsTimeSeriesData[];
    audienceDemographics?: AudienceDemographics;
    watchTimeAnalytics?: WatchTimeAnalytics;
    eligibilityInfo?: {
      eligible: boolean;
      reason?: string;
    };
  }> {
    try {
      console.log('🔄 Fetching YouTube Analytics API data...');
      console.log('📋 Channel ID:', channelId);

      // Calculate date range (last 30 days)
      const endDate = new Date().toISOString().split('T')[0]; // YYYY-MM-DD
      const startDate = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
      console.log('📅 Date range:', startDate, 'to', endDate);

      // First, check if the channel is eligible for Analytics API
      const eligibilityCheck = await this.checkAnalyticsEligibility(accessToken, channelId);
      console.log('🔍 Analytics eligibility:', eligibilityCheck);

      if (!eligibilityCheck.eligible) {
        console.log('📊 Channel not eligible for Analytics API');
        return {
          viewsOverTime: undefined,
          audienceDemographics: undefined,
          watchTimeAnalytics: undefined,
          eligibilityInfo: {
            eligible: false,
            reason: eligibilityCheck.reason || 'Channel not eligible for YouTube Analytics API'
          }
        };
      }

      // Fetch analytics data in parallel
      const [viewsTimeSeriesData, demographicsData, watchTimeData] = await Promise.all([
        this.fetchViewsOverTime(accessToken, channelId, startDate, endDate),
        this.fetchAudienceDemographics(accessToken, channelId, startDate, endDate),
        this.fetchWatchTimeAnalytics(accessToken, channelId, startDate, endDate)
      ]);

      return {
        viewsOverTime: viewsTimeSeriesData,
        audienceDemographics: demographicsData,
        watchTimeAnalytics: watchTimeData
      };

    } catch (error) {
      console.warn('⚠️ YouTube Analytics API error:', error);

      // Return information about why analytics data is not available
      return {
        viewsOverTime: undefined,
        audienceDemographics: undefined,
        watchTimeAnalytics: undefined,
        eligibilityInfo: {
          eligible: false,
          reason: `API Error: ${error.message}`
        }
      };
    }
  }

  // Check if channel is eligible for YouTube Analytics API
  private async checkAnalyticsEligibility(accessToken: string, channelId: string): Promise<{
    eligible: boolean;
    reason?: string;
  }> {
    try {
      // Try a simple analytics query to test access
      const testParams = new URLSearchParams({
        'ids': `channel==${channelId}`,
        'startDate': '2024-01-01',
        'endDate': '2024-01-01',
        'metrics': 'views'
      });

      const response = await fetch(
        `https://youtubeanalytics.googleapis.com/v2/reports?${testParams}`,
        {
          headers: {
            'Authorization': `Bearer ${accessToken}`,
            'Accept': 'application/json'
          }
        }
      );

      if (response.ok) {
        return { eligible: true };
      } else {
        const errorText = await response.text();
        console.log('Analytics API test response:', response.status, errorText);
        return {
          eligible: false,
          reason: response.status === 403 ?
            'YouTube Analytics API access not available (Status 403). This requires channel monetization and sufficient analytics history.' :
            `API returned ${response.status}: ${errorText}`
        };
      }
    } catch (error) {
      return {
        eligible: false,
        reason: `Network error: ${error.message}`
      };
    }
  }



  // Fetch views over time data
  private async fetchViewsOverTime(accessToken: string, channelId: string, startDate: string, endDate: string): Promise<ViewsTimeSeriesData[]> {
    const params = new URLSearchParams({
      'ids': `channel==${channelId}`,
      'startDate': startDate,
      'endDate': endDate,
      'metrics': 'views,estimatedMinutesWatched,averageViewDuration',
      'dimensions': 'day',
      'sort': 'day'
    });

    const response = await fetch(
      `https://youtubeanalytics.googleapis.com/v2/reports?${params}`,
      {
        headers: {
          'Authorization': `Bearer ${accessToken}`,
          'Accept': 'application/json'
        }
      }
    );

    if (!response.ok) {
      throw new Error(`YouTube Analytics API error: ${response.status} - ${response.statusText}`);
    }

    const data = await response.json();

    if (!data.rows || data.rows.length === 0) {
      return [];
    }

    return data.rows.map((row: (string | number)[]) => ({
      date: row[0] as string, // day dimension
      views: (row[1] as number) || 0,
      estimatedMinutesWatched: (row[2] as number) || 0,
      averageViewDuration: (row[3] as number) || 0
    }));
  }

  // Fetch audience demographics
  private async fetchAudienceDemographics(accessToken: string, channelId: string, startDate: string, endDate: string): Promise<AudienceDemographics> {
    // Fetch age and gender demographics
    const ageGenderParams = new URLSearchParams({
      'ids': `channel==${channelId}`,
      'startDate': startDate,
      'endDate': endDate,
      'metrics': 'viewerPercentage',
      'dimensions': 'ageGroup,gender',
      'sort': '-viewerPercentage'
    });

    // Fetch geography data
    const geoParams = new URLSearchParams({
      'ids': `channel==${channelId}`,
      'startDate': startDate,
      'endDate': endDate,
      'metrics': 'views,viewerPercentage',
      'dimensions': 'country',
      'sort': '-views',
      'maxResults': '10'
    });

    const [ageGenderResponse, geoResponse] = await Promise.all([
      fetch(`https://youtubeanalytics.googleapis.com/v2/reports?${ageGenderParams}`, {
        headers: {
          'Authorization': `Bearer ${accessToken}`,
          'Accept': 'application/json'
        }
      }),
      fetch(`https://youtubeanalytics.googleapis.com/v2/reports?${geoParams}`, {
        headers: {
          'Authorization': `Bearer ${accessToken}`,
          'Accept': 'application/json'
        }
      })
    ]);

    if (!ageGenderResponse.ok || !geoResponse.ok) {
      throw new Error('Failed to fetch demographics data');
    }

    const ageGenderData = await ageGenderResponse.json();
    const geoData = await geoResponse.json();

    // Process age and gender data
    const ageGroups = {
      age13_17: 0,
      age18_24: 0,
      age25_34: 0,
      age35_44: 0,
      age45_54: 0,
      age55_64: 0,
      age65_: 0
    };

    const gender = {
      male: 0,
      female: 0,
      other: 0
    };

    if (ageGenderData.rows) {
      ageGenderData.rows.forEach((row: (string | number)[]) => {
        const ageGroup = row[0] as string;
        const genderValue = row[1] as string;
        const percentage = (row[2] as number) || 0;

        // Map age groups
        if (ageGroup === 'age13-17') ageGroups.age13_17 = percentage;
        else if (ageGroup === 'age18-24') ageGroups.age18_24 = percentage;
        else if (ageGroup === 'age25-34') ageGroups.age25_34 = percentage;
        else if (ageGroup === 'age35-44') ageGroups.age35_44 = percentage;
        else if (ageGroup === 'age45-54') ageGroups.age45_54 = percentage;
        else if (ageGroup === 'age55-64') ageGroups.age55_64 = percentage;
        else if (ageGroup === 'age65-') ageGroups.age65_ = percentage;

        // Map gender
        if (genderValue === 'male') gender.male += percentage;
        else if (genderValue === 'female') gender.female += percentage;
        else gender.other += percentage;
      });
    }

    // Process geography data
    const topCountries = geoData.rows ? geoData.rows.slice(0, 10).map((row: (string | number)[]) => ({
      country: row[0] as string,
      views: (row[1] as number) || 0,
      percentage: (row[2] as number) || 0
    })) : [];

    return {
      ageGroups,
      gender,
      topCountries,
      topCities: [] // Cities data requires a separate API call
    };
  }

  // Fetch watch time analytics
  private async fetchWatchTimeAnalytics(accessToken: string, channelId: string, startDate: string, endDate: string): Promise<WatchTimeAnalytics> {
    const params = new URLSearchParams({
      'ids': `channel==${channelId}`,
      'startDate': startDate,
      'endDate': endDate,
      'metrics': 'estimatedMinutesWatched,averageViewDuration'
    });

    const response = await fetch(
      `https://youtubeanalytics.googleapis.com/v2/reports?${params}`,
      {
        headers: {
          'Authorization': `Bearer ${accessToken}`,
          'Accept': 'application/json'
        }
      }
    );

    if (!response.ok) {
      throw new Error('Failed to fetch watch time analytics');
    }

    const data = await response.json();

    if (!data.rows || data.rows.length === 0) {
      return {
        averageViewDuration: '0:00',
        totalWatchTimeHours: 0,
        audienceRetention: {
          relative: [],
          absolute: []
        }
      };
    }

    const row = data.rows[0];
    const totalMinutesWatched = row[0] || 0;
    const avgViewDurationSeconds = row[1] || 0;

    // Convert seconds to MM:SS format
    const minutes = Math.floor(avgViewDurationSeconds / 60);
    const seconds = Math.floor(avgViewDurationSeconds % 60);
    const avgViewDurationFormatted = `${minutes}:${seconds.toString().padStart(2, '0')}`;

    return {
      averageViewDuration: avgViewDurationFormatted,
      totalWatchTimeHours: Math.round(totalMinutesWatched / 60),
      audienceRetention: {
        relative: [], // Would require video-specific retention data
        absolute: []
      }
    };
  }

  // Fetch detailed channel statistics
  private async fetchChannelStats(accessToken: string, channelId: string) {
    const response = await fetch(
      `https://www.googleapis.com/youtube/v3/channels?part=statistics,snippet&id=${channelId}&key=${this.API_KEY}`,
      {
        headers: {
          'Authorization': `Bearer ${accessToken}`,
          'Accept': 'application/json',
        },
      }
    );

    if (!response.ok) {
      throw new Error(`Failed to fetch channel statistics: ${response.status}`);
    }

    const data = await response.json();
    return data.items[0];
  }

  // Fetch channel playlists
  private async fetchChannelPlaylists(accessToken: string, channelId: string): Promise<YouTubePlaylist[]> {
    try {
      const response = await fetch(
        `https://www.googleapis.com/youtube/v3/playlists?part=snippet,status,contentDetails&channelId=${channelId}&maxResults=50&key=${this.API_KEY}`,
        {
          headers: {
            'Authorization': `Bearer ${accessToken}`,
            'Accept': 'application/json',
          },
        }
      );

      if (!response.ok) {
        console.warn('Failed to fetch playlists:', response.status);
        return [];
      }

      const data = await response.json();

      return data.items.map((playlist: YouTubeApiPlaylistItem) => ({
        id: playlist.id,
        title: playlist.snippet.title,
        description: playlist.snippet.description,
        thumbnail: playlist.snippet.thumbnails?.medium?.url || playlist.snippet.thumbnails?.default?.url || '',
        itemCount: playlist.contentDetails?.itemCount || 0,
        privacy: playlist.status?.privacyStatus || 'unknown',
        publishedAt: this.formatPublishDate(playlist.snippet.publishedAt)
      }));
    } catch (error) {
      console.error('Error fetching playlists:', error);
      return [];
    }
  }

  // Fetch channel videos (recent and top performing)
  private async fetchChannelVideos(accessToken: string, channelId: string) {
    try {
      // Get recent videos
      const recentResponse = await fetch(
        `https://www.googleapis.com/youtube/v3/search?part=snippet&channelId=${channelId}&order=date&type=video&maxResults=10&key=${this.API_KEY}`,
        {
          headers: {
            'Authorization': `Bearer ${accessToken}`,
            'Accept': 'application/json',
          },
        }
      );

      if (!recentResponse.ok) {
        throw new Error(`Failed to fetch recent videos: ${recentResponse.status}`);
      }

      const recentData = await recentResponse.json();
      const videoIds = recentData.items.map((item: { id: { videoId: string } }) => item.id.videoId).join(',');

      // Get detailed video information
      const detailsResponse = await fetch(
        `https://www.googleapis.com/youtube/v3/videos?part=statistics,contentDetails,snippet&id=${videoIds}&key=${this.API_KEY}`,
        {
          headers: {
            'Authorization': `Bearer ${accessToken}`,
            'Accept': 'application/json',
          },
        }
      );

      if (!detailsResponse.ok) {
        throw new Error(`Failed to fetch video details: ${detailsResponse.status}`);
      }

      const detailsData = await detailsResponse.json();

      const videos = detailsData.items.map((video: YouTubeApiVideoItem) => ({
        id: video.id,
        title: video.snippet.title,
        description: video.snippet.description,
        thumbnail: video.snippet.thumbnails?.medium?.url || video.snippet.thumbnails?.default?.url || '',
        views: this.formatNumber(parseInt(video.statistics.viewCount || '0')),
        likes: this.formatNumber(parseInt(video.statistics.likeCount || '0')),
        duration: this.formatDuration(video.contentDetails.duration),
        durationRaw: video.contentDetails.duration, // Keep raw ISO 8601 duration
        publishedAt: this.formatPublishDate(video.snippet.publishedAt),
        publishedAtRaw: video.snippet.publishedAt, // Keep raw ISO date for sorting
        tags: video.snippet.tags || []
      }));

      // Sort by views to get top videos
      const sortedByViews = [...videos].sort((a, b) =>
        parseInt(a.views.replace(/[^\d]/g, '')) - parseInt(b.views.replace(/[^\d]/g, ''))
      ).reverse();

      return {
        recentVideos: videos,
        topVideos: sortedByViews.slice(0, 5)
      };
    } catch (error) {
      console.error('Error fetching channel videos:', error);
      return {
        recentVideos: [],
        topVideos: []
      };
    }
  }

  // Helper method to format video duration from ISO 8601 format
  private formatDuration(duration: string): string {
    try {
      const match = duration.match(/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/);
      if (!match) return '0:00';

      const hours = parseInt(match[1] || '0');
      const minutes = parseInt(match[2] || '0');
      const seconds = parseInt(match[3] || '0');

      if (hours > 0) {
        return `${hours}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
      } else {
        return `${minutes}:${seconds.toString().padStart(2, '0')}`;
      }
    } catch (error) {
      return '0:00';
    }
  }

  // Helper method to format publish date
  private formatPublishDate(publishedAt: string): string {
    try {
      const publishDate = new Date(publishedAt);
      const now = new Date();
      const diffTime = Math.abs(now.getTime() - publishDate.getTime());
      const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

      if (diffDays === 1) return '1 day ago';
      if (diffDays < 7) return `${diffDays} days ago`;
      if (diffDays < 30) return `${Math.floor(diffDays / 7)} weeks ago`;
      if (diffDays < 365) return `${Math.floor(diffDays / 30)} months ago`;
      return `${Math.floor(diffDays / 365)} years ago`;
    } catch (error) {
      return 'Recently';
    }
  }

  // Refresh access token using refresh token via viewsmax.ai backend
  async refreshAccessToken(refreshToken: string): Promise<string> {
    try {
      // Import the API service dynamically to avoid circular dependencies
      const { viewsMaxApi } = await import('@/lib/api-service');

      const result = await viewsMaxApi.refreshYouTubeToken(refreshToken);

      if (!result.success) {
        console.error('Token refresh failed:', result.error);
        throw new Error(result.error || 'Token refresh failed');
      }

      // Update stored access token
      localStorage.setItem('youtube_access_token', result.data!.access_token);

      return result.data!.access_token;
    } catch (error) {
      console.error('Error refreshing token:', error);
      throw error;
    }
  }

  // Format numbers for display
  private formatNumber(num: number): string {
    if (num >= 1000000) {
      return (num / 1000000).toFixed(1) + 'M';
    }
    if (num >= 1000) {
      return (num / 1000).toFixed(1) + 'K';
    }
    return num.toString();
  }

  // Parse formatted numbers back to integers
  private parseFormattedNumber(str: string): number {
    const num = parseFloat(str);
    if (str.includes('M')) return Math.round(num * 1000000);
    if (str.includes('K')) return Math.round(num * 1000);
    return Math.round(num);
  }

  // Check if user is authenticated
  isAuthenticated(): boolean {
    const accessToken = localStorage.getItem('youtube_access_token');
    const channelData = localStorage.getItem('youtube_channel_data');
    return !!(accessToken && channelData);
  }

  // Get stored channel data
  getStoredChannelData(): YouTubeChannelData | null {
    const data = localStorage.getItem('youtube_channel_data');
    return data ? JSON.parse(data) : null;
  }

  // Get API key for debugging
  getApiKey(): string {
    return this.API_KEY;
  }

  // Disconnect/logout
  disconnect(): void {
    this.clearStoredAuth();
    console.log('🔌 Disconnected from YouTube');
  }

  // Clear stored authentication data
  private clearStoredAuth(): void {
    localStorage.removeItem('youtube_access_token');
    localStorage.removeItem('youtube_refresh_token');
    localStorage.removeItem('youtube_channel_data');
    localStorage.removeItem('youtube_auth_type');
  }

  // Advanced API testing with detailed logs
  async performDetailedApiTest(): Promise<{
    success: boolean, details: {
      apiKeyValid: boolean;
      channelDataRetrieved: boolean;
      videosRetrieved: boolean;
      error: string | null;
    }
  }> {
    const testResults = {
      success: false,
      details: {
        apiKeyValid: false,
        channelDataRetrieved: false,
        videosRetrieved: false,
        error: null as string | null
      }
    };

    try {
      console.log('🔍 Starting detailed API test...');

      // Test 1: Basic API key validation
      console.log('Test 1: Validating API key...');
      const apiResponse = await fetch(
        `https://www.googleapis.com/youtube/v3/channels?part=snippet&id=UCBR8-60-B28hp2BmDPdntcQ&key=${this.API_KEY}`
      );

      if (!apiResponse.ok) {
        throw new Error(`API Key validation failed: ${apiResponse.status} - ${apiResponse.statusText}`);
      }

      testResults.details.apiKeyValid = true;
      console.log('✅ API key is valid');

      // Test 2: Retrieve channel data
      console.log('Test 2: Retrieving channel data...');
      const channelData = await apiResponse.json();
      if (channelData.items && channelData.items.length > 0) {
        testResults.details.channelDataRetrieved = true;
        console.log('✅ Channel data retrieved:', channelData.items[0].snippet.title);
      }

      // Test 3: Retrieve videos
      console.log('Test 3: Retrieving videos...');
      const videosResponse = await fetch(
        `https://www.googleapis.com/youtube/v3/search?part=snippet&channelId=UCBR8-60-B28hp2BmDPdntcQ&type=video&maxResults=5&key=${this.API_KEY}`
      );

      if (videosResponse.ok) {
        const videosData = await videosResponse.json();
        if (videosData.items && videosData.items.length > 0) {
          testResults.details.videosRetrieved = true;
          console.log('✅ Videos retrieved:', videosData.items.length, 'videos');
        }
      }

      testResults.success = testResults.details.apiKeyValid &&
        testResults.details.channelDataRetrieved &&
        testResults.details.videosRetrieved;

      console.log('🎉 Detailed API test completed:', testResults);
      return testResults;

    } catch (error) {
      console.error('❌ Detailed API test failed:', error);
      testResults.details.error = error.message;
      return testResults;
    }
  }
}

// Export singleton instance
export const youtubeAuthService = new YouTubeAuthService();
export type {
  YouTubeChannelData,
  YouTubeVideo,
  YouTubePlaylist,
  YouTubeAnalytics,
  ViewsTimeSeriesData,
  AudienceDemographics,
  WatchTimeAnalytics
};
