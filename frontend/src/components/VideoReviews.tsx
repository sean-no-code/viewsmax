import { Star } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { useEffect, useState } from "react";
import {
  Carousel,
  CarouselContent,
  CarouselItem,
} from "@/components/ui/carousel";

const VideoReviews = () => {
  const testimonials = [
    {
      name: "Sarah Martinez",
      channel: "@CreatorSarah",
      quote: "ViewsMax helped me go from 10K to 100K+ subscribers in just 6 months. The AI trend predictions are scary accurate!",
      avatar: "https://i.pravatar.cc/150?img=47",
      faceIcon: "😊",
    },
    {
      name: "Mike Johnson",
      channel: "@TechMike",
      quote: "The script generator is a game-changer. My videos now get 3x more engagement and the AI suggestions always hit different. Can't imagine creating content without it!",
      avatar: "https://i.pravatar.cc/150?img=12",
      faceIcon: "😄",
    },
    {
      name: "Jessica Chen",
      channel: "@LifestyleJess",
      quote: "Finally, a tool that actually understands YouTube!",
      avatar: "https://i.pravatar.cc/150?img=33",
      faceIcon: "😃",
    },
    {
      name: "David Thompson",
      channel: "@DavidCreates",
      quote: "ViewsMax transformed my channel completely. The thumbnail generator alone increased my CTR by 40%! The analytics insights are incredibly detailed and actionable.",
      avatar: "https://i.pravatar.cc/150?img=15",
      faceIcon: "😁",
    },
    {
      name: "Emily Rodriguez",
      channel: "@EmilyVlogs",
      quote: "I've tried every tool out there, but ViewsMax is the only one that actually delivers results.",
      avatar: "https://i.pravatar.cc/150?img=20",
      faceIcon: "😊",
    },
    {
      name: "Alex Kim",
      channel: "@AlexTech",
      quote: "The AI-powered title suggestions are incredible. My latest video went viral thanks to ViewsMax's recommendations! The platform is intuitive and saves me so much time.",
      avatar: "https://i.pravatar.cc/150?img=32",
      faceIcon: "😎",
    },
    {
      name: "Maria Garcia",
      channel: "@MariaContent",
      quote: "ViewsMax saved me hours of work every week.",
      avatar: "https://i.pravatar.cc/150?img=45",
      faceIcon: "😊",
    },
    {
      name: "James Wilson",
      channel: "@JamesReviews",
      quote: "Best investment I've made for my channel. ViewsMax helped me reach 200K subscribers faster than I ever imagined! The support team is also fantastic and always ready to help.",
      avatar: "https://i.pravatar.cc/150?img=8",
      faceIcon: "😄",
    },
  ];

  const [api, setApi] = useState<any>(null);

  useEffect(() => {
    if (!api) {
      return;
    }

    // Auto-scroll every 4 seconds
    const interval = setInterval(() => {
      if (api.canScrollNext()) {
        api.scrollNext();
      } else {
        // If at the end, scroll to the beginning
        api.scrollTo(0);
      }
    }, 4000);

    return () => clearInterval(interval);
  }, [api]);

  return (
    <section id="reviews" className="py-20 bg-secondary/30">
      <div className="container mx-auto px-4">
        <Carousel
          setApi={setApi}
          opts={{
            align: "start",
            loop: true,
          }}
          className="w-full max-w-7xl mx-auto"
        >
          <CarouselContent className="-ml-2 md:-ml-4">
            {testimonials.map((testimonial, index) => (
              <CarouselItem key={index} className="pl-2 md:pl-4 md:basis-1/2 lg:basis-1/3">
                <Card className="group hover:shadow-card transition-all duration-300 border-border/50 h-full">
                  <CardContent className="p-6 relative h-full flex flex-col">
                    {/* Content */}
                    <div className="mb-4 flex items-start justify-between">
                      <div className="flex items-center gap-2">
                        <img 
                          src={testimonial.avatar} 
                          alt={testimonial.name}
                          className="w-8 h-8 rounded-full object-cover"
                          onError={(e) => {
                            // Fallback to a default avatar if image fails to load
                            (e.target as HTMLImageElement).src = `https://ui-avatars.com/api/?name=${encodeURIComponent(testimonial.name)}&background=random&size=64`;
                          }}
                        />
                        <div className="font-semibold text-foreground">{testimonial.name}</div>
                      </div>
                      <div className="flex items-center gap-1">
                        {[...Array(5)].map((_, i) => (
                          <Star key={i} className="w-4 h-4 fill-yellow-400 text-yellow-400" />
                        ))}
                      </div>
                    </div>

                    <blockquote className="text-muted-foreground italic flex-grow">
                      "{testimonial.quote}"
                    </blockquote>

                    {/* Verified purchase - bottom right */}
                    <div className="text-sm text-muted-foreground mt-auto pt-4 text-right">
                      ✅ Verified purchase
                    </div>
                  </CardContent>
                </Card>
              </CarouselItem>
            ))}
          </CarouselContent>
        </Carousel>

        <div className="text-center mt-12">
          <p className="text-muted-foreground mb-4">Ready to join them?</p>
          <div className="flex items-center justify-center gap-4 text-sm text-muted-foreground">
            <div className="flex items-center gap-2">
              <div className="w-2 h-2 bg-green-500 rounded-full"></div>
              <span>5000+ creators</span>
            </div>
            <div className="flex items-center gap-2">
              <div className="w-2 h-2 bg-green-500 rounded-full"></div>
              <span>2M+ views generated</span>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
};

export default VideoReviews;
