import { Skeleton } from "@viewsmax/ui";

export const LoadingChannel = () => (
  <div className="flex w-full max-w-sm items-center gap-4 rounded-lg border p-4">
    <Skeleton className="h-12 w-12 rounded-full" />
    <div className="space-y-2">
      <Skeleton className="h-4 w-48" />
      <Skeleton className="h-4 w-32" />
    </div>
  </div>
);

export const LoadingMetric = () => (
  <div className="w-full max-w-xs space-y-3 rounded-lg border p-4">
    <Skeleton className="h-3 w-24" />
    <Skeleton className="h-8 w-20" />
    <Skeleton className="h-3 w-40" />
  </div>
);

export const LoadingTableRows = () => (
  <div className="w-full max-w-2xl divide-y rounded-lg border">
    <div className="flex items-center justify-between gap-4 p-4">
      <div className="flex items-center gap-4">
        <Skeleton className="h-10 w-16" />
        <Skeleton className="h-4 w-64" />
      </div>
      <div className="flex items-center gap-4">
        <Skeleton className="h-4 w-16" />
        <Skeleton className="h-4 w-12" />
      </div>
    </div>
    <div className="flex items-center justify-between gap-4 p-4">
      <div className="flex items-center gap-4">
        <Skeleton className="h-10 w-16" />
        <Skeleton className="h-4 w-56" />
      </div>
      <div className="flex items-center gap-4">
        <Skeleton className="h-4 w-16" />
        <Skeleton className="h-4 w-12" />
      </div>
    </div>
    <div className="flex items-center justify-between gap-4 p-4">
      <div className="flex items-center gap-4">
        <Skeleton className="h-10 w-16" />
        <Skeleton className="h-4 w-72" />
      </div>
      <div className="flex items-center gap-4">
        <Skeleton className="h-4 w-16" />
        <Skeleton className="h-4 w-12" />
      </div>
    </div>
  </div>
);
