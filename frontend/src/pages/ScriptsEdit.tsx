import { useParams, useLocation, useNavigate } from "react-router-dom";
import { ScriptWizard } from "@/components/scripts";
import { Button } from "@/components/ui/button";
import { ArrowLeft } from "lucide-react";
import { Script } from "@/lib/api-service";

const ScriptsEdit = () => {
  const { id } = useParams<{ id: string }>();
  // const location = useLocation(); // Not using auto-submit for now in Wizard flow
  const navigate = useNavigate();

  const handleScriptGenerated = (script: Script) => {
    // Maybe stay on page or show success
  };

  return (
    <div className="space-y-6">
      <ScriptWizard
        scriptId={id}
        onScriptGenerated={handleScriptGenerated}
      />
    </div>
  );
};

export default ScriptsEdit;





