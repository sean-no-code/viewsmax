import { useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
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
import { ArrowLeft, Save } from "lucide-react";
import { componentTypeConfig, ComponentType } from "@/lib/scripts-static-data";
import { viewsMaxApi } from "@/lib/api-service";
import { cn } from "@/lib/utils";

const ScriptsLibraryCreate = () => {
  const navigate = useNavigate();
  const [type, setType] = useState<ComponentType>("hook");
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");
  const [tags, setTags] = useState("");
  const [isSaving, setIsSaving] = useState(false);

  const parsedTags = useMemo(
    () => tags.split(",").map(t => t.trim()).filter(Boolean),
    [tags]
  );

  const handleSave = async () => {
    if (!title.trim()) return toast.error("Title is required");
    if (!body.trim()) return toast.error("Body is required");

    setIsSaving(true);
    const res = await viewsMaxApi.createLibraryComponent({
      type,
      title,
      body,
      tags: parsedTags.length ? parsedTags : undefined,
    });
    setIsSaving(false);

    if (res.success) {
      toast.success("Component created");
      navigate("/dashboard/scripts/library");
    } else {
      toast.error(res.error || "Failed to create component");
    }
  };

  return (
    <div className="space-y-6 max-w-3xl">
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="icon" onClick={() => navigate("/dashboard/scripts/library")}>
          <ArrowLeft className="w-5 h-5" />
        </Button>
        <div>
          <h1 className="text-2xl font-semibold">New component</h1>
          <p className="text-muted-foreground">Create a reusable script component</p>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Component details</CardTitle>
          <CardDescription>These are saved in your Library</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
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
              placeholder="e.g. Question hook: Have you ever wondered…"
              className={!title.trim() ? 'border-red-500' : ''}
              required
            />
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium">Body <span className="text-destructive">*</span></label>
            <Textarea
              value={body}
              onChange={(e) => setBody(e.target.value)}
              placeholder="Write the full component text…"
              className={cn("min-h-[200px]", !body.trim() ? 'border-red-500' : '')}
              required
            />
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium">Tags (optional)</label>
            <Input
              value={tags}
              onChange={(e) => setTags(e.target.value)}
              placeholder="comma,separated,tags"
            />
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
        </CardContent>
      </Card>
    </div>
  );
};

export default ScriptsLibraryCreate;

