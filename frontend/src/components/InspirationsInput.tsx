import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Tooltip, TooltipContent, TooltipTrigger, TooltipProvider } from "@/components/ui/tooltip";
import { X, Link as LinkIcon } from "lucide-react";
import { toast } from "sonner";

export interface Inspiration {
  id: string;
  type: 'youtube' | 'file';
  url?: string;
  thumbnailUrl?: string;
  title?: string;
  file?: File;
  fileName?: string;
}

interface InspirationsInputProps {
  inspirations: Inspiration[];
  onChange: (inspirations: Inspiration[]) => void;
}

const InspirationsInput = ({ inspirations, onChange }: InspirationsInputProps) => {
  const [youtubeUrl, setYoutubeUrl] = useState("");
  const [failedThumbnails, setFailedThumbnails] = useState<Set<string>>(new Set());

  // Validate YouTube URL
  const isValidYouTubeUrl = (url: string): boolean => {
    const patterns = [
      /^https?:\/\/(www\.)?(youtube\.com|youtu\.be)\/.+/,
      /^https?:\/\/youtube\.com\/watch\?v=[\w-]+/,
      /^https?:\/\/youtu\.be\/[\w-]+/,
      /^https?:\/\/youtube\.com\/embed\/[\w-]+/,
    ];
    return patterns.some(pattern => pattern.test(url));
  };

  // Extract YouTube video ID from URL
  const extractVideoId = (url: string): string | null => {
    const patterns = [
      /(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([\w-]+)/,
    ];
    for (const pattern of patterns) {
      const match = url.match(pattern);
      if (match && match[1]) {
        return match[1];
      }
    }
    return null;
  };

  const fetchVideoDetails = async (videoId: string): Promise<{ title: string; thumbnailUrl: string } | null> => {
    try {
      // Use YouTube oEmbed API as a fallback (no API key needed) or try direct API
      const oEmbedUrl = `https://www.youtube.com/oembed?url=https://www.youtube.com/watch?v=${videoId}&format=json`;
      
      try {
        const response = await fetch(oEmbedUrl);
        if (response.ok) {
          const data = await response.json();
          return {
            title: data.title,
            thumbnailUrl: `https://img.youtube.com/vi/${videoId}/mqdefault.jpg`,
          };
        }
      } catch (e) {
        // oEmbed failed, try YouTube Data API if we have a key
        const apiKey = import.meta.env.VITE_YOUTUBE_API_KEY;
        if (apiKey) {
          const apiResponse = await fetch(
            `https://www.googleapis.com/youtube/v3/videos?id=${videoId}&part=snippet&key=${apiKey}`
          );
          if (apiResponse.ok) {
            const apiData = await apiResponse.json();
            if (apiData.items && apiData.items.length > 0) {
              return {
                title: apiData.items[0].snippet.title,
                thumbnailUrl: apiData.items[0].snippet.thumbnails?.medium?.url || `https://img.youtube.com/vi/${videoId}/mqdefault.jpg`,
              };
            }
          }
        }
      }
      
      // Fallback: just return with no title
      return {
        title: '',
        thumbnailUrl: `https://img.youtube.com/vi/${videoId}/mqdefault.jpg`,
      };
    } catch (error) {
      console.error('Error fetching video details:', error);
      return null;
    }
  };

  const addYouTubeUrl = async (url?: string) => {
    const urlToAdd = url || youtubeUrl;
    
    if (inspirations.length >= 3) {
      toast.error("Maximum of 3 inspirations allowed");
      return;
    }

    if (!urlToAdd.trim()) {
      toast.error("Please enter a YouTube URL");
      return;
    }

    if (!isValidYouTubeUrl(urlToAdd)) {
      toast.error("Please enter a valid YouTube URL");
      return;
    }

    // Check if URL already exists
    if (inspirations.some(insp => insp.type === 'youtube' && insp.url === urlToAdd)) {
      toast.error("This YouTube URL has already been added");
      return;
    }

    const videoId = extractVideoId(urlToAdd);
    if (!videoId) {
      toast.error("Could not extract video ID from URL");
      return;
    }

    // Fetch video details
    toast.info("Fetching video details...");
    const videoDetails = await fetchVideoDetails(videoId);
    
    if (!videoDetails) {
      toast.error("Failed to fetch video details");
      return;
    }

    const newInspiration: Inspiration = {
      id: Date.now().toString() + Math.random().toString(36).substr(2, 9),
      type: 'youtube',
      url: urlToAdd,
      thumbnailUrl: videoDetails.thumbnailUrl,
      title: videoDetails.title || 'Untitled Video',
    };

    onChange([...inspirations, newInspiration]);
    setYoutubeUrl("");
    toast.success("YouTube video added");
  };

  const removeInspiration = (id: string) => {
    onChange(inspirations.filter(insp => insp.id !== id));
    toast.success("Inspiration removed");
  };

  const handlePaste = async (e: React.ClipboardEvent<HTMLInputElement>) => {
    const pastedText = e.clipboardData.getData('text').trim();
    
    // If pasted text is a valid YouTube URL, automatically add it after paste
    if (pastedText && isValidYouTubeUrl(pastedText)) {
      // Let the paste happen first, then auto-add
      // Use setTimeout to ensure the paste event completes first
      setTimeout(() => {
        addYouTubeUrl(pastedText);
      }, 100);
    }
  };

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const value = e.target.value;
    setYoutubeUrl(value);
  };

  const isMaxReached = inspirations.length >= 3;

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <Label>Inspirations</Label>
          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger asChild>
                <Button variant="ghost" size="sm" className="h-4 w-4 p-0">
                  <span className="sr-only">Help</span>
                  <svg className="h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                  </svg>
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>Add a YouTube URL as an inspiration for the script. A.I will summarise the script and use it as an outline to build your script.</p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>
        </div>
        <span className="text-sm text-muted-foreground">
          {inspirations.length}/3
        </span>
      </div>
      
      {/* YouTube URL Input */}
      <div className="space-y-2">
        <Input
          type="url"
          placeholder="Enter YouTube URL (e.g., https://youtube.com/watch?v=...)"
          value={youtubeUrl}
          onChange={handleInputChange}
          onPaste={handlePaste}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              addYouTubeUrl();
            }
          }}
          onBlur={() => {
            // Auto-add if the input contains a valid YouTube URL when user leaves the field
            if (youtubeUrl.trim() && isValidYouTubeUrl(youtubeUrl.trim())) {
              addYouTubeUrl(youtubeUrl.trim());
            }
          }}
          className="w-full"
          disabled={isMaxReached}
        />
      </div>

      {/* Inspiration List */}
      {inspirations.length > 0 && (
        <div className="space-y-2">
          <Label className="text-sm">Added Inspirations ({inspirations.length})</Label>
          
          {/* YouTube thumbnails grid */}
          {inspirations.filter(insp => insp.type === 'youtube').length > 0 && (
            <div className="flex gap-2 flex-wrap">
              {inspirations
                .filter(inspiration => inspiration.type === 'youtube')
                .map((inspiration) => (
                  <div
                    key={inspiration.id}
                    className="relative aspect-video rounded-lg overflow-hidden border bg-muted group"
                    style={{ width: '120px', height: '67.5px' }}
                  >
                    {inspiration.thumbnailUrl && !failedThumbnails.has(inspiration.id) ? (
                      <img
                        src={inspiration.thumbnailUrl}
                        alt={inspiration.title || "YouTube thumbnail"}
                        className="w-full h-full object-cover"
                        onError={() => {
                          // Mark this thumbnail as failed
                          setFailedThumbnails(prev => new Set(prev).add(inspiration.id));
                        }}
                      />
                    ) : (
                      <div className="w-full h-full flex items-center justify-center bg-muted">
                        <LinkIcon className="w-5 h-5 text-red-500" />
                      </div>
                    )}
                    {/* Delete button overlay */}
                    <Button
                      type="button"
                      variant="destructive"
                      size="icon"
                      onClick={() => removeInspiration(inspiration.id)}
                      className="absolute top-0.5 right-0.5 h-5 w-5 p-0 shadow-lg"
                    >
                      <X className="w-3 h-3" />
                    </Button>
                  </div>
                ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
};

export default InspirationsInput;

