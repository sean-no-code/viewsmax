import { useState, useEffect } from "react";
import { Button } from "@/components/ui/button";
import { ArrowLeft } from "lucide-react";
import { useNavigate, useParams } from "react-router-dom";
import { toast } from "sonner";
import { viewsMaxApi } from "@/lib/api-service";
import ProjectForm from "@/components/ProjectForm";

const EditProject = () => {
  const navigate = useNavigate();
  const { id } = useParams();
  const [projectData, setProjectData] = useState<{
    title: string;
    script: string;
    include_motion_graphics: boolean;
    include_references: boolean;
    include_youtube_videos: boolean;
  } | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [projectExists, setProjectExists] = useState(false);

  useEffect(() => {
    if (id) {
      loadProject();
    }
  }, [id]);

  const loadProject = async () => {
    try {
      const result = await viewsMaxApi.getProject(id!);

      if (!result.success) {
        if (result.error?.includes('not found')) {
          toast.error("Project not found");
          navigate("/dashboard/projects");
          return;
        }
        throw new Error(result.error || 'Failed to load project');
      }

      if (result.data) {
        setProjectData({
          title: result.data.title || "",
          script: result.data.script || "",
          include_motion_graphics: result.data.include_motion_graphics || false,
          include_references: result.data.include_references || false,
          include_youtube_videos: result.data.include_youtube_videos || false,
        });
        setProjectExists(true);
      }
    } catch (error) {
      console.error('Error loading project:', error);
      toast.error("Failed to load project");
      navigate("/dashboard/projects");
    } finally {
      setIsLoading(false);
    }
  };

  const handleSave = async (data: {
    title: string;
    script: string;
    include_motion_graphics: boolean;
    include_references: boolean;
    include_youtube_videos: boolean;
  }) => {
    try {
      const result = await viewsMaxApi.updateProject(id!, data);

      if (!result.success) {
        throw new Error(result.error || 'Failed to update project');
      }
      
      toast.success("Project updated successfully!");
      navigate("/dashboard/projects");
    } catch (error) {
      console.error('Error updating project:', error);
      toast.error("Failed to update project. Please try again.");
    }
  };

  const handleCancel = () => {
    navigate("/dashboard/projects");
  };

  if (isLoading) {
    return (
      <div className="space-y-6 max-w-4xl">
        <div className="flex items-center gap-4">
          <Button 
            variant="ghost" 
            onClick={() => navigate("/dashboard/projects")}
            className="gap-2"
          >
            <ArrowLeft className="w-4 h-4" />
            Back to Projects
          </Button>
          <div>
            <h2 className="text-2xl font-bold text-foreground">Loading...</h2>
            <p className="text-muted-foreground">Please wait while we load your project</p>
          </div>
        </div>
      </div>
    );
  }

  if (!projectExists || !projectData) {
    return (
      <div className="space-y-6 max-w-4xl">
        <div className="flex items-center gap-4">
          <Button 
            variant="ghost" 
            onClick={() => navigate("/dashboard/projects")}
            className="gap-2"
          >
            <ArrowLeft className="w-4 h-4" />
            Back to Projects
          </Button>
          <div>
            <h2 className="text-2xl font-bold text-foreground">Project Not Found</h2>
            <p className="text-muted-foreground">The project you're looking for doesn't exist</p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-4xl">
      <ProjectForm
        mode="edit"
        initialData={projectData}
        onSave={handleSave}
        onCancel={handleCancel}
      />
    </div>
  );
};

export default EditProject;