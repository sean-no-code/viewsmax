import { useParams, useNavigate } from "react-router-dom";
import { ScriptWizard } from "@/components/scripts";
import { Script } from "@/lib/api-service";
import { Button } from "@/components/ui/button";
import { ArrowLeft } from "lucide-react";

const ScriptsCreate = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();

  const handleScriptGenerated = (script: Script) => {
    console.log("Script generated:", script);
    navigate(`/dashboard/scripts/edit/${script.id}`);
  };

  return (
    <div className="space-y-6">
      {/* Header */}


      {/* Wizard */}
      <ScriptWizard
        onScriptGenerated={handleScriptGenerated}
      />
    </div>
  );
};

export default ScriptsCreate;
