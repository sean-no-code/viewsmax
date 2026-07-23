import { useEffect } from "react";
import { useNavigate } from "react-router-dom";
import ViewsMaxLanding from "@/pages/landing/ViewsMaxLanding";
import CookieNotice from "@/components/CookieNotice";
import { useAuth } from "@/hooks/useAuth";

const Index = () => {
  const { user } = useAuth();
  const navigate = useNavigate();

  useEffect(() => {
    if (user) {
      navigate('/dashboard', { replace: true });
    }
  }, [user, navigate]);

  // Don't render landing page content if user is logged in (redirect will happen)
  if (user) {
    return null;
  }

  return (
    <>
      <ViewsMaxLanding />
      <CookieNotice />
    </>
  );
};

export default Index;
