import { useState, useEffect, useCallback } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Loader2, Copy, Check, DollarSign } from "lucide-react";
import { toast } from "sonner";
import { viewsMaxApi } from "@/lib/api-service";
import { useAuth } from "@/hooks/useAuth";

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

interface Video {
  id: number;
  youtube_video_id: string;
  title: string;
  description: string;
  thumbnail_url?: string;
  thumbnail_medium_url?: string;
  thumbnail_high_url?: string;
  published_at: string;
  view_count: number;
  like_count: number;
  comment_count: number;
  duration: string;
  formatted_duration?: string;
  definition?: string;
  has_captions: boolean;
  unique_link?: string;
  conversions?: number;
}

const Monetization = () => {
  const { user } = useAuth();
  const [isLoadingChannels, setIsLoadingChannels] = useState(true);
  const [isLoadingVideos, setIsLoadingVideos] = useState(false);
  const [backendChannels, setBackendChannels] = useState<BackendChannel[]>([]);
  const [selectedChannel, setSelectedChannel] = useState<BackendChannel | null>(null);
  const [videos, setVideos] = useState<Video[]>([]);
  const [copiedLink, setCopiedLink] = useState<string | null>(null);

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
      }
    } catch (error) {
      console.error('Failed to fetch backend channels:', error);
      toast.error('Failed to load channels');
    } finally {
      setIsLoadingChannels(false);
    }
  }, [user, selectedChannel]);

  // Load videos for selected channel
  const loadVideos = useCallback(async () => {
    if (!selectedChannel) {
      setVideos([]);
      return;
    }

    setIsLoadingVideos(true);
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

      const response = await viewsMaxApi.getChannelVideos(token, selectedChannel.id, 100);
      if (response.success && response.data) {
        // Generate unique links for each video
        const videosWithLinks = response.data.videos.map((video) => ({
          ...video,
          unique_link: `${window.location.origin}/monetization/${video.id}`,
          conversions: video.conversions || 0, // Placeholder until API supports it
        }));
        setVideos(videosWithLinks);
      } else {
        toast.error(response.error || 'Failed to load videos');
        setVideos([]);
      }
    } catch (error) {
      console.error('Error loading videos:', error);
      toast.error('Failed to load videos');
      setVideos([]);
    } finally {
      setIsLoadingVideos(false);
    }
  }, [selectedChannel]);

  useEffect(() => {
    fetchBackendChannels();
  }, [fetchBackendChannels]);

  useEffect(() => {
    loadVideos();
  }, [loadVideos]);

  const handleCopyLink = (link: string) => {
    navigator.clipboard.writeText(link);
    setCopiedLink(link);
    toast.success('Link copied to clipboard');
    setTimeout(() => setCopiedLink(null), 2000);
  };

  if (isLoadingChannels) {
    return (
      <div className="space-y-6">
        <div className="flex items-center justify-center h-64">
          <div className="text-center">
            <Loader2 className="w-8 h-8 animate-spin mx-auto mb-4" />
            <p className="text-muted-foreground">Loading channels...</p>
          </div>
        </div>
      </div>
    );
  }

  if (backendChannels.length === 0) {
    return (
      <div className="space-y-6">
        <Card>
          <CardHeader>
            <CardTitle className="text-lg">Monetization</CardTitle>
            <CardDescription>
              Track conversions and manage monetization links for your videos
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="text-center py-8">
              <DollarSign className="w-12 h-12 mx-auto text-muted-foreground mb-4" />
              <p className="text-muted-foreground">No channels found</p>
              <p className="text-sm text-muted-foreground mt-2">
                Connect your YouTube channel to get started
              </p>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader className="p-4">
          <CardTitle className="text-lg">Monetization</CardTitle>
          <CardDescription>
            Track conversions and manage monetization links for your videos
          </CardDescription>
        </CardHeader>
        <CardContent className="p-4 pt-0">
          <div className="mb-6">
            <label className="text-sm font-medium mb-2 block">Select Channel</label>
            <Select
              value={selectedChannel?.id.toString() || ""}
              onValueChange={(value) => {
                const channel = backendChannels.find((c) => c.id.toString() === value);
                setSelectedChannel(channel || null);
              }}
            >
              <SelectTrigger className="w-full max-w-md">
                <SelectValue placeholder="Select a channel" />
              </SelectTrigger>
              <SelectContent>
                {backendChannels.map((channel) => (
                  <SelectItem key={channel.id} value={channel.id.toString()}>
                    {channel.channel_name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {isLoadingVideos ? (
            <div className="flex items-center justify-center h-64">
              <div className="text-center">
                <Loader2 className="w-8 h-8 animate-spin mx-auto mb-4" />
                <p className="text-muted-foreground">Loading videos...</p>
              </div>
            </div>
          ) : videos.length === 0 ? (
            <div className="text-center py-8">
              <DollarSign className="w-12 h-12 mx-auto text-muted-foreground mb-4" />
              <p className="text-muted-foreground">No videos found</p>
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-[72px] px-3">Thumbnail</TableHead>
                  <TableHead className="px-3">Title</TableHead>
                  <TableHead className="px-3">Unique Link</TableHead>
                  <TableHead className="text-right px-3">Conversions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {videos.map((video) => (
                  <TableRow key={video.id}>
                    <TableCell className="px-3 py-2">
                      <img
                        src={video.thumbnail_medium_url || video.thumbnail_url || ''}
                        alt={video.title}
                        className="w-16 h-10 object-cover rounded"
                      />
                    </TableCell>
                    <TableCell className="font-medium max-w-[240px] px-3 py-2">
                      <div className="truncate" title={video.title}>
                        {video.title}
                      </div>
                    </TableCell>
                    <TableCell className="px-3 py-2">
                      <div className="flex items-center gap-2">
                        <code className="text-xs bg-muted px-2 py-1 rounded max-w-[220px] truncate">
                          {video.unique_link}
                        </code>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-8 w-8 p-0 shrink-0"
                          onClick={() => handleCopyLink(video.unique_link || '')}
                        >
                          {copiedLink === video.unique_link ? (
                            <Check className="w-4 h-4 text-green-600" />
                          ) : (
                            <Copy className="w-4 h-4" />
                          )}
                        </Button>
                      </div>
                    </TableCell>
                    <TableCell className="text-right font-medium px-3 py-2">
                      {video.conversions || 0}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  );
};

export default Monetization;




