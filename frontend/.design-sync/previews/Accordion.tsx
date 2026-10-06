import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from "@viewsmax/ui";

export const Faq = () => (
  <Accordion type="single" collapsible defaultValue="item-1" className="w-full max-w-md">
    <AccordionItem value="item-1">
      <AccordionTrigger>What counts as an outlier?</AccordionTrigger>
      <AccordionContent>
        A video that earns at least 3× its channel's median views in the same window. We score it 0 to 100, so a
        4M-view Short on a 12K channel ranks above the same number on a 2M channel.
      </AccordionContent>
    </AccordionItem>
    <AccordionItem value="item-2">
      <AccordionTrigger>Which platforms can I publish to?</AccordionTrigger>
      <AccordionContent>
        YouTube, TikTok, Instagram, X, LinkedIn, Threads and Bluesky. Connect each account once and post to all of
        them from a single composer.
      </AccordionContent>
    </AccordionItem>
    <AccordionItem value="item-3">
      <AccordionTrigger>What happens when my trial ends?</AccordionTrigger>
      <AccordionContent>
        Your connected accounts and saved outliers stay put. Scheduled posts pause until you upgrade to Pro, and you
        can export your library at any time.
      </AccordionContent>
    </AccordionItem>
  </Accordion>
);

export const MultipleOpen = () => (
  <Accordion type="multiple" defaultValue={["youtube", "tiktok"]} className="w-full max-w-md">
    <AccordionItem value="youtube">
      <AccordionTrigger>YouTube publishing</AccordionTrigger>
      <AccordionContent className="text-muted-foreground">
        Uploads as a Short when the video is vertical and under 60 seconds. Default privacy is public.
      </AccordionContent>
    </AccordionItem>
    <AccordionItem value="tiktok">
      <AccordionTrigger>TikTok publishing</AccordionTrigger>
      <AccordionContent className="text-muted-foreground">
        Requires a privacy level on every post. Trending audio can be attached before scheduling.
      </AccordionContent>
    </AccordionItem>
    <AccordionItem value="instagram">
      <AccordionTrigger>Instagram publishing</AccordionTrigger>
      <AccordionContent className="text-muted-foreground">
        Publishes as a Reel or an image carousel. Reconnect is required after Meta app review.
      </AccordionContent>
    </AccordionItem>
  </Accordion>
);
