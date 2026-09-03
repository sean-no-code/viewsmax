import { useEffect, useState, useCallback } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogTrigger,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogClose,
} from "@/components/ui/dialog";
import { toast } from "sonner";
import { useTranslation } from "react-i18next";
import { Switch } from "@/components/ui/switch";
import { youtubeAuthService } from "@/lib/youtube-auth";
import { viewsMaxApi } from "@/lib/api-service";
import { supabase } from "@/integrations/supabase/client";
import { useAuth } from "@/hooks/useAuth";
import ConnectAccounts from "@/components/ConnectAccounts";
import AiAssistantAccess from "@/components/AiAssistantAccess";
import AiActivityLog from "@/components/AiActivityLog";

interface BackendChannel {
  id: number;
  youtube_channel_id: string;
  channel_name: string;
  channel_description?: string;
  subscriber_count: number;
  video_count: number;
  view_count: number;
  profile_image_url?: string;
  custom_url?: string;
  country?: string;
  published_at?: string;
}

const Settings = () => {
  const { t } = useTranslation();
  const { user } = useAuth();
  const [isDeleting, setIsDeleting] = useState(false);
  const [isDisconnecting, setIsDisconnecting] = useState(false);
  const [isConnected, setIsConnected] = useState(false);
  const [channelName, setChannelName] = useState<string | null>(null);
  const [backendChannels, setBackendChannels] = useState<BackendChannel[]>([]);
  const [notifyPostFailures, setNotifyPostFailures] = useState<boolean | null>(null);
  const [savingNotify, setSavingNotify] = useState(false);

  // Load notification preferences
  useEffect(() => {
    let cancelled = false;
    viewsMaxApi.getUserSettings().then((res) => {
      if (!cancelled && res.success && res.data) {
        setNotifyPostFailures(res.data.notify_post_failures);
      }
    });
    return () => {
      cancelled = true;
    };
  }, []);

  const handleNotifyToggle = async (checked: boolean) => {
    setNotifyPostFailures(checked);
    setSavingNotify(true);
    const res = await viewsMaxApi.updateUserSettings({ notify_post_failures: checked });
    setSavingNotify(false);
    if (!res.success) {
      setNotifyPostFailures(!checked); // revert optimistic flip
      toast.error(t("settings.notifications.saveFailed", "Couldn't save the notification setting. Please try again."));
      return;
    }
    toast.success(checked
      ? t("settings.notifications.enabled", "You'll be emailed when a post fails.")
      : t("settings.notifications.disabled", "Publish-failure emails turned off."));
  };


  // Fetch backend channels to check connection status
  const fetchBackendChannels = useCallback(async () => {
    if (!user) return;

    try {
      const storedSession = localStorage.getItem('auth_session');
      let token = null;
      if (storedSession) {
        try {
          const session = JSON.parse(storedSession);
          token = session.token;
        } catch (error) {
          console.error('Error parsing auth session:', error);
        }
      }

      if (!token) {
        setIsConnected(false);
        setChannelName(null);
        return;
      }

      const response = await viewsMaxApi.getChannels(token);
      if (response.success && response.data) {
        setBackendChannels(response.data);
        setIsConnected(response.data.length > 0);
        if (response.data.length > 0) {
          setChannelName(response.data[0].channel_name);
        } else {
          setChannelName(null);
        }
      } else {
        setIsConnected(false);
        setChannelName(null);
      }
    } catch (error) {
      console.error('Failed to fetch backend channels:', error);
      setIsConnected(false);
      setChannelName(null);
    }
  }, [user]);

  useEffect(() => {
    fetchBackendChannels();
  }, [fetchBackendChannels]);

  const handleDisconnect = async () => {
    if (backendChannels.length === 0) {
      toast.error('No channels to disconnect');
      return;
    }

    setIsDisconnecting(true);
    try {
      // Get token from auth session
      const storedSession = localStorage.getItem('auth_session');
      let token = null;
      if (storedSession) {
        try {
          const session = JSON.parse(storedSession);
          token = session.token;
        } catch (error) {
          console.error('Error parsing auth session:', error);
        }
      }

      if (!token) {
        toast.error('Authentication required');
        return;
      }

      // Disconnect all channels
      let successCount = 0;
      let failCount = 0;

      for (const channel of backendChannels) {
        const response = await viewsMaxApi.disconnectChannel(token, channel.id);
        if (response.success) {
          successCount++;
        } else {
          failCount++;
        }
      }

      // Clear local state
      youtubeAuthService.disconnect();
      
      // Clear cached data
      try {
        localStorage.removeItem('youtube_analytics_data');
        localStorage.removeItem('youtube_analytics_timestamp');
        localStorage.removeItem('youtube_cache_channel_id');
        localStorage.removeItem('youtube_oauth_code');
      } catch (e) {
        console.debug('Failed to clear cached data', e);
      }

      // Refresh channel list
      await fetchBackendChannels();

      if (successCount > 0) {
        toast.success('YouTube channel disconnected');
        setIsConnected(false);
        setChannelName(null);
      }
      if (failCount > 0) {
        toast.error(`Failed to disconnect ${failCount} channel(s)`);
      }
    } catch (e) {
      console.error('Error disconnecting channels:', e);
      toast.error('Could not disconnect. Please try again.');
    } finally {
      setIsDisconnecting(false);
    }
  };

  const handleDeleteData = async () => {
    setIsDeleting(true);
    try {
      // Delete all user data
      const result = await viewsMaxApi.deleteAllUserData();
      if (!result.success) {
        throw new Error(result.error || 'Failed to delete user data');
      }

      // Clear cached analytics; keep YouTube connection and basic info intact
      try {
        localStorage.removeItem('youtube_analytics_data');
        localStorage.removeItem('youtube_analytics_timestamp');
        localStorage.removeItem('youtube_cache_channel_id');
        localStorage.removeItem('youtube_oauth_code');
        // Clear any locally cached privacy consent keys
        try {
          Object.keys(localStorage).forEach((key) => {
            if (key.startsWith('tm_privacy_consent') || key.includes('ViewsMax_privacy_consent')) {
              localStorage.removeItem(key);
            }
          });
        } catch (e) {
          console.debug('Failed to clear local privacy consent keys', e);
        }
      } catch (e) {
        console.debug('Failed to clear some cached analytics items', e);
      }

      toast.success('Projects, analytics cache, and privacy consent cleared. YouTube connection remains.');
    } catch (error) {
      console.error('Local data clear error:', error);
      toast.error('Failed to clear local data. Please try again.');
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <div className="space-y-6 max-w-3xl mx-auto">
      {/* NOTE: Other Settings sections (Connected Accounts, Account & Data) are
          temporarily commented out while we build/review the AI Assistant Access
          card in isolation. Restore these when ready.
      <Card>
        <CardHeader>
          <CardTitle>Connected Accounts</CardTitle>
          <CardDescription>Connect or disconnect your video platforms.</CardDescription>
        </CardHeader>
        <CardContent>
          <ConnectAccounts mode="settings" />
        </CardContent>
      </Card>
      */}

      <Card>
        <CardHeader>
          <CardTitle>{t("settings.notifications.title", "Notifications")}</CardTitle>
          <CardDescription>{t("settings.notifications.description", "Choose when ViewsMax emails you.")}</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex items-center justify-between">
            <div>
              <div className="font-medium">{t("settings.notifications.postFailures", "Email me when a post fails to publish")}</div>
              <div className="text-sm text-muted-foreground">
                {t("settings.notifications.postFailuresHint", "One email per failed post listing every platform that failed, with a link to retry.")}
              </div>
            </div>
            <Switch
              checked={notifyPostFailures ?? true}
              disabled={notifyPostFailures === null || savingNotify}
              onCheckedChange={handleNotifyToggle}
              aria-label={t("settings.notifications.postFailures", "Email me when a post fails to publish")}
            />
          </div>
        </CardContent>
      </Card>


      <AiAssistantAccess />

      <AiActivityLog />

      {/*
      <Card>
        <CardHeader>
          <CardTitle>Account & Data</CardTitle>
          <CardDescription>Manage your connected accounts and your stored data.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <div className="font-medium">Disconnect YouTube</div>
              <div className="text-sm text-muted-foreground">Stops access and clears YouTube tokens and cached data.<br>
              </br> You can also revoke via Google settings.</div>
            </div>
            <Button onClick={handleDisconnect} disabled={!isConnected || isDisconnecting} variant="outline">
              {isConnected ? (isDisconnecting ? 'Disconnecting…' : 'Disconnect') : 'Not connected'}
            </Button>
          </div>

          <div className="flex items-center justify-between">
            <div>
              <div className="font-medium">Delete My Data</div>
              <div className="text-sm text-muted-foreground">Deletes your projects and YouTube tokens and cached data.</div>
            </div>
            <Dialog>
              <DialogTrigger asChild>
                <Button disabled={isDeleting} variant="destructive">
                  {isDeleting ? 'Deleting…' : 'Delete Data'}
                </Button>
              </DialogTrigger>
              <DialogContent>
                <DialogHeader>
                  <DialogTitle>Are you absolutely sure?</DialogTitle>
                  <DialogDescription>
                    This action will permanently delete all your projects and clear YouTube tokens and cached analytics from this device. This cannot be undone.
                  </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                  <DialogClose asChild>
                    <Button variant="outline">Cancel</Button>
                  </DialogClose>
                  <DialogClose asChild>
                    <Button onClick={handleDeleteData} variant="destructive">
                      Yes, delete my data
                    </Button>
                  </DialogClose>
                </DialogFooter>
              </DialogContent>
            </Dialog>
          </div>
        </CardContent>
      </Card>
      */}
    </div>
  );
};

export default Settings;


