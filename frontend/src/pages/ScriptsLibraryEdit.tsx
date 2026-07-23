import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { toast } from "sonner";
import { ArrowLeft, Save, Loader2 } from "lucide-react";
import { componentTypeConfig, ComponentType } from "@/lib/scripts-static-data";
import { viewsMaxApi } from "@/lib/api-service";
import { cn } from "@/lib/utils";

const ScriptsLibraryEdit = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [type, setType] = useState<ComponentType>("hook");
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");
  const [tags, setTags] = useState("");

  useEffect(() => {
    const run = async () => {
      if (!id) return;
      setIsLoading(true);
      const res = await viewsMaxApi.getLibraryComponents();
      setIsLoading(false);
      const component = res.data?.find(c => c.id === Number(id));
      if (!res.success || !component) {
        toast.error(res.error || "Failed to load component");
        navigate("/dashboard/scripts/library");
        return;
      }
      setType(component.type as ComponentType);
      setTitle(component.title);
      setBody(component.body);
      setTags((component.tags || []).map(t => t.name).join(", "));
    };
    run();
  }, [id, navigate]);

  const parsedTags = useMemo(
    () => tags.split(",").map(t => t.trim()).filter(Boolean),
    [tags]
  );

  const handleSave = async () => {
    if (!id) return;
    if (!title.trim()) return toast.error("Title is required");
    if (!body.trim()) return toast.error("Body is required");

    setIsSaving(true);
    const res = await viewsMaxApi.updateLibraryComponent(Number(id), {
      type,
      title,
      body,
      tags: parsedTags.length ? parsedTags : undefined,
    });
    setIsSaving(false);

    if (res.success) {
      toast.success("Component updated");
      navigate("/dashboard/scripts/library");
    } else {
      toast.error(res.error || "Failed to update component");
    }
  };

  return (
    <div className="space-y-6 max-w-3xl">
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="icon" onClick={() => navigate("/dashboard/scripts/library")}>
          <ArrowLeft className="w-5 h-5" />
        </Button>
        <div>
          <h1 className="text-2xl font-semibold">Edit component</h1>
          <p className="text-muted-foreground">Update a reusable script component</p>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Component details</CardTitle>
          <CardDescription>Component ID: {id}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {isLoading ? (
            <div className="py-10 text-center text-muted-foreground">
              <Loader2 className="w-6 h-6 animate-spin mx-auto mb-3" />
              Loading…
            </div>
          ) : (
            <>
              <div className="space-y-2">
                <label className="text-sm font-medium">Type</label>
                <Select value={type} onValueChange={(v) => setType(v as ComponentType)}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select type" />
                  </SelectTrigger>
                  <SelectContent>
                    {Object.entries(componentTypeConfig).map(([k, v]) => (
                      <SelectItem key={k} value={k}>
                        {v.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <label className="text-sm font-medium">Title <span className="text-destructive">*</span></label>
                <Input
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  className={!title.trim() ? 'border-red-500' : ''}
                  required
                />
              </div>

              <div className="space-y-2">
                <label className="text-sm font-medium">Body <span className="text-destructive">*</span></label>
                <Textarea
                  value={body}
                  onChange={(e) => setBody(e.target.value)}
                  className={cn("min-h-[200px]", !body.trim() ? 'border-red-500' : '')}
                  required
                />
              </div>

              <div className="space-y-2">
                <label className="text-sm font-medium">Tags (optional)</label>
                <Input value={tags} onChange={(e) => setTags(e.target.value)} />
              </div>

              <div className="flex justify-end gap-2 pt-2">
                <Button variant="outline" onClick={() => navigate("/dashboard/scripts/library")}>
                  Cancel
                </Button>
                <Button onClick={handleSave} disabled={isSaving}>
                  <Save className="w-4 h-4 mr-2" />
                  {isSaving ? "Saving…" : "Save"}
                </Button>
              </div>
            </>
          )}
        </CardContent>
      </Card>
    </div>
  );
};

export default ScriptsLibraryEdit;

