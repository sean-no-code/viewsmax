import { Button } from "@/components/ui/button";
import { ArrowRight, Sparkles, BarChart3 } from "lucide-react";
import { Link } from "react-router-dom";
import { useAuth } from "@/hooks/useAuth";

const CTA = () => {
  const { user } = useAuth();

  return (
    <section className="py-20 bg-gradient-hero relative overflow-hidden">
      <div className="absolute inset-0 bg-black/10"></div>
      <div className="container mx-auto px-4 relative z-10">
        <div className="max-w-4xl mx-auto text-center">
          <div className="flex items-center justify-center gap-2 mb-6">
            <Sparkles className="w-6 h-6 text-white/90" />
            <span className="text-white/90 font-medium">Join 5000+ Successful Creators</span>
          </div>
          
          <h2 className="text-3xl md:text-5xl font-bold text-white mb-6">
            Your Breakthrough Moment
            <br />
            Starts Right Now
          </h2>
          
          <p className="text-xl text-white/90 mb-8 max-w-2xl mx-auto">
            Join 5000+ creators who've already transformed their channels. 
            Your first viral video is just one sign-up away.
          </p>

          <div className="flex flex-col sm:flex-row gap-4 justify-center mb-8">
            {user ? (
              <Button 
                variant="secondary" 
                size="lg" 
                className="group bg-white text-primary hover:bg-white/90 font-semibold"
                asChild
              >
                <Link to="/dashboard">
                  <BarChart3 className="w-4 h-4 group-hover:scale-110 transition-transform mr-2" />
                  Go to Dashboard
                  <ArrowRight className="w-4 h-4 group-hover:translate-x-1 transition-transform" />
                </Link>
              </Button>
            ) : (
              <Button 
                variant="secondary" 
                size="lg" 
                className="group bg-white text-primary hover:bg-white/90 font-semibold"
                asChild
              >
                <Link to="/auth">
                  Sign Up Free
                  <ArrowRight className="w-4 h-4 group-hover:translate-x-1 transition-transform" />
                </Link>
              </Button>
            )}
            <Button 
              variant="outline" 
              size="lg" 
              className="border-white/30 text-white hover:bg-white/10 bg-white/10 hover:text-white"
            >
              Watch Success Stories
            </Button>
          </div>

          <div className="flex items-center justify-center gap-8 text-white/80 text-sm">
            <div className="flex items-center gap-2">
              <div className="w-2 h-2 bg-green-400 rounded-full"></div>
              <span>Free 7-day trial</span>
            </div>
            <div className="flex items-center gap-2">
              <div className="w-2 h-2 bg-green-400 rounded-full"></div>
              <span>Cancel anytime</span>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
};

export default CTA;