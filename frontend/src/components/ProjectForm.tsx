import { useState, useEffect } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Checkbox } from "@/components/ui/checkbox";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Lightbulb, Wand2, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { viewsMaxApi } from "@/lib/api-service";

interface ProjectFormProps {
  mode: 'create' | 'edit';
  initialData?: {
    title: string;
    script: string;
    include_motion_graphics: boolean;
    include_references: boolean;
    include_youtube_videos: boolean;
  };
  onSave: (data: {
    title: string;
    script: string;
    include_motion_graphics: boolean;
    include_references: boolean;
    include_youtube_videos: boolean;
  }) => Promise<void>;
  onCancel: () => void;
  isLoading?: boolean;
}

const ProjectForm = ({ 
  mode, 
  initialData, 
  onSave, 
  onCancel, 
  isLoading = false 
}: ProjectFormProps) => {
  const [title, setTitle] = useState(initialData?.title || "");
  const [suggestedTitles, setSuggestedTitles] = useState<string[]>([]);
  const [showSuggestions, setShowSuggestions] = useState(false);
  const [script, setScript] = useState(initialData?.script || "");
  const [includeMotionGraphics, setIncludeMotionGraphics] = useState(initialData?.include_motion_graphics || false);
  const [includeReferences, setIncludeReferences] = useState(initialData?.include_references || false);
  const [includeYouTubeVideos, setIncludeYouTubeVideos] = useState(initialData?.include_youtube_videos || false);
  const [isGeneratingScript, setIsGeneratingScript] = useState(false);
  const [isGeneratingTitles, setIsGeneratingTitles] = useState(false);

  // Update form state when initialData changes
  useEffect(() => {
    if (initialData) {
      setTitle(initialData.title || "");
      setScript(initialData.script || "");
      setIncludeMotionGraphics(initialData.include_motion_graphics || false);
      setIncludeReferences(initialData.include_references || false);
      setIncludeYouTubeVideos(initialData.include_youtube_videos || false);
    }
  }, [initialData]);

  const generateTitleSuggestions = async () => {
    if (!title.trim()) {
      toast.error("Please enter a title or topic first");
      return;
    }

    setIsGeneratingTitles(true);
    try {
      const result = await viewsMaxApi.searchTitles(title);

      if (result.success && result.data) {
        setSuggestedTitles(result.data);
        setShowSuggestions(true);
      } else {
        throw new Error(result.error || 'Failed to generate suggestions');
      }
    } catch (error) {
      console.error('Error generating title suggestions:', error);
      const errorMessage = error instanceof Error ? error.message : "Failed to generate title suggestions. Please try again.";
      toast.error(errorMessage);
    } finally {
      setIsGeneratingTitles(false);
    }
  };

  const selectSuggestedTitle = (selectedTitle: string) => {
    setTitle(selectedTitle);
    setShowSuggestions(false);
  };

  const generateScript = async () => {
    if (!title.trim()) {
      toast.error("Please enter a title first");
      return;
    }

    setIsGeneratingScript(true);
    try {
      const result = await viewsMaxApi.generateScript({
        title: title,
        options: {
          includeMotionGraphics,
          includeReferences,
          includeYouTubeVideos
        }
      });

      if (result.success && result.data) {
        setScript(result.data);
      } else {
        throw new Error(result.error || 'Failed to generate script');
      }
    } catch (error) {
      console.error('Error generating script:', error);
      const errorMessage = error instanceof Error ? error.message : "Failed to generate script. Please try again.";
      toast.error(errorMessage);
    } finally {
      setIsGeneratingScript(false);
    }
  };

  const handleSave = async () => {
    if (!title.trim()) {
      toast.error("Please enter a project title");
      return;
    }

    await onSave({
      title: title.trim(),
      script,
      include_motion_graphics: includeMotionGraphics,
      include_references: includeReferences,
      include_youtube_videos: includeYouTubeVideos
    });
  };

  if (isLoading) {
    return (
      <div className="space-y-6 max-w-4xl">
        <div className="flex items-center justify-center h-64">
          <div className="text-center">
            <Loader2 className="w-8 h-8 animate-spin mx-auto mb-4" />
            <p className="text-muted-foreground">Loading project...</p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-4xl">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Lightbulb className="w-5 h-5 text-primary" />
            Project Title
          </CardTitle>
          <CardDescription>
            Give your project a compelling title that captures your audience's attention
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-3">
            <div className="flex gap-2">
              <div className="flex-1">
                <Label htmlFor="title">Title *</Label>
                <Input
                  id="title"
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  placeholder="Enter your video title..."
                  className={`mt-1 ${!title.trim() ? 'border-red-500' : ''}`}
                  required
                />
              </div>
              <div className="flex items-end">
                <Button
                  onClick={generateTitleSuggestions}
                  variant="outline"
                  className="gap-2"
                  disabled={isGeneratingTitles || !title.trim()}
                >
                  {isGeneratingTitles ? (
                    <Loader2 className="w-4 h-4 animate-spin" />
                  ) : (
                    <Lightbulb className="w-4 h-4" />
                  )}
                  {isGeneratingTitles ? "Generating..." : "Suggest Titles"}
                </Button>
              </div>
            </div>
          </div>

          {showSuggestions && (
            <div className="space-y-2">
              <Label>Suggested Titles</Label>
              <div className="grid gap-2 max-h-48 overflow-y-auto">
                {suggestedTitles.map((suggestedTitle, index) => (
                  <Button
                    key={index}
                    variant="ghost"
                    className="justify-start h-auto py-2 px-3 text-left whitespace-normal"
                    onClick={() => selectSuggestedTitle(suggestedTitle)}
                  >
                    {suggestedTitle}
                  </Button>
                ))}
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-lg">Thumbnail</CardTitle>
          <CardDescription>
            Set your video thumbnail
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col items-center justify-center h-32 border-2 border-dashed border-muted-foreground/25 rounded-lg">
            <Button variant="outline" className="gap-2">
              Generate/Set Thumbnail
            </Button>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Wand2 className="w-5 h-5 text-primary" />
            AI Script Generation
          </CardTitle>
          <CardDescription>
            Let AI help you create a compelling script for your video
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-3">
            <Label>Include Additional Suggestions</Label>
            <div className="flex flex-wrap gap-4">
              <div className="flex items-center space-x-2">
                <Checkbox
                  id="motion-graphics"
                  checked={includeMotionGraphics}
                  onCheckedChange={(checked) => setIncludeMotionGraphics(checked === true)}
                />
                <Label htmlFor="motion-graphics">Motion Graphics</Label>
              </div>
              <div className="flex items-center space-x-2">
                <Checkbox
                  id="references"
                  checked={includeReferences}
                  onCheckedChange={(checked) => setIncludeReferences(checked === true)}
                />
                <Label htmlFor="references">References</Label>
              </div>
              <div className="flex items-center space-x-2">
                <Checkbox
                  id="youtube-videos"
                  checked={includeYouTubeVideos}
                  onCheckedChange={(checked) => setIncludeYouTubeVideos(checked === true)}
                />
                <Label htmlFor="youtube-videos">YouTube Videos</Label>
              </div>
            </div>
          </div>

          <div className="flex justify-end">
            <Button
              onClick={generateScript}
              disabled={!title.trim() || isGeneratingScript}
              className="gap-2"
            >
              <Wand2 className="w-4 h-4" />
              {isGeneratingScript ? "Generating Script..." : "Generate Script"}
            </Button>
          </div>

          {script && (
            <div className="space-y-2">
              <Label htmlFor="script">Generated Script</Label>
              <Textarea
                id="script"
                value={script}
                onChange={(e) => setScript(e.target.value)}
                placeholder="Your generated script will appear here..."
                className="min-h-[400px] font-mono text-sm"
              />
            </div>
          )}
        </CardContent>
      </Card>

      {title.trim() && (
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onCancel}>
            Cancel
          </Button>
          <Button className="gap-2" onClick={handleSave}>
            {mode === 'create' ? 'Save Project' : 'Update Project'}
          </Button>
        </div>
      )}
    </div>
  );
};

export default ProjectForm;
