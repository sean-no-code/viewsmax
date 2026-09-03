// Analytics — full Sources & Referrers index. The Overview widgets show the
// top 5 of each and link here for the complete tables.
import { useState } from "react";
import { SectionHead, Segmented } from "@/components/analytics/primitives";
import { TrafficSources } from "@/components/analytics/TrafficSources";
import { AnalyticsShell, useTrafficSources, RANGE_OPTS, type Range } from "@/components/analytics/useAnalytics";

export default function Sources() {
  const [range, setRange] = useState<Range>("28d");
  const { sources, loading } = useTrafficSources(range);

  return (
    <AnalyticsShell>
      <SectionHead
        eyebrow="ACQUISITION"
        title="Sources & referrers."
        right={<Segmented options={RANGE_OPTS} value={range} onChange={(v) => setRange(v as Range)} />}
      />
      <TrafficSources data={sources} loading={loading} />
    </AnalyticsShell>
  );
}
