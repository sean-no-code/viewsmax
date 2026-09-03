import { useState, useEffect } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { Plus, Trash2, Link as LinkIcon, Loader2 } from "lucide-react";
import { Link, useNavigate } from "react-router-dom";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { viewsMaxApi as apiService, TrackingEvent, TrackingGoal, TrackingLink } from "@/lib/api-service";
import { useAuth } from "@/hooks/useAuth";

interface EventRow {
    id: string; // frontend temp id or backend id
    type: string;
    url: string;
    conversionValue: string;
    backendId?: number; // if saved
    customMode?: boolean; // true while "Custom" is selected and the user names their own event
}

interface LinkRow {
    id: string;
    placement: string;
    videoId?: string;
    generatedLink?: string;
    isGenerating?: boolean;
    name?: string; // Optional name for the link
    description?: string; // Added description
    backendId?: number; // if saved
}

// Built-in goal types. "custom" is a sentinel: picking it reveals a free-text
// field where the user names their own event (stored verbatim as the type).
const EVENT_TYPE_OPTIONS = ["conversion", "call booked", "email-signup", "newsletter", "trial", "custom"];
const BUILTIN_EVENT_TYPES = new Set(["conversion", "call booked", "email-signup", "newsletter", "trial"]);

const PLACEMENT_OPTIONS = [
    { label: "Video", value: "video" },
    { label: "Email", value: "email" },
    { label: "X", value: "x" },
    { label: "LinkedIn", value: "linkedin" },
    { label: "Podcast", value: "podcast" },
    { label: "Blog", value: "blog" },
    { label: "Website", value: "website" },
    { label: "TikTok", value: "tiktok" },
    { label: "Ad", value: "ad" },
    { label: "Instagram", value: "instagram" },
    { label: "Other", value: "other" },
];

interface TrackingEventFormProps {
    initialData?: TrackingEvent | null;
    isEdit?: boolean;
}

