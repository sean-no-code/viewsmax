import { useCallback, useEffect, useState } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import {
  Activity,
  RefreshCw,
  Loader2,
  ChevronDown,
  ChevronRight,
  ChevronLeft,
} from "lucide-react";
import { viewsMaxApi, McpActivityItem } from "@/lib/api-service";

/**
 * Read-only view of the MCP audit log: every tool call a connected AI
 * assistant made on the account — tool, arguments, outcome, auth mode.
 * Paginated (20/page) so the list stays usable as history grows.
 */
const AiActivityLog = () => {
  const [loading, setLoading] = useState(true);
  const [items, setItems] = useState<McpActivityItem[]>([]);
  const [expanded, setExpanded] = useState<number | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);

  const load = useCallback(async (targetPage: number) => {
    setLoading(true);
    const res = await viewsMaxApi.getMcpActivity(targetPage);
    if (res.success && res.data) {
      setItems(res.data.data);
      setPage(res.data.current_page);
      setLastPage(res.data.last_page);
      setTotal(res.data.total);
    } else {
      setItems([]);
    }
    setExpanded(null);
    setLoading(false);
  }, []);

  useEffect(() => {
    load(1);
  }, [load]);

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center justify-between">
          <div>
            <CardTitle className="flex items-center gap-2">
              <Activity className="h-4 w-4" />
              AI Activity
            </CardTitle>
            <CardDescription>
              Every action your connected AI assistants took on this account.
            </CardDescription>
          </div>
          <Button variant="outline" size="sm" onClick={() => load(page)} disabled={loading}>
            {loading ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
          </Button>
        </div>
      </CardHeader>
      <CardContent>
        {loading ? (
          <div className="text-sm text-muted-foreground">Loading…</div>
        ) : items.length === 0 ? (
          <div className="text-sm text-muted-foreground">
            No activity yet. Once an AI assistant runs a tool on your account,
            it shows up here.
          </div>
        ) : (
          <>
            <div className="divide-y">
              {items.map((item) => (
                <div key={item.id} className="py-2">
                  <button
                    type="button"
                    className="flex w-full items-center gap-2 text-left"
                    onClick={() => setExpanded(expanded === item.id ? null : item.id)}
                  >
                    {expanded === item.id ? (
                      <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    ) : (
                      <ChevronRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    )}
                    <code className="text-sm">{item.tool}</code>
                    {item.is_error && <Badge variant="destructive">failed</Badge>}
                    <span className="ml-auto flex items-center gap-2 text-xs text-muted-foreground">
                      {item.auth_mode && <span className="uppercase">{item.auth_mode}</span>}
                      {new Date(item.created_at).toLocaleString()}
                    </span>
                  </button>
                  {expanded === item.id && (
                    <pre className="mt-2 max-h-48 overflow-auto rounded-md bg-muted p-3 text-xs">
                      {JSON.stringify(item.arguments ?? {}, null, 2)}
                    </pre>
                  )}
                </div>
              ))}
            </div>

            <div className="mt-4 flex items-center justify-between">
              <span className="text-xs text-muted-foreground">
                Page {page} of {lastPage} · {total} total
              </span>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => load(page - 1)}
                  disabled={loading || page <= 1}
                >
                  <ChevronLeft className="h-4 w-4" />
                  Previous
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => load(page + 1)}
                  disabled={loading || page >= lastPage}
                >
                  Next
                  <ChevronRight className="h-4 w-4" />
                </Button>
              </div>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  );
};

export default AiActivityLog;
