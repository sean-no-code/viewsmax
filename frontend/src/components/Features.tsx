import { useState } from "react";
import { ImageIcon, ScrollText, TrendingUp, Star, Globe } from "lucide-react";

const Features = () => {
  const [activeTab, setActiveTab] = useState("Thumbnail Maker");

  const features = [
    {
      id: "Thumbnail Maker",
      title: "Thumbnail Maker",
      icon: ImageIcon,
      videoId: "dQw4w9WgXcQ", // Replace with actual demo video ID
    },
    {
      id: "Script Writer",
      title: "Script Writer",
      icon: ScrollText,
      videoId: "dQw4w9WgXcQ", // Replace with actual demo video ID
    },
    {
      id: "Trend Finder",
      title: "Trend Finder",
      icon: TrendingUp,
      videoId: "dQw4w9WgXcQ", // Replace with actual demo video ID
    },
    {
      id: "Review Content",
      title: "Review Content",
      icon: Star,
      videoId: "dQw4w9WgXcQ", // Replace with actual demo video ID
    },
    {
      id: "Chrome Extension",
      title: "Chrome Extension",
      icon: Globe,
      videoId: "dQw4w9WgXcQ", // Replace with actual demo video ID
    },
  ];

  const activeFeature = features.find((f) => f.id === activeTab) || features[0];

  return (
    <section id="features" className="py-20 bg-background">
      <div className="container mx-auto px-4">
        <div className="text-center mb-16">
          <h2 className="text-3xl md:text-4xl font-bold text-foreground mb-4">
            Stop Guessing. Start Growing.
          </h2>
          <p className="text-xl text-muted-foreground max-w-2xl mx-auto">
            Everything you need to <span className="text-primary font-semibold">find and create</span> content that actually gets views, subscribers, and engagement
          </p>
        </div>

        <div className="grid lg:grid-cols-3 gap-8 max-w-7xl mx-auto">
          {/* Left Navigation */}
          <div className="lg:col-span-1">
            <nav className="space-y-2">
              {features.map((feature) => {
                const Icon = feature.icon;
                const isActive = activeTab === feature.id;
                return (
                  <button
                    key={feature.id}
                    onClick={() => setActiveTab(feature.id)}
                    className={`w-full flex items-center gap-3 px-4 py-3 rounded-lg text-left transition-all duration-200 ${
                      isActive
                        ? "bg-primary text-primary-foreground shadow-lg"
                        : "bg-secondary/50 text-foreground hover:bg-secondary/70"
                    }`}
                  >
                    <Icon className={`w-5 h-5 ${isActive ? "text-primary-foreground" : "text-muted-foreground"}`} />
                    <span className="font-medium">{feature.title}</span>
                  </button>
                );
              })}
            </nav>
          </div>

          {/* Right Video Player */}
          <div className="lg:col-span-2">
            <div className="bg-secondary/30 rounded-lg overflow-hidden shadow-lg">
              <div className="aspect-video">
                <iframe
                  className="w-full h-full"
                  src={`https://www.youtube.com/embed/${activeFeature.videoId}?autoplay=1&mute=1&loop=1&playlist=${activeFeature.videoId}`}
                  title={activeFeature.title}
                  allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                  allowFullScreen
                />
              </div>
            </div>
            <div className="mt-4">
              <h3 className="text-xl font-semibold text-foreground mb-2">
                {activeFeature.title}
              </h3>
              <p className="text-muted-foreground">
                See how {activeFeature.title} helps you create better content and grow your channel.
              </p>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
};

export default Features;
