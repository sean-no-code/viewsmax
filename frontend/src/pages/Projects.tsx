import { useState, useEffect } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Plus, FolderOpen, Calendar, Users, Edit, Trash2 } from "lucide-react";
import { useNavigate } from "react-router-dom";
import { viewsMaxApi } from "@/lib/api-service";
import { useAuth } from "@/hooks/useAuth";
import { toast } from "sonner";

const Projects = () => {
  const navigate = useNavigate();
  const { user } = useAuth();
  const [projects, setProjects] = useState<any[]>([]);

  const handleDeleteProject = async (projectId: string) => {
    try {
      if (!user) {
        toast.error("Please log in to delete projects");
        return;
      }

      const result = await viewsMaxApi.deleteProject(projectId);

      if (!result.success) {
        throw new Error(result.error || 'Failed to delete project');
      }

      setProjects(projects.filter(project => project.id !== projectId));
      toast.success("Project deleted successfully");
    } catch (error) {
      console.error('Error deleting project:', error);
      toast.error("Failed to delete project");
    }
  };

  const loadProjects = async () => {
    try {
      if (!user) {
        console.log('No user authenticated, skipping projects load');
        setProjects([]);
        return;
      }

      const result = await viewsMaxApi.getProjects();

      if (!result.success) {
        throw new Error(result.error || 'Failed to load projects');
      }

      setProjects(result.data || []);
    } catch (error) {
      console.error('Error loading projects:', error);
      toast.error("Failed to load projects");
    }
  };

  useEffect(() => {
    loadProjects();
  }, [user]);


  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-foreground">Projects</h2>
          <p className="text-muted-foreground">Manage your video projects and scripts</p>
        </div>
        <Button 
          variant="hero" 
          className="gap-2"
          onClick={() => navigate("/dashboard/projects/new")}
        >
          <Plus className="w-4 h-4" />
          New Project
        </Button>
      </div>

      <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
        {projects.map((project) => (
          <Card key={project.id} className="hover:shadow-card transition-shadow cursor-pointer">
            <CardHeader>
              <div className="flex items-center justify-between">
                <FolderOpen className="w-8 h-8 text-primary" />
                <div className="flex gap-1">
                  <Button 
                    variant="ghost" 
                    size="sm" 
                    className="p-1 h-6 w-6 bg-gray-200 hover:bg-gray-300 text-gray-700 hover:text-gray-800"
                    onClick={(e) => {
                      e.stopPropagation();
                      navigate(`/dashboard/projects/${project.id}/edit`);
                    }}
                  >
                    <Edit className="w-3 h-3" />
                  </Button>
                  <Button 
                    variant="ghost" 
                    size="sm" 
                    className="p-1 h-6 w-6 text-destructive hover:text-destructive"
                    onClick={(e) => {
                      e.stopPropagation();
                      handleDeleteProject(project.id);
                    }}
                  >
                    <Trash2 className="w-3 h-3" />
                  </Button>
                </div>
              </div>
              <CardTitle className="text-lg">{project.title}</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center gap-1 text-sm text-muted-foreground">
                <Calendar className="w-4 h-4" />
                <span>{new Date(project.created_at).toLocaleDateString()}</span>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
};

export default Projects;