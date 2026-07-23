import { useState, useMemo, useEffect } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Calendar } from "@/components/ui/calendar";
import { format } from "date-fns";
import { CalendarIcon, Plus, Eye, Phone, Mail, DollarSign, Youtube, Music2, Twitter, Linkedin, Instagram, Mic, Globe, Megaphone, ArrowUpDown, ArrowUp, ArrowDown } from "lucide-react";
import { Link } from "react-router-dom";
import { cn } from "@/lib/utils";
import { EventsList } from "@/components/tracking/EventsList";
import { ScriptModal } from "@/components/tracking/ScriptModal";
import { viewsMaxApi, TrackingEvent } from "@/lib/api-service";
import { toast } from "sonner";

const Tracking = () => {
  const [events, setEvents] = useState<TrackingEvent[]>([]);
  const [loading, setLoading] = useState(true);
  const [dateRange, setDateRange] = useState<{ from: Date | undefined; to: Date | undefined }>({
    from: undefined,
    to: undefined,
  });
  const [isCalendarOpen, setIsCalendarOpen] = useState(false);

  // filteredEvents is just same as events now that we filter on backend
  const filteredEvents = events;

  const [sortConfig, setSortConfig] = useState<{ key: string; direction: 'asc' | 'desc' } | null>(null);

  const requestSort = (key: string) => {
    let direction: 'asc' | 'desc' = 'asc';
    if (sortConfig && sortConfig.key === key && sortConfig.direction === 'asc') {
      direction = 'desc';
    }
    setSortConfig({ key, direction });
  };

  const getSortIcon = (key: string) => {
    if (!sortConfig || sortConfig.key !== key) {
      return <ArrowUpDown className="ml-2 h-4 w-4 opacity-50" />;
    }
    return sortConfig.direction === 'asc' ? <ArrowUp className="ml-2 h-4 w-4" /> : <ArrowDown className="ml-2 h-4 w-4" />;
  };

  const allLinks = filteredEvents.flatMap((e: any) => e.links || []);

  const sortedLinks = useMemo(() => {
    let sortableItems = [...allLinks];
    if (sortConfig !== null) {
      sortableItems.sort((a, b) => {
        let aValue: any;
        let bValue: any;

        switch (sortConfig.key) {
          case 'source':
            const aIsVideo = a.placement === 'video';
            aValue = aIsVideo ? (a.video?.title || a.name || "") : (a.placement === 'other' ? a.description : a.placement);
            const bIsVideo = b.placement === 'video';
            bValue = bIsVideo ? (b.video?.title || b.name || "") : (b.placement === 'other' ? b.description : b.placement);
            break;
          case 'date':
            aValue = new Date(a.created_at).getTime();
            bValue = new Date(b.created_at).getTime();
            break;
          case 'views':
            aValue = a.views || 0;
            bValue = b.views || 0;
            break;
          case 'ctr':
            aValue = a.views > 0 ? (a.clicks_count || 0) / a.views : 0;
            bValue = b.views > 0 ? (b.clicks_count || 0) / b.views : 0;
            break;
          case 'clicks':
            aValue = a.clicks_count || 0;
            bValue = b.clicks_count || 0;
            break;
          case 'calls':
            aValue = a.calls_booked_count || 0;
            bValue = b.calls_booked_count || 0;
            break;
          case 'emails':
            aValue = a.email_signups_count || 0;
            bValue = b.email_signups_count || 0;
            break;
          case 'sales':
            aValue = a.sales_amount || 0;
            bValue = b.sales_amount || 0;
            break;
          default:
            aValue = 0;
            bValue = 0;
        }

        if (aValue < bValue) {
          return sortConfig.direction === 'asc' ? -1 : 1;
        }
        if (aValue > bValue) {
          return sortConfig.direction === 'asc' ? 1 : -1;
        }
        return 0;
      });
    } else {
      // default sorting by date desc
      sortableItems.sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime());
    }
    return sortableItems;
  }, [allLinks, sortConfig]);

  const fetchEvents = async () => {
    setLoading(true);
    const res = await viewsMaxApi.getTrackingEvents(dateRange.from || dateRange.to ? {
      from: dateRange.from,
      to: dateRange.to
    } : undefined);
    if (res.success && res.data) {
      setEvents(res.data);
    } else {
      toast.error(res.error || "Failed to load events");
    }
    setLoading(false);
  };

  useEffect(() => {
    fetchEvents();
  }, [dateRange]);

  const handleDelete = async (id: number) => {
    const res = await viewsMaxApi.deleteTrackingEvent(id);
    if (res.success) {
      toast.success("Event deleted");
      fetchEvents();
    } else {
      toast.error(res.error);
    }
  };

  const [stats, setStats] = useState({
    views: 0,
    clicks: 0,
    callsBooked: 0,
    emailSignups: 0,
    sales: 0
  });

  const fetchStats = async () => {
    const res = await viewsMaxApi.getTrackingStats(dateRange.from || dateRange.to ? {
      from: dateRange.from,
      to: dateRange.to
    } : undefined);

    if (res.success && res.data) {
      setStats(res.data);
    }
  };

  useEffect(() => {
    fetchStats();
  }, [dateRange]); // Refetch stats when date range changes

  // Use backend stats instead of local calculation
  const totals = stats;

  const clearDateFilter = () => {
    setDateRange({ from: undefined, to: undefined });
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-foreground">Event Tracking</h2>
          <p className="text-muted-foreground">Track performance metrics for your YouTube videos</p>
        </div>
        <div className="flex gap-2">
          <ScriptModal />
          <Link to="/dashboard/tracking/new">
            <Button>
              <Plus className="mr-2 h-4 w-4" />
              Add Event Tracking
            </Button>
          </Link>
        </div>
      </div>

      {/* Total Widgets */}
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">Total Views</CardTitle>
            <Eye className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold text-foreground">{totals.views.toLocaleString()}</div>
            <p className="text-xs text-muted-foreground mt-1">
              {filteredEvents.length} {filteredEvents.length === 1 ? "event" : "events"}
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">Calls Booked</CardTitle>
            <Phone className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold text-foreground">{totals.callsBooked.toLocaleString()}</div>
            <p className="text-xs text-muted-foreground mt-1">
              {filteredEvents.length > 0
                ? (totals.callsBooked / filteredEvents.length).toFixed(1)
                : "0"}{" "}
              avg per event
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">Email Signups</CardTitle>
            <Mail className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold text-foreground">{totals.emailSignups.toLocaleString()}</div>
            <p className="text-xs text-muted-foreground mt-1">
              {filteredEvents.length > 0
                ? (totals.emailSignups / filteredEvents.length).toFixed(1)
                : "0"}{" "}
              avg per event
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">Total Sales</CardTitle>
            <DollarSign className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold text-foreground">${totals.sales.toLocaleString()}</div>
            <p className="text-xs text-muted-foreground mt-1">
              $
              {filteredEvents.length > 0
                ? (totals.sales / filteredEvents.length).toLocaleString(undefined, {
                  minimumFractionDigits: 0,
                  maximumFractionDigits: 0,
                })
                : "0"}{" "}
              avg per event
            </p>
          </CardContent>
        </Card>
      </div>

      {/* Date Range Filter */}
      <Card>
        <CardHeader>
          <CardTitle>Filter by Date Range</CardTitle>
          <CardDescription>Select a date range to filter tracking events</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex items-center gap-4">
            <Popover open={isCalendarOpen} onOpenChange={setIsCalendarOpen}>
              <PopoverTrigger asChild>
                <Button
                  id="date"
                  variant={"outline"}
                  className={cn(
                    "w-[300px] justify-start text-left font-normal",
                    !dateRange.from && !dateRange.to && "text-muted-foreground"
                  )}
                >
                  <CalendarIcon className="mr-2 h-4 w-4" />
                  {dateRange.from ? (
                    dateRange.to ? (
                      <>
                        {format(dateRange.from, "LLL dd, y")} - {format(dateRange.to, "LLL dd, y")}
                      </>
                    ) : (
                      format(dateRange.from, "LLL dd, y")
                    )
                  ) : (
                    <span>Pick a date range</span>
                  )}
                </Button>
              </PopoverTrigger>
              <PopoverContent className="w-auto p-0" align="start">
                <Calendar
                  initialFocus
                  mode="range"
                  defaultMonth={dateRange.from}
                  selected={{
                    from: dateRange.from,
                    to: dateRange.to,
                  }}
                  onSelect={(range) => {
                    setDateRange({
                      from: range?.from,
                      to: range?.to,
                    });
                    if (range?.from && range?.to) {
                      setIsCalendarOpen(false);
                    }
                  }}
                  numberOfMonths={2}
                />
              </PopoverContent>
            </Popover>
            {(dateRange.from || dateRange.to) && (
              <Button variant="outline" onClick={clearDateFilter}>
                Clear Filter
              </Button>
            )}
          </div>
        </CardContent>
      </Card>

      {/* Events Table */}
      <Card>
        <CardHeader>
          <CardTitle>Tracking Events</CardTitle>
          <CardDescription>
            {filteredEvents.reduce((acc, e) => acc + (e.links?.length || 0), 0)} events found
          </CardDescription>
        </CardHeader>
        <CardContent>
          {filteredEvents.length > 0 ? (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="cursor-pointer hover:bg-muted/50 transition-colors" onClick={() => requestSort('source')}>
                    <div className="flex items-center">Source {getSortIcon('source')}</div>
                  </TableHead>
                  <TableHead className="cursor-pointer hover:bg-muted/50 transition-colors" onClick={() => requestSort('date')}>
                    <div className="flex items-center">Created Date {getSortIcon('date')}</div>
                  </TableHead>
                  <TableHead className="text-right cursor-pointer hover:bg-muted/50 transition-colors" onClick={() => requestSort('views')}>
                    <div className="flex justify-end items-center">Views {getSortIcon('views')}</div>
                  </TableHead>
                  <TableHead className="text-right cursor-pointer hover:bg-muted/50 transition-colors" onClick={() => requestSort('ctr')}>
                    <div className="flex justify-end items-center">CTR {getSortIcon('ctr')}</div>
                  </TableHead>
                  <TableHead className="text-right cursor-pointer hover:bg-muted/50 transition-colors" onClick={() => requestSort('clicks')}>
                    <div className="flex justify-end items-center">Clicks {getSortIcon('clicks')}</div>
                  </TableHead>
                  <TableHead className="text-right cursor-pointer hover:bg-muted/50 transition-colors" onClick={() => requestSort('calls')}>
                    <div className="flex justify-end items-center">Calls Booked {getSortIcon('calls')}</div>
                  </TableHead>
                  <TableHead className="text-right cursor-pointer hover:bg-muted/50 transition-colors" onClick={() => requestSort('emails')}>
                    <div className="flex justify-end items-center">Email Signups {getSortIcon('emails')}</div>
                  </TableHead>
                  <TableHead className="text-right cursor-pointer hover:bg-muted/50 transition-colors" onClick={() => requestSort('sales')}>
                    <div className="flex justify-end items-center">Sales {getSortIcon('sales')}</div>
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {sortedLinks.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={8} className="text-center py-8 text-muted-foreground">
                      No data available.
                    </TableCell>
                  </TableRow>
                ) : (
                  sortedLinks.map((link: any) => {
                    const renderSourceCell = () => {
                      const iconSize = "h-4 w-4";
                      const isVideo = link.placement === 'video';
                      const videoUrl = link.video?.youtube_video_id ? `https://www.youtube.com/watch?v=${link.video.youtube_video_id}` : null;

                      const getIcon = () => {
                        switch (link.placement) {
                          case 'video': return <Youtube className={`${iconSize} text-red-600`} />;
                          case 'email': return <Mail className={`${iconSize} text-blue-500`} />;
                          case 'tiktok': return <Music2 className={`${iconSize} text-pink-500`} />;
                          case 'x':
                          case 'twitter': return <Twitter className={`${iconSize} text-sky-500`} />;
                          case 'linkedin': return <Linkedin className={`${iconSize} text-blue-700`} />;
                          case 'instagram': return <Instagram className={`${iconSize} text-purple-500`} />;
                          case 'podcast': return <Mic className={`${iconSize} text-orange-500`} />;
                          case 'website':
                          case 'blog': return <Globe className={`${iconSize} text-slate-500`} />;
                          case 'ad': return <Megaphone className={`${iconSize} text-amber-500`} />;
                          default: return <Eye className={`${iconSize} text-muted-foreground`} />;
                        }
                      };

                      const displayName = isVideo
                        ? (link.video?.title || link.name || "Untitled Video")
                        : (link.placement === 'other' && link.description
                          ? `Other: ${link.description}`
                          : link.placement.charAt(0).toUpperCase() + link.placement.slice(1));

                      const landingPageUrl = filteredEvents.find((e: any) => e.id === link.tracking_event_id)?.offer_url;

                      return (
                        <div className="flex items-center gap-2">
                          {getIcon()}
                          <div className="flex flex-col">
                            {isVideo && videoUrl ? (
                              <a
                                href={videoUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="font-medium text-sm text-primary hover:underline truncate max-w-[300px] block"
                              >
                                {displayName}
                              </a>
                            ) : (
                              <span className="font-medium text-sm block truncate max-w-[300px]" title={displayName}>{displayName}</span>
                            )}
                            <a
                              href={landingPageUrl}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="text-xs text-muted-foreground hover:underline truncate max-w-[300px] block"
                            >
                              {landingPageUrl}
                            </a>
                          </div>
                        </div>
                      );
                    };

                    return (
                      <TableRow key={link.id}>
                        <TableCell>
                          {renderSourceCell()}
                        </TableCell>
                        <TableCell>{format(new Date(link.created_at), "LLL dd, y")}</TableCell>
                        <TableCell className="text-right">{(link.views || 0).toLocaleString()}</TableCell>
                        <TableCell className="text-right">
                          {(() => {
                            const views = link.views || 0;
                            const clicks = link.clicks_count || 0;
                            const ctr = views > 0 ? ((clicks / views) * 100).toFixed(2) : "0.00";
                            return `${ctr}%`;
                          })()}
                        </TableCell>
                        <TableCell className="text-right">{(link.clicks_count || 0).toLocaleString()}</TableCell>
                        <TableCell className="text-right">{(link.calls_booked_count || 0).toLocaleString()}</TableCell>
                        <TableCell className="text-right">{(link.email_signups_count || 0).toLocaleString()}</TableCell>
                        <TableCell className="text-right">${(link.sales_amount || 0).toLocaleString()}</TableCell>
                      </TableRow>
                    );
                  })
                )}
              </TableBody>
            </Table>
          ) : (
            <div className="flex flex-col items-center justify-center py-12 text-center">
              <CalendarIcon className="h-12 w-12 text-muted-foreground mb-4 opacity-50" />
              <h3 className="text-lg font-semibold text-foreground mb-2">No events found</h3>
              <p className="text-sm text-muted-foreground mb-4">
                {dateRange.from || dateRange.to
                  ? "Try adjusting your date range filter"
                  : "Get started by adding your first tracking event"}
              </p>
              {!(dateRange.from || dateRange.to) && (
                <Link to="/dashboard/tracking/new">
                  <Button>
                    <Plus className="mr-2 h-4 w-4" />
                    Add Event Tracking
                  </Button>
                </Link>
              )}
            </div>
          )}
        </CardContent>
      </Card>

      <EventsList events={filteredEvents} loading={loading} onDelete={handleDelete} />
    </div>
  );
};

export default Tracking;






