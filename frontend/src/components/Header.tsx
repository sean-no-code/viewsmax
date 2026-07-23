import { useState } from "react";
import { Button } from "@/components/ui/button";
import { TrendingUp, Menu, BarChart3, LogOut, User, Crown, ChevronDown, Youtube, Calculator, ImageIcon } from "lucide-react";
import { Link } from "react-router-dom";
import { useAuth } from "@/hooks/useAuth";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from "@/components/ui/sheet";

// Chrome Icon Component - More accurate Chrome logo
const ChromeIcon = ({ className }: { className?: string }) => (
  <svg
    className={className}
    viewBox="0 0 24 24"
    fill="none"
    xmlns="http://www.w3.org/2000/svg"
  >
    {/* Outer circle background */}
    <circle cx="12" cy="12" r="11" fill="#4285F4" />
    
    {/* Red segment (left) */}
    <path
      d="M12 2C6.48 2 2 6.48 2 12c0 1.85.63 3.55 1.69 4.9L8.5 12l-4.81-4.81C5.28 5.45 8.29 2 12 2z"
      fill="#EA4335"
    />
    
    {/* Yellow segment (top right) */}
    <path
      d="M12 2c3.71 0 6.72 3.45 8.31 5.19L12 12 3.69 7.19C5.28 5.45 8.29 2 12 2z"
      fill="#FBBC04"
    />
    
    {/* Green segment (bottom right) */}
    <path
      d="M12 22c-3.71 0-6.72-3.45-8.31-5.19L12 12l8.31 4.81C18.72 18.55 15.71 22 12 22z"
      fill="#34A853"
    />
    
    {/* Blue center circle with white border */}
    <circle cx="12" cy="12" r="4" fill="#4285F4" stroke="white" strokeWidth="0.5" />
    <circle cx="12" cy="12" r="2.5" fill="white" />
  </svg>
);
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

