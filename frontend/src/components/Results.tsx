import { Button } from "@/components/ui/button";
import { ArrowRight, TrendingUp, Youtube } from "lucide-react";
import { Link } from "react-router-dom";

const Results = () => {
  return (
    <section className="py-20 bg-background">
      <div className="container mx-auto px-4">
        <div className="grid lg:grid-cols-2 gap-12 items-center">
          {/* Left side - Image */}
          <div className="relative">
            <div className="relative overflow-hidden rounded-lg shadow-2xl">
              <img
                src="/lovable-uploads/9b8a0dca-167e-4edf-a71e-7974324e8095.png"
                alt="Sean Facer YouTube Channel - 100 Subscribers Growth"
                className="w-full h-auto"
              />
              <div className="absolute inset-0 bg-gradient-to-t from-black/20 to-transparent" />
            </div>
            
            {/* Stats overlay */}
            <div className="absolute -bottom-6 -right-6 bg-primary text-primary-foreground p-4 rounded-lg shadow-lg">
              <div className="flex items-center space-x-2">
                <TrendingUp className="h-5 w-5" />
                <div>
                  <div className="font-bold text-lg">100 Subscribers</div>
                  <div className="text-sm opacity-90">25/08/25</div>
                </div>
              </div>
            </div>
          </div>

          {/* Right side - Content */}
          <div className="space-y-6">
            <div>
              <h2 className="text-3xl md:text-4xl font-bold text-foreground mb-4">
                Don't Take My Word For It
              </h2>
              <p className="text-xl text-muted-foreground mb-6">
                I built this tool to grow my YouTube channel. Check it out for yourself and see if it's working.
              </p>
            </div>

            <div className="space-y-4">
              <div className="bg-muted/50 p-6 rounded-lg border-l-4 border-primary">
                <p className="text-foreground mb-2">
                  <strong>Currently at 100 subscribers</strong> at the time of writing (25/08/25).
                </p>
                <p className="text-muted-foreground text-sm">
                  Follow my journey as I use my own tool to grow from zero to hero.
                </p>
              </div>

              <div className="bg-gradient-to-r from-primary/10 to-accent/10 p-6 rounded-lg">
                <p className="text-foreground font-medium mb-2">
                  I stand behind this tool 100%
                </p>
                <p className="text-muted-foreground">
                  I want it to be the best possible tool out there for YouTube creators. That's why I'm using it myself and sharing my real results with you.
                </p>
              </div>
            </div>

            <div className="pt-4">
              <div className="flex flex-col sm:flex-row gap-3">
                <Button asChild size="lg" className="group">
                  <Link to="/auth">
                    Start Your Growth Journey
                    <ArrowRight className="ml-2 h-4 w-4 group-hover:translate-x-1 transition-transform" />
                  </Link>
                </Button>
                <Button
                  variant="secondary"
                  size="lg"
                  className="group"
                  asChild
                >
                  <a
                    href="https://www.youtube.com/@seanfacer"
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    <Youtube className="mr-2 h-4 w-4" />
                    Check Out My Channel
                    <ArrowRight className="ml-2 h-4 w-4 group-hover:translate-x-1 transition-transform" />
                  </a>
                </Button>
              </div>
              <p className="text-sm text-muted-foreground mt-3">
                Join me and see the results for yourself
              </p>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
};

export default Results;