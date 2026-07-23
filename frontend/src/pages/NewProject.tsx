import { useNavigate } from "react-router-dom";
import { toast } from "sonner";
import { useAuth } from "@/hooks/useAuth";
import { viewsMaxApi } from "@/lib/api-service";
import ProjectForm from "@/components/ProjectForm";

const NewProject = () => {
  const navigate = useNavigate();
  const { user } = useAuth();

  const handleSave = async (data: {
    title: string;
    script: string;
    include_motion_graphics: boolean;
    include_references: boolean;
    include_youtube_videos: boolean;
  }) => {
    try {
      if (!user) {
        toast.error("Please log in to save projects");
        return;
      }

      const result = await viewsMaxApi.saveProject(data);

      if (!result.success) {
        throw new Error(result.error || 'Failed to save project');
      }
      
      toast.success("Project saved successfully!");
      navigate("/dashboard/projects");
    } catch (error) {
      console.error('Error saving project:', error);
      toast.error("Failed to save project. Please try again.");
    }
  };

  const handleCancel = () => {
    navigate("/dashboard/projects");
  };

  return (
    <div className="space-y-6 max-w-4xl">
      <ProjectForm
        mode="create"
        onSave={handleSave}
        onCancel={handleCancel}
      />
    </div>
  );
};

export default NewProject;