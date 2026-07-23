import { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import { viewsMaxApi } from '@/lib/api-service';

interface UserCreditsContextType {
  credits: number;
  setCredits: (credits: number) => void;
  updateCredits: (newCredits: number) => void;
  isAnimating: boolean;
}

const UserCreditsContext = createContext<UserCreditsContextType | undefined>(undefined);

export const UserCreditsProvider = ({ children }: { children: ReactNode }) => {
  const [credits, setCredits] = useState<number>(0);
  const [displayCredits, setDisplayCredits] = useState<number>(0);
  const [isAnimating, setIsAnimating] = useState(false);

  // Load credits from localStorage on mount
  useEffect(() => {
    const storedCredits = localStorage.getItem('user_credits');
    if (storedCredits) {
      const parsedCredits = parseInt(storedCredits, 10);
      setCredits(parsedCredits);
      setDisplayCredits(parsedCredits);
    }
  }, []);

  const updateCredits = (newCredits: number) => {
    setCredits((oldCredits) => {
      localStorage.setItem('user_credits', newCredits.toString());
      
      // Trigger countdown animation if credits decreased
      if (newCredits < oldCredits) {
        setIsAnimating(true);
        
        // Animate countdown from old to new value
        const difference = oldCredits - newCredits;
        const steps = Math.min(difference, 20); // Limit steps for performance
        const stepSize = difference / steps;
        const duration = 1000; // 1 second
        const stepDuration = duration / steps;
        
        let currentStep = 0;
        const interval = setInterval(() => {
          currentStep++;
          const currentValue = Math.max(newCredits, Math.round(oldCredits - (stepSize * currentStep)));
          setDisplayCredits(currentValue);
          
          if (currentStep >= steps) {
            setDisplayCredits(newCredits);
            clearInterval(interval);
            setTimeout(() => setIsAnimating(false), 200);
          }
        }, stepDuration);
      } else {
        // If credits increased, just update immediately
        setDisplayCredits(newCredits);
      }
      
      return newCredits;
    });
  };

  // Register callback with API service to receive credit updates
  useEffect(() => {
    viewsMaxApi.setCreditsUpdateCallback(updateCredits);

    // Cleanup on unmount
    return () => {
      viewsMaxApi.setCreditsUpdateCallback(null);
    };
  }, []);

  return (
    <UserCreditsContext.Provider value={{ credits: displayCredits, setCredits, updateCredits, isAnimating }}>
      {children}
    </UserCreditsContext.Provider>
  );
};

export const useUserCredits = () => {
  const context = useContext(UserCreditsContext);
  if (context === undefined) {
    throw new Error('useUserCredits must be used within a UserCreditsProvider');
  }
  return context;
};

