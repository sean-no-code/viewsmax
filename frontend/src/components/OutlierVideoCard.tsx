import { Card, CardContent } from "@/components/ui/card";
import { Play, Eye, Users, Calendar, Clock, TrendingUp } from "lucide-react";

export interface OutlierVideoData {
    id: string;
    title: string;
    thumbnail: string;
    subscribers: string;
    views: number;
    publishedDate: string;
    outlierScore: number;
    duration: string;
    channelName: string;
    channelAvatar: string;
}

interface OutlierVideoCardProps {
    video: OutlierVideoData;
}

const formatDuration = (isoDuration: string) => {
    if (!isoDuration || !isoDuration.startsWith('PT')) return isoDuration || '0:00';
    const match = isoDuration.match(/PT(\d+H)?(\d+M)?(\d+S)?/);
    if (!match) return isoDuration;
    const hours = (match[1] || '').replace('H', '');
    const minutes = (match[2] || '').replace('M', '');
    const seconds = (match[3] || '').replace('S', '');
    let result = '';
    if (hours) result += `${hours}:`;
    result += `${minutes.padStart(hours ? 2 : 1, '0')}:`;
    result += `${seconds.padStart(2, '0')}`;
    if (!hours && !minutes) result = `0:${seconds.padStart(2, '0')}`;
    return result;
};

const formatDate = (dateString: string) => {
    try {
        return new Date(dateString).toLocaleDateString("en-US", {
            year: "numeric",
            month: "short",
            day: "numeric",
        });
    } catch {
        return dateString;
    }
};

const formatViews = (views: number): string => {
    if (!views && views !== 0) return '—';
    if (views >= 1_000_000_000) return (views / 1_000_000_000).toFixed(1).replace(/\.0$/, '') + 'B';
    if (views >= 1_000_000) return (views / 1_000_000).toFixed(1).replace(/\.0$/, '') + 'M';
    if (views >= 1_000) return (views / 1_000).toFixed(1).replace(/\.0$/, '') + 'K';
    return views.toLocaleString();
};

const OutlierVideoCard = ({ video }: OutlierVideoCardProps) => {
    const getScoreColor = (score: number) => {
        if (score >= 80) return "text-green-500";
        if (score >= 50) return "text-yellow-500";
        return "text-red-500";
    };

    const getScoreBg = (score: number) => {
        if (score >= 80) return "bg-green-500/10 border-green-500/20";
        if (score >= 50) return "bg-yellow-500/10 border-yellow-500/20";
        return "bg-red-500/10 border-red-500/20";
    };

    return (
        <a
            href={`https://www.youtube.com/watch?v=${video.id}`}
            target="_blank"
            rel="noopener noreferrer"
            className="block h-full group"
        >
            <Card className="overflow-hidden hover:shadow-lg transition-all duration-300 h-full flex flex-col">
                <div className="relative aspect-video bg-muted">
                    <img
                        src={video.thumbnail}
                        alt={video.title}
                        className="w-full h-full object-cover"
                        loading="lazy"
                    />

                    {/* Duration badge — bottom right */}
                    <div className="absolute bottom-2 right-2 bg-black/80 text-white text-xs px-1.5 py-0.5 rounded font-medium flex items-center gap-1">
                        <Clock className="w-3 h-3" />
                        {formatDuration(video.duration)}
                    </div>

                    {/* Views badge — bottom left */}
                    {video.views > 0 && (
                        <div className="absolute bottom-2 left-2 bg-black/75 backdrop-blur-sm text-white text-xs px-2 py-0.5 rounded-full font-semibold flex items-center gap-1">
                            <Eye className="w-3 h-3 text-blue-300" />
                            <span>{formatViews(video.views)}</span>
                        </div>
                    )}

                    {/* Play overlay */}
                    <div className="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition-colors flex items-center justify-center opacity-0 group-hover:opacity-100">
                        <Play className="w-12 h-12 text-white fill-white drop-shadow-lg" />
                    </div>
                </div>

                <CardContent className="p-4 flex flex-col flex-grow gap-3">
                    {/* Score row */}
                    <div className={`flex items-center gap-2 px-2.5 py-1 rounded-full w-fit text-xs font-semibold border ${getScoreBg(video.outlierScore)} ${getScoreColor(video.outlierScore)}`}>
                        <TrendingUp className="w-3.5 h-3.5" />
                        <span>Outlier Score: {video.outlierScore.toFixed()}</span>
                    </div>

                    <h3 className="font-semibold text-base line-clamp-2 leading-tight min-h-[2.5rem]">
                        {video.title}
                    </h3>

                    <div className="flex items-center gap-2 mt-auto pt-2 border-t border-border/50">
                        <div className="flex flex-col text-xs text-muted-foreground w-full">
                            <span className="font-medium text-foreground truncate">{video.channelName}</span>
                            <div className="flex items-center justify-between gap-2 mt-0.5">
                                <span className="flex items-center gap-1">
                                    <Users className="w-3 h-3" /> {video.subscribers}
                                </span>
                                <span className="flex items-center gap-1">
                                    <Calendar className="w-3 h-3" /> {formatDate(video.publishedDate)}
                                </span>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </a>
    );
};

export default OutlierVideoCard;