const Header = () => {
  const { user, signOut } = useAuth();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  return (
    <header className="fixed top-0 left-0 right-0 bg-background/95 backdrop-blur-md border-b border-border z-50">
      <div className="container mx-auto px-4 h-16 flex items-center justify-between">
        <Link to="/" className="flex items-center gap-2">
          <div className="w-8 h-8 bg-gradient-primary rounded-lg flex items-center justify-center">
            <TrendingUp className="w-4 h-4 text-primary-foreground" />
          </div>
          <span className="text-xl font-bold text-foreground">ViewsMax</span>
        </Link>
        
        <nav className="hidden md:flex items-center gap-8">
          <Link to="/#features" className="text-muted-foreground hover:text-primary transition-colors font-medium">Features</Link>
          <Link to="/#reviews" className="text-muted-foreground hover:text-primary transition-colors font-medium">Reviews</Link>
          <Link to="/#pricing" className="text-muted-foreground hover:text-primary transition-colors font-medium">Pricing</Link>
          
          <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
              <button className="text-muted-foreground hover:text-primary transition-colors font-medium flex items-center gap-1">
                Tools
                <ChevronDown className="w-4 h-4" />
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
              <DropdownMenuItem asChild>
                <Link to="/youtube-monetization-calculator" className="cursor-pointer flex items-center gap-2">
                  <Calculator className="w-4 h-4" />
                  Revenue Calculator
                </Link>
              </DropdownMenuItem>
              <DropdownMenuItem asChild>
                <Link to="/thumbnail-preview" className="cursor-pointer flex items-center gap-2">
                  <ImageIcon className="w-4 h-4" />
                  Thumbnail Preview
                </Link>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>

          <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
              <button className="text-muted-foreground hover:text-primary transition-colors font-medium flex items-center gap-1">
                Blog
                <ChevronDown className="w-4 h-4" />
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
              <DropdownMenuItem asChild>
                <a href="https://blog.viewsmax.com" target="_blank" rel="noopener noreferrer" className="cursor-pointer">
                  Blog
                </a>
              </DropdownMenuItem>
              <DropdownMenuItem asChild>
                <a href="https://blog.viewsmax.com/guides" target="_blank" rel="noopener noreferrer" className="cursor-pointer">
                  Guides
                </a>
              </DropdownMenuItem>
              <DropdownMenuItem asChild>
                <a href="https://www.youtube.com/@viewsmaxdotcom" target="_blank" rel="noopener noreferrer" className="cursor-pointer flex items-center gap-2">
                  <Youtube className="w-4 h-4" />
                  YouTube Channel
                </a>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
          
          {!user && (
            <a href="https://chromewebstore.google.com/detail/viewsmax/mefpcplmigglgpjbjbnogaldnpbnddib" target="_blank" rel="noopener noreferrer" className="text-muted-foreground hover:text-primary transition-colors font-medium flex items-center gap-1">
              <ChromeIcon className="w-6 h-6" />
              Chrome Extension
            </a>
          )}
        </nav>

        <div className="flex items-center gap-3">
          {user ? (
            // Show dashboard and user dropdown when logged in
            <>
              <Button variant="ghost" className="hidden md:inline-flex text-muted-foreground hover:text-foreground" asChild>
                <Link to="/dashboard">
                  <BarChart3 className="w-4 h-4 mr-2" />
                  Dashboard
                </Link>
              </Button>
              
              <a
                href="https://chromewebstore.google.com/detail/viewsmax/mefpcplmigglgpjbjbnogaldnpbnddib"
                target="_blank"
                rel="noopener noreferrer"
                className="hidden md:flex items-center gap-2 text-muted-foreground hover:text-foreground transition-colors font-medium"
              >
                <ChromeIcon className="w-5 h-5" />
                <span>Chrome Extension</span>
              </a>
              
              <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" size="icon" className="!ring-0 !ring-offset-0 !outline-none focus:!ring-0 focus:!outline-none focus-visible:!ring-0 focus-visible:!outline-none active:!ring-0 active:!outline-none data-[state=open]:!ring-0 data-[state=closed]:!ring-0">
                    <User className="w-4 h-4" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem disabled>
                    {user?.email}
                  </DropdownMenuItem>
                  <DropdownMenuItem asChild>
                    <Link to="/dashboard/billing" className="flex items-center">
                      <Crown className="w-4 h-4 mr-2" />
                      Billing
                    </Link>
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={signOut}>
                    <LogOut className="w-4 h-4 mr-2" />
                    Sign Out
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </>
          ) : (
            // Show auth buttons when not logged in
            <>
              <Button variant="ghost" className="hidden md:inline-flex text-muted-foreground hover:text-foreground" asChild>
                <Link to="/auth">Sign In</Link>
              </Button>
              <Button variant="cta" size="sm" className="font-semibold" asChild>
                <Link to="/auth">Sign Up Free</Link>
              </Button>
            </>
          )}
          <Sheet open={mobileMenuOpen} onOpenChange={setMobileMenuOpen}>
            <SheetTrigger asChild>
          <Button variant="ghost" size="icon" className="md:hidden">
            <Menu className="w-4 h-4" />
          </Button>
            </SheetTrigger>
            <SheetContent side="right" className="w-[300px] sm:w-[400px]">
              <SheetHeader>
                <SheetTitle>Menu</SheetTitle>
              </SheetHeader>
              <nav className="flex flex-col gap-4 mt-6">
                <Link 
                  to="/#features" 
                  className="text-muted-foreground hover:text-primary transition-colors font-medium"
                  onClick={() => setMobileMenuOpen(false)}
                >
                  Features
                </Link>
                <Link 
                  to="/#reviews" 
                  className="text-muted-foreground hover:text-primary transition-colors font-medium"
                  onClick={() => setMobileMenuOpen(false)}
                >
                  Reviews
                </Link>
                <Link 
                  to="/#pricing" 
                  className="text-muted-foreground hover:text-primary transition-colors font-medium"
                  onClick={() => setMobileMenuOpen(false)}
                >
                  Pricing
                </Link>
                
                <div className="pt-2 border-t border-border">
                  <p className="text-sm font-semibold text-foreground mb-2">Tools</p>
                  <div className="flex flex-col gap-2 ml-4">
                    <Link
                      to="/youtube-monetization-calculator"
                      className="text-muted-foreground hover:text-primary transition-colors text-sm flex items-center gap-2"
                      onClick={() => setMobileMenuOpen(false)}
                    >
                      <Calculator className="w-4 h-4" />
                      Revenue Calculator
                    </Link>
                    <Link
                      to="/thumbnail-preview"
                      className="text-muted-foreground hover:text-primary transition-colors text-sm flex items-center gap-2"
                      onClick={() => setMobileMenuOpen(false)}
                    >
                      <ImageIcon className="w-4 h-4" />
                      Thumbnail Preview
                    </Link>
                  </div>
                </div>

                <div className="pt-2 border-t border-border">
                  <p className="text-sm font-semibold text-foreground mb-2">Blog</p>
                  <div className="flex flex-col gap-2 ml-4">
                    <a
                      href="https://blog.viewsmax.com"
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-muted-foreground hover:text-primary transition-colors text-sm"
                      onClick={() => setMobileMenuOpen(false)}
                    >
                      Blog
                    </a>
                    <a
                      href="https://blog.viewsmax.com/guides"
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-muted-foreground hover:text-primary transition-colors text-sm"
                      onClick={() => setMobileMenuOpen(false)}
                    >
                      Guides
                    </a>
                    <a
                      href="https://www.youtube.com/@viewsmaxdotcom"
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-muted-foreground hover:text-primary transition-colors text-sm flex items-center gap-2"
                      onClick={() => setMobileMenuOpen(false)}
                    >
                      <Youtube className="w-4 h-4" />
                      YouTube Channel
                    </a>
                  </div>
                </div>

                {!user && (
                  <a 
                    href="https://chromewebstore.google.com/detail/viewsmax/mefpcplmigglgpjbjbnogaldnpbnddib" 
                    target="_blank" 
                    rel="noopener noreferrer" 
                    className="text-muted-foreground hover:text-primary transition-colors font-medium flex items-center gap-2"
                    onClick={() => setMobileMenuOpen(false)}
                  >
                    <ChromeIcon className="w-5 h-5" />
                    Chrome Extension
                  </a>
                )}

                {user ? (
                  <>
                    <div className="pt-2 border-t border-border">
                      <Link
                        to="/dashboard"
                        className="text-muted-foreground hover:text-primary transition-colors font-medium flex items-center gap-2"
                        onClick={() => setMobileMenuOpen(false)}
                      >
                        <BarChart3 className="w-4 h-4" />
                        Dashboard
                      </Link>
                    </div>
                    <a
                      href="https://chromewebstore.google.com/detail/viewsmax/mefpcplmigglgpjbjbnogaldnpbnddib"
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-muted-foreground hover:text-primary transition-colors font-medium flex items-center gap-2"
                      onClick={() => setMobileMenuOpen(false)}
                    >
                      <ChromeIcon className="w-5 h-5" />
                      Chrome Extension
                    </a>
                    <Link 
                      to="/dashboard/billing" 
                      className="text-muted-foreground hover:text-primary transition-colors font-medium flex items-center gap-2"
                      onClick={() => setMobileMenuOpen(false)}
                    >
                      <Crown className="w-4 h-4" />
                      Billing
                    </Link>
                    <button
                      onClick={() => {
                        setMobileMenuOpen(false);
                        signOut();
                      }}
                      className="text-muted-foreground hover:text-primary transition-colors font-medium flex items-center gap-2 text-left"
                    >
                      <LogOut className="w-4 h-4" />
                      Sign Out
                    </button>
                  </>
                ) : (
                  <div className="pt-2 border-t border-border flex flex-col gap-2">
                    <Button variant="ghost" className="w-full justify-start" asChild>
                      <Link to="/auth" onClick={() => setMobileMenuOpen(false)}>
                        Sign In
                      </Link>
                    </Button>
                    <Button variant="cta" className="w-full" asChild>
                      <Link to="/auth" onClick={() => setMobileMenuOpen(false)}>
                        Sign Up Free
                      </Link>
                    </Button>
                  </div>
                )}
              </nav>
            </SheetContent>
          </Sheet>
        </div>
      </div>
    </header>
  );
};

export default Header;