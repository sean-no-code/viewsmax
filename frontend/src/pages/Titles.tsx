import { useState, useEffect } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";
import { Type, Wand2, Loader2, Eye, EyeOff } from "lucide-react";
import { toast } from "sonner";
import { viewsMaxApi, Title, Project } from "@/lib/api-service";
import { useNavigate } from "react-router-dom";

const Titles = () => {
  const [projects, setProjects] = useState<Project[]>([]);
  const [projectTitles, setProjectTitles] = useState<Record<string, Title[]>>({});
  const [expandedProjects, setExpandedProjects] = useState<Set<string>>(new Set());
  const [isLoading, setIsLoading] = useState(true);
  const [isGenerating, setIsGenerating] = useState(false);
  const navigate = useNavigate();

  useEffect(() => {
    loadProjects();
  }, []);

  const loadProjects = async () => {
    try {
      setIsLoading(true);
      const result = await viewsMaxApi.getProjects();
      
      if (result.success && result.data) {
        setProjects(result.data);
      } else {
        toast.error(result.error || "Failed to load projects");
      }
    } catch (error) {
      console.error("Error loading projects:", error);
      toast.error("Failed to load projects");
    } finally {
      setIsLoading(false);
    }
  };

  const generateTitlesForProject = async (projectId: string) => {
    // Navigate to titles/edit with the project ID and flag indicating wand click
    navigate(`/dashboard/titles/edit/${projectId}`, { 
      state: { fromWandClick: true } 
    });
  };

  const toggleProjectExpansion = async (projectId: string) => {
    const isExpanded = expandedProjects.has(projectId);
    
    if (isExpanded) {
      // Collapse
      setExpandedProjects(prev => {
        const newSet = new Set(prev);
        newSet.delete(projectId);
        return newSet;
      });
    } else {
      // Expand and load titles if not already loaded
      setExpandedProjects(prev => new Set([...prev, projectId]));
      
      if (!projectTitles[projectId]) {
        await loadTitlesForProject(projectId);
      }
    }
  };

  const loadTitlesForProject = async (projectId: string) => {
    try {
      // This would be a new API call to get titles for a specific project
      // For now, we'll use the existing getTitles and filter by project
      const result = await viewsMaxApi.getTitles();
      
      if (result.success && result.data) {
        // Filter titles by project (this would need to be implemented in the API)
        // For now, we'll show all titles as a placeholder, sorted by most recently created
        const sortedTitles = (result.data || []).sort((a, b) => 
          new Date(b.created_at).getTime() - new Date(a.created_at).getTime()
        );
        setProjectTitles(prev => ({
          ...prev,
          [projectId]: sortedTitles
        }));
      }
    } catch (error) {
      console.error("Error loading titles for project:", error);
      toast.error("Failed to load titles for project");
    }
  };

  if (isLoading) {
    return (
      <div className="space-y-6 max-w-6xl">
        <div className="flex items-center justify-center h-64">
          <div className="text-center">
            <Loader2 className="w-8 h-8 animate-spin mx-auto mb-4" />
            <p className="text-muted-foreground">Loading projects...</p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-6xl">
      {/* Create Titles Button */}
      <div className="flex justify-end">
        <Button 
          onClick={() => navigate('/dashboard/titles/new')}
          className="gap-2"
        >
          <Wand2 className="w-4 h-4" />
          Create Titles
        </Button>
      </div>

      {/* Projects List */}
      <Card>
        <CardHeader>
          <CardTitle className="text-lg">Projects</CardTitle>
          <CardDescription>
            Manage your video projects and generate titles
          </CardDescription>
        </CardHeader>
        <CardContent>
          {projects.length === 0 ? (
            <div className="text-center py-8">
              <Type className="w-12 h-12 mx-auto text-muted-foreground mb-4" />
              <p className="text-muted-foreground">No projects found</p>
              <p className="text-sm text-muted-foreground mt-2">
                Create your first project to get started
              </p>
            </div>
          ) : (
            <div className="space-y-4">
              {projects.map((project) => (
                <div key={project.id} className="border rounded-lg p-4">
                  <div className="flex items-center justify-between">
                    <div className="flex-1">
                      <p className="font-medium">{project.description}</p>
                      <p className="text-sm text-muted-foreground mt-1">
                        Created {new Date(project.created_at).toLocaleDateString()}
                      </p>
                    </div>
                    
                    <div className="flex items-center gap-2">
                      <TooltipProvider>
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Button
                              onClick={() => toggleProjectExpansion(project.id)}
                              variant="ghost"
                              size="sm"
                              className="p-2 h-8 w-8"
                            >
                              {expandedProjects.has(project.id) ? (
                                <EyeOff className="w-4 h-4" />
                              ) : (
                                <Eye className="w-4 h-4" />
                              )}
                            </Button>
                          </TooltipTrigger>
                          <TooltipContent>
                            {expandedProjects.has(project.id) ? "Hide titles" : "Show titles"}
                          </TooltipContent>
                        </Tooltip>
                      </TooltipProvider>
                      
                      <TooltipProvider>
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Button
                              onClick={() => generateTitlesForProject(project.id)}
                              disabled={isGenerating}
                              size="sm"
                              className="p-2 h-8 w-8"
                            >
                              {isGenerating ? (
                                <Loader2 className="w-4 h-4 animate-spin" />
                              ) : (
                                <Wand2 className="w-4 h-4" />
                              )}
                            </Button>
                          </TooltipTrigger>
                          <TooltipContent>Create titles</TooltipContent>
                        </Tooltip>
                      </TooltipProvider>
                    </div>
                  </div>

                  {/* Expanded Titles Section */}
                  {expandedProjects.has(project.id) && (
                    <div className="mt-4 pt-4 border-t">
                      {projectTitles[project.id] ? (
                        projectTitles[project.id].length > 0 ? (
                          <div className="space-y-2">
                            <h4 className="font-medium text-sm text-muted-foreground">
                              Generated Titles ({projectTitles[project.id].length})
                            </h4>
                            <div className="space-y-2">
                              {projectTitles[project.id].map((title, index) => (
                                <div key={title.id || index} className="p-2 bg-muted/50 rounded text-sm">
                                  <p className="font-medium">
                                    {(title.title || title.enhanced_title || title.original_title || '').replace(/^["']|["']$/g, '')}
                                  </p>
                                  {title.description && (
                                    <p className="text-muted-foreground text-xs mt-1">
                                      {title.description}
                                    </p>
                                  )}
                                </div>
                              ))}
                            </div>
                          </div>
                        ) : (
                          <p className="text-sm text-muted-foreground">No titles generated yet</p>
                        )
                      ) : (
                        <div className="flex items-center gap-2">
                          <Loader2 className="w-4 h-4 animate-spin" />
                          <span className="text-sm text-muted-foreground">Loading titles...</span>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
};

export default Titles;
