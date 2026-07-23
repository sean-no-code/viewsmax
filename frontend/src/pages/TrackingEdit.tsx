import { useEffect, useState } from "react";
import { useParams, Link } from "react-router-dom";
import { ArrowLeft, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { TrackingEventForm } from "@/components/tracking/TrackingEventForm";
import { viewsMaxApi as apiService, TrackingEvent } from "@/lib/api-service";
import { toast } from "sonner";

const TrackingEdit = () => {
    const { id } = useParams<{ id: string }>();
    const [eventData, setEventData] = useState<TrackingEvent | null>(null);
    const [isLoading, setIsLoading] = useState(true);

    useEffect(() => {
        const fetchEvent = async () => {
            if (!id) return;
            try {
                const response = await apiService.getTrackingEvent(id);
                if (response.success && response.data) {
                    setEventData(response.data);
                } else {
                    toast.error("Failed to load event data");
                }
            } catch (error) {
                console.error("Error fetching event:", error);
                toast.error("An error occurred while fetching event data");
            } finally {
                setIsLoading(false);
            }
        };

        fetchEvent();
    }, [id]);

    if (isLoading) {
        return (
            <div className="flex h-[50vh] items-center justify-center">
                <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
            </div>
        );
    }

    if (!eventData) {
        return (
            <div className="flex h-[50vh] flex-col items-center justify-center gap-4">
                <p className="text-muted-foreground">Event not found</p>
                <Link to="/dashboard/tracking">
                    <Button>Back to Events</Button>
                </Link>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div className="flex items-center gap-4">
                <Link to="/dashboard/tracking">
                    <Button variant="ghost" size="icon">
                        <ArrowLeft className="h-4 w-4" />
                    </Button>
                </Link>
                <div>
                    <h2 className="text-2xl font-bold text-foreground">Edit Tracking Event</h2>
                    <p className="text-muted-foreground">Manage your tracking campaign and links</p>
                </div>
            </div>

            <TrackingEventForm initialData={eventData} isEdit={true} />
        </div>
    );
};

export default TrackingEdit;
