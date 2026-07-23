import { useState, useEffect, useRef } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Upload, X } from "lucide-react";
import { cn } from "@/lib/utils";
import youtubeLogo from "@/assets/youtubelogo.png";
import searchBar from "@/assets/searchbar-scaled.png";
import signInButton from "@/assets/sign-in-buttons.png";

// Original videos data
const originalVideos = [
  { title: "How to Build a Successful YouTube Channel in 2024", channel: "Creator Academy", views: "245K", time: "3 days ago", duration: "12:45" },
  { title: "10 YouTube SEO Secrets That Actually Work", channel: "Video Marketing Pro", views: "89K", time: "1 week ago", duration: "8:20" },
  { title: "The Ultimate Guide to YouTube Thumbnail Design", channel: "Design Mastery", views: "156K", time: "5 days ago", duration: "15:30" },
  { title: "YouTube Algorithm Explained - What You Need to Know", channel: "Tech Insights", views: "312K", time: "2 weeks ago", duration: "18:15" },
  { title: "Monetize Your Channel: Complete Guide to YouTube Revenue", channel: "Business Builder", views: "421K", time: "1 week ago", duration: "22:10" },
  { title: "Best Video Editing Software for YouTube Creators", channel: "Creative Tools", views: "178K", time: "4 days ago", duration: "9:45" },
  { title: "Growing from 0 to 100K Subscribers: My Journey", channel: "Success Stories", views: "567K", time: "3 weeks ago", duration: "25:00" },
  { title: "YouTube Shorts vs Long Form: Which Performs Better?", channel: "Content Strategy", views: "234K", time: "6 days ago", duration: "11:30" },
  { title: "How to Get More Views on YouTube Videos", channel: "Growth Hacks", views: "198K", time: "1 week ago", duration: "14:20" },
  { title: "YouTube Analytics: Understanding Your Data", channel: "Data Driven", views: "145K", time: "5 days ago", duration: "16:45" },
  { title: "Creating Viral YouTube Content: What Works in 2024", channel: "Viral Secrets", views: "389K", time: "2 weeks ago", duration: "19:30" },
  { title: "YouTube Studio Tutorial: Master the Creator Tools", channel: "Creator Hub", views: "267K", time: "1 week ago", duration: "13:15" },
  { title: "Best Practices for YouTube Channel Branding", channel: "Brand Mastery", views: "156K", time: "4 days ago", duration: "10:50" },
  { title: "How to Collaborate with Other YouTubers", channel: "Network Pro", views: "223K", time: "1 week ago", duration: "17:25" },
  { title: "YouTube Copyright: What Creators Need to Know", channel: "Legal Guide", views: "134K", time: "3 days ago", duration: "12:10" },
];

// Unique YouTube video IDs for thumbnails (15 unique IDs)
const uniqueVideoIds = [
  'dQw4w9WgXcQ', 'VphHv-EXZjg', 'kJQP7kiw5Fk', '9bZkp7q19f0', 'L_jWHffIx5E',
  'GMuLmqvONho', 'OPf0YbXqDm0', 'RgKAFK5djSk', 'bDwr_7n1AZg', 'Qs2-klYtq5Y',
  'kffacxfA7G4', 'WwFwk0K67q8', 'YQHsXMglC9A', 'pRpeEdMmmQ0', 'iiOi4rD83kM'
];

// Shuffle function using Fisher-Yates algorithm
const shuffleArray = <T,>(array: T[]): T[] => {
  const shuffled = [...array];
  for (let i = shuffled.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
  }
  return shuffled;
};

