import { useLocation } from "react-router-dom";
import TitlesForm from "@/components/TitlesForm";

const TitlesNew = () => {
  const location = useLocation();

  return (
    <TitlesForm 
      projectDescription={location.state?.project}
      autoSubmit={location.state?.autoSubmit}
    />
  );
};

export default TitlesNew;
