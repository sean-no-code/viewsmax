import {
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
  Input,
  Label,
} from "@viewsmax/ui";
import { CalendarClock } from "lucide-react";

export const Open = () => (
  <Dialog open>
    <DialogTrigger asChild>
      <Button>
        <CalendarClock className="mr-2 h-4 w-4" /> Schedule post
      </Button>
    </DialogTrigger>
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Schedule to TikTok and YouTube</DialogTitle>
        <DialogDescription>
          Pick a publish time. We post to every selected account at once and report back per platform.
        </DialogDescription>
      </DialogHeader>
      <div className="grid gap-4 py-2">
        <div className="grid gap-2">
          <Label htmlFor="publish-date">Publish date</Label>
          <Input id="publish-date" type="date" defaultValue="2026-10-08" />
        </div>
        <div className="grid gap-2">
          <Label htmlFor="publish-time">Time</Label>
          <Input id="publish-time" type="time" defaultValue="17:30" />
        </div>
      </div>
      <DialogFooter>
        <Button variant="outline">Cancel</Button>
        <Button>Schedule</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
);

export const Closed = () => (
  <Dialog>
    <DialogTrigger asChild>
      <Button variant="outline">Rotate API key</Button>
    </DialogTrigger>
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Rotate your API key?</DialogTitle>
        <DialogDescription>
          Your current key stops working immediately. Any AI assistant using it will need the new key to keep
          posting.
        </DialogDescription>
      </DialogHeader>
      <DialogFooter>
        <Button variant="outline">Cancel</Button>
        <Button variant="destructive">Rotate key</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
);
