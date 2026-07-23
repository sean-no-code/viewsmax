import { ReactNode } from 'react';

interface ProFeatureRouteProps {
  children: ReactNode;
}

const ProFeatureRoute = ({ children }: ProFeatureRouteProps) => {
  // Pass-through component - no longer redirects to billing
  return <>{children}</>;
};

export default ProFeatureRoute;
