import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { ArrowLeft, Eye, Calendar, User, TrendingUp } from "lucide-react";
import { useNavigate, useParams } from "react-router-dom";

const Summarize = () => {
  const navigate = useNavigate();
  const { videoId } = useParams();

  // Mock data - in a real app, this would come from an API or state
  const videoData = {
    videoId: videoId || "dQw4w9WgXcQ",
    title: "AI Tools Revolution: Complete Guide",
    totalViews: "2,340,567",
    dailyViews: "45,230",
    publishedDate: "2024-01-15",
    channelName: "TechExplained",
    summary: {
      intro: "This comprehensive guide explores the latest AI tools that are revolutionizing how we work, create, and solve problems in 2024.",
      sections: [
        {
          title: "Introduction to AI Tools",
          timestamp: "00:00 - 02:30",
          keyPoints: [
            "Overview of the current AI landscape",
            "Why AI tools matter for productivity",
            "Common misconceptions about AI"
          ]
        },
        {
          title: "Content Creation AI",
          timestamp: "02:30 - 08:15",
          keyPoints: [
            "Text generation tools like GPT-4 and Claude",
            "Image generation with DALL-E and Midjourney",
            "Video creation platforms"
          ]
        },
        {
          title: "Business & Productivity AI",
          timestamp: "08:15 - 14:20",
          keyPoints: [
            "Project management automation",
            "Email and communication assistants",
            "Data analysis and reporting tools"
          ]
        },
        {
          title: "Development & Technical AI",
          timestamp: "14:20 - 18:45",
          keyPoints: [
            "Code generation and debugging",
            "API integration tools",
            "Testing and deployment automation"
          ]
        },
        {
          title: "Future Trends & Predictions",
          timestamp: "18:45 - 22:00",
          keyPoints: [
            "Emerging AI technologies",
            "Industry adoption patterns",
            "Ethical considerations and best practices"
          ]
        }
      ],
      keyTakeaways: [
        "AI tools are becoming essential for competitive advantage",
        "Integration is more important than individual tool mastery",
        "Human oversight remains crucial for quality output",
        "The landscape is rapidly evolving - continuous learning is key"
      ]
    }
  };

  return (
    <div className="space-y-6">
      {/* Header - Sticky */}
      <div className="sticky top-0 bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60 z-10 py-4 border-b">
        <div className="flex items-center justify-between">
          <h1 className="text-2xl font-bold text-foreground">Video Analysis</h1>
          <div className="flex gap-3">
            <Button>
              Create Script from Summary
            </Button>
          </div>
        </div>
      </div>

      {/* Video and Stats Layout */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Video Player */}
        <div className="lg:col-span-2">
          <Card>
            <CardContent className="p-0">
              <div className="aspect-video">
                <iframe
                  className="w-full h-full rounded-t-lg"
                  src={`https://www.youtube.com/embed/${videoData.videoId}`}
                  title={videoData.title}
                  frameBorder="0"
                  allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                  allowFullScreen
                />
              </div>
              <div className="p-4">
                <h2 className="font-semibold text-lg mb-2">{videoData.title}</h2>
                <p className="text-sm text-muted-foreground">{videoData.channelName}</p>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Stats Panel */}
        <div className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">Video Statistics</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <Eye className="w-4 h-4 text-muted-foreground" />
                  <span className="text-sm">Total Views</span>
                </div>
                <span className="font-medium">{videoData.totalViews}</span>
              </div>
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <TrendingUp className="w-4 h-4 text-muted-foreground" />
                  <span className="text-sm">Daily Views</span>
                </div>
                <span className="font-medium">{videoData.dailyViews}</span>
              </div>
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <Calendar className="w-4 h-4 text-muted-foreground" />
                  <span className="text-sm">Published</span>
                </div>
                <span className="font-medium">{videoData.publishedDate}</span>
              </div>
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <User className="w-4 h-4 text-muted-foreground" />
                  <span className="text-sm">Channel</span>
                </div>
                <span className="font-medium">{videoData.channelName}</span>
              </div>
            </CardContent>
          </Card>
        </div>
      </div>

      {/* AI Summary */}
      <Card>
        <CardHeader>
          <CardTitle className="text-xl">AI Video Summary</CardTitle>
        </CardHeader>
        <CardContent className="space-y-6">
          {/* Introduction */}
          <div>
            <h3 className="font-semibold text-lg mb-2">Introduction</h3>
            <p className="text-muted-foreground">{videoData.summary.intro}</p>
          </div>

          {/* Sections */}
          <div>
            <h3 className="font-semibold text-lg mb-4">Video Sections</h3>
            <div className="space-y-4">
              {videoData.summary.sections.map((section, index) => (
                <Card key={index} className="border-l-4 border-l-primary">
                  <CardContent className="p-4">
                    <div className="flex items-center justify-between mb-2">
                      <h4 className="font-medium">{section.title}</h4>
                      <span className="text-xs text-muted-foreground bg-muted px-2 py-1 rounded">
                        {section.timestamp}
                      </span>
                    </div>
                    <ul className="list-disc list-inside space-y-1 text-sm text-muted-foreground">
                      {section.keyPoints.map((point, pointIndex) => (
                        <li key={pointIndex}>{point}</li>
                      ))}
                    </ul>
                  </CardContent>
                </Card>
              ))}
            </div>
          </div>

          {/* Key Takeaways */}
          <div>
            <h3 className="font-semibold text-lg mb-4">Key Takeaways</h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              {videoData.summary.keyTakeaways.map((takeaway, index) => (
                <div key={index} className="bg-muted/50 p-3 rounded-lg">
                  <p className="text-sm">{takeaway}</p>
                </div>
              ))}
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default Summarize;