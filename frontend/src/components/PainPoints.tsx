import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { ArrowRight, Zap, TrendingUp, BarChart3 } from "lucide-react";
import { Link } from "react-router-dom";
import painpoint1 from "@/assets/painpoint-1.jpg";
import painpoint2 from "@/assets/painpoint-2.jpg";
import painpoint3 from "@/assets/painpoint-3.jpg";

const painPoints = [
  {
    id: 1,
    title: "Struggling with Titles & Scripts",
    problem: "Hours spent staring at blank pages, trying to craft the perfect title or script that will actually get views.",
    solution: "Our AI instantly generates compelling titles, engaging scripts, and optimized descriptions tailored to your niche.",
    image: painpoint1,
    icon: Zap,
    color: "text-red-500"
  },
  {
    id: 2,
    title: "Can't Find Trending Ideas",
    problem: "Endless scrolling through competitors' channels, wondering what content will actually resonate with your audience.",
    solution: "TrendFinder shows you outliers, rising videos, and unique ideas specifically for your niche before they go viral.",
    image: painpoint2,
    icon: TrendingUp,
    color: "text-orange-500"
  },
  {
    id: 3,
    title: "Old Content Underperforming",
    problem: "Your previous videos aren't getting the views they deserve, but you don't know how to fix the titles, thumbnails, or descriptions.",
    solution: "Our AI reviews your existing content and provides specific instructions on how to optimize for better performance.",
    image: painpoint3,
    icon: BarChart3,
    color: "text-blue-500"
  }
];

const PainPoints = () => {
  return (
    <section className="py-20 bg-muted/30">
      <div className="container mx-auto px-4">
        <div className="text-center mb-16">
          <h2 className="text-3xl md:text-4xl font-bold text-foreground mb-4">
            Stop Fighting These YouTube Challenges
          </h2>
          <p className="text-xl text-muted-foreground max-w-3xl mx-auto">
            Every successful creator has faced these roadblocks. Here's how we solve them for you.
          </p>
        </div>

        <div className="grid md:grid-cols-3 gap-8 mb-12">
          {painPoints.map((point) => (
            <Card key={point.id} className="group hover:shadow-lg transition-all duration-300 hover:-translate-y-1">
              <CardContent className="p-0">
                <div className="relative overflow-hidden rounded-t-lg">
                  <img
                    src={point.image}
                    alt={point.title}
                    className="w-full h-48 object-cover group-hover:scale-105 transition-transform duration-300"
                  />
                  <div className="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent" />
                  <div className="absolute bottom-4 left-4 flex items-center space-x-2">
                    <point.icon className={`h-6 w-6 ${point.color}`} />
                    <span className="text-white font-semibold">{point.title}</span>
                  </div>
                </div>
                
                <div className="p-6">
                  <div className="mb-4">
                    <h3 className="font-semibold text-destructive mb-2">The Problem:</h3>
                    <p className="text-sm text-muted-foreground mb-4">{point.problem}</p>
                  </div>
                  
                  <div className="border-t pt-4">
                    <h3 className="font-semibold text-primary mb-2">Our Solution:</h3>
                    <p className="text-sm text-foreground">{point.solution}</p>
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>

        <div className="text-center">
          <Button asChild size="lg" className="group">
            <Link to="/auth">
              Start Solving These Problems Today
              <ArrowRight className="ml-2 h-4 w-4 group-hover:translate-x-1 transition-transform" />
            </Link>
          </Button>
          <p className="text-sm text-muted-foreground mt-3">
            Free 7-day trial
          </p>
        </div>
      </div>
    </section>
  );
};

export default PainPoints;