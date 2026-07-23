import { useState } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { 
  ChartContainer, 
  ChartTooltip, 
  ChartTooltipContent 
} from "@/components/ui/chart";
import { TrendingUp } from "lucide-react";
import { LineChart, Line, XAxis, YAxis, CartesianGrid, ReferenceLine, ResponsiveContainer } from "recharts";
import { format } from "date-fns";

// Dummy data for subscriber growth
const generateSubscriberData = () => {
  const startDate = new Date('2024-01-01');
  const data = [];
  let subscribers = 1000;
  
  for (let i = 0; i < 365; i++) {
    const date = new Date(startDate);
    date.setDate(date.getDate() + i);
    
    // Simulate gradual growth with some randomness
    const growth = Math.floor(Math.random() * 50) + 10;
    subscribers += growth;
    
    data.push({
      date: date.toISOString(),
      subscribers: Math.floor(subscribers),
      timestamp: date.getTime(),
    });
  }
  
  return data;
};

// Dummy video data
const dummyVideos = [
  {
    id: '1',
    title: '10 Mind-Blowing Productivity Hacks That Changed My Life',
    thumbnail: 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?w=320&h=180&fit=crop',
    releaseDate: '2024-01-15',
    views: 125000,
    likes: 8500,
    subscribers: 1520,
  },
  {
    id: '2',
    title: 'The Ultimate Guide to Building Your First App',
    thumbnail: 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=320&h=180&fit=crop',
    releaseDate: '2024-02-28',
    views: 245000,
    likes: 15200,
    subscribers: 1980,
  },
  {
    id: '3',
    title: 'I Tried AI Tools for 30 Days - Here\'s What Happened',
    thumbnail: 'https://images.unsplash.com/photo-1677442136019-0c03654afe5c?w=320&h=180&fit=crop',
    releaseDate: '2024-04-10',
    views: 389000,
    likes: 22100,
    subscribers: 2650,
  },
  {
    id: '4',
    title: 'Why I Quit My Job to Become a YouTuber Full-Time',
    thumbnail: 'https://images.unsplash.com/photo-1591455769819-ee40a8459ef4?w=320&h=180&fit=crop',
    releaseDate: '2024-05-22',
    views: 567000,
    likes: 34200,
    subscribers: 3420,
  },
  {
    id: '5',
    title: 'The Secret to Getting 1 Million Subscribers',
    thumbnail: 'https://images.unsplash.com/photo-1611162617474-5b21e879e113?w=320&h=180&fit=crop',
    releaseDate: '2024-07-05',
    views: 892000,
    likes: 56800,
    subscribers: 4580,
  },
  {
    id: '6',
    title: 'Building a Million Dollar Business From Scratch',
    thumbnail: 'https://images.unsplash.com/photo-1552664730-d307ca884978?w=320&h=180&fit=crop',
    releaseDate: '2024-08-18',
    views: 1245000,
    likes: 78200,
    subscribers: 5820,
  },
  {
    id: '7',
    title: 'My 2024 Year in Review: Lessons and Growth',
    thumbnail: 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=320&h=180&fit=crop',
    releaseDate: '2024-10-03',
    views: 678000,
    likes: 42100,
    subscribers: 6520,
  },
  {
    id: '8',
    title: 'How I Edit Videos in Under 30 Minutes',
    thumbnail: 'https://images.unsplash.com/photo-1593508512255-86ab42c8a355?w=320&h=180&fit=crop',
    releaseDate: '2024-11-15',
    views: 445000,
    likes: 28900,
    subscribers: 7200,
  },
];

const subscriberData = generateSubscriberData();

// Merge video data with subscriber data to show video release lines
const chartData = subscriberData.map((point) => {
  const videoOnThisDate = dummyVideos.find(video => {
    const videoDate = new Date(video.releaseDate).toISOString().split('T')[0];
    const pointDate = point.date.split('T')[0];
    return videoDate === pointDate;
  });
  
  return {
    ...point,
    video: videoOnThisDate ? videoOnThisDate.id : null,
  };
});

