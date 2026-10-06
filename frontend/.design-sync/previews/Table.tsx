import { Badge, Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from "@viewsmax/ui";

export const Outliers = () => (
  <Table>
    <TableCaption>Top outliers across your connected channels, last 28 days.</TableCaption>
    <TableHeader>
      <TableRow>
        <TableHead>Video</TableHead>
        <TableHead>Platform</TableHead>
        <TableHead className="text-right">Views</TableHead>
        <TableHead className="text-right">vs channel avg</TableHead>
        <TableHead className="text-right">Score</TableHead>
      </TableRow>
    </TableHeader>
    <TableBody>
      <TableRow>
        <TableCell className="font-medium">Cold plunge at 5am for 30 days</TableCell>
        <TableCell>
          <Badge variant="outline">YouTube</Badge>
        </TableCell>
        <TableCell className="text-right tabular-nums">4,120,884</TableCell>
        <TableCell className="text-right tabular-nums text-up">▲ 340×</TableCell>
        <TableCell className="text-right font-medium tabular-nums">94</TableCell>
      </TableRow>
      <TableRow>
        <TableCell className="font-medium">I quit caffeine and tracked my sleep</TableCell>
        <TableCell>
          <Badge variant="outline">TikTok</Badge>
        </TableCell>
        <TableCell className="text-right tabular-nums">1,862,310</TableCell>
        <TableCell className="text-right tabular-nums text-up">▲ 46×</TableCell>
        <TableCell className="text-right font-medium tabular-nums">88</TableCell>
      </TableRow>
      <TableRow>
        <TableCell className="font-medium">Posting every day for a year, results</TableCell>
        <TableCell>
          <Badge variant="outline">Instagram</Badge>
        </TableCell>
        <TableCell className="text-right tabular-nums">912,407</TableCell>
        <TableCell className="text-right tabular-nums text-up">▲ 12×</TableCell>
        <TableCell className="text-right font-medium tabular-nums">71</TableCell>
      </TableRow>
      <TableRow>
        <TableCell className="font-medium">Why your Shorts stop at 300 views</TableCell>
        <TableCell>
          <Badge variant="outline">YouTube</Badge>
        </TableCell>
        <TableCell className="text-right tabular-nums">486,120</TableCell>
        <TableCell className="text-right tabular-nums text-up">▲ 8×</TableCell>
        <TableCell className="text-right font-medium tabular-nums">63</TableCell>
      </TableRow>
      <TableRow>
        <TableCell className="font-medium">Studio tour, finally</TableCell>
        <TableCell>
          <Badge variant="outline">TikTok</Badge>
        </TableCell>
        <TableCell className="text-right tabular-nums">38,904</TableCell>
        <TableCell className="text-right tabular-nums text-down">▼ 0.4×</TableCell>
        <TableCell className="text-right font-medium tabular-nums">21</TableCell>
      </TableRow>
    </TableBody>
  </Table>
);

export const RecentPosts = () => (
  <Table>
    <TableCaption>Posts published in the last 7 days, with views against your previous 7-day average.</TableCaption>
    <TableHeader>
      <TableRow>
        <TableHead>Post</TableHead>
        <TableHead>Platforms</TableHead>
        <TableHead>Status</TableHead>
        <TableHead className="text-right">Views</TableHead>
        <TableHead className="text-right">Change</TableHead>
      </TableRow>
    </TableHeader>
    <TableBody>
      <TableRow>
        <TableCell className="font-medium">3 hooks that doubled my retention</TableCell>
        <TableCell className="text-muted-foreground">YouTube, TikTok</TableCell>
        <TableCell>
          <Badge>Published</Badge>
        </TableCell>
        <TableCell className="text-right tabular-nums">128,440</TableCell>
        <TableCell className="text-right tabular-nums text-up">▲ 18%</TableCell>
      </TableRow>
      <TableRow>
        <TableCell className="font-medium">Behind the thumbnail: outlier #94</TableCell>
        <TableCell className="text-muted-foreground">Instagram</TableCell>
        <TableCell>
          <Badge>Published</Badge>
        </TableCell>
        <TableCell className="text-right tabular-nums">41,208</TableCell>
        <TableCell className="text-right tabular-nums text-down">▼ 7%</TableCell>
      </TableRow>
      <TableRow>
        <TableCell className="font-medium">Weekly channel recap</TableCell>
        <TableCell className="text-muted-foreground">YouTube, X, Threads</TableCell>
        <TableCell>
          <Badge variant="secondary">Scheduled</Badge>
        </TableCell>
        <TableCell className="text-right tabular-nums text-muted-foreground">—</TableCell>
        <TableCell className="text-right tabular-nums text-muted-foreground">—</TableCell>
      </TableRow>
      <TableRow>
        <TableCell className="font-medium">Trending audio test, take two</TableCell>
        <TableCell className="text-muted-foreground">TikTok</TableCell>
        <TableCell>
          <Badge variant="destructive">Failed</Badge>
        </TableCell>
        <TableCell className="text-right tabular-nums text-muted-foreground">—</TableCell>
        <TableCell className="text-right tabular-nums text-muted-foreground">—</TableCell>
      </TableRow>
    </TableBody>
  </Table>
);
