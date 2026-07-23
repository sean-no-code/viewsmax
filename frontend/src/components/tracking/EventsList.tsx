import React, { useEffect, useState } from "react";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { viewsMaxApi, TrackingEvent } from "@/lib/api-service";
import { toast } from "sonner";
import { Loader2, Trash2, Edit } from "lucide-react";
import { Link } from "react-router-dom";

interface EventsListProps {
    events: TrackingEvent[];
    loading: boolean;
    onDelete: (id: number) => void;
}

import { format } from "date-fns";

export function EventsList({ events, loading, onDelete }: EventsListProps) {
    const handleDelete = async (id: number) => {
        if (!confirm("Are you sure? This will break tracking for this event!")) return;
        onDelete(id);
    };

    if (loading) {
        return <div className="flex justify-center p-8"><Loader2 className="animate-spin" /></div>;
    }

    return (
        <Card className="mt-8">
            <CardHeader>
                <CardTitle>Manage Events</CardTitle>
                <CardDescription>Manage your tracking events and conversion links.</CardDescription>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Offer URL</TableHead>
                            <TableHead>Created Date</TableHead>
                            <TableHead>Event Type</TableHead>

                            <TableHead className="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {events.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={5} className="text-center py-8 text-muted-foreground">
                                    No events found. Create one to get started.
                                </TableCell>
                            </TableRow>
                        ) : events.map((event) => (
                            <TableRow
                                key={event.id}
                                className="hover:bg-muted/50"
                            >
                                <TableCell className="font-medium max-w-[300px] truncate">
                                    {event.offer_url}

                                </TableCell>
                                <TableCell>{format(new Date(event.created_at), "LLL dd, y")}</TableCell>
                                <TableCell>
                                    <div className="flex flex-col text-sm text-muted-foreground">
                                        {event.goals && event.goals.map((g, i) => (
                                            <span key={i} className="capitalize">{g.event_type}</span>
                                        ))}
                                        {(!event.goals || event.goals.length === 0) && <span>-</span>}
                                    </div>
                                </TableCell>

                                <TableCell className="text-right">
                                    <div className="flex justify-end gap-1">
                                        <Link to={`/dashboard/tracking/edit/${event.id}`}>
                                            <Button variant="ghost" size="icon">
                                                <Edit className="h-4 w-4" />
                                            </Button>
                                        </Link>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            onClick={(e) => { e.stopPropagation(); handleDelete(event.id); }}
                                            className="text-destructive hover:text-destructive/90"
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card >
    );
}