const chartConfig = {
  subscribers: {
    label: "Subscribers",
    color: "hsl(var(--primary))",
  },
};

const Stats = () => {
  const [hoveredVideo, setHoveredVideo] = useState<string | null>(null);
  const [selectedVideo, setSelectedVideo] = useState<typeof dummyVideos[0] | null>(null);

  const formatDate = (dateString: string) => {
    return format(new Date(dateString), 'MMM d, yyyy');
  };

  const formatSubscribers = (value: number) => {
    if (value >= 1000000) {
      return `${(value / 1000000).toFixed(1)}M`;
    }
    if (value >= 1000) {
      return `${(value / 1000).toFixed(1)}K`;
    }
    return value.toString();
  };


  return (
    <div className="space-y-6 max-w-7xl">
      <div>
        <h2 className="text-2xl font-bold text-foreground">Channel Statistics</h2>
        <p className="text-muted-foreground">Track your subscriber growth and video performance</p>
      </div>

      {/* Subscriber Growth Chart */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <TrendingUp className="w-5 h-5 text-primary" />
            Subscriber Growth Over Time
          </CardTitle>
          <CardDescription>
            Your channel's subscriber count with video release markers
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="space-y-6">
            {/* Chart */}
            <div className="h-[500px] w-full">
              <ChartContainer config={chartConfig} className="h-full">
                <ResponsiveContainer width="100%" height="100%">
                  <LineChart
                    data={chartData}
                    margin={{ top: 20, right: 30, left: 20, bottom: 100 }}
                  >
                    <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                    <XAxis
                      dataKey="date"
                      tickFormatter={(value) => format(new Date(value), 'MMM d')}
                      angle={-45}
                      textAnchor="end"
                      height={80}
                      className="text-xs fill-muted-foreground"
                    />
                    <YAxis
                      tickFormatter={formatSubscribers}
                      className="text-xs fill-muted-foreground"
                    />
                    <ChartTooltip 
                      content={
                        <ChartTooltipContent 
                          formatter={(value: any) => formatSubscribers(value)}
                          labelFormatter={(label: string) => formatDate(label)}
                        />
                      }
                    />
                    <Line
                      type="monotone"
                      dataKey="subscribers"
                      stroke="hsl(var(--primary))"
                      strokeWidth={2}
                      dot={false}
                      activeDot={{ r: 6 }}
                    />
                    {/* Video release markers */}
                    {dummyVideos.map((video) => {
                      // Find the data point that matches this video's release date
                      const videoDataPoint = chartData.find(point => {
                        const pointDate = new Date(point.date).toISOString().split('T')[0];
                        const videoDate = new Date(video.releaseDate).toISOString().split('T')[0];
                        return pointDate === videoDate;
                      });
                      
                      if (!videoDataPoint) return null;
                      
                      return (
                        <ReferenceLine
                          key={video.id}
                          x={videoDataPoint.date}
                          stroke="hsl(var(--destructive))"
                          strokeWidth={2}
                          strokeDasharray="5 5"
                          label={{
                            value: `📹`,
                            position: 'top',
                            offset: 10,
                            className: 'text-lg',
                          }}
                        />
                      );
                    })}
                  </LineChart>
                </ResponsiveContainer>
              </ChartContainer>
            </div>

            {/* Video Thumbnails */}
            <div className="border-t pt-6">
              <h3 className="text-sm font-semibold text-foreground mb-4">Video Releases</h3>
              <div className="flex gap-4 overflow-x-auto pb-2 scrollbar-thin scrollbar-thumb-border scrollbar-track-transparent">
                {dummyVideos.map((video) => {
                  const videoSubscriberCount = subscriberData.find(point => {
                    const pointDate = new Date(point.date);
                    const videoDate = new Date(video.releaseDate);
                    return Math.abs(pointDate.getTime() - videoDate.getTime()) < 86400000; // within 1 day
                  })?.subscribers || 0;

                  return (
                    <div
                      key={video.id}
                      className="flex-shrink-0 w-48 cursor-pointer group"
                      onMouseEnter={() => setHoveredVideo(video.id)}
                      onMouseLeave={() => setHoveredVideo(null)}
                      onClick={() => setSelectedVideo(video)}
                    >
                      <div className="relative">
                        <div className="aspect-video rounded-lg overflow-hidden border-2 border-transparent group-hover:border-primary transition-all duration-200">
                          <img
                            src={video.thumbnail}
                            alt={video.title}
                            className="w-full h-full object-cover transition-transform duration-200 group-hover:scale-105"
                          />
                          <div className="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                            <div className="absolute bottom-2 left-2 right-2">
                              <p className="text-xs font-medium text-white line-clamp-2">
                                {video.title}
                              </p>
                            </div>
                          </div>
                        </div>
                        
                        {/* Hover tooltip */}
                        {hoveredVideo === video.id && (
                          <div className="absolute left-1/2 -translate-x-1/2 bottom-full mb-2 w-80 bg-background border border-border rounded-lg shadow-xl p-4 z-50 pointer-events-none">
                            <div className="space-y-3">
                              <img
                                src={video.thumbnail}
                                alt={video.title}
                                className="w-full aspect-video object-cover rounded-lg"
                              />
                              <div className="space-y-2">
                                <h4 className="font-semibold text-sm text-foreground line-clamp-2">
                                  {video.title}
                                </h4>
                                <div className="flex items-center justify-between text-xs text-muted-foreground">
                                  <span>{formatDate(video.releaseDate)}</span>
                                  <span>{formatSubscribers(videoSubscriberCount)} subscribers</span>
                                </div>
                                <div className="flex items-center gap-4 text-xs text-muted-foreground pt-2 border-t border-border">
                                  <span>{formatSubscribers(video.views)} views</span>
                                  <span>{formatSubscribers(video.likes)} likes</span>
                                </div>
                              </div>
                            </div>
                            {/* Tooltip arrow */}
                            <div className="absolute left-1/2 -translate-x-1/2 top-full w-0 h-0 border-l-8 border-r-8 border-t-8 border-transparent border-t-border"></div>
                          </div>
                        )}
                      </div>
                      <div className="mt-2 text-center">
                        <p className="text-xs text-muted-foreground font-medium">
                          {formatDate(video.releaseDate)}
                        </p>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Video Detail Modal */}
      <Dialog open={!!selectedVideo} onOpenChange={() => setSelectedVideo(null)}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle className="line-clamp-2">{selectedVideo?.title}</DialogTitle>
            <DialogDescription>
              Video released on {selectedVideo ? formatDate(selectedVideo.releaseDate) : ''}
            </DialogDescription>
          </DialogHeader>
          {selectedVideo && (
            <div className="space-y-4">
              <div className="aspect-video rounded-lg overflow-hidden border">
                <img
                  src={selectedVideo.thumbnail}
                  alt={selectedVideo.title}
                  className="w-full h-full object-cover"
                />
              </div>
              <div className="grid grid-cols-3 gap-4 pt-4 border-t">
                <div>
                  <p className="text-xs text-muted-foreground mb-1">Views</p>
                  <p className="text-lg font-semibold text-foreground">
                    {formatSubscribers(selectedVideo.views)}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground mb-1">Likes</p>
                  <p className="text-lg font-semibold text-foreground">
                    {formatSubscribers(selectedVideo.likes)}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground mb-1">Subscribers at Release</p>
                  <p className="text-lg font-semibold text-foreground">
                    {formatSubscribers(selectedVideo.subscribers)}
                  </p>
                </div>
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
};

export default Stats;

