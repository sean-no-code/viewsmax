import { Tabs, TabsContent, TabsList, TabsTrigger } from "@viewsmax/ui";

export const Default = () => (
  <Tabs defaultValue="overview" className="w-full max-w-md">
    <TabsList>
      <TabsTrigger value="overview">Overview</TabsTrigger>
      <TabsTrigger value="videos">Videos</TabsTrigger>
      <TabsTrigger value="audience">Audience</TabsTrigger>
    </TabsList>
    <TabsContent value="overview" className="text-sm text-muted-foreground">
      Channel views, watch time and subscriber velocity for the last 28 days.
    </TabsContent>
    <TabsContent value="videos" className="text-sm text-muted-foreground">
      Every upload ranked by views against your channel average.
    </TabsContent>
    <TabsContent value="audience" className="text-sm text-muted-foreground">
      Where viewers come from and when they watch.
    </TabsContent>
  </Tabs>
);

export const FullWidth = () => (
  <Tabs defaultValue="tiktok" className="w-full max-w-md">
    <TabsList className="grid w-full grid-cols-3">
      <TabsTrigger value="tiktok">TikTok</TabsTrigger>
      <TabsTrigger value="youtube">YouTube</TabsTrigger>
      <TabsTrigger value="instagram">Instagram</TabsTrigger>
    </TabsList>
    <TabsContent value="tiktok" className="text-sm">
      Post as a video, choose a privacy level, and add trending audio.
    </TabsContent>
    <TabsContent value="youtube" className="text-sm">
      Upload as a Short with a title, description and privacy status.
    </TabsContent>
    <TabsContent value="instagram" className="text-sm">
      Publish a Reel or an image carousel to your connected account.
    </TabsContent>
  </Tabs>
);
