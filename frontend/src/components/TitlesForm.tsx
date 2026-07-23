import { useState, useEffect, useRef } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { Wand2, Loader2, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { viewsMaxApi, Title } from "@/lib/api-service";
import { useNavigate } from "react-router-dom";

interface TitlesFormProps {
  projectId?: string;
  projectDescription?: string;
  autoSubmit?: boolean;
  onSuccess?: (titles: Title[]) => void;
}

const TitlesForm = ({ projectId, projectDescription, autoSubmit = false, onSuccess }: TitlesFormProps) => {
  const [titleQuery, setTitleQuery] = useState("");
  const [isGenerating, setIsGenerating] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [existingTitles, setExistingTitles] = useState<Title[]>([]);
  const [newlyGeneratedTitles, setNewlyGeneratedTitles] = useState<Title[]>([]);
  const hasAutoSubmitted = useRef(false);
  const navigate = useNavigate();

  useEffect(() => {
    loadExistingTitles();
  }, []);

  useEffect(() => {
    if (projectDescription) {
      setTitleQuery(projectDescription);
    }
  }, [projectDescription]);

  useEffect(() => {
    if (projectId) {
      loadProject();
    }
  }, [projectId]);

  useEffect(() => {
    if (autoSubmit && titleQuery.trim() && !hasAutoSubmitted.current) {
      // Only auto-submit once when the component mounts with autoSubmit=true
      // This prevents re-submission when navigating away or other state changes
      hasAutoSubmitted.current = true;
      generateTitles();
    }
  }, [autoSubmit, titleQuery]);

  const loadExistingTitles = async () => {
    try {
      setIsLoading(true);
      const result = await viewsMaxApi.getTitles();
      
      if (result.success && result.data) {
        // Sort titles by most recently created
        const sortedTitles = result.data.sort((a, b) => 
          new Date(b.created_at).getTime() - new Date(a.created_at).getTime()
        );
        setExistingTitles(sortedTitles);
      } else {
        console.error("Failed to load existing titles:", result.error);
        // Don't show error toast for this as it's not critical
      }
    } catch (error) {
      console.error("Error loading existing titles:", error);
      // Don't show error toast for this as it's not critical
    } finally {
      setIsLoading(false);
    }
  };

  const loadProject = async () => {
    if (!projectId) return;
    
    try {
      const result = await viewsMaxApi.getProject(projectId);
      
      if (result.success && result.data) {
        setTitleQuery(result.data.description);
        // Note: We don't override existingTitles here since we load them separately
      } else {
        toast.error(result.error || "Failed to load project");
      }
    } catch (error) {
      console.error("Error loading project:", error);
      toast.error("Failed to load project");
    }
  };

  const generateTitles = async () => {
    if (!titleQuery.trim()) {
      toast.error("Please enter a query for the titles");
      return;
    }

    try {
      setIsGenerating(true);
      
      // Use the searchTitles method which calls /api/titles/enhance
      const result = await viewsMaxApi.searchTitles(titleQuery);

      if (result.success && result.data) {
        const titles = result.data; // The data field contains the titles array directly
        
        // Convert the API response format to Title objects
        const convertedTitles: Title[] = titles.map((titleText, index) => ({
          id: `generated-${Date.now()}-${index}`,
          title: titleText,
          generated: true,
          created_at: new Date().toISOString(),
          updated_at: new Date().toISOString(),
        }));

        toast.success(`Generated ${titles.length} title(s) successfully`);
        
        // Add new titles to the top of existing titles immediately
        setExistingTitles(prev => [...convertedTitles, ...prev]);
        
        // Set newly generated titles to show as "new" (permanent highlighting)
        setNewlyGeneratedTitles(convertedTitles);
        
        if (onSuccess) {
          onSuccess(convertedTitles);
        }
      } else {
        toast.error(result.error || "Failed to generate titles");
      }
    } catch (error) {
      console.error("Error generating titles:", error);
      const errorMessage = error instanceof Error ? error.message : "Failed to generate titles";
      toast.error(errorMessage);
    } finally {
      setIsGenerating(false);
    }
  };

  if (isLoading) {
    return (
      <div className="space-y-6 max-w-6xl">
        <div className="flex items-center justify-center h-64">
          <div className="text-center">
            <Loader2 className="w-8 h-8 animate-spin mx-auto mb-4" />
            <p className="text-muted-foreground">Loading titles...</p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-6xl">
      <Card>
        <CardHeader>
          <CardTitle className="text-lg">Generate Titles</CardTitle>
          <CardDescription>
            Create compelling video titles based on your query
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="query">Title Query</Label>
            <Input
              id="query"
              name="query"
              placeholder="Enter your title query to generate enhanced titles..."
              value={titleQuery}
              onChange={(e) => setTitleQuery(e.target.value)}
            />
          </div>
          
          <div className="flex justify-end gap-2">
            <Button 
              variant="outline"
              onClick={() => navigate('/dashboard/titles/new')}
            >
              Cancel
            </Button>
            <Button 
              onClick={generateTitles} 
              disabled={isGenerating || !titleQuery.trim()}
              className="gap-2"
            >
              {isGenerating ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : (
                <Wand2 className="w-4 h-4" />
              )}
              {isGenerating ? "Generating..." : "Generate Titles"}
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Generated Titles Section */}
      {existingTitles.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-lg">Generated Titles</CardTitle>
            <CardDescription>
              {existingTitles.length} title(s) generated
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="space-y-3">
              {existingTitles.map((title, index) => {
                const isNew = newlyGeneratedTitles.some(newTitle => newTitle.id === title.id);
                return (
                  <div 
                    key={title.id || index} 
                    className={`flex items-center justify-between p-3 border rounded-lg transition-all duration-500 ${
                      isNew 
                        ? 'border-blue-300 bg-blue-50/50 shadow-md animate-in fade-in-0 slide-in-from-left-2' 
                        : ''
                    }`}
                    style={isNew ? { animationDelay: `${index * 100}ms` } : {}}
                  >
                    <div className="flex-1">
                      <div className="flex items-center gap-2">
                        <p className="font-medium">
                          {(title.title || title.enhanced_title || title.original_title || '').replace(/^["']|["']$/g, '')}
                        </p>
                        {isNew && (
                          <Badge variant="secondary" className="text-xs bg-blue-100 text-blue-700">
                            NEW
                          </Badge>
                        )}
                      </div>
                      {title.description && (
                        <p className="text-sm text-muted-foreground mt-1">{title.description}</p>
                      )}
                      <div className="flex items-center gap-2 mt-2">
                        <span className="text-xs text-muted-foreground">
                          {isNew ? 'Just now' : new Date(title.created_at).toLocaleDateString()}
                        </span>
                      </div>
                    </div>
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => {
                        // TODO: Implement delete functionality
                        console.log('Delete title:', title.id);
                      }}
                      className="text-destructive hover:text-destructive"
                    >
                      <Trash2 className="w-4 h-4" />
                    </Button>
                  </div>
                );
              })}
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
};

export default TitlesForm;
