import { useParams, useLocation } from "react-router-dom";
import TitlesForm from "@/components/TitlesForm";

const TitlesEdit = () => {
  const { id } = useParams<{ id: string }>();
  const location = useLocation();

  // Only auto-submit if we came from the titles index page (wand icon click)
  const shouldAutoSubmit = location.state?.fromWandClick === true;

  return <TitlesForm projectId={id} autoSubmit={shouldAutoSubmit} />;
};

export default TitlesEdit;