const ThumbnailPreview = () => {
  const [uploadedImage, setUploadedImage] = useState<string | null>(null);
  const [imageFile, setImageFile] = useState<File | null>(null);
  const [failedThumbnails, setFailedThumbnails] = useState<Set<number>>(new Set());
  const [userThumbnailPosition, setUserThumbnailPosition] = useState<number | null>(null);
  const [shuffledVideos, setShuffledVideos] = useState<typeof originalVideos>(originalVideos);
  const [shuffledVideoIds, setShuffledVideoIds] = useState<string[]>(uniqueVideoIds);
  const fileInputRef = useRef<HTMLInputElement>(null);

  // SEO Metadata
  useEffect(() => {
    const title = "YouTube Thumbnail Preview Tool - See How Your Thumbnail Looks | ViewsMax";
    const description = "Upload your YouTube thumbnail and see exactly how it will appear on YouTube's home page. Test your thumbnail design before publishing to maximize click-through rates.";
    const keywords = "youtube thumbnail preview, thumbnail tester, youtube thumbnail checker, thumbnail preview tool, youtube thumbnail design, thumbnail mockup";

    // Update document title
    document.title = title;

    // Update or create meta tags
    const updateMetaTag = (name: string, content: string, isProperty = false) => {
      const attribute = isProperty ? "property" : "name";
      let meta = document.querySelector(`meta[${attribute}="${name}"]`) as HTMLMetaElement;
      if (!meta) {
        meta = document.createElement("meta");
        meta.setAttribute(attribute, name);
        document.head.appendChild(meta);
      }
      meta.content = content;
    };

    // Basic meta tags
    updateMetaTag("description", description);
    updateMetaTag("keywords", keywords);

    // Open Graph tags
    updateMetaTag("og:title", title, true);
    updateMetaTag("og:description", description, true);
    updateMetaTag("og:type", "website", true);
    updateMetaTag("og:url", `${window.location.origin}/thumbnail-preview`, true);

    // Twitter Card tags
    updateMetaTag("twitter:card", "summary_large_image");
    updateMetaTag("twitter:title", title);
    updateMetaTag("twitter:description", description);

    // Canonical URL
    let canonical = document.querySelector("link[rel='canonical']") as HTMLLinkElement;
    if (!canonical) {
      canonical = document.createElement("link");
      canonical.rel = "canonical";
      document.head.appendChild(canonical);
    }
    canonical.href = `${window.location.origin}/thumbnail-preview`;

    // Cleanup function to restore original title when component unmounts
    return () => {
      document.title = "ViewsMax - Content Analytics & Trend Insights";
    };
  }, []);

  const handleFileUpload = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (file) {
      if (!file.type.startsWith("image/")) {
        alert("Please upload an image file");
        return;
      }
      setImageFile(file);
      const reader = new FileReader();
      reader.onloadend = () => {
        setUploadedImage(reader.result as string);
        // Shuffle all videos and video IDs
        const shuffled = shuffleArray(originalVideos);
        const shuffledIds = shuffleArray(uniqueVideoIds);
        setShuffledVideos(shuffled);
        setShuffledVideoIds(shuffledIds);
        // Randomly place the thumbnail between position 0 and 14
        const randomPosition = Math.floor(Math.random() * 15);
        setUserThumbnailPosition(randomPosition);
        // Reset failed thumbnails when shuffling
        setFailedThumbnails(new Set());
      };
      reader.readAsDataURL(file);
    }
  };

  const handleRemoveImage = () => {
    setUploadedImage(null);
    setImageFile(null);
    setUserThumbnailPosition(null);
    setShuffledVideos(originalVideos);
    setShuffledVideoIds(uniqueVideoIds);
    setFailedThumbnails(new Set());
    if (fileInputRef.current) {
      fileInputRef.current.value = "";
    }
  };

  const handleDragOver = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
  };

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    const file = e.dataTransfer.files?.[0];
    if (file && file.type.startsWith("image/")) {
      setImageFile(file);
      const reader = new FileReader();
      reader.onloadend = () => {
        setUploadedImage(reader.result as string);
        // Shuffle all videos and video IDs
        const shuffled = shuffleArray(originalVideos);
        const shuffledIds = shuffleArray(uniqueVideoIds);
        setShuffledVideos(shuffled);
        setShuffledVideoIds(shuffledIds);
        // Randomly place the thumbnail between position 0 and 14
        const randomPosition = Math.floor(Math.random() * 15);
        setUserThumbnailPosition(randomPosition);
        // Reset failed thumbnails when shuffling
        setFailedThumbnails(new Set());
      };
      reader.readAsDataURL(file);
    }
  };


  return (
    <div className="min-h-screen bg-background py-12">
      <style>{`
        .youtube-grid {
          max-width: 1120px;
          grid-template-columns: repeat(3, 1fr);
          gap: 24px;
          padding: 0 0px;
        }
        
        @media (max-width: 1358px) {
          .youtube-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
          }
        }
        
        @media (max-width: 956px) {
          .youtube-grid {
            grid-template-columns: 1fr;
            gap: 16px;
          }
        }
        
        @media (max-width: 768px) {
          .search-bar-hidden {
            display: none;
          }
        }
        
        .thumbnail-container {
          position: relative;
          width: 100%;
          padding-bottom: 56.25%;
          background: #000;
          border-radius: 12px;
          overflow: hidden;
        }
        
        .thumbnail-container img {
          position: absolute;
          top: 0;
          left: 0;
          width: 100%;
          height: 100%;
          object-fit: cover;
        }
        
        .video-card-text {
          font-family: 'Roboto', sans-serif;
        }
          .container{
            max-width: 1490px;
          }
      `}</style>
      <div className="container mx-auto px-4 max-w-8xl">
        <div className="text-center mb-8">
          <h1 className="text-4xl font-bold text-foreground mb-2">YouTube Thumbnail Preview</h1>
          <p className="text-muted-foreground text-lg">
            Upload your thumbnail to see how it will appear on YouTube's home page
          </p>
        </div>

        <div className="flex flex-col md:flex-row gap-3">
          {/* Upload Section */}
          <Card className="w-full md:min-w-[280px] md:max-w-[280px]">
            <CardHeader className="p-5 pb-0">
              <CardTitle>Upload Thumbnail</CardTitle>
              <CardDescription>Upload your thumbnail to preview</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4 p-5">
              {!uploadedImage ? (
                <div
                  className="border-2 border-dashed border-muted-foreground/25 rounded-lg p-12 text-center cursor-pointer hover:border-primary transition-colors"
                  onDragOver={handleDragOver}
                  onDrop={handleDrop}
                  onClick={() => fileInputRef.current?.click()}
                >
                  <input
                    ref={fileInputRef}
                    type="file"
                    accept="image/*"
                    onChange={handleFileUpload}
                    className="hidden"
                  />
                  <Upload className="w-12 h-12 mx-auto mb-4 text-muted-foreground" />
                  <p className="text-sm text-muted-foreground mb-2">
                    Click to upload or drag and drop
                  </p>
                  <p className="text-xs text-muted-foreground">
                    Recommended: 1280x720px (16:9 aspect ratio)
                  </p>
                </div>
              ) : (
                <div className="space-y-4">
                  <div className="relative">
                    <img
                      src={uploadedImage}
                      alt="Uploaded thumbnail"
                      className="w-full h-auto rounded-lg border"
                    />
                    <Button
                      variant="destructive"
                      size="icon"
                      className="absolute top-2 right-2"
                      onClick={handleRemoveImage}
                    >
                      <X className="w-4 h-4" />
                    </Button>
                  </div>
                  <div className="text-sm text-muted-foreground">
                    <p>File: {imageFile?.name}</p>
                    <p>Size: {imageFile ? (imageFile.size / 1024).toFixed(2) : 0} KB</p>
                  </div>
                </div>
              )}
            </CardContent>
          </Card>

          {/* Preview Section */}
          <Card className="w-full">
            <CardHeader className="p-5 pb-0">
              <CardTitle>YouTube Desktop Home Page Preview</CardTitle>
              <CardDescription>How your thumbnail will appear to viewers on desktop</CardDescription>
            </CardHeader>
            <CardContent className="p-5">
              {uploadedImage ? (
                <div className="bg-white rounded-lg overflow-x-auto">
                  {/* Mock YouTube Desktop Home Page Layout */}
                  <div className="space-y-4">
                    {/* Header */}
                    <div className="flex items-center justify-between pb-3 border-b border-gray-200 gap-4">
                      {/* Logo - Left */}
                      <div className="flex items-center flex-shrink-0">
                        <img
                          src={youtubeLogo}
                          alt="YouTube"
                          className="w-[90px] h-auto"
                        />
                      </div>

                      {/* Search Bar - Center */}
                      <div className="flex-1 flex justify-center search-bar-hidden">
                        <img
                          src={searchBar}
                          alt="Search"
                          className="h-[34.92px] w-auto object-contain"
                        />
                      </div>

                      {/* Sign In Button - Right */}
                      <div className="flex items-center flex-shrink-0">
                        <img
                          src={signInButton}
                          alt="Sign in"
                          className="h-[36px] w-auto"
                        />
                      </div>
                    </div>

                    {/* Video Grid - Desktop layout (responsive) */}
                    <div className="grid youtube-grid">
                      {shuffledVideos.map((video, index) => {
                        // Check if this is the position for user's thumbnail
                        const isUserThumbnail = userThumbnailPosition !== null && index === userThumbnailPosition;

                        // Use shuffled video ID for each card
                        const videoId = shuffledVideoIds[index];
                        const thumbnailUrl = `https://img.youtube.com/vi/${videoId}/maxresdefault.jpg`;
                        const avatarIndex = (index + 1) % 70;

                        // Use a different index for failed thumbnails tracking to avoid conflicts
                        const thumbnailIndex = isUserThumbnail ? -1 : index;
                        const hasFailed = failedThumbnails.has(thumbnailIndex);

                        return (
                          <div key={index} className="cursor-pointer group">
                            <div className="thumbnail-container mb-2">
                              {isUserThumbnail && uploadedImage ? (
                                <img
                                  src={uploadedImage}
                                  alt="Your thumbnail preview"
                                />
                              ) : hasFailed ? (
                                <div
                                  className="absolute inset-0 flex items-center justify-center text-white text-xs font-semibold"
                                  style={{
                                    background: `linear-gradient(135deg, hsl(${(index * 30) % 360}, 70%, 50%), hsl(${(index * 30 + 60) % 360}, 70%, 50%))`
                                  }}
                                >
                                  {video.channel.substring(0, 2).toUpperCase()}
                                </div>
                              ) : (
                                <img
                                  src={thumbnailUrl}
                                  alt={video.title}
                                  onError={() => {
                                    setFailedThumbnails(prev => new Set(prev).add(thumbnailIndex));
                                  }}
                                />
                              )}
                              <div className="absolute bottom-1.5 right-1.5 bg-black/80 text-white text-xs px-1.5 py-0.5 rounded font-medium">
                                {isUserThumbnail ? "10:30" : video.duration}
                              </div>
                            </div>
                            <div className="flex gap-2">
                              <div className="w-9 h-9 rounded-full bg-gray-300 flex-shrink-0 mt-1 overflow-hidden">
                                <img
                                  src={`https://i.pravatar.cc/150?img=${avatarIndex}`}
                                  alt={isUserThumbnail ? "Channel avatar" : video.channel}
                                  className="w-full h-full object-cover"
                                />
                              </div>
                              <div className="flex-1 min-w-0 video-card-text">
                                <h3 className="text-gray-900 font-medium text-sm mb-1 line-clamp-2 group-hover:text-blue-600 transition-colors">
                                  {isUserThumbnail ? "Your Video Title Here - This is a sample title that might be longer" : video.title}
                                </h3>
                                <p className="text-gray-600 text-xs">{isUserThumbnail ? "Channel Name" : video.channel}</p>
                                <p className="text-gray-600 text-xs">{isUserThumbnail ? "1.2K views • 2 days ago" : `${video.views} views • ${video.time}`}</p>
                              </div>
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                </div>
              ) : (
                <div className="bg-white rounded-lg p-4 min-h-[500px] flex items-center justify-center border border-gray-200">
                  <div className="text-center text-gray-500">
                    <img
                      src={youtubeLogo}
                      alt="YouTube"
                      className="h-16 w-auto mx-auto mb-4 opacity-50"
                    />
                    <p className="text-sm">Upload a thumbnail to see the preview</p>
                  </div>
                </div>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Tips Section */}
        <Card className="mt-6">
          <CardHeader>
            <CardTitle>Thumbnail Best Practices</CardTitle>
            <CardDescription>Tips to make your thumbnail stand out</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="grid md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <h4 className="font-semibold text-foreground">Size & Format</h4>
                <ul className="text-sm text-muted-foreground space-y-1 list-disc list-inside">
                  <li>Recommended: 1280x720 pixels (16:9 aspect ratio)</li>
                  <li>Maximum file size: 2MB</li>
                  <li>Formats: JPG, PNG, GIF, or WebP</li>
                </ul>
              </div>
              <div className="space-y-2">
                <h4 className="font-semibold text-foreground">Design Tips</h4>
                <ul className="text-sm text-muted-foreground space-y-1 list-disc list-inside">
                  <li>Use high contrast colors for visibility</li>
                  <li>Include text that's readable at small sizes</li>
                  <li>Show faces or emotions to increase engagement</li>
                  <li>Avoid clutter - keep it simple and clear</li>
                </ul>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
};

export default ThumbnailPreview;

