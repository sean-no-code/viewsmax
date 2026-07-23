import { Button } from "@/components/ui/button";
import { ArrowRight, X, BarChart3 } from "lucide-react";
import { useState, useEffect } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "@/hooks/useAuth";

const StickyCtA = () => {
  const { user } = useAuth();
  const [isVisible, setIsVisible] = useState(false);
  const [isDismissed, setIsDismissed] = useState(false);

  useEffect(() => {
    // Don't set up scroll listener if user is logged in
    if (user) return;

    const handleScroll = () => {
      // Show sticky CTA after scrolling past hero section (roughly 80vh)
      const scrolled = window.scrollY > window.innerHeight * 0.8;
      setIsVisible(scrolled && !isDismissed);
    };

    window.addEventListener('scroll', handleScroll);
    return () => window.removeEventListener('scroll', handleScroll);
  }, [isDismissed, user]);

  // Don't render anything if user is logged in or if not visible
  if (user || !isVisible) return null;

  return (
    <div className="fixed bottom-6 left-1/2 transform -translate-x-1/2 z-50 animate-fade-in">
      <div className="bg-background/95 backdrop-blur-md border border-border/50 rounded-full shadow-hero p-2 flex items-center gap-3">
        <Button variant="cta" size="lg" className="rounded-full font-semibold" asChild>
          <Link to="/auth">
            Sign Up Free
            <ArrowRight className="w-4 h-4 ml-2" />
          </Link>
        </Button>
        <Button 
          variant="ghost" 
          size="icon"
          className="rounded-full w-8 h-8"
          onClick={() => setIsDismissed(true)}
        >
          <X className="w-4 h-4" />
        </Button>
      </div>
    </div>
  );
};

export default StickyCtA;