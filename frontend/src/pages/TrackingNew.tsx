import { ArrowLeft } from "lucide-react";
import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { TrackingEventForm } from "@/components/tracking/TrackingEventForm";

const TrackingNew = () => {
  return (
    <div className="space-y-6">
      <div className="flex items-center gap-4">
        <Link to="/dashboard/tracking">
          <Button variant="ghost" size="icon">
            <ArrowLeft className="h-4 w-4" />
          </Button>
        </Link>
        <div>
          <h2 className="text-2xl font-bold text-foreground">Add Event Tracking</h2>
          <p className="text-muted-foreground">Create a new tracking event for a YouTube video</p>
        </div>
      </div>

      <TrackingEventForm />
    </div>
  );
};

export default TrackingNew;
