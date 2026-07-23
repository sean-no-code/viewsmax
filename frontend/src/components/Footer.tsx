import { TrendingUp } from "lucide-react";
import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";

// Chrome Icon Component
const ChromeIcon = ({ className }: { className?: string }) => (
  <svg
    className={className}
    viewBox="0 0 24 24"
    fill="none"
    xmlns="http://www.w3.org/2000/svg"
  >
    <circle cx="12" cy="12" r="11" fill="#4285F4" />
    <path
      d="M12 2C6.48 2 2 6.48 2 12c0 1.85.63 3.55 1.69 4.9L8.5 12l-4.81-4.81C5.28 5.45 8.29 2 12 2z"
      fill="#EA4335"
    />
    <path
      d="M12 2c3.71 0 6.72 3.45 8.31 5.19L12 12 3.69 7.19C5.28 5.45 8.29 2 12 2z"
      fill="#FBBC04"
    />
    <path
      d="M12 22c-3.71 0-6.72-3.45-8.31-5.19L12 12l8.31 4.81C18.72 18.55 15.71 22 12 22z"
      fill="#34A853"
    />
    <circle cx="12" cy="12" r="4" fill="#4285F4" stroke="white" strokeWidth="0.5" />
    <circle cx="12" cy="12" r="2.5" fill="white" />
  </svg>
);

const Footer = () => {
  return (
    <footer className="bg-muted/30 border-t border-border">
      <div className="container mx-auto px-4 py-12">
        <div className="grid grid-cols-1 md:grid-cols-5 gap-8">
          {/* Brand */}
          <div className="col-span-1 md:col-span-2">
            <div className="flex items-center gap-2 mb-4">
              <div className="w-8 h-8 bg-gradient-primary rounded-lg flex items-center justify-center">
                <TrendingUp className="w-4 h-4 text-primary-foreground" />
              </div>
              <span className="text-xl font-bold text-foreground">ViewsMax</span>
            </div>
            <p className="text-muted-foreground max-w-md">
              Transform your content with AI-powered analytics, trend insights, and content optimization tools
            </p>
          </div>

          {/* Navigation */}
          <div>
            <h3 className="font-semibold text-foreground mb-4">Navigation</h3>
            <ul className="space-y-2 text-muted-foreground">
              <li><a href="#features" className="hover:text-primary transition-colors">Features</a></li>
              <li><a href="#reviews" className="hover:text-primary transition-colors">Reviews</a></li>
              <li><a href="#pricing" className="hover:text-primary transition-colors">Pricing</a></li>
            </ul>
          </div>

          {/* Legal */}
          <div>
            <h3 className="font-semibold text-foreground mb-4">Legal</h3>
            <ul className="space-y-2 text-muted-foreground">
              <li><Link to="/privacy" className="hover:text-primary transition-colors">Privacy Policy</Link></li>
              <li><a href="/terms" className="hover:text-primary transition-colors">Terms of Service</a></li>
            </ul>
          </div>

          {/* Chrome Extension */}
          <div>
            <div className="flex items-center justify-between mb-2">
              <div className="flex items-center gap-2">
                <ChromeIcon className="w-5 h-5" />
                <h3 className="font-semibold text-foreground">Free Chrome Extension</h3>
              </div>
              <Button
                variant="default"
                size="sm"
                className="bg-primary hover:bg-primary/90"
                asChild
              >
                <a
                  href="https://chromewebstore.google.com/detail/viewsmax/mefpcplmigglgpjbjbnogaldnpbnddib"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  Install
                </a>
              </Button>
            </div>
            <p className="text-muted-foreground text-sm">
              Easily view video and channel analytics while browsing YouTube
            </p>
          </div>
        </div>

        <div className="border-t border-border mt-8 pt-8 flex flex-col md:flex-row justify-between items-center">
          <p className="text-muted-foreground text-sm">
            © {new Date().getFullYear()} ViewsMax. All rights reserved.
          </p>
          <div className="flex items-center gap-6 mt-4 md:mt-0">
            <p className="text-muted-foreground text-sm">
              Built with YouTube API Services
            </p>
            <a
              href="https://www.youtube.com/t/terms"
              target="_blank"
              rel="noopener noreferrer"
              className="text-muted-foreground hover:text-primary text-sm transition-colors"
            >
              YouTube Terms
            </a>
            <a
              href="http://www.google.com/policies/privacy"
              target="_blank"
              rel="noopener noreferrer"
              className="text-muted-foreground hover:text-primary text-sm transition-colors"
            >
              Google Privacy Policy
            </a>
          </div>
        </div>
      </div>
    </footer>
  );
};

export default Footer;

