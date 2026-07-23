import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { TrendingUp, Eye, Clock, Hash, Play, FileText } from "lucide-react";
import { useNavigate } from "react-router-dom";

const Trending = () => {
  const navigate = useNavigate();
  const featuredVideos = [
    {
      id: 1,
      videoId: "dQw4w9WgXcQ",
      title: "AI Tools Revolution: Complete Guide",
      totalViews: "2,340,567",
      dailyViews: "45,230",
      publishedDate: "2024-01-15",
      channelName: "TechExplained"
    },
    {
      id: 2,
      videoId: "jNQXAC9IVRw",
      title: "10 Minute Home Workout - No Equipment",
      totalViews: "1,892,433",
      dailyViews: "32,180",
      publishedDate: "2024-01-12",
      channelName: "FitLife"
    },
    {
      id: 3,
      videoId: "9bZkp7q19f0",
      title: "Sustainable Living Made Easy",
      totalViews: "987,654",
      dailyViews: "18,420",
      publishedDate: "2024-01-10",
      channelName: "EcoLifestyle"
    },
    {
      id: 4,
      videoId: "M7lc1UVf-VE",
      title: "Crypto Investing for Beginners",
      totalViews: "3,145,789",
      dailyViews: "62,340",
      publishedDate: "2024-01-08",
      channelName: "CryptoGuru"
    },
    {
      id: 5,
      videoId: "ZZ5LpwO-An4",
      title: "5-Minute Pasta Recipes",
      totalViews: "2,567,890",
      dailyViews: "48,920",
      publishedDate: "2024-01-06",
      channelName: "QuickCooks"
    },
    {
      id: 6,
      videoId: "ALZHF5UqnU4",
      title: "Home Office Setup Guide 2024",
      totalViews: "1,234,567",
      dailyViews: "28,450",
      publishedDate: "2024-01-04",
      channelName: "WorkFromHome"
    },
    {
      id: 7,
      videoId: "hFZFjoX2cGg",
      title: "Travel Hacks That Actually Work",
      totalViews: "1,789,234",
      dailyViews: "35,670",
      publishedDate: "2024-01-02",
      channelName: "TravelSmart"
    },
    {
      id: 8,
      videoId: "fJ9rUzIMcZQ",
      title: "Photography Tips for Beginners",
      totalViews: "1,456,789",
      dailyViews: "29,880",
      publishedDate: "2023-12-30",
      channelName: "PhotoPro"
    },
    {
      id: 9,
      videoId: "QH2-TGUlwu4",
      title: "Morning Routine for Productivity",
      totalViews: "2,123,456",
      dailyViews: "41,250",
      publishedDate: "2023-12-28",
      channelName: "LifeHacker"
    }
  ];
  // Group videos into rows of 3
  const videoRows = [];
  for (let i = 0; i < featuredVideos.length; i += 3) {
    videoRows.push(featuredVideos.slice(i, i + 3));
  }

  return (
    <div className="space-y-8">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-foreground">Trending Videos</h2>
          <p className="text-muted-foreground">Discover viral content opportunities in your niche</p>
        </div>
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <Clock className="w-4 h-4" />
          <span>Updated 2 hours ago</span>
        </div>
      </div>

      {/* Video Rows */}
      {videoRows.map((row, rowIndex) => (
        <div key={rowIndex} className="grid grid-cols-1 md:grid-cols-3 gap-6">
          {row.map((video) => (
            <Card key={video.id} className="overflow-hidden">
              <div className="aspect-video">
                <iframe
                  className="w-full h-full"
                  src={`https://www.youtube.com/embed/${video.videoId}`}
                  title={video.title}
                  frameBorder="0"
                  allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                  allowFullScreen
                />
              </div>
              <CardContent className="p-4 space-y-3">
                <h4 className="font-semibold text-sm truncate">{video.title}</h4>
                <p className="text-xs text-muted-foreground">{video.channelName}</p>
                <div className="space-y-2 text-xs text-muted-foreground">
                  <div className="flex items-center justify-between">
                    <span>Total Views</span>
                    <span className="font-medium text-foreground">{video.totalViews}</span>
                  </div>
                  <div className="flex items-center justify-between">
                    <span>Daily Views</span>
                    <span className="font-medium text-foreground">{video.dailyViews}</span>
                  </div>
                  <div className="flex items-center justify-between">
                    <span>Published</span>
                    <span className="font-medium text-foreground">{video.publishedDate}</span>
                  </div>
                </div>
                <div className="flex gap-1 pt-2">
                  <Button
                    size="sm"
                    variant="outline"
                    className="flex-1 text-xs px-1 py-1 h-7"
                    onClick={() => navigate(`/dashboard/summarize/${video.videoId}`)}
                  >
                    <FileText className="w-3 h-3 mr-1" />
                    Summarise
                  </Button>
                  <Button
                    size="sm"
                    variant="secondary"
                    className="flex-1 text-xs px-1 py-1 h-7"
                  >
                    <Play className="w-3 h-3 mr-1" />
                    Use Template
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      ))}
    </div>
  );
};

export default Trending;