import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { toast } from "sonner";
import { Plus, Pencil, Trash2, Library, Search } from "lucide-react";
import { componentTypeConfig, ComponentType } from "@/lib/scripts-static-data";
import { viewsMaxApi, LibraryComponent } from "@/lib/api-service";
import { cn } from "@/lib/utils";

const ScriptsLibrary = () => {
  const navigate = useNavigate();
  const [items, setItems] = useState<LibraryComponent[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [type, setType] = useState<string>("all");
  const [query, setQuery] = useState("");
  const [deleteId, setDeleteId] = useState<number | null>(null);

  const load = async () => {
    setIsLoading(true);
    try {
      const result = await viewsMaxApi.getLibraryComponents();
      if (result.success && result.data) {
        let filtered = result.data;
        if (type !== 'all') {
          filtered = filtered.filter(i => i.type === type);
        }
        if (query) {
          const lower = query.toLowerCase();
          filtered = filtered.filter(i => i.title.toLowerCase().includes(lower) || i.body.toLowerCase().includes(lower));
        }
        setItems(filtered);
      }
      else toast.error(result.error || "Failed to load library");
    } catch (e) {
      toast.error("Failed to load library");
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [type]);

  useEffect(() => {
    const t = window.setTimeout(() => {
      load();
    }, 250);
    return () => window.clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query]);

  const handleDelete = async () => {
    if (!deleteId) return;
    const res = await viewsMaxApi.deleteLibraryComponent(Number(deleteId));
    if (res.success) {
      toast.success("Component deleted");
      setDeleteId(null);
      load();
    } else {
      toast.error(res.error || "Failed to delete");
    }
  };

  const typeBadge = (t: string) => {
    const label = componentTypeConfig[t as ComponentType]?.label || t;
    const colors: Record<string, string> = {
      hook: "bg-orange-100 text-orange-700 border-orange-200",
      cta: "bg-blue-100 text-blue-700 border-blue-200",
      outro: "bg-purple-100 text-purple-700 border-purple-200",
      transition: "bg-green-100 text-green-700 border-green-200",
      story: "bg-pink-100 text-pink-700 border-pink-200",
    };
    return (
      <Badge variant="outline" className={cn("text-xs", colors[t] || "bg-muted")}>
        {label}
      </Badge>
    );
  };

  return (
    <div className="space-y-6 max-w-6xl">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold flex items-center gap-2">
            <Library className="w-6 h-6 text-primary" />
            Component Library
          </h1>
          <p className="text-muted-foreground">
            Manage reusable script components (hooks, CTAs, outros, and more)
          </p>
        </div>
        <Button onClick={() => navigate("/dashboard/scripts/library/new")} className="gap-2">
          <Plus className="w-4 h-4" />
          New Component
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="text-lg">Library</CardTitle>
          <CardDescription>Search and edit your saved components</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex flex-col md:flex-row gap-3">
            <Select value={type} onValueChange={setType}>
              <SelectTrigger className="md:w-[220px]">
                <SelectValue placeholder="Filter by type" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All types</SelectItem>
                {Object.entries(componentTypeConfig).map(([k, v]) => (
                  <SelectItem key={k} value={k}>
                    {v.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>

            <div className="flex-1 relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
              <Input
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Search by title or body…"
                className="pl-9"
              />
            </div>
          </div>

          {isLoading ? (
            <div className="py-10 text-center text-muted-foreground">Loading…</div>
          ) : items.length === 0 ? (
            <div className="py-10 text-center text-muted-foreground">
              No components found.
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Type</TableHead>
                  <TableHead>Title</TableHead>
                  <TableHead>Preview</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.map((c) => (
                  <TableRow key={c.id}>
                    <TableCell>{typeBadge(c.type)}</TableCell>
                    <TableCell className="font-medium">{c.title}</TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      <span className="line-clamp-2 whitespace-pre-wrap">{c.body}</span>
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-2">
                        <Button
                          size="sm"
                          variant="outline"
                          className="h-8 w-8 p-0"
                          onClick={() => navigate(`/dashboard/scripts/library/edit/${c.id}`)}
                        >
                          <Pencil className="w-4 h-4" />
                        </Button>
                        <Button
                          size="sm"
                          variant="destructive"
                          className="h-8 w-8 p-0"
                          onClick={() => setDeleteId(c.id)}
                        >
                          <Trash2 className="w-4 h-4" />
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <AlertDialog open={!!deleteId} onOpenChange={(open) => !open && setDeleteId(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete component?</AlertDialogTitle>
            <AlertDialogDescription>
              This can’t be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
};

export default ScriptsLibrary;

