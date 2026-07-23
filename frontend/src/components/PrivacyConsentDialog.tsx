import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { ScrollArea } from "@/components/ui/scroll-area";
import { Link } from "react-router-dom";
import { Shield, FileText, ExternalLink } from "lucide-react";

interface PrivacyConsentDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onAccept: () => void;
}

const PrivacyConsentDialog = ({ open, onOpenChange, onAccept }: PrivacyConsentDialogProps) => {
  const [hasReadPrivacy, setHasReadPrivacy] = useState(false);
  const [hasReadTerms, setHasReadTerms] = useState(false);
  const [showPrivacyDialog, setShowPrivacyDialog] = useState(false);

  const canProceed = hasReadPrivacy && hasReadTerms;

  const handleAccept = () => {
    onAccept();
    onOpenChange(false);
    // Reset checkboxes for next time
    setHasReadPrivacy(false);
    setHasReadTerms(false);
  };

  const handleCancel = () => {
    onOpenChange(false);
    // Reset checkboxes
    setHasReadPrivacy(false);
    setHasReadTerms(false);
  };

  return (
    <>
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="max-w-2xl max-h-[90vh]">
          <DialogHeader>
            <div className="mx-auto w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center mb-4">
              <Shield className="w-6 h-6 text-primary" />
            </div>
            <DialogTitle className="text-2xl text-center">Privacy Policy Agreement Required</DialogTitle>
            <DialogDescription className="text-base text-center">
              Before connecting your YouTube channel, you must agree to our privacy policy and terms of service.
            </DialogDescription>
          </DialogHeader>
          
          <ScrollArea className="max-h-[60vh]">
            <div className="space-y-6 px-1">
              <div className="bg-blue-50 dark:bg-blue-950/20 p-4 rounded-lg">
                <h3 className="font-semibold mb-2 flex items-center">
                  <FileText className="w-4 h-4 mr-2" />
                  What data we will access:
                </h3>
                <ul className="text-sm space-y-1 text-muted-foreground">
                  <li>• Your YouTube channel metadata (name, subscriber count, views)</li>
                  <li>• Video information (titles, descriptions, statistics, thumbnails)</li>
                  <li>• Playlists and analytics data (views over time, demographics)</li>
                  <li>• Basic Google account info (email, name, profile picture)</li>
                </ul>
              </div>

              <div className="space-y-4">
                <div className="flex items-center gap-3">
                  <Checkbox 
                    id="privacy-policy" 
                    checked={hasReadPrivacy}
                    onCheckedChange={(checked) => setHasReadPrivacy(checked as boolean)}
                  />
                  <div className="flex-1">
                    <label htmlFor="privacy-policy" className="text-sm font-medium leading-none inline-flex items-center gap-1 flex-wrap peer-disabled:cursor-not-allowed peer-disabled:opacity-70">
                      I have read and agree to the
                      <Dialog open={showPrivacyDialog} onOpenChange={setShowPrivacyDialog}>
                        <Button variant="link" className="p-0 h-auto text-primary underline" onClick={() => setShowPrivacyDialog(true)}>
                          Privacy Policy
                        </Button>
                        <DialogContent className="max-w-4xl max-h-[80vh]">
                          <DialogHeader>
                            <DialogTitle>Privacy Policy</DialogTitle>
                            <DialogDescription>
                              Please read our privacy policy carefully before proceeding.
                            </DialogDescription>
                          </DialogHeader>
                          <ScrollArea className="h-[60vh] pr-4">
                            <div className="space-y-4 text-sm">
                              <p>
                                ViewsMax is committed to protecting your privacy. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our YouTube analytics platform.
                              </p>
                              
                              <h4 className="font-semibold">YouTube Data Access</h4>
                              <p>
                                We request read-only access to your YouTube channel data including channel metadata, video information, playlists, and analytics data. This data is accessed through Google's YouTube Data API v3 and YouTube Analytics API using OAuth 2.0 with scopes: youtube.readonly and yt-analytics.readonly.
                              </p>
                              
                              <h4 className="font-semibold">Limited Use Requirements</h4>
                              <p>
                                We access Google user data solely to provide user-facing features in accordance with Google API Services User Data Policy. We do not sell Google user data, transfer it to third parties except as necessary to provide the Service, or use it for advertising or retargeting.
                              </p>
                              
                              <h4 className="font-semibold">Your Rights</h4>
                              <p>
                                You can disconnect your YouTube channel and revoke access at any time through your Google Account settings or our app settings. You can request deletion of your data by contacting us.
                              </p>
                              
                              <div className="pt-4 border-t">
                                <Link 
                                  to="/privacy" 
                                  target="_blank" 
                                  className="text-primary hover:underline flex items-center"
                                  onClick={() => setShowPrivacyDialog(false)}
                                >
                                  Read Full Privacy Policy <ExternalLink className="w-3 h-3 ml-1" />
                                </Link>
                              </div>
                            </div>
                          </ScrollArea>
                        </DialogContent>
                      </Dialog>
                    </label>
                  </div>
                </div>

                <div className="flex items-center gap-3">
                  <Checkbox 
                    id="terms-service" 
                    checked={hasReadTerms}
                    onCheckedChange={(checked) => setHasReadTerms(checked as boolean)}
                  />
                  <div className="flex-1">
                    <label htmlFor="terms-service" className="text-sm font-medium leading-none inline-flex items-center gap-1 flex-wrap peer-disabled:cursor-not-allowed peer-disabled:opacity-70">
                      I have read and agree to the
                      <Link to="/terms" target="_blank" className="text-primary hover:underline">
                        Terms of Service
                      </Link>{" "}
                      and{" "}
                      <a 
                        href="https://www.youtube.com/t/terms" 
                        target="_blank" 
                        rel="noopener noreferrer"
                        className="text-primary hover:underline"
                      >
                        YouTube Terms of Service
                      </a>
                    </label>
                  </div>
                </div>
              </div>

              <div className="flex gap-3 pt-4">
                <Button 
                  onClick={handleCancel} 
                  variant="outline" 
                  className="flex-1"
                >
                  Cancel
                </Button>
                <Button 
                  onClick={handleAccept} 
                  disabled={!canProceed}
                  className="flex-1"
                >
                  Accept & Connect YouTube
                </Button>
              </div>

              <p className="text-xs text-muted-foreground text-center">
                By accepting, you acknowledge that you understand how your YouTube data will be used as described in our Privacy Policy.
              </p>
            </div>
          </ScrollArea>
        </DialogContent>
      </Dialog>
    </>
  );
};

export default PrivacyConsentDialog;