export function TrackingEventForm({ initialData, isEdit = false }: TrackingEventFormProps) {
    const navigate = useNavigate();
    const { user } = useAuth();
    const [eventId, setEventId] = useState<number | null>(initialData?.id || null);

    const [eventName, setEventName] = useState(initialData?.name || "");
    const [landingPage, setLandingPage] = useState(initialData?.offer_url || "");
    const [landingPageCustom, setLandingPageCustom] = useState(
        initialData?.offer_url || ""
    );
    const [conversionValue, setConversionValue] = useState(initialData?.conversion_value || "0.00");

    // Initialize Goals (Event Types)
    const [eventRows, setEventRows] = useState<EventRow[]>(
        initialData?.goals?.map(g => ({
            id: g.id.toString(),
            type: g.event_type,
            url: g.conversion_url,
            conversionValue: (g.conversion_value || 0).toString(),
            backendId: g.id
        })) || [
            { id: "1", type: "", url: "", conversionValue: "0.00" } // Default empty row
        ]
    );

    // Initialize Links
    const [linkRows, setLinkRows] = useState<LinkRow[]>(
        initialData?.links?.map(l => ({
            id: l.id.toString(),
            placement: 'video', // Force video
            videoId: l.youtube_video_id || l.video_id?.toString(), // Use youtube_video_id if available, fallback for safety
            generatedLink: `${initialData.offer_url}${initialData.offer_url.includes('?') ? '&' : '?'}trk=${l.parameter_id}`,
            backendId: l.id,
            name: l.name || ""
        })) || [
            { id: "1", placement: "video" }
        ]
    );

    const [videos, setVideos] = useState<Array<{ id: string; title: string }>>([]);
    const [isLoadingVideos, setIsLoadingVideos] = useState(false);
    const [isRefreshingVideos, setIsRefreshingVideos] = useState(false);
    const [isSaving, setIsSaving] = useState(false);

    const [landingPageOptions, setLandingPageOptions] = useState<string[]>([]);

    useEffect(() => {
        if (initialData) {
            setEventId(initialData.id);
            setEventName(initialData.name || "");
            setLandingPage(initialData.offer_url || "");
            setLandingPageCustom(initialData.offer_url || "");
            setConversionValue(initialData.conversion_value || "0.00");

            if (initialData.goals) {
                setEventRows(initialData.goals.map(g => ({
                    id: g.id.toString(),
                    type: g.event_type,
                    url: g.conversion_url,
                    conversionValue: (g.conversion_value || 0).toString(),
                    backendId: g.id
                })));
            }

            if (initialData.links) {
                setLinkRows(initialData.links.map(l => ({
                    id: l.id.toString(),
                    placement: 'video',
                    videoId: l.youtube_video_id || l.video_id?.toString(),
                    generatedLink: `${initialData.offer_url}${initialData.offer_url.includes('?') ? '&' : '?'}trk=${l.parameter_id}`,
                    backendId: l.id,
                    name: l.name || "",
                    description: l.description || ""
                })));
            }
        }
    }, [initialData]);

    // Load initial data
    useEffect(() => {
        const loadInitialData = async () => {
            // 1. Load Landing Pages
            try {
                const res = await apiService.getOffers();
                if (res.success && res.data) {
                    const uniqueOptions = Array.from(new Set([...res.data, initialData?.offer_url].filter(Boolean) as string[]));
                    setLandingPageOptions(uniqueOptions);
                }
            } catch (error) {
                console.error("Failed to load landing pages", error);
            }
        };
        loadInitialData();
    }, []);

    // Sync landing page select state when options are loaded
    useEffect(() => {
        if (landingPageOptions.length > 0 && landingPage && landingPageOptions.includes(landingPage)) {
            // If the current landing page is in the options, strictly clear custom input
            // to force the Select to match the option value.
            setLandingPageCustom("");
        }
    }, [landingPageOptions, landingPage]);

    // Ensure initial landing page is in options when data loads
    useEffect(() => {
        if (initialData?.offer_url && !landingPageOptions.includes(initialData.offer_url)) {
            setLandingPageOptions(prev => {
                if (prev.includes(initialData.offer_url!)) return prev;
                return [...prev, initialData.offer_url!];
            });
        }
    }, [initialData?.offer_url]);

    // Load videos across ALL connected channels. getChannelVideos is a pure DB
    // read; pass { refresh: true } to first import the newest uploads from the
    // YouTube API so a video published just now becomes selectable.
    const loadVideos = async (opts?: { refresh?: boolean }) => {
        const token = localStorage.getItem('auth_session') ? JSON.parse(localStorage.getItem('auth_session')!).token : '';
        if (!user || !token) return;
        if (opts?.refresh) setIsRefreshingVideos(true); else setIsLoadingVideos(true);
        try {
            const channels = await apiService.getChannels(token);
            if (!channels.success || !channels.data || channels.data.length === 0) {
                setVideos([]);
                return;
            }
            if (opts?.refresh) {
                await Promise.all(channels.data.map(c => apiService.fetchChannelVideosFromYouTube(token, c.id)));
            }
            const lists = await Promise.all(channels.data.map(c => apiService.getChannelVideos(token, c.id, 100)));
            const merged = new Map<string, { id: string; title: string }>();
            for (const l of lists) {
                if (l.success && l.data) {
                    for (const v of l.data.videos) merged.set(v.youtube_video_id, { id: v.youtube_video_id, title: v.title });
                }
            }
            setVideos([...merged.values()]);
            if (opts?.refresh) toast.success("Pulled your latest videos from YouTube.");
        } catch (error) {
            console.error("Failed to load videos:", error);
            if (opts?.refresh) toast.error("Couldn't refresh videos from YouTube.");
        } finally {
            if (opts?.refresh) setIsRefreshingVideos(false); else setIsLoadingVideos(false);
        }
    };

    useEffect(() => {
        loadVideos();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [user]);

    // -- Event Row Management --
    const addEventRow = () => {
        setEventRows([...eventRows, { id: Date.now().toString(), type: "", url: "", conversionValue: "0.00" }]);
    };

    const removeEventRow = (id: string) => {
        if (eventRows.length > 1) {
            setEventRows(eventRows.filter((row) => row.id !== id));
        } else {
            toast.error("At least one event goal is required");
        }
    };

    const updateEventRow = (id: string, updates: Partial<EventRow>) => {
        setEventRows(eventRows.map((row) => (row.id === id ? { ...row, ...updates } : row)));
    };

    // -- Link Row Management --
    const addLinkRow = () => {
        setLinkRows([...linkRows, { id: Date.now().toString(), placement: "video" }]);
    };

    const removeLinkRow = (id: string) => {
        if (linkRows.length > 1) {
            setLinkRows(linkRows.filter((row) => row.id !== id));
        } else {
            toast.error("At least one tracking link is required");
        }
    };

    const updateLinkRow = (id: string, updates: Partial<LinkRow>) => {
        setLinkRows(linkRows.map((row) => (row.id === id ? { ...row, ...updates } : row)));
    };

    // -- Logic --

    const getFormData = () => {
        const finalLandingPage = landingPage === "other" ? landingPageCustom : (landingPage || landingPageCustom);

        return {
            name: eventName || `Campaign ${new Date().toLocaleDateString()}`,
            offer_url: finalLandingPage,
            conversion_value: conversionValue,
            goals: eventRows.map(r => ({
                event_type: r.type,
                conversion_url: r.url,
                conversion_value: r.conversionValue
            })),
            links: linkRows.map(l => ({
                id: l.backendId, // pass ID if updating specific link?
                placement: 'video',
                youtube_video_id: l.videoId, // map videoId (state) to youtube_video_id (backend)
                name: l.name,
                description: l.description
            })).filter(l => l.youtube_video_id) // Only send valid links
        };
    };

    const isFormValid = () => {
        const finalLandingPage = landingPage === "other" ? landingPageCustom : (landingPage || landingPageCustom);
        if (!finalLandingPage?.trim()) return false;
        if (eventRows.length === 0) return false;
        const hasValidLink = linkRows.some(row =>
            row.placement && (row.placement !== 'video' || row.videoId)
        );
        if (!hasValidLink) return false;
        return true;
    };

    const saveEvent = async (isDraft = false) => {
        setIsSaving(true);
        try {
            const payload = getFormData();
            let result: any;
            if (eventId) {
                result = await apiService.updateTrackingEvent(eventId, payload as any);
            } else {
                result = await apiService.createTrackingEvent(payload as any);
            }

            if (result.success && result.data) {
                setEventId(result.data.id);
                if (!isDraft) {
                    toast.success(isEdit ? "Event updated successfully" : "Event created successfully");
                    navigate("/dashboard/tracking");
                }
                return result.data;
            } else {
                toast.error(result.error || "Failed to save event");
                return null;
            }
        } catch (e) {
            toast.error("An unexpected error occurred");
            return null;
        } finally {
            setIsSaving(false);
        }
    };

    const handleGenerateUTM = async (row: LinkRow) => {
        if (!row.placement) {
            toast.error("Please select a placement first");
            return;
        }
        if (row.placement === "video" && !row.videoId) {
            toast.error("Please select a video");
            return;
        }

        updateLinkRow(row.id, { isGenerating: true });

        // 1. Ensure Event Draft/Record exists
        let currentEventId = eventId;
        if (!currentEventId) {
            // Create Draft
            const savedEvent = await saveEvent(true);
            if (!savedEvent) {
                updateLinkRow(row.id, { isGenerating: false });
                return;
            }
            currentEventId = savedEvent.id;
        }

        // 2. Generate Link
        try {
            const linkPayload = {
                tracking_event_id: currentEventId!,
                placement: 'video',
                youtube_video_id: row.videoId, // this is now the youtube_video_id string
                description: row.description,
            };

            const res = await apiService.generateTrackingLink(linkPayload);

            if (res.success && res.data) {
                // Check what data allows: result.data.link or construct it?
                // Assuming result.data has { link: "..." } or similar
                // If backend returns the created Link object:
                const linkObj: any = res.data;
                // If backend returns standard link object:
                const paramId = linkObj.parameter_id;
                const finalLandingPage = landingPage === "other" ? landingPageCustom : (landingPage || landingPageCustom);
                const separator = finalLandingPage.includes('?') ? '&' : '?';
                const generatedUrl = `${finalLandingPage}${separator}trk=${paramId}`;

                updateLinkRow(row.id, {
                    generatedLink: generatedUrl,
                    isGenerating: false,
                    backendId: linkObj.id
                });
                toast.success("UTM link generated successfully");
            } else {
                toast.error(res.error || "Failed to generate link");
                updateLinkRow(row.id, { isGenerating: false });
            }

        } catch (error) {
            console.error(error);
            toast.error("Failed to generate link");
            updateLinkRow(row.id, { isGenerating: false });
        }
    };

    // ...

    const handleLandingPageChange = (value: string) => {
        setLandingPage(value);
        if (value !== "other") {
            setLandingPageCustom("");
        }
    };

    return (
        <form onSubmit={(e) => { e.preventDefault(); saveEvent(); }} className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>Event Details</CardTitle>
                    <CardDescription>Enter the basic information for this tracking event</CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">


                    {/* Landing Page */}
                    <div className="space-y-2">
                        <Label htmlFor="landing-page">
                            Offer URL <span className="text-destructive">*</span>
                        </Label>
                        <Select
                            value={landingPage}
                            onValueChange={handleLandingPageChange}
                        >
                            <SelectTrigger id="landing-page" className={cn(!landingPage ? "border-red-500" : "")}>
                                <SelectValue placeholder="Select or enter offer URL" />
                            </SelectTrigger>
                            <SelectContent>
                                {landingPageOptions.map((url) => (
                                    <SelectItem key={url} value={url}>{url}</SelectItem>
                                ))}
                                <SelectItem value="other">Other (Enter custom URL)</SelectItem>
                            </SelectContent>
                        </Select>
                        {(landingPage === "other" || (landingPageCustom && !landingPageOptions.includes(landingPageCustom))) && (
                            <Input
                                placeholder="Enter offer URL"
                                value={landingPageCustom}
                                onChange={(e) => { setLandingPageCustom(e.target.value); setLandingPage("other"); }}
                                className={cn("mt-2", !landingPageCustom.trim() ? "border-red-500" : "")}
                            />
                        )}
                    </div>
                    {/* Global Conversion Value Removed */}
                </CardContent>
            </Card>

            {/* Goals Section */}
            <Card>
                <CardHeader>
                    <CardTitle>Events</CardTitle>
                    <CardDescription>Configure event tracking URLs</CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="space-y-4">
                        {/* Header Row */}
                        <div className="grid grid-cols-12 gap-4 pb-2 border-b font-medium text-sm text-muted-foreground">
                            <div className="col-span-3">Type</div>
                            <div className="col-span-5">URL</div>
                            <div className="col-span-3">Value ($)</div>
                            <div className="col-span-1">Action</div>
                        </div>

                        {eventRows.map((row) => (
                            <div key={row.id} className="grid grid-cols-12 gap-4 items-start">
                                <div className="col-span-3 space-y-2">
                                    {(() => {
                                        const isCustom = row.customMode || (!!row.type && !BUILTIN_EVENT_TYPES.has(row.type));
                                        return (
                                            <>
                                                <Select
                                                    value={isCustom ? "custom" : (row.type || undefined)}
                                                    onValueChange={(value) =>
                                                        updateEventRow(row.id, value === "custom"
                                                            ? { type: "", customMode: true }
                                                            : { type: value, customMode: false })
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Select type" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {EVENT_TYPE_OPTIONS.map((option) => (
                                                            <SelectItem key={option} value={option}>
                                                                {option.charAt(0).toUpperCase() + option.slice(1)}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                {isCustom && (
                                                    <Input
                                                        placeholder="Custom event name"
                                                        value={row.type}
                                                        onChange={(e) => updateEventRow(row.id, { type: e.target.value })}
                                                    />
                                                )}
                                            </>
                                        );
                                    })()}
                                </div>
                                <div className="col-span-5">
                                    <Input
                                        placeholder="Enter URL"
                                        value={row.url}
                                        onChange={(e) => updateEventRow(row.id, { url: e.target.value })}
                                    />
                                </div>
                                <div className="col-span-3">
                                    <Input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        placeholder="0.00"
                                        value={row.conversionValue}
                                        onChange={(e) => updateEventRow(row.id, { conversionValue: e.target.value })}
                                    />
                                </div>
                                <div className="col-span-1">
                                    {eventRows.length > 1 && (
                                        <Button type="button" variant="ghost" size="icon" onClick={() => removeEventRow(row.id)}>
                                            <Trash2 className="h-4 w-4 text-destructive" />
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ))}
                        <Button type="button" variant="outline" onClick={addEventRow} className="w-full">
                            <Plus className="mr-2 h-4 w-4" /> Add Event Row
                        </Button>
                    </div>
                </CardContent>
            </Card>

            {/* Links Section */}
            <Card>
                <CardHeader>
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <CardTitle>Links</CardTitle>
                            <CardDescription>Configure tracking links for different placements</CardDescription>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => loadVideos({ refresh: true })}
                            disabled={isRefreshingVideos || isLoadingVideos}
                            title="Fetch your newest uploads from YouTube"
                        >
                            <Loader2 className={cn("h-4 w-4", isRefreshingVideos ? "animate-spin" : "hidden")} />
                            {isRefreshingVideos ? "Refreshing…" : "Refresh from YouTube"}
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <div className="space-y-4">
                        {/* Headers similar to Goals but handling generated link display */}
                        <div className="grid grid-cols-12 gap-4 pb-2 border-b font-medium text-sm text-muted-foreground">
                            <div className="col-span-4">Video</div>
                            <div className="col-span-6">Link</div>
                            <div className="col-span-2">Action</div>
                        </div>

                        {linkRows.map((row) => (
                            <div key={row.id} className="grid grid-cols-12 gap-4 items-start">
                                <div className="col-span-4 space-y-2">
                                    <Select
                                        value={row.videoId || ""}
                                        onValueChange={v => updateLinkRow(row.id, { videoId: v, placement: 'video' })}
                                        disabled={isLoadingVideos}
                                    >
                                        <SelectTrigger><SelectValue placeholder={isLoadingVideos ? "Loading videos..." : "Select video"} /></SelectTrigger>
                                        <SelectContent>
                                            {videos.map(v => <SelectItem key={v.id} value={v.id}>{v.title}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </div>
                                {/* Generated Link - Modified to match original column span of 6 */}
                                <div className="col-span-6 flex items-center">
                                    {row.generatedLink ? (
                                        <div className="flex items-center gap-2 w-full">
                                            <Input
                                                readOnly
                                                value={row.generatedLink}
                                                className="font-mono text-xs"
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="icon"
                                                onClick={() => {
                                                    navigator.clipboard.writeText(row.generatedLink!);
                                                    toast.success("Link copied to clipboard");
                                                }}
                                            >
                                                <LinkIcon className="h-4 w-4" />
                                            </Button>
                                        </div>
                                    ) : (
                                        <span className="text-sm text-muted-foreground">No link generated yet</span>
                                    )}
                                </div>

                                {/* Action - Modified to match original column span of 2 */}
                                <div className="col-span-2 flex items-center gap-2">
                                    <div className="flex-1" title={row.generatedLink ? "Link already generated" : ""}>
                                        <Button
                                            type="button"
                                            size="sm"
                                            className="w-full"
                                            disabled={row.isGenerating || !row.videoId || !!row.generatedLink}
                                            onClick={() => handleGenerateUTM(row)}
                                        >
                                            {row.isGenerating ? (
                                                <>
                                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                                    Generating...
                                                </>
                                            ) : (
                                                row.generatedLink ? "UTM Link Generated" : "Generate UTM link"
                                            )}
                                        </Button>
                                    </div>
                                    {linkRows.length > 1 && (
                                        <Button type="button" size="icon" variant="ghost" onClick={() => removeLinkRow(row.id)}>
                                            <Trash2 className="h-4 w-4 text-destructive" />
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ))}
                        <Button type="button" variant="outline" onClick={addLinkRow} className="w-full">
                            <Plus className="mr-2 h-4 w-4" /> Add Link Row
                        </Button>
                    </div>
                </CardContent>
            </Card>

            {/* Footer */}
            <div className="flex justify-end gap-4">
                <Button type="button" variant="outline" onClick={() => navigate("/dashboard/tracking")}>Cancel</Button>
                <Button type="submit" disabled={isSaving || (!isEdit && !isFormValid())}>
                    {isSaving ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : (isEdit ? "Update tracking event" : "Create tracking event")}
                </Button>
            </div>
        </form>
    );
}
